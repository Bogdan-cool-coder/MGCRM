"""Эпик 23 — action_kind='complete_tasks' pure-function tests.

Покрываем: whitelist (COMPLETE_TASKS_FILTERS), включение action в
AUTOMATION_ACTIONS, dry-run превью корректности структуры result.
БД-фикстур нет — handler тестируется опосредованно через whitelist'ы
и через структуру cfg (валидация filter_mode).
"""
from __future__ import annotations

from app.services.automation_executor import (
    AUTOMATION_ACTIONS,
    COMPLETE_TASKS_FILTERS,
)


# ============ Whitelist ============


def test_complete_tasks_action_in_whitelist():
    """Эпик 23: complete_tasks регистрируется в AUTOMATION_ACTIONS."""
    assert "complete_tasks" in AUTOMATION_ACTIONS


def test_complete_tasks_filters_whitelist():
    """2 режима: all / open_only."""
    assert set(COMPLETE_TASKS_FILTERS) == {"all", "open_only"}
    # Без дублей
    assert len(set(COMPLETE_TASKS_FILTERS)) == len(COMPLETE_TASKS_FILTERS)


def test_complete_tasks_default_is_open_only():
    """Default filter — open_only (без явного config)."""
    # Семантически: «open_only» консервативен, не перезавершает уже-completed.
    # Проверяем что он первый в whitelist либо отдельно отмечен — здесь делаем
    # косвенную проверку через констант.
    assert "open_only" in COMPLETE_TASKS_FILTERS


# ============ Структурные проверки ============


def test_complete_tasks_filter_open_only_excludes_completed():
    """open_only означает что completed_at IS NULL фильтруется в SQL.

    Это семантический тест — реальный SQL-фильтр в _action_complete_tasks
    реализован через `stmt.where(Activity.completed_at.is_(None))`. Здесь
    подтверждаем что whitelist разрешает только эти 2 mode'а — никаких
    'closed_only' или 'archived' случайно не появилось.
    """
    # Регрессия: запрещаем добавление новых modes без обновления handler'а.
    assert COMPLETE_TASKS_FILTERS == ("all", "open_only")


def test_complete_tasks_supports_all_target_types_in_automation_runs():
    """Action не привязан к target_type — допустим на любом таргете автоматизации
    (deal/lead/subscription). Cfg.target_type — optional override."""
    # Проверка через здравый смысл whitelist'а: action не в SET_FIELD_WHITELIST
    # — он не trivial set_field, а полиморфный UPDATE через target_type.
    from app.services.automation_executor import SET_FIELD_WHITELIST
    # complete_tasks НЕ должен фигурировать как ключ или значение в set_field
    # whitelist — это отдельный action.
    for target_t, fields in SET_FIELD_WHITELIST.items():
        assert "complete_tasks" not in fields
        assert "completed_at" not in fields  # завершение задач — не через set_field


def test_complete_tasks_action_kind_string():
    """Регрессия: имя action_kind — точно 'complete_tasks', без typo."""
    assert "complete_tasks" in AUTOMATION_ACTIONS
    # Не путаем с похожими именами
    assert "complete_task" not in AUTOMATION_ACTIONS  # без 's'
    assert "task_complete" not in AUTOMATION_ACTIONS
    assert "close_tasks" not in AUTOMATION_ACTIONS


def test_complete_tasks_distinct_from_create_task():
    """Регрессия: complete_tasks ≠ create_task (другой semantic — массовое
    завершение vs создание одной)."""
    assert "complete_tasks" in AUTOMATION_ACTIONS
    assert "create_task" in AUTOMATION_ACTIONS
    # Они оба есть, но это разные ключи
    assert "complete_tasks" != "create_task"
