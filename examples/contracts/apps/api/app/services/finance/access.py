"""Права финмодуля — `fin_can` (Ф0, инвариант ролей J §7).

Матрица `fin_permission(role|user_id, legal_entity_id, capability, allowed)`:
приоритет user-override > role-дефолт; legal_entity-specific > для-всех (NULL).
admin — суперправо (через существующий access_control, плюс сид all-caps).

Чистое ядро `can_from_rows` тестируется без БД (передаём список строк-правил как
duck-typed dict). Async `fin_can` читает fin_permission + дефолты из seed_data.
"""

from __future__ import annotations

from dataclasses import dataclass

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import FinLegalEntity, FinPermission, User
from app.services.finance.seed_data import ROLE_PERMISSIONS


@dataclass
class PermissionRow:
    """Строка правила прав (duck-typed для pure-тестов)."""

    role: str | None
    user_id: int | None
    legal_entity_id: int | None
    capability: str
    allowed: bool


def role_default(role: str | None, capability: str) -> bool:
    """Дефолт права по роли из канонического сида (J §7.3). admin → всё. Pure."""
    if role is None:
        return False
    table = ROLE_PERMISSIONS.get(role)
    if table is None:
        return False
    return bool(table.get(capability, False))


def _scope_rank(row_le: int | None, legal_entity_id: int | None) -> int:
    """Специфичность scope: точное юрлицо (2) > для-всех NULL (1); не совпало → -1."""
    if row_le is None:
        return 1
    if legal_entity_id is not None and row_le == legal_entity_id:
        return 2
    return -1


def can_from_rows(
    *,
    role: str | None,
    user_id: int | None,
    capability: str,
    legal_entity_id: int | None,
    rows: list[PermissionRow],
) -> bool:
    """Решение по матрице. Pure. Приоритет: user-override > role; внутри — точное
    юрлицо > NULL-для-всех. Если override отсутствует — дефолт роли из сида.

    Алгоритм: среди применимых строк (по role/user и scope) берём с максимальным
    «весом» (user>role, точное-юрлицо>NULL); её `allowed` — ответ. Нет строк → дефолт.
    """
    best_weight = -1
    best_allowed: bool | None = None
    for r in rows:
        if r.capability != capability:
            continue
        scope = _scope_rank(r.legal_entity_id, legal_entity_id)
        if scope < 0:
            continue
        # принадлежность: user-override (вес 10) ИЛИ role-override (вес 0)
        if r.user_id is not None and user_id is not None and r.user_id == user_id:
            actor_weight = 10
        elif r.user_id is None and r.role is not None and r.role == role:
            actor_weight = 0
        else:
            continue
        weight = actor_weight + scope
        if weight > best_weight:
            best_weight = weight
            best_allowed = r.allowed

    if best_allowed is not None:
        return best_allowed
    return role_default(role, capability)


async def fin_can(
    session: AsyncSession,
    user: User,
    capability: str,
    *,
    legal_entity_id: int | None = None,
) -> bool:
    """Может ли user выполнить capability (опц. в разрезе юрлица).

    admin — всегда True (суперправо). Иначе — матрица fin_permission поверх дефолтов
    ролей. Читает только релевантные строки (для роли/пользователя, scope NULL|=LE).
    """
    role = user.role.value if user.role is not None else None
    if role == "admin":
        return True

    cond = [FinPermission.capability == capability]
    rows_orm = (
        await session.execute(
            select(FinPermission).where(
                *cond,
                ((FinPermission.user_id == user.id) | (FinPermission.role == role)),
                (
                    FinPermission.legal_entity_id.is_(None)
                    if legal_entity_id is None
                    else (
                        (FinPermission.legal_entity_id.is_(None))
                        | (FinPermission.legal_entity_id == legal_entity_id)
                    )
                ),
            )
        )
    ).scalars().all()

    rows = [
        PermissionRow(
            role=r.role,
            user_id=r.user_id,
            legal_entity_id=r.legal_entity_id,
            capability=r.capability,
            allowed=r.allowed,
        )
        for r in rows_orm
    ]
    return can_from_rows(
        role=role,
        user_id=user.id,
        capability=capability,
        legal_entity_id=legal_entity_id,
        rows=rows,
    )


def accessible_entities_from_rows(
    *,
    role: str | None,
    user_id: int | None,
    capability: str,
    entity_ids: list[int],
    rows: list[PermissionRow],
) -> list[int] | None:
    """Множество юрлиц, доступных по capability. Pure.

    Возвращает `None` если ограничения НЕТ (право выдано на scope=NULL «для всех
    юрлиц» — пользователь видит всё; так сейчас работают все фин-роли). Иначе —
    явный список id юрлиц, к которым доступ разрешён (может быть пустым).

    Defense-in-depth для list-эндпоинтов: пока все права NULL-scope, вернётся None и
    поведение не меняется; как только появятся per-entity права (Ф2), выборка
    автоматически ограничится доступными юрлицами.
    """
    # NULL-scope grant → доступ ко всем юрлицам → ограничение не нужно.
    if can_from_rows(
        role=role,
        user_id=user_id,
        capability=capability,
        legal_entity_id=None,
        rows=rows,
    ):
        return None
    return [
        le_id
        for le_id in entity_ids
        if can_from_rows(
            role=role,
            user_id=user_id,
            capability=capability,
            legal_entity_id=le_id,
            rows=rows,
        )
    ]


async def accessible_legal_entity_ids(
    session: AsyncSession,
    user: User,
    capability: str,
) -> list[int] | None:
    """Список id юрлиц, доступных user по capability (для scope read-листингов).

    `None` — ограничения нет (admin или NULL-scope grant; видно всё). Иначе — явный
    список доступных юрлиц. Применять в list-эндпоинтах когда `legal_entity_id` в
    query не задан: `if scope is not None: stmt = stmt.where(col.in_(scope))`.
    """
    role = user.role.value if user.role is not None else None
    if role == "admin":
        return None

    entity_ids = list(
        (await session.execute(select(FinLegalEntity.id))).scalars().all()
    )
    rows_orm = (
        await session.execute(
            select(FinPermission).where(
                FinPermission.capability == capability,
                ((FinPermission.user_id == user.id) | (FinPermission.role == role)),
            )
        )
    ).scalars().all()
    rows = [
        PermissionRow(
            role=r.role,
            user_id=r.user_id,
            legal_entity_id=r.legal_entity_id,
            capability=r.capability,
            allowed=r.allowed,
        )
        for r in rows_orm
    ]
    return accessible_entities_from_rows(
        role=role,
        user_id=user.id,
        capability=capability,
        entity_ids=entity_ids,
        rows=rows,
    )
