"""Категории клиентов: конструктор (CRUD), пересчёт, автосписок клиентов по категории."""
from __future__ import annotations

from decimal import Decimal
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin
from app.models import ClientCategory, Counterparty
from app.schemas import CategoryClientOut, ClientCategoryIn, ClientCategoryOut
from app.services.categories import recompute_all

router = APIRouter(prefix="/client-categories", tags=["client-categories"])


def _apply(c: ClientCategory, data: ClientCategoryIn) -> None:
    c.name = data.name
    c.group = data.group
    c.min_amount = Decimal(str(data.min_amount))
    c.max_amount = Decimal(str(data.max_amount)) if data.max_amount is not None else None
    c.options = data.options
    c.color = data.color
    c.sort_order = data.sort_order
    c.is_active = data.is_active
    # Эпик 23: визуальный конструктор категорий — description + options_meta.
    c.description = data.description
    c.options_meta = data.options_meta


@router.get("", response_model=list[ClientCategoryOut])
async def list_categories(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    return (await session.execute(select(ClientCategory).order_by(ClientCategory.sort_order))).scalars().all()


@router.post("", response_model=ClientCategoryOut, status_code=status.HTTP_201_CREATED)
async def create_category(
    data: ClientCategoryIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    if (await session.execute(select(ClientCategory).where(ClientCategory.code == data.code))).scalar_one_or_none():
        raise HTTPException(400, f"Категория «{data.code}» уже существует")
    c = ClientCategory(code=data.code)
    _apply(c, data)
    session.add(c)
    await session.commit()
    await session.refresh(c)
    return c


@router.patch("/{cat_id}", response_model=ClientCategoryOut)
async def update_category(
    cat_id: int,
    data: ClientCategoryIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    c = (await session.execute(select(ClientCategory).where(ClientCategory.id == cat_id))).scalar_one_or_none()
    if not c:
        raise HTTPException(404, "Категория не найдена")
    if data.code != c.code and (await session.execute(select(ClientCategory).where(ClientCategory.code == data.code))).scalar_one_or_none():
        raise HTTPException(400, f"Категория «{data.code}» уже существует")
    c.code = data.code
    _apply(c, data)
    await session.commit()
    await session.refresh(c)
    return c


@router.delete("/{cat_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_category(
    cat_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    c = (await session.execute(select(ClientCategory).where(ClientCategory.id == cat_id))).scalar_one_or_none()
    if not c:
        raise HTTPException(404, "Категория не найдена")
    await session.delete(c)
    await session.commit()


@router.post("/recompute")
async def recompute(
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    n = await recompute_all(session)
    return {"recomputed": n}


@router.get("/{code}/clients", response_model=list[CategoryClientOut])
async def category_clients(
    code: str,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Автосписок контрагентов категории (по кешу category_code, обновляется на sign/recompute)."""
    rows = (
        await session.execute(
            select(Counterparty)
            .where(Counterparty.category_code == code)
            .order_by(Counterparty.turnover_rub.desc().nullslast(), Counterparty.name)
        )
    ).scalars().all()
    return [
        CategoryClientOut(
            id=r.id, name=r.name,
            turnover_rub=float(r.turnover_rub) if r.turnover_rub is not None else None,
            group_id=r.group_id,
        )
        for r in rows
    ]
