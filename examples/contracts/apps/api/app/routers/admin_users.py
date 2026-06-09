"""Epic 14.2 — Admin endpoints для увольнения / восстановления + rights transfer audit.

Префикс /api/admin — все endpoints под AdminUser depend'ом.

Endpoints:
- POST /api/admin/users/{user_id}/dismiss — увольнение с auto-transfer прав
- POST /api/admin/users/{user_id}/restore — восстановление уволенного
- GET  /api/admin/users?status=dismissed|active|on_vacation — list по статусу
- POST /api/admin/rights-transfers — ручная передача без увольнения
- GET  /api/admin/rights-transfers — список операций с фильтрами
- GET  /api/admin/rights-transfers/{id} — детали + items
- POST /api/admin/rights-transfers/{id}/revert — undo (если reversible)
"""
from __future__ import annotations

from datetime import UTC, date, datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import desc
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select
from sqlalchemy.orm import aliased

from app.db import get_session
from app.deps import AdminUser
from app.models import (
    Department,
    RightsTransferItem,
    RightsTransferLog,
    User,
    UserRole,
)
from app.services.rights_transfer import (
    ALLOWED_CATEGORIES,
    is_reversible,
    revert_transfer,
    transfer_rights,
)

router = APIRouter(prefix="/admin", tags=["admin"])


# ============ Schemas ============


class DismissUserIn(BaseModel):
    substitute_user_id: int | None = None
    transfer_categories: list[str] = Field(default_factory=list)
    reason: str | None = None


class RestoreUserIn(BaseModel):
    new_role: UserRole | None = None


class RightsTransferCreateIn(BaseModel):
    from_user_id: int
    to_user_id: int
    categories: list[str]
    reason: str | None = None


class RightsTransferItemOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    entity_type: str
    entity_id: int
    field_name: str
    old_owner_user_id: int | None
    new_owner_user_id: int | None
    reverted_at: datetime | None


class RightsTransferLogOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    from_user_id: int | None
    to_user_id: int | None
    initiated_by_user_id: int | None
    categories: list[str]
    reason: str | None
    executed_at: datetime
    reversible_until: datetime | None
    is_reverted: bool
    reverted_at: datetime | None
    reverted_by_user_id: int | None


class RightsTransferLogWithItemsOut(RightsTransferLogOut):
    items: list[RightsTransferItemOut]


class UserAdminOut(BaseModel):
    """Расширенный UserOut с dismissal/employment fields для админки."""

    model_config = ConfigDict(from_attributes=True)
    id: int
    email: str
    full_name: str
    role: UserRole
    is_active: bool
    employment_status: str
    department_id: int | None
    manager_id: int | None
    substitute_user_id: int | None
    dismissed_at: datetime | None
    dismissed_by_user_id: int | None
    dismissed_reason: str | None
    created_at: datetime


# ============ Endpoints ============


