"""Эпик 14 — Departments + Visibility ACL: scope-фильтр и helpers.

Чистые функции для фильтрации list-эндпоинтов по правилу видимости:
- `scope='personal'`               → owner_user_id = current_user.id
- `scope='department'`             → department_id = current_user.department_id
- `scope='department_and_children'` → department_id IN (subtree)
- `scope='all'` (default)           → без фильтра (бэквард-совместимо)

admin всегда видит всё — apply_scope_filter возвращает stmt без изменений
(не вычитываем настройки из БД для admin).

Источник правила — таблица `visibility_settings`:
- ищем строку (entity_type, applies_to_role=current_user.role);
- если нет → ищем строку (entity_type, applies_to_role=NULL) — fallback;
- если и её нет → 'all'.

resolve_scope() кеширует через async-lru на TTL=60s — повторные list-вызовы
в один запрос дают один SELECT visibility_settings. Кеш не критичен (нет
hot-path), но избавляет от N+1 если на странице 10+ списков.

Recursive CTE для subtree: `get_department_subtree(db, root_id)` — возвращает
список department_id (включая root). Безопасно для NULL root (возвращает []).
"""
from __future__ import annotations

from typing import Any

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import User, UserRole, VisibilitySetting

# Допустимые значения scope (синхронизированы с CHECK в миграции 0036).
ALLOWED_SCOPES = frozenset({"personal", "department", "department_and_children", "all"})

# Допустимые entity_type — список из плана эпика 14.
# Используется в /admin/visibility-settings для валидации body.
ALLOWED_ENTITY_TYPES = frozenset({
    "lead", "deal", "contract", "subscription", "counterparty", "company",
    # P0 security (Unit 3a): activity видимость наследуется от target, но также
    # имеет собственный owner-scope (responsible_id/created_by_id) — добавлен в
    # матрицу, чтобы админ мог настроить роль→scope для задач/Timeline.
    "activity",
})


async def get_department_subtree(
    db: AsyncSession, root_dept_id: int | None,
) -> list[int]:
    """Возвращает list[id] всех отделов в поддереве (root + descendants).

    Если root_dept_id=None — возвращает пустой список (у юзера нет отдела,
    нечего раскрывать).

    Реализация — recursive CTE по `departments.parent_id`. Хорошо масштабируется
    до тысяч узлов; для миллионов перейти на materialized path / closure table
    (отдельный эпик).
    """
    if root_dept_id is None:
        return []
    sql = text("""
        WITH RECURSIVE subtree AS (
            SELECT id FROM departments WHERE id = :root
            UNION ALL
            SELECT d.id FROM departments d
            JOIN subtree s ON d.parent_id = s.id
        )
        SELECT id FROM subtree
    """)
    result = await db.execute(sql, {"root": root_dept_id})
    return [int(r[0]) for r in result.fetchall()]


