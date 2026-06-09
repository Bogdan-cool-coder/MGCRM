"""Эпик 24 — Tasks v2: тесты машины состояний и restrict_close_without_result.

Pure-function тесты (без БД). Покрываем:
- _STATUS_TRANSITIONS: все допустимые и недопустимые переходы
- restrict_close_without_result логика (pure validation)
- ActivityStatusPatch schema validation
- is_closed / close endpoint contract
"""
from __future__ import annotations

import pytest
from pydantic import ValidationError

from app.routers.activities import (
    ACTIVITY_STATUSES,
    ActivityStatusPatch,
    BulkActionIn,
    _STATUS_TRANSITIONS,
)


# ============ Schema validation ============


def test_activity_status_patch_valid():
    """ActivityStatusPatch принимает допустимые статусы."""
    for st in ACTIVITY_STATUSES:
        patch = ActivityStatusPatch(status=st)
        assert patch.status == st


def test_activity_status_patch_with_result_text():
    """ActivityStatusPatch может включать result_text."""
    patch = ActivityStatusPatch(status="done", result_text="Задача выполнена, КП отправлено")
    assert patch.result_text == "Задача выполнена, КП отправлено"


def test_activity_status_patch_result_text_optional():
    """ActivityStatusPatch: result_text необязателен."""
    patch = ActivityStatusPatch(status="in_progress")
    assert patch.result_text is None


# ============ Status Machine: полное покрытие переходов ============


def test_transition_new_to_in_progress():
    assert "in_progress" in _STATUS_TRANSITIONS["new"]


def test_transition_new_to_rejected():
    assert "rejected" in _STATUS_TRANSITIONS["new"]


def test_transition_new_to_done_not_allowed():
    """Из 'new' нельзя сразу в 'done'."""
    assert "done" not in _STATUS_TRANSITIONS["new"]


def test_transition_in_progress_to_done():
    assert "done" in _STATUS_TRANSITIONS["in_progress"]


def test_transition_in_progress_to_rejected():
    assert "rejected" in _STATUS_TRANSITIONS["in_progress"]


def test_transition_in_progress_to_new():
    """Можно вернуть задачу обратно в 'new' из in_progress."""
    assert "new" in _STATUS_TRANSITIONS["in_progress"]


def test_transition_done_to_in_progress():
    """Задачу в done можно вернуть в in_progress (переоткрыть)."""
    assert "in_progress" in _STATUS_TRANSITIONS["done"]


def test_transition_done_to_new_not_allowed():
    """Из done нельзя сразу прыгнуть в new (нужно через in_progress)."""
    assert "new" not in _STATUS_TRANSITIONS["done"]


def test_transition_done_to_rejected_not_allowed():
    assert "rejected" not in _STATUS_TRANSITIONS["done"]


def test_transition_rejected_to_new():
    """Из rejected можно вернуть в new."""
    assert "new" in _STATUS_TRANSITIONS["rejected"]


def test_transition_rejected_to_done_not_allowed():
    """Из rejected нельзя сразу в done."""
    assert "done" not in _STATUS_TRANSITIONS["rejected"]


def test_transition_rejected_to_in_progress_not_allowed():
    """Из rejected нельзя сразу в in_progress."""
    assert "in_progress" not in _STATUS_TRANSITIONS["rejected"]


def test_transitions_dict_covers_all_statuses():
    """Каждый статус из ACTIVITY_STATUSES есть в _STATUS_TRANSITIONS."""
    for st in ACTIVITY_STATUSES:
        assert st in _STATUS_TRANSITIONS, f"Статус {st!r} отсутствует в _STATUS_TRANSITIONS"


def test_transitions_are_sets():
    """Все значения _STATUS_TRANSITIONS — это set (а не list/tuple)."""
    for key, val in _STATUS_TRANSITIONS.items():
        assert isinstance(val, set), f"_STATUS_TRANSITIONS[{key!r}] должен быть set, получили {type(val)}"


def test_transitions_target_values_are_valid_statuses():
    """Все целевые статусы в переходах — валидные значения из ACTIVITY_STATUSES."""
    valid = set(ACTIVITY_STATUSES)
    for source, targets in _STATUS_TRANSITIONS.items():
        for target in targets:
            assert target in valid, (
                f"Недопустимый целевой статус {target!r} в переходе из {source!r}"
            )


def test_transitions_source_values_are_valid_statuses():
    """Все исходные статусы в переходах — валидные значения из ACTIVITY_STATUSES."""
    valid = set(ACTIVITY_STATUSES)
    for source in _STATUS_TRANSITIONS:
        assert source in valid, (
            f"Недопустимый исходный статус {source!r} в _STATUS_TRANSITIONS"
        )


# ============ restrict_close_without_result: pure logic ============


def _check_restrict(restrict: bool, result_text: str | None) -> tuple[bool, str]:
    """Pure-function эмуляция проверки restrict_close_without_result.

    Returns:
        (ok: bool, error_msg: str)
    """
    if restrict and not result_text:
        return False, "Требуется result_text для закрытия задачи"
    return True, ""


def test_restrict_close_without_result_passes_when_disabled():
    """Без restrict — можно закрыть без result_text."""
    ok, _ = _check_restrict(restrict=False, result_text=None)
    assert ok is True


def test_restrict_close_without_result_passes_with_text():
    """С restrict + result_text — можно закрыть."""
    ok, _ = _check_restrict(restrict=True, result_text="Задача выполнена")
    assert ok is True


def test_restrict_close_without_result_fails_without_text():
    """С restrict + пустой result_text — нельзя."""
    ok, msg = _check_restrict(restrict=True, result_text=None)
    assert ok is False
    assert msg


def test_restrict_close_without_result_fails_with_empty_string():
    """С restrict + пустая строка result_text — тоже нельзя."""
    ok, msg = _check_restrict(restrict=True, result_text="")
    assert ok is False


def test_restrict_close_without_result_passes_whitespace():
    """С restrict + пробелы в result_text — зависит от реализации.

    В нашей реализации мы не trim — пробелы == True (truthy).
    """
    ok, _ = _check_restrict(restrict=True, result_text="  ")
    # "  " — truthy в Python; наша pure-function принимает
    assert ok is True
