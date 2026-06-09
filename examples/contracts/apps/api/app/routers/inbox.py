"""Inbox (Эпик 5 MVP): просмотр входящих сообщений + универсальный webhook.

GET /api/inbox — список InboundMessage с фильтрами (для UI таба «Inbox»).
POST /api/inbox/webhook/{channel_id} — UNAUTHENTICATED. Принимает входящие
сообщения из внешних систем. Verify через header X-Channel-Token
(constant-time compare). Создаёт InboundMessage и автоматически — Lead
(через inbox.auto_create_lead_from_message).

Reuses dedup: повторный POST с тем же external_id создаёт ещё один
InboundMessage (для audit), но Lead не дублирует (привязывается к существующему
target_lead_id первого сообщения).
"""
from __future__ import annotations

import hmac
import logging
from datetime import datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, Header, HTTPException, Request, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import or_
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import LawyerOrAdmin
from app.models import Channel, InboundMessage
from app.services.inbox import auto_create_lead_from_message
from app.services.rate_limit import check_inbound_webhook_rate_limit

_logger = logging.getLogger(__name__)

router = APIRouter(prefix="/inbox", tags=["inbox"])


# ============ Pydantic-схемы ============

class InboundMessageOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    channel_id: int
    external_id: str | None
    from_identifier: str | None
    from_name: str | None
    subject: str | None
    body: str | None
    raw_payload: dict[str, Any] | None
    target_lead_id: int | None
    target_lead_created: bool
    target_deal_id: int | None = None
    target_deal_created: bool = False
    # 'routed' | 'dedup' | 'failed' | None (миграция 0082). UI показывает
    # «не разобрано» для failed.
    routing_status: str | None = None
    received_at: datetime


class WebhookIn(BaseModel):
    """Body универсального webhook'а. Все поля опциональны (зависит от канала)."""

    external_id: str | None = Field(default=None, max_length=128)
    from_identifier: str | None = Field(default=None, max_length=255)
    from_name: str | None = Field(default=None, max_length=255)
    subject: str | None = Field(default=None, max_length=255)
    body: str | None = None
    raw_payload: dict[str, Any] | None = None


class WebhookOut(BaseModel):
    message_id: int
    # DEALS 2.0 (Ф1c): входящий поток создаёт Company+Deal. lead_id/lead_created
    # сохранены для обратной совместимости клиентов webhook'а, но теперь несут
    # значения сделки (lead_id ← deal_id, lead_created ← deal_created).
    lead_id: int | None
    lead_created: bool
    deal_id: int | None = None
    deal_created: bool = False


# ============ Helpers ============

async def _get_message_or_404(session: AsyncSession, message_id: int) -> InboundMessage:
    msg = (await session.execute(
        select(InboundMessage).where(InboundMessage.id == message_id)
    )).scalar_one_or_none()
    if not msg:
        raise HTTPException(404, "Сообщение не найдено")
    return msg


def _verify_channel_token(channel: Channel, provided_token: str | None) -> None:
    """Constant-time сравнение secret_token с переданным header.

    Поднимает 401 если токен отсутствует, 403 если не совпадает.
    """
    if not provided_token:
        raise HTTPException(401, "Отсутствует X-Channel-Token")
    if not hmac.compare_digest(channel.secret_token, provided_token):
        raise HTTPException(403, "Невалидный X-Channel-Token")


async def _idempotent_webhook_response(
    session: AsyncSession, channel_id: int, external_id: str | None
) -> WebhookOut:
    """Ответ при гонке UNIQUE(channel_id, external_id): привязаться к уже принятому.

    Находит ранее принятое сообщение с тем же external_id, отдаёт его deal_id
    (deal_created=False — мы его НЕ создавали в этом запросе).

    Гонка scale=2 при READ COMMITTED: конкурентная реплика могла вставить строку,
    но ещё не закоммитить — тогда наш SELECT её не видит (existing=None). В этом
    случае НЕ возвращаем 409 (провайдер ретраит → 409-шторм / усиление гонки),
    а отдаём мягкий ACCEPTED (HTTP 202): «принято, обрабатывается». Дедуп всё
    равно сходится к одному InboundMessage — повторный ретрай провайдера попадёт
    в уже закоммиченную строку и получит её deal_id.
    """
    existing = (
        await session.execute(
            select(InboundMessage)
            .where(
                InboundMessage.channel_id == channel_id,
                InboundMessage.external_id == external_id,
            )
            .order_by(InboundMessage.id)
            .limit(1)
        )
    ).scalar_one_or_none()
    if existing is None:
        # Конкурентная реплика ещё не закоммитила свою строку (uncommitted под
        # READ COMMITTED). Мягкий 202 вместо 409 — провайдер не должен ретраить
        # агрессивно; сообщение уже принимается параллельным воркером.
        raise HTTPException(202, "Сообщение принято, обрабатывается")
    return WebhookOut(
        message_id=existing.id,
        lead_id=existing.target_deal_id,
        lead_created=False,
        deal_id=existing.target_deal_id,
        deal_created=False,
    )


