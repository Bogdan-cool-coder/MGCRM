"""Channel CRUD (Эпик 5 MVP).

Канал — описание точки приёма входящих сообщений (tg/wa/email/web_form/api).
secret_token генерится при create через secrets.token_urlsafe(32) и используется
для verification webhook'ов в /api/inbox/webhook/{channel_id}.

ACL:
- list/get — CurrentUser;
- create/update/delete/regenerate-token — DirectorOrAdmin (канал — admin-сущность).
"""
from __future__ import annotations

from datetime import datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func as sa_func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin
from app.models import Channel, Form, Pipeline, PipelineStage, User
from app.services.inbox import CHANNEL_KINDS, LEAD_SOURCE_MAP, generate_channel_token

router = APIRouter(prefix="/channels", tags=["channels"])


# ============ Pydantic-схемы ============

def _mask_token(token: str | None) -> str:
    """Маскировать secret_token для широкой выдачи (баг C4 CRIT-3).

    Показываем только последние 4 символа: «****abcd». Полный токен —
    единственная защита unauth-webhook'а /api/inbox/webhook/{id}, поэтому
    он НЕ должен утекать всем CurrentUser. Полный отдаётся лишь на create /
    regenerate / admin-only reveal.
    """
    if not token:
        return "****"
    return "****" + token[-4:] if len(token) > 8 else "****"


class ChannelOut(BaseModel):
    """Выдача канала для list/get. secret_token замаскирован (см. _mask_token).

    Полный токен — только в ChannelSecretOut (create / regenerate / reveal).
    """

    id: int
    name: str
    kind: str
    secret_token_preview: str
    config: dict[str, Any]
    default_lead_source: str
    default_owner_id: int | None
    default_pipeline_id: int | None
    default_stage_id: int | None
    is_active: bool
    created_at: datetime
    updated_at: datetime

    @classmethod
    def from_channel(cls, channel: Channel) -> "ChannelOut":
        return cls(
            id=channel.id,
            name=channel.name,
            kind=channel.kind,
            secret_token_preview=_mask_token(channel.secret_token),
            config=channel.config,
            default_lead_source=channel.default_lead_source,
            default_owner_id=channel.default_owner_id,
            default_pipeline_id=channel.default_pipeline_id,
            default_stage_id=channel.default_stage_id,
            is_active=channel.is_active,
            created_at=channel.created_at,
            updated_at=channel.updated_at,
        )


class ChannelSecretOut(ChannelOut):
    """Расширение ChannelOut с ПОЛНЫМ secret_token.

    Возвращается ТОЛЬКО на create / regenerate / reveal — все три под
    DirectorOrAdmin. Никогда не используется в list/get (там — preview).
    """

    secret_token: str

    @classmethod
    def from_channel(cls, channel: Channel) -> "ChannelSecretOut":
        return cls(
            id=channel.id,
            name=channel.name,
            kind=channel.kind,
            secret_token_preview=_mask_token(channel.secret_token),
            secret_token=channel.secret_token,
            config=channel.config,
            default_lead_source=channel.default_lead_source,
            default_owner_id=channel.default_owner_id,
            default_pipeline_id=channel.default_pipeline_id,
            default_stage_id=channel.default_stage_id,
            is_active=channel.is_active,
            created_at=channel.created_at,
            updated_at=channel.updated_at,
        )


class ChannelCreate(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    kind: str
    config: dict[str, Any] = Field(default_factory=dict)
    default_lead_source: str | None = None
    default_owner_id: int | None = None
    default_pipeline_id: int | None = None
    default_stage_id: int | None = None
    is_active: bool = True


class ChannelUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=255)
    kind: str | None = None
    config: dict[str, Any] | None = None
    default_lead_source: str | None = None
    default_owner_id: int | None = None
    default_pipeline_id: int | None = None
    default_stage_id: int | None = None
    is_active: bool | None = None


# ============ Helpers ============

async def _get_channel_or_404(session: AsyncSession, channel_id: int) -> Channel:
    channel = (await session.execute(
        select(Channel).where(Channel.id == channel_id)
    )).scalar_one_or_none()
    if not channel:
        raise HTTPException(404, "Канал не найден")
    return channel


def _validate_kind(kind: str) -> None:
    if kind not in CHANNEL_KINDS:
        raise HTTPException(400, f"Недопустимый kind: {kind}. Разрешено: {sorted(CHANNEL_KINDS)}")


def _validate_lead_source(source: str | None) -> None:
    if source is None:
        return
    allowed = set(LEAD_SOURCE_MAP.values()) | {"manual", "import"}
    if source not in allowed:
        raise HTTPException(400, f"Недопустимый default_lead_source: {source}")


async def _validate_owner(session: AsyncSession, owner_id: int | None) -> None:
    if owner_id is None:
        return
    user = (await session.execute(
        select(User.id).where(User.id == owner_id)
    )).scalar_one_or_none()
    if user is None:
        raise HTTPException(404, f"Пользователь {owner_id} не найден")


async def _validate_pipeline_and_stage(
    session: AsyncSession, pipeline_id: int | None, stage_id: int | None
) -> None:
    if pipeline_id is not None:
        pipe = (await session.execute(
            select(Pipeline.id).where(Pipeline.id == pipeline_id)
        )).scalar_one_or_none()
        if pipe is None:
            raise HTTPException(404, f"Воронка {pipeline_id} не найдена")
    if stage_id is not None:
        stage = (await session.execute(
            select(PipelineStage).where(PipelineStage.id == stage_id)
        )).scalar_one_or_none()
        if stage is None:
            raise HTTPException(404, f"Этап {stage_id} не найден")
        if pipeline_id is not None and stage.pipeline_id != pipeline_id:
            raise HTTPException(400, "Этап не из этой воронки")


