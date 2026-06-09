"""Prod-hotfix tests (MARATHON-2 follow-up).

Покрывают:
1. GET /api/me/profile endpoint существует и зарегистрирован в роутере me.
2. MeProfileOut schema:
   - имеет все поля которые ждёт фронт (MeProfile из lib/types.ts);
   - поля имеют ожидаемые типы / nullable;
   - дефолты для salary_currency, theme_preference, locale (НЕ None).
3. OverviewResponse теперь содержит ПЛОСКИЕ поля, которые ждёт
   фронтенд OverviewKpiRow.tsx (data.completion_rate_pct.toFixed(...))
   и backward-compatible `.totals` для xlsx-экспорта.
4. compute_avg_completion_hours: всегда возвращает float (защита от
   None / int в numeric полях overview).

Pure-function, без БД-fixture'ов. asyncio_mode=auto.
"""
from __future__ import annotations

from datetime import UTC, date, datetime, timedelta

import pytest

from app.routers.me import MeProfileOut, router as me_router
from app.routers.users import router as users_router
from app.routers.analytics_onboarding import (
    OverviewResponse,
    OverviewTotals,
    StatusDistribution,
)
from app.services.analytics_onboarding import (
    compute_avg_completion_hours,
    compute_completion_rate,
)


# ============ /api/me/profile endpoint регистрация ============


def test_me_profile_endpoint_registered():
    """Хотфикс гарантирует что GET /profile теперь в роутере me."""
    routes = [
        (r.path, list(r.methods)) for r in me_router.routes
        if hasattr(r, "methods") and hasattr(r, "path")
    ]
    # APIRouter с prefix='/me' даёт path='/me/profile' уже на этом уровне.
    # Финальный URL = '/api/me/profile' (префикс /api добавляется в main.py).
    profile_routes = [
        (path, methods) for path, methods in routes if path == "/me/profile"
    ]
    assert profile_routes, (
        "GET /me/profile не зарегистрирован в me router — фронт упадёт на 404"
    )
    methods = profile_routes[0][1]
    assert "GET" in methods, "Endpoint /me/profile должен быть GET-only"


def test_user_profile_endpoint_registered():
    """Хотфикс: GET /users/{user_id}/profile тоже доступен (для admin/director)."""
    routes = [
        (r.path, list(r.methods)) for r in users_router.routes
        if hasattr(r, "methods") and hasattr(r, "path")
    ]
    profile_routes = [
        (path, methods) for path, methods in routes
        if path == "/users/{user_id}/profile"
    ]
    assert profile_routes, (
        "GET /users/{user_id}/profile не зарегистрирован — фронт MePageHeader "
        "сломается при просмотре чужого профиля"
    )
    assert "GET" in profile_routes[0][1]


# ============ MeProfileOut schema ============


def test_me_profile_out_required_frontend_fields():
    """Полный набор полей которые читает apps/web/.../MePageHeader.tsx.

    Если этот тест упадёт после рефакторинга — нужно синхронить
    `MeProfile` interface в `apps/web/src/lib/types.ts`.
    """
    # Минимальный валидный объект — только required-поля без defaults
    p = MeProfileOut(
        id=1,
        user_id=1,
        full_name="Иван Иванов",
        email="i@test.com",
        role="manager",
        salary_currency="RUB",
        theme_preference="system",
        locale="ru",
    )
    # Frontend MePageHeader использует именно эти поля:
    assert p.id == 1
    assert p.full_name == "Иван Иванов"
    assert p.email == "i@test.com"
    assert p.role == "manager"
    assert p.job_title is None
    assert p.department_name is None
    assert p.manager_name is None
    assert p.manager_id is None
    assert p.supervisor_name is None
    assert p.supervisor_id is None
    assert p.avatar_path is None
    assert p.subordinates_count == 0


def test_me_profile_out_extended_settings_fields():
    """Дополнительные UX-Profile поля для таба «Настройки» / зарплата."""
    p = MeProfileOut(
        id=42,
        user_id=42,
        full_name="Анна",
        email="a@test.com",
        role="director",
        salary_currency="UZS",
        salary_country_code="UZ",
        employment_start_date=date(2025, 1, 15),
        theme_preference="dark",
        locale="ru",
        department_id=3,
        signature_url="/uploads/signatures/42.png",
    )
    assert p.salary_currency == "UZS"
    assert p.salary_country_code == "UZ"
    assert p.employment_start_date == date(2025, 1, 15)
    assert p.theme_preference == "dark"
    assert p.locale == "ru"
    assert p.department_id == 3
    assert p.signature_url == "/uploads/signatures/42.png"


def test_me_profile_out_id_and_user_id_can_match():
    """user_id и id — это alias на одно и то же. Pydantic не должен ругаться."""
    p = MeProfileOut(
        id=7,
        user_id=7,
        full_name="X",
        email="x@x.com",
        role="admin",
        salary_currency="RUB",
        theme_preference="system",
        locale="ru",
    )
    assert p.id == p.user_id == 7


def test_me_profile_out_subordinates_count_default_zero():
    """Если backend не посчитал — должно быть 0, не None (frontend не имеет null-guard)."""
    p = MeProfileOut(
        id=1, user_id=1,
        full_name="X", email="x@x.com", role="manager",
        salary_currency="RUB", theme_preference="system", locale="ru",
    )
    assert p.subordinates_count == 0
    assert isinstance(p.subordinates_count, int)


