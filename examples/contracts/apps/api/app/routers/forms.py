"""Forms (Эпик 5 MVP): CRUD форм + публичный endpoint для приёма submission'ов.

Admin CRUD (cookie auth, LawyerOrAdmin):
- GET /forms — список форм с фильтрами.
- POST /forms — создать (public_slug автогенерится, если не передан).
- GET /forms/{id}.
- PATCH /forms/{id}.
- DELETE /forms/{id}.

Public endpoints (UNAUTHENTICATED, без cookie):
- GET /forms/public/{slug} — получить мета-данные формы для рендера на публичной
  странице (name, fields, thank_you_text). Если форма inactive — 404.
- POST /forms/public/{slug}/submit — приём submission'а. Валидация по fields
  (только required-проверка в MVP). Создаёт InboundMessage в привязанном канале
  (если есть) и автогенерирует Lead. Возвращает thank_you_text.
"""
from __future__ import annotations

import time
from datetime import datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Request, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, LawyerOrAdmin
from app.models import Channel, Form, InboundMessage
from app.services.inbox import (
    auto_create_lead_from_message,
    build_message_from_form_submission,
    form_submission_external_id,
    generate_form_slug,
    is_honeypot_filled,
    validate_form_submission,
)
from app.services.rate_limit import check_form_rate_limit

router = APIRouter(prefix="/forms", tags=["forms"])


# ============ Pydantic-схемы ============

class FormOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    public_slug: str
    fields: list[dict[str, Any]]
    channel_id: int | None
    thank_you_text: str | None
    is_active: bool
    created_by_user_id: int | None
    created_at: datetime
    updated_at: datetime


class FormCreate(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    public_slug: str | None = Field(default=None, min_length=1, max_length=64)
    fields: list[dict[str, Any]] = Field(default_factory=list)
    channel_id: int | None = None
    thank_you_text: str | None = None
    is_active: bool = True


class FormUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=255)
    public_slug: str | None = Field(default=None, min_length=1, max_length=64)
    fields: list[dict[str, Any]] | None = None
    channel_id: int | None = None
    thank_you_text: str | None = None
    is_active: bool | None = None


class FormPublicOut(BaseModel):
    """То, что отдаём анониму на публичной странице: ни slug, ни channel."""

    name: str
    fields: list[dict[str, Any]]
    thank_you_text: str | None


class FormSubmitOut(BaseModel):
    ok: bool = True
    thank_you_text: str | None
    # DEALS 2.0 (Ф1c): submit создаёт Company+Deal. lead_created сохранён для
    # обратной совместимости публичного контракта формы — теперь = deal_created.
    lead_created: bool
    deal_created: bool = False
    # Ссылка на созданную (или уже существующую при дубль-submit) сделку. На
    # первичном сабмите = id новой сделки; на дедупе повторной отправки —
    # резолвится из ранее принятого InboundMessage, чтобы клиент не «терял»
    # ссылку (баг аудита: дубль-submit возвращал deal_created=false без id).
    deal_id: int | None = None


# ============ Helpers ============

async def _get_form_or_404(session: AsyncSession, form_id: int) -> Form:
    form = (await session.execute(
        select(Form).where(Form.id == form_id)
    )).scalar_one_or_none()
    if not form:
        raise HTTPException(404, "Форма не найдена")
    return form


async def _get_form_by_slug_or_404(session: AsyncSession, slug: str) -> Form:
    form = (await session.execute(
        select(Form).where(Form.public_slug == slug)
    )).scalar_one_or_none()
    if not form:
        raise HTTPException(404, "Форма не найдена")
    return form


async def _validate_channel_id(session: AsyncSession, channel_id: int | None) -> None:
    if channel_id is None:
        return
    channel = (await session.execute(
        select(Channel.id).where(Channel.id == channel_id)
    )).scalar_one_or_none()
    if channel is None:
        raise HTTPException(404, f"Канал {channel_id} не найден")


async def _slug_taken(session: AsyncSession, slug: str, exclude_id: int | None = None) -> bool:
    stmt = select(Form.id).where(Form.public_slug == slug)
    if exclude_id is not None:
        stmt = stmt.where(Form.id != exclude_id)
    return (await session.execute(stmt)).scalar_one_or_none() is not None


