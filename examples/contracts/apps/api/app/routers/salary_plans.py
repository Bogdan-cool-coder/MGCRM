"""Epic 10.5 — Salary Plans CRUD (admin only).

GET/POST/PATCH/DELETE /api/admin/salary-plans
"""
from __future__ import annotations

from datetime import UTC, datetime
from decimal import Decimal
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser
from app.models import SalaryPlan, User

router = APIRouter(prefix="/admin/salary-plans", tags=["salary-plans"])


class SalaryPlanOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    user_id: int
    period_year: int
    period_month: int
    supervisor_user_id: int | None
    base_salary_amount: Decimal
    base_salary_currency: str
    base_salary_payment_note: str | None
    commission_rule_id: int | None
    personal_income_plan_amount: Decimal | None
    personal_income_plan_currency: str | None
    personal_ftm_plan: int | None
    team_target_id: int | None
    created_at: datetime
    updated_at: datetime


class SalaryPlanIn(BaseModel):
    user_id: int
    period_year: int = Field(..., ge=2020, le=2100)
    period_month: int = Field(..., ge=1, le=12)
    supervisor_user_id: int | None = None
    base_salary_amount: Decimal = Field(..., gt=0)
    base_salary_currency: str = Field(..., max_length=8)
    base_salary_payment_note: str | None = None
    commission_rule_id: int | None = None
    personal_income_plan_amount: Decimal | None = None
    personal_income_plan_currency: str | None = None
    personal_ftm_plan: int | None = None
    team_target_id: int | None = None


class SalaryPlanPatch(BaseModel):
    supervisor_user_id: int | None = None
    base_salary_amount: Decimal | None = Field(None, gt=0)
    base_salary_currency: str | None = Field(None, max_length=8)
    base_salary_payment_note: str | None = None
    commission_rule_id: int | None = None
    personal_income_plan_amount: Decimal | None = None
    personal_income_plan_currency: str | None = None
    personal_ftm_plan: int | None = None
    team_target_id: int | None = None


@router.get("", response_model=list[SalaryPlanOut])
async def list_salary_plans(
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    user_id: int | None = Query(None),
    year: int | None = Query(None),
    month: int | None = Query(None),
) -> list[SalaryPlanOut]:
    q = select(SalaryPlan).order_by(
        SalaryPlan.period_year.desc(),
        SalaryPlan.period_month.desc(),
        SalaryPlan.user_id,
    )
    if user_id:
        q = q.where(SalaryPlan.user_id == user_id)
    if year:
        q = q.where(SalaryPlan.period_year == year)
    if month:
        q = q.where(SalaryPlan.period_month == month)
    return list((await session.execute(q)).scalars().all())


@router.get("/{plan_id}", response_model=SalaryPlanOut)
async def get_salary_plan(
    plan_id: int,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> SalaryPlanOut:
    row = (
        await session.execute(select(SalaryPlan).where(SalaryPlan.id == plan_id))
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=404, detail="SalaryPlan not found")
    return row


@router.post("", response_model=SalaryPlanOut, status_code=status.HTTP_201_CREATED)
async def create_salary_plan(
    body: SalaryPlanIn,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> SalaryPlanOut:
    user = (
        await session.execute(select(User).where(User.id == body.user_id))
    ).scalar_one_or_none()
    if user is None:
        raise HTTPException(status_code=404, detail="User not found")

    row = SalaryPlan(**body.model_dump())
    session.add(row)
    try:
        await session.commit()
    except Exception:
        await session.rollback()
        raise HTTPException(
            status_code=409,
            detail="SalaryPlan for this user/period already exists",
        )
    await session.refresh(row)
    return row


@router.patch("/{plan_id}", response_model=SalaryPlanOut)
async def update_salary_plan(
    plan_id: int,
    body: SalaryPlanPatch,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> SalaryPlanOut:
    row = (
        await session.execute(select(SalaryPlan).where(SalaryPlan.id == plan_id))
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=404, detail="SalaryPlan not found")

    for k, v in body.model_dump(exclude_unset=True).items():
        setattr(row, k, v)
    row.updated_at = datetime.now(tz=UTC)
    await session.commit()
    await session.refresh(row)
    return row


@router.delete("/{plan_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_salary_plan(
    plan_id: int,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> None:
    row = (
        await session.execute(select(SalaryPlan).where(SalaryPlan.id == plan_id))
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=404, detail="SalaryPlan not found")
    await session.delete(row)
    await session.commit()
