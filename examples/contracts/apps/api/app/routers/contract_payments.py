"""Epic 10.5 — Contract Payments CRUD.

Платежи по договорам. Используются для расчёта комиссии менеджеров.

GET    /api/contracts/{id}/payments — список платежей договора
POST   /api/contracts/{id}/payments — добавить платёж
PATCH  /api/contract-payments/{id} — обновить платёж
DELETE /api/contract-payments/{id} — удалить платёж

При POST автоматически вычисляется is_first_payment_from_counterparty.
"""
from __future__ import annotations

import logging
from datetime import date, datetime
from decimal import Decimal
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func
from sqlalchemy import func as sa_func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from sqlalchemy import or_

from app.db import get_session
from app.deps import CurrentUser
from app.models import Contract, ContractPayment, Deal, User, UserRole

router = APIRouter(tags=["contract-payments"])
payments_router = APIRouter(prefix="/contract-payments", tags=["contract-payments"])

# B7 (race-fix): namespace для per-counterparty advisory-lock «первый платёж».
# pg_advisory_xact_lock(ns, subkey) — двухаргументная int4-форма; ns изолирует
# наш лок от других пространств (recognition/recompute), subkey = counterparty_id.
_FIRST_PAYMENT_LOCK_NS = 821_734


def _counterparty_lock_subkey(counterparty_id: int) -> int:
    """Привести counterparty_id к знаковому int4-диапазону для advisory-lock subkey.

    pg_advisory_xact_lock(int4, int4) принимает только int4; id может вырасти за
    его пределы. Коллизии безвредны (в худшем случае два несвязанных контрагента
    сериализуются — корректность first-payment не страдает, лишь лишняя
    сериализация). Pure.
    """
    raw = counterparty_id & 0x7FFFFFFF
    return raw - 0x80000000 if raw >= 0x40000000 else raw


class ContractPaymentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    contract_id: int | None
    counterparty_id: int | None
    amount: Decimal
    currency: str
    payment_date: date
    attributed_to_user_id: int | None
    is_first_payment_from_counterparty: bool
    notes: str | None
    created_at: datetime


class ContractPaymentIn(BaseModel):
    amount: Decimal = Field(..., gt=0)
    currency: str = Field(..., max_length=8)
    payment_date: date
    attributed_to_user_id: int | None = None
    notes: str | None = None


class ContractPaymentPatch(BaseModel):
    amount: Decimal | None = Field(None, gt=0)
    currency: str | None = Field(None, max_length=8)
    payment_date: date | None = None
    attributed_to_user_id: int | None = None
    notes: str | None = None


