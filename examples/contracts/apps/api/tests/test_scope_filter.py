"""Pure-function тесты scope-фильтра видимости (Эпик 14).

Покрывает apply_scope_filter() — добавление WHERE к Select-запросу по
scope ∈ {personal, department, department_and_children, all}.

Тесты НЕ касаются БД: используем in-memory User + select(...) + str(compile()).
"""
from __future__ import annotations

from sqlalchemy.future import select

from app.models import (
    ClientSubscription,
    Company,
    Counterparty,
    Deal,
    Lead,
    User,
    UserRole,
)
from app.services.access_control import (
    ALLOWED_ENTITY_TYPES,
    ALLOWED_SCOPES,
    _get_owner_column,
    apply_scope_filter,
    check_object_scope,
)


def _user(role: UserRole, uid: int = 1, dept_id: int | None = None) -> User:
    """Лёгкий User без обращения к БД."""
    return User(id=uid, role=role, department_id=dept_id)


# ============ scope='personal' ============


def test_scope_filter_personal_filters_by_owner_for_deal():
    """Deal.owner_user_id == user.id — добавляется WHERE."""
    user = _user(UserRole.manager, uid=42)
    stmt = select(Deal)
    out = apply_scope_filter(stmt, Deal, user, scope="personal")
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "deals.owner_user_id = 42" in compiled


def test_scope_filter_personal_filters_by_owner_for_lead():
    """Lead.owner_id == user.id — добавляется WHERE (Lead использует owner_id, не owner_user_id)."""
    user = _user(UserRole.manager, uid=7)
    stmt = select(Lead)
    out = apply_scope_filter(stmt, Lead, user, scope="personal")
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "leads.owner_id = 7" in compiled


def test_scope_filter_personal_filters_by_owner_for_counterparty():
    """Counterparty.owner_user_id == user.id — добавляется WHERE."""
    user = _user(UserRole.manager, uid=15)
    stmt = select(Counterparty)
    out = apply_scope_filter(stmt, Counterparty, user, scope="personal")
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "counterparties.owner_user_id = 15" in compiled


def test_scope_filter_personal_filters_by_owner_for_company():
    user = _user(UserRole.manager, uid=33)
    stmt = select(Company)
    out = apply_scope_filter(stmt, Company, user, scope="personal")
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "crm_companies.owner_user_id = 33" in compiled


def test_scope_filter_personal_filters_by_owner_for_subscription():
    user = _user(UserRole.manager, uid=99)
    stmt = select(ClientSubscription)
    out = apply_scope_filter(stmt, ClientSubscription, user, scope="personal")
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "client_subscriptions.owner_user_id = 99" in compiled


# ============ scope='department' ============


def test_scope_filter_department_filters_by_dept():
    """User.department_id != None → WHERE entity.department_id == user.department_id."""
    user = _user(UserRole.manager, uid=10, dept_id=5)
    stmt = select(Deal)
    out = apply_scope_filter(stmt, Deal, user, scope="department")
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "deals.department_id = 5" in compiled


def test_scope_filter_department_user_without_dept_filters_null():
    """User.department_id is None → WHERE entity.department_id IS NULL.

    Это «человек без отдела видит только бесхозные записи» — строгое поведение.
    """
    user = _user(UserRole.manager, uid=11, dept_id=None)
    stmt = select(Deal)
    out = apply_scope_filter(stmt, Deal, user, scope="department")
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "deals.department_id IS NULL" in compiled


def test_scope_filter_department_for_lead():
    """Lead тоже получил department_id в миграции 0036 — scope=department работает."""
    user = _user(UserRole.manager, uid=7, dept_id=3)
    stmt = select(Lead)
    out = apply_scope_filter(stmt, Lead, user, scope="department")
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "leads.department_id = 3" in compiled


# ============ scope='department_and_children' ============


def test_scope_filter_department_and_children_with_subtree():
    """dept_ids передан → WHERE entity.department_id IN (...)."""
    user = _user(UserRole.manager, uid=1, dept_id=10)
    stmt = select(Deal)
    out = apply_scope_filter(
        stmt, Deal, user, scope="department_and_children", dept_ids=[10, 11, 12],
    )
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    # SQLAlchemy формирует IN (10, 11, 12)
    assert "deals.department_id IN" in compiled
    assert "10" in compiled and "11" in compiled and "12" in compiled


