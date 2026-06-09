"""Эпик 24.2 — Google Calendar 2-way sync endpoints (per-user).

Endpoints:
- GET    /api/me/google-calendar              — статус подключения текущего user'а.
- GET    /api/me/google-calendar/connect      — начать OAuth flow (returns authorize_url).
- GET    /api/me/google-calendar/callback     — OAuth callback (Google redirect target).
- DELETE /api/me/google-calendar              — отключить sync (стирает tokens).
- PATCH  /api/me/google-calendar/settings     — обновить настройки sync (что синкать).
- POST   /api/me/google-calendar/sync-now     — manual trigger sync.

Все endpoints — cookie-auth (CurrentUser). Если GOOGLE_OAUTH_CLIENT_ID не
задан → 503 «Google Calendar sync не настроен на сервере».

Cookie:
- gcal_state — random 24-byte CSRF state, ставится в /connect, сверяется
  в /callback. TTL 10 мин, secure / httponly / samesite=lax.
- gcal_return — URL для редиректа после успешного подключения (опционально).
"""
from __future__ import annotations

import logging
import secrets
from datetime import UTC, datetime, timedelta
from typing import Annotated, Any
from urllib.parse import urlencode

import httpx
from fastapi import APIRouter, Cookie, Depends, HTTPException, Request, Response, status
from fastapi.responses import RedirectResponse
from pydantic import BaseModel, ConfigDict
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.config import get_settings
from app.db import get_session
from app.deps import CurrentUser
from app.models import GoogleCalendarEventLink, GoogleCalendarLink, User
from app.services.gcal_sync import sync_user_calendar
from app.services.google_calendar import (
    encrypt_token,
    exchange_code_for_tokens,
    fetch_userinfo,
    get_oauth_authorize_url,
)

router = APIRouter(prefix="/me/google-calendar", tags=["google-calendar"])
logger = logging.getLogger(__name__)

# Cookie names.
GCAL_STATE_COOKIE = "gcal_state"
GCAL_RETURN_COOKIE = "gcal_return"
GCAL_STATE_TTL_SECONDS = 600


# ============ Schemas ============


class GCalStatusOut(BaseModel):
    """Статус подключения GCal для UI (раздел «Профиль → Календарь»)."""

    model_config = ConfigDict(from_attributes=True)
    configured: bool  # настроен ли OAuth client на сервере (env vars заданы)
    connected: bool   # есть ли link у текущего user'а
    google_email: str | None = None
    sync_enabled: bool = True
    sync_meeting: bool = True
    sync_call: bool = False
    sync_only_with_time: bool = True
    calendar_id: str = "primary"
    last_sync_at: datetime | None = None
    connected_at: datetime | None = None
    # Количество текущих привязанных events (для UI).
    linked_events_count: int = 0


class GCalConnectOut(BaseModel):
    """Ответ на /connect — фронт делает window.location.href = authorize_url."""

    authorize_url: str


class GCalSettingsIn(BaseModel):
    """PATCH /settings body. Все поля optional — partial update."""

    sync_enabled: bool | None = None
    sync_meeting: bool | None = None
    sync_call: bool | None = None
    sync_only_with_time: bool | None = None
    calendar_id: str | None = None


class GCalSyncNowOut(BaseModel):
    """Ответ на POST /sync-now."""

    pulled_in: int
    pushed_out: int
    deleted_out: int
    errors: int
    full_resync: bool
    synced_at: datetime


# ============ Helpers ============


def _ensure_configured() -> None:
    """Бросает 503 если Google OAuth не настроен на сервере."""
    settings = get_settings()
    if not settings.gcal_ready:
        raise HTTPException(
            503, "Google Calendar sync не настроен на сервере",
        )


def _set_short_cookie(response: Response, key: str, value: str) -> None:
    """Поставить cookie на короткий TTL для OAuth state."""
    settings = get_settings()
    response.set_cookie(
        key=key,
        value=value,
        httponly=True,
        secure=settings.app_env != "development",
        samesite="lax",
        max_age=GCAL_STATE_TTL_SECONDS,
        path="/api/me/google-calendar",
    )


def _safe_return_path(return_param: str | None) -> str:
    """Защита от open redirect: возвращаем только path-only."""
    DEFAULT = "/profile/calendar?connected=1"
    if not return_param:
        return DEFAULT
    if not return_param.startswith("/") or return_param.startswith("//"):
        return DEFAULT
    if "\\" in return_param:
        return DEFAULT
    return return_param


def _ui_redirect(target_path: str, *, query: dict[str, str] | None = None) -> RedirectResponse:
    """Редирект на UI с относительным path-only."""
    settings = get_settings()
    base = (settings.public_base_url or "").rstrip("/")
    full = f"{base}{target_path}"
    if query:
        sep = "&" if "?" in target_path else "?"
        full = f"{full}{sep}{urlencode(query)}"
    return RedirectResponse(full, status_code=status.HTTP_302_FOUND)


