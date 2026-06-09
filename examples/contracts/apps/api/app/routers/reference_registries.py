"""Wave 3 — admin справочники: страны / города / источники / группы продуктов.

Паттерн повторяет CompanyType (contacts_v2.py): list-эндпоинты для авторизованных,
CRUD под ролевым гардом (AdminUser, кроме product-groups → DirectorOrAdmin, как у products).

Роутеры:
- /api/countries        + /api/admin/countries        (AdminUser CRUD)
- /api/cities           + /api/admin/cities           (AdminUser CRUD)
- /api/sources          + /api/admin/sources          (AdminUser CRUD)
- /api/product-groups   + /api/admin/product-groups    (DirectorOrAdmin CRUD)
"""
from __future__ import annotations

from datetime import datetime
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func, or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser, DirectorOrAdmin
from app.models import City, Country, Product, ProductGroup, Source


# ====================== Schemas ======================

class CountryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    code: str
    name: str
    name_en: str | None
    phone_prefix: str | None
    sort_order: int
    is_active: bool
    created_at: datetime


class CountryCreate(BaseModel):
    code: str = Field(min_length=2, max_length=2)
    name: str = Field(min_length=1, max_length=128)
    name_en: str | None = Field(default=None, max_length=128)
    phone_prefix: str | None = Field(default=None, max_length=8)
    sort_order: int = 0
    is_active: bool = True


class CountryUpdate(BaseModel):
    code: str | None = Field(default=None, min_length=2, max_length=2)
    name: str | None = Field(default=None, min_length=1, max_length=128)
    name_en: str | None = Field(default=None, max_length=128)
    phone_prefix: str | None = Field(default=None, max_length=8)
    sort_order: int | None = None
    is_active: bool | None = None


class CityOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    country_code: str
    name: str
    sort_order: int
    is_active: bool
    created_at: datetime


class CityCreate(BaseModel):
    country_code: str = Field(min_length=2, max_length=2)
    name: str = Field(min_length=1, max_length=128)
    sort_order: int = 0
    is_active: bool = True


class CityUpdate(BaseModel):
    country_code: str | None = Field(default=None, min_length=2, max_length=2)
    name: str | None = Field(default=None, min_length=1, max_length=128)
    sort_order: int | None = None
    is_active: bool | None = None


class SourceOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    code: str
    name: str
    sort_order: int
    is_active: bool
    created_at: datetime


class SourceCreate(BaseModel):
    code: str = Field(min_length=1, max_length=32)
    name: str = Field(min_length=1, max_length=128)
    sort_order: int = 0
    is_active: bool = True


class SourceUpdate(BaseModel):
    code: str | None = Field(default=None, min_length=1, max_length=32)
    name: str | None = Field(default=None, min_length=1, max_length=128)
    sort_order: int | None = None
    is_active: bool | None = None


class ProductGroupOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    description: str | None
    sort_order: int
    is_active: bool
    created_at: datetime


class ProductGroupCreate(BaseModel):
    name: str = Field(min_length=1, max_length=128)
    description: str | None = None
    sort_order: int = 0
    is_active: bool = True


class ProductGroupUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=128)
    description: str | None = None
    sort_order: int | None = None
    is_active: bool | None = None


# ====================== Countries ======================

countries_router = APIRouter(prefix="/countries", tags=["reference-registries"])
admin_countries_router = APIRouter(prefix="/admin/countries", tags=["admin-dictionaries"])


@countries_router.get("", response_model=list[CountryOut])
async def list_countries(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    q: str | None = None,
    only_active: bool = False,
):
    """Список стран (для пикера). q — поиск по name/name_en/code."""
    stmt = select(Country).order_by(Country.sort_order, Country.name)
    if only_active:
        stmt = stmt.where(Country.is_active.is_(True))
    if q:
        like = f"%{q}%"
        stmt = stmt.where(or_(
            Country.name.ilike(like),
            Country.name_en.ilike(like),
            Country.code.ilike(like),
        ))
    return (await session.execute(stmt)).scalars().all()