@router.get("/contracts/{contract_id}/payments", response_model=list[ContractPaymentOut])
async def list_contract_payments(
    contract_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> list[ContractPaymentOut]:
    contract = (
        await session.execute(select(Contract).where(Contract.id == contract_id))
    ).scalar_one_or_none()
    if contract is None:
        raise HTTPException(status_code=404, detail="Contract not found")

    # IDOR-гейт: раньше Contract грузился только для existence-проверки, а затем
    # отдавались ВСЕ платежи — любой залогиненный читал чужие оплаты (и суммы).
    # ensure_object_visible резолвит scope (admin/director → all) и 404'ит чужой
    # договор по полю author_user_id (см. _PERSONAL_OWNER_FIELDS["contract"]).
    from app.services.access_control import ensure_object_visible

    await ensure_object_visible(session, contract, "contract", current_user)

    rows = list(
        (
            await session.execute(
                select(ContractPayment)
                .where(ContractPayment.contract_id == contract_id)
                .order_by(ContractPayment.payment_date.desc())
            )
        ).scalars().all()
    )
    return rows


@router.post(
    "/contracts/{contract_id}/payments",
    response_model=ContractPaymentOut,
    status_code=status.HTTP_201_CREATED,
)
async def add_contract_payment(
    contract_id: int,
    body: ContractPaymentIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> ContractPaymentOut:
    """Добавить платёж.

    Право фиксировать оплату имеют: admin/director, автор договора, а также
    владелец сделки, причастной к этому договору. Последнее критично для
    win-gate: SuccessGateModal сделки зовёт этот эндпоинт, и менеджер-владелец
    сделки (не обязательно автор договора) должен иметь возможность пройти
    гейт оплаты, иначе гейт непроходим (баг аудита).
    """
    contract = (
        await session.execute(select(Contract).where(Contract.id == contract_id))
    ).scalar_one_or_none()
    if contract is None:
        raise HTTPException(status_code=404, detail="Contract not found")

    if current_user.role not in (UserRole.admin, UserRole.director):
        authorized = contract.author_user_id == current_user.id
        if not authorized:
            # Причастность через сделку: либо сделка прямо ссылается на этот
            # договор (Deal.contract_id), либо связана с тем же контрагентом/
            # компанией. Достаточно одной сделки, которой владеет текущий юзер.
            deal_match = [Deal.contract_id == contract_id]
            if contract.company_id is not None:
                deal_match.append(Deal.company_id == contract.company_id)
            if contract.counterparty_id is not None:
                deal_match.append(Deal.counterparty_id == contract.counterparty_id)
            owns_related_deal = (
                await session.execute(
                    select(Deal.id)
                    .where(Deal.owner_user_id == current_user.id)
                    .where(or_(*deal_match))
                    .limit(1)
                )
            ).first()
            authorized = owns_related_deal is not None
        if not authorized:
            raise HTTPException(status_code=403, detail="Not authorized")

    # Деньги-гейт (commission self-attribution): salary.py платит комиссию по
    # attributed_to_user_id. Раньше это поле бралось из body для любой роли —
    # менеджер мог атрибутировать комиссию себе/любому. Non-elevated (НЕ admin/
    # director) НЕ управляют атрибуцией: форсим контракт-автора, который и есть
    # авторизованный получатель по этому договору. Override на произвольного
    # юзера разрешён только admin/director.
    is_elevated = current_user.role in (UserRole.admin, UserRole.director)
    if is_elevated:
        attributed_to_user_id = body.attributed_to_user_id or contract.author_user_id
    else:
        attributed_to_user_id = contract.author_user_id

    # Определить is_first_payment_from_counterparty.
    #
    # B7 (race-fix): без блокировки два конкурентных «первых платежа» одного
    # контрагента (scale=2) оба видят existing_count==0 → оба is_first=True →
    # комиссионный first-payment-бонус начисляется дважды (деньги). Берём
    # per-counterparty advisory-xact-lock ПЕРЕД подсчётом, в той же транзакции:
    # второй POST ждёт коммита/отката первого, затем видит уже вставленный платёж
    # и корректно проставляет is_first=False. Лок снимается с завершением
    # транзакции (session.commit() ниже). Уникальный индекс не добавляем — это
    # потребовало бы миграции (зона другого агента).
    is_first = False
    if contract.counterparty_id:
        await session.execute(
            select(
                sa_func.pg_advisory_xact_lock(
                    _FIRST_PAYMENT_LOCK_NS,
                    _counterparty_lock_subkey(contract.counterparty_id),
                )
            )
        )
        existing_count = (
            await session.execute(
                select(func.count(ContractPayment.id)).where(
                    ContractPayment.counterparty_id == contract.counterparty_id,
                )
            )
        ).scalar_one()
        is_first = existing_count == 0

    row = ContractPayment(
        contract_id=contract_id,
        counterparty_id=contract.counterparty_id,
        amount=body.amount,
        currency=body.currency,
        payment_date=body.payment_date,
        attributed_to_user_id=attributed_to_user_id,
        is_first_payment_from_counterparty=is_first,
        notes=body.notes,
    )
    session.add(row)
    await session.commit()
    await session.refresh(row)

    # Ф3 (финансы): write-through ContractPayment → зеркальная income fin_operation
    # (source='contract_payment', идемпотентно). КЛЮЧЕВОЕ: НЕ влияет на расчёт комиссий
    # (комиссии читают ContractPayment, который тут уже зафиксирован). Best-effort —
    # сбой GL-зеркала не должен валить фиксацию оплаты (win-gate сделки критичен).
    await _write_through_contract_payment_safe(session, row, current_user.id)
    return row


async def _write_through_contract_payment_safe(
    session: AsyncSession, payment: ContractPayment, user_id: int
) -> None:
    """Best-effort зеркалирование ContractPayment в GL (Ф3). Не бросает наружу."""
    from app.services.finance import pay_integration

    try:
        await pay_integration.integrate_contract_payment(
            session, payment, posted_by_user_id=user_id
        )
        await session.commit()
    except Exception:  # noqa: BLE001 — GL-сбой не валит фиксацию оплаты
        await session.rollback()
        logging.getLogger("finance.integration").warning(
            "Ф3: не удалось зеркалировать ContractPayment #%s в GL (отложено)",
            payment.id,
            exc_info=True,
        )


@payments_router.patch("/{payment_id}", response_model=ContractPaymentOut)
async def update_contract_payment(
    payment_id: int,
    body: ContractPaymentPatch,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> ContractPaymentOut:
    row = (
        await session.execute(
            select(ContractPayment).where(ContractPayment.id == payment_id)
        )
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=404, detail="ContractPayment not found")

    if current_user.role not in (UserRole.admin, UserRole.director):
        raise HTTPException(status_code=403, detail="Only admin/director can edit payments")

    for k, v in body.model_dump(exclude_none=True).items():
        setattr(row, k, v)
    await session.commit()
    await session.refresh(row)

    # Ф3 (B3 WARN-4): после правки платежа пере-синхронизируем GL-зеркало дохода, иначе
    # P&L дрейфует (старая сумма/дата висит в проводке). Best-effort, отдельный commit,
    # НЕ трогает комиссии (читают ContractPayment, который уже сохранён выше).
    await _resync_gl_safe(session, row, current_user.id)
    return row


async def _resync_gl_safe(
    session: AsyncSession, payment: ContractPayment, user_id: int
) -> None:
    """Best-effort пере-синхронизация GL-зеркала после правки платежа. Не бросает наружу."""
    from app.services.finance import pay_integration

    try:
        await pay_integration.resync_contract_payment(
            session, payment, posted_by_user_id=user_id
        )
        await session.commit()
    except Exception as e:  # noqa: BLE001 — GL-сбой не валит правку платежа
        await session.rollback()
        import sentry_sdk
        sentry_sdk.capture_exception(e)
        logging.getLogger("finance.integration").warning(
            "Ф3: не удалось пере-синхронизировать GL-зеркало ContractPayment #%s",
            payment.id,
            exc_info=True,
        )


@payments_router.delete("/{payment_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_contract_payment(
    payment_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> None:
    row = (
        await session.execute(
            select(ContractPayment).where(ContractPayment.id == payment_id)
        )
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(status_code=404, detail="ContractPayment not found")

    if current_user.role not in (UserRole.admin, UserRole.director):
        raise HTTPException(status_code=403, detail="Only admin/director can delete payments")

    payment_id_ = row.id
    await session.delete(row)
    await session.commit()

    # Ф3 (B3 WARN-4): после удаления платежа сторнируем GL-зеркало дохода (reversal —
    # единственный способ снять проведённый факт, инвариант №4). Best-effort, отдельный
    # commit; комиссии не затрагиваются.
    from app.services.finance import pay_integration

    try:
        await pay_integration.void_contract_payment(
            session, payment_id_, posted_by_user_id=current_user.id
        )
        await session.commit()
    except Exception as e:  # noqa: BLE001 — GL-сбой не валит удаление платежа
        await session.rollback()
        import sentry_sdk
        sentry_sdk.capture_exception(e)
        logging.getLogger("finance.integration").warning(
            "Ф3: не удалось сторнировать GL-зеркало ContractPayment #%s после удаления",
            payment_id_,
            exc_info=True,
        )
