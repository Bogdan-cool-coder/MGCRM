"""Настройки системы (key/value). Сейчас — лимит скидки для менеджера."""
from __future__ import annotations

from decimal import Decimal
from typing import Annotated

from fastapi import APIRouter, Depends
from sqlalchemy.ext.asyncio import AsyncSession

from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin
from app.schemas import DiscountSettingIn, DiscountSettingOut
from app.services.pricing import get_manager_max_discount, set_manager_max_discount

router = APIRouter(prefix="/settings", tags=["settings"])


@router.get("/discount", response_model=DiscountSettingOut)
async def get_discount(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    v = await get_manager_max_discount(session)
    return DiscountSettingOut(manager_max_discount_pct=float(v) if v is not None else None)


@router.put("/discount", response_model=DiscountSettingOut)
async def put_discount(
    data: DiscountSettingIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    val = None if data.manager_max_discount_pct is None else Decimal(str(data.manager_max_discount_pct))
    await set_manager_max_discount(session, val)
    await session.commit()
    return DiscountSettingOut(manager_max_discount_pct=float(val) if val is not None else None)
