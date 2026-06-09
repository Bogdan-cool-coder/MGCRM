"""Outbound webhook dispatcher (Эпик 11.2).

Главные функции:
- dispatch_event() — inline-hook, который вызывается после изменения сущности.
  Создаёт WebhookDelivery(status='pending', next_retry_at=now()) для каждого
  активного Webhook'а, подписанного на event. Не блокирует ответ — catch на всё.

- deliver_one() — попытка отправить ОДНУ delivery. Используется cron'ом и
  manual retry. Логика retry внутри: success → 'success'+finished_at;
  4xx (кроме 408/429) → 'failed' немедленно (клиентская ошибка, retry
  бессмысленен); 5xx/timeout/network → 'retrying' или 'failed' если max
  attempt'ов.

- scan_pending_deliveries() — cron-loop, забирает batch deliveries и шлёт.

Retry-policy: 1m → 5m → 30m → 2h → 6h → 24h (6 attempts max). Чем выше
attempt — тем дольше пауза (экспоненциальный backoff в днях). После 6-го —
status='failed', оператор может через UI ручной retry.
"""
from __future__ import annotations

import asyncio
import json
import logging
from datetime import UTC, datetime, timedelta
from typing import Any

import httpx
from sqlalchemy import or_, text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import Webhook, WebhookDelivery
from app.services.webhook_events import (
    event_matches_subscription,
    validate_event_subscriptions,
)
from app.services.webhook_signature import (
    SIGNATURE_HEADER,
    filter_custom_headers,
    sign_body,
)

logger = logging.getLogger(__name__)

# Максимум attempts перед терминальным fail.
MAX_ATTEMPTS = 6

# Расписание ретраев. Индекс = attempt - 1 (т.е. интервал ДО попытки № attempt).
# Конкретные значения подобраны из стандартной практики webhook-доставки:
# 1m быстро ловит транзиентные сетевые ошибки, 24h даёт оператору время
# поднять упавший подписчик.
RETRY_SCHEDULE_SECONDS: list[int] = [
    60,            # 1 min
    5 * 60,        # 5 min
    30 * 60,       # 30 min
    2 * 60 * 60,   # 2 h
    6 * 60 * 60,   # 6 h
    24 * 60 * 60,  # 24 h
]

# Не ретраим 4xx (клиентская ошибка подписчика — либо payload ему не нравится,
# либо URL устарел) КРОМЕ:
# - 408 Request Timeout: сетевой issue на их стороне, ретраим.
# - 429 Too Many Requests: rate-limit, ретраим (backoff в schedule помогает).
RETRYABLE_4XX = frozenset({408, 429})

# Максимум attempts pick'ов за один тик cron'а — защита от случая, когда
# одна delivery упала и cron начинает крутиться слишком быстро.
CRON_BATCH_SIZE = 50

# HTTP timeout одной попытки. Подписчики обычно отвечают за <5s; 10s — запас.
HTTP_TIMEOUT_SECONDS = 10.0

# Truncate сохраняемого response body для диагностики. P0 security: cap до
# 256 символов — не отражаем большие фрагменты ответа internal-таргетов в лог
# (минимизируем data-leak surface при возможном SSRF).
MAX_BODY_SAVE = 256


def schedule_next_retry(attempt_after: int) -> datetime | None:
    """Вычислить next_retry_at для следующей попытки.

    attempt_after — номер УЖЕ выполненной (неудачной) попытки. Если она
    привела к необходимости retry, next_retry_at = now + RETRY_SCHEDULE[attempt].

    Возвращает None если attempt_after >= MAX_ATTEMPTS (терминальный fail —
    caller должен поставить status='failed').

    NB: глобальный schedule остаётся для back-compat (caller'ы которые не
    передают webhook'ом). Если у webhook есть кастомные настройки, см.
    schedule_next_retry_for_webhook ниже.
    """
    if attempt_after >= MAX_ATTEMPTS:
        return None
    # RETRY_SCHEDULE[0] — пауза перед 2-й попыткой (после 1-й upload).
    # attempt_after = 1 → берём RETRY_SCHEDULE[0]. attempt_after = 6 → None.
    delay_idx = attempt_after - 1
    if delay_idx < 0 or delay_idx >= len(RETRY_SCHEDULE_SECONDS):
        return None
    delay = RETRY_SCHEDULE_SECONDS[delay_idx]
    return datetime.now(UTC) + timedelta(seconds=delay)


