"""Эпик 15 — Integration Hub: marketplace + settings + Calldown endpoints.

В одном файле:
- /integrations/marketplace — список доступных интеграций (любой авторизованный).
- /integrations/{provider}/settings — admin CRUD конкретного провайдера.
- /integrations/calldown/webhook/{provider} — PUBLIC inbound webhook (HMAC verify).
- /integrations/calldown/test-webhook — admin endpoint для test mock-events.
- /integrations/calldown/calls — list/детали/прикрепить-к-сделке.
- /integrations/calldown/calls/{id}/transcribe — manual trigger Whisper.
- /integrations/calldown/stats/today — KPI для CalldownLogsTab.
- /integrations/logs — унифицированный feed логов запросов (AIRequestLog +
  WebhookDelivery за 30 дней). Для admin Logs UI (Эпик 15).
- /integrations/logs/export — CSV-экспорт того же feed'а.

Старые роуты /integrations/google-drive/* сохраняем (back-compat).
"""
from __future__ import annotations

import csv
import io
import logging
from datetime import date, datetime, timedelta, timezone
from typing import Annotated, Any

from fastapi import (
    APIRouter,
    BackgroundTasks,
    Depends,
    Header,
    HTTPException,
    Query,
    Request,
    status,
)
from fastapi.responses import RedirectResponse, StreamingResponse
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.db import SessionLocal, get_session
from app.deps import AdminUser, CurrentUser, DirectorOrAdmin
from app.models import (
    AIRequestLog,
    APIToken,
    CalldownCall,
    IntegrationSettings,
    WebhookDelivery,
)
from app.services import drive
from app.services.calldown import (
    CALLDOWN_PROVIDERS,
    auto_match_counterparty,
    create_activity_from_call,
    detect_provider,
    parse_for_provider,
    verify_webhook_signature,
)
from app.services.integrations import (
    PROVIDERS,
    decrypt_sensitive_config,
    encrypt_sensitive_config,
    find_marketplace_entry,
    get_or_create_settings,
    get_settings_by_provider,
    list_marketplace,
    mask_sensitive_config,
    validate_provider,
)
from app.services.rate_limit import check_uptime_webhook_rate_limit
from app.services.uptime_alert import (
    format_uptime_message,
    send_telegram_alert,
    verify_secret,
)
from app.services.whisper import (
    WhisperAPIError,
    WhisperDownloadError,
    WhisperNotConfiguredError,
    is_configured as whisper_is_configured,
    transcribe_audio_url,
)

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/integrations", tags=["integrations"])
settings = get_settings()


# ============ Marketplace ============

class MarketplaceItemOut(BaseModel):
    provider: str
    name: str
    category: str
    icon: str
    description: str
    # Реальный статус «по факту»: "connected" | "available" | "coming_soon"
    status: str
    is_enabled: bool


