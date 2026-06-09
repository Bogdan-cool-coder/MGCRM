"""Эпик 16 — Security: pure-function тесты rate-limit алгоритма.

Без сети, без Redis. compute_rate_limit_info — pure function (input → output).
check_rate_limit тестируем с моком redis (AsyncMock).
"""
from __future__ import annotations

import time
from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from app.services import rate_limit as rate_limit_module
from app.services import redis_client as redis_client_module
from app.services.rate_limit import (
    RATE_LIMIT_WINDOW_SECONDS,
    RateLimitInfo,
    check_rate_limit,
    compute_rate_limit_info,
)


# ============ RateLimitInfo dataclass ============


def test_rate_limit_info_headers_when_allowed():
    info = RateLimitInfo(
        limit=1000, remaining=999, reset_at=1700000000, retry_after_seconds=0,
    )
    h = info.to_headers()
    assert h["X-RateLimit-Limit"] == "1000"
    assert h["X-RateLimit-Remaining"] == "999"
    assert h["X-RateLimit-Reset"] == "1700000000"
    # Retry-After НЕ ставим если allowed
    assert "Retry-After" not in h


def test_rate_limit_info_headers_when_blocked_includes_retry_after():
    info = RateLimitInfo(
        limit=1000, remaining=0, reset_at=1700000000, retry_after_seconds=240,
    )
    h = info.to_headers()
    assert h["X-RateLimit-Remaining"] == "0"
    assert h["Retry-After"] == "240"


def test_rate_limit_info_remaining_floor_at_zero():
    """Если remaining ушёл в минус (баг где-то выше) — в headers 0, не отрицательное."""
    info = RateLimitInfo(
        limit=100, remaining=-5, reset_at=1700000000, retry_after_seconds=10,
    )
    assert info.to_headers()["X-RateLimit-Remaining"] == "0"


# ============ compute_rate_limit_info ============


def test_compute_rate_limit_first_request_allowed():
    allowed, info = compute_rate_limit_info(
        current_count=1, limit=1000, ttl_seconds=3600,
    )
    assert allowed is True
    assert info.limit == 1000
    assert info.remaining == 999
    assert info.retry_after_seconds == 0


def test_compute_rate_limit_at_limit_still_allowed():
    """count == limit — последний разрешённый запрос."""
    allowed, info = compute_rate_limit_info(
        current_count=1000, limit=1000, ttl_seconds=10,
    )
    assert allowed is True
    assert info.remaining == 0


def test_compute_rate_limit_over_limit_blocked():
    allowed, info = compute_rate_limit_info(
        current_count=1001, limit=1000, ttl_seconds=120,
    )
    assert allowed is False
    assert info.remaining == 0
    assert info.retry_after_seconds == 120


def test_compute_rate_limit_negative_ttl_uses_full_window():
    """TTL = -2 (Redis: ключ не существует) → берём полное окно для reset_at."""
    allowed, info = compute_rate_limit_info(
        current_count=1, limit=10, ttl_seconds=-2,
    )
    assert allowed is True
    # reset_at примерно now + 3600
    now = int(time.time())
    assert info.reset_at >= now + RATE_LIMIT_WINDOW_SECONDS - 2
    assert info.reset_at <= now + RATE_LIMIT_WINDOW_SECONDS + 2


# ============ check_rate_limit с mock Redis ============


@pytest.fixture
def _reset_redis():
    """Сбросить singleton Redis client после каждого теста."""
    yield
    redis_client_module.reset_redis_client()


async def test_check_rate_limit_noop_redis_always_allows(monkeypatch, _reset_redis):
    """Без REDIS_URL → Noop client → всегда allowed (graceful fallback)."""

    class _S:
        redis_url = ""

    monkeypatch.setattr(redis_client_module, "get_settings", lambda: _S())
    # Сброс singleton чтобы взяли новый settings
    redis_client_module.reset_redis_client()

    allowed, info = await check_rate_limit("fake_hash", 1000)
    assert allowed is True
    assert info.limit == 1000
    assert info.remaining == 1000


async def test_check_rate_limit_first_call_sets_expire(monkeypatch, _reset_redis):
    """При первом INCR (count=1) должны вызвать EXPIRE."""
    mock_redis = SimpleNamespace(
        incr=AsyncMock(return_value=1),
        expire=AsyncMock(return_value=True),
        ttl=AsyncMock(return_value=3600),
    )
    monkeypatch.setattr(rate_limit_module, "get_redis", lambda: mock_redis)
    monkeypatch.setattr(rate_limit_module, "is_noop_redis", lambda r: False)

    allowed, info = await check_rate_limit("h1", 1000)
    assert allowed is True
    mock_redis.expire.assert_awaited_once()
    assert info.remaining == 999


async def test_check_rate_limit_second_call_no_expire(monkeypatch, _reset_redis):
    """При count > 1 EXPIRE НЕ должен вызываться (окно идёт от первого)."""
    mock_redis = SimpleNamespace(
        incr=AsyncMock(return_value=2),
        expire=AsyncMock(),
        ttl=AsyncMock(return_value=3500),
    )
    monkeypatch.setattr(rate_limit_module, "get_redis", lambda: mock_redis)
    monkeypatch.setattr(rate_limit_module, "is_noop_redis", lambda r: False)

    allowed, info = await check_rate_limit("h1", 1000)
    assert allowed is True
    mock_redis.expire.assert_not_awaited()
    assert info.remaining == 998


