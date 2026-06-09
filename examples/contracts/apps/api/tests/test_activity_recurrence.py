"""Эпик 24 — Tasks v2: тесты повторяющихся задач.

Pure-function тесты (без БД). Покрываем:
- next_due_at: daily/weekly/monthly расчёты
- _add_one_month: граничные случаи (конец месяца, 31→28/30)
- should_create_next_instance: logic trigger
- RECURRENCE_RULES whitelist
"""
from __future__ import annotations

from datetime import date, datetime, timezone

import pytest

from app.services.task_recurrence import (
    RECURRENCE_RULES,
    _add_one_month,
    next_due_at,
    should_create_next_instance,
)


# ============ RECURRENCE_RULES whitelist ============


def test_recurrence_rules_whitelist():
    """Только daily/weekly/monthly поддерживаются."""
    assert RECURRENCE_RULES == frozenset({"daily", "weekly", "monthly"})


def test_next_due_at_unknown_rule_raises():
    """Неизвестное правило — ValueError."""
    with pytest.raises(ValueError, match="Unknown recurrence rule"):
        next_due_at(date(2026, 1, 1), "hourly")


# ============ next_due_at: daily ============


def test_next_due_at_daily_simple():
    """daily: +1 день."""
    d = date(2026, 6, 1)
    assert next_due_at(d, "daily") == date(2026, 6, 2)


def test_next_due_at_daily_month_boundary():
    """daily: переход через конец месяца."""
    d = date(2026, 6, 30)
    assert next_due_at(d, "daily") == date(2026, 7, 1)


def test_next_due_at_daily_year_boundary():
    """daily: переход через конец года."""
    d = date(2025, 12, 31)
    assert next_due_at(d, "daily") == date(2026, 1, 1)


# ============ next_due_at: weekly ============


def test_next_due_at_weekly_simple():
    """weekly: +7 дней."""
    d = date(2026, 6, 1)
    assert next_due_at(d, "weekly") == date(2026, 6, 8)


def test_next_due_at_weekly_month_boundary():
    """weekly: переход через конец месяца."""
    d = date(2026, 6, 28)
    assert next_due_at(d, "weekly") == date(2026, 7, 5)


def test_next_due_at_weekly_year_boundary():
    """weekly: переход через конец года."""
    d = date(2025, 12, 28)
    assert next_due_at(d, "weekly") == date(2026, 1, 4)


# ============ next_due_at: monthly ============


def test_next_due_at_monthly_simple():
    """monthly: +1 месяц, обычный день."""
    d = date(2026, 1, 15)
    assert next_due_at(d, "monthly") == date(2026, 2, 15)


def test_next_due_at_monthly_year_boundary():
    """monthly: декабрь → январь следующего года."""
    d = date(2025, 12, 10)
    assert next_due_at(d, "monthly") == date(2026, 1, 10)


def test_next_due_at_monthly_31_to_feb():
    """monthly: 31 января → 28 февраля (клэмп на последний день)."""
    d = date(2026, 1, 31)
    assert next_due_at(d, "monthly") == date(2026, 2, 28)


def test_next_due_at_monthly_31_to_feb_leap():
    """monthly: 31 января → 29 февраля в високосном году."""
    d = date(2024, 1, 31)
    result = next_due_at(d, "monthly")
    assert result == date(2024, 2, 29)


def test_next_due_at_monthly_30_to_feb():
    """monthly: 30 ноября → 31 декабря (нет клэмпа, декабрь 31 день)."""
    d = date(2026, 11, 30)
    result = next_due_at(d, "monthly")
    assert result == date(2026, 12, 30)


def test_next_due_at_monthly_31_to_april():
    """monthly: 31 марта → 30 апреля (клэмп)."""
    d = date(2026, 3, 31)
    assert next_due_at(d, "monthly") == date(2026, 4, 30)


def test_next_due_at_monthly_first_day():
    """monthly: первое число — без клэмпа."""
    d = date(2026, 1, 1)
    assert next_due_at(d, "monthly") == date(2026, 2, 1)


# ============ _add_one_month: прямые тесты ============


def test_add_one_month_june_to_july():
    """Июнь → июль."""
    assert _add_one_month(date(2026, 6, 15)) == date(2026, 7, 15)


def test_add_one_month_november_to_december():
    """Ноябрь → декабрь."""
    assert _add_one_month(date(2026, 11, 10)) == date(2026, 12, 10)


def test_add_one_month_december_to_january():
    """Декабрь → январь следующего года."""
    assert _add_one_month(date(2026, 12, 5)) == date(2027, 1, 5)


def test_add_one_month_clamp_to_last_day():
    """Клэмп: 30 января → 28 февраля (не 30 февраля)."""
    assert _add_one_month(date(2025, 1, 30)) == date(2025, 2, 28)


# ============ should_create_next_instance ============


def _make_template(rule: str, due_date: date, until: date | None = None) -> object:
    """Создаёт mock-объект Activity с нужными полями."""
    class MockActivity:
        recurrence_rule = rule
        due_at = datetime.combine(due_date, datetime.min.time()).replace(tzinfo=timezone.utc)
        recurrence_until = until
        is_closed = False
        recurrence_parent_id = None
        id = 1

    return MockActivity()


def test_should_create_due_today():
    """Создать если today >= next_due."""
    template = _make_template("daily", date(2026, 5, 31))
    assert should_create_next_instance(template, today=date(2026, 6, 1)) is True


def test_should_not_create_before_due():
    """Не создавать если today < next_due."""
    template = _make_template("weekly", date(2026, 6, 1))
    # next_due = 2026-06-08; today = 2026-06-02 → нет
    assert should_create_next_instance(template, today=date(2026, 6, 2)) is False


def test_should_not_create_after_until():
    """Не создавать если today > recurrence_until."""
    template = _make_template("daily", date(2026, 6, 1), until=date(2026, 6, 3))
    # today > until
    assert should_create_next_instance(template, today=date(2026, 6, 10)) is False


def test_should_create_on_until_date():
    """На дату until — пограничный случай. today > until → False."""
    template = _make_template("daily", date(2026, 6, 1), until=date(2026, 6, 2))
    # today = until, today > until → False
    assert should_create_next_instance(template, today=date(2026, 6, 3)) is False


def test_should_not_create_without_rule():
    """Без recurrence_rule — не создавать."""
    class MockActivity:
        recurrence_rule = None
        due_at = datetime(2026, 6, 1, tzinfo=timezone.utc)
        recurrence_until = None
        is_closed = False
        recurrence_parent_id = None
        id = 1

    assert should_create_next_instance(MockActivity(), today=date(2026, 6, 2)) is False


def test_should_not_create_without_due_at():
    """Без due_at — не создавать."""
    class MockActivity:
        recurrence_rule = "daily"
        due_at = None
        recurrence_until = None
        is_closed = False
        recurrence_parent_id = None
        id = 1

    assert should_create_next_instance(MockActivity(), today=date(2026, 6, 2)) is False
