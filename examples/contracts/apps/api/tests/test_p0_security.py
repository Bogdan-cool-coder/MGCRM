"""P0 Security sprint, Unit 1 — pure-function + mock-Redis тесты.

Покрывает:
- Слабый JWT-секрет predicate + production startup-guard.
- tg-bot verify_bot_token fail-CLOSED (503 без секрета, 403 при mismatch).
- SSO email_verified + domain allowlist (всех провайдеров) guard.
- Rate-limit window math (login/tg-intent/ai/broadcast) + in-process fallback.

Все тесты pure-function ИЛИ с моком Redis — без живого Redis / DB / сети.
"""
from __future__ import annotations

from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from app.config import (
    MIN_JWT_SECRET_LENGTH,
    Settings,
    is_weak_jwt_secret,
)
from app.services import auth_rate_limit as arl
from app.services.auth_rate_limit import (
    LOGIN_RATE_LIMIT_PER_WINDOW,
    LOGIN_RATE_LIMIT_WINDOW_SECONDS,
    TG_INTENT_RATE_LIMIT_PER_WINDOW,
    check_ai_user_rate_limit,
    check_broadcast_rate_limit,
    check_login_rate_limit,
    check_tg_intent_rate_limit,
    reset_fallback_counters,
)
from app.services.sso import (
    SSOUserInfo,
    is_domain_allowed,
    is_sso_identity_trusted,
    parse_google_userinfo,
    parse_yandex_userinfo,
)


# ============ Fix 2: weak JWT secret guard ============


def test_default_change_me_is_weak():
    assert is_weak_jwt_secret("change-me") is True


def test_blacklisted_defaults_are_weak():
    for s in [
        "changeme",
        "changeme_use_openssl_rand_hex_64",
        "dev-secret-replace-in-production",
        "secret",
        "",
    ]:
        assert is_weak_jwt_secret(s) is True


def test_short_secret_is_weak():
    assert is_weak_jwt_secret("a" * (MIN_JWT_SECRET_LENGTH - 1)) is True


def test_strong_secret_is_ok():
    strong = "f3a9" * 32  # 128 chars, не в чёрном списке
    assert is_weak_jwt_secret(strong) is False


def test_whitespace_stripped_before_check():
    assert is_weak_jwt_secret("  change-me  ") is True


def test_validate_production_secrets_raises_on_weak():
    s = Settings(app_env="production", jwt_secret="change-me")
    with pytest.raises(RuntimeError, match="JWT_SECRET"):
        s.validate_production_secrets()


def test_validate_production_secrets_ok_on_strong():
    s = Settings(app_env="production", jwt_secret="f3a9" * 32)
    s.validate_production_secrets()  # не должно бросить


def test_validate_dev_noop_even_with_default_secret():
    s = Settings(app_env="development", jwt_secret="change-me")
    s.validate_production_secrets()  # dev — no-op


def test_is_production_default_true():
    # Дефолт app_env='production' → is_production True.
    assert Settings(jwt_secret="x" * 40).is_production is True
    assert Settings(app_env="development", jwt_secret="x").is_production is False
    assert Settings(app_env="DEVELOPMENT", jwt_secret="x").is_production is False


# ============ Fix 1: tg-bot verify_bot_token fail-CLOSED ============


@pytest.mark.asyncio
async def test_verify_bot_token_fail_closed_when_no_secret(monkeypatch):
    """Без секрета — 503 для ВСЕХ (никогда не allow)."""
    from fastapi import HTTPException

    from app.routers import tg_bot

    monkeypatch.setattr(
        tg_bot, "get_settings", lambda: SimpleNamespace(tg_bot_api_secret="")
    )
    with pytest.raises(HTTPException) as exc:
        await tg_bot.verify_bot_token(authorization="Bearer anything")
    assert exc.value.status_code == 503


@pytest.mark.asyncio
async def test_verify_bot_token_401_without_bearer(monkeypatch):
    from fastapi import HTTPException

    from app.routers import tg_bot

    monkeypatch.setattr(
        tg_bot, "get_settings", lambda: SimpleNamespace(tg_bot_api_secret="topsecret")
    )
    with pytest.raises(HTTPException) as exc:
        await tg_bot.verify_bot_token(authorization=None)
    assert exc.value.status_code == 401


@pytest.mark.asyncio
async def test_verify_bot_token_403_on_mismatch(monkeypatch):
    from fastapi import HTTPException

    from app.routers import tg_bot

    monkeypatch.setattr(
        tg_bot, "get_settings", lambda: SimpleNamespace(tg_bot_api_secret="topsecret")
    )
    with pytest.raises(HTTPException) as exc:
        await tg_bot.verify_bot_token(authorization="Bearer wrong")
    assert exc.value.status_code == 403


