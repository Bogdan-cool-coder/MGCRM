"""Tech Sprint Фаза 0 (задача 4): pure-function тесты для webhook retry settings.

Без БД — мокаем Webhook через MagicMock. Проверяем:
1. compute_per_webhook_backoff — экспоненциальный backoff с per-webhook params.
2. schedule_next_retry_for_webhook — обёртка с datetime.
3. _apply_retry_or_fail с per-webhook settings — max_attempts override.
4. WebhookCreate / WebhookUpdate Pydantic — поля доступны и валидируются.
5. WebhookOut.from_orm_safe — экспортирует new поля + back-compat fallback.
"""
from __future__ import annotations

from datetime import UTC, datetime, timedelta
from unittest.mock import MagicMock

import pytest

from app.models import Webhook, WebhookDelivery
from app.routers.webhooks import (
    WebhookCreate,
    WebhookOut,
    WebhookUpdate,
)
from app.services.webhook_dispatcher import (
    MAX_ATTEMPTS,
    _apply_retry_or_fail,
    compute_per_webhook_backoff,
    schedule_next_retry_for_webhook,
)


def _make_webhook(
    max_attempts: int = 5, backoff_seconds: int = 60, timeout_seconds: int = 10,
) -> Webhook:
    """Минимальный Webhook stub без БД."""
    wh = Webhook(
        name="test", url="https://example.com",
        secret="secret_minimum_16_chars",
        event_subscriptions=["*"],
    )
    wh.max_attempts = max_attempts
    wh.backoff_seconds = backoff_seconds
    wh.timeout_seconds = timeout_seconds
    wh.is_active = True
    wh.headers = None
    return wh


# ============ compute_per_webhook_backoff ============


def test_compute_backoff_first_attempt():
    """1-я попытка → backoff_seconds (без экспоненты)."""
    assert compute_per_webhook_backoff(1, backoff_seconds=60, max_attempts=5) == 60


def test_compute_backoff_exponential():
    """Экспонента: 60 → 120 → 240 → 480 → ..."""
    assert compute_per_webhook_backoff(1, 60, 5) == 60
    assert compute_per_webhook_backoff(2, 60, 5) == 120
    assert compute_per_webhook_backoff(3, 60, 5) == 240
    assert compute_per_webhook_backoff(4, 60, 5) == 480


def test_compute_backoff_max_reached_returns_none():
    """attempt >= max_attempts → None (терминальный fail)."""
    assert compute_per_webhook_backoff(5, 60, 5) is None
    assert compute_per_webhook_backoff(6, 60, 5) is None
    assert compute_per_webhook_backoff(100, 60, 5) is None


def test_compute_backoff_custom_max():
    """Кастомный max=3 — после 3-й попытки None."""
    assert compute_per_webhook_backoff(2, 60, 3) == 120
    assert compute_per_webhook_backoff(3, 60, 3) is None


def test_compute_backoff_zero_attempt():
    """attempt_after=0 → backoff_seconds (защита от minus indexing)."""
    assert compute_per_webhook_backoff(0, 60, 5) == 60


def test_compute_backoff_large_backoff():
    """600s base → 600, 1200, 2400, ..."""
    assert compute_per_webhook_backoff(1, 600, 5) == 600
    assert compute_per_webhook_backoff(3, 600, 5) == 2400


# ============ schedule_next_retry_for_webhook ============


def test_schedule_next_retry_uses_webhook_settings():
    """Webhook с backoff=120 → next_retry_at = now + 120s."""
    wh = _make_webhook(max_attempts=5, backoff_seconds=120)
    before = datetime.now(UTC)
    result = schedule_next_retry_for_webhook(1, wh)
    after = datetime.now(UTC)
    assert result is not None
    # Должно быть примерно before+120 ... after+120 (мс-разница)
    expected_lo = before + timedelta(seconds=120)
    expected_hi = after + timedelta(seconds=120)
    assert expected_lo <= result <= expected_hi


def test_schedule_next_retry_max_reached_returns_none():
    """attempt >= max_attempts → None."""
    wh = _make_webhook(max_attempts=3, backoff_seconds=60)
    assert schedule_next_retry_for_webhook(3, wh) is None


def test_schedule_next_retry_fallback_when_no_settings():
    """Старый Webhook (без max_attempts атрибута) → используется MAX_ATTEMPTS."""
    wh = MagicMock()
    # Имитируем «нет атрибутов» — getattr вернёт None
    wh.max_attempts = None
    wh.backoff_seconds = None
    result = schedule_next_retry_for_webhook(1, wh)
    assert result is not None


# ============ _apply_retry_or_fail с per-webhook ============


def _make_delivery(attempt: int = 0) -> WebhookDelivery:
    d = WebhookDelivery(webhook_id=1, event="test.event", payload={}, status="pending")
    d.attempt = attempt
    d.last_error = None
    return d


def test_apply_retry_uses_webhook_max_attempts():
    """Webhook max_attempts=3 → после 3-й попытки → failed (не глобальный 6)."""
    wh = _make_webhook(max_attempts=3, backoff_seconds=60)
    d = _make_delivery(attempt=3)
    _apply_retry_or_fail(d, retryable=True, webhook=wh)
    assert d.status == "failed"
    assert d.next_retry_at is None
    assert d.finished_at is not None
    assert d.last_error is not None and "3" in d.last_error


