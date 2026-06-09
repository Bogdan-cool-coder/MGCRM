"""Личный кабинет (/me) — unit-тесты KPI-формул.

Pure-function, без БД-fixture'ов (asyncio_mode=auto). Покрывают:
- score_pct           — «Выполнение МК»
- team_avg_pct        — средний % по команде
- team_rank           — ранг в команде (competition ranking)
- parse_period        — нормализация ?period= / ?month=
- to_float            — Decimal|None → float|None
- Out-схемы /me (DashboardOut, MetricsOut, SubordinateOut, ActivityFeedOut)
- регистрация эндпоинтов /me/dashboard, /me/metrics, /me/activities, /me/subordinates
"""
from __future__ import annotations

from datetime import date
from decimal import Decimal

from app.routers.me import (
    ActivityFeedOut,
    DashboardOut,
    MetricsOut,
    SubordinateOut,
    router as me_router,
)
from app.services.me_kpi import (
    parse_period,
    score_pct,
    team_avg_pct,
    team_rank,
    to_float,
)


# ============ score_pct («Выполнение МК») ============


def test_score_pct_basic():
    assert score_pct(Decimal("80"), Decimal("100")) == 80


def test_score_pct_rounds_half_up():
    # 125/100*... → 12.5/10 проверим округление: 5/8*100 = 62.5 → 63
    assert score_pct(Decimal("5"), Decimal("8")) == 63


def test_score_pct_over_100():
    assert score_pct(Decimal("150"), Decimal("100")) == 150


def test_score_pct_zero_plan_with_fact():
    """План 0 (или None), но факт есть → 100% (нечего не выполнять, но факт есть)."""
    assert score_pct(Decimal("50"), Decimal("0")) == 100
    assert score_pct(Decimal("50"), None) == 100


def test_score_pct_zero_plan_no_fact():
    assert score_pct(Decimal("0"), Decimal("0")) == 0
    assert score_pct(None, None) == 0


def test_score_pct_never_negative():
    assert score_pct(Decimal("-10"), Decimal("100")) == 0


def test_score_pct_accepts_float():
    assert score_pct(80.0, 100.0) == 80


# ============ team_avg_pct ============


def test_team_avg_pct_mean():
    assert team_avg_pct([80, 100, 60]) == 80


def test_team_avg_pct_rounds():
    assert team_avg_pct([50, 51]) == 50  # 50.5 → round-half-to-even = 50
    assert team_avg_pct([50, 52]) == 51


def test_team_avg_pct_empty():
    assert team_avg_pct([]) == 0


def test_team_avg_pct_single():
    assert team_avg_pct([73]) == 73


# ============ team_rank (competition ranking, desc) ============


def test_team_rank_top():
    assert team_rank(100, [100, 80, 60]) == 1


def test_team_rank_middle():
    assert team_rank(80, [100, 80, 60]) == 2


def test_team_rank_last():
    assert team_rank(60, [100, 80, 60]) == 3


def test_team_rank_ties_share_rank():
    """Ничья → одинаковый ранг (двое со 100 → оба rank 1, следующий rank 3)."""
    assert team_rank(100, [100, 100, 60]) == 1
    assert team_rank(60, [100, 100, 60]) == 3


def test_team_rank_alone():
    assert team_rank(0, [0]) == 1
    assert team_rank(50, []) == 1


# ============ parse_period ============


_TODAY = date(2026, 6, 15)


def test_parse_period_default_is_current_month():
    pr = parse_period(None, today=_TODAY)
    assert (pr.year, pr.month) == (2026, 6)
    assert pr.start == date(2026, 6, 1)
    assert pr.end == date(2026, 6, 30)


def test_parse_period_current_month_alias():
    pr = parse_period("current_month", today=_TODAY)
    assert (pr.year, pr.month) == (2026, 6)


def test_parse_period_last_month():
    pr = parse_period("last_month", today=_TODAY)
    assert (pr.year, pr.month) == (2026, 5)
    assert pr.start == date(2026, 5, 1)
    assert pr.end == date(2026, 5, 31)


