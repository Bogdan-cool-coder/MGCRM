"""Эпик 16 — Security: pure-function тесты SSO helpers.

Тестируем:
- generate_pkce_pair() — формат, длина, voucher/challenge соответствие SHA256
- generate_state() — энтропия
- build_google_authorize_url() / build_yandex_authorize_url() — обязательные params
- parse_google_userinfo() / parse_yandex_userinfo() — успех и ошибки
- is_hd_allowed() — Google hd check, прозрачно для Yandex
"""
from __future__ import annotations

import base64
import hashlib
from urllib.parse import parse_qs, urlparse

import pytest

from app.services.sso import (
    GOOGLE_AUTHORIZE_URL,
    YANDEX_AUTHORIZE_URL,
    SSOUserInfo,
    build_google_authorize_url,
    build_yandex_authorize_url,
    generate_pkce_pair,
    generate_state,
    is_hd_allowed,
    parse_google_userinfo,
    parse_yandex_userinfo,
)


# ============ PKCE ============


def test_generate_pkce_pair_verifier_length_and_charset():
    v, c = generate_pkce_pair()
    # RFC 7636: verifier — 43..128 ASCII URL-safe chars
    assert 43 <= len(v) <= 128
    assert all(ch.isalnum() or ch in "-._~" for ch in v)
    # challenge — base64url(sha256(verifier)) без padding
    assert "=" not in c
    expected_digest = hashlib.sha256(v.encode("ascii")).digest()
    expected = base64.urlsafe_b64encode(expected_digest).rstrip(b"=").decode("ascii")
    assert c == expected


def test_generate_pkce_pair_is_random_each_call():
    v1, _ = generate_pkce_pair()
    v2, _ = generate_pkce_pair()
    assert v1 != v2


def test_generate_state_is_high_entropy():
    s1 = generate_state()
    s2 = generate_state()
    assert s1 != s2
    assert len(s1) >= 20  # token_urlsafe(24) → 32 chars


# ============ Authorize URL builders ============


def test_build_google_authorize_url_has_required_params():
    url = build_google_authorize_url(
        client_id="cid",
        redirect_uri="https://app.example.com/cb",
        state="STATE",
        code_challenge="CHALLENGE",
        hd="example.com",
    )
    assert url.startswith(GOOGLE_AUTHORIZE_URL)
    qs = parse_qs(urlparse(url).query)
    assert qs["client_id"] == ["cid"]
    assert qs["redirect_uri"] == ["https://app.example.com/cb"]
    assert qs["state"] == ["STATE"]
    assert qs["code_challenge"] == ["CHALLENGE"]
    assert qs["code_challenge_method"] == ["S256"]
    assert qs["response_type"] == ["code"]
    assert qs["scope"] == ["openid email profile"]
    assert qs["hd"] == ["example.com"]


def test_build_google_authorize_url_omits_hd_when_none():
    url = build_google_authorize_url(
        client_id="cid",
        redirect_uri="https://app.example.com/cb",
        state="STATE",
        code_challenge="CHALLENGE",
        hd=None,
    )
    qs = parse_qs(urlparse(url).query)
    assert "hd" not in qs


def test_build_yandex_authorize_url_has_required_params():
    url = build_yandex_authorize_url(
        client_id="ya_cid",
        redirect_uri="https://app.example.com/cb-y",
        state="STATE",
    )
    assert url.startswith(YANDEX_AUTHORIZE_URL)
    qs = parse_qs(urlparse(url).query)
    assert qs["client_id"] == ["ya_cid"]
    assert qs["redirect_uri"] == ["https://app.example.com/cb-y"]
    assert qs["state"] == ["STATE"]
    assert qs["response_type"] == ["code"]


# ============ parse_google_userinfo / parse_yandex_userinfo ============


def test_parse_google_userinfo_extracts_fields():
    data = {
        "sub": "1234567890",
        "email": "user@macroglobaltech.com",
        "email_verified": True,
        "name": "User Name",
        "hd": "macroglobaltech.com",
    }
    info = parse_google_userinfo(data)
    assert info.provider == "google"
    assert info.provider_user_id == "1234567890"
    assert info.email == "user@macroglobaltech.com"
    assert info.name == "User Name"
    assert info.hd == "macroglobaltech.com"


def test_parse_google_userinfo_lowercases_email():
    data = {
        "sub": "abc",
        "email": "MIXEDcase@Example.com",
        "name": "X",
    }
    info = parse_google_userinfo(data)
    assert info.email == "mixedcase@example.com"


def test_parse_google_userinfo_raises_on_missing_sub():
    with pytest.raises(ValueError, match="sub|email"):
        parse_google_userinfo({"email": "a@b.com"})


def test_parse_yandex_userinfo_uses_default_email():
    data = {
        "id": "999",
        "default_email": "user@yandex.ru",
        "real_name": "Иван Иванов",
    }
    info = parse_yandex_userinfo(data)
    assert info.provider == "yandex"
    assert info.provider_user_id == "999"
    assert info.email == "user@yandex.ru"
    assert info.name == "Иван Иванов"
    assert info.hd is None


def test_parse_yandex_userinfo_falls_back_to_emails_array():
    data = {
        "id": "777",
        "emails": ["alt@yandex.ru"],
        "login": "alt-login",
    }
    info = parse_yandex_userinfo(data)
    assert info.email == "alt@yandex.ru"


def test_parse_yandex_userinfo_raises_on_missing_email():
    with pytest.raises(ValueError, match="id|email"):
        parse_yandex_userinfo({"id": "1"})


# ============ is_hd_allowed ============


def test_is_hd_allowed_google_match_passes():
    info = SSOUserInfo(
        provider="google", provider_user_id="x", email="a@b.com",
        name="X", hd="macroglobaltech.com",
    )
    assert is_hd_allowed(info, "macroglobaltech.com") is True


def test_is_hd_allowed_google_mismatch_blocked():
    info = SSOUserInfo(
        provider="google", provider_user_id="x", email="a@b.com",
        name="X", hd="gmail.com",
    )
    assert is_hd_allowed(info, "macroglobaltech.com") is False


def test_is_hd_allowed_google_missing_hd_blocked():
    info = SSOUserInfo(
        provider="google", provider_user_id="x", email="a@b.com",
        name="X", hd=None,
    )
    assert is_hd_allowed(info, "macroglobaltech.com") is False


def test_is_hd_allowed_google_empty_allowed_permits_any():
    """Если allowed_hd пуст → пропускаем любой Google аккаунт."""
    info = SSOUserInfo(
        provider="google", provider_user_id="x", email="a@b.com",
        name="X", hd=None,
    )
    assert is_hd_allowed(info, "") is True


def test_is_hd_allowed_yandex_always_true():
    """У Yandex нет понятия Workspace domain → always passes."""
    info = SSOUserInfo(
        provider="yandex", provider_user_id="x", email="a@y.ru",
        name="X", hd=None,
    )
    assert is_hd_allowed(info, "anything.com") is True


def test_is_hd_allowed_case_insensitive():
    info = SSOUserInfo(
        provider="google", provider_user_id="x", email="a@b.com",
        name="X", hd="Macroglobaltech.com",
    )
    assert is_hd_allowed(info, "macroglobaltech.com") is True
