"""Эпик 24.2 — Google Calendar 2-way sync: OAuth flow + REST API клиент.

Что здесь:
- Pure helpers (без I/O): build_oauth_authorize_url, activity_to_event,
  event_to_activity_patch, encrypt_token, decrypt_token, should_sync_activity.
- Async I/O helpers (httpx): exchange_code_for_tokens, refresh_access_token,
  list_events, create_event, update_event, delete_event.

Encryption — Fernet (тот же алгоритм что в services/totp.py).
Ключ — settings.gcal_encryption_key (fallback на totp_encryption_key чтобы
не плодить env'ы).

Все async-функции читают `link.access_token_encrypted` и сами вызывают
`_ensure_access_token(session, link)` — если token истёк, обмениваем
refresh → новый access и сохраняем в БД.

Pure-функции тестируются в tests/test_gcal_conversion.py и
tests/test_gcal_should_sync.py без сети/БД.
"""
from __future__ import annotations

import logging
from dataclasses import dataclass
from datetime import UTC, datetime, time, timedelta
from typing import Any, Final
from urllib.parse import urlencode

import httpx
from cryptography.fernet import Fernet, InvalidToken
from sqlalchemy.ext.asyncio import AsyncSession

from app.config import get_settings

logger = logging.getLogger(__name__)


# ============ Constants ============

GOOGLE_OAUTH_AUTHORIZE_URL: Final[str] = (
    "https://accounts.google.com/o/oauth2/v2/auth"
)
GOOGLE_OAUTH_TOKEN_URL: Final[str] = "https://oauth2.googleapis.com/token"
GOOGLE_CALENDAR_API_BASE: Final[str] = (
    "https://www.googleapis.com/calendar/v3"
)
GOOGLE_USERINFO_URL: Final[str] = (
    "https://openidconnect.googleapis.com/v1/userinfo"
)

# Scope для Calendar 2-way sync + минимум для опознания юзера (sub/email).
# offline_access НЕ нужен явно — `access_type=offline` в authorize URL
# делает то же самое (Google выдаст refresh_token).
GCAL_SCOPES: Final[tuple[str, ...]] = (
    "https://www.googleapis.com/auth/calendar.events",
    "openid",
    "email",
    "profile",
)

# Окно для первого pull (без syncToken). Берём прошлое 7 дней + 90 дней
# вперёд — типичный горизонт планирования встреч.
INITIAL_PULL_BACK_DAYS: Final[int] = 7
INITIAL_PULL_FORWARD_DAYS: Final[int] = 90

# За сколько секунд до истечения access_token считать его «скоро истечёт»
# и refresh'ить превентивно. Защита от race между check и httpx-вызовом.
ACCESS_TOKEN_REFRESH_BUFFER_SECONDS: Final[int] = 60

# Default длительность встречи если в Activity нет explicit end time.
# 60 минут — типичный слот в продажах.
DEFAULT_EVENT_DURATION_MINUTES: Final[int] = 60


# ============ Encryption ============


def _get_fernet() -> Fernet:
    """Получить Fernet для шифрования gcal tokens.

    Использует settings.gcal_encryption_key (с fallback на
    totp_encryption_key). Бросает ValueError если ключ не задан.
    """
    key = get_settings().gcal_encryption_key
    if not key:
        raise ValueError(
            "GOOGLE_OAUTH_ENCRYPTION_KEY / TOTP_ENCRYPTION_KEY не заданы "
            "→ gcal sync disabled",
        )
    return Fernet(key.encode("ascii") if isinstance(key, str) else key)


def encrypt_token(plain: str) -> str:
    """Зашифровать access/refresh token Fernet'ом для хранения в БД.

    Result — base64-URL-safe строка (Fernet token, ~150-200 chars).
    """
    if not plain:
        return ""
    token = _get_fernet().encrypt(plain.encode("ascii"))
    return token.decode("ascii")


