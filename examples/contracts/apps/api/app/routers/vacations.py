"""Epic 14.2 — Vacations: отпуска / больничные / отгулы / командировки.

Endpoints:
- POST   /api/me/vacations
- GET    /api/me/vacations
- PATCH  /api/me/vacations/{id}
- DELETE /api/me/vacations/{id}
- POST   /api/me/vacations/{id}/approve   (manager/admin)
- GET    /api/admin/vacations/calendar    (общая карта)
"""
from __future__ import annotations

from datetime import UTC, date, datetime
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict
from sqlalchemy import and_, or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser, DirectorOrAdmin
from app.models import User, UserRole, UserVacation

router = APIRouter(tags=["vacations"])

ALLOWED_VACATION_TYPES = frozenset(
    {"vacation", "sick_leave", "day_off", "business_trip"}
)


# ============ Schemas ============


class VacationCreateIn(BaseModel):
    start_date: date
    end_date: date
    vacation_type: str = "vacation"
    substitute_user_id: int | None = None
    notes: str | None = None


class VacationPatchIn(BaseModel):
    start_date: date | None = None
    end_date: date | None = None
    vacation_type: str | None = None
    substitute_user_id: int | None = None
    notes: str | None = None


class VacationOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    user_id: int
    start_date: date
    end_date: date
    vacation_type: str
    substitute_user_id: int | None
    notes: str | None
    approved_by_user_id: int | None
    approved_at: datetime | None
    created_at: datetime


# ============ Personal endpoints ============


def _validate_dates(start: date, end: date) -> None:
    if end < start:
        raise HTTPException(400, "end_date должно быть не раньше start_date")


def _validate_type(t: str) -> None:
    if t not in ALLOWED_VACATION_TYPES:
        raise HTTPException(
            400,
            f"vacation_type должно быть одним из {sorted(ALLOWED_VACATION_TYPES)}",
        )