# ============ Endpoints ============

@router.get("", response_model=list[ChannelOut])
async def list_channels(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    kind: str | None = None,
    is_active: bool | None = None,
    limit: int = 100,
    offset: int = 0,
):
    stmt = select(Channel).order_by(Channel.created_at.desc())
    if kind is not None:
        stmt = stmt.where(Channel.kind == kind)
    if is_active is not None:
        stmt = stmt.where(Channel.is_active.is_(is_active))
    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))
    channels = (await session.execute(stmt)).scalars().all()
    return [ChannelOut.from_channel(c) for c in channels]


@router.post("", response_model=ChannelSecretOut, status_code=status.HTTP_201_CREATED)
async def create_channel(
    data: ChannelCreate,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    _validate_kind(data.kind)
    _validate_lead_source(data.default_lead_source)
    await _validate_owner(session, data.default_owner_id)
    await _validate_pipeline_and_stage(session, data.default_pipeline_id, data.default_stage_id)

    channel = Channel(
        name=data.name,
        kind=data.kind,
        secret_token=generate_channel_token(),
        config=data.config,
        default_lead_source=data.default_lead_source or LEAD_SOURCE_MAP.get(data.kind, "api"),
        default_owner_id=data.default_owner_id,
        default_pipeline_id=data.default_pipeline_id,
        default_stage_id=data.default_stage_id,
        is_active=data.is_active,
    )
    session.add(channel)
    await session.commit()
    await session.refresh(channel)
    # Полный secret_token отдаём ОДИН раз на create (admin его сохраняет).
    return ChannelSecretOut.from_channel(channel)


@router.get("/{channel_id}", response_model=ChannelOut)
async def get_channel(
    channel_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    channel = await _get_channel_or_404(session, channel_id)
    return ChannelOut.from_channel(channel)


@router.get("/{channel_id}/reveal-token", response_model=ChannelSecretOut)
async def reveal_channel_token(
    channel_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Показать ПОЛНЫЙ secret_token канала (admin-only).

    Баг C4 CRIT-3: list/get отдают только маску. Когда admin/director'у нужен
    полный токен (скопировать в curl / внешнюю систему) — он явно дёргает этот
    эндпоинт. Рядовому пользователю токен недоступен.
    """
    channel = await _get_channel_or_404(session, channel_id)
    return ChannelSecretOut.from_channel(channel)


@router.patch("/{channel_id}", response_model=ChannelOut)
async def update_channel(
    channel_id: int,
    data: ChannelUpdate,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    channel = await _get_channel_or_404(session, channel_id)
    patch = data.model_dump(exclude_unset=True)

    if "kind" in patch and patch["kind"] is not None:
        _validate_kind(patch["kind"])
    if "default_lead_source" in patch:
        _validate_lead_source(patch["default_lead_source"])
    if "default_owner_id" in patch:
        await _validate_owner(session, patch["default_owner_id"])
    # Если приходит хотя бы один из (pipeline/stage) — провалидировать пару
    if "default_pipeline_id" in patch or "default_stage_id" in patch:
        new_pipeline = patch.get("default_pipeline_id", channel.default_pipeline_id)
        new_stage = patch.get("default_stage_id", channel.default_stage_id)
        await _validate_pipeline_and_stage(session, new_pipeline, new_stage)

    for k, v in patch.items():
        setattr(channel, k, v)
    await session.commit()
    await session.refresh(channel)
    return ChannelOut.from_channel(channel)


@router.delete("/{channel_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_channel(
    channel_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    force: bool = False,
):
    """Удалить канал. Если к нему привязаны формы — 409 без force=true (баг #12).

    Form.channel_id имеет ondelete=SET NULL, поэтому удаление канала «тихо» осиротит
    формы (перестанут создавать Deal). Предупреждаем оператора: при наличии форм
    требуем явный ?force=true. С force формы остаются, но их channel_id обнулится.
    """
    channel = await _get_channel_or_404(session, channel_id)
    if not force:
        form_count = (
            await session.execute(
                select(sa_func.count(Form.id)).where(Form.channel_id == channel_id)
            )
        ).scalar_one()
        if form_count:
            raise HTTPException(
                status.HTTP_409_CONFLICT,
                f"К каналу привязано форм: {form_count}. Они перестанут создавать "
                f"сделки. Для подтверждения повторите с параметром force=true.",
            )
    await session.delete(channel)
    await session.commit()


@router.post("/{channel_id}/regenerate-token", response_model=ChannelSecretOut)
async def regenerate_channel_token(
    channel_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Сгенерировать новый secret_token (старый перестанет работать).

    Используется при подозрении на утечку токена. Канал не теряет данные —
    только меняется ключ для verify входящих webhook'ов. Полный новый токен
    отдаётся в ответе (admin его пересохраняет во внешних системах).
    """
    channel = await _get_channel_or_404(session, channel_id)
    channel.secret_token = generate_channel_token()
    await session.commit()
    await session.refresh(channel)
    return ChannelSecretOut.from_channel(channel)
