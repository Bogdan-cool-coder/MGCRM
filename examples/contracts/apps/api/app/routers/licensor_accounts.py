"""Дополнительные банковские счета лицензиара (по валютам)."""

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict
from sqlalchemy import update
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import LicensorBankAccount, LicensorEntity

router = APIRouter(prefix="/licensors", tags=["licensor-accounts"])


class AccountIn(BaseModel):
    currency: str
    bank: str
    bank_code_label: str
    bank_code: str
    account: str
    swift: str | None = None
    is_primary: bool = False
    note: str | None = None


class AccountOut(AccountIn):
    model_config = ConfigDict(from_attributes=True)
    id: int
    licensor_id: int


@router.get("/{licensor_id}/accounts", response_model=list[AccountOut])
async def list_accounts(
    licensor_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    rows = (await session.execute(
        select(LicensorBankAccount).where(LicensorBankAccount.licensor_id == licensor_id).order_by(LicensorBankAccount.currency, LicensorBankAccount.id)
    )).scalars().all()
    return rows


@router.post("/{licensor_id}/accounts", response_model=AccountOut, status_code=status.HTTP_201_CREATED)
async def create_account(
    licensor_id: int,
    payload: AccountIn,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    lic = (await session.execute(select(LicensorEntity).where(LicensorEntity.id == licensor_id))).scalar_one_or_none()
    if not lic:
        raise HTTPException(404, "Лицензиар не найден")
    if payload.is_primary:
        await session.execute(
            update(LicensorBankAccount)
            .where(LicensorBankAccount.licensor_id == licensor_id, LicensorBankAccount.currency == payload.currency)
            .values(is_primary=False)
        )
    acc = LicensorBankAccount(licensor_id=licensor_id, **payload.model_dump())
    session.add(acc)
    await session.commit()
    await session.refresh(acc)
    return acc


@router.patch("/{licensor_id}/accounts/{account_id}", response_model=AccountOut)
async def update_account(
    licensor_id: int,
    account_id: int,
    payload: AccountIn,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    acc = (await session.execute(select(LicensorBankAccount).where(LicensorBankAccount.id == account_id))).scalar_one_or_none()
    if not acc or acc.licensor_id != licensor_id:
        raise HTTPException(404, "Не найден")
    if payload.is_primary and not acc.is_primary:
        await session.execute(
            update(LicensorBankAccount)
            .where(LicensorBankAccount.licensor_id == licensor_id, LicensorBankAccount.currency == payload.currency)
            .values(is_primary=False)
        )
    for k, v in payload.model_dump().items():
        setattr(acc, k, v)
    await session.commit()
    await session.refresh(acc)
    return acc


@router.delete("/{licensor_id}/accounts/{account_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_account(
    licensor_id: int,
    account_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    acc = (await session.execute(select(LicensorBankAccount).where(LicensorBankAccount.id == account_id))).scalar_one_or_none()
    if not acc or acc.licensor_id != licensor_id:
        raise HTTPException(404, "Не найден")
    await session.delete(acc)
    await session.commit()