@router.post(
    "/users/{user_id}/dismiss",
    response_model=UserAdminOut,
    status_code=status.HTTP_200_OK,
)
async def dismiss_user(
    user_id: int,
    payload: DismissUserIn,
    current_admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Увольнение сотрудника + auto-transfer прав.

    Атомарно:
    1. UPDATE users SET dismissed_at=NOW, dismissed_by, employment_status='dismissed',
       is_active=false, substitute_user_id=substitute
    2. Если transfer_categories — создать RightsTransferLog + items (через
       services.rights_transfer.transfer_rights).
    """
    if user_id == current_admin.id:
        raise HTTPException(400, "Нельзя уволить себя")

    user = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    if not user:
        raise HTTPException(404, "Пользователь не найден")
    if user.dismissed_at is not None:
        raise HTTPException(400, "Сотрудник уже уволен")

    # Если хотим transfer, проверим что substitute задан и существует
    substitute_user: User | None = None
    if payload.substitute_user_id is not None:
        substitute_user = (
            await session.execute(
                select(User).where(User.id == payload.substitute_user_id)
            )
        ).scalar_one_or_none()
        if substitute_user is None:
            raise HTTPException(400, "Заместитель не найден")
        if substitute_user.id == user.id:
            raise HTTPException(400, "Заместитель не может совпадать с увольняемым")

    # Валидация категорий ДО изменения users — чтобы при ошибке не оставить
    # юзера в полу-уволенном состоянии.
    if payload.transfer_categories:
        unknown = set(payload.transfer_categories) - ALLOWED_CATEGORIES
        if unknown:
            raise HTTPException(
                400,
                f"Неизвестные категории: {sorted(unknown)}. "
                f"Доступны: {sorted(ALLOWED_CATEGORIES)}",
            )
        if substitute_user is None:
            raise HTTPException(
                400,
                "Для передачи прав укажите substitute_user_id",
            )

    now = datetime.now(UTC)
    user.dismissed_at = now
    user.dismissed_by_user_id = current_admin.id
    user.dismissed_reason = payload.reason
    user.substitute_user_id = (
        substitute_user.id if substitute_user else None
    )
    user.employment_status = "dismissed"
    user.is_active = False

    # Transfer rights, если запрошено
    if payload.transfer_categories and substitute_user is not None:
        await transfer_rights(
            session,
            from_user_id=user.id,
            to_user_id=substitute_user.id,
            categories=payload.transfer_categories,
            initiated_by_user_id=current_admin.id,
            reason=payload.reason or "Auto-transfer at dismissal",
        )

    await session.commit()
    await session.refresh(user)
    return user


@router.post(
    "/users/{user_id}/restore",
    response_model=UserAdminOut,
    status_code=status.HTTP_200_OK,
)
async def restore_user(
    user_id: int,
    payload: RestoreUserIn,
    current_admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Восстановление уволенного сотрудника.

    Не делает auto-revert rights transfer (admin должен явно через
    /api/admin/rights-transfers/{id}/revert).
    """
    user = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    if not user:
        raise HTTPException(404, "Пользователь не найден")
    if user.dismissed_at is None:
        raise HTTPException(400, "Сотрудник не уволен")

    user.dismissed_at = None
    user.dismissed_by_user_id = None
    user.dismissed_reason = None
    # substitute_user_id оставляем как есть — это «постоянный» заместитель.
    user.employment_status = "active"
    user.is_active = True
    if payload.new_role is not None:
        user.role = payload.new_role

    # current_admin used only for audit log (не сохраняем явно, но id виден
    # через FastAPI dependency tree).
    _ = current_admin

    await session.commit()
    await session.refresh(user)
    return user


@router.get(
    "/users",
    response_model=list[UserAdminOut],
)
async def list_users_by_status(
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    status_filter: Annotated[
        str | None,
        Query(alias="status", description="active|dismissed|on_vacation"),
    ] = None,
):
    """Список юзеров с фильтром по employment_status.

    status=dismissed — только уволенные
    status=active — только активные
    status=on_vacation — в отпуске
    Без фильтра — все.
    """
    stmt = select(User).order_by(User.full_name)
    if status_filter is not None:
        if status_filter not in ("active", "dismissed", "on_vacation"):
            raise HTTPException(
                400, "status must be one of: active, dismissed, on_vacation",
            )
        stmt = stmt.where(User.employment_status == status_filter)
    return (await session.execute(stmt)).scalars().all()


# ============ Employees list (Epic 14.2 — /company/employees page) ============
#
# Расширенный listing с denormalized department_name / manager_name /
# substitute_name (один SQL с тремя outer joins, без N+1). Используется в
# apps/web/src/components/Company/EmployeesTable.tsx — там идёт фильтр по
# status (active|on_vacation|dismissed|all) + поиск по имени/email + фильтр
# по department локально на фронте.
#
# Зачем отдельный endpoint от GET /admin/users:
# - GET /admin/users возвращает UserAdminOut без department/manager/substitute
#   names — фронту пришлось бы делать N+1 запрос (по каждому юзеру отдельный
#   GET для department/manager). Здесь one-shot select + 3 outer join.


class EmployeeListItemOut(BaseModel):
    """Расширенный listing сотрудника для /company/employees.

    Формат синхронизирован с EmployeeListItem на фронте (apps/web/src/lib/
    types.ts). Включает denormalized имена department / manager / substitute,
    избегая N+1.
    """

    model_config = ConfigDict(from_attributes=True)
    id: int
    full_name: str
    email: str
    role: UserRole
    avatar_path: str | None = None
    department_id: int | None = None
    department_name: str | None = None
    manager_id: int | None = None
    manager_name: str | None = None
    employment_status: str
    substitute_user_id: int | None = None
    substitute_name: str | None = None
    dismissed_at: datetime | None = None
    dismissed_reason: str | None = None


@router.get(
    "/users/employees",
    response_model=list[EmployeeListItemOut],
)
async def list_employees(
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    status_filter: Annotated[
        str | None,
        Query(alias="status", description="active|dismissed|on_vacation"),
    ] = None,
):
    """Расширенный listing сотрудников для страницы /company/employees.

    Возвращает все поля для табличного отображения (включая denormalized
    department_name / manager_name / substitute_name) — без N+1.

    status:
    - active — только активные (employment_status='active')
    - dismissed — только уволенные
    - on_vacation — в отпуске
    - не задано (или 'all' на фронте, который в этом случае дропает query
      param) — все сотрудники
    """
    if status_filter is not None and status_filter not in (
        "active",
        "dismissed",
        "on_vacation",
    ):
        raise HTTPException(
            400, "status must be one of: active, dismissed, on_vacation",
        )

    # Aliased для двух LEFT JOIN на users (manager + substitute).
    manager = aliased(User)
    substitute = aliased(User)

    stmt = (
        select(
            User.id,
            User.full_name,
            User.email,
            User.role,
            User.avatar_path,
            User.department_id,
            Department.name.label("department_name"),
            User.manager_id,
            manager.full_name.label("manager_name"),
            User.employment_status,
            User.substitute_user_id,
            substitute.full_name.label("substitute_name"),
            User.dismissed_at,
            User.dismissed_reason,
        )
        .outerjoin(Department, Department.id == User.department_id)
        .outerjoin(manager, manager.id == User.manager_id)
        .outerjoin(substitute, substitute.id == User.substitute_user_id)
        .order_by(User.full_name)
    )
    if status_filter is not None:
        stmt = stmt.where(User.employment_status == status_filter)

    rows = (await session.execute(stmt)).all()
    return [
        EmployeeListItemOut(
            id=r.id,
            full_name=r.full_name,
            email=r.email,
            role=r.role,
            avatar_path=r.avatar_path,
            department_id=r.department_id,
            department_name=r.department_name,
            manager_id=r.manager_id,
            manager_name=r.manager_name,
            employment_status=r.employment_status,
            substitute_user_id=r.substitute_user_id,
            substitute_name=r.substitute_name,
            dismissed_at=r.dismissed_at,
            dismissed_reason=r.dismissed_reason,
        )
        for r in rows
    ]


# ============ Rights transfer endpoints ============


@router.post(
    "/rights-transfers",
    response_model=RightsTransferLogWithItemsOut,
    status_code=status.HTTP_201_CREATED,
)
async def create_rights_transfer(
    payload: RightsTransferCreateIn,
    current_admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Ручная передача прав без увольнения. Создаёт log + items + UPDATE entity."""
    if payload.from_user_id == payload.to_user_id:
        raise HTTPException(400, "from_user_id и to_user_id не должны совпадать")

    # Проверка существования юзеров
    from_user = (
        await session.execute(
            select(User).where(User.id == payload.from_user_id)
        )
    ).scalar_one_or_none()
    to_user = (
        await session.execute(
            select(User).where(User.id == payload.to_user_id)
        )
    ).scalar_one_or_none()
    if from_user is None or to_user is None:
        raise HTTPException(404, "from_user_id или to_user_id не найден")

    unknown = set(payload.categories) - ALLOWED_CATEGORIES
    if unknown:
        raise HTTPException(
            400,
            f"Неизвестные категории: {sorted(unknown)}. "
            f"Доступны: {sorted(ALLOWED_CATEGORIES)}",
        )
    if not payload.categories:
        raise HTTPException(400, "categories не должно быть пустым")

    log = await transfer_rights(
        session,
        from_user_id=payload.from_user_id,
        to_user_id=payload.to_user_id,
        categories=payload.categories,
        initiated_by_user_id=current_admin.id,
        reason=payload.reason,
    )
    await session.commit()

    # Подтянуть items для ответа
    items = list(
        (
            await session.execute(
                select(RightsTransferItem).where(
                    RightsTransferItem.transfer_log_id == log.id
                )
            )
        )
        .scalars()
        .all()
    )
    out = RightsTransferLogWithItemsOut.model_validate({
        **{f: getattr(log, f) for f in RightsTransferLogOut.model_fields},
        "items": [RightsTransferItemOut.model_validate(i) for i in items],
    })
    return out


@router.get(
    "/rights-transfers",
    response_model=list[RightsTransferLogOut],
)
async def list_rights_transfers(
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    from_user_id: int | None = None,
    to_user_id: int | None = None,
    after_date: date | None = None,
    transfer_status: Annotated[
        str | None,
        Query(alias="status", description="reversible|reverted|all"),
    ] = None,
):
    """Лог операций передачи прав с фильтрами."""
    stmt = select(RightsTransferLog).order_by(desc(RightsTransferLog.executed_at))

    if from_user_id is not None:
        stmt = stmt.where(RightsTransferLog.from_user_id == from_user_id)
    if to_user_id is not None:
        stmt = stmt.where(RightsTransferLog.to_user_id == to_user_id)
    if after_date is not None:
        stmt = stmt.where(
            RightsTransferLog.executed_at >= datetime.combine(
                after_date, datetime.min.time(), tzinfo=UTC,
            )
        )
    if transfer_status == "reverted":
        stmt = stmt.where(RightsTransferLog.is_reverted.is_(True))
    elif transfer_status == "reversible":
        now = datetime.now(UTC)
        stmt = stmt.where(
            RightsTransferLog.is_reverted.is_(False),
            RightsTransferLog.reversible_until >= now,
        )

    return (await session.execute(stmt)).scalars().all()


@router.get(
    "/rights-transfers/{transfer_id}",
    response_model=RightsTransferLogWithItemsOut,
)
async def get_rights_transfer(
    transfer_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Детали операции + список items."""
    log = (
        await session.execute(
            select(RightsTransferLog).where(RightsTransferLog.id == transfer_id)
        )
    ).scalar_one_or_none()
    if log is None:
        raise HTTPException(404, "Transfer log не найден")

    items = list(
        (
            await session.execute(
                select(RightsTransferItem).where(
                    RightsTransferItem.transfer_log_id == log.id
                )
            )
        )
        .scalars()
        .all()
    )
    return RightsTransferLogWithItemsOut.model_validate({
        **{f: getattr(log, f) for f in RightsTransferLogOut.model_fields},
        "items": [RightsTransferItemOut.model_validate(i) for i in items],
    })


@router.post(
    "/rights-transfers/{transfer_id}/revert",
    response_model=RightsTransferLogOut,
)
async def revert_rights_transfer(
    transfer_id: int,
    current_admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Откат операции (если в окне reversible_until)."""
    try:
        log = await revert_transfer(
            session,
            log_id=transfer_id,
            reverted_by_user_id=current_admin.id,
        )
    except ValueError as e:
        msg = str(e)
        if "not found" in msg.lower():
            raise HTTPException(404, msg)
        raise HTTPException(400, msg)
    await session.commit()
    return log
