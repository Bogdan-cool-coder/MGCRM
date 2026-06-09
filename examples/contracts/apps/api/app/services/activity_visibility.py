"""P0 security (Unit 3a, C1) — видимость активностей/задач.

`activities.py` исторически не применял ни scope_to_user, ни owner-guard ни в
одном из 37 эндпоинтов — массовый IDOR. Этот модуль даёт переиспользуемые
helpers:

- `assert_target_visible(session, user, target_type, target_id)` — резолвит
  целевую CRM-сущность и прогоняет её через ensure_object_visible (404 на чужую).
  Видимость Timeline/list по target наследуется от видимости самого target.
- `activity_owner_scope_clause(user)` — SQL-предикат «активность касается меня»
  для list-эндпоинта (responsible_id | created_by_id | collaborator), кроме
  elevated-ролей.
- `can_mutate_activity(activity, user)` — может ли пользователь менять задачу
  (постановщик/исполнитель/admin/director).

Контакт/компания/сделка/контракт/подписка/лид — owner-резолвинг внутри
ensure_object_visible (см. _PERSONAL_OWNER_FIELDS в access_control).
"""
from __future__ import annotations

from sqlalchemy import or_, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import Activity, ActivityCollaborator, User, UserRole
from app.services.access_control import ensure_object_visible
from app.services.activities import _TARGET_MODEL_MAP

# Роли, которые видят/правят всё (наследует список из owner-scope политики).
_ACTIVITY_ELEVATED_ROLES: frozenset[UserRole] = frozenset(
    {UserRole.admin, UserRole.director}
)


def is_elevated(user: User) -> bool:
    return user.role in _ACTIVITY_ELEVATED_ROLES


async def assert_target_visible(
    session: AsyncSession,
    user: User,
    target_type: str,
    target_id: int,
) -> None:
    """Бросает 404, если пользователь не видит целевую сущность активности.

    Для elevated-ролей (admin/director) — всегда проходит (ensure_object_visible
    сам это учитывает через scope='all'). Загружает target по target_type и
    делегирует owner-scope в ensure_object_visible. Если target_type неизвестен —
    тихо пропускает (валидируется отдельно в роутере).
    """
    from fastapi import HTTPException

    model = _TARGET_MODEL_MAP.get(target_type)
    if model is None:
        return
    obj = (
        await session.execute(select(model).where(model.id == target_id))
    ).scalar_one_or_none()
    if obj is None:
        raise HTTPException(404, "Объект не найден")
    await ensure_object_visible(session, obj, target_type, user)


def activity_owner_scope_clause(user: User):
    """SQL-предикат «активность касается меня» для list-скоупинга.

    Возвращает None для elevated-ролей (без фильтра). Иначе — OR по
    responsible_id / created_by_id / membership в коллабораторах.
    """
    if is_elevated(user):
        return None
    return or_(
        Activity.responsible_id == user.id,
        Activity.created_by_id == user.id,
        Activity.id.in_(
            select(ActivityCollaborator.activity_id).where(
                ActivityCollaborator.user_id == user.id
            )
        ),
    )


def can_mutate_activity(activity: Activity, user: User) -> bool:
    """Может ли пользователь менять задачу (постановщик/исполнитель/elevated)."""
    if is_elevated(user):
        return True
    return user.id in (activity.created_by_id, activity.responsible_id)


def assert_can_mutate_activity(activity: Activity, user: User) -> None:
    """Guard-обёртка: 404 если нет прав менять задачу (не палим существование)."""
    from fastapi import HTTPException

    if not can_mutate_activity(activity, user):
        raise HTTPException(404, "Задача не найдена")
