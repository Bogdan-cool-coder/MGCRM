"""Эпик 16 — Security: pure-function тесты 2FA validate rate-limit.

Тестируем `compute_auth_rate_limit_decision` без Redis (pure function).
Тестируем `check_2fa_validate_rate_limit` с моком Redis (AsyncMock).
"""
from __future__ import annotations

from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from app.services import auth_rate_limit as arl_module
from app.services import redis_client as redis_client_module
from app.services.auth_rate_limit import (
    AUTH_2FA_VALIDATE_LIMIT,
    AUTH_2FA_VALIDATE_WINDOW_SECONDS,
    check_2fa_validate_rate_limit,
    compute_auth_rate_limit_decision,
)


# ============ compute_auth_rate_limit_decision (pure) ============


def test_noop_count_zero_is_allowed():
    """Когда Redis Noop возвращает 0 (count) — мы трактуем как allowed."""
    allowed, retry = compute_auth_rate_limit_decision(0, -2, AUTH_2FA_VALIDATE_LIMIT)
    assert allowed is True
    assert retry == 0


def test_first_attempt_allowed():
    allowed, retry = compute_auth_rate_limit_decision(1, 900, AUTH_2FA_VALIDATE_LIMIT)
    assert allowed is True
    assert retry == 0


def test_below_limit_allowed():
    """5 — лимит; 5-я попытка ещё allowed (count <= limit)."""
    allowed, _ = compute_auth_rate_limit_decision(
        AUTH_2FA_VALIDATE_LIMIT, 300, AUTH_2FA_VALIDATE_LIMIT,
    )
    assert allowed is True


def test_over_limit_blocked_with_retry_after():
    allowed, retry = compute_auth_rate_limit_decision(
        AUTH_2FA_VALIDATE_LIMIT + 1, 600, AUTH_2FA_VALIDATE_LIMIT,
    )
    assert allowed is False
    assert retry == 600


def test_over_limit_no_ttl_falls_back_to_window():
    """Если TTL -1/0 (нет TTL по какой-то причине) — отдаём полный window
    в retry_after, чтобы не вернуть 0."""
    allowed, retry = compute_auth_rate_limit_decision(
        AUTH_2FA_VALIDATE_LIMIT + 1, -1, AUTH_2FA_VALIDATE_LIMIT,
    )
    assert allowed is False
    assert retry == AUTH_2FA_VALIDATE_WINDOW_SECONDS

    allowed, retry = compute_auth_rate_limit_decision(
        AUTH_2FA_VALIDATE_LIMIT + 1, 0, AUTH_2FA_VALIDATE_LIMIT,
    )
    assert allowed is False
    assert retry == AUTH_2FA_VALIDATE_WINDOW_SECONDS


def test_custom_limit_respected():
    """Лимит — параметр; тестируем что 3 — наш custom лимит."""
    allowed, _ = compute_auth_rate_limit_decision(3, 100, 3)
    assert allowed is True
    allowed, retry = compute_auth_rate_limit_decision(4, 100, 3)
    assert allowed is False
    assert retry == 100


# ============ check_2fa_validate_rate_limit (с моком Redis) ============


@pytest.fixture
def mock_redis(monkeypatch):
    """Подменить get_redis на AsyncMock — для тестирования без живого Redis."""
    redis_mock = SimpleNamespace(
        incr=AsyncMock(return_value=1),
        expire=AsyncMock(return_value=True),
        ttl=AsyncMock(return_value=900),
    )
    # is_noop_redis должен вернуть False (мы хотим тестировать активный Redis path).
    monkeypatch.setattr(arl_module, "get_redis", lambda: redis_mock)
    monkeypatch.setattr(arl_module, "is_noop_redis", lambda c: False)
    return redis_mock


@pytest.fixture
def noop_redis_env(monkeypatch):
    """Подменить get_redis на noop-инстанс. Используется для проверки graceful fallback."""
    # Реальный _NoopRedis из app.services.redis_client.
    noop = redis_client_module._NoopRedis()
    monkeypatch.setattr(arl_module, "get_redis", lambda: noop)
    monkeypatch.setattr(arl_module, "is_noop_redis", lambda c: True)
    return noop


@pytest.mark.asyncio
async def test_first_attempt_calls_expire(mock_redis):
    mock_redis.incr.return_value = 1
    allowed, retry = await check_2fa_validate_rate_limit("1.2.3.4", 42)
    assert allowed is True
    assert retry == 0
    mock_redis.incr.assert_awaited_once()
    mock_redis.expire.assert_awaited_once()


@pytest.mark.asyncio
async def test_second_attempt_does_not_call_expire(mock_redis):
    mock_redis.incr.return_value = 2
    allowed, _ = await check_2fa_validate_rate_limit("1.2.3.4", 42)
    assert allowed is True
    mock_redis.expire.assert_not_called()


@pytest.mark.asyncio
async def test_over_limit_returns_429_with_retry_after(mock_redis):
    mock_redis.incr.return_value = AUTH_2FA_VALIDATE_LIMIT + 1
    mock_redis.ttl.return_value = 720
    allowed, retry = await check_2fa_validate_rate_limit("1.2.3.4", 42)
    assert allowed is False
    assert retry == 720


@pytest.mark.asyncio
async def test_redis_exception_failopen(mock_redis):
    """Если Redis в рантайме упал — пропускаем (fail-open). Иначе при сбое
    инфры все пользователи будут залочены."""
    mock_redis.incr.side_effect = RuntimeError("Redis down")
    allowed, retry = await check_2fa_validate_rate_limit("1.2.3.4", 42)
    assert allowed is True
    assert retry == 0


@pytest.mark.asyncio
async def test_noop_redis_always_allowed(noop_redis_env):
    """Если REDIS_URL не задан → fallback всегда allowed."""
    allowed, retry = await check_2fa_validate_rate_limit("1.2.3.4", 42)
    assert allowed is True
    assert retry == 0
