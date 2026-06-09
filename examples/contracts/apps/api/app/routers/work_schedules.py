"""Epic 14.2 — Work schedules: расписание per department/user + available slots.

Endpoints:
- GET   /api/admin/work-schedules?scope_type=&scope_id=
- PATCH /api/admin/work-schedules/bulk
- GET   /api/users/{user_id}/available-slots?date_from=&date_to=
- GET   /api/users/team-available-slots?user_ids=&date_from=&date_to=
"""
from __future__ import annotations

from datetime import date, time
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import WorkSchedule
from app.services.work_schedule import (
    compute_available_slots,
    compute_team_available_slots,
)

router = APIRouter(tags=["work-schedules"])

ALLOWED_SCOPE_TYPES = frozenset({"department", "user"})


# ============ Schemas ============


class WorkScheduleOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    scope_type: str
    scope_id: int
    day_of_week: int
    is_working: bool
    start_time: time | None
    end_time: time | None
    meeting_slot_minutes: int


class WorkScheduleBulkItem(BaseModel):
    day_of_week: int = Field(ge=0, le=6)
    is_working: bool = True
    start_time: time | None = None
    end_time: time | None = None
    meeting_slot_minutes: int = Field(default=30, ge=5, le=480)


class WorkScheduleBulkIn(BaseModel):
    scope_type: str
    scope_id: int
    items: list[WorkScheduleBulkItem]


class SlotOut(BaseModel):
    start_at: str
    end_at: str


# ============ Admin endpoints ============


@router.get(
    "/admin/work-schedules",
    response_model=list[WorkScheduleOut],
)
async def list_work_schedules(
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    scope_type: str | None = None,
    scope_id: int | None = None,
):
    """Список расписаний с фильтрами scope_type / scope_id."""
    stmt = select(WorkSchedule).order_by(
        WorkSchedule.scope_type, WorkSchedule.scope_id, WorkSchedule.day_of_week,
    )
    if scope_type is not None:
        if scope_type not in ALLOWED_SCOPE_TYPES:
            raise HTTPException(400, "scope_type must be department or user")
        stmt = stmt.where(WorkSchedule.scope_type == scope_type)
    if scope_id is not None:
        stmt = stmt.where(WorkSchedule.scope_id == scope_id)
    return (await session.execute(stmt)).scalars().all()


@router.patch(
    "/admin/work-schedules/bulk",
    response_model=list[WorkScheduleOut],
)
async def bulk_upsert_work_schedules(
    payload: WorkScheduleBulkIn,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Bulk-upsert: для заданного scope перезаписывает расписание по дням.

    Не удаляет старые записи, не входящие в payload (если payload не
    покрывает все дни — оставшиеся остаются как есть). Если нужно
    «дни не в payload = выходной» — фронт должен слать полное расписание.
    """
    if payload.scope_type not in ALLOWED_SCOPE_TYPES:
        raise HTTPException(400, "scope_type must be department or user")

    affected: list[WorkSchedule] = []
    for item in payload.items:
        existing = (
            await session.execute(
                select(WorkSchedule).where(
                    WorkSchedule.scope_type == payload.scope_type,
                    WorkSchedule.scope_id == payload.scope_id,
                    WorkSchedule.day_of_week == item.day_of_week,
                )
            )
        ).scalar_one_or_none()
        if existing is not None:
            existing.is_working = item.is_working
            existing.start_time = item.start_time
            existing.end_time = item.end_time
            existing.meeting_slot_minutes = item.meeting_slot_minutes
            affected.append(existing)
        else:
            new = WorkSchedule(
                scope_type=payload.scope_type,
                scope_id=payload.scope_id,
                day_of_week=item.day_of_week,
                is_working=item.is_working,
                start_time=item.start_time,
                end_time=item.end_time,
                meeting_slot_minutes=item.meeting_slot_minutes,
            )
            session.add(new)
            affected.append(new)
    await session.commit()
    for s in affected:
        await session.refresh(s)
    return affected


# ============ Available slots endpoints ============


@router.get(
    "/users/{user_id}/available-slots",
    response_model=list[SlotOut],
)
async def user_available_slots(
    user_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    date_from: date,
    date_to: date,
    country_code: str = "RU",
    slot_minutes: int | None = None,
):
    """Свободные слоты для встречи c юзером на диапазоне дат.

    Любой авторизованный пользователь может смотреть слоты любого юзера
    (для распределения задач, бронирования встреч).
    """
    if date_to < date_from:
        raise HTTPException(400, "date_to must be >= date_from")
    slots = await compute_available_slots(
        session, user_id, date_from, date_to,
        country_code=country_code,
        slot_minutes_override=slot_minutes,
    )
    return [SlotOut(**s.to_dict()) for s in slots]


@router.get(
    "/users/team-available-slots",
)
async def team_available_slots(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    user_ids: Annotated[
        list[int],
        Query(description="Список user_id (повторите параметр)"),
    ],
    date_from: date,
    date_to: date,
    country_code: str = "RU",
    slot_minutes: int = 30,
):
    """Слоты для команды (dict {user_id: [slots]}).

    Используется для динамического распределения задач — UI может
    подсветить, кто свободен в нужный временной слот.
    """
    if date_to < date_from:
        raise HTTPException(400, "date_to must be >= date_from")
    if not user_ids:
        return {}
    by_uid = await compute_team_available_slots(
        session, user_ids, date_from, date_to,
        country_code=country_code,
        slot_minutes=slot_minutes,
    )
    return {
        str(uid): [SlotOut(**s.to_dict()).model_dump() for s in slots]
        for uid, slots in by_uid.items()
    }