def compute_per_webhook_backoff(
    attempt_after: int, backoff_seconds: int, max_attempts: int,
) -> int | None:
    """Pure-function: вычислить задержку retry на основе per-webhook настроек.

    Экспоненциальный backoff: delay = backoff_seconds * 2^(attempt_after-1).
    То есть: 1 attempt → backoff_seconds; 2 → 2*backoff_seconds;
    3 → 4*backoff_seconds; 4 → 8*backoff_seconds; 5 → 16*backoff_seconds.

    Для backoff_seconds=60 это даёт: 1m → 2m → 4m → 8m → 16m. Если webhook
    хочет longer waits — настроит backoff_seconds=600 (10m), 5 attempts —
    10m/20m/40m/80m/160m.

    Возвращает delay_seconds (int) или None если attempt_after >= max_attempts.
    """
    if attempt_after >= max_attempts:
        return None
    if attempt_after <= 0:
        return backoff_seconds
    # exponential backoff base = backoff_seconds
    return backoff_seconds * (2 ** (attempt_after - 1))


def schedule_next_retry_for_webhook(
    attempt_after: int, webhook: "Webhook",
) -> datetime | None:
    """Backoff для следующей попытки с учётом per-webhook настроек.

    Использует webhook.max_attempts и webhook.backoff_seconds. Безопасный
    fallback для старых webhook'ов БЕЗ этих полей — используется глобальный
    schedule_next_retry (back-compat).
    """
    # Back-compat: если у webhook'а нет настроек (ORM до миграции 0033),
    # используем глобальный schedule.
    max_attempts_val = getattr(webhook, "max_attempts", None) or MAX_ATTEMPTS
    backoff_val = getattr(webhook, "backoff_seconds", None) or 60
    delay = compute_per_webhook_backoff(
        attempt_after, backoff_val, max_attempts_val,
    )
    if delay is None:
        return None
    return datetime.now(UTC) + timedelta(seconds=delay)


def build_event_payload(
    event: str,
    entity_type: str,
    entity_id: int,
    data: dict[str, Any],
    occurred_at: datetime | None = None,
) -> dict[str, Any]:
    """Сформировать payload для отправки подписчику (pure-function).

    Это то, что увидит подписчик в body POST'а. Структура задокументирована
    в openapi (когда добавим — пока в коде).
    """
    return {
        "event": event,
        "entity": {"type": entity_type, "id": entity_id},
        "data": data,
        "occurred_at": (occurred_at or datetime.now(UTC)).isoformat(),
    }


def is_retryable_status(code: int) -> bool:
    """4xx — не ретраим (кроме 408/429); 5xx — ретраим; <400 не вызывается."""
    if 500 <= code <= 599:
        return True
    if 400 <= code <= 499:
        return code in RETRYABLE_4XX
    return False


def truncate_response_body(body: str | None) -> str | None:
    """Усечь тело ответа до MAX_BODY_SAVE символов (для записи в last_response_body)."""
    if body is None:
        return None
    if len(body) <= MAX_BODY_SAVE:
        return body
    return body[:MAX_BODY_SAVE] + "...[truncated]"


async def find_subscribed_webhooks(
    session: AsyncSession, event: str
) -> list[Webhook]:
    """Список активных Webhook'ов, подписанных на event (включая '*' wildcard).

    Использует JSON-LIKE для фильтра в БД — для SQLite/Postgres работает,
    но не индексирует. Маленькие таблицы webhooks (<100 записей в проде)
    — это OK; для масштаба >10k нужно либо отдельная таблица event_links,
    либо нормальный JSONB-индекс.
    """
    stmt = select(Webhook).where(Webhook.is_active.is_(True))
    webhooks = (await session.execute(stmt)).scalars().all()
    # Filter в Python — JSON-search в SQL не портативен SQLite ↔ Postgres,
    # и количество webhooks мало.
    return [
        wh for wh in webhooks
        if event_matches_subscription(event, wh.event_subscriptions or [])
    ]


