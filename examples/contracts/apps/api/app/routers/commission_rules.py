"""Epic 10.5 — Commission Rules CRUD (admin only).

GET/POST/PATCH/DELETE /api/admin/commission-rules
"""
from __future__ import annotations

from datetime import UTC, datetime
from decimal import Decimal
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser
from app.models import CommissionRule

router = APIRouter(prefix="/admin/commission-rules", tags=["commission-rules"])

VALID_BASE_METRICS = ("new_income_payments",)
VALID_SCOPES = ("personal_deals", "any_deal")


class CommissionRuleOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    rate_pct: Decimal
    base_metric: str
    base_currency: str
    scope: str
    applies_to_first_payment_only: bool
    requires_signed_contract: bool
    requires_amount_match_payment_plan: bool
    payment_trigger: str | None
    payment_note: str | None
    is_active: bool
    created_by_user_id: int | None


class CommissionRuleIn(BaseModel):
    name: str = Field(..., max_length=128)
    rate_pct: Decimal = Field(..., gt=0, le=100)
    base_metric: str = Field(default="new_income_payments", max_length=32)
    base_currency: str = Field(default="RUB", max_length=8)
    scope: str = Field(default="personal_deals", max_length=32)
    applies_to_first_payment_only: bool = True
    requires_signed_contract: bool = True
    requires_amount_match_payment_plan: bool = True
    payment_trigger: str | None = "immediate"
    payment_note: str | None = None
    is_active: bool = True


class CommissionRulePatch(BaseModel):
    name: str | None = Field(None, max_length=128)
    rate_pct: Decimal | None = Field(None, gt=0, le=100)
    base_currency: str | None = Field(None, max_length=8)
    scope: str | None = Field(None, max_length=32)
    applies_to_first_payment_only: bool | None = None
    requires_signed_contract: bool | None = None
    requires_amount_match_payment_plan: bool | None = None
    payment_trigger: str | None = None
    payment_note: str | None = None
    is_active: bool | None = None


@router.get("", response_model=list[CommissionRuleOut])
async def list_commission_rules(
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> list[CommissionRuleOut]:
    rows = list(
        (await session.execute(select(CommissionRule).order_by(CommissionRule.id))).scalars().all()
    )
    return rows


@router.get("/{rule_id}", response_model=CommissionRuleOut)
async def get_commission_rule(
    rule_id: int,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> CommissionRuleOut:
    row = (
        await session.execute(select(CommissionRule).where(CommissionRule.id == rule_id))
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=404, detail="CommissionRule not found")
    return row


@router.post("", response_model=CommissionRuleOut, status_code=status.HTTP_201_CREATED)
async def create_commission_rule(
    body: CommissionRuleIn,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> CommissionRuleOut:
    if body.base_metric not in VALID_BASE_METRICS:
        raise HTTPException(422, f"base_metric must be one of {VALID_BASE_METRICS}")
    if body.scope not in VALID_SCOPES:
        raise HTTPException(422, f"scope must be one of {VALID_SCOPES}")

    row = CommissionRule(
        **body.model_dump(),
        created_by_user_id=admin.id,
    )
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return row


@router.patch("/{rule_id}", response_model=CommissionRuleOut)
async def update_commission_rule(
    rule_id: int,
    body: CommissionRulePatch,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> CommissionRuleOut:
    row = (
        await session.execute(select(CommissionRule).where(CommissionRule.id == rule_id))
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=404, detail="CommissionRule not found")

    for k, v in body.model_dump(exclude_none=True).items():
        setattr(row, k, v)
    row.updated_at = datetime.now(tz=UTC)
    await session.commit()
    await session.refresh(row)
    return row


@router.delete("/{rule_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_commission_rule(
    rule_id: int,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> None:
    row = (
        await session.execute(select(CommissionRule).where(CommissionRule.id == rule_id))
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=404, detail="CommissionRule not found")
    await session.delete(row)
    await session.commit()
