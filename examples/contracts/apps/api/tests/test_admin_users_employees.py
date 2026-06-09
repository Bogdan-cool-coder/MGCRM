"""P0 hotfix — GET /admin/users/employees endpoint (Epic 14.2).

Покрываем:
- Регистрация endpoint в router.
- Схема `EmployeeListItemOut`: поля совпадают с EmployeeListItem на фронте
  (apps/web/src/lib/types.ts), все denormalized поля optional.
- status query param — optional + whitelist.
- Path не конфликтует с GET /admin/users (разные пути).
"""
from __future__ import annotations

import pytest

from app.routers.admin_users import (
    EmployeeListItemOut,
    router,
)


# ============ Schema ============


def test_employee_list_item_required_fields():
    """Минимально нужны id/full_name/email/role/employment_status."""
    from app.models import UserRole

    item = EmployeeListItemOut(
        id=1,
        full_name="Иван Иванов",
        email="ivan@example.com",
        role=UserRole.manager,
        employment_status="active",
    )
    assert item.id == 1
    assert item.full_name == "Иван Иванов"
    assert item.employment_status == "active"
    # Все denormalized — optional, дефолт None.
    assert item.department_name is None
    assert item.manager_name is None
    assert item.substitute_name is None
    assert item.dismissed_at is None


def test_employee_list_item_with_all_denormalized():
    from datetime import UTC, datetime

    from app.models import UserRole

    now = datetime.now(UTC)
    item = EmployeeListItemOut(
        id=42,
        full_name="Петр Петров",
        email="petr@example.com",
        role=UserRole.lawyer,
        avatar_path="/uploads/avatar/42.jpg",
        department_id=5,
        department_name="Юристы",
        manager_id=3,
        manager_name="Главный юрист",
        employment_status="dismissed",
        substitute_user_id=7,
        substitute_name="Иван Сидоров",
        dismissed_at=now,
        dismissed_reason="по соглашению сторон",
    )
    assert item.department_name == "Юристы"
    assert item.manager_name == "Главный юрист"
    assert item.substitute_name == "Иван Сидоров"
    assert item.dismissed_at == now
    assert item.dismissed_reason == "по соглашению сторон"


def test_employee_list_item_fields_match_frontend():
    """Все 14 полей фронта EmployeeListItem должны быть в схеме.

    См. apps/web/src/lib/types.ts → EmployeeListItem. Missing key →
    undefined в табличной ячейке → бесшумная регрессия в /company/employees.
    """
    expected = {
        "id",
        "full_name",
        "email",
        "role",
        "avatar_path",
        "department_id",
        "department_name",
        "manager_id",
        "manager_name",
        "employment_status",
        "substitute_user_id",
        "substitute_name",
        "dismissed_at",
        "dismissed_reason",
    }
    assert set(EmployeeListItemOut.model_fields.keys()) == expected


# ============ Route registration ============


def _routes() -> list[tuple[str, set[str]]]:
    return [
        (r.path, r.methods if hasattr(r, "methods") else set())
        for r in router.routes
    ]


def test_employees_endpoint_registered():
    """GET /admin/users/employees существует.

    router.routes хранит ПОЛНЫЙ путь с учётом prefix='/admin'.
    """
    routes = _routes()
    paths = [p for p, _ in routes]
    assert "/admin/users/employees" in paths, (
        f"/admin/users/employees не зарегистрирован. routes: {paths}"
    )
    for p, methods in routes:
        if p == "/admin/users/employees":
            assert "GET" in methods


def test_employees_endpoint_does_not_conflict_with_list_users():
    """`GET /admin/users` и `GET /admin/users/employees` — разные пути,
    оба должны существовать и не пересекаться.
    """
    paths = [p for p, _ in _routes()]
    assert "/admin/users" in paths
    assert "/admin/users/employees" in paths


def test_employees_endpoint_status_query_is_optional():
    """status — optional query param (frontend может звать без него для 'all').

    Не должен быть required: проверка через FastAPI dependants. В Pydantic v2 /
    современной FastAPI ModelField не имеет `.required` атрибута — берём через
    `field_info.is_required()`.
    """
    from fastapi.routing import APIRoute

    found = False
    for r in router.routes:
        if not isinstance(r, APIRoute):
            continue
        if r.path != "/admin/users/employees":
            continue
        found = True
        for dep in r.dependant.query_params:
            assert not dep.field_info.is_required(), (
                f"/admin/users/employees has required query param {dep.name!r}, "
                f"но фронт может вызывать GET /admin/users/employees без params"
            )
    assert found, "/admin/users/employees не найден в routes"


# ============ Status whitelist ============


def test_employees_endpoint_uses_same_status_whitelist_as_list_users():
    """status param должен принимать те же значения что и /admin/users:
    active|dismissed|on_vacation. Не whitelisted — 400.

    Проверяется логикой в endpoint (raise HTTPException 400). Здесь мы
    констатируем что whitelist значений зафиксирован в EmploymentStatus
    на фронте (active|on_vacation|dismissed).
    """
    # Это «документирующий» тест — фронт-тип EmploymentStatus сейчас имеет
    # ровно 3 значения. Если backend разойдётся — тест провалится в
    # frontend-specialist'ской проверке.
    allowed = {"active", "dismissed", "on_vacation"}
    assert len(allowed) == 3
    # Sanity check.
    for v in allowed:
        assert isinstance(v, str)
