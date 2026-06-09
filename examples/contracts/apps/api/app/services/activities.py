"""Activity / Timeline (Эпик 2).

Хелперы валидации полиморфной Activity: список допустимых kind / target_type +
проверка существования target в БД (выбор модели по target_type через map).
Список 7 типов фиксирован продакт-решением — расширяется явно (новая сущность ⇒
правка карты + миграции значений в БД при необходимости).
"""
from __future__ import annotations

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import Base
from app.models import (
    Company,
    Contact,
    Contract,
    Counterparty,
    Deal,
    Lead,
    ClientSubscription,
)

# Допустимые kind активности — fixed list, валидируется в роутере.
ACTIVITY_KINDS: tuple[str, ...] = ("call", "meeting", "task", "note")

# Допустимые target_type (polymorphic).
ACTIVITY_TARGET_TYPES: tuple[str, ...] = (
    "lead",
    "contact",
    "company",
    "counterparty",
    "deal",
    "contract",
    "subscription",
)

# target_type → SQLAlchemy-модель. Используется validate_target_exists.
_TARGET_MODEL_MAP: dict[str, type[Base]] = {
    "lead": Lead,
    "contact": Contact,
    "company": Company,
    "counterparty": Counterparty,
    "deal": Deal,
    "contract": Contract,
    "subscription": ClientSubscription,
}


async def validate_target_exists(
    session: AsyncSession,
    target_type: str,
    target_id: int,
) -> bool:
    """Проверяет что (target_type, target_id) ссылается на существующую запись.

    Returns:
        True — запись существует;
        False — нет такой записи (роутер должен вернуть 404/400).

    Raises:
        ValueError — если target_type не из ACTIVITY_TARGET_TYPES (роутер валидирует
        этот случай раньше через ACTIVITY_TARGET_TYPES, но fail-fast на всякий).
    """
    model = _TARGET_MODEL_MAP.get(target_type)
    if model is None:
        raise ValueError(f"Unknown target_type: {target_type}")
    # Выбираем только id, чтобы не тянуть весь объект — дешёвый existence-check.
    found = (await session.execute(
        select(model.id).where(model.id == target_id)  # type: ignore[attr-defined]
    )).scalar_one_or_none()
    return found is not None
