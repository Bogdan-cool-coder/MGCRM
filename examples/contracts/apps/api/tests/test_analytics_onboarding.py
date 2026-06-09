"""Тесты чистых функций аналитики онбординга (Эпик 17).

Все тесты — pure-function, без БД fixture'ов.
asyncio_mode="auto" наследуется из pytest.ini / pyproject.toml.
"""
from __future__ import annotations

from datetime import UTC, datetime, timedelta, date

import pytest

from app.services.analytics_onboarding import (
    aggregate_team_progress,
    classify_funnel_step,
    compute_avg_completion_hours,
    compute_completion_rate,
    compute_funnel_steps,
    fill_daily_gaps_dict,
    fill_daily_gaps_int,
    sort_hard_questions,
    build_hard_questions_xlsx,
    build_overview_xlsx,
    build_team_progress_xlsx,
)


# ============ compute_completion_rate ============

def test_completion_rate_normal():
    assert compute_completion_rate(100, 42) == 42.0


def test_completion_rate_zero_assignments():
    assert compute_completion_rate(0, 0) == 0.0


def test_completion_rate_full():
    assert compute_completion_rate(10, 10) == 100.0


def test_completion_rate_rounds_to_one_decimal():
    # 7/3 = 233.333... → неприменимо, но 1/3 = 33.333… → 33.3
    assert compute_completion_rate(3, 1) == 33.3


# ============ compute_avg_completion_hours ============

def test_avg_completion_hours_normal():
    now = datetime(2026, 6, 1, 12, 0, 0, tzinfo=UTC)
    pairs = [
        (now - timedelta(hours=4), now),
        (now - timedelta(hours=8), now),
    ]
    assert compute_avg_completion_hours(pairs) == 6.0


def test_avg_completion_hours_empty():
    assert compute_avg_completion_hours([]) == 0.0


def test_avg_completion_hours_skips_none():
    now = datetime(2026, 6, 1, tzinfo=UTC)
    assert compute_avg_completion_hours([(None, now), (now, None)]) == 0.0


def test_avg_completion_hours_skips_negative():
    # b < a → пропускаем (некорректные данные)
    now = datetime(2026, 6, 1, tzinfo=UTC)
    pairs = [(now, now - timedelta(hours=1))]
    assert compute_avg_completion_hours(pairs) == 0.0


# ============ fill_daily_gaps ============

def test_fill_daily_gaps_int_no_gaps():
    start = date(2026, 6, 1)
    rows = [(date(2026, 6, 1), 5), (date(2026, 6, 2), 3)]
    result = fill_daily_gaps_int(rows, start, date(2026, 6, 2))
    assert result == [5, 3]


def test_fill_daily_gaps_int_with_gaps():
    start = date(2026, 6, 1)
    rows = [(date(2026, 6, 3), 7)]
    result = fill_daily_gaps_int(rows, start, date(2026, 6, 3))
    assert result == [0, 0, 7]


def test_fill_daily_gaps_int_empty():
    result = fill_daily_gaps_int([], date(2026, 6, 1), date(2026, 6, 3))
    assert result == [0, 0, 0]


def test_fill_daily_gaps_dict_structure():
    rows = [(date(2026, 6, 2), 4)]
    result = fill_daily_gaps_dict(rows, date(2026, 6, 1), date(2026, 6, 2))
    assert len(result) == 2
    assert result[0] == {"date": "2026-06-01", "count": 0}
    assert result[1] == {"date": "2026-06-02", "count": 4}


# ============ sort_hard_questions ============

def test_sort_hard_questions_orders_by_success_rate_asc():
    rows = [
        {"question_id": 1, "text": "Q1", "course_id": 1, "course_name": "C", "lesson_id": 1, "lesson_name": "L",
         "total_attempts": 10, "correct_attempts": 7},
        {"question_id": 2, "text": "Q2", "course_id": 1, "course_name": "C", "lesson_id": 1, "lesson_name": "L",
         "total_attempts": 10, "correct_attempts": 2},
        {"question_id": 3, "text": "Q3", "course_id": 1, "course_name": "C", "lesson_id": 1, "lesson_name": "L",
         "total_attempts": 10, "correct_attempts": 5},
    ]
    result = sort_hard_questions(rows, limit=5)
    # Q2 (20%) < Q3 (50%) < Q1 (70%)
    assert result[0]["question_id"] == 2
    assert result[1]["question_id"] == 3
    assert result[2]["question_id"] == 1