async def dispatch_event(
    session: AsyncSession,
    event: str,
    entity_type: str,
    entity_id: int,
    data: dict[str, Any],
) -> list[WebhookDelivery]:
    """Создать WebhookDelivery'и для всех подписанных Webhook'ов.

    Возвращает список созданных deliveries (пустой если никто не подписан).

    КРИТИЧНО: не выбрасывает исключения наружу — если что-то пошло не так
    (например, незарегистрированный event), это записывается в лог, но
    не ломает основной flow (POST /api/leads возвращается 201, даже если
    диспетчер webhook не отработал).
    """
    try:
        # Валидация event_name (защита от опечаток в каждом вызове сайта).
        # Если событие не в whitelist — ничего не делаем, лог.
        from app.services.webhook_events import WEBHOOK_EVENTS

        if event not in WEBHOOK_EVENTS:
            logger.warning(
                "dispatch_event: неизвестное событие %r, dispatch пропущен", event
            )
            return []

        subscribed = await find_subscribed_webhooks(session, event)
        if not subscribed:
            return []

        payload = build_event_payload(event, entity_type, entity_id, data)
        now = datetime.now(UTC)
        deliveries: list[WebhookDelivery] = []
        for wh in subscribed:
            d = WebhookDelivery(
                webhook_id=wh.id,
                event=event,
                payload=payload,
                status="pending",
                attempt=0,
                next_retry_at=now,  # сразу готово к pick'у
            )
            session.add(d)
            deliveries.append(d)
        await session.flush()  # получить id
        return deliveries
    except Exception as e:  # noqa: BLE001
        logger.exception("dispatch_event failed (%s/%s/%s): %s",
                         event, entity_type, entity_id, e)
        return []


async def safe_dispatch_event(
    session: AsyncSession,
    event: str,
    entity_type: str,
    entity_id: int,
    data: dict[str, Any],
) -> None:
    """Inline-hook для роутеров: dispatch + commit + catch-all.

    Используется напрямую из роутеров после основной транзакции:
        await safe_dispatch_event(session, "lead.created", "lead", lead.id, lead_to_payload(lead))

    Не блокирует ответ — если dispatch упал, об этом будет в логе, но
    клиент 201/200 не пострадает.
    """
    try:
        await dispatch_event(session, event, entity_type, entity_id, data)
        await session.commit()
    except Exception as e:  # noqa: BLE001
        logger.exception(
            "safe_dispatch_event failed (%s/%s/%s): %s",
            event, entity_type, entity_id, e,
        )


# ============ Payload builders для inline hook'ов в роутерах ============
# Минимальный набор полей, который мы готовы отдавать наружу. Это публичный
# контракт — добавлять поля можно (back-compat), убирать — это breaking change.


def lead_to_payload(lead) -> dict[str, Any]:
    """Минимальный публичный snapshot Lead для webhook payload."""
    return {
        "id": lead.id,
        "name": lead.name,
        "contact_email": lead.contact_email,
        "contact_phone": lead.contact_phone,
        "source": lead.source,
        "owner_id": lead.owner_id,
        "pipeline_id": lead.pipeline_id,
        "stage_id": lead.stage_id,
        "status": lead.status,
        "score": lead.score,
        "converted_to_counterparty_id": lead.converted_to_counterparty_id,
        "converted_deal_id": lead.converted_deal_id,
    }


def deal_to_payload(deal) -> dict[str, Any]:
    """Минимальный публичный snapshot Deal для webhook payload."""
    return {
        "id": deal.id,
        "title": deal.title,
        "pipeline_id": deal.pipeline_id,
        "stage_id": deal.stage_id,
        "counterparty_id": deal.counterparty_id,
        "amount": float(deal.amount) if deal.amount is not None else None,
        "currency": deal.currency,
        "owner_user_id": deal.owner_user_id,
        "contract_id": deal.contract_id,
    }


def contract_to_payload(contract) -> dict[str, Any]:
    """Минимальный публичный snapshot Contract для webhook payload."""
    return {
        "id": contract.id,
        "number": contract.number,
        "title": contract.title,
        "product_code": contract.product_code,
        "country_code": contract.country_code,
        "counterparty_id": contract.counterparty_id,
        "status": contract.status.value if hasattr(contract.status, "value") else str(contract.status),
        "total": float(contract.total) if contract.total is not None else None,
        "currency": contract.currency,
    }


def counterparty_to_payload(cp) -> dict[str, Any]:
    """Минимальный публичный snapshot Counterparty для webhook payload."""
    return {
        "id": cp.id,
        "name": cp.name,
        "country_code": cp.country_code,
        "tax_id": cp.tax_id,
        "email": cp.email,
        "phone": cp.phone,
        "category_code": cp.category_code,
    }


