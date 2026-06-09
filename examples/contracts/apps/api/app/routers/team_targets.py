"""Epic 10.5 — Team Targets CRUD (admin only).

GET/POST/PATCH/DELETE /api/admin/team-targets
"""
from __future__ import annotations

from decimal import Decimal
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser
from app.models import TeamTarget

router = APIRouter(prefix="/admin/team-targets", tags=["team-targets"])

VALID_METRICS = ("new_income_total", "ftm_count")


class TeamTargetOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    department_id: int | None
    pipeline_id: int | None
    period_year: int
    period_month: int
    metric: str
    target_amount: Decimal
    target_currency: str
    bonus_pool_amount: Decimal | None
    bonus_pool_currency: str | None
    bonus_per_additional_member: Decimal | None
    bonus_min_completion_pct: Decimal
    bonus_split_proportional_pct: Decimal
    bonus_split_equal_pct: Decimal
    is_active: bool


class TeamTargetIn(BaseModel):
    department_id: int | None = None
    pipeline_id: int | None = None
    period_year: int = Field(..., ge=2020, le=2100)
    period_month: int = Field(..., ge=1, le=12)
    metric: str = Field(default="new_income_total", max_length=32)
    target_amount: Decimal = Field(..., gt=0)
    target_currency: str = Field(default="RUB", max_length=8)
    bonus_pool_amount: Decimal | None = None
    bonus_pool_currency: str | None = Field(None, max_length=8)
    bonus_per_additional_member: Decimal | None = Decimal("100000")
    bonus_min_completion_pct: Decimal = Field(default=Decimal("80.00"))
    bonus_split_proportional_pct: Decimal = Field(default=Decimal("60.00"))
    bonus_split_equal_pct: Decimal = Field(default=Decimal("40.00"))
    is_active: bool = True


class TeamTargetPatch(BaseModel):
    target_amount: Decimal | None = Field(None, gt=0)
    target_currency: str | None = Field(None, max_length=8)
    bonus_pool_amount: Decimal | None = None
    bonus_pool_currency: str | None = Field(None, max_length=8)
    bonus_per_additional_member: Decimal | None = None
    bonus_min_completion_pct: Decimal | None = None
    bonus_split_proportional_pct: Decimal | None = None
    bonus_split_equal_pct: Decimal | None = None
    is_active: bool | None = None


@router.get("", response_model=list[TeamTargetOut])
async def list_team_targets(
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    year: int | None = Query(None),
    month: int | None = Query(None),
    department_id: int | None = Query(None),
) -> list[TeamTargetOut]:
    q = select(TeamTarget).order_by(
        TeamTarget.period_year.desc(), TeamTarget.period_month.desc()
    )
    if year:
        q = q.where(TeamTarget.period_year == year)
    if month:
        q = q.where(TeamTarget.period_month == month)
    if department_id:
        q = q.where(TeamTarget.department_id == department_id)
    return list((await session.execute(q)).scalars().all())


@router.get("/{target_id}", response_model=TeamTargetOut)
async def get_team_target(
    target_id: int,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> TeamTargetOut:
    row = (
        await session.execute(select(TeamTarget).where(TeamTarget.id == target_id))
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=404, detail="TeamTarget not found")
    return row


@router.post("", response_model=TeamTargetOut, status_code=status.HTTP_201_CREATED)
async def create_team_target(
    body: TeamTargetIn,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> TeamTargetOut:
    if body.metric not in VALID_METRICS:
        raise HTTPException(422, f"metric must be one of {VALID_METRICS}")

    row = TeamTarget(**body.model_dump())
    session.add(row)
    try:
        await session.commit()
    except Exception:
        await session.rollback()
        raise HTTPException(
            status_code=409,
            detail="TeamTarget for this department/pipeline/period/metric already exists",
        )
    await session.refresh(row)
    return row


@router.patch("/{target_id}", response_model=TeamTargetOut)
async def update_team_target(
    target_id: int,
    body: TeamTargetPatch,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> TeamTargetOut:
    row = (
        await session.execute(select(TeamTarget).where(TeamTarget.id == target_id))
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=404, detail="TeamTarget not found")
    for k, v in body.model_dump(exclude_none=True).items():
        setattr(row, k, v)
    await session.commit()
    await session.refresh(row)
    return row


@router.delete("/{target_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_team_target(
    target_id: int,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> None:
    row = (
        await session.execute(select(TeamTarget).where(TeamTarget.id == target_id))
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=404, detail="TeamTarget not found")
    await session.delete(row)
    await session.commit()
