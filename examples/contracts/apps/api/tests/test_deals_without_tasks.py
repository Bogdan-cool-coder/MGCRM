"""Wave 2a — чистый unit-тест предиката «открытая задача» для виджета
«Сделки без задач». Без БД fixture."""
from __future__ import annotations

from datetime import UTC, datetime

import pytest

from app.services.analytics_deals import (
    TASK_LIKE_ACTIVITY_KINDS,
    is_open_task_activity,
)

NOW = datetime(2026, 6, 4, tzinfo=UTC)


def test_task_like_kinds_set():
    assert TASK_LIKE_ACTIVITY_KINDS == frozenset({"task", "call", "meeting"})


@pytest.mark.parametrize("kind", ["task", "call", "meeting"])
def test_open_task_like_is_open(kind):
    assert is_open_task_activity(kind, completed_at=None, is_closed=False) is True


def test_note_is_never_a_task():
    # note — не задача (это заметка), даже если не завершена/не закрыта.
    assert is_open_task_activity("note", completed_at=None, is_closed=False) is False


def test_completed_task_is_not_open():
    assert is_open_task_activity("task", completed_at=NOW, is_closed=False) is False


def test_closed_task_is_not_open():
    assert is_open_task_activity("task", completed_at=None, is_closed=True) is False


def test_unknown_kind_is_not_task():
    assert is_open_task_activity("email", completed_at=None, is_closed=False) is False