def test_sort_hard_questions_filters_low_attempts():
    rows = [
        {"question_id": 1, "text": "Q1", "course_id": 1, "course_name": "C", "lesson_id": 1, "lesson_name": "L",
         "total_attempts": 4, "correct_attempts": 0},  # < 5 → исключён
        {"question_id": 2, "text": "Q2", "course_id": 1, "course_name": "C", "lesson_id": 1, "lesson_name": "L",
         "total_attempts": 5, "correct_attempts": 1},
    ]
    result = sort_hard_questions(rows, limit=5)
    assert len(result) == 1
    assert result[0]["question_id"] == 2


def test_sort_hard_questions_limit():
    rows = [
        {"question_id": i, "text": f"Q{i}", "course_id": 1, "course_name": "C", "lesson_id": 1, "lesson_name": "L",
         "total_attempts": 10, "correct_attempts": i}
        for i in range(1, 11)
    ]
    result = sort_hard_questions(rows, limit=3)
    assert len(result) == 3


def test_sort_hard_questions_computes_success_rate():
    rows = [
        {"question_id": 1, "text": "Q", "course_id": 1, "course_name": "C", "lesson_id": 1, "lesson_name": "L",
         "total_attempts": 8, "correct_attempts": 6},
    ]
    result = sort_hard_questions(rows, limit=5)
    assert result[0]["success_rate_pct"] == 75.0


def test_sort_hard_questions_empty():
    assert sort_hard_questions([], limit=5) == []


# ============ compute_funnel_steps ============

def test_funnel_steps_empty_assignments():
    steps = compute_funnel_steps([])
    assert len(steps) == 5
    for s in steps:
        assert s["count"] == 0
        assert s["pct_of_total"] == 0.0


def test_funnel_steps_all_assigned_only():
    assignments = [
        {"user_id": i, "percent": 0, "status": "not_started"}
        for i in range(1, 11)
    ]
    steps = compute_funnel_steps(assignments)
    step_map = {s["step_key"]: s for s in steps}
    assert step_map["assigned"]["count"] == 10
    assert step_map["started"]["count"] == 0
    assert step_map["completed"]["count"] == 0


def test_funnel_steps_mixed():
    assignments = [
        {"user_id": 1, "percent": 0, "status": "not_started"},
        {"user_id": 2, "percent": 30, "status": "in_progress"},
        {"user_id": 3, "percent": 60, "status": "in_progress"},
        {"user_id": 4, "percent": 92, "status": "in_progress"},
        {"user_id": 5, "percent": 100, "status": "completed"},
    ]
    steps = compute_funnel_steps(assignments)
    step_map = {s["step_key"]: s for s in steps}
    assert step_map["assigned"]["count"] == 5
    assert step_map["started"]["count"] == 4   # percent > 0
    assert step_map["half_done"]["count"] == 3  # percent >= 50 (60, 92, 100)
    assert step_map["near_done"]["count"] == 2  # percent >= 90 (92, 100)
    assert step_map["completed"]["count"] == 1


def test_funnel_steps_pct_calculation():
    assignments = [
        {"user_id": i, "percent": 100, "status": "completed"}
        for i in range(1, 5)  # 4 из 4
    ]
    steps = compute_funnel_steps(assignments)
    step_map = {s["step_key"]: s for s in steps}
    assert step_map["assigned"]["pct_of_total"] == 100.0
    assert step_map["completed"]["pct_of_total"] == 100.0


# ============ classify_funnel_step ============

def test_classify_funnel_step_completed():
    assert classify_funnel_step(100, "completed") == "completed"
    assert classify_funnel_step(50, "completed") == "completed"


def test_classify_funnel_step_near_done():
    assert classify_funnel_step(90, "in_progress") == "near_done"
    assert classify_funnel_step(95, "in_progress") == "near_done"


