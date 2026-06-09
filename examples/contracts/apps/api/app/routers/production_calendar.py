"""Epic 14.2 — Production calendar endpoints.

- GET  /api/production-calendar?country_code=&year=
- POST /api/admin/production-calendar/seed?country_code=&year=
"""
from __future__ import annotations

from datetime import date
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, ConfigDict
from sqlalchemy.ext.asyncio import AsyncSession

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.services.production_calendar import (
    get_holidays,
    seed_default,
)

router = APIRouter(tags=["production-calendar"])

ALLOWED_COUNTRY_CODES = frozenset({"RU", "KZ", "UZ", "AE"})


class ProductionCalendarDayOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    country_code: str
    year: int
    date: date
    is_holiday: bool
    is_short_day: bool
    name: str | None


class SeedResultOut(BaseModel):
    country_code: str
    year: int
    inserted: int


@router.get(
    "/production-calendar",
    response_model=list[ProductionCalendarDayOut],
)
async def list_production_calendar(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    country_code: str,
    year: int,
):
    """Список праздничных/сокращённых дней для конкретной страны и года.

    Используется фронтом для расчёта «срок задачи + N рабочих дней».
    """
    cc = country_code.upper()
    if cc not in ALLOWED_COUNTRY_CODES:
        raise HTTPException(
            400, f"country_code должен быть одним из {sorted(ALLOWED_COUNTRY_CODES)}",
        )
    return await get_holidays(session, cc, year)


@router.post(
    "/admin/production-calendar/seed",
    response_model=SeedResultOut,
)
async def seed_production_calendar(
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    country_code: str,
    year: int,
):
    """Идемпотентный сид дефолтных праздников для страны/года.

    Сейчас сид определён только для 2026 года. Для других лет вернёт 0.
    """
    cc = country_code.upper()
    if cc not in ALLOWED_COUNTRY_CODES:
        raise HTTPException(
            400, f"country_code должен быть одним из {sorted(ALLOWED_COUNTRY_CODES)}",
        )
    inserted = await seed_default(session, cc, year)
    return SeedResultOut(country_code=cc, year=year, inserted=inserted)