def test_apply_retry_uses_webhook_backoff():
    """С webhook'ом → backoff из webhook'а, не RETRY_SCHEDULE_SECONDS."""
    wh = _make_webhook(max_attempts=5, backoff_seconds=300)
    d = _make_delivery(attempt=1)
    before = datetime.now(UTC)
    _apply_retry_or_fail(d, retryable=True, webhook=wh)
    assert d.status == "retrying"
    assert d.next_retry_at is not None
    # 300s ± немного
    delta = (d.next_retry_at - before).total_seconds()
    assert 295 <= delta <= 305


def test_apply_retry_non_retryable_immediate_fail():
    """retryable=False → failed немедленно (4xx например)."""
    wh = _make_webhook(max_attempts=5)
    d = _make_delivery(attempt=1)
    _apply_retry_or_fail(d, retryable=False, webhook=wh)
    assert d.status == "failed"
    assert d.next_retry_at is None


def test_apply_retry_fallback_without_webhook():
    """Без webhook параметра → fallback на глобальный MAX_ATTEMPTS."""
    d = _make_delivery(attempt=1)
    _apply_retry_or_fail(d, retryable=True, webhook=None)
    assert d.status == "retrying"
    assert d.next_retry_at is not None  # глобальный schedule вернул что-то


def test_apply_retry_fallback_max_attempts():
    """Без webhook → MAX_ATTEMPTS как граница."""
    d = _make_delivery(attempt=MAX_ATTEMPTS)
    _apply_retry_or_fail(d, retryable=True, webhook=None)
    assert d.status == "failed"


# ============ Pydantic schemas ============


def test_webhook_create_has_new_fields():
    """WebhookCreate включает max_attempts/backoff_seconds/timeout_seconds c дефолтами."""
    data = WebhookCreate(
        name="my-hook",
        url="https://example.com/hook",
        event_subscriptions=["lead.created"],
    )
    assert data.max_attempts == 5
    assert data.backoff_seconds == 60
    assert data.timeout_seconds == 10


def test_webhook_create_validates_max_attempts_bounds():
    """max_attempts ge=1, le=20."""
    with pytest.raises(ValueError):
        WebhookCreate(
            name="x", url="https://example.com",
            event_subscriptions=[],
            max_attempts=0,
        )
    with pytest.raises(ValueError):
        WebhookCreate(
            name="x", url="https://example.com",
            event_subscriptions=[],
            max_attempts=21,
        )


def test_webhook_create_validates_backoff_bounds():
    """backoff_seconds ge=1, le=86400 (24h)."""
    with pytest.raises(ValueError):
        WebhookCreate(
            name="x", url="https://example.com",
            event_subscriptions=[],
            backoff_seconds=0,
        )
    with pytest.raises(ValueError):
        WebhookCreate(
            name="x", url="https://example.com",
            event_subscriptions=[],
            backoff_seconds=86401,
        )


def test_webhook_create_validates_timeout_bounds():
    """timeout_seconds ge=1, le=300 (5min)."""
    with pytest.raises(ValueError):
        WebhookCreate(
            name="x", url="https://example.com",
            event_subscriptions=[],
            timeout_seconds=0,
        )
    with pytest.raises(ValueError):
        WebhookCreate(
            name="x", url="https://example.com",
            event_subscriptions=[],
            timeout_seconds=301,
        )


def test_webhook_update_optional_settings():
    """WebhookUpdate — все опционально."""
    upd = WebhookUpdate(max_attempts=10)
    assert upd.max_attempts == 10
    assert upd.backoff_seconds is None
    assert upd.timeout_seconds is None


def test_webhook_out_from_orm_safe_uses_settings():
    """WebhookOut.from_orm_safe берёт настройки из ORM (или дефолты)."""
    wh = _make_webhook(max_attempts=7, backoff_seconds=120, timeout_seconds=30)
    wh.id = 1
    wh.event_subscriptions = ["lead.created"]
    wh.created_by_user_id = None
    wh.created_at = datetime.now(UTC)
    wh.updated_at = datetime.now(UTC)
    out = WebhookOut.from_orm_safe(wh)
    assert out.max_attempts == 7
    assert out.backoff_seconds == 120
    assert out.timeout_seconds == 30


def test_webhook_out_from_orm_safe_fallback_defaults():
    """Если у ORM нет полей (старый Webhook до миграции) — дефолты."""
    wh = MagicMock(spec=Webhook)
    wh.id = 1
    wh.name = "old-hook"
    wh.url = "https://example.com"
    wh.secret = "secret_minimum_16_chars"
    wh.event_subscriptions = ["lead.created"]
    wh.is_active = True
    wh.headers = None
    wh.max_attempts = None  # имитируем отсутствие после миграции
    wh.backoff_seconds = None
    wh.timeout_seconds = None
    wh.created_by_user_id = None
    wh.created_at = datetime.now(UTC)
    wh.updated_at = datetime.now(UTC)
    out = WebhookOut.from_orm_safe(wh)
    # Дефолты-fallback
    assert out.max_attempts == 5
    assert out.backoff_seconds == 60
    assert out.timeout_seconds == 10