# ============ Endpoints ============

@router.get("", response_model=list[InboundMessageOut])
async def list_inbound_messages(
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    channel_id: int | None = None,
    target_deal_id: int | None = None,
    has_deal: bool | None = None,
    routing_status: str | None = None,
    q: str | None = None,
    limit: int = 100,
    offset: int = 0,
):
    """Список входящих сообщений с фильтрами (для админ-UI таба «Inbox»).

    DEALS 2.0 (Ф1c): входящий поток создаёт Deal, а не Lead. Фильтр привязки —
    по target_deal_id (target_lead_id всегда NULL после DEALS 2.0).

    has_deal=True → только сообщения, привязанные к Deal (target_deal_id IS NOT NULL).
    has_deal=False → только сообщения без Deal (например, routing failed/канал inactive).
    routing_status='failed' → только неразобранные (для таба «требует внимания»).
    """
    stmt = select(InboundMessage).order_by(InboundMessage.received_at.desc())
    if channel_id is not None:
        stmt = stmt.where(InboundMessage.channel_id == channel_id)
    if target_deal_id is not None:
        stmt = stmt.where(InboundMessage.target_deal_id == target_deal_id)
    if has_deal is True:
        stmt = stmt.where(InboundMessage.target_deal_id.is_not(None))
    elif has_deal is False:
        stmt = stmt.where(InboundMessage.target_deal_id.is_(None))
    if routing_status is not None:
        stmt = stmt.where(InboundMessage.routing_status == routing_status)
    if q:
        like = f"%{q}%"
        stmt = stmt.where(or_(
            InboundMessage.from_identifier.ilike(like),
            InboundMessage.from_name.ilike(like),
            InboundMessage.subject.ilike(like),
            InboundMessage.body.ilike(like),
        ))
    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))
    return (await session.execute(stmt)).scalars().all()


@router.get("/{message_id}", response_model=InboundMessageOut)
async def get_inbound_message(
    message_id: int,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    return await _get_message_or_404(session, message_id)


@router.post(
    "/webhook/{channel_id}",
    response_model=WebhookOut,
    status_code=status.HTTP_201_CREATED,
)
async def inbox_webhook(
    channel_id: int,
    payload: WebhookIn,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    x_channel_token: Annotated[str | None, Header(alias="X-Channel-Token")] = None,
):
    """UNAUTHENTICATED. Универсальный webhook для приёма входящих сообщений.

    Заголовок X-Channel-Token: <secret_token> обязателен (constant-time compare).
    Если канал inactive — 503. Сообщение создаётся всегда (audit), Lead — через
    auto_create_lead_from_message (с дедупом по external_id).

    Per-channel+IP rate-limit (C4 WARN-1) — до любой работы с БД, чтобы флуд при
    утечке токена не создавал лавину Company/Deal/automations.
    """
    client_ip = request.client.host if request.client else None
    if not await check_inbound_webhook_rate_limit(channel_id, client_ip):
        raise HTTPException(
            status.HTTP_429_TOO_MANY_REQUESTS,
            "Слишком много сообщений. Повторите позже.",
        )

    channel = (await session.execute(
        select(Channel).where(Channel.id == channel_id)
    )).scalar_one_or_none()
    if not channel:
        raise HTTPException(404, "Канал не найден")

    _verify_channel_token(channel, x_channel_token)

    if not channel.is_active:
        raise HTTPException(503, "Канал отключён")

    # БД-UNIQUE (channel_id, external_id) — финальный гард против гонки scale=2 и
    # легитимных ретраев провайдера (баг #4). Если параллельный воркер уже принял
    # это же сообщение — INSERT msg упадёт IntegrityError; отвечаем идемпотентно,
    # привязавшись к уже созданной сделке.
    msg = InboundMessage(
        channel_id=channel.id,
        external_id=payload.external_id,
        from_identifier=payload.from_identifier,
        from_name=payload.from_name,
        subject=payload.subject,
        body=payload.body,
        raw_payload=payload.raw_payload,
    )
    try:
        # begin_nested() = SAVEPOINT. При IntegrityError на flush() контекст
        # откатывает ТОЛЬКО savepoint (не всю внешнюю транзакцию) — внешняя
        # остаётся живой, SELECT существующего сообщения ниже работает в том же
        # соединении. НИКАКОГО session.rollback() здесь — он бы убил всю
        # транзакцию и спровоцировал 409-шторм на гонке (баг аудита).
        async with session.begin_nested():
            session.add(msg)
            await session.flush()  # нужен msg.id; ловит UNIQUE-конфликт
    except IntegrityError:
        return await _idempotent_webhook_response(
            session, channel.id, payload.external_id
        )

    await auto_create_lead_from_message(session, channel, msg)
    deal_id = msg.target_deal_id
    deal_created = msg.target_deal_created

    await session.commit()
    await session.refresh(msg)

    return WebhookOut(
        message_id=msg.id,
        # backward-compat: lead_* несут значения сделки.
        lead_id=deal_id,
        lead_created=deal_created,
        deal_id=deal_id,
        deal_created=deal_created,
    )
