"""Закрытие периода (Ф0, инвариант №5).

В закрытом периоде (`fin_period_lock(legal_entity, year, month)`) операции не
создаются и не меняются задним числом — постинг и сторно запрещены.

`is_period_locked` — чистый предикат (дата + множество закрытых (year,month)) для
pure-тестов; `assert_period_open` — async-обёртка, читающая fin_period_lock.
"""

from __future__ import annotations

from datetime import date

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import FinPeriodLock
from app.services.finance.posting import PeriodLocked


def is_period_locked(on_date: date, locked_periods: set[tuple[int, int]]) -> bool:
    """True ⇔ (on_date.year, on_date.month) ∈ locked_periods. Pure."""
    return (on_date.year, on_date.month) in locked_periods


async def assert_period_open(
    session: AsyncSession, legal_entity_id: int, on_date: date
) -> None:
    """Бросает PeriodLocked, если период (юрлицо, год, месяц) закрыт."""
    locked = (
        await session.execute(
            select(FinPeriodLock.id).where(
                FinPeriodLock.legal_entity_id == legal_entity_id,
                FinPeriodLock.year == on_date.year,
                FinPeriodLock.month == on_date.month,
            )
        )
    ).scalar_one_or_none()
    if locked is not None:
        raise PeriodLocked(on_date.year, on_date.month)
