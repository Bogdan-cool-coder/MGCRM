"""Эпик 16 — Security: SSO (Google + Yandex) endpoints.

Endpoints:
- GET    /api/auth/sso/links              — массив SSO-привязок текущего user'а
                                              (для UI «Безопасность»).
- GET    /api/auth/sso/{provider}/start   — redirect на consent URL провайдера
                                              для логина (auto-create user если
                                              email не найден).
- GET    /api/auth/sso/{provider}/link    — redirect на consent URL провайдера
                                              для линковки УЖЕ залогиненного
                                              user'а (НЕ создаёт нового).
                                              Query `?return=` — куда вернуть
                                              после успешного link.
- GET    /api/auth/sso/{provider}/callback — общий callback для login и link
                                              (различает по `link_user_id` cookie).
- DELETE /api/auth/sso/{provider}/unlink  — снять привязку (auth required).
                                              POST alias оставлен для backward
                                              compat. Блокирует если у user'а
                                              нет пароля и это последний SSO (409).
- GET    /api/auth/sso/{provider}/status  — метаданные одной привязки (для UI).

Поведение:
- Если CLIENT_ID не задан → endpoint'ы возвращают 503 «SSO not configured».
- Domain check для Google: если settings.google_allowed_hd задан, требуем
  userinfo.hd == allowed_hd; иначе redirect на /login?sso_error=domain_not_allowed.
- Auto-create user: если email не найден в БД → создаём User с role=manager,
  с PLACEHOLDER_PASSWORD_HASH (login через SSO only до момента, когда
  юзер сам задаст пароль).

Cookie:
- sso_state       — random 24-byte token, сверяется в callback против state.
                     TTL 10 мин, secure / httponly / samesite=lax.
- sso_verifier    — PKCE code_verifier для Google (Yandex его не поддерживает,
                     но мы держим cookie тем же ключом для единообразия).
- sso_link_user_id — user_id, для которого делается линковка (callback использует
                     эту cookie чтобы понять: это link к существующему user'у,
                     а не свежий login).
- sso_link_return  — URL для редиректа после успешного link.
"""
from __future__ import annotations

import logging
import secrets
from datetime import datetime
from typing import Annotated
from urllib.parse import urlencode

import httpx
from fastapi import APIRouter, Cookie, Depends, HTTPException, Request, Response, status
from fastapi.responses import RedirectResponse
from pydantic import BaseModel, ConfigDict
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.db import get_session
from app.deps import CurrentUser
from app.models import User, UserRole, UserSSOLink
from app.routers.auth import _issue_access_cookie
from app.security import PLACEHOLDER_PASSWORD_HASH, has_password
from app.services.sso import (
    GOOGLE_TOKEN_URL,
    GOOGLE_USERINFO_URL,
    SSO_STATE_COOKIE,
    SSO_STATE_TTL_SECONDS,
    SSO_VERIFIER_COOKIE,
    YANDEX_TOKEN_URL,
    YANDEX_USERINFO_URL,
    SSOUserInfo,
    build_google_authorize_url,
    build_yandex_authorize_url,
    generate_pkce_pair,
    generate_state,
    is_sso_identity_trusted,
    parse_google_userinfo,
    parse_yandex_userinfo,
)

router = APIRouter(prefix="/auth/sso", tags=["auth-sso"])
logger = logging.getLogger(__name__)

_VALID_PROVIDERS = ("google", "yandex")

# Cookie-имена для link-флоу (короткие TTL, тот же путь /api/auth/sso).
SSO_LINK_USER_COOKIE = "sso_link_user_id"
SSO_LINK_RETURN_COOKIE = "sso_link_return"


# ============ Pydantic schemas ============


class SSOLinkOut(BaseModel):
    """Одна SSO-привязка текущего user'а (для GET /api/auth/sso/links)."""

    model_config = ConfigDict(from_attributes=True)
    provider: str
    provider_email: str | None
    linked_at: datetime


# ============ Helpers ============


def _provider_or_404(provider: str) -> None:
    if provider not in _VALID_PROVIDERS:
        raise HTTPException(404, f"Неизвестный SSO provider: {provider!r}")


