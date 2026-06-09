"""Эпик 24.2 — Google Calendar conversion helpers (pure-function tests).

Тестируем activity_to_event, event_to_activity_patch, extract_macro_activity_id,
encrypt_token/decrypt_token. Без сети и БД.

Используем простые дата-классы как заглушки для Activity/User — нам не
нужен ORM, только duck-typing с нужными полями.
"""
from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime
from decimal import Decimal

import pytest
from cryptography.fernet import Fernet

from app.services import google_calendar as gcal_module
from app.services.google_calendar import (
    DEFAULT_EVENT_DURATION_MINUTES,
    activity_to_event,
    decrypt_token,
    encrypt_token,
    event_to_activity_patch,
    extract_macro_activity_id,
)


# ============ Test fixtures ============


@dataclass
class FakeActivity:
    """Заглушка Activity с минимальным набором полей для конверсии."""
    id: int = 42
    kind: str = "meeting"
    title: str = "Demo с клиентом"
    body: str | None = "Презентация продукта"
    due_at: datetime | None = None
    planned_hours: Decimal | None = None


@dataclass
class FakeUser:
    full_name: str = "Иван Иванов"


@pytest.fixture(autouse=True)
def _fernet_key(monkeypatch):
    """Перед каждым тестом ставим свежий Fernet ключ + public_base_url."""
    key = Fernet.generate_key().decode("ascii")

    class _S:
        gcal_encryption_key = key
        public_base_url = "https://contracts.macroglobal.tech"
        # Остальное не используется в этих тестах, но get_settings()
        # может потянуть — даём заглушки на случай.
        gcal_ready = True
        gcal_client_id = "x"
        gcal_client_secret = "y"
        gcal_redirect_uri = "https://example.com/cb"

    monkeypatch.setattr(gcal_module, "get_settings", lambda: _S())
    yield


# ============ encrypt_token / decrypt_token ============


def test_encrypt_decrypt_token_roundtrip():
    plain = "ya29.abc123XYZ_long_access_token"
    enc = encrypt_token(plain)
    assert enc != plain
    assert decrypt_token(enc) == plain


def test_encrypt_empty_token_returns_empty():
    assert encrypt_token("") == ""


def test_decrypt_empty_returns_empty():
    assert decrypt_token("") == ""


def test_decrypt_invalid_ciphertext_raises():
    with pytest.raises(ValueError):
        decrypt_token("not-a-fernet-token")


# ============ activity_to_event ============


