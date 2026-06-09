"""Производный остаток счёта + trial balance (Ф0, инварианты №1/№2).

НИКАКОГО хранимого поля остатка: остаток денежного счёта = initial_balance + Σ
знаковых amount по его ledger lines из ЖИВЫХ проводок (status IN ('posted',
'reversed')). Сторно гасит оригинал суммой обеих половин: оригинал (reversed) +
зеркало (posted) = 0. Если считать только 'posted', останется лишь зеркало сторно
(−original вместо 0) — баг B7 CRIT-1. 'draft' (план/черновик) не считается.

Чистое ядро (`sum_signed`, `trial_balance_ok`) — для pure-тестов мультивалюты и
баланса; async-функции читают fin_ledger_line.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date
from decimal import ROUND_HALF_UP, Decimal

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import FinJournalEntry, FinLedgerLine, FinLegalEntity, FinMoneyAccount
from app.services.finance import fx

#: Живые статусы проекции остатка/целостности: posted + reversed (обе половины
#: сторно гасятся в 0). 'draft' исключён. См. B7 CRIT-1.
_LIVE_STATUSES = ("posted", "reversed")


def _q2(value: Decimal) -> Decimal:
    return value.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


@dataclass
class MoneyBalance:
    """Остаток денежного счёта в валюте счёта и в функц.валюте юрлица."""

    money_account_id: int
    currency: str
    amount: Decimal  # в валюте счёта
    amount_func: Decimal  # в функц.валюте юрлица


def sum_signed(values: list[Decimal]) -> Decimal:
    """Σ знаковых сумм (Дт>0 / Кт<0) → 2 знака. Pure. Основа остатка и trial balance."""
    return _q2(sum(values, Decimal("0")))


def trial_balance_ok(amount_func_values: list[Decimal]) -> bool:
    """True ⇔ Σ всех amount_func == 0 (контроль целостности GL; инвариант №2). Pure."""
    return sum_signed(amount_func_values) == Decimal("0")


async def money_account_balance(
    session: AsyncSession,
    money_account: FinMoneyAccount,
    *,
    on_date: date | None = None,
) -> MoneyBalance:
    """Остаток денежного счёта = initial_balance + Σ ЖИВЫХ строк (валюта счёта + func).

    ВАЛЮТНАЯ СЕМАНТИКА initial_balance (важно — иначе складываем разнородные валюты):
      `money_account.initial_balance` хранится/трактуется В ВАЛЮТЕ СЧЁТА
      (`money_account.currency`).
      • amount (в валюте счёта): прибавляем initial КАК ЕСТЬ (валюта совпадает).
      • amount_func (в функц.валюте юрлица): initial нужно СКОНВЕРТИРОВАТЬ по курсу.
        Если валюта счёта == функц.валюта юрлица → прибавляем как есть. Иначе —
        конвертируем по строгому курсу на `on_date` (или сегодня, если on_date=None).
    Складывать сырой initial (в валюте счёта) с func_sum (в функц.валюте) НЕЛЬЗЯ.

    По дизайну Ф0 у всех сидов initial_balance=0 (ввод сальдо делается проводкой
    build_opening, а не этим полем), поэтому ветка конвертации обычно не активна —
    но математика верна и для ненулевого initial.

    on_date — верхняя граница (включительно) по entry.date; None = на текущий момент.
    status IN ('posted','reversed') — учитываем обе половины сторно: оригинал
    (reversed) + зеркало (posted) гасятся в 0. Черновики ('draft') не считаются.
    """
    q_amount = (
        select(
            func.coalesce(func.sum(FinLedgerLine.amount), Decimal("0")),
            func.coalesce(func.sum(FinLedgerLine.amount_func), Decimal("0")),
        )
        .join(FinJournalEntry, FinLedgerLine.journal_entry_id == FinJournalEntry.id)
        .where(
            FinLedgerLine.money_account_id == money_account.id,
            FinJournalEntry.status.in_(_LIVE_STATUSES),
        )
    )
    if on_date is not None:
        q_amount = q_amount.where(FinJournalEntry.date <= on_date)

    amount_sum, func_sum = (await session.execute(q_amount)).one()

    initial = money_account.initial_balance or Decimal("0")
    initial_func = await _initial_in_func(session, money_account, initial, on_date)
    return MoneyBalance(
        money_account_id=money_account.id,
        currency=money_account.currency,
        amount=_q2(initial + amount_sum),  # initial в валюте счёта — совпадает
        amount_func=_q2(initial_func + func_sum),  # initial сконвертирован в func
    )


async def _initial_in_func(
    session: AsyncSession,
    money_account: FinMoneyAccount,
    initial: Decimal,
    on_date: date | None,
) -> Decimal:
    """initial_balance (в валюте счёта) → функц.валюту юрлица по строгому курсу.

    initial==0 → 0 без запроса курса (не дёргаем FX зря; покрывает дизайн Ф0).
    Валюта счёта == функц.валюта → initial как есть. Иначе строгий курс на on_date.
    """
    if initial == Decimal("0"):
        return Decimal("0")
    func_ccy = (
        await session.execute(
            select(FinLegalEntity.functional_currency).where(
                FinLegalEntity.id == money_account.legal_entity_id
            )
        )
    ).scalar_one()
    if money_account.currency == func_ccy:
        return initial
    rate_date = on_date or date.today()
    rate = await fx.get_rate_strict(
        session, money_account.currency, func_ccy, rate_date
    )
    return _q2(initial * rate)


async def trial_balance(
    session: AsyncSession,
    legal_entity_id: int,
    *,
    on_date: date | None = None,
) -> Decimal:
    """Σ amount_func всех ЖИВЫХ строк юрлица (posted+reversed). 0 (иначе баг постинга)."""
    q = (
        select(func.coalesce(func.sum(FinLedgerLine.amount_func), Decimal("0")))
        .join(FinJournalEntry, FinLedgerLine.journal_entry_id == FinJournalEntry.id)
        .where(
            FinLedgerLine.legal_entity_id == legal_entity_id,
            FinJournalEntry.status.in_(_LIVE_STATUSES),
        )
    )
    if on_date is not None:
        q = q.where(FinJournalEntry.date <= on_date)
    return _q2((await session.execute(q)).scalar_one())
