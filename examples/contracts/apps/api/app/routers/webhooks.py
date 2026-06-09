"""Webhook CRUD + WebhookDelivery view (Эпик 11.2).

ACL:
- Все операции — DirectorOrAdmin (webhooks — admin-сущность, через них уходят
  данные клиентов наружу).

Создание secret: если не передан в payload — генерится secrets.token_urlsafe(32)
и возвращается plaintext в response (показывается админу ОДИН раз для копирования
в систему-подписчик). При последующих GET — НЕ возвращается (только last4).

Test-delivery: POST /api/webhooks/{id}/test создаёт тестовую WebhookDelivery
с фиктивным payload — для проверки доступности URL и валидности подписи.
"""
from __future__ import annotations

import secrets
from datetime import UTC, datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import DirectorOrAdmin
from app.models import Webhook, WebhookDelivery
from app.services.ssrf_guard import SSRFBlockedError, assert_safe_webhook_url
from app.services.webhook_dispatcher import build_event_payload, deliver_one
from app.services.webhook_events import (
    WEBHOOK_EVENTS,
    WILDCARD_EVENT,
    validate_event_subscriptions,
)

router = APIRouter(prefix="/webhooks", tags=["webhooks"])
deliveries_router = APIRouter(prefix="/webhook-deliveries", tags=["webhooks"])


# ============ Pydantic-схемы ============

def _mask_secret(secret: str) -> str:
    """Маскировка: показываем только last4 (после префикса есть тело)."""
    if not secret:
        return ""
    if len(secret) <= 8:
        return "****"
    return "****" + secret[-4:]


class WebhookOut(BaseModel):
    """Маскированный output: secret скрыт (показывается только last4)."""

    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    url: str
    secret_preview: str
    event_subscriptions: list[str]
    is_active: bool
    headers: dict[str, str] | None
    # Tech Sprint Фаза 0: per-webhook retry/timeout (миграция 0033).
    max_attempts: int = 5
    backoff_seconds: int = 60
    timeout_seconds: int = 10
    created_by_user_id: int | None
    created_at: datetime
    updated_at: datetime

    @classmethod
    def from_orm_safe(cls, wh: Webhook) -> "WebhookOut":
        return cls(
            id=wh.id,
            name=wh.name,
            url=wh.url,
            secret_preview=_mask_secret(wh.secret),
            event_subscriptions=list(wh.event_subscriptions or []),
            is_active=wh.is_active,
            headers=wh.headers,
            max_attempts=getattr(wh, "max_attempts", None) or 5,
            backoff_seconds=getattr(wh, "backoff_seconds", None) or 60,
            timeout_seconds=getattr(wh, "timeout_seconds", None) or 10,
            created_by_user_id=wh.created_by_user_id,
            created_at=wh.created_at,
            updated_at=wh.updated_at,
        )


class WebhookCreated(WebhookOut):
    """Response создания: добавлен plaintext_secret (показывается ОДИН раз!)."""

    plaintext_secret: str


class WebhookCreate(BaseModel):
    name: str = Field(min_length=1, max_length=128)
    url: str = Field(min_length=1, max_length=512)
    event_subscriptions: list[str] = Field(default_factory=list)
    # Если не передан — генерится сервером.
    secret: str | None = Field(default=None, min_length=16, max_length=128)
    headers: dict[str, str] | None = None
    is_active: bool = True
    # Tech Sprint Фаза 0: optional override настроек retry/timeout.
    # Минимумы — sanity (не даём положить 0 attempts или timeout=0).
    max_attempts: int = Field(default=5, ge=1, le=20)
    backoff_seconds: int = Field(default=60, ge=1, le=86400)  # макс 24h
    timeout_seconds: int = Field(default=10, ge=1, le=300)    # макс 5min


class WebhookUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=128)
    url: str | None = Field(default=None, min_length=1, max_length=512)
    event_subscriptions: list[str] | None = None
    # Update secret требует явного rotate-endpoint'а (отдельная PATCH семантика
    # — здесь не разрешаем, чтобы случайно не сбросить).
    headers: dict[str, str] | None = None
    is_active: bool | None = None
    max_attempts: int | None = Field(default=None, ge=1, le=20)
    backoff_seconds: int | None = Field(default=None, ge=1, le=86400)
    timeout_seconds: int | None = Field(default=None, ge=1, le=300)


class WebhookTest(BaseModel):
    """Запрос на тестовую доставку: какое событие сэмулировать."""

    event: str
    # Опциональный фейковый payload data — иначе подставляется дефолтный sample.
    data: dict[str, Any] | None = None


class WebhookEventsOut(BaseModel):
    """Whitelist event'ов для UI-конструктора Webhook."""

    events: list[str]
    wildcard: str


class WebhookDeliveryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    webhook_id: int
    event: str
    payload: dict[str, Any]
    status: str
    attempt: int
    next_retry_at: datetime | None
    last_http_code: int | None
    last_error: str | None
    last_response_body: str | None
    created_at: datetime
    finished_at: datetime | None


# ============ Helpers ============

async def _get_webhook_or_404(
    session: AsyncSession, webhook_id: int
) -> Webhook:
    wh = (
        await session.execute(select(Webhook).where(Webhook.id == webhook_id))
    ).scalar_one_or_none()
    if not wh:
        raise HTTPException(404, "Webhook не найден")
    return wh


async def _get_delivery_or_404(
    session: AsyncSession, delivery_id: int
) -> WebhookDelivery:
    d = (
        await session.execute(
            select(WebhookDelivery).where(WebhookDelivery.id == delivery_id)
        )
    ).scalar_one_or_none()
    if not d:
        raise HTTPException(404, "Delivery не найдена")
    return d


# ============ Endpoints — Webhook CRUD ============

@router.get("/events", response_model=WebhookEventsOut)
async def list_available_events(_: DirectorOrAdmin):
    """Whitelist возможных событий для подписки. Для UI-конструктора."""
    return WebhookEventsOut(
        events=sorted(WEBHOOK_EVENTS), wildcard=WILDCARD_EVENT,
    )


@router.get("", response_model=list[WebhookOut])
async def list_webhooks(
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    is_active: bool | None = None,
    event: str | None = None,
):
    stmt = select(Webhook).order_by(Webhook.created_at.desc())
    if is_active is not None:
        stmt = stmt.where(Webhook.is_active.is_(is_active))
    rows = (await session.execute(stmt)).scalars().all()
    # Фильтр по event делаем в Python (JSON search в SQL не портативен).
    if event:
        rows = [
            wh for wh in rows
            if event in (wh.event_subscriptions or [])
            or WILDCARD_EVENT in (wh.event_subscriptions or [])
        ]
    return [WebhookOut.from_orm_safe(wh) for wh in rows]


