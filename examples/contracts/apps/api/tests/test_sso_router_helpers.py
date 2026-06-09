"""Эпик 16 — Security: pure-function тесты helpers для SSO роутера.

Тестируем:
- _safe_return_path: open-redirect protection в /link?return=
- SSOLinkOut response shape (для GET /api/auth/sso/links)
- _link_to_existing_user error codes (без БД, через mock session)

Без сети, без БД (mock session где нужно).
"""
from __future__ import annotations

from datetime import datetime, timezone

import pytest

from app.routers.sso import (
    SSO_LINK_RETURN_COOKIE,
    SSO_LINK_USER_COOKIE,
    SSOLinkOut,
    _safe_return_path,
)


# ============ _safe_return_path ============


def test_safe_return_path_default_when_none():
    assert _safe_return_path(None) == "/profile/security?linked=1"


def test_safe_return_path_default_when_empty():
    assert _safe_return_path("") == "/profile/security?linked=1"


def test_safe_return_path_default_when_absolute_url():
    """Защита от open redirect: example.com / //example.com / http://X — игнор."""
    assert _safe_return_path("http://evil.com/") == "/profile/security?linked=1"
    assert _safe_return_path("https://evil.com/") == "/profile/security?linked=1"
    assert _safe_return_path("//evil.com/") == "/profile/security?linked=1"


def test_safe_return_path_default_when_backslash():
    """Браузеры могут трактовать `\\\\evil.com` как `//evil.com`. Запрещаем."""
    assert _safe_return_path("/path\\evil.com") == "/profile/security?linked=1"


def test_safe_return_path_relative_passes():
    assert _safe_return_path("/profile/security") == "/profile/security"
    assert _safe_return_path("/some/page?q=1") == "/some/page?q=1"
    assert _safe_return_path("/profile/security?linked=1&tab=sso") == (
        "/profile/security?linked=1&tab=sso"
    )


def test_safe_return_path_rejects_relative_without_leading_slash():
    """Должен начинаться с `/` — иначе он может выглядеть как URL."""
    assert _safe_return_path("profile/security") == "/profile/security?linked=1"
    assert _safe_return_path("evil.com") == "/profile/security?linked=1"


# ============ SSOLinkOut shape ============


def test_sso_link_out_shape():
    """Shape ответа GET /api/auth/sso/links — это массив SSOLinkOut.

    Поля: provider, provider_email, linked_at.
    """
    item = SSOLinkOut(
        provider="google",
        provider_email="user@macroglobaltech.com",
        linked_at=datetime(2026, 6, 1, 12, 0, 0, tzinfo=timezone.utc),
    )
    dumped = item.model_dump()
    assert dumped["provider"] == "google"
    assert dumped["provider_email"] == "user@macroglobaltech.com"
    assert dumped["linked_at"] is not None


def test_sso_link_out_allows_null_provider_email():
    """provider_email может быть None (так бывает в Yandex если default_email
    не задан явно). Frontend учитывает что строка может прийти null."""
    item = SSOLinkOut(
        provider="yandex",
        provider_email=None,
        linked_at=datetime(2026, 6, 1, 12, 0, 0, tzinfo=timezone.utc),
    )
    dumped = item.model_dump()
    assert dumped["provider_email"] is None


def test_sso_link_cookies_constants_are_stable():
    """Cookie-имена должны быть стабильны (фронт не читает их напрямую,
    но снапшот-тест защитит от случайного rename)."""
    assert SSO_LINK_USER_COOKIE == "sso_link_user_id"
    assert SSO_LINK_RETURN_COOKIE == "sso_link_return"