def decrypt_token(enc: str) -> str:
    """Расшифровать access/refresh token из БД.

    Бросает ValueError если token подделан (InvalidToken).
    """
    if not enc:
        return ""
    try:
        plain = _get_fernet().decrypt(enc.encode("ascii"))
    except InvalidToken as e:
        raise ValueError("Invalid gcal token ciphertext") from e
    return plain.decode("ascii")


# ============ OAuth URL builders ============


def get_oauth_authorize_url(state: str, redirect_uri: str | None = None) -> str:
    """Сгенерировать Google OAuth Authorization URL с нужными scopes.

    `state` — random CSRF-token, ставится в cookie и сверяется в callback.
    `redirect_uri` — куда Google вернёт юзера; если None — берём из
    settings.gcal_redirect_uri.

    Используем `access_type=offline` чтобы Google выдал refresh_token (он
    нужен для долгоживущего sync без повторного consent).
    `prompt=consent` чтобы refresh_token выдавался даже при повторных
    подключениях (Google по умолчанию выдаёт его ТОЛЬКО на первом consent;
    с prompt=consent — каждый раз).
    """
    settings = get_settings()
    effective_redirect = redirect_uri or settings.gcal_redirect_uri
    params = {
        "response_type": "code",
        "client_id": settings.gcal_client_id,
        "redirect_uri": effective_redirect,
        "scope": " ".join(GCAL_SCOPES),
        "state": state,
        "access_type": "offline",
        "prompt": "consent",
        "include_granted_scopes": "true",
    }
    return f"{GOOGLE_OAUTH_AUTHORIZE_URL}?{urlencode(params)}"


# ============ Token exchange (async I/O) ============


async def exchange_code_for_tokens(
    code: str, redirect_uri: str | None = None,
) -> dict[str, Any]:
    """Обменять authorization code → access_token + refresh_token + id_token.

    Возвращает Google token response (raw dict). Поля:
    - access_token: str
    - refresh_token: str (только если access_type=offline и первый consent)
    - id_token: str (JWT, sub/email если был scope=openid)
    - expires_in: int (секунды до истечения access_token)
    - scope: str (space-separated, что юзер реально выдал)
    - token_type: "Bearer"

    Бросает httpx.HTTPStatusError на 4xx/5xx — caller обрабатывает.
    """
    settings = get_settings()
    effective_redirect = redirect_uri or settings.gcal_redirect_uri
    async with httpx.AsyncClient(timeout=15.0) as client:
        resp = await client.post(
            GOOGLE_OAUTH_TOKEN_URL,
            data={
                "code": code,
                "client_id": settings.gcal_client_id,
                "client_secret": settings.gcal_client_secret,
                "redirect_uri": effective_redirect,
                "grant_type": "authorization_code",
            },
        )
        resp.raise_for_status()
        return resp.json()


async def refresh_access_token(refresh_token: str) -> dict[str, Any]:
    """Обменять refresh_token → новый access_token.

    Возвращает Google token response. ВАЖНО: при refresh-grant Google НЕ
    возвращает новый refresh_token (старый продолжает работать). Поля:
    - access_token: str
    - expires_in: int
    - scope: str
    - token_type: "Bearer"

    Если refresh_token истёк/отозван — Google вернёт 400 invalid_grant,
    httpx бросит HTTPStatusError. Caller должен помечать link как
    sync_enabled=False и просить юзера повторить /connect.
    """
    settings = get_settings()
    async with httpx.AsyncClient(timeout=15.0) as client:
        resp = await client.post(
            GOOGLE_OAUTH_TOKEN_URL,
            data={
                "refresh_token": refresh_token,
                "client_id": settings.gcal_client_id,
                "client_secret": settings.gcal_client_secret,
                "grant_type": "refresh_token",
            },
        )
        resp.raise_for_status()
        return resp.json()


async def fetch_userinfo(access_token: str) -> dict[str, Any]:
    """Получить userinfo (sub/email/name) по access_token.

    Используется в callback'е чтобы заполнить google_user_id + google_email
    в link.
    """
    async with httpx.AsyncClient(timeout=10.0) as client:
        resp = await client.get(
            GOOGLE_USERINFO_URL,
            headers={"Authorization": f"Bearer {access_token}"},
        )
        resp.raise_for_status()
        return resp.json()