@router.post("", response_model=WebhookCreated, status_code=status.HTTP_201_CREATED)
async def create_webhook(
    data: WebhookCreate,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    try:
        events = validate_event_subscriptions(data.event_subscriptions)
    except ValueError as e:
        raise HTTPException(400, str(e)) from e

    # P0 SSRF: не даём сохранить webhook на приватный/loopback/link-local URL.
    try:
        await assert_safe_webhook_url(data.url)
    except SSRFBlockedError as e:
        raise HTTPException(422, SSRFBlockedError.safe_reason) from e

    plaintext_secret = data.secret or secrets.token_urlsafe(32)

    wh = Webhook(
        name=data.name,
        url=data.url,
        secret=plaintext_secret,
        event_subscriptions=events,
        headers=data.headers,
        is_active=data.is_active,
        max_attempts=data.max_attempts,
        backoff_seconds=data.backoff_seconds,
        timeout_seconds=data.timeout_seconds,
        created_by_user_id=current_user.id,
    )
    session.add(wh)
    await session.commit()
    await session.refresh(wh)
    base = WebhookOut.from_orm_safe(wh)
    return WebhookCreated(
        **base.model_dump(),
        plaintext_secret=plaintext_secret,
    )


@router.get("/{webhook_id}", response_model=WebhookOut)
async def get_webhook(
    webhook_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    wh = await _get_webhook_or_404(session, webhook_id)
    return WebhookOut.from_orm_safe(wh)


@router.patch("/{webhook_id}", response_model=WebhookOut)
async def update_webhook(
    webhook_id: int,
    data: WebhookUpdate,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    wh = await _get_webhook_or_404(session, webhook_id)
    patch = data.model_dump(exclude_unset=True)
    if "event_subscriptions" in patch and patch["event_subscriptions"] is not None:
        try:
            patch["event_subscriptions"] = validate_event_subscriptions(
                patch["event_subscriptions"]
            )
        except ValueError as e:
            raise HTTPException(400, str(e)) from e
    # P0 SSRF: валидируем новый URL при апдейте.
    if "url" in patch and patch["url"] is not None:
        try:
            await assert_safe_webhook_url(patch["url"])
        except SSRFBlockedError as e:
            raise HTTPException(422, SSRFBlockedError.safe_reason) from e
    for k, v in patch.items():
        setattr(wh, k, v)
    await session.commit()
    await session.refresh(wh)
    return WebhookOut.from_orm_safe(wh)


@router.delete("/{webhook_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_webhook(
    webhook_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    wh = await _get_webhook_or_404(session, webhook_id)
    await session.delete(wh)
    await session.commit()


@router.post(
    "/{webhook_id}/regenerate-secret",
    response_model=WebhookCreated,
)
async def regenerate_webhook_secret(
    webhook_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Ротация secret. Старый перестанет работать — подписчик должен
    зарегистрировать новый. Возвращает plaintext один раз."""
    wh = await _get_webhook_or_404(session, webhook_id)
    new_secret = secrets.token_urlsafe(32)
    wh.secret = new_secret
    await session.commit()
    await session.refresh(wh)
    base = WebhookOut.from_orm_safe(wh)
    return WebhookCreated(
        **base.model_dump(),
        plaintext_secret=new_secret,
    )


@router.post("/{webhook_id}/test", response_model=WebhookDeliveryOut)
async def test_webhook(
    webhook_id: int,
    data: WebhookTest,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать тестовую доставку и СРАЗУ её отправить (без cron'а).

    Удобно для проверки URL подписчика и подписи: возвращает результат
    одной попытки. Если упало с retry — следующие попытки сделает cron.
    """
    if data.event != WILDCARD_EVENT and data.event not in WEBHOOK_EVENTS:
        raise HTTPException(
            400, f"Неизвестное событие: {data.event!r}",
        )
    wh = await _get_webhook_or_404(session, webhook_id)
    payload = build_event_payload(
        event=data.event,
        entity_type="test",
        entity_id=0,
        data=data.data or {"hello": "world", "test": True},
    )
    delivery = WebhookDelivery(
        webhook_id=wh.id,
        event=data.event,
        payload=payload,
        status="pending",
        attempt=0,
        next_retry_at=datetime.now(UTC),
    )
    session.add(delivery)
    await session.flush()
    await deliver_one(session, delivery)
    await session.commit()
    await session.refresh(delivery)
    return delivery


@router.post(
    "/{webhook_id}/redeliver/{delivery_id}", response_model=WebhookDeliveryOut,
)
async def redeliver_event(
    webhook_id: int,
    delivery_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Tech Sprint Фаза 0 (задача 4): повторить delivery конкретного события.

    Создаёт НОВУЮ WebhookDelivery (не редактирует исходную) с тем же payload
    и event, отправляет синхронно через deliver_one — admin видит результат
    сразу. Используется когда:
    - подписчик упал и был починен (хотим переслать важный event);
    - дебаг подписчика: воспроизвести событие повторно.

    Исходная delivery должна принадлежать этому webhook'у (400 иначе) —
    защита от случайного «переслать чужое».
    """
    wh = await _get_webhook_or_404(session, webhook_id)
    src = await _get_delivery_or_404(session, delivery_id)
    if src.webhook_id != webhook_id:
        raise HTTPException(
            400,
            f"Delivery {delivery_id} принадлежит webhook'у {src.webhook_id}, "
            f"а не {webhook_id}",
        )

    new_delivery = WebhookDelivery(
        webhook_id=wh.id,
        event=src.event,
        payload=src.payload,
        status="pending",
        attempt=0,
        next_retry_at=datetime.now(UTC),
    )
    session.add(new_delivery)
    await session.flush()  # получить id для X-Macro-Delivery-Id header
    await deliver_one(session, new_delivery)
    await session.commit()
    await session.refresh(new_delivery)
    return new_delivery


@router.get(
    "/{webhook_id}/deliveries", response_model=list[WebhookDeliveryOut],
)
async def list_webhook_deliveries(
    webhook_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    status_filter: Annotated[str | None, Query(alias="status")] = None,
    event: str | None = None,
    limit: int = 50,
    offset: int = 0,
):
    """Лог доставок для одного webhook'а. status фильтр + event фильтр."""
    await _get_webhook_or_404(session, webhook_id)
    stmt = (
        select(WebhookDelivery)
        .where(WebhookDelivery.webhook_id == webhook_id)
        .order_by(WebhookDelivery.created_at.desc())
    )
    if status_filter:
        stmt = stmt.where(WebhookDelivery.status == status_filter)
    if event:
        stmt = stmt.where(WebhookDelivery.event == event)
    stmt = stmt.limit(max(1, min(500, limit))).offset(max(0, offset))
    return (await session.execute(stmt)).scalars().all()


# ============ Endpoints — Manual retry (Эпик 11.2) ============

@deliveries_router.post(
    "/{delivery_id}/retry", response_model=WebhookDeliveryOut,
)
async def retry_delivery(
    delivery_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Force-retry: пометить delivery как pending + next_retry_at=now().

    attempt НЕ сбрасываем (если уже потратили 6 попыток — это очевидно для
    оператора, но ручная попытка делает ещё одну). Перепланируется через cron
    либо синхронно отправляется через POST /webhooks/{id}/test для тест-сообщений.
    """
    delivery = await _get_delivery_or_404(session, delivery_id)
    delivery.status = "pending"
    delivery.next_retry_at = datetime.now(UTC)
    # finished_at сбрасываем — статус-машина «открывается» снова.
    delivery.finished_at = None
    await session.commit()
    await session.refresh(delivery)
    return delivery
