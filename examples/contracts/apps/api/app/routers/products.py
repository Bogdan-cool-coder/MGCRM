"""Продукты и прайс: список/чтение (все роли), CRUD и reimport (director/admin)."""
from __future__ import annotations

import re
import unicodedata
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin
from app.models import ContractItem, DealProduct, Product, ProductGroup, ProductPlan, ProductPrice
from app.schemas import ProductIn, ProductOut, ProductPlanOut, ProductPriceOut
from app.services.pricing import q2, seed_products_from_json

router = APIRouter(prefix="/products", tags=["products"])


def _slug(name: str) -> str:
    base = unicodedata.normalize("NFKD", name).encode("ascii", "ignore").decode()
    base = re.sub(r"[^a-zA-Z0-9]+", "_", base).strip("_").lower()
    return base or "product"


async def _assemble(session: AsyncSession, products: list[Product]) -> list[ProductOut]:
    ids = [p.id for p in products]
    if not ids:
        return []
    prices = (
        await session.execute(select(ProductPrice).where(ProductPrice.product_id.in_(ids)))
    ).scalars().all()
    plans = (
        await session.execute(
            select(ProductPlan).where(ProductPlan.product_id.in_(ids)).order_by(ProductPlan.sort_order)
        )
    ).scalars().all()
    prices_by_prod: dict[int, list[ProductPrice]] = {}
    for pr in prices:
        prices_by_prod.setdefault(pr.product_id, []).append(pr)
    plans_by_prod: dict[int, list[ProductPlan]] = {}
    for pl in plans:
        plans_by_prod.setdefault(pl.product_id, []).append(pl)

    out: list[ProductOut] = []
    for p in products:
        own = prices_by_prod.get(p.id, [])
        base_prices = [
            ProductPriceOut(currency=pr.currency, amount=float(pr.amount), plan_id=None)
            for pr in own if pr.plan_id is None
        ]
        plan_outs = []
        for pl in plans_by_prod.get(p.id, []):
            pl_prices = [
                ProductPriceOut(currency=pr.currency, amount=float(pr.amount), plan_id=pl.id)
                for pr in own if pr.plan_id == pl.id
            ]
            plan_outs.append(ProductPlanOut(
                id=pl.id, name=pl.name, unit=pl.unit, sort_order=pl.sort_order, prices=pl_prices,
            ))
        out.append(ProductOut(
            id=p.id, code=p.code, name=p.name, description=p.description, group=p.group,
            group_id=p.group_id,
            pricing_type=p.pricing_type, maps_to_product_code=p.maps_to_product_code,
            is_active=p.is_active, sort_order=p.sort_order, prices=base_prices, plans=plan_outs,
        ))
    return out


async def _resolve_group(
    session: AsyncSession, group_id: int | None, group: str | None
) -> tuple[int | None, str | None]:
    """Согласовать group_id ↔ legacy строку group.

    Если задан group_id — валидируем и берём имя группы как строку group (приоритет).
    Иначе оставляем переданную строку group как есть (group_id=None).
    """
    if group_id is not None:
        g = (await session.execute(
            select(ProductGroup).where(ProductGroup.id == group_id)
        )).scalar_one_or_none()
        if not g:
            raise HTTPException(400, f"Группа продуктов id={group_id} не найдена")
        return g.id, g.name
    return None, group


@router.get("", response_model=list[ProductOut])
async def list_products(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    active_only: bool = True,
):
    stmt = select(Product).order_by(Product.sort_order, Product.id)
    if active_only:
        stmt = stmt.where(Product.is_active.is_(True))
    products = list((await session.execute(stmt)).scalars().all())
    return await _assemble(session, products)