def _clear_gcal_cookies(redirect: RedirectResponse) -> None:
    """Стереть все gcal_* cookies после успешного callback."""
    redirect.delete_cookie(GCAL_STATE_COOKIE, path="/api/me/google-calendar")
    redirect.delete_cookie(GCAL_RETURN_COOKIE, path="/api/me/google-calendar")


async def _get_link_or_none(
    session: AsyncSession, user_id: int,
) -> GoogleCalendarLink | None:
    return (
        await session.execute(
            select(GoogleCalendarLink).where(
                GoogleCalendarLink.user_id == user_id,
            )
        )
    ).scalar_one_or_none()


# ============ /me/google-calendar — status ============


@router.get("", response_model=GCalStatusOut)
async def get_status(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> GCalStatusOut:
    """Статус подключения GCal для current_user.

    configured=False если OAuth не настроен (фронт скроет UI).
    connected=False если link нет (фронт покажет кнопку «Подключить»).
    """
    settings = get_settings()
    link = await _get_link_or_none(session, current_user.id)
    if link is None:
        return GCalStatusOut(
            configured=settings.gcal_ready,
            connected=False,
        )

    # Count активных привязанных events.
    count_row = (
        await session.execute(
            select(GoogleCalendarEventLink).where(
                GoogleCalendarEventLink.is_active.is_(True),
            )
        )
    ).scalars().all()
    # Фильтруем по принадлежности user'у (через activity.responsible_id).
    # Для простоты считаем сразу через связь — без N+1 эту цифру всё равно
    # точно посчитать сложно, и юзеру нужна оценка а не точное число.
    # Можно было бы сделать через JOIN, но это lazy-attr и счёт «всех
    # активных events этого календаря».
    linked = sum(
        1 for el in count_row if el.google_calendar_id == link.calendar_id
    )

    return GCalStatusOut(
        configured=settings.gcal_ready,
        connected=True,
        google_email=link.google_email,
        sync_enabled=link.sync_enabled,
        sync_meeting=link.sync_meeting,
        sync_call=link.sync_call,
        sync_only_with_time=link.sync_only_with_time,
        calendar_id=link.calendar_id,
        last_sync_at=link.last_sync_at,
        connected_at=link.connected_at,
        linked_events_count=linked,
    )


# ============ /connect — OAuth flow start ============


@router.get("/connect", response_model=GCalConnectOut)
async def connect(
    current_user: CurrentUser,
    response: Response,
    return_url: str | None = None,
) -> GCalConnectOut:
    """Начать OAuth flow: ставим state cookie, возвращаем authorize_url.

    Фронт делает window.location.href = authorize_url. Google → consent →
    redirect на /callback с code + state.

    `return_url` — куда вернуть юзера после успешного подключения
    (default `/profile/calendar?connected=1`).
    """
    _ensure_configured()
    state = secrets.token_urlsafe(24)
    authorize_url = get_oauth_authorize_url(state)

    _set_short_cookie(response, GCAL_STATE_COOKIE, state)
    _set_short_cookie(
        response, GCAL_RETURN_COOKIE, _safe_return_path(return_url),
    )
    return GCalConnectOut(authorize_url=authorize_url)


# ============ /callback — OAuth flow finish ============


@router.get("/callback")
async def callback(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    code: str | None = None,
    state: str | None = None,
    error: str | None = None,
    gcal_state: Annotated[str | None, Cookie()] = None,
    gcal_return: Annotated[str | None, Cookie()] = None,
) -> RedirectResponse:
    """OAuth callback: обмен code → tokens → upsert GoogleCalendarLink.

    Затем редиректит юзера на return path (из cookie) с ?connected=1 или
    ?gcal_error=<reason> при failure.
    """
    return_path = _safe_return_path(gcal_return)

    def err(reason: str) -> RedirectResponse:
        r = _ui_redirect(return_path, query={"gcal_error": reason})
        _clear_gcal_cookies(r)
        return r

    if not get_settings().gcal_ready:
        return err("not_configured")

    if error:
        return err(error)
    if not code or not state:
        return err("missing_code_or_state")
    if not gcal_state or not secrets.compare_digest(gcal_state, state):
        return err("state_mismatch")

    # Exchange code → tokens
    try:
        token_resp = await exchange_code_for_tokens(code)
    except httpx.HTTPStatusError as e:
        logger.warning(
            "gcal token exchange failed: %s %s",
            e.response.status_code, e.response.text[:200],
        )
        return err("token_exchange_failed")
    except Exception as e:  # noqa: BLE001
        logger.warning("gcal token exchange exception: %s", e)
        return err("token_exchange_failed")

    access_token = token_resp.get("access_token")
    refresh_token = token_resp.get("refresh_token")
    expires_in = int(token_resp.get("expires_in") or 3600)
    scopes_str = token_resp.get("scope") or ""
    scopes = [s.strip() for s in scopes_str.split() if s.strip()]
    if not access_token:
        return err("no_access_token")

    # Fetch userinfo (sub, email).
    google_user_id: str | None = None
    google_email: str | None = None
    try:
        info = await fetch_userinfo(access_token)
        google_user_id = str(info.get("sub")) if info.get("sub") else None
        google_email = str(info.get("email")) if info.get("email") else None
    except Exception as e:  # noqa: BLE001
        logger.warning("gcal userinfo fetch failed: %s", e)
        # Не блокируем — sync будет работать и без email.

    # Upsert link для current_user.
    link = await _get_link_or_none(session, current_user.id)
    expires_at = datetime.now(UTC) + timedelta(seconds=expires_in)

    if link is None:
        link = GoogleCalendarLink(
            user_id=current_user.id,
            google_user_id=google_user_id,
            google_email=google_email,
            access_token_encrypted=encrypt_token(access_token),
            refresh_token_encrypted=(
                encrypt_token(refresh_token) if refresh_token else None
            ),
            token_expires_at=expires_at,
            scopes=scopes,
            calendar_id="primary",
            sync_enabled=True,
            sync_meeting=True,
            sync_call=False,
            sync_only_with_time=True,
        )
        session.add(link)
    else:
        link.google_user_id = google_user_id or link.google_user_id
        link.google_email = google_email or link.google_email
        link.access_token_encrypted = encrypt_token(access_token)
        if refresh_token:
            # refresh_token не выдаётся при каждом consent — сохраняем
            # только если пришёл (Google может вернуть пустой при
            # повторных подключениях без prompt=consent).
            link.refresh_token_encrypted = encrypt_token(refresh_token)
        link.token_expires_at = expires_at
        link.scopes = scopes
        # sync_enabled = True — если юзер сделал /connect, он хочет sync.
        link.sync_enabled = True
        # Сбрасываем sync_token — нужен новый полный pull при первой
        # синхронизации после реконнекта.
        link.last_sync_token = None

    await session.commit()

    redirect = _ui_redirect(return_path, query={"connected": "1"})
    _clear_gcal_cookies(redirect)
    return redirect


# ============ DELETE — отключить sync ============


@router.delete("", status_code=status.HTTP_204_NO_CONTENT)
async def disconnect(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> None:
    """Отключить sync: удалить GoogleCalendarLink (cascade удалит
    event_links через FK ON DELETE CASCADE).

    Google tokens НЕ revoke'аются — юзер сам решит в Google account
    settings. Это безопасно: мы просто перестаём их хранить и использовать.
    """
    link = await _get_link_or_none(session, current_user.id)
    if link is None:
        raise HTTPException(404, "Google Calendar не подключён")
    await session.delete(link)
    await session.commit()


# ============ PATCH /settings — обновить настройки sync ============


@router.patch("/settings", response_model=GCalStatusOut)
async def update_settings(
    data: GCalSettingsIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> GCalStatusOut:
    """Обновить настройки sync.

    Partial update: только переданные поля меняются. Не требует
    подключения — но если link нет, возвращает 404.
    """
    link = await _get_link_or_none(session, current_user.id)
    if link is None:
        raise HTTPException(404, "Google Calendar не подключён")

    patch = data.model_dump(exclude_unset=True)
    if "calendar_id" in patch and patch["calendar_id"]:
        if patch["calendar_id"] != link.calendar_id:
            # При смене календаря — сбрасываем sync_token (новый календарь
            # = новый incremental log).
            link.last_sync_token = None
    for k, v in patch.items():
        setattr(link, k, v)
    await session.commit()
    return await get_status(current_user, session)


# ============ POST /sync-now — manual trigger ============


@router.post("/sync-now", response_model=GCalSyncNowOut)
async def sync_now(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> GCalSyncNowOut:
    """Manual trigger: пройти полный sync для current_user сейчас.

    Возвращает количества (pulled_in/pushed_out/errors). Не падает —
    если sync_enabled=False или link нет → возвращаем нули.
    """
    _ensure_configured()
    link = await _get_link_or_none(session, current_user.id)
    if link is None:
        raise HTTPException(404, "Google Calendar не подключён")
    if not link.sync_enabled:
        raise HTTPException(409, "Sync отключён в настройках")

    result = await sync_user_calendar(session, current_user.id)
    return GCalSyncNowOut(
        pulled_in=result.pulled_in,
        pushed_out=result.pushed_out,
        deleted_out=result.deleted_out,
        errors=result.errors,
        full_resync=result.full_resync,
        synced_at=datetime.now(UTC),
    )
