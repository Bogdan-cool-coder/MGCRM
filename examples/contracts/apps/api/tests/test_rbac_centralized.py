"""Pure-function тесты централизованной RBAC-логики (app.deps).

Покрывает Эпик 0 / Блок A (май 2026):
- scope_to_user(stmt, model, user, owner_field) — добавляет WHERE для manager,
  пропускает admin/director/lawyer без фильтра.
- require_owner_or_role-фабрика — собирается без побочных эффектов.

Тесты НЕ касаются БД: используем in-memory модели + sqlalchemy.compile() для
проверки добавился ли WHERE.
"""
from __future__ import annotations

from sqlalchemy.future import select

from app.deps import require_owner_or_role, scope_to_user
from app.models import BulkTask, Contract, Lead, User, UserRole


def _user(role: UserRole, uid: int = 1) -> User:
    """Лёгкий User без обращения к БД."""
    return User(id=uid, role=role)


# ============ scope_to_user ============


def test_scope_to_user_manager_adds_where():
    """Manager → к stmt добавляется WHERE owner_id=user.id."""
    user = _user(UserRole.manager, uid=42)
    stmt = select(Lead)
    scoped = scope_to_user(stmt, Lead, user, "owner_id")

    compiled = str(scoped.compile(compile_kwargs={"literal_binds": True}))
    # WHERE добавлен и содержит owner_id и id юзера
    assert "WHERE" in compiled.upper()
    assert "leads.owner_id = 42" in compiled


def test_scope_to_user_admin_no_filter():
    """Admin → исходный stmt возвращается без изменений."""
    user = _user(UserRole.admin, uid=1)
    stmt = select(Lead)
    scoped = scope_to_user(stmt, Lead, user, "owner_id")
    assert scoped is stmt  # тот же объект, без копирования


def test_scope_to_user_director_no_filter():
    """Director → исходный stmt возвращается без изменений."""
    user = _user(UserRole.director, uid=1)
    stmt = select(Lead)
    scoped = scope_to_user(stmt, Lead, user, "owner_id")
    assert scoped is stmt


def test_scope_to_user_lawyer_no_filter():
    """Lawyer → исходный stmt возвращается без изменений (для исключения нужно явно).

    Это контрактное поведение: scope_to_user фильтрует ТОЛЬКО для manager. Если
    в будущем понадобится lawyer-scope — нужно передать другую логику явно.
    """
    user = _user(UserRole.lawyer, uid=1)
    stmt = select(Lead)
    scoped = scope_to_user(stmt, Lead, user, "owner_id")
    assert scoped is stmt


def test_scope_to_user_different_owner_fields():
    """Owner_field — параметр; для Contract это author_user_id, для BulkTask — created_by_user_id."""
    manager = _user(UserRole.manager, uid=7)

    # Contract.author_user_id
    stmt_c = select(Contract)
    scoped_c = scope_to_user(stmt_c, Contract, manager, "author_user_id")
    compiled_c = str(scoped_c.compile(compile_kwargs={"literal_binds": True}))
    assert "contracts.author_user_id = 7" in compiled_c

    # BulkTask.created_by_user_id
    stmt_b = select(BulkTask)
    scoped_b = scope_to_user(stmt_b, BulkTask, manager, "created_by_user_id")
    compiled_b = str(scoped_b.compile(compile_kwargs={"literal_binds": True}))
    assert "bulk_tasks.created_by_user_id = 7" in compiled_b


def test_scope_to_user_preserves_existing_where():
    """Существующий WHERE сохраняется, добавляется AND с owner_field=id."""
    manager = _user(UserRole.manager, uid=5)
    stmt = select(Lead).where(Lead.status == "active")
    scoped = scope_to_user(stmt, Lead, manager, "owner_id")
    compiled = str(scoped.compile(compile_kwargs={"literal_binds": True}))
    assert "leads.status = 'active'" in compiled
    assert "leads.owner_id = 5" in compiled
    # И оба условия объединены AND (для SQLAlchemy — в WHERE через AND)
    assert compiled.upper().count("WHERE") == 1


# ============ require_owner_or_role — фабрика собирается ============


def test_require_owner_or_role_factory_returns_callable():
    """Фабрика возвращает зависимость без побочных эффектов на стадии import."""
    async def fake_loader(session, obj_id):
        return None

    dep = require_owner_or_role(fake_loader, owner_field="author_user_id")
    # Должна быть async-функция
    assert callable(dep)
    # Это closure, привязанная к loader/owner_field — её можно использовать в Depends


def test_require_owner_or_role_default_elevated():
    """По умолчанию elevated = (admin, director); lawyer НЕ в дефолтных elevated.

    Это важно для leads/contacts — там lawyer не должен иметь привилегий
    «свой/чужой = всё равно». Для договоров явно расширяется до admin+director+lawyer.
    """
    from app.deps import _DEFAULT_ELEVATED
    assert UserRole.admin in _DEFAULT_ELEVATED
    assert UserRole.director in _DEFAULT_ELEVATED
    assert UserRole.lawyer not in _DEFAULT_ELEVATED
    assert UserRole.manager not in _DEFAULT_ELEVATED


def test_require_owner_or_role_custom_elevated():
    """Можно передать кастомный elevated tuple (для договоров — +lawyer)."""
    async def fake_loader(session, obj_id):
        return None

    # Передаём 3-tuple (admin, director, lawyer)
    custom = (UserRole.admin, UserRole.director, UserRole.lawyer)
    dep = require_owner_or_role(fake_loader, owner_field="author_user_id", elevated=custom)
    assert callable(dep)
    # Фабрика не падает, замыкание создано — практическое поведение проверяется
    # через FastAPI testclient (не входит в pure-function тесты).


# ============ Loader-функции ============


def test_load_contract_signature():
    """load_contract — async helper для require_owner_or_role(load_contract, ...)."""
    import inspect

    from app.deps import load_contract
    sig = inspect.signature(load_contract)
    params = list(sig.parameters.keys())
    assert params == ["session", "contract_id"]
    assert inspect.iscoroutinefunction(load_contract)


def test_load_bulk_task_signature():
    """load_bulk_task — async helper для require_owner_or_role(load_bulk_task, ...)."""
    import inspect

    from app.deps import load_bulk_task
    sig = inspect.signature(load_bulk_task)
    params = list(sig.parameters.keys())
    assert params == ["session", "bulk_task_id"]
    assert inspect.iscoroutinefunction(load_bulk_task)
