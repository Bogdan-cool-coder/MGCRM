"""Юр.лица лицензиара (наши компании в KZ/UZ/...)."""

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import LicensorEntity

router = APIRouter(prefix="/licensors", tags=["licensors"])


class LicensorIn(BaseModel):
    country_code: str
    legal_form: str
    full_legal_form: str
    gender_ending_oe: str = "ое"
    name: str
    director_position: str = "Директора"
    director_short: str
    director_genitive: str
    acts_basis: str = "Устава"
    tax_id_label: str
    tax_id: str
    address: str
    bank: str
    bank_code_label: str
    bank_code: str
    account: str
    phone: str | None = None
    email: str | None = None
    website: str | None = None
    training_login: str | None = None


class LicensorOut(LicensorIn):
    model_config = ConfigDict(from_attributes=True)
    id: int


@router.get("", response_model=list[LicensorOut])
async def list_licensors(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    return (await session.execute(select(LicensorEntity).order_by(LicensorEntity.country_code))).scalars().all()


@router.post("", response_model=LicensorOut, status_code=status.HTTP_201_CREATED)
async def create_licensor(
    payload: LicensorIn,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    existing = (await session.execute(
        select(LicensorEntity).where(LicensorEntity.country_code == payload.country_code)
    )).scalar_one_or_none()
    if existing:
        raise HTTPException(400, f"Юр.лицо для {payload.country_code.upper()} уже существует, отредактируйте его")
    entity = LicensorEntity(**payload.model_dump())
    session.add(entity)
    await session.commit()
    await session.refresh(entity)
    return entity


@router.patch("/{lic_id}", response_model=LicensorOut)
async def update_licensor(
    lic_id: int,
    payload: LicensorIn,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    entity = (await session.execute(select(LicensorEntity).where(LicensorEntity.id == lic_id))).scalar_one_or_none()
    if not entity:
        raise HTTPException(404, "Не найден")
    for k, v in payload.model_dump().items():
        setattr(entity, k, v)
    await session.commit()
    await session.refresh(entity)
    return entity