# ============ Token lifecycle helper ============


def _is_token_expired(expires_at: datetime | None) -> bool:
    """Истёк ли access_token (с буфером)?

    Возвращает True если token нужно refresh'ить. None expires_at = считаем
    истёкшим (безопасный default).
    """
    if expires_at is None:
        return True
    now = datetime.now(UTC)
    if expires_at.tzinfo is None:
        # Если из БД пришёл naive — считаем UTC.
        expires_at = expires_at.replace(tzinfo=UTC)
    return (
        expires_at - now
    ).total_seconds() <= ACCESS_TOKEN_REFRESH_BUFFER_SECONDS


async def _ensure_access_token(session: AsyncSession, link: Any) -> str:
    """Гарантировать актуальный access_token для link.

    Если token свежий → расшифровываем и возвращаем.
    Если истёк → refresh, обновляем БД, возвращаем новый.

    Бросает ValueError если refresh_token пуст / истёк (caller должен
    разрулить — пометить sync_enabled=False).
    """
    if not _is_token_expired(link.token_expires_at):
        if not link.access_token_encrypted:
            raise ValueError("link.access_token_encrypted пуст")
        return decrypt_token(link.access_token_encrypted)

    # Refresh
    if not link.refresh_token_encrypted:
        raise ValueError(
            "Нет refresh_token — юзер должен повторить /connect",
        )
    refresh = decrypt_token(link.refresh_token_encrypted)
    try:
        token_resp = await refresh_access_token(refresh)
    except httpx.HTTPStatusError as e:
        # 400 invalid_grant — refresh_token отозван.
        if e.response.status_code == 400:
            link.sync_enabled = False
            await session.flush()
        raise ValueError(f"Refresh failed: {e}") from e

    new_access = token_resp.get("access_token")
    if not new_access:
        raise ValueError("Refresh не вернул access_token")

    expires_in = int(token_resp.get("expires_in") or 3600)
    link.access_token_encrypted = encrypt_token(new_access)
    link.token_expires_at = datetime.now(UTC) + timedelta(seconds=expires_in)
    await session.flush()
    return new_access


# ============ Google Calendar API client (async) ============


async def list_events(
    session: AsyncSession,
    link: Any,
    *,
    sync_token: str | None = None,
    time_min: datetime | None = None,
    time_max: datetime | None = None,
    page_token: str | None = None,
) -> dict[str, Any]:
    """events.list — incremental pull через syncToken (если задан) или
    окно time_min..time_max.

    Google не позволяет одновременно syncToken и time_min — если есть
    syncToken, time_min/time_max игнорируются.

    Возвращает raw response:
    - items: list[dict] — события
    - nextSyncToken: str | None — для следующего incremental pull
    - nextPageToken: str | None — пагинация внутри одного pull

    Если Google вернул 410 Gone (sync_token устарел) — НЕ обрабатываем тут,
    caller (sync_user_calendar) должен поймать и сделать full re-pull.
    """
    access_token = await _ensure_access_token(session, link)
    params: dict[str, Any] = {
        "singleEvents": "true",  # разворачиваем recurring → instances
        "maxResults": 250,
    }
    if sync_token:
        params["syncToken"] = sync_token
    else:
        if time_min:
            params["timeMin"] = time_min.isoformat()
        if time_max:
            params["timeMax"] = time_max.isoformat()
        params["orderBy"] = "startTime"
    if page_token:
        params["pageToken"] = page_token

    url = (
        f"{GOOGLE_CALENDAR_API_BASE}/calendars/{link.calendar_id}/events"
    )
    async with httpx.AsyncClient(timeout=20.0) as client:
        resp = await client.get(
            url,
            headers={"Authorization": f"Bearer {access_token}"},
            params=params,
        )
        resp.raise_for_status()
        return resp.json()