def test_scope_filter_department_and_children_empty_subtree_user_has_dept():
    """Пустой subtree + user.department_id is not None → fallback на == user.dept_id.

    Это страховка от ситуации «отдел только что создан, subtree пуст».
    """
    user = _user(UserRole.manager, uid=1, dept_id=22)
    stmt = select(Deal)
    out = apply_scope_filter(
        stmt, Deal, user, scope="department_and_children", dept_ids=[],
    )
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "deals.department_id = 22" in compiled


def test_scope_filter_department_and_children_empty_subtree_user_no_dept():
    """Пустой subtree + user без отдела → WHERE department_id IS NULL."""
    user = _user(UserRole.manager, uid=1, dept_id=None)
    stmt = select(Deal)
    out = apply_scope_filter(
        stmt, Deal, user, scope="department_and_children", dept_ids=[],
    )
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "deals.department_id IS NULL" in compiled


# ============ scope='all' / неизвестные значения ============


def test_scope_filter_all_returns_query_unchanged():
    """scope='all' → stmt возвращается без изменений (same object)."""
    user = _user(UserRole.manager, uid=1)
    stmt = select(Deal)
    out = apply_scope_filter(stmt, Deal, user, scope="all")
    assert out is stmt


def test_scope_filter_unknown_scope_fail_closed_to_personal():
    """P0 security (Unit 3a): неизвестный scope → fail-CLOSED на 'personal'.

    Раньше битый scope трактовался как 'all' (fail-open) — повреждение строки
    visibility_settings открывало сущность всем. Теперь сводится к самому узкому.
    """
    user = _user(UserRole.manager, uid=77)
    stmt = select(Deal)
    out = apply_scope_filter(stmt, Deal, user, scope="garbage_value")
    assert out is not stmt
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "deals.owner_user_id = 77" in compiled


# ============ Model без owner-column ============


def test_scope_filter_personal_model_without_owner_falls_back():
    """Модель без owner_user_id/owner_id → fallback (без WHERE), не падает.

    Поведение: defensive — если сервис вызовут на «справочнике», ничего не
    сломается. В реальности на такую модель scope_query не вызовут.
    """
    from app.models import Department  # есть только id + name + parent_id + ...
    user = _user(UserRole.manager, uid=1)
    stmt = select(Department)
    out = apply_scope_filter(stmt, Department, user, scope="personal")
    # Поскольку Department не имеет owner_user_id / owner_id, ничего не
    # добавилось — возвращён оригинальный stmt.
    assert out is stmt


# ============ _get_owner_column ============


def test_get_owner_column_prefers_owner_user_id():
    """Deal имеет owner_user_id → возвращается он, не owner_id."""
    col = _get_owner_column(Deal)
    assert col is not None
    # Колонка — InstrumentedAttribute; имя — owner_user_id
    assert col.key == "owner_user_id"


def test_get_owner_column_falls_back_to_owner_id():
    """Lead имеет только owner_id → возвращается он (бэквард-совместимо)."""
    col = _get_owner_column(Lead)
    assert col is not None
    assert col.key == "owner_id"


def test_get_owner_column_returns_none_when_no_owner_field():
    """Модель без owner_*_id → None (fallback, без exception)."""
    from app.models import Department
    col = _get_owner_column(Department)
    assert col is None


# ============ Существующие WHERE сохраняются ============


def test_scope_filter_preserves_existing_where():
    """Существующий WHERE остаётся; scope-фильтр добавляется через AND."""
    user = _user(UserRole.manager, uid=99, dept_id=4)
    stmt = select(Deal).where(Deal.amount > 100)
    out = apply_scope_filter(stmt, Deal, user, scope="department")
    compiled = str(out.compile(compile_kwargs={"literal_binds": True}))
    assert "deals.amount > 100" in compiled
    assert "deals.department_id = 4" in compiled
    # Оба условия в одном WHERE через AND — count WHERE должно быть 1
    assert compiled.upper().count("WHERE") == 1


# ============ check_object_scope (item-эндпоинты) ============


def test_check_object_scope_all_always_visible():
    """scope='all' → True независимо от owner/department."""
    user = _user(UserRole.manager, uid=1, dept_id=5)
    obj = Company(id=10, owner_user_id=999, department_id=999)
    assert check_object_scope(obj, user, "all") is True


def test_check_object_scope_personal_owner_match():
    """scope='personal' → True если owner_user_id == user.id."""
    user = _user(UserRole.manager, uid=42)
    assert check_object_scope(Company(id=1, owner_user_id=42), user, "personal") is True
    assert check_object_scope(Company(id=2, owner_user_id=7), user, "personal") is False


