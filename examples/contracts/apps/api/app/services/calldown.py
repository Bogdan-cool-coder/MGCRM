"""Эпик 15 — Calldown сервис: парсинг webhook'ов от Mango/UIS + автоматизация.

Чем занимается:
- `parse_mango_webhook(payload)` / `parse_uis_webhook(payload)` — pure-функции,
  превращают raw JSON от провайдера в нормализованный CallEvent dataclass.
  Разные провайдеры присылают разные форматы — нормализуем здесь.

- `verify_webhook_signature(payload, signature, secret)` — pure-функция,
  HMAC-SHA256 constant-time проверка. Используется в роутере перед парсингом.

- `normalize_phone(raw)` — приводим телефон к E.164-like формату (без +,
  без скобок/дефисов/пробелов) для сравнения с counterparties.phone.

- `auto_match_counterparty(phone, session)` — поиск контрагента по
  нормализованному телефону.

- `create_activity_from_call(session, call)` — создаёт Activity(kind='call')
  и привязывает к counterparty/deal. Возвращает Activity.id для записи
  в call.activity_id.

Что НЕ делаем:
- Не пишем напрямую в БД из parse_* — это pure-функции.
- Не отправляем POST в Whisper отсюда — это services/whisper.py.

NB: Все pure-функции тестируются без БД-фикстуры в tests/test_calldown_parsers.py.
"""
from __future__ import annotations

import hashlib
import hmac
import re
from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Any

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import Activity, CalldownCall, Counterparty

# Whitelist провайдеров. Любой другой → 404 в роутере.
CALLDOWN_PROVIDERS: frozenset[str] = frozenset({"calldown_mango", "calldown_uis"})


# ============ DataClass ============

@dataclass
class CallEvent:
    """Нормализованное событие звонка от Calldown-провайдера.

    Поля могут быть None если провайдер их не присылает в данном callback'е
    (Mango шлёт серию events: start, ringing, answered, ended — у каждого
    разный набор полей). В таком случае на upsert обновим только заполненные
    поля.
    """
    provider: str
    external_call_id: str | None
    direction: str  # "in" | "out"
    from_number: str | None
    to_number: str | None
    duration_seconds: int | None = None
    started_at: datetime | None = None
    ended_at: datetime | None = None
    recording_url: str | None = None
    raw_payload: dict[str, Any] = field(default_factory=dict)


# ============ Webhook signature verification ============

def verify_webhook_signature(
    payload_body: bytes, signature_header: str | None, secret: str
) -> bool:
    """HMAC-SHA256 constant-time проверка подписи inbound webhook'а.

    Mango/UIS отправляют header `X-Signature: <hex>` (формат без префикса,
    в отличие от наших исходящих с префиксом "sha256="). Парсим оба
    варианта: с префиксом и без.

    Pure-функция, без сети. Constant-time через hmac.compare_digest.
    """
    if not signature_header or not secret:
        return False
    if not isinstance(payload_body, (bytes, bytearray)):
        return False
    # Принимаем оба формата: "sha256=<hex>" и просто "<hex>".
    sig = signature_header.strip()
    if sig.startswith("sha256="):
        sig = sig[len("sha256="):]
    expected = hmac.new(
        secret.encode("utf-8"), bytes(payload_body), hashlib.sha256
    ).hexdigest()
    try:
        return hmac.compare_digest(expected, sig.lower())
    except (TypeError, ValueError):
        return False


# ============ Phone normalization ============

_PHONE_STRIP = re.compile(r"[^\d]")


def normalize_phone(raw: str | None) -> str | None:
    """Привести телефон к виду «только цифры» (без +, скобок, дефисов).

    Используется для сравнения с counterparties.phone (которые тоже могут
    приходить в разных форматах). Возвращает None если на вход пусто или
    нет цифр.

    Примеры:
      "+7 (700) 123-45-67" → "77001234567"
      "8-700-123-45-67"    → "87001234567"
      "internal:101"       → "101"
      None                 → None
    """
    if not raw:
        return None
    digits = _PHONE_STRIP.sub("", str(raw))
    if not digits:
        return None
    return digits


# ============ Mango Office webhook parser ============

# Mango API event-types: "call_start" | "ring" | "answer" | "ended" | "voicemail".
# В MVP смотрим только "call_start" (для дедупа external_call_id) и "ended"
# (для duration и ended_at).
_MANGO_EVENT_TYPES_KEEP: frozenset[str] = frozenset({
    "call_start", "ring", "answer", "ended", "voicemail",
})