@router.get("/marketplace", response_model=list[MarketplaceItemOut])
async def list_integration_marketplace(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Список доступных интеграций с фактическим статусом подключения.

    Для каждого entry проверяем — есть ли в БД IntegrationSettings и
    is_enabled=True → "connected"; иначе marketplace.status ("available"
    | "coming_soon").
    """
    entries = list_marketplace()
    # Подтянем все IntegrationSettings одним запросом (их мало).
    db_settings = (
        await session.execute(select(IntegrationSettings))
    ).scalars().all()
    by_provider = {s.provider: s for s in db_settings}

    out: list[MarketplaceItemOut] = []
    for entry in entries:
        s = by_provider.get(entry.provider)
        enabled = bool(s and s.is_enabled)
        status_label = "connected" if enabled else entry.status
        out.append(MarketplaceItemOut(
            provider=entry.provider,
            name=entry.name,
            category=entry.category,
            icon=entry.icon,
            description=entry.description,
            status=status_label,
            is_enabled=enabled,
        ))
    return out


# ============ Per-provider settings ============

class IntegrationSettingsOut(BaseModel):
    """Маскированный output. Sensitive-поля показываются как ****<last4>."""

    model_config = ConfigDict(from_attributes=True)
    id: int
    provider: str
    is_enabled: bool
    config: dict[str, Any]
    webhook_secret_preview: str  # last4
    last_sync_at: datetime | None
    created_at: datetime
    updated_at: datetime


class IntegrationSettingsUpdate(BaseModel):
    is_enabled: bool | None = None
    config: dict[str, Any] | None = None


def _mask_webhook_secret(secret: str | None) -> str:
    if not secret:
        return ""
    if len(secret) <= 4:
        return "****"
    return "****" + secret[-4:]


def _to_settings_out(item: IntegrationSettings) -> IntegrationSettingsOut:
    return IntegrationSettingsOut(
        id=item.id,
        provider=item.provider,
        is_enabled=item.is_enabled,
        config=mask_sensitive_config(item.provider, item.config or {}),
        webhook_secret_preview=_mask_webhook_secret(item.webhook_secret),
        last_sync_at=item.last_sync_at,
        created_at=item.created_at,
        updated_at=item.updated_at,
    )


@router.get(
    "/{provider}/settings", response_model=IntegrationSettingsOut,
)
async def get_integration_settings(
    provider: str,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Получить настройки провайдера. Создаёт пустую запись если не было."""
    if provider not in PROVIDERS:
        raise HTTPException(404, f"Неизвестный провайдер: {provider!r}")
    item = await get_or_create_settings(session, provider)
    await session.commit()
    return _to_settings_out(item)


@router.patch(
    "/{provider}/settings", response_model=IntegrationSettingsOut,
)
async def update_integration_settings(
    provider: str,
    payload: IntegrationSettingsUpdate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Обновить is_enabled и/или config (sensitive поля шифруются Fernet'ом)."""
    if provider not in PROVIDERS:
        raise HTTPException(404, f"Неизвестный провайдер: {provider!r}")
    item = await get_or_create_settings(session, provider)
    if payload.is_enabled is not None:
        item.is_enabled = bool(payload.is_enabled)
    if payload.config is not None:
        # Merge: новые значения накладываются на старые, но если в payload
        # есть masked ("****<last4>") — игнорим (значит UI не показывал
        # реальное значение и не должен перезаписать).
        existing = dict(item.config or {})
        for k, v in payload.config.items():
            if isinstance(v, str) and v.startswith("****"):
                # Маскированное значение из UI — оставляем существующее.
                continue
            existing[k] = v
        # Шифруем sensitive поля.
        item.config = encrypt_sensitive_config(provider, existing)
    await session.commit()
    await session.refresh(item)
    return _to_settings_out(item)


# ============ Calldown — inbound webhook (PUBLIC, no auth) ============

@router.post(
    "/calldown/webhook/{provider}",
    status_code=status.HTTP_200_OK,
)
async def calldown_inbound_webhook(
    provider: str,
    request: Request,
    background_tasks: BackgroundTasks,
    session: Annotated[AsyncSession, Depends(get_session)],
    x_signature: Annotated[str | None, Header(alias="X-Signature")] = None,
):
    """Inbound webhook от Mango/UIS.

    Public endpoint без cookie. Защита — HMAC-SHA256 подпись через
    integration_settings.webhook_secret. Тело payload — JSON.

    Идемпотентность: дедуп по (provider, external_call_id) — повторные
    доставки одного и того же call'а идут в один и тот же ряд.
    """
    if provider not in CALLDOWN_PROVIDERS:
        raise HTTPException(404, f"Неизвестный Calldown-провайдер: {provider!r}")

    item = await get_settings_by_provider(session, provider)
    if not item or not item.is_enabled or not item.webhook_secret:
        raise HTTPException(503, "Calldown integration не активирован для этого провайдера")

    body_bytes = await request.body()
    if not verify_webhook_signature(body_bytes, x_signature, item.webhook_secret):
        raise HTTPException(401, "Невалидная подпись webhook'а")

    # Парсим JSON. FastAPI Body доступен только через request.json()
    # потому что мы уже прочитали body для signature.
    import json as _json
    try:
        payload = _json.loads(body_bytes.decode("utf-8") or "{}")
    except (ValueError, UnicodeDecodeError) as e:
        raise HTTPException(400, f"Невалидный JSON payload: {e}") from e
    if not isinstance(payload, dict):
        raise HTTPException(400, "Payload должен быть JSON-объектом")

    try:
        event = parse_for_provider(detect_provider(provider), payload)
    except ValueError as e:
        raise HTTPException(400, str(e)) from e

    # Идемпотентность: ищем существующий по (provider, external_call_id).
    existing: CalldownCall | None = None
    if event.external_call_id:
        existing = (
            await session.execute(
                select(CalldownCall).where(
                    CalldownCall.provider == event.provider,
                    CalldownCall.external_call_id == event.external_call_id,
                )
            )
        ).scalar_one_or_none()

    if existing:
        # Обновляем только заполненные поля (Mango шлёт серию событий —
        # каждое следующее может дополнить картину).
        _update_call_from_event(existing, event)
        await session.commit()
        return {"ok": True, "call_id": existing.id, "deduped": True}

    # Новый звонок — создаём.
    call = CalldownCall(
        provider=event.provider,
        external_call_id=event.external_call_id,
        direction=event.direction,
        from_number=event.from_number,
        to_number=event.to_number,
        duration_seconds=event.duration_seconds,
        started_at=event.started_at,
        ended_at=event.ended_at,
        recording_url=event.recording_url,
        raw_payload=event.raw_payload,
    )
    session.add(call)
    await session.flush()

    # Auto-match counterparty (входящие — по from_number, исходящие — по to_number).
    match_phone = event.from_number if event.direction == "in" else event.to_number
    counterparty = await auto_match_counterparty(match_phone, session)
    if counterparty is not None:
        call.counterparty_id = counterparty.id

    # Auto-create Activity.
    activity = await create_activity_from_call(
        session, call, counterparty, created_by_user_id=None
    )
    call.activity_id = activity.id

    await session.commit()

    # Если есть recording_url и whisper включён — фоном запускаем транскрипцию.
    if event.recording_url and whisper_is_configured():
        call.transcript_status = "pending"
        await session.commit()
        background_tasks.add_task(
            _bg_transcribe_call, call.id, event.recording_url, "ru"
        )

    return {
        "ok": True,
        "call_id": call.id,
        "counterparty_id": call.counterparty_id,
        "activity_id": call.activity_id,
    }


def _update_call_from_event(call: CalldownCall, event) -> None:
    """Обновить call только теми полями event'а, которые не пустые."""
    if event.from_number:
        call.from_number = event.from_number
    if event.to_number:
        call.to_number = event.to_number
    if event.duration_seconds is not None:
        call.duration_seconds = event.duration_seconds
    if event.started_at is not None:
        call.started_at = event.started_at
    if event.ended_at is not None:
        call.ended_at = event.ended_at
    if event.recording_url:
        call.recording_url = event.recording_url
    if event.raw_payload:
        call.raw_payload = event.raw_payload


async def _bg_transcribe_call(call_id: int, url: str, lang: str) -> None:
    """Background task: скачать → Whisper → обновить call.transcript_text.

    Открываем СВОЮ сессию (не та что в request — FastAPI её уже закрыла).
    Catch на всё чтобы воркер не упал.
    """
    try:
        # Поставим processing
        async with SessionLocal() as session:
            row = (
                await session.execute(
                    select(CalldownCall).where(CalldownCall.id == call_id)
                )
            ).scalar_one_or_none()
            if not row:
                return
            row.transcript_status = "processing"
            row.transcript_lang = lang
            await session.commit()

        # Сама транскрипция (вне сессии — может занять до минуты).
        result = await transcribe_audio_url(url, lang)

        async with SessionLocal() as session:
            row = (
                await session.execute(
                    select(CalldownCall).where(CalldownCall.id == call_id)
                )
            ).scalar_one_or_none()
            if not row:
                return
            row.transcript_text = result.text
            row.transcript_lang = result.lang
            row.transcript_status = "done"
            await session.commit()
    except (WhisperNotConfiguredError, WhisperDownloadError, WhisperAPIError) as e:
        logger.warning("Whisper transcription failed for call_id=%s: %s", call_id, e)
        async with SessionLocal() as session:
            row = (
                await session.execute(
                    select(CalldownCall).where(CalldownCall.id == call_id)
                )
            ).scalar_one_or_none()
            if row:
                row.transcript_status = "failed"
                await session.commit()
    except Exception as e:  # noqa: BLE001
        logger.exception("Whisper bg task crashed for call_id=%s: %s", call_id, e)


# ============ Calldown — test webhook (admin) ============

class CalldownTestPayload(BaseModel):
    provider: str = Field(default="calldown_mango")
    direction: str = Field(default="in")
    from_number: str = Field(default="+77001234567")
    to_number: str = Field(default="+77007654321")
    duration_seconds: int = Field(default=60)


@router.post("/calldown/test-webhook")
async def calldown_test_webhook(
    payload: CalldownTestPayload,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Admin endpoint: создать тестовый CalldownCall без HMAC проверки.

    Используется в UI «Проверить подключение» — фронт жмёт кнопку, мы
    создаём mock-call и возвращаем id. Counterparty match, activity-create
    отрабатывают как для реального webhook'а.
    """
    if payload.provider not in CALLDOWN_PROVIDERS:
        raise HTTPException(400, f"Неизвестный провайдер: {payload.provider!r}")
    if payload.direction not in ("in", "out"):
        raise HTTPException(400, "direction должен быть in или out")

    # Имитируем nucленное событие
    import secrets as _secrets
    call = CalldownCall(
        provider=payload.provider,
        external_call_id=f"test-{_secrets.token_hex(6)}",
        direction=payload.direction,
        from_number=payload.from_number.lstrip("+"),
        to_number=payload.to_number.lstrip("+"),
        duration_seconds=payload.duration_seconds,
        started_at=datetime.utcnow(),
        ended_at=datetime.utcnow(),
        recording_url=None,
        raw_payload={"test": True},
    )
    session.add(call)
    await session.flush()

    match_phone = call.from_number if call.direction == "in" else call.to_number
    counterparty = await auto_match_counterparty(match_phone, session)
    if counterparty is not None:
        call.counterparty_id = counterparty.id
    activity = await create_activity_from_call(
        session, call, counterparty, created_by_user_id=None
    )
    call.activity_id = activity.id
    await session.commit()
    return {
        "ok": True,
        "call_id": call.id,
        "counterparty_id": call.counterparty_id,
        "activity_id": call.activity_id,
    }


# ============ UptimeRobot → Telegram alert (PUBLIC, secret-protected) ============

@router.api_route(
    "/uptime-webhook",
    methods=["POST"],
    status_code=status.HTTP_200_OK,
)
async def uptimerobot_webhook(
    request: Request,
    background_tasks: BackgroundTasks,
    secret: Annotated[str | None, Query()] = None,
    x_webhook_secret: Annotated[
        str | None, Header(alias="X-Webhook-Secret")
    ] = None,
):
    """PUBLIC webhook от UptimeRobot — форвардит DOWN/UP алерт в Telegram.

    Защита — shared-secret (НЕ cookie-auth; как inbound-каналы). Секрет
    приходит через ?secret= ИЛИ заголовок X-Webhook-Secret. Параметры
    UptimeRobot принимаем И из query-string, И из form-body (UptimeRobot
    может слать любым способом): alertType, monitorFriendlyName, monitorURL,
    alertDetails, alertDuration.

    Контракт ответа:
    - secret НЕ сконфигурен в .env → 503 (fail-CLOSED, никакого unauth-форвардинга).
    - secret не совпал → 403.
    - rate-limit превышен → 429.
    - валидный вызов → ВСЕГДА 200 (UptimeRobot ретраит на non-2xx; не утекаем
      внутренние детали). Отправка в Telegram — фоном, best-effort.
    """
    settings_ = get_settings()

    # 1) Fail-CLOSED: секрет не сконфигурен → отклоняем всех.
    if not settings_.uptime_webhook_ready:
        raise HTTPException(503, "Uptime-webhook не сконфигурен")

    # 2) Verify secret (query или header), constant-time.
    provided = secret or x_webhook_secret
    if not verify_secret(provided, settings_.uptimerobot_webhook_secret):
        raise HTTPException(403, "Невалидный секрет")

    # 3) Rate-limit per-IP (fail-safe).
    client_ip = request.client.host if request.client else None
    if not await check_uptime_webhook_rate_limit(client_ip):
        raise HTTPException(429, "Слишком много запросов")

    # 4) Параметры: объединяем query + form-body (form имеет приоритет).
    params: dict[str, str] = dict(request.query_params)
    try:
        form = await request.form()
        for k, v in form.items():
            if isinstance(v, str):
                params[k] = v
    except Exception:  # noqa: BLE001
        # Тело не form-encoded (или пустое) — работаем по query-string.
        pass

    message = format_uptime_message(
        alert_type=params.get("alertType"),
        monitor_name=params.get("monitorFriendlyName"),
        monitor_url=params.get("monitorURL"),
        alert_details=params.get("alertDetails"),
        alert_duration=params.get("alertDuration"),
    )

    # 5) Форвард в Telegram — фоном, чтобы не держать UptimeRobot на сетевом RTT.
    background_tasks.add_task(
        send_telegram_alert,
        settings_.telegram_alert_bot_token,
        settings_.telegram_alert_chat_id,
        message,
    )

    return {"ok": True}


# ============ Calldown — list / details / patch ============

class CalldownCallOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    provider: str
    external_call_id: str | None
    direction: str
    from_number: str | None
    to_number: str | None
    duration_seconds: int | None
    started_at: datetime | None
    ended_at: datetime | None
    recording_url: str | None
    transcript_text: str | None
    transcript_status: str | None
    transcript_lang: str | None
    user_id: int | None
    counterparty_id: int | None
    deal_id: int | None
    activity_id: int | None
    created_at: datetime


@router.get("/calldown/calls", response_model=list[CalldownCallOut])
async def list_calldown_calls(
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    limit: int = Query(default=50, ge=1, le=200),
    direction: str | None = Query(default=None),
    user_id: int | None = Query(default=None),
    counterparty_id: int | None = Query(default=None),
):
    """List звонков с базовыми фильтрами.

    Звонки содержат чувствительные данные (транскрипты, записи, телефоны),
    поэтому доступ ограничен ролью director/admin. Per-call owner-scope
    (как у Activity) можно добавить позже, если потребуется выдать доступ
    рядовым менеджерам только к своим звонкам.
    """
    stmt = select(CalldownCall).order_by(
        CalldownCall.started_at.desc().nulls_last(),
        CalldownCall.created_at.desc(),
    ).limit(limit)
    if direction in ("in", "out"):
        stmt = stmt.where(CalldownCall.direction == direction)
    if user_id is not None:
        stmt = stmt.where(CalldownCall.user_id == user_id)
    if counterparty_id is not None:
        stmt = stmt.where(CalldownCall.counterparty_id == counterparty_id)
    rows = (await session.execute(stmt)).scalars().all()
    return rows


@router.get(
    "/calldown/calls/{call_id}", response_model=CalldownCallOut,
)
async def get_calldown_call(
    call_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    row = (
        await session.execute(
            select(CalldownCall).where(CalldownCall.id == call_id)
        )
    ).scalar_one_or_none()
    if not row:
        raise HTTPException(404, "Звонок не найден")
    return row


class AttachDealPayload(BaseModel):
    deal_id: int | None


@router.patch(
    "/calldown/calls/{call_id}/attach-deal", response_model=CalldownCallOut,
)
async def attach_call_to_deal(
    call_id: int,
    payload: AttachDealPayload,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Привязать звонок к сделке (или открепить, если deal_id=null)."""
    row = (
        await session.execute(
            select(CalldownCall).where(CalldownCall.id == call_id)
        )
    ).scalar_one_or_none()
    if not row:
        raise HTTPException(404, "Звонок не найден")
    if payload.deal_id is not None:
        from app.models import Deal
        from app.services.access_control import ensure_object_visible

        deal = (
            await session.execute(
                select(Deal).where(Deal.id == payload.deal_id)
            )
        ).scalar_one_or_none()
        if not deal:
            raise HTTPException(404, "Сделка не найдена")
        await ensure_object_visible(session, deal, "deal", current_user)
    row.deal_id = payload.deal_id
    await session.commit()
    await session.refresh(row)
    return row


@router.post("/calldown/calls/{call_id}/transcribe")
async def trigger_transcribe(
    call_id: int,
    background_tasks: BackgroundTasks,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    lang: str = Query(default="ru"),
):
    """Manual trigger транскрипции.

    Если Whisper не сконфигурирован → 503. Если recording_url нет → 400.
    Запуск идёт фоном; UI poll'ит call.transcript_status.
    """
    if not whisper_is_configured():
        raise HTTPException(503, "OpenAI Whisper не настроен (OPENAI_API_KEY пуст)")
    row = (
        await session.execute(
            select(CalldownCall).where(CalldownCall.id == call_id)
        )
    ).scalar_one_or_none()
    if not row:
        raise HTTPException(404, "Звонок не найден")
    if not row.recording_url:
        raise HTTPException(400, "У звонка нет записи (recording_url пуст)")
    row.transcript_status = "pending"
    row.transcript_lang = lang
    await session.commit()
    background_tasks.add_task(
        _bg_transcribe_call, row.id, row.recording_url, lang
    )
    return {"ok": True, "call_id": row.id, "status": "pending"}


# ============ Calldown KPI ============

class CalldownStatsToday(BaseModel):
    """Сводка по звонкам за сегодня — для KPI-карточек в /admin/integrations/logs.

    Поля today_count / transcribed_count / error_count — то, что фактически
    рендерит CalldownLogsTab.tsx. Доп. поля calls_today / transcripts_done /
    avg_duration_minutes / attached_to_deals — расширенная сводка (на будущее
    UI / документация для public API).
    """
    today_count: int
    transcribed_count: int
    error_count: int
    # Расширенный набор (alias-friendly для public API).
    calls_today: int
    transcripts_done: int
    avg_duration_minutes: float
    attached_to_deals: int


@router.get("/calldown/stats/today", response_model=CalldownStatsToday)
async def calldown_stats_today(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """KPI по звонкам за сегодня (UTC).

    today_count — все звонки, у которых started_at попадает в сегодняшний
    UTC-день (или, если started_at отсутствует, created_at). transcribed_count —
    transcript_status='done'. error_count — transcript_status='failed'.

    avg_duration_minutes считается по звонкам, у которых duration_seconds
    задан и >0 (NULL/0 не должны утопить среднее).
    """
    # Граница сегодняшнего UTC-дня. Frontend форматирует в local TZ, поэтому
    # начало дня в UTC — приемлемый компромисс на MVP.
    now_utc = datetime.now(timezone.utc)
    day_start = now_utc.replace(hour=0, minute=0, second=0, microsecond=0)
    day_end = day_start + timedelta(days=1)

    # COALESCE(started_at, created_at) — чтобы старые webhook'и без started_at
    # тоже попадали в счёт.
    today_filter = func.coalesce(
        CalldownCall.started_at, CalldownCall.created_at,
    ).between(day_start, day_end)

    total_today = (
        await session.execute(
            select(func.count(CalldownCall.id)).where(today_filter)
        )
    ).scalar_one()

    transcripts_done = (
        await session.execute(
            select(func.count(CalldownCall.id)).where(
                today_filter,
                CalldownCall.transcript_status == "done",
            )
        )
    ).scalar_one()

    transcripts_failed = (
        await session.execute(
            select(func.count(CalldownCall.id)).where(
                today_filter,
                CalldownCall.transcript_status == "failed",
            )
        )
    ).scalar_one()

    avg_duration_sec = (
        await session.execute(
            select(func.avg(CalldownCall.duration_seconds)).where(
                today_filter,
                CalldownCall.duration_seconds.is_not(None),
                CalldownCall.duration_seconds > 0,
            )
        )
    ).scalar_one()

    attached_to_deals = (
        await session.execute(
            select(func.count(CalldownCall.id)).where(
                today_filter,
                CalldownCall.deal_id.is_not(None),
            )
        )
    ).scalar_one()

    avg_minutes = (
        float(avg_duration_sec) / 60.0 if avg_duration_sec else 0.0
    )

    return CalldownStatsToday(
        today_count=int(total_today or 0),
        transcribed_count=int(transcripts_done or 0),
        error_count=int(transcripts_failed or 0),
        calls_today=int(total_today or 0),
        transcripts_done=int(transcripts_done or 0),
        avg_duration_minutes=round(avg_minutes, 2),
        attached_to_deals=int(attached_to_deals or 0),
    )


# ============ Унифицированный лог запросов (AIRequestLog + WebhookDelivery) ============

# Frontend (Эпик 15 — LogsViewer) ожидает «API request log» с полями
# method / path / status_code / duration_ms. У нас нет универсального HTTP
# middleware-лога — собираем синтетический feed из двух источников за
# последние 30 дней:
#   - AIRequestLog → POST /api/ai/{feature}, HTTP-code = 200 (success) /
#     500 (error) / 503 (not_configured) / 429 (rate_limited).
#   - WebhookDelivery → POST /api/webhooks/out/{event}, code = last_http_code.
# Сортировка по created_at desc. Пагинация — limit/offset на каждый источник
# раздельно (берём с запасом, потом merge-sort и отрезаем).

# Хранение фиксированное — 30 дней (не настраивается).
_LOG_WINDOW_DAYS = 30


def _ai_status_to_http_code(status: str | None, error_message: str | None) -> int:
    """Отображение AIRequestLog.status в синтетический HTTP-код для UI."""
    if status == "success":
        return 200
    if status == "rate_limited":
        return 429
    if status == "not_configured":
        return 503
    # status == "error" или unknown
    if error_message:
        return 500
    return 500


def _bucket_for_status(status_code: int) -> str:
    if 200 <= status_code < 300:
        return "2xx"
    if 300 <= status_code < 400:
        return "3xx"
    if 400 <= status_code < 500:
        return "4xx"
    return "5xx"


class ApiRequestLogItem(BaseModel):
    id: int
    token_id: int | None
    token_name: str | None
    method: str
    path: str
    status_code: int
    duration_ms: int
    created_at: datetime
    # source — служебное поле для фронта (не отображается, но позволяет фильтровать).
    source: str  # 'ai' | 'webhook'


class ApiRequestLogsResponse(BaseModel):
    items: list[ApiRequestLogItem]
    total: int


async def _collect_log_items(
    session: AsyncSession,
    token_id: int | None,
    status_bucket: str | None,
    from_date: date | None,
    to_date: date | None,
) -> list[ApiRequestLogItem]:
    """Собрать унифицированный feed (без пагинации — пагинация делается caller'ом).

    token_id фильтрует только если задан — но у наших источников tokens нет
    (AI/webhook = внутренние вызовы). Поэтому при token_id != None
    возвращаем пустой список (нет данных для конкретного токена; явно
    указано в ApiRequestLog.token_id).
    """
    if token_id is not None:
        # На сегодня source-таблицы (ai_request_log, webhook_deliveries) не
        # ссылаются на APIToken — лог по конкретному токену пуст.
        return []

    # Окно по умолчанию — последние 30 дней.
    now_utc = datetime.now(timezone.utc)
    default_from = now_utc - timedelta(days=_LOG_WINDOW_DAYS)
    from_dt = (
        datetime.combine(from_date, datetime.min.time(), tzinfo=timezone.utc)
        if from_date else default_from
    )
    to_dt = (
        datetime.combine(to_date, datetime.max.time(), tzinfo=timezone.utc)
        if to_date else now_utc
    )

    items: list[ApiRequestLogItem] = []

    # 1) AIRequestLog (Эпик 18)
    ai_stmt = (
        select(AIRequestLog)
        .where(
            AIRequestLog.created_at >= from_dt,
            AIRequestLog.created_at <= to_dt,
        )
        .order_by(AIRequestLog.created_at.desc())
        .limit(500)
    )
    ai_rows = (await session.execute(ai_stmt)).scalars().all()
    for row in ai_rows:
        code = _ai_status_to_http_code(row.status, row.error_message)
        if status_bucket and _bucket_for_status(code) != status_bucket:
            continue
        items.append(ApiRequestLogItem(
            id=row.id,
            token_id=None,
            token_name=None,
            method="POST",
            path=f"/api/ai/{row.feature}",
            status_code=code,
            duration_ms=int(row.duration_ms or 0),
            created_at=row.created_at,
            source="ai",
        ))

    # 2) WebhookDelivery (Эпик 11.2)
    wh_stmt = (
        select(WebhookDelivery)
        .where(
            WebhookDelivery.created_at >= from_dt,
            WebhookDelivery.created_at <= to_dt,
        )
        .order_by(WebhookDelivery.created_at.desc())
        .limit(500)
    )
    wh_rows = (await session.execute(wh_stmt)).scalars().all()
    for row in wh_rows:
        # last_http_code может быть NULL (pending) — отдадим 0 (фронт покажет «—»).
        # Если status='success' и code пуст — отображаем 200 для удобства UI.
        if row.last_http_code is not None:
            code = int(row.last_http_code)
        elif row.status == "success":
            code = 200
        elif row.status in ("pending", "retrying"):
            code = 0
        else:
            code = 500
        if status_bucket and code > 0 and _bucket_for_status(code) != status_bucket:
            continue
        if status_bucket and code == 0:
            # pending/retrying не попадают ни в 2xx/4xx/5xx — скрываем при фильтре.
            continue
        # duration не отслеживаем у webhook'ов — отдадим 0.
        # Composite id = -row.id чтобы не пересекался с AIRequestLog.id
        # (фронт уникальности использует id; отрицательный безопасен).
        items.append(ApiRequestLogItem(
            id=-row.id,
            token_id=None,
            token_name=None,
            method="POST",
            path=f"/api/webhooks/out/{row.event}",
            status_code=code,
            duration_ms=0,
            created_at=row.created_at,
            source="webhook",
        ))

    # Сортировка merged по created_at desc.
    items.sort(key=lambda x: x.created_at, reverse=True)
    return items


@router.get("/logs", response_model=ApiRequestLogsResponse)
async def list_api_request_logs(
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    token_id: int | None = Query(None),
    status: str | None = Query(None, pattern="^(2xx|3xx|4xx|5xx)$"),
    from_date: date | None = Query(None, alias="from"),
    to_date: date | None = Query(None, alias="to"),
    limit: int = Query(50, ge=1, le=200),
    offset: int = Query(0, ge=0),
):
    """Унифицированный feed API-запросов: AIRequestLog + WebhookDelivery за 30 дней.

    Только admin: показывает потенциально чувствительные пути и коды ошибок.
    На сегодня token_id-фильтр всегда возвращает пустой набор — наши логи
    не ссылаются на конкретный APIToken (это внутренние AI/webhook-вызовы;
    Public API tokens получат отдельный лог в следующих эпиках).
    """
    items = await _collect_log_items(
        session, token_id, status, from_date, to_date
    )
    total = len(items)
    paged = items[offset:offset + limit]
    return ApiRequestLogsResponse(items=paged, total=total)


@router.get("/logs/export")
async def export_api_request_logs(
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    token_id: int | None = Query(None),
    status: str | None = Query(None, pattern="^(2xx|3xx|4xx|5xx)$"),
    from_date: date | None = Query(None, alias="from"),
    to_date: date | None = Query(None, alias="to"),
    format: str = Query("csv", pattern="^(csv)$"),
):
    """CSV-экспорт того же feed'а. Только admin. UTF-8 BOM для Excel."""
    items = await _collect_log_items(
        session, token_id, status, from_date, to_date
    )

    buf = io.StringIO()
    # UTF-8 BOM, чтобы Excel правильно открыл кириллицу.
    buf.write("﻿")
    writer = csv.writer(buf)
    writer.writerow([
        "id", "source", "method", "path", "status_code",
        "duration_ms", "created_at",
    ])
    for item in items:
        writer.writerow([
            item.id,
            item.source,
            item.method,
            item.path,
            item.status_code,
            item.duration_ms,
            item.created_at.isoformat() if item.created_at else "",
        ])
    buf.seek(0)

    filename = f"integration-logs-{datetime.now(timezone.utc):%Y%m%d-%H%M%S}.csv"
    return StreamingResponse(
        iter([buf.getvalue()]),
        media_type="text/csv; charset=utf-8",
        headers={"Content-Disposition": f'attachment; filename="{filename}"'},
    )


# ============ Старые роуты Google Drive (back-compat) ============

@router.get("/google-drive/status")
async def google_drive_status(_: CurrentUser):
    return {
        "has_client_config": drive.has_client_config(),
        "configured": drive.is_configured(),
        "connected_email": drive.get_connected_email(),
        "redirect_uri": drive.redirect_uri(),
    }


class OAuthClientConfig(BaseModel):
    client_id: str
    client_secret: str


@router.post("/google-drive/oauth/config", status_code=status.HTTP_201_CREATED)
async def set_oauth_config(payload: OAuthClientConfig, _: AdminUser):
    if not payload.client_id.strip() or not payload.client_secret.strip():
        raise HTTPException(400, "Client ID и Client Secret обязательны")
    drive.save_client_config(payload.client_id, payload.client_secret)
    return {"ok": True}


@router.get("/google-drive/oauth/start")
async def oauth_start(_: AdminUser):
    """Возвращает URL согласия Google для редиректа."""
    try:
        url = drive.build_auth_url()
    except RuntimeError as e:
        raise HTTPException(400, str(e)) from e
    return {"auth_url": url}


@router.get("/google-drive/oauth/callback")
async def oauth_callback(
    code: str | None = Query(None),
    state: str | None = Query(None),
    error: str | None = Query(None),
):
    """Google редиректит сюда после согласия. Публичный (без auth — приходит из браузера).
    Защита — CSRF state. Обменивает code на refresh_token и редиректит в админку."""
    base = f"{settings.public_base_url}/admin/integrations"
    if error:
        # Баг C4 WARN-6: не отражаем сырой query-param в redirect. Маппим на
        # whitelist известных OAuth-кодов Google, остальное → generic.
        _known_oauth_errors = {
            "access_denied",
            "invalid_request",
            "unauthorized_client",
            "unsupported_response_type",
            "invalid_scope",
            "server_error",
            "temporarily_unavailable",
        }
        reason = error if error in _known_oauth_errors else "denied"
        return RedirectResponse(f"{base}?google=error&reason={reason}")
    if not code or not state:
        return RedirectResponse(f"{base}?google=error&reason=no_code")
    try:
        email = drive.exchange_code(code, state)
        return RedirectResponse(f"{base}?google=connected&email={email}")
    except Exception:  # noqa: BLE001
        # Баг C4 WARN-6: не льём str(e) (внутренние пути/детали) в браузер.
        # Полностью логируем, наружу — обобщённый код.
        logger.exception("Google Drive OAuth callback failed")
        return RedirectResponse(f"{base}?google=error&reason=internal")


@router.post("/google-drive/oauth/disconnect", status_code=status.HTTP_204_NO_CONTENT)
async def oauth_disconnect(_: AdminUser):
    drive.disconnect()


# ============ C5: allowlist папок Google Drive (anti arbitrary-folder upload) ============


class DriveAllowedFolders(BaseModel):
    # Ссылки или id папок. Пустой список снимает ограничение (любая папка).
    folders: list[str] = []


@router.get("/google-drive/allowed-folders")
async def get_allowed_folders(_: AdminUser):
    """Текущий allowlist папок Drive. Пустой = ограничения нет."""
    return {"folder_ids": drive.get_allowed_folder_ids()}


@router.put("/google-drive/allowed-folders")
async def set_allowed_folders(payload: DriveAllowedFolders, _: AdminUser):
    """Задать allowlist папок (ссылки/id нормализуются в id). Пустой список —
    снять ограничение, разрешив выгрузку в любую доступную папку."""
    folder_ids = drive.set_allowed_folder_ids(payload.folders)
    return {"folder_ids": folder_ids}
