"""POST-AUDIT #7 — date_field-эскалация (2-й уровень SLA).

Pure-function проверки (без DB fixture) для нового
`run_date_field_escalation_scanner` и его чистых хелперов:
- date_field_base_breach_ts — момент первичного breach = midnight(field_date) − days;
- date_field_escalation_event_ts — стабильный per-level event_ts (breach + after_hours);
- стабильность event_ts → дедуп через ux_automation_runs_idem (повторный тик/scale=2);
- after_hours входит в offset → разные уровни не конфликтуют в idem-индексе;
- порядок уровней по возрастанию after_hours;
- _safe_after_hours на мусоре.

End-to-end путь сканера (SQL func.date-фильтр, _execute_escalation_step,
ON CONFLICT-дедуп) требует Postgres и здесь НЕ покрыт — это интеграционный прогон
(как и для idle-эскалации). Здесь покрыта вся чистая арифметика breach-окна, от
которой зависит дедуп.
"""
from __future__ import annotations

from datetime import UTC, date, datetime, timedelta

from app.services.automation_executor import (
    _safe_after_hours,
    date_field_base_breach_ts,
    date_field_escalation_event_ts,
    floor_to_hour,
)


# ============ base breach = midnight(field_date) − days ============


def test_base_breach_matches_legacy_date_field_formula():
    """date_field_base_breach_ts повторяет ту же арифметику, что и базовый
    date_field-сканер: (значение поля − days), нормализованное к началу дня."""
    field_date = date(2026, 7, 1)
    days = 7
    legacy = datetime(
        field_date.year, field_date.month, field_date.day, tzinfo=UTC
    ) - timedelta(days=days)
    assert date_field_base_breach_ts(field_date, days) == legacy
    assert date_field_base_breach_ts(field_date, days) == datetime(
        2026, 6, 24, tzinfo=UTC
    )


def test_base_breach_is_midnight_utc_aware():
    bt = date_field_base_breach_ts(date(2026, 1, 15), 0)
    assert bt == datetime(2026, 1, 15, tzinfo=UTC)
    assert bt.hour == 0 and bt.minute == 0 and bt.second == 0
    assert bt.tzinfo is not None


def test_base_breach_clamps_negative_days():
    """days < 0 нормализуется к 0 (как в сканерах)."""
    assert date_field_base_breach_ts(date(2026, 3, 10), -5) == datetime(
        2026, 3, 10, tzinfo=UTC
    )


# ============ escalation event_ts = breach + after_hours ============


def test_escalation_event_ts_adds_after_hours_to_breach():
    """Формула: floor_to_hour((field_date − days) + after_hours)."""
    field_date = date(2026, 7, 1)
    days = 7  # base breach = 2026-06-24 00:00 UTC
    ev = date_field_escalation_event_ts(field_date, days, after_hours=48)
    assert ev == datetime(2026, 6, 26, 0, 0, tzinfo=UTC)  # +48h


def test_escalation_event_ts_equals_floor_of_breach_plus_offset():
    field_date = date(2026, 5, 20)
    days = 3
    after_hours = 30
    base = date_field_base_breach_ts(field_date, days)
    assert date_field_escalation_event_ts(
        field_date, days, after_hours
    ) == floor_to_hour(base + timedelta(hours=after_hours))


# ============ стабильность → дедуп (ux_automation_runs_idem) ============


def test_escalation_event_ts_stable_across_ticks():
    """Один уровень для одной цели → один event_ts вне зависимости от момента
    cron-тика (зависит только от field_date/days/after_hours, не от now).
    Это и есть гарантия дедупа на повторном тике и на scale=2."""
    field_date = date(2026, 9, 1)
    ev1 = date_field_escalation_event_ts(field_date, 14, 24)
    ev2 = date_field_escalation_event_ts(field_date, 14, 24)
    assert ev1 == ev2


def test_escalation_levels_have_distinct_event_ts():
    """after_hours входит в offset → разные уровни эскалации дают РАЗНЫЕ event_ts,
    поэтому не конфликтуют в ux_automation_runs_idem (каждый уровень — свой слот)."""
    field_date = date(2026, 9, 1)
    days = 14
    ev24 = date_field_escalation_event_ts(field_date, days, 24)
    ev48 = date_field_escalation_event_ts(field_date, days, 48)
    ev72 = date_field_escalation_event_ts(field_date, days, 72)
    assert len({ev24, ev48, ev72}) == 3
    assert ev24 < ev48 < ev72


def test_escalation_event_ts_differs_from_base_breach():
    """Уровень эскалации не совпадает с первичным date_field-breach (иначе
    конфликт в idem с базовым действием). После offset > 0 — всегда позже base."""
    field_date = date(2026, 9, 1)
    days = 10
    base = date_field_base_breach_ts(field_date, days)
    assert date_field_escalation_event_ts(field_date, days, 24) > base


def test_escalation_event_ts_new_field_date_new_slot():
    """Если дату поля сдвинули — новый event_ts (новый эпизод, заново исполнится)."""
    days, after = 7, 24
    ev_a = date_field_escalation_event_ts(date(2026, 7, 1), days, after)
    ev_b = date_field_escalation_event_ts(date(2026, 7, 10), days, after)
    assert ev_a != ev_b


# ============ порядок уровней + _safe_after_hours на мусоре ============


def test_levels_sorted_by_after_hours_ascending():
    """Сканер исполняет уровни по возрастанию after_hours (детерминизм)."""
    chain = [
        {"after_hours": 72, "action_kind": "tg_notify"},
        {"after_hours": 24, "action_kind": "tg_notify"},
        {"after_hours": 48, "action_kind": "tg_notify"},
    ]
    ordered = sorted(
        (e for e in chain if isinstance(e, dict)),
        key=lambda e: _safe_after_hours(e.get("after_hours")),
    )
    assert [e["after_hours"] for e in ordered] == [24, 48, 72]


def test_safe_after_hours_on_garbage():
    """Мусорный after_hours → 0 (уровень со значением ≤ 0 сканер пропускает)."""
    assert _safe_after_hours(None) == 0
    assert _safe_after_hours("oops") == 0
    assert _safe_after_hours(-10) == 0
    assert _safe_after_hours({}) == 0
    assert _safe_after_hours([]) == 0
    assert _safe_after_hours("48") == 48
    assert _safe_after_hours(72) == 72


def test_escalation_event_ts_handles_garbage_after_hours_as_zero():
    """date_field_escalation_event_ts сам нормализует after_hours через
    _safe_after_hours → мусор трактуется как 0 (event_ts == base breach)."""
    field_date = date(2026, 7, 1)
    days = 7
    base = date_field_base_breach_ts(field_date, days)
    assert date_field_escalation_event_ts(field_date, days, "oops") == base  # type: ignore[arg-type]
    assert date_field_escalation_event_ts(field_date, days, -5) == base