def apply_scope_filter(
    stmt: Any,
    entity_model: Any,
    user: User,
    scope: str,
    dept_ids: list[int] | None = None,
) -> Any:
    """Применить scope-фильтр к Select-запросу.

    Pure-function: на входе stmt + параметры, на выходе либо тот же stmt,
    либо stmt с добавленным WHERE. Не лезет в БД.

    Args:
        stmt:        SQLAlchemy Select-объект (select(Entity)).
        entity_model: класс модели (Lead, Deal, Counterparty, ...) — должен
                     иметь поля owner_user_id и/или department_id (для
                     соответствующего scope).
        user:        текущий пользователь (для scope=personal и dept resolution).
        scope:       'personal' | 'department' | 'department_and_children' | 'all'.
                     Неизвестные значения трактуются как 'all' (без фильтра) —
                     defensive поведение, чтобы битая настройка не уронила endpoint.
        dept_ids:    список department_id для scope='department_and_children'
                     (передаётся caller'ом после get_department_subtree).
                     Для других scope игнорируется.

    Returns:
        Модифицированный или оригинальный stmt.

    Защита от ломки: если scope='personal' но у entity нет owner_user_id —
    pythonic AttributeError на этапе compile (как и должно). Caller должен
    проверять до вызова, что модель поддерживает scope. У Lead owner_field —
    `owner_id` (а НЕ owner_user_id), это особый случай: используем `_owner_attr`.

    P0 security (Unit 3a): fail-CLOSED. Битый/неизвестный scope более НЕ
    трактуется как 'all'. Любое значение вне ALLOWED_SCOPES сводится к самому
    узкому 'personal' — повреждение строки visibility_settings не открывает
    сущность всем (раньше это был fail-open).
    """
    if scope == "all":
        return stmt
    if scope not in ALLOWED_SCOPES:
        # fail-CLOSED: битая настройка → самый узкий scope, не 'all'.
        scope = "personal"

    if scope == "personal":
        owner_col = _get_owner_column(entity_model)
        if owner_col is None:
            # модель не поддерживает owner-scope — fallback на 'all' вместо crash.
            return stmt
        return stmt.where(owner_col == user.id)

    if scope == "department":
        dept_col = getattr(entity_model, "department_id", None)
        if dept_col is None:
            return stmt
        # Если у юзера нет department_id — он не видит ничего (department_id=NULL
        # тоже не совпадает с NULL в SQL). Делаем строгий фильтр == NULL — это
        # покажет только записи без отдела (их вряд ли много, но это «правильный»
        # фильтр: «человек без отдела видит только бесхозные записи»).
        if user.department_id is None:
            return stmt.where(dept_col.is_(None))
        return stmt.where(dept_col == user.department_id)

    if scope == "department_and_children":
        dept_col = getattr(entity_model, "department_id", None)
        if dept_col is None:
            return stmt
        if not dept_ids:
            # Юзер без отдела — поведение как для scope='department' (см. выше).
            if user.department_id is None:
                return stmt.where(dept_col.is_(None))
            # Subtree вычислился пуст (например, отдел удалён) — fallback на own.
            return stmt.where(dept_col == user.department_id)
        return stmt.where(dept_col.in_(dept_ids))

    return stmt


def _get_owner_column(entity_model: Any) -> Any:
    """Найти owner-колонку модели.

    Lead исторически использует `owner_id` (а НЕ `owner_user_id`). Все остальные
    (Deal, Counterparty, Company, ClientSubscription) — `owner_user_id`. Эта
    функция универсальная.

    Возвращает SQLAlchemy InstrumentedAttribute или None если не нашли.
    """
    # Сначала ищем owner_user_id (стандарт для Deal, Counterparty, Company, Subscription).
    col = getattr(entity_model, "owner_user_id", None)
    if col is not None:
        return col
    # Lead имеет owner_id — поддерживаем legacy для бэквард-совместимости.
    col = getattr(entity_model, "owner_id", None)
    if col is not None:
        return col
    return None


async def resolve_scope(
    db: AsyncSession,
    entity_type: str,
    user_role: UserRole,
) -> str:
    """Найти эффективный scope для (entity_type, role).

    Стратегия:
    1. Ищем VisibilitySetting(entity_type, applies_to_role=role) — точное совпадение.
    2. Если нет — ищем VisibilitySetting(entity_type, applies_to_role=NULL) — fallback.
    3. Если и его нет — возвращаем 'personal' (fail-CLOSED, least-privilege).

    P0 security (Unit 3a): дефолт при отсутствии настройки — самый узкий 'personal',
    а НЕ 'all'. Любая неконфигурированная/новая роль по умолчанию видит только свои
    записи; админ явно расширяет через матрицу (owner decision «всё на роль явно»).

    admin всегда видит всё — apply_scope_filter обходит resolve_scope для admin
    в get_effective_scope. Эта функция — pure DB-lookup без admin-логики.
    """
    role_value = user_role.value if isinstance(user_role, UserRole) else str(user_role)

    # 1) Точное совпадение по роли
    stmt = select(VisibilitySetting).where(
        VisibilitySetting.entity_type == entity_type,
        VisibilitySetting.applies_to_role == role_value,
    )
    row = (await db.execute(stmt)).scalar_one_or_none()
    if row is not None:
        return row.scope

    # 2) Fallback — NULL role
    stmt = select(VisibilitySetting).where(
        VisibilitySetting.entity_type == entity_type,
        VisibilitySetting.applies_to_role.is_(None),
    )
    row = (await db.execute(stmt)).scalar_one_or_none()
    if row is not None:
        return row.scope

    # 3) Дефолт — самый узкий 'personal' (fail-CLOSED, least-privilege).
    return "personal"


async def get_effective_scope(
    db: AsyncSession,
    entity_type: str,
    user: User,
) -> str:
    """Возвращает эффективный scope для пользователя (с admin-override).

    admin → всегда 'all' (без DB-запроса).
    Иначе → resolve_scope.
    """
    if user.role == UserRole.admin:
        return "all"
    return await resolve_scope(db, entity_type, user.role)