def subscription_to_payload(sub) -> dict[str, Any]:
    """Минимальный публичный snapshot ClientSubscription для webhook payload."""
    return {
        "id": sub.id,
        "counterparty_id": sub.counterparty_id,
        "platform_id": sub.platform_id,
        "lifecycle_stage_id": sub.lifecycle_stage_id,
        "health_tier": sub.health_tier,
        "is_active": sub.is_active,
    }


async def deliver_one(
    session: AsyncSession,
    delivery: WebhookDelivery,
    client: httpx.AsyncClient | None = None,
) -> WebhookDelivery:
    """Попытаться доставить одну WebhookDelivery (одна HTTP-попытка).

    Финальный статус delivery:
    - 'success' — 2xx ответ, finished_at заполнен.
    - 'retrying' — fail, attempt+1, next_retry_at заполнен.
    - 'failed' — все attempts исчерпаны либо 4xx (не-retryable).

    Возвращает delivery (обновлён in-place).

    Tech Sprint Фаза 0: использует per-webhook max_attempts/backoff_seconds/
    timeout_seconds, если они есть в БД (миграция 0033). Иначе fallback на
    глобальные константы.
    """
    webhook = (
        await session.execute(
            select(Webhook).where(Webhook.id == delivery.webhook_id)
        )
    ).scalar_one_or_none()
    if webhook is None or not webhook.is_active:
        # Сценарий: webhook удалён или отключён между dispatch и delivery.
        delivery.status = "failed"
        delivery.last_error = "Webhook удалён или отключён"
        delivery.finished_at = datetime.now(UTC)
        delivery.next_retry_at = None
        return delivery

    delivery.attempt += 1

    # P0 SSRF guard: блокируем приватные/loopback/link-local (cloud-metadata)
    # таргеты ДО отправки. DNS резолвится при каждой попытке (адрес мог смениться).
    from app.services.ssrf_guard import SSRFBlockedError, assert_safe_webhook_url

    try:
        await assert_safe_webhook_url(webhook.url)
    except SSRFBlockedError as e:
        logger.warning(
            "webhook delivery %s blocked by SSRF guard: %s", delivery.id, e
        )
        delivery.status = "failed"
        delivery.last_http_code = None
        delivery.last_error = SSRFBlockedError.safe_reason
        delivery.last_response_body = None  # не храним ничего для блок-таргета
        delivery.finished_at = datetime.now(UTC)
        delivery.next_retry_at = None
        return delivery

    body_bytes = json.dumps(delivery.payload, ensure_ascii=False).encode("utf-8")
    signature = sign_body(webhook.secret, body_bytes)

    # Кастомные admin-заголовки кладём ПЕРВЫМИ, затем наши зарезервированные —
    # так наши Content-Type/подпись/метаданные всегда побеждают. filter_custom_
    # headers дополнительно вырезает попытку переопределить Host/Authorization
    # и т.п. (баг C4 WARN-3: header-injection усиливал SSRF).
    headers: dict[str, str] = filter_custom_headers(webhook.headers)
    headers.update(
        {
            "Content-Type": "application/json",
            SIGNATURE_HEADER: signature,
            "X-Macro-Event": delivery.event,
            "X-Macro-Delivery-Id": str(delivery.id),
            "User-Agent": "MACRO-CRM-Webhook/1.0",
        }
    )

    # Per-webhook timeout с back-compat fallback на глобальный.
    timeout_val = (
        getattr(webhook, "timeout_seconds", None) or HTTP_TIMEOUT_SECONDS
    )

    owns_client = client is None
    if client is None:
        # follow_redirects=False: 30x в internal URL иначе обходит SSRF-проверку.
        client = httpx.AsyncClient(
            timeout=float(timeout_val), follow_redirects=False
        )

    try:
        try:
            response = await client.post(
                webhook.url, content=body_bytes, headers=headers,
                timeout=float(timeout_val),
            )
            code = response.status_code
            body_text = response.text or ""
        except (httpx.RequestError, asyncio.TimeoutError) as e:
            # Сетевая ошибка / DNS / timeout — это retry-кандидат (баг #11: ловим
            # ТОЛЬКО транспортные ошибки httpx, не голый Exception — иначе баги в
            # коде маскировались бы под retry. Программные ошибки летят наверх в
            # scan_pending_deliveries, где log + continue по одной delivery).
            delivery.last_http_code = None
            delivery.last_error = f"{type(e).__name__}: {e}"[:512]
            delivery.last_response_body = None
            _apply_retry_or_fail(delivery, retryable=True, webhook=webhook)
            return delivery

        delivery.last_http_code = code
        delivery.last_error = None
        delivery.last_response_body = truncate_response_body(body_text)

        if 200 <= code <= 299:
            delivery.status = "success"
            delivery.finished_at = datetime.now(UTC)
            delivery.next_retry_at = None
            return delivery

        # Не-2xx: ретраим только retryable status'ы.
        if is_retryable_status(code):
            _apply_retry_or_fail(delivery, retryable=True, webhook=webhook)
        else:
            delivery.status = "failed"
            delivery.finished_at = datetime.now(UTC)
            delivery.next_retry_at = None
            delivery.last_error = (
                delivery.last_error
                or f"HTTP {code} (не-retryable клиентская ошибка)"
            )
        return delivery
    finally:
        if owns_client:
            await client.aclose()


