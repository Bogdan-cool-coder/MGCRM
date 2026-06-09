"""P0 security (Unit 3a) — object-level IDOR: pure-function тесты.

Покрывают entity-aware owner-резолвинг ensure_object_visible (через чистые
предикаты _object_in_personal_scope / _object_in_department_scope), mutate-guard
активностей (can_mutate_activity) и owner-scope SQL-clause для list-активностей.

Тесты НЕ касаются БД (предикаты — pure; clause проверяем через compile()).
Сама ensure_object_visible/assert_target_visible требует session (DB-lookup
scope) — её 404-логика покрыта integration-уровнем; здесь — детерминированное
ядро решения «виден или нет».
"""
from __future__ import annotations

from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest
from fastapi import HTTPException
from sqlalchemy.future import select

from app.models import (
    Activity,
    Company,
    Contract,
    Deal,
    Lead,
    User,
    UserRole,
)
from app.services.access_control import (
    _object_in_department_scope,
    _object_in_personal_scope,
)
from app.services.activity_visibility import (
    activity_owner_scope_clause,
    can_mutate_activity,
    is_elevated,
)


def _user(role: UserRole, uid: int = 1, dept_id: int | None = None) -> User:
    return User(id=uid, role=role, department_id=dept_id)


# ============ deal: owner_user_id ============


def test_personal_scope_deal_owner_match():
    """Менеджер видит свою сделку, не видит чужую (scope='personal')."""
    owner = _user(UserRole.manager, uid=10)
    foreign = _user(UserRole.manager, uid=20)
    deal = Deal(id=1, owner_user_id=10)
    assert _object_in_personal_scope(deal, "deal", owner) is True
    assert _object_in_personal_scope(deal, "deal", foreign) is False


# ============ contract: author_user_id (НЕ owner_user_id/owner_id) ============


def test_personal_scope_contract_uses_author():
    """Contract скоупится по author_user_id (раньше обобщённый helper его не видел
    → контракт был «без владельца» → виден всем). Теперь — корректно."""
    author = _user(UserRole.manager, uid=5)
    foreign = _user(UserRole.manager, uid=6)
    contract = Contract(id=1, author_user_id=5)
    assert _object_in_personal_scope(contract, "contract", author) is True
    assert _object_in_personal_scope(contract, "contract", foreign) is False


# ============ activity: responsible_id ИЛИ created_by_id ============


def test_personal_scope_activity_responsible_or_creator():
    """Activity видна постановщику ИЛИ исполнителю, не постороннему."""
    creator = _user(UserRole.manager, uid=1)
    executor = _user(UserRole.manager, uid=2)
    foreign = _user(UserRole.manager, uid=3)
    act = Activity(id=1, created_by_id=1, responsible_id=2)
    assert _object_in_personal_scope(act, "activity", creator) is True
    assert _object_in_personal_scope(act, "activity", executor) is True
    assert _object_in_personal_scope(act, "activity", foreign) is False


# ============ lead: owner_id ============


def test_personal_scope_lead_owner_id():
    owner = _user(UserRole.manager, uid=9)
    foreign = _user(UserRole.manager, uid=8)
    lead = Lead(id=1, owner_id=9)
    assert _object_in_personal_scope(lead, "lead", owner) is True
    assert _object_in_personal_scope(lead, "lead", foreign) is False


# ============ department scope ============


def test_department_scope_match():
    user = _user(UserRole.manager, uid=1, dept_id=4)
    assert _object_in_department_scope(
        Deal(id=1, department_id=4), user, "department", None
    ) is True
    assert _object_in_department_scope(
        Deal(id=2, department_id=5), user, "department", None
    ) is False


def test_department_and_children_subtree():
    user = _user(UserRole.manager, uid=1, dept_id=4)
    assert _object_in_department_scope(
        Deal(id=1, department_id=7), user, "department_and_children", [4, 7]
    ) is True
    assert _object_in_department_scope(
        Deal(id=2, department_id=99), user, "department_and_children", [4, 7]
    ) is False


# ============ elevated roles ============


def test_is_elevated_admin_director():
    assert is_elevated(_user(UserRole.admin)) is True
    assert is_elevated(_user(UserRole.director)) is True
    assert is_elevated(_user(UserRole.manager)) is False
    assert is_elevated(_user(UserRole.accountant)) is False
    assert is_elevated(_user(UserRole.cfo)) is False


# ============ can_mutate_activity ============


def test_can_mutate_activity_owner_or_elevated():
    act = Activity(id=1, created_by_id=1, responsible_id=2)
    assert can_mutate_activity(act, _user(UserRole.manager, uid=1)) is True  # creator
    assert can_mutate_activity(act, _user(UserRole.manager, uid=2)) is True  # executor
    assert can_mutate_activity(act, _user(UserRole.manager, uid=3)) is False  # foreign
    assert can_mutate_activity(act, _user(UserRole.admin, uid=9)) is True    # admin
    assert can_mutate_activity(act, _user(UserRole.director, uid=9)) is True  # director


# ============ activity_owner_scope_clause (list-скоупинг) ============


def test_activity_owner_scope_clause_elevated_none():
    """admin/director → None (без фильтра)."""
    assert activity_owner_scope_clause(_user(UserRole.admin)) is None
    assert activity_owner_scope_clause(_user(UserRole.director)) is None


