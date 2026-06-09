"""Эпик 16 — Security: SSO helpers (Google + Yandex OAuth 2.0).

Здесь — чистые функции (без I/O для тех, что без httpx): построение
authorize URL, разбор userinfo, проверка hd (Google Workspace domain).

I/O (httpx exchange code → token, fetch userinfo) — отдельная асинхронная
часть. Pure-function части тестируются в tests/test_sso_flow.py.
"""
from __future__ import annotations

import base64
import hashlib
import secrets
from dataclasses import dataclass
from typing import Final
from urllib.parse import urlencode

# Endpoints провайдеров. Захардкожены, потому что URL'ы стабильны (ни Google,
# ни Yandex не меняли их с 2015 года), и хранить в env'е бесполезно.

GOOGLE_AUTHORIZE_URL: Final[str] = "https://accounts.google.com/o/oauth2/v2/auth"
GOOGLE_TOKEN_URL: Final[str] = "https://oauth2.googleapis.com/token"
GOOGLE_USERINFO_URL: Final[str] = "https://openidconnect.googleapis.com/v1/userinfo"

YANDEX_AUTHORIZE_URL: Final[str] = "https://oauth.yandex.ru/authorize"
YANDEX_TOKEN_URL: Final[str] = "https://oauth.yandex.ru/token"
YANDEX_USERINFO_URL: Final[str] = "https://login.yandex.ru/info"

# Cookie names для PKCE state и code_verifier. Короткие TTL (10 мин).
SSO_STATE_COOKIE = "sso_state"
SSO_VERIFIER_COOKIE = "sso_verifier"
SSO_STATE_TTL_SECONDS = 600


@dataclass(frozen=True)
class SSOUserInfo:
    """Разобранный userinfo от провайдера.

    provider — "google" / "yandex".
    provider_user_id — стабильный ID юзера у провайдера.
    email — email от провайдера (может отличаться от User.email).
    name — full_name для auto-create user (если такого ещё нет в БД).
    hd — Google Workspace domain (только для Google), None для Yandex.
    """

    provider: str
    provider_user_id: str
    email: str
    name: str
    hd: str | None = None
    # P0 Security: провайдер утверждает, что email подтверждён. Google отдаёт
    # bool/строку в email_verified; Yandex не отдаёт явного флага, но OAuth-флоу
    # выдаёт только подтверждённый default_email — для него считаем True.
    email_verified: bool = False


# ============ PKCE / state ============


def generate_pkce_pair() -> tuple[str, str]:
    """Сгенерировать (code_verifier, code_challenge) для PKCE.

    RFC 7636: verifier — 43..128 chars URL-safe; challenge — SHA256(verifier)
    base64url-encoded без padding.
    """
    verifier = secrets.token_urlsafe(64)[:96]  # ~96 chars, в пределах 43..128
    digest = hashlib.sha256(verifier.encode("ascii")).digest()
    challenge = base64.urlsafe_b64encode(digest).rstrip(b"=").decode("ascii")
    return verifier, challenge


def generate_state() -> str:
    """CSRF-state token для OAuth flow. Возвращается в state параметре,
    сверяется в callback'е с тем, что лежит в cookie."""
    return secrets.token_urlsafe(24)


# ============ Authorize URL builders ============


def build_google_authorize_url(
    client_id: str, redirect_uri: str, state: str, code_challenge: str,
    hd: str | None = None,
) -> str:
    """OAuth2 authorize URL для Google с PKCE.

    hd — Google Workspace domain hint (например "macroglobaltech.com").
    Google всё равно может вернуть consent другого аккаунта — реальная
    проверка hd идёт в callback на userinfo.hd.
    """
    params = {
        "response_type": "code",
        "client_id": client_id,
        "redirect_uri": redirect_uri,
        "scope": "openid email profile",
        "state": state,
        "code_challenge": code_challenge,
        "code_challenge_method": "S256",
        "access_type": "online",
        "prompt": "select_account",
    }
    if hd:
        params["hd"] = hd
    return f"{GOOGLE_AUTHORIZE_URL}?{urlencode(params)}"


def build_yandex_authorize_url(
    client_id: str, redirect_uri: str, state: str,
) -> str:
    """OAuth2 authorize URL для Yandex.

    Yandex НЕ поддерживает PKCE официально, но игнорирует лишние параметры.
    Защита от CSRF — через state.
    """
    params = {
        "response_type": "code",
        "client_id": client_id,
        "redirect_uri": redirect_uri,
        "state": state,
        "force_confirm": "yes",
    }
    return f"{YANDEX_AUTHORIZE_URL}?{urlencode(params)}"