def test_parse_period_last_month_year_rollover():
    pr = parse_period("last_month", today=date(2026, 1, 10))
    assert (pr.year, pr.month) == (2025, 12)


def test_parse_period_explicit_month():
    pr = parse_period("2026-03", today=_TODAY)
    assert (pr.year, pr.month) == (2026, 3)
    assert pr.start == date(2026, 3, 1)
    assert pr.end == date(2026, 3, 31)


def test_parse_period_quarter():
    pr = parse_period("current_quarter", today=_TODAY)  # Q2 2026
    assert pr.start == date(2026, 4, 1)
    assert pr.end == date(2026, 6, 30)
    assert (pr.year, pr.month) == (2026, 6)  # MK берём за последний месяц квартала


def test_parse_period_year():
    pr = parse_period("current_year", today=_TODAY)
    assert pr.start == date(2026, 1, 1)
    assert pr.end == date(2026, 12, 31)
    assert (pr.year, pr.month) == (2026, 12)


def test_parse_period_invalid_raises():
    import pytest

    with pytest.raises(ValueError):
        parse_period("2026-13", today=_TODAY)
    with pytest.raises(ValueError):
        parse_period("garbage", today=_TODAY)


# ============ to_float ============


def test_to_float_decimal():
    assert to_float(Decimal("12.50")) == 12.5
    assert isinstance(to_float(Decimal("1")), float)


def test_to_float_none():
    assert to_float(None) is None


# ============ Out-схемы соответствуют types.ts ============


def test_dashboard_out_matches_frontend_shape():
    d = DashboardOut(
        personal_income_fact=100.0,
        personal_income_plan=200.0,
        team_income_fact=500.0,
        team_income_plan=1000.0,
        ftm_count_fact=3,
        ftm_count_plan=5,
        score_pct=50,
        personal_income_currency="KZT",
    )
    assert d.personal_income_fact == 100.0
    assert d.score_pct == 50
    assert d.active_deals == []
    assert d.today_tasks == []


def test_dashboard_out_all_nullable_default_none():
    d = DashboardOut()
    assert d.personal_income_fact is None
    assert d.score_pct is None
    assert d.active_deals == []


def test_metrics_out_team_comparison_fields():
    m = MetricsOut(
        personal_pct=80,
        team_avg_pct=65,
        team_rank=2,
        team_size=4,
    )
    assert m.personal_pct == 80
    assert m.team_avg_pct == 65
    assert m.team_rank == 2
    assert m.team_size == 4
    assert m.sales_by_day is None or m.sales_by_day == []


def test_subordinate_out_shape():
    s = SubordinateOut(
        id=5,
        full_name="Иван",
        department_name="ОП",
        personal_income_plan=1000.0,
        personal_income_fact=800.0,
        personal_income_currency="RUB",
        personal_income_pct=80,
        ftm_count_fact=2,
        ftm_count_plan=4,
    )
    assert s.personal_income_pct == 80
    assert s.ftm_count_fact == 2


def test_subordinate_out_defaults():
    s = SubordinateOut(id=1, full_name="X")
    assert s.personal_income_plan == 0.0
    assert s.personal_income_pct == 0
    assert s.personal_income_currency == "RUB"


def test_activity_feed_out_shape():
    a = ActivityFeedOut(
        id=10,
        kind="meeting",
        title="Встреча",
        created_at="2026-06-15T10:00:00+00:00",
        is_first_time_meeting=True,
        ftm_counted=True,
    )
    assert a.kind == "meeting"
    assert a.ftm_counted is True
    assert a.body is None


# ============ Регистрация эндпоинтов ============


def _paths() -> set[str]:
    return {
        r.path for r in me_router.routes
        if hasattr(r, "path")
    }


def test_me_endpoints_registered():
    paths = _paths()
    assert "/me/dashboard" in paths
    assert "/me/metrics" in paths
    assert "/me/activities" in paths
    assert "/me/subordinates" in paths