def test_me_profile_out_role_is_string():
    """role в response — string ('admin'/'manager'/'director'), не enum."""
    p = MeProfileOut(
        id=1, user_id=1,
        full_name="X", email="x@x.com",
        role="admin",
        salary_currency="RUB", theme_preference="system", locale="ru",
    )
    assert isinstance(p.role, str)
    assert p.role == "admin"


# ============ OverviewResponse — фронт ждёт плоский shape ============


def test_overview_response_has_flat_fields():
    """Хотфикс: фронт OverviewKpiRow.tsx читает поля плоско (НЕ через .totals).

    Если этот тест упадёт — фронт снова получит undefined.toFixed crash.
    """
    overview = OverviewResponse(
        totals=OverviewTotals(
            courses_active=5,
            assignments_total=100,
            completions_total=42,
            completion_rate_pct=42.0,
            avg_completion_hours=3.5,
            active_learners_30d=20,
            overdue_mandatory=2,
            assignments_new_30d=15,
        ),
        sparkline_assignments_per_day=[1, 2, 3],
        completions_by_course=[],
        status_distribution=StatusDistribution(
            assigned=10, in_progress=20, completed=40, overdue=2,
        ),
        activity_by_day_90d=[],
        # Плоские поля
        total_courses=5,
        total_assignments=100,
        new_assignments_30d=15,
        total_completed=42,
        completion_rate_pct=42.0,
        avg_time_to_complete_hours=3.5,
        active_learners_30d=20,
        overdue_mandatory=2,
        courses_sparkline_30d=[1, 2, 3],
    )
    # Frontend читает именно эти поля:
    assert overview.total_courses == 5
    assert overview.total_assignments == 100
    assert overview.new_assignments_30d == 15
    assert overview.total_completed == 42
    assert overview.completion_rate_pct == 42.0
    assert overview.avg_time_to_complete_hours == 3.5
    assert overview.active_learners_30d == 20
    assert overview.overdue_mandatory == 2
    assert overview.courses_sparkline_30d == [1, 2, 3]


def test_overview_response_backward_compat_totals():
    """xlsx-экспорт читает overview.totals — не должно сломаться."""
    overview = OverviewResponse(
        totals=OverviewTotals(
            courses_active=3,
            assignments_total=50,
            completions_total=20,
            completion_rate_pct=40.0,
            avg_completion_hours=2.0,
            active_learners_30d=10,
            overdue_mandatory=0,
            assignments_new_30d=5,
        ),
        sparkline_assignments_per_day=[],
        completions_by_course=[],
        status_distribution=StatusDistribution(
            assigned=0, in_progress=0, completed=0, overdue=0,
        ),
        activity_by_day_90d=[],
    )
    assert overview.totals.courses_active == 3
    assert overview.totals.completion_rate_pct == 40.0
    assert overview.totals.avg_completion_hours == 2.0


def test_overview_response_flat_fields_default_to_zero():
    """Если плоские поля не переданы — должны быть 0 / 0.0 / [], НЕ None.

    Фронт зовёт `.toFixed()` без null-guard'а, поэтому None уронит UI.
    """
    overview = OverviewResponse(
        totals=OverviewTotals(
            courses_active=0,
            assignments_total=0,
            completions_total=0,
            completion_rate_pct=0.0,
            avg_completion_hours=0.0,
            active_learners_30d=0,
            overdue_mandatory=0,
            assignments_new_30d=0,
        ),
        sparkline_assignments_per_day=[],
        completions_by_course=[],
        status_distribution=StatusDistribution(
            assigned=0, in_progress=0, completed=0, overdue=0,
        ),
        activity_by_day_90d=[],
        # Не передаём плоские → должны дефолтиться
    )
    assert overview.total_courses == 0
    assert overview.completion_rate_pct == 0.0
    assert isinstance(overview.completion_rate_pct, float)
    assert overview.total_assignments == 0
    assert overview.courses_sparkline_30d == []


def test_overview_completion_rate_is_always_float():
    """Защита от int → проверяем тип. Фронту нужен float для toFixed."""
    overview = OverviewResponse(
        totals=OverviewTotals(
            courses_active=0,
            assignments_total=10,
            completions_total=5,
            completion_rate_pct=50.0,
            avg_completion_hours=1.0,
            active_learners_30d=0,
            overdue_mandatory=0,
            assignments_new_30d=0,
        ),
        sparkline_assignments_per_day=[],
        completions_by_course=[],
        status_distribution=StatusDistribution(
            assigned=0, in_progress=0, completed=0, overdue=0,
        ),
        activity_by_day_90d=[],
        completion_rate_pct=50.0,
    )
    assert isinstance(overview.completion_rate_pct, float)


# ============ Numeric coercion в overview сервисе ============


def test_compute_avg_hours_returns_float_when_empty():
    """Регрессия: для пустых данных функция должна вернуть 0.0 (float), не None."""
    result = compute_avg_completion_hours([])
    assert result == 0.0
    assert isinstance(result, float)


def test_compute_avg_hours_returns_float_with_data():
    """Для нормальных данных результат тоже float."""
    now = datetime(2026, 6, 1, 12, 0, 0, tzinfo=UTC)
    result = compute_avg_completion_hours([(now - timedelta(hours=2), now)])
    assert isinstance(result, float)
    assert result == 2.0


def test_compute_completion_rate_returns_float():
    """Регрессия: 0/0 случай и normal случай — всегда float."""
    assert isinstance(compute_completion_rate(0, 0), float)
    assert isinstance(compute_completion_rate(100, 42), float)