async def autofill_department_from_owner(
    db: AsyncSession,
    owner_user_id: int | None,
    current_department_id: int | None,
) -> int | None:
    """Подставить department_id из User.department_id если он не задан.

    Используется в create/update эндпоинтах для сущностей с owner+department.
    Если owner_user_id задан и current_department_id is None → возвращаем
    user.department_id (может быть None). Иначе возвращаем текущее значение.

    Не подгружает пользователя если current_department_id уже задан — это
    оптимизация для частого случая «owner назначен, отдел тоже».
    """
    if current_department_id is not None:
        return current_department_id
    if owner_user_id is None:
        return None
    user = (await db.execute(
        select(User).where(User.id == owner_user_id)
    )).scalar_one_or_none()
    if user is None:
        return None
    return user.department_id


async def scope_query(
    db: AsyncSession,
    stmt: Any,
    entity_model: Any,
    entity_type: str,
    user: User,
) -> Any:
    """High-level helper: подцепить scope-фильтр к list-запросу.

    Объединяет get_effective_scope + (если нужно) get_department_subtree +
    apply_scope_filter. Один вызов на endpoint, без копипасты.

    Использование в роутере:
        stmt = select(Lead).order_by(...)
        stmt = await scope_query(session, stmt, Lead, "lead", current_user)
        return (await session.execute(stmt)).scalars().all()
    """
    scope = await get_effective_scope(db, entity_type, user)
    dept_ids: list[int] | None = None
    if scope == "department_and_children":
        dept_ids = await get_department_subtree(db, user.department_id)
    return apply_scope_filter(stmt, entity_model, user, scope, dept_ids)


def check_object_scope(
    obj: Any,
    user: User,
    scope: str,
    dept_ids: list[int] | None = None,
) -> bool:
    """Pure-function: проходит ли уже загруженный объект под scope пользователя.

    Зеркалит логику apply_scope_filter, но для одного ORM-инстанса (item-эндпоинты
    GET /{id}, подресурсы). Возвращает True если объект виден пользователю.

    - scope='all' / неизвестный → True (бэквард-совместимо).
    - scope='personal' → owner-колонка объекта == user.id.
    - scope='department' → department_id объекта == user.department_id
      (или оба None).
    - scope='department_and_children' → department_id объекта ∈ dept_ids
      (caller передаёт subtree); пустой dept_ids → fallback на own department.

    Если у модели нет нужной колонки — True (как apply_scope_filter не фильтрует).

    P0 security (Unit 3a): fail-CLOSED. Зеркалит apply_scope_filter — битый/
    неизвестный scope сводится к 'personal' (не 'all'), а не открывает объект.
    """
    if scope == "all":
        return True
    if scope not in ALLOWED_SCOPES:
        # fail-CLOSED: битая настройка → самый узкий scope.
        scope = "personal"

    if scope == "personal":
        owner_val = _get_owner_value(obj)
        if owner_val is _NO_COLUMN:
            return True
        return owner_val == user.id

    if scope in ("department", "department_and_children"):
        if not hasattr(obj, "department_id"):
            # P2 (audit A3/PM follow-up): сущности без department-колонки (contract)
            # под department-scope раньше возвращали True (fail-OPEN — виден всем).
            # Fail-CLOSED: откатываемся на personal — объект виден только владельцу.
            owner_val = _get_owner_value(obj)
            if owner_val is _NO_COLUMN:
                return False
            return owner_val == user.id
        obj_dept = getattr(obj, "department_id", None)
        if scope == "department_and_children" and dept_ids:
            return obj_dept in dept_ids
        if user.department_id is None:
            return obj_dept is None
        return obj_dept == user.department_id

    return True


# Sentinel: модель не имеет owner-колонки (отличаем от owner_user_id=None).
_NO_COLUMN: Any = object()


def _get_owner_value(obj: Any) -> Any:
    """Значение owner-колонки инстанса (owner_user_id или legacy owner_id).

    Возвращает _NO_COLUMN если у объекта нет ни одной из колонок.
    """
    if hasattr(obj, "owner_user_id"):
        return getattr(obj, "owner_user_id")
    if hasattr(obj, "owner_id"):
        return getattr(obj, "owner_id")
    return _NO_COLUMN


