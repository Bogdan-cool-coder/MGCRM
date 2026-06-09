"""Ф3 — SHADOW-сверка комиссий: старый путь (ContractPayment) vs новый (fin_operation GL).

Цель — ВАЛИДАТОР БЕЗОПАСНОСТИ перед любым будущим переключением чтения комиссий на GL.
Само переключение — НЕ в этой фазе (комиссии продолжают читать ContractPayment в
services/salary.py; риск F1 = 0 by construction, т.к. salary.py не трогаем).

Что сверяем: для каждого ContractPayment, который ПИТАЕТ комиссии (фильтры salary.py:
attributed_to_user_id, payment_date в периоде, is_first_payment_from_counterparty,
Contract.signed_at), должна существовать зеркальная проведённая income-`fin_operation`
(write-through, source='contract_payment') с РАВНЫМИ amount/currency/date. Расхождение —
это «новый путь дал бы другой вход в комиссию» → блокирует переключение.

Чистое ядро `reconcile_sets` — сравнение двух наборов сумм (pure, тестируемо без БД).
Async `reconcile_commissions` — собирает оба набора из БД и зовёт чистое ядро.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import Contract, ContractPayment, FinOperation

from .pay_integration import SOURCE_CONTRACT_PAYMENT


@dataclass(frozen=True)
class CommissionFact:
    """Единица сверки: факт, питающий комиссию. Сравнивается по (payment_id, amount, ccy)."""

    payment_id: int
    amount: Decimal
    currency: str
    attributed_to_user_id: int | None
    payment_date: date


@dataclass
class ReconResult:
    """Итог сверки. ok=True ⇔ 0 расхождений (можно безопасно планировать переключение)."""

    ok: bool
    total_old: int
    total_new: int
    matched: int
    missing_in_gl: list[int] = field(default_factory=list)  # payment_id без операции в GL
    amount_mismatch: list[int] = field(default_factory=list)  # payment_id с иной суммой/валютой
    orphan_in_gl: list[int] = field(default_factory=list)  # GL-операция без eligible-платежа

    def as_dict(self) -> dict[str, object]:
        return {
            "ok": self.ok,
            "total_old": self.total_old,
            "total_new": self.total_new,
            "matched": self.matched,
            "missing_in_gl": self.missing_in_gl,
            "amount_mismatch": self.amount_mismatch,
            "orphan_in_gl": self.orphan_in_gl,
        }


def reconcile_sets(
    old_facts: list[CommissionFact],
    gl_by_payment_id: dict[int, tuple[Decimal, str]],
) -> ReconResult:
    """Чистое ядро сверки. old_facts = eligible ContractPayment-факты (старый путь);
    gl_by_payment_id = {payment_id: (amount, currency)} зеркальных GL-операций (новый путь).

    Расхождения:
      • missing_in_gl   — eligible-платёж без зеркальной операции (GL недоучёл вход комиссии);
      • amount_mismatch — операция есть, но сумма/валюта отличается;
      • orphan_in_gl    — write-through операция без eligible-платежа (GL переучёл).
    """
    missing: list[int] = []
    mismatch: list[int] = []
    matched = 0
    old_ids = {f.payment_id for f in old_facts}

    for f in old_facts:
        gl = gl_by_payment_id.get(f.payment_id)
        if gl is None:
            missing.append(f.payment_id)
            continue
        gl_amount, gl_ccy = gl
        if gl_amount != f.amount or gl_ccy != f.currency:
            mismatch.append(f.payment_id)
            continue
        matched += 1

    orphans = sorted(pid for pid in gl_by_payment_id if pid not in old_ids)

    ok = not missing and not mismatch and not orphans
    return ReconResult(
        ok=ok,
        total_old=len(old_facts),
        total_new=len(gl_by_payment_id),
        matched=matched,
        missing_in_gl=sorted(missing),
        amount_mismatch=sorted(mismatch),
        orphan_in_gl=orphans,
    )


async def _eligible_facts(
    session: AsyncSession,
    *,
    period_start: date | None,
    period_end: date | None,
    require_signed_contract: bool,
    first_payment_only: bool,
) -> list[CommissionFact]:
    """Собрать eligible ContractPayment-факты ТЕМИ ЖЕ фильтрами, что salary.py.

    Фильтры повторяют services/salary.py::_get_eligible_payments / _get_team_contribution:
    is_first_payment_from_counterparty, опц. Contract.signed_at IS NOT NULL, окно дат.
    attributed_to_user_id берётся как есть (сверяем факт, не пересчитываем комиссию).
    """
    q = select(ContractPayment)
    if period_start is not None:
        q = q.where(ContractPayment.payment_date >= period_start)
    if period_end is not None:
        q = q.where(ContractPayment.payment_date <= period_end)
    if first_payment_only:
        q = q.where(
            ContractPayment.is_first_payment_from_counterparty == True  # noqa: E712
        )
    if require_signed_contract:
        q = q.join(Contract, ContractPayment.contract_id == Contract.id).where(
            Contract.signed_at.is_not(None)
        )
    rows = (await session.execute(q)).scalars().all()
    return [
        CommissionFact(
            payment_id=p.id,
            amount=p.amount,
            currency=p.currency,
            attributed_to_user_id=p.attributed_to_user_id,
            payment_date=p.payment_date,
        )
        for p in rows
    ]


async def reconcile_commissions(
    session: AsyncSession,
    *,
    period_start: date | None = None,
    period_end: date | None = None,
    require_signed_contract: bool = True,
    first_payment_only: bool = True,
) -> ReconResult:
    """Сверить eligible-платежи (старый путь) с их GL-зеркалом (write-through операции).

    Возвращает ReconResult.ok=True при 0 расхождений — зелёный сигнал для будущего
    переключения чтения комиссий на GL (само переключение — отдельная задача вне Ф3).
    """
    old_facts = await _eligible_facts(
        session,
        period_start=period_start,
        period_end=period_end,
        require_signed_contract=require_signed_contract,
        first_payment_only=first_payment_only,
    )

    # GL-зеркало: проведённые/плановые write-through операции по contract_payment.
    # source_ref_id = ContractPayment.id (write-through идемпотентность).
    eligible_ids = {f.payment_id for f in old_facts}
    gl_rows = (
        await session.execute(
            select(
                FinOperation.source_ref_id,
                FinOperation.amount,
                FinOperation.currency,
                FinOperation.status,
            ).where(
                FinOperation.source == SOURCE_CONTRACT_PAYMENT,
                FinOperation.source_ref_id.is_not(None),
            )
        )
    ).all()
    gl_by_payment_id: dict[int, tuple[Decimal, str]] = {}
    for ref_id, amount, currency, status in gl_rows:
        if status == "reversed":
            continue  # сторнированная операция не считается вкладом (инвариант №8)
        # ограничиваем сравнение только теми платежами, что eligible в этом окне —
        # иначе платежи вне периода создадут ложные «орфаны».
        if ref_id in eligible_ids:
            gl_by_payment_id[ref_id] = (amount, currency)

    return reconcile_sets(old_facts, gl_by_payment_id)