async def create_event(
    session: AsyncSession,
    link: Any,
    event: dict[str, Any],
) -> dict[str, Any]:
    """events.insert — создать событие в Google Calendar.

    Возвращает raw Google response (включая id, etag, htmlLink).
    """
    access_token = await _ensure_access_token(session, link)
    url = (
        f"{GOOGLE_CALENDAR_API_BASE}/calendars/{link.calendar_id}/events"
    )
    async with httpx.AsyncClient(timeout=15.0) as client:
        resp = await client.post(
            url,
            headers={
                "Authorization": f"Bearer {access_token}",
                "Content-Type": "application/json",
            },
            json=event,
        )
        resp.raise_for_status()
        return resp.json()


async def update_event(
    session: AsyncSession,
    link: Any,
    event_id: str,
    event: dict[str, Any],
    *,
    etag: str | None = None,
) -> dict[str, Any]:
    """events.patch — обновить событие.

    Если etag передан — добавляем `If-Match: <etag>`. Google вернёт 412
    Precondition Failed если на стороне Google поменялось — caller
    должен поймать и инвалидировать link.
    """
    access_token = await _ensure_access_token(session, link)
    url = (
        f"{GOOGLE_CALENDAR_API_BASE}/calendars/{link.calendar_id}"
        f"/events/{event_id}"
    )
    headers = {
        "Authorization": f"Bearer {access_token}",
        "Content-Type": "application/json",
    }
    if etag:
        headers["If-Match"] = etag
    async with httpx.AsyncClient(timeout=15.0) as client:
        resp = await client.patch(url, headers=headers, json=event)
        resp.raise_for_status()
        return resp.json()


async def delete_event(
    session: AsyncSession,
    link: Any,
    event_id: str,
) -> bool:
    """events.delete — удалить событие.

    Возвращает True на success (204) / 410 (already gone — idempotent OK).
    False на остальные ошибки (caller логирует).
    """
    access_token = await _ensure_access_token(session, link)
    url = (
        f"{GOOGLE_CALENDAR_API_BASE}/calendars/{link.calendar_id}"
        f"/events/{event_id}"
    )
    async with httpx.AsyncClient(timeout=15.0) as client:
        resp = await client.delete(
            url, headers={"Authorization": f"Bearer {access_token}"},
        )
        if resp.status_code in (204, 410, 404):
            return True
        logger.warning(
            "gcal delete_event %s returned %d: %s",
            event_id, resp.status_code, resp.text[:200],
        )
        return False


# ============ Pure conversion helpers ============


def _activity_kind_to_event_summary(activity: Any) -> str:
    """Title для Google event'а.

    Префиксуем kind для удобства поиска в Google Calendar (юзер видит
    «[Встреча]» / «[Звонок]» в general календаре). title — из Activity.title.
    """
    prefix_map = {"meeting": "[Встреча]", "call": "[Звонок]"}
    prefix = prefix_map.get(activity.kind, "")
    title = activity.title or ""
    return f"{prefix} {title}".strip() if prefix else title


def _activity_due_at_with_tz(due_at: datetime) -> datetime:
    """Гарантировать что due_at имеет tzinfo — если naive, считаем UTC.

    Google Calendar API требует ISO 8601 с timezone offset.
    """
    if due_at.tzinfo is None:
        return due_at.replace(tzinfo=UTC)
    return due_at