@pytest.mark.asyncio
async def test_verify_bot_token_passes_on_match(monkeypatch):
    from app.routers import tg_bot

    monkeypatch.setattr(
        tg_bot, "get_settings", lambda: SimpleNamespace(tg_bot_api_secret="topsecret")
    )
    # Не должно бросить.
    await tg_bot.verify_bot_token(authorization="Bearer topsecret")


# ============ Fix 3: SSO email_verified + domain allowlist ============


def test_google_email_verified_parsed_bool():
    info = parse_google_userinfo(
        {"sub": "1", "email": "a@b.com", "email_verified": True, "name": "X"}
    )
    assert info.email_verified is True


def test_google_email_verified_parsed_string_true():
    info = parse_google_userinfo(
        {"sub": "1", "email": "a@b.com", "email_verified": "true", "name": "X"}
    )
    assert info.email_verified is True


def test_google_email_unverified_default_false():
    info = parse_google_userinfo({"sub": "1", "email": "a@b.com", "name": "X"})
    assert info.email_verified is False


def test_yandex_default_email_treated_verified():
    info = parse_yandex_userinfo({"id": "9", "default_email": "u@yandex.ru"})
    assert info.email_verified is True


def test_yandex_login_fallback_not_verified():
    # email берётся из login (нет default_email/emails) → не доверяем.
    info = parse_yandex_userinfo({"id": "9", "login": "bob"})
    assert info.email_verified is False


def _info(provider="google", email="u@macroglobaltech.com", hd=None, verified=True):
    return SSOUserInfo(
        provider=provider,
        provider_user_id="1",
        email=email,
        name="X",
        hd=hd,
        email_verified=verified,
    )


def test_domain_allowlist_empty_permits_any():
    assert is_domain_allowed(_info(email="x@gmail.com"), "") is True


def test_domain_allowlist_google_hd_match():
    assert is_domain_allowed(_info(hd="macroglobaltech.com"), "macroglobaltech.com") is True


def test_domain_allowlist_google_email_match_without_hd():
    assert is_domain_allowed(_info(hd=None), "macroglobaltech.com") is True


def test_domain_allowlist_blocks_foreign_domain():
    assert is_domain_allowed(_info(email="x@gmail.com", hd=None), "macroglobaltech.com") is False


def test_domain_allowlist_enforced_for_yandex():
    """Раньше Yandex обходил allowlist — теперь email-домен проверяется."""
    info = _info(provider="yandex", email="x@yandex.ru")
    assert is_domain_allowed(info, "macroglobaltech.com") is False
    info_ok = _info(provider="yandex", email="x@macroglobaltech.com")
    assert is_domain_allowed(info_ok, "macroglobaltech.com") is True


def test_trusted_requires_verified_email():
    ok, err = is_sso_identity_trusted(_info(verified=False), "macroglobaltech.com")
    assert ok is False
    assert err == "email_not_verified"


def test_trusted_requires_allowed_domain():
    ok, err = is_sso_identity_trusted(
        _info(email="x@gmail.com", verified=True), "macroglobaltech.com"
    )
    assert ok is False
    assert err == "domain_not_allowed"


def test_trusted_happy_path():
    ok, err = is_sso_identity_trusted(
        _info(hd="macroglobaltech.com", verified=True), "macroglobaltech.com"
    )
    assert ok is True
    assert err == ""


# ============ Fix 4: rate-limit window math + fallback ============


@pytest.fixture(autouse=True)
def _reset_fallback():
    reset_fallback_counters()
    yield
    reset_fallback_counters()


def test_fallback_sliding_window_allows_under_limit():
    # 10 hits под лимитом 10 → все allowed; 11-й → отказ.
    for _ in range(LOGIN_RATE_LIMIT_PER_WINDOW):
        assert arl._fallback_sliding_window("k", 10, 60) is True
    assert arl._fallback_sliding_window("k", 10, 60) is False


def test_fallback_sliding_window_expires_old_hits():
    # Старый hit (now-100s) не считается при window=60.
    arl._fallback_sliding_window("k", 1, 60, now=0.0)
    # Спустя 100s — окно пустое → снова allowed.
    assert arl._fallback_sliding_window("k", 1, 60, now=100.0) is True


@pytest.fixture
def redis_seq(monkeypatch):
    """Мок Redis с управляемой последовательностью INCR-значений."""
    state = {"count": 0}

    async def incr(key):
        state["count"] += 1
        return state["count"]

    redis_mock = SimpleNamespace(
        incr=AsyncMock(side_effect=incr),
        expire=AsyncMock(return_value=True),
        ttl=AsyncMock(return_value=60),
    )
    monkeypatch.setattr(arl, "get_redis", lambda: redis_mock)
    monkeypatch.setattr(arl, "is_noop_redis", lambda c: False)
    return redis_mock


