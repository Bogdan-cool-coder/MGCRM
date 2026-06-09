"""Эпик 15 — OAuth 2.0 Provider: PKCE + scope validation + token helpers (pure-function).

Без DB-фикстуры: проверяем PKCE-S256 challenge/verifier, валидацию scope'ов,
форматы токенов, парсинг scope-string, валидацию redirect_uri.
"""
from __future__ import annotations

import base64
import hashlib
import re
from unittest.mock import AsyncMock, MagicMock

import pytest

from app.services.oauth import (
    ACCESS_TOKEN_PREFIX,
    AUTH_CODE_PREFIX,
    CODE_CHALLENGE_METHODS,
    GRANT_TYPES,
    OAUTH_ALLOWED_SCOPES,
    REFRESH_TOKEN_PREFIX,
    compute_pkce_s256_challenge,
    consume_authorization,
    generate_access_token_plain,
    generate_authorization_code_plain,
    generate_client_id,
    generate_client_secret,
    generate_refresh_token_plain,
    hash_authorization_code,
    hash_token_sha256,
    parse_scope_string,
    validate_code_verifier,
    validate_redirect_uri,
    validate_requested_scopes,
    verify_authorization_code_hash,
    verify_client_secret,
    verify_pkce_s256,
)


# ============ PKCE S256 ============

def test_pkce_s256_round_trip():
    """compute_pkce_s256_challenge ↔ verify_pkce_s256."""
    verifier = "a" * 43  # 43 chars — минимум по RFC 7636
    challenge = compute_pkce_s256_challenge(verifier)
    assert verify_pkce_s256(verifier, challenge) is True


def test_pkce_s256_wrong_verifier():
    """Чужой verifier не проходит."""
    challenge = compute_pkce_s256_challenge("a" * 43)
    assert verify_pkce_s256("b" * 43, challenge) is False


def test_pkce_s256_known_answer():
    """Известный test vector из RFC 7636 §4.6."""
    verifier = "dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"
    expected_challenge = "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM"
    challenge = compute_pkce_s256_challenge(verifier)
    assert challenge == expected_challenge


def test_pkce_s256_empty_inputs():
    assert verify_pkce_s256("", "x") is False
    assert verify_pkce_s256("x", "") is False
    assert compute_pkce_s256_challenge("") == ""


def test_pkce_s256_no_padding():
    """RFC 7636: base64url без padding."""
    verifier = "a" * 50
    challenge = compute_pkce_s256_challenge(verifier)
    assert "=" not in challenge


# ============ Code verifier validation ============

@pytest.mark.parametrize(
    "verifier,expected",
    [
        ("a" * 43, True),
        ("a" * 128, True),
        ("a" * 42, False),    # короче минимума
        ("a" * 129, False),   # длиннее максимума
        ("", False),
        (None, False),
        ("ab/cd" * 10, False),  # `/` не в whitelist'е
        ("ABC-_.~" * 7, True),  # символы из whitelist'а
    ],
)
def test_validate_code_verifier(verifier, expected):
    assert validate_code_verifier(verifier) is expected


# ============ Scope validation ============

def test_validate_requested_scopes_subset_ok():
    """Запрашиваемые scope'ы есть в client_allowed → OK."""
    client_allowed = ["read:leads", "write:leads", "read:deals"]
    result = validate_requested_scopes(["read:leads", "read:deals"], client_allowed)
    assert result == ["read:deals", "read:leads"]  # sorted


def test_validate_requested_scopes_not_in_whitelist():
    """Запрашиваемый scope не в OAUTH_ALLOWED_SCOPES → ValueError."""
    with pytest.raises(ValueError, match="Неизвестный OAuth-scope"):
        validate_requested_scopes(["totally-wrong"], ["totally-wrong"])


def test_validate_requested_scopes_not_in_client():
    """Запрашиваемый scope в whitelist, но не разрешён клиенту."""
    with pytest.raises(ValueError, match="не разрешён клиенту"):
        validate_requested_scopes(["read:leads"], ["read:deals"])


def test_validate_requested_scopes_dedup_and_sort():
    """Дубли удаляются, результат отсортирован."""
    client_allowed = ["read:leads", "write:leads"]
    result = validate_requested_scopes(
        ["write:leads", "read:leads", "write:leads"], client_allowed
    )
    assert result == ["read:leads", "write:leads"]


def test_validate_requested_scopes_empty_ok():
    """Пустой список scope'ов — допустимо."""
    assert validate_requested_scopes([], []) == []


def test_validate_requested_scopes_not_list():
    with pytest.raises(ValueError, match="списком"):
        validate_requested_scopes("read:leads", [])  # type: ignore[arg-type]


def test_oauth_allowed_scopes_no_wildcard():
    """Звёздочка-admin scope НЕ в OAuth-whitelist."""
    assert "*" not in OAUTH_ALLOWED_SCOPES


def test_oauth_allowed_scopes_contains_oidc():
    """OIDC scopes присутствуют."""
    assert {"openid", "profile", "email"}.issubset(OAUTH_ALLOWED_SCOPES)


# ============ Scope string parsing ============

@pytest.mark.parametrize(
    "raw,expected",
    [
        ("read:leads write:deals", ["read:leads", "write:deals"]),
        ("read:leads", ["read:leads"]),
        ("", []),
        (None, []),
        ("  read:leads   write:deals  ", ["read:leads", "write:deals"]),
    ],
)
def test_parse_scope_string(raw, expected):
    assert parse_scope_string(raw) == expected