def test_activity_owner_scope_clause_manager_filters():
    """Не-elevated → OR(responsible|created_by|collaborator)."""
    clause = activity_owner_scope_clause(_user(UserRole.manager, uid=42))
    assert clause is not None
    stmt = select(Activity).where(clause)
    compiled = str(stmt.compile(compile_kwargs={"literal_binds": True}))
    assert "activities.responsible_id = 42" in compiled
    assert "activities.created_by_id = 42" in compiled
    assert "activity_collaborators" in compiled


def test_activity_owner_scope_clause_accountant_also_filtered():
    """P0 (Unit 3a): accountant/cfo тоже скоупятся (раньше видели всё)."""
    for role in (UserRole.accountant, UserRole.cfo):
        clause = activity_owner_scope_clause(_user(role, uid=7))
        assert clause is not None


# ============ entity без owner-колонки → не фильтруем (defensive) ============


def test_personal_scope_no_owner_column_passes():
    """Объект без owner-полей (entity вне карты, нет owner_*) → True."""
    from app.models import Department
    user = _user(UserRole.manager, uid=1)
    assert _object_in_personal_scope(Department(id=1, name="X"), "department", user) is True


# ============ resolve_scope fail-CLOSED default ============


def _scope_session(rows: list):
    """AsyncMock session: каждый execute().scalar_one_or_none() возвращает rows.pop(0)."""
    sess = AsyncMock()
    seq = list(rows)

    async def _execute(*_a, **_k):
        result = SimpleNamespace()
        val = seq.pop(0) if seq else None
        result.scalar_one_or_none = lambda: val
        return result

    sess.execute = _execute
    return sess


@pytest.mark.asyncio
async def test_resolve_scope_missing_setting_defaults_personal():
    """P0 (Unit 3a): нет строки в visibility_settings → 'personal' (fail-CLOSED).

    Раньше дефолт был 'all' (fail-open) — неконфигурированная роль видела всё.
    """
    from app.services.access_control import resolve_scope
    sess = _scope_session([None, None])  # ни role-row, ни NULL-fallback
    scope = await resolve_scope(sess, "deal", UserRole.manager)
    assert scope == "personal"


@pytest.mark.asyncio
async def test_resolve_scope_role_row_wins():
    """Явная per-role настройка возвращается как есть."""
    from app.services.access_control import resolve_scope
    sess = _scope_session([SimpleNamespace(scope="all")])
    scope = await resolve_scope(sess, "deal", UserRole.lawyer)
    assert scope == "all"


@pytest.mark.asyncio
async def test_ensure_object_visible_foreign_deal_404():
    """ensure_object_visible бросает 404 на чужую сделку при scope='personal'."""
    from app.services.access_control import ensure_object_visible
    sess = _scope_session([None, None])  # → resolve_scope = personal
    foreign_deal = Deal(id=1, owner_user_id=999)
    with pytest.raises(HTTPException) as exc:
        await ensure_object_visible(sess, foreign_deal, "deal", _user(UserRole.manager, uid=1))
    assert exc.value.status_code == 404


@pytest.mark.asyncio
async def test_ensure_object_visible_own_deal_passes():
    """Владелец проходит ensure_object_visible (personal)."""
    from app.services.access_control import ensure_object_visible
    sess = _scope_session([None, None])
    own_deal = Deal(id=1, owner_user_id=42)
    await ensure_object_visible(sess, own_deal, "deal", _user(UserRole.manager, uid=42))


@pytest.mark.asyncio
async def test_ensure_object_visible_admin_always_passes():
    """admin → scope='all' (без DB-lookup) → проходит на любую запись."""
    from app.services.access_control import ensure_object_visible
    sess = _scope_session([])  # admin не должен ходить в БД
    foreign_deal = Deal(id=1, owner_user_id=999)
    await ensure_object_visible(sess, foreign_deal, "deal", _user(UserRole.admin, uid=1))


@pytest.mark.asyncio
async def test_ensure_object_visible_director_always_passes():
    from app.services.access_control import ensure_object_visible
    # director не admin → resolve_scope ходит в БД; вернём 'all' первой строкой.
    sess = _scope_session([SimpleNamespace(scope="all")])
    foreign_contract = Contract(id=1, author_user_id=999)
    await ensure_object_visible(sess, foreign_contract, "contract", _user(UserRole.director, uid=1))


# ============ default role-scope matrix (seed) ============


def test_default_role_scopes_fail_closed_sensible():
    """Дефолтная матрица: elevated=all, manager/accountant/cfo=personal (own)."""
    from app.services.visibility import _DEFAULT_ROLE_SCOPES
    assert _DEFAULT_ROLE_SCOPES["admin"] == "all"
    assert _DEFAULT_ROLE_SCOPES["director"] == "all"
    assert _DEFAULT_ROLE_SCOPES["lawyer"] == "all"
    assert _DEFAULT_ROLE_SCOPES["manager"] == "personal"
    assert _DEFAULT_ROLE_SCOPES["accountant"] == "personal"
    assert _DEFAULT_ROLE_SCOPES["cfo"] == "personal"


def test_scope_to_user_fail_closed_for_non_elevated():
    """deps.scope_to_user: accountant/cfo/manager скоупятся; admin/director/lawyer — нет."""
    from app.deps import scope_to_user

    def _filtered(role: UserRole) -> bool:
        stmt = select(Deal)
        out = scope_to_user(stmt, Deal, _user(role, uid=5), "owner_user_id")
        return out is not stmt

    assert _filtered(UserRole.manager) is True
    assert _filtered(UserRole.accountant) is True
    assert _filtered(UserRole.cfo) is True
    assert _filtered(UserRole.admin) is False
    assert _filtered(UserRole.director) is False
    assert _filtered(UserRole.lawyer) is False
