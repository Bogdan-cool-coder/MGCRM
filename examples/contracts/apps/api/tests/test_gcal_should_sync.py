"""Эпик 24.2 — should_sync_activity logic (pure-function).

Покрываем все ветки фильтра — sync_enabled, kind, sync_meeting/sync_call,
sync_only_with_time, due_at None. Заглушки Activity/Link через dataclass.
"""
from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime

from app.services.google_calendar import should_sync_activity


@dataclass
class FakeLink:
    sync_enabled: bool = True
    sync_meeting: bool = True
    sync_call: bool = False
    sync_only_with_time: bool = True


@dataclass
class FakeActivity:
    kind: str = "meeting"
    due_at: datetime | None = None


# ============ sync_enabled gating ============


def test_should_sync_false_when_sync_disabled():
    link = FakeLink(sync_enabled=False)
    activity = FakeActivity(
        kind="meeting", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is False


def test_should_sync_true_when_sync_enabled_meeting_with_time():
    link = FakeLink()
    activity = FakeActivity(
        kind="meeting", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is True


# ============ kind filter ============


def test_should_sync_false_for_task_kind():
    link = FakeLink()
    activity = FakeActivity(
        kind="task", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is False


def test_should_sync_false_for_note_kind():
    link = FakeLink()
    activity = FakeActivity(
        kind="note", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is False


def test_should_sync_true_for_meeting_kind():
    link = FakeLink()
    activity = FakeActivity(
        kind="meeting", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is True


def test_should_sync_true_for_call_kind_when_enabled():
    link = FakeLink(sync_call=True)
    activity = FakeActivity(
        kind="call", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is True


# ============ per-kind toggles ============


def test_should_sync_false_when_meeting_disabled():
    link = FakeLink(sync_meeting=False)
    activity = FakeActivity(
        kind="meeting", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is False


def test_should_sync_false_when_call_disabled_by_default():
    link = FakeLink()  # sync_call=False by default
    activity = FakeActivity(
        kind="call", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is False


def test_should_sync_meeting_disabled_does_not_affect_call():
    link = FakeLink(sync_meeting=False, sync_call=True)
    activity = FakeActivity(
        kind="call", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is True


# ============ due_at requirements ============


def test_should_sync_false_when_due_at_none():
    link = FakeLink()
    activity = FakeActivity(kind="meeting", due_at=None)
    assert should_sync_activity(activity, link) is False


def test_should_sync_false_when_only_with_time_and_midnight():
    """due_at=00:00 — считаем «дата-напоминание», skip."""
    link = FakeLink(sync_only_with_time=True)
    activity = FakeActivity(
        kind="meeting", due_at=datetime(2026, 6, 5, 0, 0, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is False


def test_should_sync_true_with_midnight_if_only_with_time_off():
    """sync_only_with_time=False → даже midnight синкаем."""
    link = FakeLink(sync_only_with_time=False)
    activity = FakeActivity(
        kind="meeting", due_at=datetime(2026, 6, 5, 0, 0, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is True


def test_should_sync_true_with_non_midnight_when_only_with_time():
    """due_at=14:30 — реальный слот, синкаем."""
    link = FakeLink(sync_only_with_time=True)
    activity = FakeActivity(
        kind="meeting", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is True


def test_should_sync_call_with_time_when_enabled():
    link = FakeLink(sync_call=True, sync_only_with_time=True)
    activity = FakeActivity(
        kind="call", due_at=datetime(2026, 6, 5, 9, 15, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is True


# ============ combinations ============


def test_should_sync_full_disable_overrides_everything():
    link = FakeLink(
        sync_enabled=False, sync_meeting=True, sync_call=True,
        sync_only_with_time=False,
    )
    activity = FakeActivity(
        kind="meeting", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is False


def test_should_sync_call_disabled_by_kind_even_if_with_time():
    link = FakeLink(sync_call=False)
    activity = FakeActivity(
        kind="call", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is False


def test_should_sync_unknown_kind_false():
    link = FakeLink()
    activity = FakeActivity(
        kind="random_kind", due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is False


def test_should_sync_seconds_in_time_still_counts():
    """due_at=14:30:45 — компонента времени != 00:00 → ок."""
    link = FakeLink(sync_only_with_time=True)
    activity = FakeActivity(
        kind="meeting", due_at=datetime(2026, 6, 5, 14, 30, 45, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is True


def test_should_sync_microseconds_only_still_counts():
    """due_at=00:00:00.123456 — компонента времени != 00:00:00 → ок,
    т.к. .time() возвращает (0, 0, 0, microsecond=123456)."""
    link = FakeLink(sync_only_with_time=True)
    activity = FakeActivity(
        kind="meeting",
        due_at=datetime(2026, 6, 5, 0, 0, 0, 123456, tzinfo=UTC),
    )
    assert should_sync_activity(activity, link) is True
