"""Эпик 15 — Calldown: парсинг Mango/UIS webhook'ов + HMAC verify (pure-function).

Без DB-фикстуры: проверяем парсеры, нормализацию телефона, signature-verify,
маршрутизацию по провайдеру.
"""
from __future__ import annotations

import hashlib
import hmac

import pytest

from app.services.calldown import (
    CALLDOWN_PROVIDERS,
    CallEvent,
    detect_provider,
    normalize_phone,
    parse_for_provider,
    parse_mango_webhook,
    parse_uis_webhook,
    verify_webhook_signature,
)


# ============ HMAC signature verify ============

def test_verify_signature_valid():
    """Корректная hex-подпись валидна."""
    secret = "test-secret-123"
    body = b'{"event":"call_start","entry_id":"x"}'
    sig = hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
    assert verify_webhook_signature(body, sig, secret) is True


def test_verify_signature_with_prefix():
    """Принимаем формат `sha256=<hex>` (как у GitHub/Mailgun)."""
    secret = "test-secret-123"
    body = b'{"event":"ended"}'
    sig = hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
    assert verify_webhook_signature(body, f"sha256={sig}", secret) is True


def test_verify_signature_tampered_body():
    """Изменение body → подпись невалидна."""
    secret = "test-secret-123"
    body = b'{"event":"ended"}'
    sig = hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
    tampered = b'{"event":"ended","tampered":true}'
    assert verify_webhook_signature(tampered, sig, secret) is False


def test_verify_signature_empty():
    """Пустой signature/secret → False."""
    assert verify_webhook_signature(b"x", None, "s") is False
    assert verify_webhook_signature(b"x", "abc", "") is False
    # not bytes
    assert verify_webhook_signature("not bytes", "abc", "s") is False  # type: ignore[arg-type]


def test_verify_signature_wrong_secret():
    """Неправильный secret → False."""
    body = b'{"x":1}'
    sig = hmac.new(b"correct", body, hashlib.sha256).hexdigest()
    assert verify_webhook_signature(body, sig, "wrong") is False


# ============ normalize_phone ============

@pytest.mark.parametrize(
    "raw,expected",
    [
        ("+7 (700) 123-45-67", "77001234567"),
        ("8-700-123-45-67", "87001234567"),
        ("77001234567", "77001234567"),
        ("internal:101", "101"),
        ("", None),
        (None, None),
        ("no digits here!", None),
        ("  +7  700  123-45-67  ", "77001234567"),
    ],
)
def test_normalize_phone(raw, expected):
    assert normalize_phone(raw) == expected


# ============ Mango parser ============

def test_parse_mango_call_start():
    """Парсим start-event: только заполненные поля."""
    payload = {
        "entry_id": "call-uuid-1",
        "event": "call_start",
        "from": {"number": "+77001234567"},
        "to": {"number": "+77007654321"},
        "call_direction": "in",
        "timestamp": "1717325000",
    }
    event = parse_mango_webhook(payload)
    assert isinstance(event, CallEvent)
    assert event.provider == "calldown_mango"
    assert event.external_call_id == "call-uuid-1"
    assert event.direction == "in"
    assert event.from_number == "77001234567"
    assert event.to_number == "77007654321"
    assert event.duration_seconds is None
    assert event.started_at is not None
    assert event.ended_at is None


def test_parse_mango_call_ended():
    """Парсим ended-event: появляется duration + recording_url."""
    payload = {
        "entry_id": "call-uuid-2",
        "event": "ended",
        "from": {"number": "77001234567"},
        "to": {"number": "77007654321"},
        "call_direction": "out",
        "duration": 120,
        "timestamp": "1717325120",
        "recording_url": "https://mango.example.com/rec/x.mp3",
    }
    event = parse_mango_webhook(payload)
    assert event.direction == "out"
    assert event.duration_seconds == 120
    assert event.recording_url == "https://mango.example.com/rec/x.mp3"
    assert event.ended_at is not None
    assert event.started_at is not None
    # started_at = ended_at - duration
    delta = (event.ended_at - event.started_at).total_seconds()
    assert int(delta) == 120


def test_parse_mango_invalid_direction():
    """Неизвестный direction → ValueError."""
    payload = {
        "entry_id": "x",
        "call_direction": "sideways",
        "from": {"number": "1"},
        "to": {"number": "2"},
    }
    with pytest.raises(ValueError, match="direction"):
        parse_mango_webhook(payload)


def test_parse_mango_not_dict():
    with pytest.raises(ValueError, match="dict"):
        parse_mango_webhook([])  # type: ignore[arg-type]


def test_parse_mango_accepts_inbound_outbound_alias():
    """direction='inbound' тоже принимается (мапим в 'in')."""
    payload = {
        "entry_id": "x",
        "call_direction": "outbound",
        "from": {"number": "1"},
        "to": {"number": "2"},
    }
    event = parse_mango_webhook(payload)
    assert event.direction == "out"


