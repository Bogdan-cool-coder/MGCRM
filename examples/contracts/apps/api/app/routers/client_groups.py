"""Холдинги (группы контрагентов). Членство задаётся через Counterparty.group_id."""
from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin
from app.models import ClientGroup, Counterparty
from app.schemas import ClientGroupIn, ClientGroupOut
from app.services.categories import assign_for_counterparty

router = APIRouter(prefix="/client-groups", tags=["client-groups"])


async def _out(session: AsyncSession, groups: list[ClientGroup]) -> list[ClientGroupOut]:
    ids = [g.id for g in groups]
    members: dict[int, list[int]] = {}
    if ids:
        rows = (await session.execute(
            select(Counterparty.id, Counterparty.group_id).where(Counterparty.group_id.in_(ids))
        )).all()
        for cp_id, gid in rows:
            members.setdefault(gid, []).append(cp_id)
    return [
        ClientGroupOut(
            id=g.id, name=g.name, category_code=g.category_code,
            turnover_rub=float(g.turnover_rub) if g.turnover_rub is not None else None,
            member_ids=members.get(g.id, []),
        )
        for g in groups
    ]


@router.get("", response_model=list[ClientGroupOut])
async def list_groups(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    groups = list((await session.execute(select(ClientGroup).order_by(ClientGroup.name))).scalars().all())
    return await _out(session, groups)


@router.post("", response_model=ClientGroupOut, status_code=status.HTTP_201_CREATED)
async def create_group(
    data: ClientGroupIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    g = ClientGroup(name=data.name)
    session.add(g)
    await session.commit()
    await session.refresh(g)
    return (await _out(session, [g]))[0]


@router.patch("/{group_id}", response_model=ClientGroupOut)
async def rename_group(
    group_id: int,
    data: ClientGroupIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    g = (await session.execute(select(ClientGroup).where(ClientGroup.id == group_id))).scalar_one_or_none()
    if not g:
        raise HTTPException(404, "Группа не найдена")
    g.name = data.name
    await session.commit()
    await session.refresh(g)
    return (await _out(session, [g]))[0]


@router.delete("/{group_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_group(
    group_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    g = (await session.execute(select(ClientGroup).where(ClientGroup.id == group_id))).scalar_one_or_none()
    if not g:
        raise HTTPException(404, "Группа не найдена")
    member_ids = (await session.execute(select(Counterparty.id).where(Counterparty.group_id == group_id))).scalars().all()
    await session.delete(g)  # FK ondelete SET NULL обнулит group_id у членов
    await session.commit()
    for cp_id in member_ids:  # бывшие члены теперь считаются по одному
        await assign_for_counterparty(session, cp_id)
    await session.commit()
