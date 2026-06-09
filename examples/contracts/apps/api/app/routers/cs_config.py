"""Справочники реестра CS: платформы, регионы, модули, чек-листы внедрения.
GET — все; изменение — admin/director (как конструктор воронок)."""
from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin
from app.models import (
    ChecklistTemplate,
    ChecklistTemplateItem,
    Module,
    Platform,
    Region,
)
from app.schemas import (
    ChecklistItemTemplateIn,
    ChecklistItemTemplateOut,
    ModuleIn,
    ModuleOut,
    PlatformIn,
    PlatformOut,
    RegionIn,
    RegionOut,
)

router = APIRouter(tags=["cs-config"])


# ---------- Платформы ----------

@router.get("/platforms", response_model=list[PlatformOut])
async def list_platforms(current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    return (await session.execute(select(Platform).order_by(Platform.sort_order, Platform.id))).scalars().all()


@router.post("/platforms", response_model=PlatformOut, status_code=status.HTTP_201_CREATED)
async def create_platform(data: PlatformIn, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    p = Platform(**data.model_dump())
    session.add(p)
    await session.commit()
    await session.refresh(p)
    return p


@router.patch("/platforms/{platform_id}", response_model=PlatformOut)
async def update_platform(platform_id: int, data: PlatformIn, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    p = (await session.execute(select(Platform).where(Platform.id == platform_id))).scalar_one_or_none()
    if not p:
        raise HTTPException(404, "Платформа не найдена")
    for k, v in data.model_dump().items():
        setattr(p, k, v)
    await session.commit()
    await session.refresh(p)
    return p


# ---------- Регионы ----------

@router.get("/regions", response_model=list[RegionOut])
async def list_regions(current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    return (await session.execute(select(Region).order_by(Region.sort_order, Region.id))).scalars().all()


@router.post("/regions", response_model=RegionOut, status_code=status.HTTP_201_CREATED)
async def create_region(data: RegionIn, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    r = Region(**data.model_dump())
    session.add(r)
    await session.commit()
    await session.refresh(r)
    return r


@router.patch("/regions/{region_id}", response_model=RegionOut)
async def update_region(region_id: int, data: RegionIn, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    r = (await session.execute(select(Region).where(Region.id == region_id))).scalar_one_or_none()
    if not r:
        raise HTTPException(404, "Регион не найден")
    for k, v in data.model_dump().items():
        setattr(r, k, v)
    await session.commit()
    await session.refresh(r)
    return r


# ---------- Модули ----------

@router.get("/modules", response_model=list[ModuleOut])
async def list_modules(current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)], platform_id: int | None = None):
    stmt = select(Module).order_by(Module.platform_id, Module.sort_order, Module.id)
    if platform_id is not None:
        stmt = stmt.where((Module.platform_id == platform_id) | (Module.platform_id.is_(None)))
    return (await session.execute(stmt)).scalars().all()


@router.post("/modules", response_model=ModuleOut, status_code=status.HTTP_201_CREATED)
async def create_module(data: ModuleIn, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    m = Module(**data.model_dump())
    session.add(m)
    await session.commit()
    await session.refresh(m)
    return m


@router.patch("/modules/{module_id}", response_model=ModuleOut)
async def update_module(module_id: int, data: ModuleIn, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    m = (await session.execute(select(Module).where(Module.id == module_id))).scalar_one_or_none()
    if not m:
        raise HTTPException(404, "Модуль не найден")
    for k, v in data.model_dump().items():
        setattr(m, k, v)
    await session.commit()
    await session.refresh(m)
    return m


@router.delete("/modules/{module_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_module(module_id: int, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    m = (await session.execute(select(Module).where(Module.id == module_id))).scalar_one_or_none()
    if not m:
        raise HTTPException(404, "Модуль не найден")
    await session.delete(m)
    await session.commit()


# ---------- Чек-листы внедрения ----------

async def _template_for(session: AsyncSession, platform_id: int, create: bool = False) -> ChecklistTemplate | None:
    tpl = (await session.execute(
        select(ChecklistTemplate).where(ChecklistTemplate.platform_id == platform_id)
    )).scalars().first()
    if tpl is None and create:
        pl = (await session.execute(select(Platform).where(Platform.id == platform_id))).scalar_one_or_none()
        if not pl:
            raise HTTPException(404, "Платформа не найдена")
        tpl = ChecklistTemplate(platform_id=platform_id, name=f"Внедрение {pl.name}")
        session.add(tpl)
        await session.flush()
    return tpl


@router.get("/platforms/{platform_id}/checklist-items", response_model=list[ChecklistItemTemplateOut])
async def list_checklist_items(platform_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    tpl = await _template_for(session, platform_id)
    if not tpl:
        return []
    return (await session.execute(
        select(ChecklistTemplateItem).where(ChecklistTemplateItem.template_id == tpl.id).order_by(ChecklistTemplateItem.sort_order)
    )).scalars().all()


@router.post("/platforms/{platform_id}/checklist-items", response_model=ChecklistItemTemplateOut, status_code=status.HTTP_201_CREATED)
async def create_checklist_item(platform_id: int, data: ChecklistItemTemplateIn, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    tpl = await _template_for(session, platform_id, create=True)
    it = ChecklistTemplateItem(template_id=tpl.id, **data.model_dump())
    session.add(it)
    await session.commit()
    await session.refresh(it)
    return it


@router.patch("/checklist-items/{item_id}", response_model=ChecklistItemTemplateOut)
async def update_checklist_item(item_id: int, data: ChecklistItemTemplateIn, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    it = (await session.execute(select(ChecklistTemplateItem).where(ChecklistTemplateItem.id == item_id))).scalar_one_or_none()
    if not it:
        raise HTTPException(404, "Пункт чек-листа не найден")
    for k, v in data.model_dump().items():
        setattr(it, k, v)
    await session.commit()
    await session.refresh(it)
    return it


@router.delete("/checklist-items/{item_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_checklist_item(item_id: int, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    it = (await session.execute(select(ChecklistTemplateItem).where(ChecklistTemplateItem.id == item_id))).scalar_one_or_none()
    if not it:
        raise HTTPException(404, "Пункт чек-листа не найден")
    await session.delete(it)
    await session.commit()
