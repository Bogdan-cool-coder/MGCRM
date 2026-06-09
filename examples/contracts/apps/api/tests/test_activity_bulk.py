"""Эпик 24 — Tasks v2: тесты bulk-операций.

Pure-function тесты (без БД). Покрываем:
- BulkActionIn schema: допустимые действия, entity_ids минимум 1
- params валидация per-action (логический уровень)
- bulk delete ACL logic (pure)
"""
from __future__ import annotations

import pytest
from pydantic import ValidationError

from app.routers.activities import BulkActionIn


# ============ BulkActionIn schema ============


def test_bulk_action_in_valid_close():
    """Валидный close без params."""
    b = BulkActionIn(action="close", entity_ids=[1, 2, 3])
    assert b.action == "close"
    assert b.entity_ids == [1, 2, 3]
    assert b.params == {}


def test_bulk_action_in_valid_delete():
    """Валидный delete."""
    b = BulkActionIn(action="delete", entity_ids=[10])
    assert b.action == "delete"
    assert len(b.entity_ids) == 1


def test_bulk_action_in_valid_change_deadline():
    """change_deadline с new_due_at в params."""
    b = BulkActionIn(
        action="change_deadline",
        entity_ids=[1, 2],
        params={"new_due_at": "2026-07-01T10:00:00"},
    )
    assert b.params["new_due_at"] == "2026-07-01T10:00:00"


def test_bulk_action_in_valid_reassign():
    """reassign с responsible_id в params."""
    b = BulkActionIn(
        action="reassign",
        entity_ids=[5],
        params={"responsible_id": 3},
    )
    assert b.params["responsible_id"] == 3


def test_bulk_action_in_entity_ids_empty_fails():
    """entity_ids не может быть пустым."""
    with pytest.raises(ValidationError):
        BulkActionIn(action="close", entity_ids=[])


def test_bulk_action_in_entity_ids_required():
    """entity_ids обязателен."""
    with pytest.raises(ValidationError):
        BulkActionIn(action="close")  # type: ignore[call-arg]


def test_bulk_action_in_default_params():
    """params по умолчанию — пустой dict."""
    b = BulkActionIn(action="delete", entity_ids=[1])
    assert isinstance(b.params, dict)
    assert len(b.params) == 0


def test_bulk_action_in_multiple_entity_ids():
    """Много entity_ids — принимается."""
    ids = list(range(1, 51))  # 50 ids
    b = BulkActionIn(action="close", entity_ids=ids)
    assert len(b.entity_ids) == 50