def activity_to_event(activity: Any, user: Any = None) -> dict[str, Any]:
    """Pure helper: конвертация Activity → Google Calendar event body.

    Структура (subset of Google Calendar Event resource):
    - summary: str — title с префиксом kind
    - description: str — body + ссылка на CRM
    - start.dateTime / end.dateTime — ISO 8601 с tz
    - extendedProperties.private.macro_activity_id — наша обратная ссылка

    Длительность: если planned_hours задан — берём его, иначе
    DEFAULT_EVENT_DURATION_MINUTES.

    user — опционально для description (кто ответственный); если None,
    пропускаем.
    """
    if activity.due_at is None:
        raise ValueError(
            "Activity без due_at не может быть конвертирован в gcal event",
        )

    start = _activity_due_at_with_tz(activity.due_at)
    if activity.planned_hours and activity.planned_hours > 0:
        duration = timedelta(hours=float(activity.planned_hours))
    else:
        duration = timedelta(minutes=DEFAULT_EVENT_DURATION_MINUTES)
    end = start + duration

    summary = _activity_kind_to_event_summary(activity)
    description_parts: list[str] = []
    if activity.body:
        description_parts.append(activity.body)
    settings = get_settings()
    base_url = (settings.public_base_url or "").rstrip("/")
    if base_url:
        description_parts.append(
            f"Открыть в MACRO CRM: {base_url}/activities/{activity.id}",
        )
    if user is not None:
        description_parts.append(f"Ответственный: {user.full_name}")
    description = "\n\n".join(description_parts) if description_parts else None

    event: dict[str, Any] = {
        "summary": summary,
        "start": {"dateTime": start.isoformat()},
        "end": {"dateTime": end.isoformat()},
        "extendedProperties": {
            "private": {
                "macro_activity_id": str(activity.id),
                "macro_kind": activity.kind,
            },
        },
    }
    if description:
        event["description"] = description
    return event


def event_to_activity_patch(event: dict[str, Any]) -> dict[str, Any]:
    """Pure helper: конвертация Google event → словарь полей для Activity.

    Возвращает только поля, которые имеет смысл синкать обратно в CRM:
    - title — из event.summary (без [Встреча]/[Звонок] префикса)
    - body — из event.description (без auto-добавленной ссылки на CRM)
    - due_at — из event.start.dateTime (или start.date если allDay)
    - kind — angle inference из event.summary префикса (default 'meeting')

    Возвращает пустой dict если event «отменён» (event.status='cancelled')
    или нет start.dateTime — caller должен пропустить.
    """
    if event.get("status") == "cancelled":
        return {}

    start = event.get("start") or {}
    start_dt_str = start.get("dateTime") or start.get("date")
    if not start_dt_str:
        return {}

    try:
        # ISO 8601 с tz. Если allDay (только date), считаем 00:00 UTC.
        if "T" in start_dt_str:
            due_at = datetime.fromisoformat(start_dt_str.replace("Z", "+00:00"))
        else:
            due_at = datetime.fromisoformat(f"{start_dt_str}T00:00:00+00:00")
    except (ValueError, TypeError):
        return {}

    raw_summary = event.get("summary") or ""
    kind = "meeting"
    title = raw_summary
    # Убираем префикс если есть.
    if raw_summary.startswith("[Встреча]"):
        title = raw_summary.removeprefix("[Встреча]").strip()
        kind = "meeting"
    elif raw_summary.startswith("[Звонок]"):
        title = raw_summary.removeprefix("[Звонок]").strip()
        kind = "call"

    # Inference kind из extendedProperties если есть.
    ext = event.get("extendedProperties") or {}
    private = ext.get("private") or {}
    macro_kind = private.get("macro_kind")
    if macro_kind in ("meeting", "call"):
        kind = macro_kind

    description = event.get("description") or ""
    # Убираем auto-добавленные строки про «Открыть в MACRO CRM».
    if description:
        cleaned_lines = [
            line for line in description.split("\n")
            if not line.startswith("Открыть в MACRO CRM:")
            and not line.startswith("Ответственный:")
        ]
        description = "\n".join(cleaned_lines).strip()

    return {
        "title": title or "(без названия)",
        "body": description or None,
        "due_at": due_at,
        "kind": kind,
    }


def extract_macro_activity_id(event: dict[str, Any]) -> int | None:
    """Извлечь macro_activity_id из event.extendedProperties.private.

    Если есть — значит этот event был создан нашим CRM, и при pull'е
    мы должны искать уже существующий link, а не создавать новый Activity.
    """
    ext = event.get("extendedProperties") or {}
    private = ext.get("private") or {}
    raw = private.get("macro_activity_id")
    if raw is None:
        return None
    try:
        return int(raw)
    except (TypeError, ValueError):
        return None