def _provider_config(provider: str) -> tuple[str, str]:
    """Вернуть (client_id, client_secret) для провайдера.

    Бросает 503 если не настроен (client_id пуст).
    """
    settings = get_settings()
    if provider == "google":
        if not settings.google_client_id:
            raise HTTPException(503, "Google SSO не настроен на сервере")
        return settings.google_client_id, settings.google_client_secret
    if provider == "yandex":
        if not settings.yandex_client_id:
            raise HTTPException(503, "Yandex SSO не настроен на сервере")
        return settings.yandex_client_id, settings.yandex_client_secret
    raise HTTPException(404, f"Неизвестный SSO provider: {provider!r}")


def _redirect_uri(request: Request, provider: str) -> str:
    """Собрать redirect_uri для callback'а (тот, что зарегистрирован в
    Google/Yandex console)."""
    settings = get_settings()
    # Предпочитаем public_base_url из settings (так совпадёт с зарегистрированным
    # в Google console и не сломается через прокси/cdn). Только fallback — request.base_url.
    base = (settings.public_base_url or str(request.base_url)).rstrip("/")
    return f"{base}/api/auth/sso/{provider}/callback"


def _set_short_cookie(response: Response, key: str, value: str) -> None:
    settings = get_settings()
    response.set_cookie(
        key=key,
        value=value,
        httponly=True,
        secure=settings.app_env != "development",
        samesite="lax",
        max_age=SSO_STATE_TTL_SECONDS,
        path=f"/api/auth/sso",
    )


def _ui_redirect_with_error(error: str) -> RedirectResponse:
    """Редирект на UI с ошибкой в querystring (frontend покажет alert)."""
    settings = get_settings()
    base = (settings.public_base_url or "").rstrip("/")
    target = f"{base}/login?{urlencode({'sso_error': error})}"
    return RedirectResponse(target, status_code=status.HTTP_302_FOUND)


def _ui_redirect_ok() -> RedirectResponse:
    """Редирект на главную UI после успешного SSO логина."""
    settings = get_settings()
    base = (settings.public_base_url or "").rstrip("/")
    return RedirectResponse(f"{base}/", status_code=status.HTTP_302_FOUND)


def _safe_return_path(return_param: str | None) -> str:
    """Нормализовать `?return=` параметр в безопасный относительный путь.

    Только path-only (начинается с "/"), без protocol/host. Защита от
    open redirect (юзер не должен иметь возможности заставить нас
    редиректить на example.com).
    """
    DEFAULT = "/profile/security?linked=1"
    if not return_param:
        return DEFAULT
    if not return_param.startswith("/") or return_param.startswith("//"):
        return DEFAULT
    # Запрещаем backslash (некоторые браузеры трактуют \\ как //).
    if "\\" in return_param:
        return DEFAULT
    return return_param


def _ui_redirect_link_ok(return_path: str) -> RedirectResponse:
    """Редирект на UI после успешного link."""
    settings = get_settings()
    base = (settings.public_base_url or "").rstrip("/")
    return RedirectResponse(
        f"{base}{return_path}", status_code=status.HTTP_302_FOUND,
    )


def _ui_redirect_link_error(error: str, return_path: str) -> RedirectResponse:
    """Редирект на return_path с ошибкой линковки."""
    settings = get_settings()
    base = (settings.public_base_url or "").rstrip("/")
    # На return_path может быть уже query — добавляем sso_link_error через "?" или "&".
    sep = "&" if "?" in return_path else "?"
    target = f"{base}{return_path}{sep}{urlencode({'sso_link_error': error})}"
    return RedirectResponse(target, status_code=status.HTTP_302_FOUND)


# ============ /links (LIST current user's links) ============
# КРИТИЧНО: этот маршрут должен идти ДО /{provider}/start, иначе FastAPI
# попытается матчить "links" в `{provider}` и упадёт на _provider_or_404.