@admin_countries_router.post("", response_model=CountryOut, status_code=status.HTTP_201_CREATED)
async def create_country(
    data: CountryCreate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    code = data.code.lower()
    if (await session.execute(select(Country).where(Country.code == code))).scalar_one_or_none():
        raise HTTPException(400, f"Страна с кодом «{code}» уже существует")
    c = Country(**{**data.model_dump(), "code": code})
    session.add(c)
    await session.commit()
    await session.refresh(c)
    return c


@admin_countries_router.patch("/{country_id}", response_model=CountryOut)
async def update_country(
    country_id: int,
    data: CountryUpdate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    c = (await session.execute(
        select(Country).where(Country.id == country_id)
    )).scalar_one_or_none()
    if not c:
        raise HTTPException(404, "Страна не найдена")
    patch = data.model_dump(exclude_unset=True)
    if "code" in patch and patch["code"]:
        patch["code"] = patch["code"].lower()
        if patch["code"] != c.code and (await session.execute(
            select(Country).where(Country.code == patch["code"])
        )).scalar_one_or_none():
            raise HTTPException(400, f"Страна с кодом «{patch['code']}» уже существует")
    for k, v in patch.items():
        setattr(c, k, v)
    await session.commit()
    await session.refresh(c)
    return c


@admin_countries_router.delete("/{country_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_country(
    country_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удаление страны из справочника.

    Города привязаны FK ondelete=CASCADE — удалятся вместе со страной.
    ВНИМАНИЕ: Company.country / Contact-реквизиты хранят код страны строкой
    (free-string, без FK — справочник лишь источник для пикера). Удаление НЕ
    осиротит существующие записи (строка останется), но новый ввод этого кода
    через пикер станет недоступен. Для вывода из оборота без потери истории
    предпочтительнее soft-disable (PATCH is_active=false), а не DELETE.
    """
    c = (await session.execute(
        select(Country).where(Country.id == country_id)
    )).scalar_one_or_none()
    if not c:
        raise HTTPException(404, "Страна не найдена")
    await session.delete(c)
    await session.commit()


# ====================== Cities ======================

cities_router = APIRouter(prefix="/cities", tags=["reference-registries"])
admin_cities_router = APIRouter(prefix="/admin/cities", tags=["admin-dictionaries"])


@cities_router.get("", response_model=list[CityOut])
async def list_cities(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    country_code: str | None = None,
    q: str | None = None,
    only_active: bool = False,
):
    """Список городов. Фильтр по country_code, поиск по name."""
    stmt = select(City).order_by(City.sort_order, City.name)
    if country_code:
        stmt = stmt.where(City.country_code == country_code.lower())
    if only_active:
        stmt = stmt.where(City.is_active.is_(True))
    if q:
        stmt = stmt.where(City.name.ilike(f"%{q}%"))
    return (await session.execute(stmt)).scalars().all()


@admin_cities_router.post("", response_model=CityOut, status_code=status.HTTP_201_CREATED)
async def create_city(
    data: CityCreate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    cc = data.country_code.lower()
    if not (await session.execute(
        select(Country.code).where(Country.code == cc)
    )).scalar_one_or_none():
        raise HTTPException(400, f"Страна «{cc}» не найдена в справочнике")
    if (await session.execute(
        select(City).where(City.country_code == cc, City.name == data.name)
    )).scalar_one_or_none():
        raise HTTPException(400, f"Город «{data.name}» ({cc}) уже существует")
    city = City(**{**data.model_dump(), "country_code": cc})
    session.add(city)
    await session.commit()
    await session.refresh(city)
    return city


@admin_cities_router.patch("/{city_id}", response_model=CityOut)
async def update_city(
    city_id: int,
    data: CityUpdate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    city = (await session.execute(
        select(City).where(City.id == city_id)
    )).scalar_one_or_none()
    if not city:
        raise HTTPException(404, "Город не найден")
    patch = data.model_dump(exclude_unset=True)
    if "country_code" in patch and patch["country_code"]:
        patch["country_code"] = patch["country_code"].lower()
        if not (await session.execute(
            select(Country.code).where(Country.code == patch["country_code"])
        )).scalar_one_or_none():
            raise HTTPException(400, f"Страна «{patch['country_code']}» не найдена")
    new_cc = patch.get("country_code", city.country_code)
    new_name = patch.get("name", city.name)
    if (new_cc, new_name) != (city.country_code, city.name) and (await session.execute(
        select(City).where(City.country_code == new_cc, City.name == new_name, City.id != city_id)
    )).scalar_one_or_none():
        raise HTTPException(400, f"Город «{new_name}» ({new_cc}) уже существует")
    for k, v in patch.items():
        setattr(city, k, v)
    await session.commit()
    await session.refresh(city)
    return city


@admin_cities_router.delete("/{city_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_city(
    city_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удаление города из справочника.

    Company.city / Contact-реквизиты хранят название города строкой (free-string,
    без FK). Удаление НЕ осиротит существующие записи — строка останется,
    недоступным станет лишь повторный выбор через пикер. Для вывода из оборота
    предпочтительнее soft-disable (PATCH is_active=false), а не DELETE.
    """
    city = (await session.execute(
        select(City).where(City.id == city_id)
    )).scalar_one_or_none()
    if not city:
        raise HTTPException(404, "Город не найден")
    await session.delete(city)
    await session.commit()


# ====================== Sources ======================

sources_router = APIRouter(prefix="/sources", tags=["reference-registries"])
admin_sources_router = APIRouter(prefix="/admin/sources", tags=["admin-dictionaries"])


@sources_router.get("", response_model=list[SourceOut])
async def list_sources(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    q: str | None = None,
    only_active: bool = False,
):
    """Список источников (для пикера). q — поиск по name/code."""
    stmt = select(Source).order_by(Source.sort_order, Source.name)
    if only_active:
        stmt = stmt.where(Source.is_active.is_(True))
    if q:
        like = f"%{q}%"
        stmt = stmt.where(or_(Source.name.ilike(like), Source.code.ilike(like)))
    return (await session.execute(stmt)).scalars().all()


@admin_sources_router.post("", response_model=SourceOut, status_code=status.HTTP_201_CREATED)
async def create_source(
    data: SourceCreate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    if (await session.execute(
        select(Source).where(Source.code == data.code)
    )).scalar_one_or_none():
        raise HTTPException(400, f"Источник с кодом «{data.code}» уже существует")
    s = Source(**data.model_dump())
    session.add(s)
    await session.commit()
    await session.refresh(s)
    return s


@admin_sources_router.patch("/{source_id}", response_model=SourceOut)
async def update_source(
    source_id: int,
    data: SourceUpdate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    s = (await session.execute(
        select(Source).where(Source.id == source_id)
    )).scalar_one_or_none()
    if not s:
        raise HTTPException(404, "Источник не найден")
    patch = data.model_dump(exclude_unset=True)
    if "code" in patch and patch["code"] and patch["code"] != s.code:
        if (await session.execute(
            select(Source).where(Source.code == patch["code"])
        )).scalar_one_or_none():
            raise HTTPException(400, f"Источник с кодом «{patch['code']}» уже существует")
    for k, v in patch.items():
        setattr(s, k, v)
    await session.commit()
    await session.refresh(s)
    return s


@admin_sources_router.delete("/{source_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_source(
    source_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удаление источника из справочника.

    Company.source / Contact.source хранят код источника строкой (free-string,
    без FK — см. модель Source). Удаление НЕ осиротит существующие записи, но
    новый выбор кода через пикер станет недоступен. Для вывода из оборота
    предпочтительнее soft-disable (PATCH is_active=false), а не DELETE.
    """
    s = (await session.execute(
        select(Source).where(Source.id == source_id)
    )).scalar_one_or_none()
    if not s:
        raise HTTPException(404, "Источник не найден")
    await session.delete(s)
    await session.commit()


# ====================== Product groups ======================

product_groups_router = APIRouter(prefix="/product-groups", tags=["reference-registries"])
admin_product_groups_router = APIRouter(
    prefix="/admin/product-groups", tags=["admin-dictionaries"]
)


@product_groups_router.get("", response_model=list[ProductGroupOut])
async def list_product_groups(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    q: str | None = None,
    only_active: bool = False,
):
    """Список групп продуктов (для пикера). q — поиск по name."""
    stmt = select(ProductGroup).order_by(ProductGroup.sort_order, ProductGroup.name)
    if only_active:
        stmt = stmt.where(ProductGroup.is_active.is_(True))
    if q:
        stmt = stmt.where(ProductGroup.name.ilike(f"%{q}%"))
    return (await session.execute(stmt)).scalars().all()


@admin_product_groups_router.post(
    "", response_model=ProductGroupOut, status_code=status.HTTP_201_CREATED
)
async def create_product_group(
    data: ProductGroupCreate,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    if (await session.execute(
        select(ProductGroup).where(ProductGroup.name == data.name)
    )).scalar_one_or_none():
        raise HTTPException(400, f"Группа «{data.name}» уже существует")
    g = ProductGroup(**data.model_dump())
    session.add(g)
    await session.commit()
    await session.refresh(g)
    return g


@admin_product_groups_router.patch("/{group_id}", response_model=ProductGroupOut)
async def update_product_group(
    group_id: int,
    data: ProductGroupUpdate,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    g = (await session.execute(
        select(ProductGroup).where(ProductGroup.id == group_id)
    )).scalar_one_or_none()
    if not g:
        raise HTTPException(404, "Группа не найдена")
    patch = data.model_dump(exclude_unset=True)
    if "name" in patch and patch["name"] and patch["name"] != g.name:
        if (await session.execute(
            select(ProductGroup).where(ProductGroup.name == patch["name"])
        )).scalar_one_or_none():
            raise HTTPException(400, f"Группа «{patch['name']}» уже существует")
    new_name = patch.get("name", g.name)
    for k, v in patch.items():
        setattr(g, k, v)
    # Синхронизируем legacy строку products.group у привязанных продуктов.
    if "name" in patch and patch["name"]:
        await _sync_product_group_string(session, group_id, new_name)
    await session.commit()
    await session.refresh(g)
    return g


@admin_product_groups_router.delete("/{group_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_product_group(
    group_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить группу. Если на неё ссылаются продукты — реассайним их group_id→NULL
    (legacy строка products.group остаётся как есть для отображения), затем удаляем.
    """
    g = (await session.execute(
        select(ProductGroup).where(ProductGroup.id == group_id)
    )).scalar_one_or_none()
    if not g:
        raise HTTPException(404, "Группа не найдена")
    products = (await session.execute(
        select(Product).where(Product.group_id == group_id)
    )).scalars().all()
    for p in products:
        p.group_id = None
    await session.flush()
    await session.delete(g)
    await session.commit()


async def _sync_product_group_string(
    session: AsyncSession, group_id: int, new_name: str
) -> None:
    """Протянуть переименование группы в legacy строку products.group."""
    products = (await session.execute(
        select(Product).where(Product.group_id == group_id)
    )).scalars().all()
    for p in products:
        p.group = new_name
