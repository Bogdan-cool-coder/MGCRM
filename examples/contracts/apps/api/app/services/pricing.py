"""Прайс: сидинг продуктов из products_seed.json, расчёт итогов договора, лимит скидки."""
from __future__ import annotations

import json
from decimal import ROUND_HALF_UP, Decimal
from pathlib import Path

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import Product, ProductPlan, ProductPrice, Setting

SEED_PATH = Path(__file__).resolve().parent.parent / "data" / "products_seed.json"
MANAGER_MAX_DISCOUNT_KEY = "manager_max_discount_pct"
# Session-level advisory-lock: сериализует сидинг между api-репликами при rolling-деплое
# (иначе на пустой БД две реплики гонятся вставлять одни code → unique violation → краш).
_SEED_LOCK_KEY = 728_274_002


def q2(v) -> Decimal:
    """Округление к 2 знакам (банковское half-up)."""
    return Decimal(str(v)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def format_money(v) -> str:
    """Денежная строка для шаблона: «2 500 000» или «113.50» (разделитель тысяч — неразрывный пробел)."""
    s = f"{q2(v):,.2f}".replace(",", " ")
    return s[:-3] if s.endswith(".00") else s


def compute_totals(line_totals: list[Decimal], discount_pct: Decimal) -> tuple[Decimal, Decimal, Decimal]:
    """(subtotal, discount_amount, total). Скидка — % на итог договора."""
    subtotal = sum(line_totals, Decimal("0"))
    discount_amount = q2(subtotal * discount_pct / Decimal("100"))
    total = q2(subtotal - discount_amount)
    return q2(subtotal), discount_amount, total


async def get_manager_max_discount(session: AsyncSession) -> Decimal | None:
    """Лимит скидки для менеджера (% ), None = без лимита."""
    row = (
        await session.execute(select(Setting).where(Setting.key == MANAGER_MAX_DISCOUNT_KEY))
    ).scalar_one_or_none()
    if not row or row.value in (None, ""):
        return None
    try:
        return Decimal(str(row.value))
    except Exception:  # noqa: BLE001
        return None


async def set_manager_max_discount(session: AsyncSession, value: Decimal | None) -> None:
    row = (
        await session.execute(select(Setting).where(Setting.key == MANAGER_MAX_DISCOUNT_KEY))
    ).scalar_one_or_none()
    val = "" if value is None else str(value)
    if row:
        row.value = val
    else:
        session.add(Setting(key=MANAGER_MAX_DISCOUNT_KEY, value=val))


async def seed_products_from_json(session: AsyncSession) -> int:
    """Добавляет из products_seed.json продукты, которых ещё нет (по code). Идемпотентно:
    на пустой БД зальёт весь прайс, при обновлении файла — только новые коды. Существующие
    не трогает (правки цен — через админку), чтобы не конфликтовать с contract_items."""
    if not SEED_PATH.exists():
        return 0
    # Лок держится на соединении сессии и переживает commit (session-level, не xact).
    await session.execute(text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_KEY})
    try:
        return await _seed_products_locked(session)
    finally:
        await session.execute(text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_KEY})


async def _seed_products_locked(session: AsyncSession) -> int:
    data = json.loads(SEED_PATH.read_text(encoding="utf-8"))
    existing = set((await session.execute(select(Product.code))).scalars().all())
    inserted = 0
    for item in data:
        if item["code"] in existing:
            continue
        prod = Product(
            code=item["code"],
            name=item["name"],
            description=item.get("description"),
            group=item.get("group"),
            pricing_type=item.get("pricing_type", "fixed"),
            maps_to_product_code=item.get("maps_to_product_code"),
            is_active=item.get("is_active", True),
            sort_order=item.get("sort_order", 0),
        )
        session.add(prod)
        await session.flush()  # нужен prod.id
        for pr in item.get("prices", []):
            session.add(ProductPrice(
                product_id=prod.id, plan_id=None,
                currency=pr["currency"], amount=q2(pr["amount"]),
            ))
        for pl in item.get("plans", []):
            plan = ProductPlan(
                product_id=prod.id, name=pl["name"],
                unit=pl.get("unit", "year"), sort_order=pl.get("sort_order", 0),
            )
            session.add(plan)
            await session.flush()
            for pr in pl.get("prices", []):
                session.add(ProductPrice(
                    product_id=prod.id, plan_id=plan.id,
                    currency=pr["currency"], amount=q2(pr["amount"]),
                ))
        inserted += 1
    if inserted:
        await session.commit()
    return inserted