# ============ Admin Endpoints ============

@router.get("", response_model=list[FormOut])
async def list_forms(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    is_active: bool | None = None,
    channel_id: int | None = None,
    limit: int = 100,
    offset: int = 0,
):
    stmt = select(Form).order_by(Form.created_at.desc())
    if is_active is not None:
        stmt = stmt.where(Form.is_active.is_(is_active))
    if channel_id is not None:
        stmt = stmt.where(Form.channel_id == channel_id)
    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))
    return (await session.execute(stmt)).scalars().all()


@router.post("", response_model=FormOut, status_code=status.HTTP_201_CREATED)
async def create_form(
    data: FormCreate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    _admin: LawyerOrAdmin,
):
    await _validate_channel_id(session, data.channel_id)

    # Если slug передан — проверим уникальность; иначе генерим, и если совпало (маловероятно) — перегенерим
    slug = data.public_slug
    if slug:
        if await _slug_taken(session, slug):
            raise HTTPException(409, f"Slug '{slug}' уже занят")
    else:
        # До 5 попыток сгенерировать уникальный
        for _ in range(5):
            candidate = generate_form_slug()
            if not await _slug_taken(session, candidate):
                slug = candidate
                break
        if not slug:
            raise HTTPException(500, "Не удалось сгенерировать уникальный slug")

    form = Form(
        name=data.name,
        public_slug=slug,
        fields=data.fields,
        channel_id=data.channel_id,
        thank_you_text=data.thank_you_text,
        is_active=data.is_active,
        created_by_user_id=current_user.id,
    )
    session.add(form)
    await session.commit()
    await session.refresh(form)
    return form