def parse_mango_webhook(payload: dict[str, Any]) -> CallEvent:
    """Парсить Mango Office webhook → CallEvent.

    Формат Mango (упрощённый MVP):
    {
      "entry_id": "uuid",         # external_call_id
      "event": "ended",           # тип события
      "from": {"number": "..."},
      "to":   {"number": "..."},
      "call_direction": "in" | "out",
      "duration": 120,            # seconds (только в ended)
      "timestamp": "1717325000",  # unix ts
      "recording_url": "..."      # ссылка на запись (только в ended)
    }

    Pure-функция. Не валидирует обязательность полей жёстко — ставим None
    в CallEvent, БД дальше переживёт NULL.

    Raises:
        ValueError если direction не in/out (защита от мусора).
    """
    if not isinstance(payload, dict):
        raise ValueError("Mango payload должен быть dict")

    external_id = payload.get("entry_id") or payload.get("call_id") or payload.get("id")
    direction_raw = (payload.get("call_direction") or payload.get("direction") or "").lower()
    if direction_raw not in ("in", "out", "inbound", "outbound"):
        raise ValueError(f"Mango: неизвестный direction {direction_raw!r}")
    direction = "in" if direction_raw in ("in", "inbound") else "out"

    from_obj = payload.get("from") or {}
    to_obj = payload.get("to") or {}
    from_number = normalize_phone(
        from_obj.get("number") if isinstance(from_obj, dict) else from_obj
    )
    to_number = normalize_phone(
        to_obj.get("number") if isinstance(to_obj, dict) else to_obj
    )

    duration = payload.get("duration")
    try:
        duration_int = int(duration) if duration is not None else None
    except (TypeError, ValueError):
        duration_int = None

    ts_raw = payload.get("timestamp")
    started_at: datetime | None = None
    ended_at: datetime | None = None
    if ts_raw is not None:
        try:
            ts_dt = datetime.fromtimestamp(int(ts_raw), tz=UTC)
            event_kind = payload.get("event", "")
            if event_kind == "ended":
                ended_at = ts_dt
                # Mango в "ended" присылает timestamp окончания. start_at можно
                # вычислить по duration, если есть.
                if duration_int is not None:
                    started_at = datetime.fromtimestamp(
                        int(ts_raw) - duration_int, tz=UTC
                    )
            else:
                started_at = ts_dt
        except (TypeError, ValueError):
            pass

    return CallEvent(
        provider="calldown_mango",
        external_call_id=str(external_id) if external_id else None,
        direction=direction,
        from_number=from_number,
        to_number=to_number,
        duration_seconds=duration_int,
        started_at=started_at,
        ended_at=ended_at,
        recording_url=payload.get("recording_url"),
        raw_payload=payload,
    )


# ============ UIS webhook parser ============

def parse_uis_webhook(payload: dict[str, Any]) -> CallEvent:
    """Парсить UIS Communications webhook → CallEvent.

    Формат UIS (упрощённый MVP):
    {
      "call_session_id": "uuid",
      "event_type": "call_ended",
      "direction": "incoming" | "outgoing",
      "caller_number": "...",     # для incoming
      "called_number": "...",     # для outgoing
      "duration": 120,
      "start_time": "2026-06-02T12:00:00Z",
      "end_time":   "2026-06-02T12:02:00Z",
      "record_url": "..."
    }

    Pure-функция. ValueError при невалидном direction.
    """
    if not isinstance(payload, dict):
        raise ValueError("UIS payload должен быть dict")

    external_id = (
        payload.get("call_session_id")
        or payload.get("call_id")
        or payload.get("id")
    )
    direction_raw = (payload.get("direction") or "").lower()
    if direction_raw not in ("in", "out", "incoming", "outgoing"):
        raise ValueError(f"UIS: неизвестный direction {direction_raw!r}")
    direction = "in" if direction_raw in ("in", "incoming") else "out"

    from_number = normalize_phone(
        payload.get("caller_number") or payload.get("from_number")
    )
    to_number = normalize_phone(
        payload.get("called_number") or payload.get("to_number")
    )

    duration = payload.get("duration")
    try:
        duration_int = int(duration) if duration is not None else None
    except (TypeError, ValueError):
        duration_int = None

    started_at = _parse_iso_dt(payload.get("start_time"))
    ended_at = _parse_iso_dt(payload.get("end_time"))

    return CallEvent(
        provider="calldown_uis",
        external_call_id=str(external_id) if external_id else None,
        direction=direction,
        from_number=from_number,
        to_number=to_number,
        duration_seconds=duration_int,
        started_at=started_at,
        ended_at=ended_at,
        recording_url=payload.get("record_url") or payload.get("recording_url"),
        raw_payload=payload,
    )


def _parse_iso_dt(value: Any) -> datetime | None:
    """Парсить ISO-8601 datetime → UTC-aware datetime, либо None."""
    if not value or not isinstance(value, str):
        return None
    try:
        # fromisoformat принимает "2026-06-02T12:00:00Z" с Python 3.11+
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None


# ============ Matching counterparty по телефону ============