# ============ Token formats ============

def test_authorization_code_format():
    code = generate_authorization_code_plain()
    assert code.startswith(AUTH_CODE_PREFIX)
    body = code.removeprefix(AUTH_CODE_PREFIX)
    assert len(body) >= 40
    assert re.fullmatch(r"[A-Za-z0-9_-]+", body)


def test_access_token_format():
    token = generate_access_token_plain()
    assert token.startswith(ACCESS_TOKEN_PREFIX)
    body = token.removeprefix(ACCESS_TOKEN_PREFIX)
    assert len(body) >= 40


def test_refresh_token_format():
    token = generate_refresh_token_plain()
    assert token.startswith(REFRESH_TOKEN_PREFIX)


def test_tokens_unique_per_call():
    """Каждый вызов — новый случайный токен."""
    a = generate_access_token_plain()
    b = generate_access_token_plain()
    assert a != b


def test_hash_token_sha256_deterministic():
    """SHA256 одного plaintext'а — одинаковый hash."""
    plain = "oat_abc"
    h1 = hash_token_sha256(plain)
    h2 = hash_token_sha256(plain)
    assert h1 == h2
    assert len(h1) == 64
    assert re.fullmatch(r"[0-9a-f]{64}", h1)


def test_access_refresh_prefixes_differ():
    """Префиксы access и refresh отличаются — для diff'ования в /revoke."""
    assert ACCESS_TOKEN_PREFIX != REFRESH_TOKEN_PREFIX


# ============ Client secret hashing ============

def test_client_secret_generation_returns_pair():
    plain, hashed = generate_client_secret()
    assert isinstance(plain, str)
    assert isinstance(hashed, str)
    assert plain != hashed
    assert verify_client_secret(plain, hashed) is True


def test_client_secret_wrong():
    plain, hashed = generate_client_secret()
    assert verify_client_secret("wrong-secret", hashed) is False


def test_client_secret_empty():
    assert verify_client_secret("", "x") is False
    assert verify_client_secret("x", "") is False


def test_client_id_generation():
    """client_id — URL-safe, ~32 chars."""
    cid = generate_client_id()
    assert len(cid) >= 24
    assert re.fullmatch(r"[A-Za-z0-9_-]+", cid)


# ============ Authorization code hashing ============

def test_authorization_code_hash_round_trip():
    plain = "oac_test123"
    hashed = hash_authorization_code(plain)
    assert verify_authorization_code_hash(plain, hashed) is True
    assert verify_authorization_code_hash("oac_different", hashed) is False


def test_authorization_code_hash_empty():
    assert verify_authorization_code_hash("", "x") is False
    assert verify_authorization_code_hash("x", "") is False


# ============ Redirect URI validation ============

def test_redirect_uri_exact_match():
    """RFC 6749 §3.1.2: только точное совпадение."""
    allowed = ["https://app.example.com/callback", "https://other.example.com/cb"]
    assert validate_redirect_uri("https://app.example.com/callback", allowed) is True
    assert validate_redirect_uri("https://other.example.com/cb", allowed) is True


def test_redirect_uri_no_wildcard():
    """Нет суффикс/префикс matching'а — только точное равенство."""
    allowed = ["https://app.example.com/callback"]
    assert validate_redirect_uri(
        "https://app.example.com/callback/extra", allowed,
    ) is False
    assert validate_redirect_uri(
        "https://app.example.com/CALLBACK", allowed,
    ) is False  # case-sensitive
    assert validate_redirect_uri(
        "http://app.example.com/callback", allowed,
    ) is False  # схема


def test_redirect_uri_empty():
    assert validate_redirect_uri("", ["x"]) is False
    assert validate_redirect_uri("x", []) is False
    assert validate_redirect_uri("", []) is False


# ============ Grant types / challenge methods constants ============

def test_grant_types_only_authcode_and_refresh():
    """В MVP — только authorization_code + refresh_token (RFC 6749 4.1 + 6)."""
    assert GRANT_TYPES == frozenset({"authorization_code", "refresh_token"})


def test_code_challenge_methods_only_s256():
    """Plain deprecated — поддерживаем только S256 (RFC 7636 §4.2)."""
    assert CODE_CHALLENGE_METHODS == frozenset({"S256"})


# ============ consume_authorization — atomic single-use guard ============


def _session_with_rowcount(rowcount: int) -> AsyncMock:
    """AsyncMock session, чей execute() возвращает result с заданным rowcount."""
    result = MagicMock()
    result.rowcount = rowcount
    session = AsyncMock()
    session.execute = AsyncMock(return_value=result)
    return session


async def test_consume_authorization_first_spend_true():
    """rowcount==1 → ровно одна успешная гашение кода (True)."""
    session = _session_with_rowcount(1)
    assert await consume_authorization(session, 42) is True
    session.execute.assert_awaited_once()


async def test_consume_authorization_double_spend_false():
    """rowcount==0 (used уже true / гонка) → False, токены выдавать нельзя."""
    session = _session_with_rowcount(0)
    assert await consume_authorization(session, 42) is False


async def test_consume_authorization_none_rowcount_false():
    """rowcount=None (драйвер не вернул) → трактуем как неуспех (fail-closed)."""
    session = _session_with_rowcount(None)
    assert await consume_authorization(session, 42) is False