# ============ Userinfo parsing ============


def parse_google_userinfo(data: dict) -> SSOUserInfo:
    """Разобрать ответ от Google userinfo endpoint.

    Поля Google: sub, email, email_verified, name, picture, hd (если
    Workspace).
    """
    sub = data.get("sub")
    email = data.get("email")
    if not sub or not email:
        raise ValueError("Google userinfo: отсутствует sub или email")
    # Google отдаёт email_verified как bool ИЛИ строку "true"/"false".
    raw_verified = data.get("email_verified")
    verified = raw_verified is True or (
        isinstance(raw_verified, str) and raw_verified.strip().lower() == "true"
    )
    return SSOUserInfo(
        provider="google",
        provider_user_id=str(sub),
        email=str(email).lower(),
        name=str(data.get("name") or email),
        hd=data.get("hd"),
        email_verified=verified,
    )


def parse_yandex_userinfo(data: dict) -> SSOUserInfo:
    """Разобрать ответ от Yandex login info endpoint.

    Поля Yandex: id, login, default_email, real_name, display_name,
    emails (массив).
    """
    uid = data.get("id")
    email = (
        data.get("default_email")
        or (data.get("emails") or [None])[0]
        or data.get("login")
    )
    if not uid or not email:
        raise ValueError("Yandex userinfo: отсутствует id или email")
    name = (
        data.get("real_name")
        or data.get("display_name")
        or data.get("login")
        or email
    )
    return SSOUserInfo(
        provider="yandex",
        provider_user_id=str(uid),
        email=str(email).lower(),
        name=str(name),
        hd=None,
        # Yandex не отдаёт явного email_verified, но OAuth выдаёт только
        # подтверждённый аккаунтный email (default_email). Считаем подтверждённым,
        # если email пришёл из default_email/emails (а не из login-фолбэка).
        email_verified=bool(
            data.get("default_email") or (data.get("emails") or [None])[0]
        ),
    )


# ============ Validation helpers ============


def is_hd_allowed(info: SSOUserInfo, allowed_hd: str) -> bool:
    """Проверить, что Google Workspace домен в hd совпадает с allowed_hd.

    Если allowed_hd пуст → ограничение не применяется (любой Google аккаунт).
    Yandex info всегда возвращает True (нет понятия Workspace для Yandex).
    """
    if info.provider != "google":
        return True
    if not allowed_hd:
        return True
    return (info.hd or "").lower() == allowed_hd.lower()


def _email_domain(email: str) -> str:
    """Достать домен из email (после последнего @), lowercase."""
    return email.rsplit("@", 1)[-1].strip().lower() if "@" in email else ""


def is_domain_allowed(info: SSOUserInfo, allowed_hd: str) -> bool:
    """P0 Security: enforce domain allowlist для ВСЕХ провайдеров.

    Раньше allowlist проверялся только через Google `hd` claim — Yandex
    (и любой другой провайдер) обходил его. Теперь:
    - allowed_hd пуст → ограничение выключено (любой домен).
    - Google: достаточно либо hd == allowed_hd, либо email-домен == allowed_hd
      (некоторые Workspace-аккаунты не отдают hd, но email верный).
    - Прочие провайдеры: email-домен должен совпасть с allowed_hd.
    """
    if not allowed_hd:
        return True
    allowed = allowed_hd.lower()
    if info.provider == "google" and (info.hd or "").lower() == allowed:
        return True
    return _email_domain(info.email) == allowed


def is_sso_identity_trusted(info: SSOUserInfo, allowed_hd: str) -> tuple[bool, str]:
    """P0 Security: единый guard перед auto-link/auto-create из SSO.

    Возвращает (ok, error_code). Требует:
    1. email_verified == True (провайдер подтвердил владение почтой), иначе
       атакер с непроверенным email мог бы захватить чужой аккаунт по email.
    2. домен из allowlist (для всех провайдеров).

    error_code: "email_not_verified" | "domain_not_allowed" | "".
    """
    if not info.email_verified:
        return False, "email_not_verified"
    if not is_domain_allowed(info, allowed_hd):
        return False, "domain_not_allowed"
    return True, ""