@pytest.mark.asyncio
async def test_login_rate_limit_allows_then_blocks(monkeypatch):
    # Свой счётчик per-key (ip и email — разные ключи).
    counts: dict[str, int] = {}

    async def incr(key):
        counts[key] = counts.get(key, 0) + 1
        return counts[key]

    redis_mock = SimpleNamespace(
        incr=AsyncMock(side_effect=incr),
        expire=AsyncMock(return_value=True),
        ttl=AsyncMock(return_value=60),
    )
    monkeypatch.setattr(arl, "get_redis", lambda: redis_mock)
    monkeypatch.setattr(arl, "is_noop_redis", lambda c: False)

    allowed_last = True
    for _ in range(LOGIN_RATE_LIMIT_PER_WINDOW):
        allowed_last, _ = await check_login_rate_limit("1.2.3.4", "a@b.com")
        assert allowed_last is True
    # Следующая (11-я) попытка — блок.
    allowed, retry = await check_login_rate_limit("1.2.3.4", "a@b.com")
    assert allowed is False
    assert retry == LOGIN_RATE_LIMIT_WINDOW_SECONDS


@pytest.mark.asyncio
async def test_login_rate_limit_fail_closed_on_noop_redis(monkeypatch):
    """Redis Noop → используем in-process fallback (НЕ fail-open)."""
    monkeypatch.setattr(arl, "get_redis", lambda: SimpleNamespace())
    monkeypatch.setattr(arl, "is_noop_redis", lambda c: True)

    # Под лимитом — allowed.
    for _ in range(LOGIN_RATE_LIMIT_PER_WINDOW):
        allowed, _ = await check_login_rate_limit("9.9.9.9", "z@z.com")
        assert allowed is True
    # Превышение — fallback блокирует (доказывает НЕ fail-open).
    allowed, retry = await check_login_rate_limit("9.9.9.9", "z@z.com")
    assert allowed is False
    assert retry == LOGIN_RATE_LIMIT_WINDOW_SECONDS


@pytest.mark.asyncio
async def test_login_rate_limit_redis_exception_fail_closed(monkeypatch):
    """Redis бросает в рантайме → fallback (fail-CLOSED), не пропуск всех."""
    redis_mock = SimpleNamespace(
        incr=AsyncMock(side_effect=RuntimeError("down")),
        expire=AsyncMock(),
        ttl=AsyncMock(),
    )
    monkeypatch.setattr(arl, "get_redis", lambda: redis_mock)
    monkeypatch.setattr(arl, "is_noop_redis", lambda c: False)

    for _ in range(LOGIN_RATE_LIMIT_PER_WINDOW):
        allowed, _ = await check_login_rate_limit("8.8.8.8", "e@e.com")
        assert allowed is True
    allowed, _ = await check_login_rate_limit("8.8.8.8", "e@e.com")
    assert allowed is False


@pytest.mark.asyncio
async def test_tg_intent_rate_limit_blocks_over_cap(redis_seq):
    allowed = True
    for _ in range(TG_INTENT_RATE_LIMIT_PER_WINDOW):
        allowed, _ = await check_tg_intent_rate_limit(42)
        assert allowed is True
    allowed, retry = await check_tg_intent_rate_limit(42)
    assert allowed is False
    assert retry > 0


@pytest.mark.asyncio
async def test_ai_rate_limit_buckets_independent(monkeypatch):
    counts: dict[str, int] = {}

    async def incr(key):
        counts[key] = counts.get(key, 0) + 1
        return counts[key]

    redis_mock = SimpleNamespace(
        incr=AsyncMock(side_effect=incr),
        expire=AsyncMock(return_value=True),
        ttl=AsyncMock(return_value=60),
    )
    monkeypatch.setattr(arl, "get_redis", lambda: redis_mock)
    monkeypatch.setattr(arl, "is_noop_redis", lambda c: False)

    # Разные bucket'ы для одного user → независимые счётчики.
    a_ok, _ = await check_ai_user_rate_limit(7, "assistant")
    b_ok, _ = await check_ai_user_rate_limit(7, "training")
    assert a_ok is True and b_ok is True
    assert counts["auth_rl:ai:assistant:7"] == 1
    assert counts["auth_rl:ai:training:7"] == 1


@pytest.mark.asyncio
async def test_broadcast_rate_limit_blocks_over_cap(redis_seq):
    from app.services.auth_rate_limit import BROADCAST_RATE_LIMIT_PER_WINDOW

    allowed = True
    for _ in range(BROADCAST_RATE_LIMIT_PER_WINDOW):
        allowed, _ = await check_broadcast_rate_limit(5)
        assert allowed is True
    allowed, retry = await check_broadcast_rate_limit(5)
    assert allowed is False
    assert retry > 0