# P0 security (Unit 3a): какие поля-владельцы дают доступ при scope='personal'
# для каждого entity_type. Объект виден, если ЛЮБОЕ из перечисленных полей ==
# user.id. Для company/counterparty/lead/subscription владелец один (покрывается
# обобщённым _get_owner_value), но мы перечисляем явно, чтобы:
#   - contract использовал author_user_id (у него нет owner_user_id/owner_id);
#   - activity мог принадлежать постановщику ИЛИ исполнителю (responsible_id).
# Если entity_type не в карте — используется обобщённый check_object_scope
# (owner_user_id/owner_id), что покрывает company/contact/etc.
_PERSONAL_OWNER_FIELDS: dict[str, tuple[str, ...]] = {
    "deal": ("owner_user_id",),
    "contract": ("author_user_id",),
    "activity": ("responsible_id", "created_by_id"),
    "company": ("owner_user_id",),
    "counterparty": ("owner_user_id",),
    "subscription": ("owner_user_id",),
    "lead": ("owner_id",),
    "contact": ("owner_id",),
}


def _object_in_personal_scope(obj: Any, entity_type: str, user: User) -> bool:
    """True если объект принадлежит пользователю (scope='personal').

    Использует явную карту полей-владельцев per entity_type. Для entity_type вне
    карты падает на обобщённый _get_owner_value (owner_user_id/owner_id). Если у
    объекта нет ни одной owner-колонки — True (как и обобщённый check_object_scope:
    не фильтруем модель без владельца).
    """
    fields = _PERSONAL_OWNER_FIELDS.get(entity_type)
    if fields is None:
        owner_val = _get_owner_value(obj)
        if owner_val is _NO_COLUMN:
            return True
        return owner_val == user.id
    present = [f for f in fields if hasattr(obj, f)]
    if not present:
        return True
    return any(getattr(obj, f, None) == user.id for f in present)


def _object_in_department_scope(
    obj: Any, user: User, scope: str, dept_ids: list[int] | None,
    entity_type: str | None = None,
) -> bool:
    """True если объект в department-scope пользователя (зеркало check_object_scope)."""
    if not hasattr(obj, "department_id"):
        # P2 (audit A3/PM follow-up): сущности без department-колонки (contract)
        # под department-scope раньше возвращали True (fail-OPEN — виден всем
        # ролям, у которых scope='department'). Fail-CLOSED: откатываемся на
        # personal (entity-aware: contract → author_user_id) — объект виден
        # только владельцу, а не всем.
        if entity_type is not None:
            return _object_in_personal_scope(obj, entity_type, user)
        owner_val = _get_owner_value(obj)
        if owner_val is _NO_COLUMN:
            return False
        return owner_val == user.id
    obj_dept = getattr(obj, "department_id", None)
    if scope == "department_and_children" and dept_ids:
        return obj_dept in dept_ids
    if user.department_id is None:
        return obj_dept is None
    return obj_dept == user.department_id


async def ensure_object_visible(
    db: AsyncSession,
    obj: Any,
    entity_type: str,
    user: User,
) -> None:
    """Item-эндпоинт guard: 404 если объект вне scope пользователя.

    Резолвит эффективный scope (admin → 'all'), при необходимости подтягивает
    subtree отделов, и сверяет уже загруженный объект.

    P0 security (Unit 3a):
    - Бросает **404** (а не 403), чтобы не палить существование чужой записи —
      единая convention горизонтального разграничения в CRM. Caller уже сделал
      свой 404 «не найдено», так что наружу всегда одинаковый 404.
    - entity-aware owner-резолвинг: deal=owner_user_id, contract=author_user_id,
      activity=responsible_id|created_by_id (см. _PERSONAL_OWNER_FIELDS). admin/
      director (scope='all') всегда проходят.

    Использование:
        deal = await _deal_or_404(session, deal_id)
        await ensure_object_visible(session, deal, "deal", current_user)
    """
    from fastapi import HTTPException

    scope = await get_effective_scope(db, entity_type, user)
    if scope == "all":
        return
    if scope not in ALLOWED_SCOPES:
        # fail-CLOSED: битая настройка → personal.
        scope = "personal"

    if scope == "personal":
        visible = _object_in_personal_scope(obj, entity_type, user)
    else:
        dept_ids: list[int] | None = None
        if scope == "department_and_children":
            dept_ids = await get_department_subtree(db, user.department_id)
        visible = _object_in_department_scope(obj, user, scope, dept_ids, entity_type)

    if not visible:
        raise HTTPException(404, "Объект не найден")