async def test_check_rate_limit_blocks_over_limit(monkeypatch, _reset_redis):
    """count > limit → blocked + retry_after = ttl."""
    mock_redis = SimpleNamespace(
        incr=AsyncMock(return_value=1001),
        expire=AsyncMock(),
        ttl=AsyncMock(return_value=42),
    )
    monkeypatch.setattr(rate_limit_module, "get_redis", lambda: mock_redis)
    monkeypatch.setattr(rate_limit_module, "is_noop_redis", lambda r: False)

    allowed, info = await check_rate_limit("h1", 1000)
    assert allowed is False
    assert info.retry_after_seconds == 42


async def test_check_rate_limit_redis_exception_fail_open(monkeypatch, _reset_redis):
    """Redis выбросил ошибку в рантайме → fail-open: allowed=True (не блокируем)."""
    mock_redis = SimpleNamespace(
        incr=AsyncMock(side_effect=ConnectionError("redis down")),
        expire=AsyncMock(),
        ttl=AsyncMock(),
    )
    monkeypatch.setattr(rate_limit_module, "get_redis", lambda: mock_redis)
    monkeypatch.setattr(rate_limit_module, "is_noop_redis", lambda r: False)

    allowed, info = await check_rate_limit("h1", 1000)
    assert allowed is True
    assert info.remaining == 1000


# ============ Public endpoints fail-policy (C4 WARN-1 + WARN-2) ============

from app.services.rate_limit import (  # noqa: E402
    INBOUND_WEBHOOK_RATE_LIMIT_PER_WINDOW,
    check_form_rate_limit,
    check_inbound_webhook_rate_limit,
)


async def test_form_rate_limit_no_ip_allows():
    assert await check_form_rate_limit("slug", None) is True


async def test_form_rate_limit_noop_redis_fail_open(monkeypatch, _reset_redis):
    """Noop Redis (REDIS_URL не задан) → fail-OPEN на публичной форме."""
    monkeypatch.setattr(rate_limit_module, "is_noop_redis", lambda r: True)
    assert await check_form_rate_limit("slug", "1.2.3.4") is True


async def test_form_rate_limit_redis_error_fail_closed(monkeypatch, _reset_redis):
    """Redis сконфигурен, но упал → fail-CLOSED (WARN-2): защита не молчит."""
    mock_redis = SimpleNamespace(
        incr=AsyncMock(side_effect=ConnectionError("down")),
        expire=AsyncMock(),
    )
    monkeypatch.setattr(rate_limit_module, "get_redis", lambda: mock_redis)
    monkeypatch.setattr(rate_limit_module, "is_noop_redis", lambda r: False)
    assert await check_form_rate_limit("slug", "1.2.3.4") is False


async def test_inbound_webhook_no_ip_allows():
    assert await check_inbound_webhook_rate_limit(1, None) is True


async def test_inbound_webhook_noop_redis_fail_open(monkeypatch, _reset_redis):
    monkeypatch.setattr(rate_limit_module, "is_noop_redis", lambda r: True)
    assert await check_inbound_webhook_rate_limit(1, "1.2.3.4") is True


async def test_inbound_webhook_under_limit_allows(monkeypatch, _reset_redis):
    mock_redis = SimpleNamespace(
        incr=AsyncMock(return_value=1),
        expire=AsyncMock(return_value=True),
    )
    monkeypatch.setattr(rate_limit_module, "get_redis", lambda: mock_redis)
    monkeypatch.setattr(rate_limit_module, "is_noop_redis", lambda r: False)
    assert await check_inbound_webhook_rate_limit(7, "9.9.9.9") is True
    mock_redis.expire.assert_awaited_once()


async def test_inbound_webhook_over_limit_blocks(monkeypatch, _reset_redis):
    mock_redis = SimpleNamespace(
        incr=AsyncMock(return_value=INBOUND_WEBHOOK_RATE_LIMIT_PER_WINDOW + 1),
        expire=AsyncMock(),
    )
    monkeypatch.setattr(rate_limit_module, "get_redis", lambda: mock_redis)
    monkeypatch.setattr(rate_limit_module, "is_noop_redis", lambda r: False)
    assert await check_inbound_webhook_rate_limit(7, "9.9.9.9") is False


async def test_inbound_webhook_redis_error_fail_closed(monkeypatch, _reset_redis):
    """C4 WARN-1+WARN-2: публичный inbound webhook fail-closed при Redis-сбое."""
    mock_redis = SimpleNamespace(
        incr=AsyncMock(side_effect=ConnectionError("down")),
        expire=AsyncMock(),
    )
    monkeypatch.setattr(rate_limit_module, "get_redis", lambda: mock_redis)
    monkeypatch.setattr(rate_limit_module, "is_noop_redis", lambda r: False)
    assert await check_inbound_webhook_rate_limit(7, "9.9.9.9") is False