def test_check_object_scope_personal_lead_owner_id():
    """Lead использует owner_id — check_object_scope находит legacy-колонку."""
    user = _user(UserRole.manager, uid=9)
    assert check_object_scope(Lead(id=1, owner_id=9), user, "personal") is True
    assert check_object_scope(Lead(id=2, owner_id=3), user, "personal") is False


def test_check_object_scope_department_match():
    """scope='department' → True если department_id совпадает."""
    user = _user(UserRole.manager, uid=1, dept_id=4)
    assert check_object_scope(Deal(id=1, department_id=4), user, "department") is True
    assert check_object_scope(Deal(id=2, department_id=5), user, "department") is False


def test_check_object_scope_department_user_without_dept_sees_only_orphans():
    """Юзер без отдела видит только записи с department_id=None."""
    user = _user(UserRole.manager, uid=1, dept_id=None)
    assert check_object_scope(Deal(id=1, department_id=None), user, "department") is True
    assert check_object_scope(Deal(id=2, department_id=3), user, "department") is False


def test_check_object_scope_subtree():
    """scope='department_and_children' → True если department_id ∈ dept_ids."""
    user = _user(UserRole.manager, uid=1, dept_id=4)
    obj_in = Deal(id=1, department_id=7)
    obj_out = Deal(id=2, department_id=99)
    assert check_object_scope(obj_in, user, "department_and_children", [4, 7]) is True
    assert check_object_scope(obj_out, user, "department_and_children", [4, 7]) is False


def test_check_object_scope_unknown_scope_fail_closed():
    """P0 security (Unit 3a): неизвестный scope → fail-CLOSED 'personal'.

    Объект чужого владельца более НЕ виден при битом scope (раньше → True).
    """
    user = _user(UserRole.manager, uid=1)
    assert check_object_scope(Company(id=1, owner_user_id=999), user, "weird") is False
    assert check_object_scope(Company(id=2, owner_user_id=1), user, "weird") is True


# ============ P2: _object_in_department_scope fail-CLOSED для сущностей ============
# без department-колонки (audit A3 / PM follow-up). Раньше contract под
# department-scope возвращал True (виден всем) → теперь fallback на personal.


def test_object_in_department_scope_contract_no_dept_falls_back_to_author():
    """Contract не имеет department_id. Под department-scope раньше → True (fail-open).

    Теперь fallback на personal (entity-aware: contract → author_user_id):
    виден ТОЛЬКО автору, а не всем.
    """
    from app.models import Contract
    from app.services.access_control import _object_in_department_scope

    user = _user(UserRole.lawyer, uid=42, dept_id=5)
    own = Contract(id=1, author_user_id=42)
    other = Contract(id=2, author_user_id=7)
    assert _object_in_department_scope(own, user, "department", None, "contract") is True
    assert _object_in_department_scope(other, user, "department", None, "contract") is False


def test_object_in_department_scope_no_entity_type_no_owner_fails_closed():
    """Без entity_type и без owner-колонки → fail-CLOSED (False), не True."""
    from app.models import Setting
    from app.services.access_control import _object_in_department_scope

    user = _user(UserRole.manager, uid=1, dept_id=3)
    # Setting не имеет ни department_id, ни owner-колонки.
    assert _object_in_department_scope(Setting(key="x"), user, "department", None) is False


def test_object_in_department_scope_with_dept_column_unchanged():
    """Сущности С department_id — поведение прежнее (по отделу)."""
    from app.services.access_control import _object_in_department_scope

    user = _user(UserRole.manager, uid=1, dept_id=4)
    assert _object_in_department_scope(Deal(id=1, department_id=4), user, "department", None) is True
    assert _object_in_department_scope(Deal(id=2, department_id=5), user, "department", None) is False


# ============ Константы ============


def test_allowed_scopes_contains_expected():
    """Конкретный набор допустимых scope — синхронизирован с CHECK миграции 0036."""
    assert ALLOWED_SCOPES == {"personal", "department", "department_and_children", "all"}


def test_allowed_entity_types_contains_expected():
    """entity для visibility-матрицы. P0 (Unit 3a): добавлен 'activity'."""
    assert ALLOWED_ENTITY_TYPES == {
        "lead", "deal", "contract", "subscription", "counterparty", "company",
        "activity",
    }