# ============ Should-sync filter (pure) ============

# Допустимые kind'ы для sync.
SYNCABLE_KINDS: Final[tuple[str, ...]] = ("meeting", "call")

# Терминальные статусы Activity — задача завершена/отклонена, синкать в
# календарь не нужно. Полный enum status: new|in_progress|done|rejected
# (см. routers/activities.ACTIVITY_STATUSES). Терминальные — done и rejected.
# 'cancelled'/'completed' в модели НЕТ. Дополнительно is_closed=True (финально
# закрыта постановщиком) — тоже терминал.
TERMINAL_STATUSES: Final[tuple[str, ...]] = ("done", "rejected")


def _has_time_component(dt: datetime | None) -> bool:
    """True если у datetime есть осмысленная компонента времени.

    Считаем что 00:00:00 (полночь) — это «только дата без времени», т.е.
    «дата-напоминание». Реальная встреча имеет конкретный слот (например,
    14:30). При sync_only_with_time=true такие напоминания не синкаются.

    Edge case: реальная встреча ровно в полночь по локальному TZ — теоретически
    возможна, но крайне маловероятна; такая встреча будет skip'нута.
    Trade-off приемлем.
    """
    if dt is None:
        return False
    return dt.time() != time(0, 0, 0)


def should_sync_activity(activity: Any, link: Any) -> bool:
    """Pure helper: должны ли мы синкать Activity в Google Calendar?

    Правила:
    1. link.sync_enabled должен быть True.
    2. activity.kind in (meeting, call).
    3. activity НЕ в терминальном/закрытом состоянии (is_closed=True или
       status in done|rejected). Закрытая/отклонённая/выполненная задача не
       синкается и не создаёт событие в календаре. Это guard против дублей:
       заархивированные (rejected, is_closed) Activity после recovery имеют
       updated_at=now и попадают в _push_modified_activities; без этой проверки
       Create-ветка sync_activity_to_gcal запостила бы для них новые события.
    4. link.sync_<kind> должен быть True (sync_meeting / sync_call).
    5. activity.due_at должен быть задан (без даты сматчить не на что).
    6. Если link.sync_only_with_time=True — due_at должен иметь
       компоненту времени != 00:00:00.

    Возвращает True / False. Не бросает.

    status / is_closed читаем через getattr с безопасными дефолтами: pure-
    функция, duck-typed stub'ы в тестах могут не иметь этих полей (тогда
    задача считается активной).
    """
    if not link.sync_enabled:
        return False
    if getattr(activity, "is_closed", False):
        return False
    if getattr(activity, "status", None) in TERMINAL_STATUSES:
        return False
    kind = activity.kind
    if kind not in SYNCABLE_KINDS:
        return False
    if kind == "meeting" and not link.sync_meeting:
        return False
    if kind == "call" and not link.sync_call:
        return False
    if activity.due_at is None:
        return False
    if link.sync_only_with_time and not _has_time_component(activity.due_at):
        return False
    return True


# ============ Dataclasses for sync results (used in gcal_sync.py) ============


@dataclass(frozen=True)
class SyncResult:
    """Результат одной sync-операции (для возврата из sync_user_calendar)."""

    pulled_in: int = 0       # сколько events создали/обновили в CRM
    pushed_out: int = 0      # сколько events запушили в Google
    deleted_out: int = 0     # сколько events удалили из Google
    errors: int = 0          # сколько ошибок (логируется)
    full_resync: bool = False  # пришлось делать full re-pull (410 Gone)


def merge_sync_results(*results: SyncResult) -> SyncResult:
    """Объединить несколько SyncResult в один (используется в sync_all_users)."""
    return SyncResult(
        pulled_in=sum(r.pulled_in for r in results),
        pushed_out=sum(r.pushed_out for r in results),
        deleted_out=sum(r.deleted_out for r in results),
        errors=sum(r.errors for r in results),
        full_resync=any(r.full_resync for r in results),
    )