async def auto_match_counterparty(
    phone_number: str | None, session: AsyncSession
) -> Counterparty | None:
    """Найти Counterparty по нормализованному номеру телефона.

    Сравниваем `normalize_phone(counterparties.phone) == normalize_phone(input)`.
    Берём только цифры — это устойчиво к разным форматам ввода (+7…, 8…, со
    скобками и без). При нескольких совпадениях возвращаем самое раннее
    созданное (детерминированный выбор).

    Возвращает None если phone пуст или ни одного совпадения.
    """
    target = normalize_phone(phone_number)
    if not target:
        return None
    # Загружаем всех с непустым phone и фильтруем в Python (нет индекса на
    # normalized form, а вариантов слишком много для регулярки в SQL).
    # Для прод-объёмов <10k counterparties это OK; при росте можно
    # ввести generated column normalized_phone + index.
    rows = (
        await session.execute(
            select(Counterparty)
            .where(Counterparty.phone.is_not(None))
            .order_by(Counterparty.created_at)
        )
    ).scalars().all()
    for cp in rows:
        if normalize_phone(cp.phone) == target:
            return cp
    return None


# ============ Activity creation ============

def _build_activity_title(call: CalldownCall, counterparty_name: str | None) -> str:
    """Сформировать заголовок Activity на основе данных звонка.

    «Входящий звонок от <КА>» либо «Исходящий звонок» с номером.
    """
    direction_label = "Входящий" if call.direction == "in" else "Исходящий"
    if counterparty_name:
        return f"{direction_label} звонок · {counterparty_name}"
    phone = call.from_number if call.direction == "in" else call.to_number
    suffix = phone or "—"
    return f"{direction_label} звонок · {suffix}"


def _build_activity_body(call: CalldownCall) -> str:
    """Сформировать body Activity: длительность + ссылка на запись (если есть)."""
    parts: list[str] = []
    if call.duration_seconds is not None:
        m, s = divmod(call.duration_seconds, 60)
        parts.append(f"Длительность: {m} мин {s} сек")
    if call.recording_url:
        parts.append(f"Запись: {call.recording_url}")
    if call.transcript_text:
        # Лимит на длину body — берём первые 4096 символов
        parts.append(f"Транскрипт: {call.transcript_text[:4096]}")
    return "\n".join(parts) if parts else ""


async def create_activity_from_call(
    session: AsyncSession,
    call: CalldownCall,
    counterparty: Counterparty | None,
    created_by_user_id: int | None,
) -> Activity:
    """Создать Activity(kind='call') и вернуть.

    target_type/target_id:
      - есть deal → ("deal", deal_id)
      - есть counterparty → ("counterparty", cp.id)
      - иначе ("call", call.id) — фоллбэк, чтобы запись была видна в общем
        списке активностей без жёсткого target'а.

    completed_at = call.ended_at (звонок уже завершён, ставим как
    выполненную задачу).

    Caller должен await commit'нуть после вызова, мы только session.add.
    """
    target_type: str
    target_id: int
    if call.deal_id is not None:
        target_type, target_id = "deal", call.deal_id
    elif counterparty is not None:
        target_type, target_id = "counterparty", counterparty.id
    else:
        target_type, target_id = "call", call.id

    cp_name = counterparty.name if counterparty else None
    title = _build_activity_title(call, cp_name)
    body = _build_activity_body(call)

    # created_by_id NOT NULL по схеме — fallback на 1 (admin) если не передан.
    # В прод-инсталляции системные вызовы всегда имеют admin'а seed'нутого.
    fallback_creator = created_by_user_id or 1

    activity = Activity(
        kind="call",
        target_type=target_type,
        target_id=target_id,
        title=title[:255],
        body=body or None,
        completed_at=call.ended_at or datetime.now(UTC),
        completed_by_id=call.user_id,
        responsible_id=call.user_id,
        created_by_id=fallback_creator,
    )
    session.add(activity)
    await session.flush()
    return activity


# ============ Auto-detect provider ============

def detect_provider(provider_hint: str) -> str:
    """Нормализация имени провайдера, ValueError при незнакомом.

    Используется в роутере /api/integrations/calldown/webhook/{provider}.
    """
    if provider_hint not in CALLDOWN_PROVIDERS:
        raise ValueError(
            f"Неизвестный Calldown-провайдер: {provider_hint!r}. "
            f"Допустимые: {sorted(CALLDOWN_PROVIDERS)}"
        )
    return provider_hint


def parse_for_provider(provider: str, payload: dict[str, Any]) -> CallEvent:
    """Диспетчер: выбрать parser по имени провайдера."""
    if provider == "calldown_mango":
        return parse_mango_webhook(payload)
    if provider == "calldown_uis":
        return parse_uis_webhook(payload)
    raise ValueError(f"Нет parser'а для провайдера {provider!r}")