@router.get("/{product_id}", response_model=ProductOut)
async def get_product(
    product_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    p = (await session.execute(select(Product).where(Product.id == product_id))).scalar_one_or_none()
    if not p:
        raise HTTPException(404, "Продукт не найден")
    return (await _assemble(session, [p]))[0]


async def _replace_base_prices(session: AsyncSession, product: Product, data: ProductIn) -> None:
    """Базовые цены (plan_id is null) безопасно пересоздаём — на ProductPrice нет внешних ссылок."""
    old = (
        await session.execute(
            select(ProductPrice).where(
                ProductPrice.product_id == product.id, ProductPrice.plan_id.is_(None)
            )
        )
    ).scalars().all()
    for pr in old:
        await session.delete(pr)
    await session.flush()
    for pr in data.prices:
        session.add(ProductPrice(
            product_id=product.id, plan_id=None, currency=pr.currency, amount=q2(pr.amount),
        ))


async def _upsert_plans(session: AsyncSession, product: Product, data: ProductIn) -> None:
    """Планы: обновляем по id, добавляем новые. НЕ удаляем (на plan_id ссылаются contract_items)."""
    existing = {
        pl.id: pl
        for pl in (
            await session.execute(select(ProductPlan).where(ProductPlan.product_id == product.id))
        ).scalars().all()
    }
    for pl_in in data.plans:
        if pl_in.id and pl_in.id in existing:
            plan = existing[pl_in.id]
            plan.name, plan.unit, plan.sort_order = pl_in.name, pl_in.unit, pl_in.sort_order
        else:
            plan = ProductPlan(
                product_id=product.id, name=pl_in.name, unit=pl_in.unit, sort_order=pl_in.sort_order,
            )
            session.add(plan)
            await session.flush()
        # цены плана пересоздаём (на них тоже нет внешних ссылок)
        old = (
            await session.execute(
                select(ProductPrice).where(
                    ProductPrice.product_id == product.id, ProductPrice.plan_id == plan.id
                )
            )
        ).scalars().all()
        for pr in old:
            await session.delete(pr)
        await session.flush()
        for pr in pl_in.prices:
            session.add(ProductPrice(
                product_id=product.id, plan_id=plan.id, currency=pr.currency, amount=q2(pr.amount),
            ))


@router.post("", response_model=ProductOut, status_code=status.HTTP_201_CREATED)
async def create_product(
    data: ProductIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    code = data.code or _slug(data.name)
    if (await session.execute(select(Product).where(Product.code == code))).scalar_one_or_none():
        raise HTTPException(400, f"Продукт с кодом «{code}» уже существует")
    group_id, group_str = await _resolve_group(session, data.group_id, data.group)
    p = Product(
        code=code, name=data.name, description=data.description,
        group=group_str, group_id=group_id,
        pricing_type=data.pricing_type, maps_to_product_code=data.maps_to_product_code,
        is_active=data.is_active, sort_order=data.sort_order,
    )
    session.add(p)
    await session.flush()
    await _replace_base_prices(session, p, data)
    await _upsert_plans(session, p, data)
    await session.commit()
    return (await _assemble(session, [p]))[0]


@router.patch("/{product_id}", response_model=ProductOut)
async def update_product(
    product_id: int,
    data: ProductIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    p = (await session.execute(select(Product).where(Product.id == product_id))).scalar_one_or_none()
    if not p:
        raise HTTPException(404, "Продукт не найден")
    if data.code and data.code != p.code:
        if (await session.execute(select(Product).where(Product.code == data.code))).scalar_one_or_none():
            raise HTTPException(400, f"Продукт с кодом «{data.code}» уже существует")
        p.code = data.code
    p.name = data.name
    p.description = data.description
    p.group_id, p.group = await _resolve_group(session, data.group_id, data.group)
    p.pricing_type = data.pricing_type
    p.maps_to_product_code = data.maps_to_product_code
    p.is_active = data.is_active
    p.sort_order = data.sort_order
    await _replace_base_prices(session, p, data)
    await _upsert_plans(session, p, data)
    await session.commit()
    return (await _assemble(session, [p]))[0]


@router.delete("/{product_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_product(
    product_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    p = (await session.execute(select(Product).where(Product.id == product_id))).scalar_one_or_none()
    if not p:
        raise HTTPException(404, "Продукт не найден")
    # B6: продукт могут ссылать И договоры (ContractItem), И сделки (DealProduct).
    # Раньше проверялся только ContractItem → DealProduct-FK уходил в БД и падал
    # необработанным 500. Проверяем обе таблицы и отвечаем 409.
    in_contracts = (
        await session.execute(
            select(func.count()).select_from(ContractItem).where(ContractItem.product_id == product_id)
        )
    ).scalar_one()
    in_deals = (
        await session.execute(
            select(func.count()).select_from(DealProduct).where(DealProduct.product_id == product_id)
        )
    ).scalar_one()
    if in_contracts or in_deals:
        raise HTTPException(
            status.HTTP_409_CONFLICT,
            "Продукт используется в сделках/договорах — деактивируйте вместо удаления",
        )
    await session.delete(p)
    await session.commit()


@router.post("/reimport")
async def reimport_products(
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Дозалить новые продукты из products_seed.json (существующие коды не трогает)."""
    inserted = await seed_products_from_json(session)
    return {"inserted": inserted}