def _apply_retry_or_fail(
    delivery: WebhookDelivery, retryable: bool, webhook: "Webhook" | None = None,
) -> None:
    """In-place: проставить status / next_retry_at в зависимости от attempt.

    Если attempt < MAX и retryable → 'retrying' + next_retry_at.
    Иначе → 'failed' + finished_at + next_retry_at=None.

    Если webhook передан и у него есть max_attempts/backoff_seconds — используем
    их (per-webhook). Иначе fallback на глобальные.
    """
    # Определяем эффективный max_attempts (per-webhook или глобальный)
    max_attempts = MAX_ATTEMPTS
    if webhook is not None:
        max_attempts = getattr(webhook, "max_attempts", None) or MAX_ATTEMPTS

    if not retryable or delivery.attempt >= max_attempts:
        delivery.status = "failed"
        delivery.finished_at = datetime.now(UTC)
        delivery.next_retry_at = None
        if delivery.last_error is None:
            delivery.last_error = (
                f"Достигнут максимум попыток ({max_attempts})"
            )
        return
    delivery.status = "retrying"
    # Используем per-webhook backoff если webhook передан, иначе глобальный.
    if webhook is not None:
        delivery.next_retry_at = schedule_next_retry_for_webhook(
            delivery.attempt, webhook,
        )
    else:
        delivery.next_retry_at = schedule_next_retry(delivery.attempt)
    if delivery.next_retry_at is None:
        # Этого быть не должно (attempt < max_attempts), но защитный pathway.
        delivery.status = "failed"
        delivery.finished_at = datetime.now(UTC)


async def scan_pending_deliveries(session: AsyncSession) -> int:
    """Cron-тик: забрать ready-to-retry deliveries и попробовать отправить.

    SELECT FOR UPDATE SKIP LOCKED — для безопасности при scale=2 (две реплики
    не возьмут одну delivery дважды). На SQLite SKIP LOCKED не существует —
    в тестовой среде fall-back к обычному SELECT (тестовый код не работает
    через scale=2, риск гонок отсутствует).

    Возвращает число обработанных deliveries в этом тике.
    """
    now = datetime.now(UTC)
    dialect = session.bind.dialect.name if session.bind is not None else ""
    stmt = (
        select(WebhookDelivery)
        .where(
            WebhookDelivery.status.in_(("pending", "retrying")),
            or_(
                WebhookDelivery.next_retry_at.is_(None),
                WebhookDelivery.next_retry_at <= now,
            ),
        )
        .order_by(WebhookDelivery.next_retry_at.asc().nullsfirst())
        .limit(CRON_BATCH_SIZE)
    )
    if dialect == "postgresql":
        stmt = stmt.with_for_update(skip_locked=True)
    rows = (await session.execute(stmt)).scalars().all()
    if not rows:
        return 0

    async with httpx.AsyncClient(
        timeout=HTTP_TIMEOUT_SECONDS, follow_redirects=False
    ) as client:
        for delivery in rows:
            try:
                await deliver_one(session, delivery, client=client)
            except Exception as e:  # noqa: BLE001
                # ОДНА сломанная delivery НЕ блокирует тик — log + continue.
                logger.exception(
                    "deliver_one failed (delivery_id=%s, webhook_id=%s): %s",
                    delivery.id, delivery.webhook_id, e,
                )
    await session.commit()
    return len(rows)