def test_classify_funnel_step_half_done():
    assert classify_funnel_step(50, "in_progress") == "half_done"
    assert classify_funnel_step(75, "in_progress") == "half_done"


def test_classify_funnel_step_started():
    assert classify_funnel_step(10, "in_progress") == "started"
    assert classify_funnel_step(1, "not_started") == "started"


def test_classify_funnel_step_assigned():
    assert classify_funnel_step(0, "not_started") == "assigned"
    assert classify_funnel_step(0, "overdue") == "assigned"


# ============ aggregate_team_progress ============

def test_aggregate_team_progress_single_user():
    now = datetime(2026, 6, 1, tzinfo=UTC)
    rows = [
        {"user_id": 1, "full_name": "Иван", "email": "i@test.com", "department_name": "ОП",
         "status": "completed", "percent": 100, "last_activity_at": now},
        {"user_id": 1, "full_name": "Иван", "email": "i@test.com", "department_name": "ОП",
         "status": "in_progress", "percent": 50, "last_activity_at": None},
    ]
    result = aggregate_team_progress(rows)
    assert len(result) == 1
    r = result[0]
    assert r["user_id"] == 1
    assert r["assignments_total"] == 2
    assert r["completed_count"] == 1
    assert r["in_progress_count"] == 1
    assert r["overdue_count"] == 0
    assert r["progress_pct"] == 75.0
    assert r["last_activity_at"] == now


def test_aggregate_team_progress_multiple_users():
    rows = [
        {"user_id": 1, "full_name": "Иван", "email": "i@test.com", "department_name": None,
         "status": "completed", "percent": 100, "last_activity_at": None},
        {"user_id": 2, "full_name": "Анна", "email": "a@test.com", "department_name": "ОП",
         "status": "overdue", "percent": 0, "last_activity_at": None},
    ]
    result = aggregate_team_progress(rows)
    assert len(result) == 2
    ids = {r["user_id"] for r in result}
    assert ids == {1, 2}


def test_aggregate_team_progress_empty():
    assert aggregate_team_progress([]) == []


# ============ Excel builders (smoke tests) ============

def test_build_overview_xlsx_is_valid_zip():
    content = build_overview_xlsx(
        totals={
            "courses_active": 5, "assignments_total": 100, "completions_total": 42,
            "completion_rate_pct": 42.0, "avg_completion_hours": 3.5,
            "active_learners_30d": 20, "overdue_mandatory": 2, "assignments_new_30d": 15,
        },
        sparkline=list(range(30)),
        course_completions=[{"title": "Курс 1", "completed": 10}, {"title": "Курс 2", "completed": 5}],
    )
    assert content[:2] == b"PK"  # .xlsx — zip
    assert len(content) > 500


def test_build_team_progress_xlsx_is_valid_zip():
    rows = [
        {"full_name": "Иван", "email": "i@test.com", "department_name": "ОП",
         "assignments_total": 3, "completed_count": 2, "in_progress_count": 1,
         "overdue_count": 0, "progress_pct": 66.7, "last_activity_at": None},
    ]
    content = build_team_progress_xlsx(rows)
    assert content[:2] == b"PK"
    assert len(content) > 300


def test_build_hard_questions_xlsx_is_valid_zip():
    rows = [
        {"text": "Вопрос 1", "course_name": "Курс", "lesson_name": "Урок",
         "total_attempts": 20, "correct_attempts": 5, "success_rate_pct": 25.0},
    ]
    content = build_hard_questions_xlsx(rows)
    assert content[:2] == b"PK"
    assert len(content) > 300


def test_build_overview_xlsx_empty_sparkline():
    """Пустой sparkline не роняет builder."""
    content = build_overview_xlsx(
        totals={
            "courses_active": 0, "assignments_total": 0, "completions_total": 0,
            "completion_rate_pct": 0.0, "avg_completion_hours": 0.0,
            "active_learners_30d": 0, "overdue_mandatory": 0, "assignments_new_30d": 0,
        },
        sparkline=[],
        course_completions=[],
    )
    assert content[:2] == b"PK"