@router.get("/links", response_model=list[SSOLinkOut])
async def list_my_sso_links(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> list[SSOLinkOut]:
    """Список SSO-привязок текущего пользователя.

    Для UI «Безопасность аккаунта» / SsoAccountsCard. Возвращает массив
    (даже пустой), отсортированный по linked_at asc — порядок «когда какая
    привязка появилась».
    """
    links = (
        await session.execute(
            select(UserSSOLink)
            .where(UserSSOLink.user_id == current_user.id)
            .order_by(UserSSOLink.linked_at.asc())
        )
    ).scalars().all()
    return [
        SSOLinkOut(
            provider=link.provider,
            provider_email=link.provider_email,
            linked_at=link.linked_at,
        )
        for link in links
    ]


# ============ /start ============


@router.get("/{provider}/start")
async def sso_start(
    provider: str,
    request: Request,
    response: Response,
) -> RedirectResponse:
    """Шаг 1: redirect юзера на consent screen провайдера.

    Ставим state cookie (защита от CSRF) и (для Google) PKCE verifier cookie.
    Возвращаем 302 на authorize URL.
    """
    _provider_or_404(provider)
    client_id, _ = _provider_config(provider)
    redirect_uri = _redirect_uri(request, provider)
    state = generate_state()

    settings = get_settings()
    if provider == "google":
        verifier, challenge = generate_pkce_pair()
        url = build_google_authorize_url(
            client_id=client_id,
            redirect_uri=redirect_uri,
            state=state,
            code_challenge=challenge,
            hd=settings.google_allowed_hd or None,
        )
        redirect = RedirectResponse(url, status_code=status.HTTP_302_FOUND)
        _set_short_cookie(redirect, SSO_STATE_COOKIE, state)
        _set_short_cookie(redirect, SSO_VERIFIER_COOKIE, verifier)
        return redirect

    # Yandex
    url = build_yandex_authorize_url(
        client_id=client_id, redirect_uri=redirect_uri, state=state,
    )
    redirect = RedirectResponse(url, status_code=status.HTTP_302_FOUND)
    _set_short_cookie(redirect, SSO_STATE_COOKIE, state)
    return redirect


# ============ /link (для уже залогиненного user'а) ============


@router.get("/{provider}/link")
async def sso_link(
    provider: str,
    request: Request,
    current_user: CurrentUser,
) -> RedirectResponse:
    """Шаг 1 link-флоу: redirect на consent screen для УЖЕ ЗАЛОГИНЕННОГО юзера.

    Отличия от /start:
    - Требует auth (cookie access_token).
    - Доп. ставит cookie `sso_link_user_id` с current_user.id → callback
      различает свежий login от линковки и не выдаёт новый access_token,
      а только добавляет UserSSOLink.
    - Доп. ставит cookie `sso_link_return` с relative path для редиректа
      после успешного link (default `/profile/security?linked=1`).
    """
    _provider_or_404(provider)
    client_id, _ = _provider_config(provider)
    redirect_uri = _redirect_uri(request, provider)
    state = generate_state()

    return_path = _safe_return_path(request.query_params.get("return"))

    settings = get_settings()
    if provider == "google":
        verifier, challenge = generate_pkce_pair()
        url = build_google_authorize_url(
            client_id=client_id,
            redirect_uri=redirect_uri,
            state=state,
            code_challenge=challenge,
            hd=settings.google_allowed_hd or None,
        )
        redirect = RedirectResponse(url, status_code=status.HTTP_302_FOUND)
        _set_short_cookie(redirect, SSO_STATE_COOKIE, state)
        _set_short_cookie(redirect, SSO_VERIFIER_COOKIE, verifier)
        _set_short_cookie(redirect, SSO_LINK_USER_COOKIE, str(current_user.id))
        _set_short_cookie(redirect, SSO_LINK_RETURN_COOKIE, return_path)
        return redirect

    url = build_yandex_authorize_url(
        client_id=client_id, redirect_uri=redirect_uri, state=state,
    )
    redirect = RedirectResponse(url, status_code=status.HTTP_302_FOUND)
    _set_short_cookie(redirect, SSO_STATE_COOKIE, state)
    _set_short_cookie(redirect, SSO_LINK_USER_COOKIE, str(current_user.id))
    _set_short_cookie(redirect, SSO_LINK_RETURN_COOKIE, return_path)
    return redirect


# ============ /callback ============


async def _exchange_google(
    code: str, verifier: str, redirect_uri: str,
) -> SSOUserInfo:
    settings = get_settings()
    async with httpx.AsyncClient(timeout=10.0) as client:
        token_resp = await client.post(
            GOOGLE_TOKEN_URL,
            data={
                "code": code,
                "client_id": settings.google_client_id,
                "client_secret": settings.google_client_secret,
                "redirect_uri": redirect_uri,
                "grant_type": "authorization_code",
                "code_verifier": verifier,
            },
        )
        if token_resp.status_code >= 400:
            logger.warning("Google token exchange failed: %s", token_resp.text)
            raise HTTPException(400, "Google не выдал access_token")
        access_token = token_resp.json().get("access_token")
        if not access_token:
            raise HTTPException(400, "Google не вернул access_token в ответе")

        userinfo_resp = await client.get(
            GOOGLE_USERINFO_URL,
            headers={"Authorization": f"Bearer {access_token}"},
        )
        if userinfo_resp.status_code >= 400:
            raise HTTPException(400, "Google userinfo error")
        return parse_google_userinfo(userinfo_resp.json())


async def _exchange_yandex(code: str, redirect_uri: str) -> SSOUserInfo:
    settings = get_settings()
    async with httpx.AsyncClient(timeout=10.0) as client:
        token_resp = await client.post(
            YANDEX_TOKEN_URL,
            data={
                "code": code,
                "client_id": settings.yandex_client_id,
                "client_secret": settings.yandex_client_secret,
                "redirect_uri": redirect_uri,
                "grant_type": "authorization_code",
            },
        )
        if token_resp.status_code >= 400:
            logger.warning("Yandex token exchange failed: %s", token_resp.text)
            raise HTTPException(400, "Yandex не выдал access_token")
        access_token = token_resp.json().get("access_token")
        if not access_token:
            raise HTTPException(400, "Yandex не вернул access_token в ответе")

        # Yandex: format=json + oauth_token=
        userinfo_resp = await client.get(
            YANDEX_USERINFO_URL,
            params={"format": "json"},
            headers={"Authorization": f"OAuth {access_token}"},
        )
        if userinfo_resp.status_code >= 400:
            raise HTTPException(400, "Yandex userinfo error")
        return parse_yandex_userinfo(userinfo_resp.json())


async def _link_to_existing_user(
    session: AsyncSession, user_id: int, info: SSOUserInfo,
) -> tuple[User, str | None]:
    """Привязать SSO-аккаунт к существующему user'у (для link-флоу).

    Возвращает (user, error). error=None → успех; иначе строка-код для UI:
    - "user_not_found" → user_id из cookie не найден (race / cookie corrupted)
    - "user_inactive" → юзер деактивирован
    - "already_linked_other" → этот SSO-аккаунт уже привязан к другому юзеру
    - "already_linked_self" → у этого юзера уже есть привязка на провайдер
      (через UNIQUE(user_id, provider))

    На успехе создаёт UserSSOLink + commit отвечает callback.
    """
    user = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    if user is None:
        return None, "user_not_found"  # type: ignore[return-value]
    if not user.is_active:
        return user, "user_inactive"

    # Этот provider_user_id уже привязан к другому юзеру?
    existing = (
        await session.execute(
            select(UserSSOLink).where(
                UserSSOLink.provider == info.provider,
                UserSSOLink.provider_user_id == info.provider_user_id,
            )
        )
    ).scalar_one_or_none()
    if existing is not None:
        if existing.user_id == user.id:
            # Уже привязан к этому же юзеру — считаем idempotent success.
            # Можно обновить provider_email если поменялся.
            if existing.provider_email != info.email:
                existing.provider_email = info.email
                await session.flush()
            return user, None
        return user, "already_linked_other"

    # У этого юзера уже есть привязка на этот провайдер?
    own = (
        await session.execute(
            select(UserSSOLink).where(
                UserSSOLink.user_id == user.id,
                UserSSOLink.provider == info.provider,
            )
        )
    ).scalar_one_or_none()
    if own is not None:
        return user, "already_linked_self"

    link = UserSSOLink(
        user_id=user.id,
        provider=info.provider,
        provider_user_id=info.provider_user_id,
        provider_email=info.email,
    )
    session.add(link)
    await session.flush()
    return user, None


async def _lookup_or_create_user(
    session: AsyncSession, info: SSOUserInfo,
) -> User:
    """Найти User по SSO link → по email → создать нового.

    Также создаёт UserSSOLink, если её не было.
    """
    # 1) По существующей SSO link
    link = (
        await session.execute(
            select(UserSSOLink).where(
                UserSSOLink.provider == info.provider,
                UserSSOLink.provider_user_id == info.provider_user_id,
            )
        )
    ).scalar_one_or_none()
    if link:
        user = (
            await session.execute(select(User).where(User.id == link.user_id))
        ).scalar_one_or_none()
        if user and user.is_active:
            return user
        raise HTTPException(403, "Пользователь деактивирован")

    # 2) По email от провайдера
    user = (
        await session.execute(
            select(User).where(User.email == info.email)
        )
    ).scalar_one_or_none()
    if user is None:
        # 3) Auto-create. role=manager (минимальная), password_hash —
        # константа-плейсхолдер PLACEHOLDER_PASSWORD_HASH (не валидный bcrypt,
        # verify_password всегда False). По ней потом отличаем SSO-only юзера
        # от юзера с настоящим паролем (has_password()).
        user = User(
            email=info.email,
            password_hash=PLACEHOLDER_PASSWORD_HASH,
            full_name=info.name,
            role=UserRole.manager,
            is_active=True,
        )
        session.add(user)
        await session.flush()

    if not user.is_active:
        raise HTTPException(403, "Пользователь деактивирован")

    # Связываем
    link = UserSSOLink(
        user_id=user.id,
        provider=info.provider,
        provider_user_id=info.provider_user_id,
        provider_email=info.email,
    )
    session.add(link)
    await session.flush()
    return user


def _clear_sso_cookies(redirect: RedirectResponse, include_link: bool) -> None:
    """Стереть все sso_* cookies (state/verifier и опционально link cookies)."""
    redirect.delete_cookie(SSO_STATE_COOKIE, path="/api/auth/sso")
    redirect.delete_cookie(SSO_VERIFIER_COOKIE, path="/api/auth/sso")
    if include_link:
        redirect.delete_cookie(SSO_LINK_USER_COOKIE, path="/api/auth/sso")
        redirect.delete_cookie(SSO_LINK_RETURN_COOKIE, path="/api/auth/sso")


@router.get("/{provider}/callback")
async def sso_callback(
    provider: str,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    code: str | None = None,
    state: str | None = None,
    error: str | None = None,
    sso_state: Annotated[str | None, Cookie()] = None,
    sso_verifier: Annotated[str | None, Cookie()] = None,
    sso_link_user_id: Annotated[str | None, Cookie()] = None,
    sso_link_return: Annotated[str | None, Cookie()] = None,
) -> RedirectResponse:
    """Шаг 2: обмен code → токен → userinfo → user.

    Два режима, определяемых наличием cookie `sso_link_user_id`:
    1. LOGIN (cookie отсутствует) — lookup/create user → access_token cookie
       → 302 на /.
    2. LINK (cookie есть) — link к существующему user_id → 302 на return_path.

    При неудаче:
    - В LOGIN режиме → 302 на /login?sso_error=<reason>.
    - В LINK режиме → 302 на return_path?sso_link_error=<reason>.
    """
    _provider_or_404(provider)
    is_link_flow = bool(sso_link_user_id)
    link_return = _safe_return_path(sso_link_return)

    def err(reason: str) -> RedirectResponse:
        if is_link_flow:
            r = _ui_redirect_link_error(reason, link_return)
        else:
            r = _ui_redirect_with_error(reason)
        _clear_sso_cookies(r, include_link=is_link_flow)
        return r

    # Если провайдер вернул error — редиректим на UI.
    if error:
        return err(error)
    if not code or not state:
        return err("missing_code_or_state")

    # Сверяем state против cookie
    if not sso_state or not secrets.compare_digest(sso_state, state):
        return err("state_mismatch")

    # Проверяем что провайдер настроен (503 → redirect с ошибкой)
    try:
        _provider_config(provider)
    except HTTPException as e:
        if e.status_code == 503:
            return err("sso_not_configured")
        raise

    redirect_uri = _redirect_uri(request, provider)

    try:
        if provider == "google":
            if not sso_verifier:
                return err("pkce_verifier_missing")
            info = await _exchange_google(code, sso_verifier, redirect_uri)
        else:
            info = await _exchange_yandex(code, redirect_uri)
    except HTTPException:
        return err("token_exchange_failed")
    except Exception as e:  # noqa: BLE001
        logger.warning("SSO %s exchange exception: %s", provider, e)
        return err("token_exchange_failed")

    # P0 Security: единый guard ДО любого auto-link/auto-create.
    # 1) email_verified обязателен (нельзя захватить чужой аккаунт по
    #    непроверенной почте); 2) domain allowlist для ВСЕХ провайдеров
    #    (раньше Yandex обходил Google-only hd-проверку).
    settings = get_settings()
    trusted, trust_err = is_sso_identity_trusted(info, settings.google_allowed_hd)
    if not trusted:
        return err(trust_err)

    # ─── LINK flow: привязать к существующему user'у ────────────────────────
    if is_link_flow:
        try:
            user_id_int = int(sso_link_user_id or "0")
        except (TypeError, ValueError):
            return err("invalid_link_user")
        _, link_err = await _link_to_existing_user(session, user_id_int, info)
        if link_err is not None:
            return err(link_err)
        await session.commit()
        redirect = _ui_redirect_link_ok(link_return)
        _clear_sso_cookies(redirect, include_link=True)
        return redirect

    # ─── LOGIN flow: lookup/create + выдать access_token ────────────────────
    user = await _lookup_or_create_user(session, info)
    await session.commit()

    # Выдаём access_token + чистим SSO cookies
    redirect = _ui_redirect_ok()
    _issue_access_cookie(redirect, user)
    _clear_sso_cookies(redirect, include_link=False)
    return redirect


# ============ /unlink ============


async def _do_unlink(
    provider: str, current_user: User, session: AsyncSession,
) -> dict[str, bool]:
    """Общая логика unlink для DELETE и legacy POST алиаса.

    Блокируем если: у юзера НЕТ пароля И НЕТ других SSO-привязок —
    иначе он останется без login (sealed account).
    """
    _provider_or_404(provider)
    link = (
        await session.execute(
            select(UserSSOLink).where(
                UserSSOLink.user_id == current_user.id,
                UserSSOLink.provider == provider,
            )
        )
    ).scalar_one_or_none()
    if link is None:
        raise HTTPException(404, "Связь с этим провайдером не найдена")

    # Проверяем что у юзера есть альтернативный способ войти.
    # 1) Пароль не placeholder → есть пароль.
    # 2) Иначе — нужна хотя бы одна другая SSO-привязка.
    user_has_password = has_password(current_user)
    if not user_has_password:
        other_links = (
            await session.execute(
                select(UserSSOLink).where(
                    UserSSOLink.user_id == current_user.id,
                    UserSSOLink.provider != provider,
                )
            )
        ).scalars().all()
        if not other_links:
            raise HTTPException(
                409,
                "Нельзя снять последнюю привязку SSO: вы потеряете доступ "
                "к аккаунту. Сначала добавьте другой SSO или пароль.",
            )

    await session.delete(link)
    await session.commit()
    return {"ok": True}


@router.delete("/{provider}/unlink")
async def sso_unlink_delete(
    provider: str,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> dict[str, bool]:
    """Снять привязку SSO (DELETE — основной HTTP метод по спеке фронта)."""
    return await _do_unlink(provider, current_user, session)


@router.post("/{provider}/unlink", deprecated=True)
async def sso_unlink_post(
    provider: str,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> dict[str, bool]:
    """Snять привязку SSO. POST — deprecated alias DELETE'а для backward compat.

    Старый клиент мог уже шить POST; оставлен чтобы не сломать
    в transition. Новый код шлёт DELETE.
    """
    return await _do_unlink(provider, current_user, session)


@router.get("/{provider}/status")
async def sso_provider_status(
    provider: str,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> dict[str, bool | str | None]:
    """Метаданные привязки SSO для UI «Безопасность»."""
    _provider_or_404(provider)
    settings = get_settings()
    configured = bool(
        settings.google_client_id if provider == "google"
        else settings.yandex_client_id
    )
    link = (
        await session.execute(
            select(UserSSOLink).where(
                UserSSOLink.user_id == current_user.id,
                UserSSOLink.provider == provider,
            )
        )
    ).scalar_one_or_none()
    return {
        "configured": configured,
        "linked": link is not None,
        "provider_email": link.provider_email if link else None,
    }