def test_activity_to_event_basic_meeting():
    activity = FakeActivity(
        id=10,
        kind="meeting",
        title="Demo",
        body="Презентация",
        due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    user = FakeUser(full_name="Иван")
    event = activity_to_event(activity, user)

    assert "[Встреча]" in event["summary"]
    assert "Demo" in event["summary"]
    assert event["start"]["dateTime"].startswith("2026-06-05T14:30")
    # default duration = 60 min
    assert event["end"]["dateTime"].startswith("2026-06-05T15:30")
    # extendedProperties
    assert event["extendedProperties"]["private"]["macro_activity_id"] == "10"
    assert event["extendedProperties"]["private"]["macro_kind"] == "meeting"
    # description содержит body + CRM link + ответственный
    assert "Презентация" in event["description"]
    assert "macroglobal.tech/activities/10" in event["description"]
    assert "Иван" in event["description"]


def test_activity_to_event_call_kind_prefix():
    activity = FakeActivity(
        kind="call",
        title="Холодный звонок",
        due_at=datetime(2026, 6, 5, 10, 0, tzinfo=UTC),
    )
    event = activity_to_event(activity)
    assert event["summary"].startswith("[Звонок]")


def test_activity_to_event_uses_planned_hours_for_duration():
    activity = FakeActivity(
        kind="meeting",
        title="Long workshop",
        due_at=datetime(2026, 6, 5, 9, 0, tzinfo=UTC),
        planned_hours=Decimal("2.5"),
    )
    event = activity_to_event(activity)
    # 9:00 + 2.5h = 11:30
    assert event["end"]["dateTime"].startswith("2026-06-05T11:30")


def test_activity_to_event_no_user_omits_responsible():
    activity = FakeActivity(
        kind="meeting",
        title="Solo task",
        due_at=datetime(2026, 6, 5, 14, 0, tzinfo=UTC),
    )
    event = activity_to_event(activity, user=None)
    assert "Ответственный" not in (event.get("description") or "")


def test_activity_to_event_no_body_no_description_section():
    activity = FakeActivity(
        kind="meeting",
        title="Quick chat",
        body=None,
        due_at=datetime(2026, 6, 5, 14, 0, tzinfo=UTC),
    )
    event = activity_to_event(activity, user=None)
    # Должно быть либо без description, либо только с CRM link.
    desc = event.get("description") or ""
    assert "Презентация" not in desc


def test_activity_to_event_raises_without_due_at():
    activity = FakeActivity(due_at=None)
    with pytest.raises(ValueError):
        activity_to_event(activity)


def test_activity_to_event_naive_datetime_normalized_to_utc():
    activity = FakeActivity(
        kind="meeting",
        title="naive",
        due_at=datetime(2026, 6, 5, 14, 30),  # без tzinfo
    )
    event = activity_to_event(activity)
    # ISO должен иметь +00:00 (UTC).
    assert "+00:00" in event["start"]["dateTime"]


# ============ event_to_activity_patch ============


def test_event_to_activity_patch_basic_meeting():
    event = {
        "id": "abc123",
        "summary": "[Встреча] Demo с клиентом",
        "description": "Подготовиться к презентации",
        "start": {"dateTime": "2026-06-05T14:30:00+00:00"},
        "end": {"dateTime": "2026-06-05T15:30:00+00:00"},
    }
    patch = event_to_activity_patch(event)
    assert patch["title"] == "Demo с клиентом"
    assert patch["kind"] == "meeting"
    assert patch["body"] == "Подготовиться к презентации"
    assert patch["due_at"].year == 2026
    assert patch["due_at"].hour == 14


def test_event_to_activity_patch_call_prefix_detection():
    event = {
        "id": "x",
        "summary": "[Звонок] Лид Acme Inc",
        "start": {"dateTime": "2026-06-05T10:00:00+00:00"},
    }
    patch = event_to_activity_patch(event)
    assert patch["kind"] == "call"
    assert patch["title"] == "Лид Acme Inc"


def test_event_to_activity_patch_macro_kind_overrides_prefix():
    """extendedProperties.private.macro_kind перебивает префикс summary."""
    event = {
        "id": "x",
        "summary": "Random no-prefix title",
        "extendedProperties": {"private": {"macro_kind": "call"}},
        "start": {"dateTime": "2026-06-05T10:00:00+00:00"},
    }
    patch = event_to_activity_patch(event)
    assert patch["kind"] == "call"


def test_event_to_activity_patch_cancelled_returns_empty():
    event = {"id": "x", "status": "cancelled"}
    assert event_to_activity_patch(event) == {}


def test_event_to_activity_patch_no_start_returns_empty():
    event = {"id": "x", "summary": "no time"}
    assert event_to_activity_patch(event) == {}


def test_event_to_activity_patch_allday_treated_as_midnight_utc():
    event = {
        "id": "x",
        "summary": "All day event",
        "start": {"date": "2026-06-05"},
    }
    patch = event_to_activity_patch(event)
    assert patch["due_at"].hour == 0
    assert patch["due_at"].day == 5


def test_event_to_activity_patch_strips_macro_link_lines():
    """Auto-добавленные строки про CRM убираются при импорте обратно."""
    event = {
        "id": "x",
        "summary": "[Встреча] Test",
        "description": (
            "Основной текст\n\n"
            "Открыть в MACRO CRM: https://x/activities/10\n"
            "Ответственный: Иван"
        ),
        "start": {"dateTime": "2026-06-05T14:30:00+00:00"},
    }
    patch = event_to_activity_patch(event)
    assert "Открыть в MACRO CRM" not in (patch.get("body") or "")
    assert "Ответственный" not in (patch.get("body") or "")
    assert "Основной текст" in (patch.get("body") or "")


def test_event_to_activity_patch_invalid_datetime_returns_empty():
    event = {
        "id": "x",
        "summary": "test",
        "start": {"dateTime": "not-a-date"},
    }
    assert event_to_activity_patch(event) == {}


def test_event_to_activity_patch_z_suffix_normalized():
    """Google использует 'Z' вместо '+00:00' иногда — fromisoformat не любит."""
    event = {
        "id": "x",
        "summary": "test",
        "start": {"dateTime": "2026-06-05T14:30:00Z"},
    }
    patch = event_to_activity_patch(event)
    assert patch["due_at"].hour == 14


def test_event_to_activity_patch_no_title_fallback():
    event = {
        "id": "x",
        "summary": "",
        "start": {"dateTime": "2026-06-05T14:30:00+00:00"},
    }
    patch = event_to_activity_patch(event)
    assert patch["title"] == "(без названия)"


# ============ extract_macro_activity_id ============


def test_extract_macro_activity_id_present():
    event = {
        "extendedProperties": {"private": {"macro_activity_id": "42"}},
    }
    assert extract_macro_activity_id(event) == 42


def test_extract_macro_activity_id_missing():
    assert extract_macro_activity_id({}) is None
    assert extract_macro_activity_id({"extendedProperties": {}}) is None


def test_extract_macro_activity_id_non_numeric():
    event = {
        "extendedProperties": {"private": {"macro_activity_id": "abc"}},
    }
    assert extract_macro_activity_id(event) is None