def test_parse_mango_strings_for_numbers():
    """from/to могут быть просто строкой (некоторые версии Mango API)."""
    payload = {
        "entry_id": "x",
        "call_direction": "in",
        "from": "+77001234567",
        "to": "+77007654321",
    }
    event = parse_mango_webhook(payload)
    assert event.from_number == "77001234567"
    assert event.to_number == "77007654321"


def test_parse_mango_raw_payload_preserved():
    """raw_payload содержит исходное тело — для аудита."""
    payload = {
        "entry_id": "x",
        "call_direction": "in",
        "from": {"number": "1"},
        "to": {"number": "2"},
        "custom_field": "ignored",
    }
    event = parse_mango_webhook(payload)
    assert event.raw_payload == payload
    assert event.raw_payload["custom_field"] == "ignored"


# ============ UIS parser ============

def test_parse_uis_ended_event():
    payload = {
        "call_session_id": "uis-call-1",
        "event_type": "call_ended",
        "direction": "incoming",
        "caller_number": "+77001234567",
        "called_number": "+77007654321",
        "duration": 90,
        "start_time": "2026-06-02T12:00:00Z",
        "end_time": "2026-06-02T12:01:30Z",
        "record_url": "https://uiscom.example/rec/y.wav",
    }
    event = parse_uis_webhook(payload)
    assert event.provider == "calldown_uis"
    assert event.external_call_id == "uis-call-1"
    assert event.direction == "in"
    assert event.from_number == "77001234567"
    assert event.to_number == "77007654321"
    assert event.duration_seconds == 90
    assert event.recording_url == "https://uiscom.example/rec/y.wav"
    assert event.started_at is not None
    assert event.ended_at is not None


def test_parse_uis_outgoing():
    payload = {
        "call_session_id": "uis-call-2",
        "direction": "outgoing",
        "caller_number": "77007654321",
        "called_number": "77001234567",
        "duration": 30,
        "start_time": "2026-06-02T12:00:00Z",
        "end_time": "2026-06-02T12:00:30Z",
    }
    event = parse_uis_webhook(payload)
    assert event.direction == "out"


def test_parse_uis_invalid_direction():
    payload = {
        "call_session_id": "x",
        "direction": "unknown",
        "caller_number": "1",
        "called_number": "2",
    }
    with pytest.raises(ValueError, match="direction"):
        parse_uis_webhook(payload)


def test_parse_uis_invalid_time_silently_none():
    """Невалидная дата → None, не падаем."""
    payload = {
        "call_session_id": "x",
        "direction": "in",
        "caller_number": "1",
        "called_number": "2",
        "start_time": "not-a-date",
    }
    event = parse_uis_webhook(payload)
    assert event.started_at is None


# ============ detect_provider / parse_for_provider ============

def test_detect_provider_whitelist():
    """В whitelist — OK, иначе ValueError."""
    assert detect_provider("calldown_mango") == "calldown_mango"
    assert detect_provider("calldown_uis") == "calldown_uis"
    with pytest.raises(ValueError):
        detect_provider("unknown_provider")


def test_calldown_providers_constant():
    """Whitelist состоит ровно из mango + uis."""
    assert CALLDOWN_PROVIDERS == frozenset({"calldown_mango", "calldown_uis"})


def test_parse_for_provider_dispatches():
    """parse_for_provider выбирает правильный парсер."""
    mango_payload = {
        "entry_id": "m1",
        "call_direction": "in",
        "from": {"number": "1"},
        "to": {"number": "2"},
    }
    uis_payload = {
        "call_session_id": "u1",
        "direction": "in",
        "caller_number": "1",
        "called_number": "2",
    }
    e1 = parse_for_provider("calldown_mango", mango_payload)
    e2 = parse_for_provider("calldown_uis", uis_payload)
    assert e1.provider == "calldown_mango"
    assert e2.provider == "calldown_uis"


def test_parse_for_provider_unknown():
    with pytest.raises(ValueError):
        parse_for_provider("nope", {})


# ============ Activity title builder (косвенно через create_activity_from_call) ============

def test_activity_title_helpers():
    """Внутренние хелперы должны порождать читабельные строки."""
    from app.services.calldown import _build_activity_title, _build_activity_body

    # Мок CalldownCall с нужными полями
    class _Mock:
        direction = "in"
        from_number = "77001234567"
        to_number = "77007654321"
        duration_seconds = 65
        recording_url = "https://r/x.mp3"
        transcript_text = None

    title = _build_activity_title(_Mock(), "ООО Тест")  # type: ignore[arg-type]
    assert "Входящий" in title and "ООО Тест" in title

    body = _build_activity_body(_Mock())  # type: ignore[arg-type]
    assert "1 мин 5 сек" in body
    assert "https://r/x.mp3" in body