@router.get("/{form_id}", response_model=FormOut)
async def get_form(
    form_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    return await _get_form_or_404(session, form_id)


@router.patch("/{form_id}", response_model=FormOut)
async def update_form(
    form_id: int,
    data: FormUpdate,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    form = await _get_form_or_404(session, form_id)
    patch = data.model_dump(exclude_unset=True)

    if "channel_id" in patch:
        await _validate_channel_id(session, patch["channel_id"])
    if "public_slug" in patch and patch["public_slug"] is not None:
        if await _slug_taken(session, patch["public_slug"], exclude_id=form.id):
            raise HTTPException(409, f"Slug '{patch['public_slug']}' уже занят")

    for k, v in patch.items():
        setattr(form, k, v)
    await session.commit()
    await session.refresh(form)
    return form


@router.delete("/{form_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_form(
    form_id: int,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    form = await _get_form_or_404(session, form_id)
    await session.delete(form)
    await session.commit()


# ============ Public Endpoints (no auth) ============

@router.get("/public/{slug}", response_model=FormPublicOut)
async def get_public_form(
    slug: str,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """UNAUTHENTICATED. Получить мета-данные формы по slug для рендера на публичной странице."""
    form = await _get_form_by_slug_or_404(session, slug)
    if not form.is_active:
        # Скрываем существование формы — 404, не 403
        raise HTTPException(404, "Форма не найдена")
    return FormPublicOut(
        name=form.name,
        fields=form.fields,
        thank_you_text=form.thank_you_text,
    )


@router.post(
    "/public/{slug}/submit",
    response_model=FormSubmitOut,
    status_code=status.HTTP_201_CREATED,
)
async def submit_public_form(
    slug: str,
    submission: dict[str, Any],
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """UNAUTHENTICATED. Приём submission'а формы.

    Защита публичного endpoint'а (баг #8/#9 код-аудита):
    - per-IP rate-limit (token-bucket в Redis) — 429 при спаме.
    - honeypot-поле: если заполнено — это бот, тихо отвечаем «ок» без создания
      Company/Deal.
    - строгая валидация по form.fields (только объявленные ключи, типы email/phone,
      лимит длины) — мусор не попадает в Company/raw_payload.

    Создаёт InboundMessage в привязанном канале (если есть), автогенерирует Deal
    со стабильным external_id (дедуп двойного клика/refresh). Если канал не задан —
    submission принимается, но Deal не создаётся.
    """
    # Per-IP rate-limit (до любой работы с БД).
    client_ip = request.client.host if request.client else None
    if not await check_form_rate_limit(slug, client_ip):
        raise HTTPException(
            status.HTTP_429_TOO_MANY_REQUESTS,
            "Слишком много попыток. Повторите позже.",
        )

    form = await _get_form_by_slug_or_404(session, slug)
    if not form.is_active:
        raise HTTPException(404, "Форма не найдена")

    # Honeypot: бот заполнил скрытое поле — тихо «ок» (не палим механизм), без Deal.
    if is_honeypot_filled(submission):
        return FormSubmitOut(
            ok=True,
            thank_you_text=form.thank_you_text,
            lead_created=False,
        )

    ok, err = validate_form_submission(form.fields, submission)
    if not ok:
        raise HTTPException(400, err or "Ошибка валидации")

    # Если у формы не задан канал — сообщение принимаем, но без InboundMessage
    # (нет канала → некуда писать). Это допустимо в MVP: считаем submission
    # «потерянным», в дальнейшем (Эпик 5.1) — авто-создание fallback канала.
    if form.channel_id is None:
        return FormSubmitOut(
            ok=True,
            thank_you_text=form.thank_you_text,
            lead_created=False,
        )

    channel = (await session.execute(
        select(Channel).where(Channel.id == form.channel_id)
    )).scalar_one_or_none()
    if channel is None or not channel.is_active:
        # Канал удалён или отключён — submission принимаем (анонимный пользователь
        # не должен знать о внутренних проблемах), но Lead не создаём.
        return FormSubmitOut(
            ok=True,
            thank_you_text=form.thank_you_text,
            lead_created=False,
        )

    msg_kwargs = build_message_from_form_submission(form.name, submission)
    # Стабильный external_id (баг #5): дедуп повторной отправки (двойной клик /
    # refresh) внутри временно́го окна по (slug + email|phone). None если нет
    # контакта — тогда дедуп формы не применяется.
    external_id = form_submission_external_id(slug, submission, time.time())

    # БД-UNIQUE (channel_id, external_id) ловит повторную отправку (гонка/refresh):
    # IntegrityError → отвечаем «ок» и резолвим ссылку на УЖЕ созданную сделку.
    msg = InboundMessage(
        channel_id=channel.id,
        external_id=external_id,
        raw_payload=submission,
        **msg_kwargs,
    )
    try:
        # begin_nested() = SAVEPOINT: IntegrityError откатывает ТОЛЬКО savepoint,
        # внешняя транзакция жива → SELECT существующего сообщения работает.
        # НИКАКОГО session.rollback() (он убил бы всю транзакцию).
        async with session.begin_nested():
            session.add(msg)
            await session.flush()
    except IntegrityError:
        # Дубль-submit: резолвим ранее принятое сообщение по (channel_id,
        # external_id) и возвращаем ссылку на его сделку (баг аудита: раньше
        # клиент получал deal_created=false без id — «терял» сделку).
        existing = (
            await session.execute(
                select(InboundMessage)
                .where(
                    InboundMessage.channel_id == channel.id,
                    InboundMessage.external_id == external_id,
                )
                .order_by(InboundMessage.id)
                .limit(1)
            )
        ).scalar_one_or_none()
        return FormSubmitOut(
            ok=True,
            thank_you_text=form.thank_you_text,
            lead_created=False,
            deal_created=False,
            # existing is None возможно только при uncommitted-гонке scale=2 —
            # тогда ссылки нет, но submission всё равно «ок» (дедуп сойдётся).
            deal_id=existing.target_deal_id if existing is not None else None,
        )

    await auto_create_lead_from_message(session, channel, msg)
    deal_created = msg.target_deal_created
    deal_id = msg.target_deal_id

    await session.commit()

    return FormSubmitOut(
        ok=True,
        thank_you_text=form.thank_you_text,
        lead_created=deal_created,  # backward-compat
        deal_created=deal_created,
        deal_id=deal_id,
    )