@router.post(
    "/me/vacations",
    response_model=VacationOut,
    status_code=status.HTTP_201_CREATED,
)
async def create_my_vacation(
    payload: VacationCreateIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создание отпуска.

    Auto-approve только для admin (глобальная роль). Director теперь НЕ
    подписывает себе отпуск сам (A2 WARNING — разделение обязанностей):
    его заявка идёт pending и требует независимого аппрува. Остальным —
    тоже pending; approve через POST /api/me/vacations/{id}/approve.
    """
    _validate_dates(payload.start_date, payload.end_date)
    _validate_type(payload.vacation_type)

    auto_approve = current_user.role == UserRole.admin

    vac = UserVacation(
        user_id=current_user.id,
        start_date=payload.start_date,
        end_date=payload.end_date,
        vacation_type=payload.vacation_type,
        substitute_user_id=payload.substitute_user_id,
        notes=payload.notes,
        approved_by_user_id=current_user.id if auto_approve else None,
        approved_at=datetime.now(UTC) if auto_approve else None,
    )
    session.add(vac)
    await session.commit()
    await session.refresh(vac)
    return vac


@router.get(
    "/me/vacations",
    response_model=list[VacationOut],
)
async def list_my_vacations(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Мои отпуска (по убыванию даты начала)."""
    res = await session.execute(
        select(UserVacation)
        .where(UserVacation.user_id == current_user.id)
        .order_by(UserVacation.start_date.desc())
    )
    return res.scalars().all()


def is_in_manager_chain_pure(
    manager_of: dict[int, int | None], *, employee_id: int, approver_id: int,
) -> bool:
    """Pure: approver_id есть в цепочке руководителей employee_id?

    `manager_of` — отображение user_id → manager_id (или None). Поднимаемся
    вверх; `seen` защищает от зацикливания на битых данных.
    """
    cur: int | None = employee_id
    seen: set[int] = set()
    while cur is not None and cur not in seen:
        seen.add(cur)
        mgr_id = manager_of.get(cur)
        if mgr_id == approver_id:
            return True
        cur = mgr_id
    return False


async def _is_in_manager_chain(
    session: AsyncSession, *, employee_id: int, approver_id: int,
) -> bool:
    """True если approver_id находится в цепочке руководителей employee_id.

    Поднимаемся вверх по manager_id от сотрудника. Защита от циклов через
    `seen` (на случай битых данных до фикса в assign_user_department).
    """
    cur: int | None = employee_id
    seen: set[int] = set()
    while cur is not None and cur not in seen:
        seen.add(cur)
        mgr_id = (
            await session.execute(
                select(User.manager_id).where(User.id == cur)
            )
        ).scalar_one_or_none()
        if mgr_id == approver_id:
            return True
        cur = mgr_id
    return False


async def _get_vac_or_403(
    session: AsyncSession, vacation_id: int, current_user: User,
) -> UserVacation:
    vac = (
        await session.execute(
            select(UserVacation).where(UserVacation.id == vacation_id)
        )
    ).scalar_one_or_none()
    if vac is None:
        raise HTTPException(404, "Отпуск не найден")
    is_owner = vac.user_id == current_user.id
    is_admin = current_user.role == UserRole.admin
    if not is_owner and not is_admin:
        raise HTTPException(403, "Доступ только владельцу или администратору")
    return vac


@router.patch(
    "/me/vacations/{vacation_id}",
    response_model=VacationOut,
)
async def patch_my_vacation(
    vacation_id: int,
    payload: VacationPatchIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Patch отпуска. Доступ — owner или admin."""
    vac = await _get_vac_or_403(session, vacation_id, current_user)

    if payload.start_date is not None:
        vac.start_date = payload.start_date
    if payload.end_date is not None:
        vac.end_date = payload.end_date
    _validate_dates(vac.start_date, vac.end_date)

    if payload.vacation_type is not None:
        _validate_type(payload.vacation_type)
        vac.vacation_type = payload.vacation_type

    if payload.substitute_user_id is not None:
        # пустое (0/None) — допустим, очистка
        vac.substitute_user_id = (
            payload.substitute_user_id if payload.substitute_user_id else None
        )
    if payload.notes is not None:
        vac.notes = payload.notes or None

    await session.commit()
    await session.refresh(vac)
    return vac


@router.delete(
    "/me/vacations/{vacation_id}",
    status_code=status.HTTP_204_NO_CONTENT,
)
async def delete_my_vacation(
    vacation_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удаление отпуска. Доступ — owner или admin."""
    vac = await _get_vac_or_403(session, vacation_id, current_user)
    await session.delete(vac)
    await session.commit()


@router.post(
    "/me/vacations/{vacation_id}/approve",
    response_model=VacationOut,
)
async def approve_vacation(
    vacation_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Approve чужого pending отпуска.

    Permissions: director/admin.
    """
    vac = (
        await session.execute(
            select(UserVacation).where(UserVacation.id == vacation_id)
        )
    ).scalar_one_or_none()
    if vac is None:
        raise HTTPException(404, "Отпуск не найден")
    if vac.approved_at is not None:
        raise HTTPException(400, "Уже одобрено")

    # A2 WARNING: разделение обязанностей. Director не может сам себе подписать
    # отпуск — нужен независимый аппрувер. Admin — глобальный, аппрувит любого.
    if current_user.role != UserRole.admin:
        if vac.user_id == current_user.id:
            raise HTTPException(
                403, "Нельзя самостоятельно одобрить собственный отпуск",
            )
        # Director аппрувит только своих подчинённых (по цепочке manager_id).
        in_chain = await _is_in_manager_chain(
            session, employee_id=vac.user_id, approver_id=current_user.id,
        )
        if not in_chain:
            raise HTTPException(
                403, "Можно одобрять отпуска только своих подчинённых",
            )

    vac.approved_by_user_id = current_user.id
    vac.approved_at = datetime.now(UTC)
    await session.commit()
    await session.refresh(vac)
    return vac


# ============ Admin: company calendar ============


@router.get(
    "/admin/vacations/calendar",
    response_model=list[VacationOut],
)
async def vacations_calendar(
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    date_from: date,
    date_to: date,
    department_id: int | None = None,
):
    """Карта отпусков по компании на диапазон дат.

    Возвращает все отпуска, чьи интервалы пересекаются с [date_from; date_to].
    Опциональный department_id фильтрует по User.department_id.
    """
    if date_to < date_from:
        raise HTTPException(400, "date_to must be >= date_from")

    # Пересечение: vac.start <= date_to AND vac.end >= date_from
    stmt = (
        select(UserVacation)
        .where(
            UserVacation.start_date <= date_to,
            UserVacation.end_date >= date_from,
        )
        .order_by(UserVacation.start_date)
    )
    if department_id is not None:
        # join по User для фильтра department_id
        stmt = stmt.join(User, User.id == UserVacation.user_id).where(
            User.department_id == department_id
        )
    return (await session.execute(stmt)).scalars().all()
