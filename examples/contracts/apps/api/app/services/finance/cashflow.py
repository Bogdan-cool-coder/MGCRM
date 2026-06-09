"""Проекция ДДС прямым методом (Ф0 — простой отчёт; полный UI → Ф1).

ИНВАРИАНТ №8 (by construction): в ДДС попадают ТОЛЬКО денежные строки с
проставленной статьёй —
    money_account_id IS NOT NULL  AND  cashflow_category_id IS NOT NULL
    AND journal_entry.status IN ('posted','reversed')
⇒ переводы (cashflow_category=NULL) исключены САМОЙ ФОРМУЛОЙ, без флагов.
Сторно учитывается ОБЕИМИ половинами: оригинал (status='reversed') даёт исходный
приток/отток, reversal-entry (status='posted') — зеркальный, в сумме 0. Если
оставить только 'posted', в проекции останется лишь зеркало сторно (−original
вместо 0) — это был баг B7 CRIT-1. 'draft' исключён (ещё не проведено).

Знак amount: приток (Дт денежного) > 0, отток (Кт денежного) < 0.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date
from decimal import ROUND_HALF_UP, Decimal

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import FinCashflowCategory, FinJournalEntry, FinLedgerLine

#: Живые статусы проекции: posted + reversed (обе половины сторно гасятся в 0).
#: 'draft' исключён. См. B7 CRIT-1.
_LIVE_STATUSES = ("posted", "reversed")


def _q2(value: Decimal) -> Decimal:
    return value.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


@dataclass
class CashflowRow:
    """Строка ДДС: статья + чистый поток (в func-валюте юрлица)."""

    category_id: int
    category_code: str
    category_name: str
    activity: str  # operating|investing|financing (для группировки в отчёте)
    direction: str  # inflow|outflow|both
    net_func: Decimal  # Σ amount_func (приток>0 / отток<0)


def classify_flow(net_func: Decimal) -> str:
    """Направление потока по знаку (приток/отток). Pure-помощник для отчёта/тестов."""
    if net_func > 0:
        return "inflow"
    if net_func < 0:
        return "outflow"
    return "neutral"


async def cashflow_by_category(
    session: AsyncSession,
    legal_entity_id: int,
    *,
    date_from: date,
    date_to: date,
) -> list[CashflowRow]:
    """ДДС прямым методом за период [date_from, date_to] по статьям (в func-валюте).

    Фильтр инварианта №8 встроен: money_account_id IS NOT NULL И
    cashflow_category_id IS NOT NULL И status IN ('posted','reversed'). Переводы —
    исключены формулой; сторно гасится обеими половинами (posted+reversed → 0).
    Группировка по статье, Σ amount_func со знаком.
    """
    q = (
        select(
            FinCashflowCategory.id,
            FinCashflowCategory.code,
            FinCashflowCategory.name,
            FinCashflowCategory.activity,
            FinCashflowCategory.direction,
            func.coalesce(func.sum(FinLedgerLine.amount_func), Decimal("0")),
        )
        .join(
            FinJournalEntry,
            FinLedgerLine.journal_entry_id == FinJournalEntry.id,
        )
        .join(
            FinCashflowCategory,
            FinLedgerLine.cashflow_category_id == FinCashflowCategory.id,
        )
        .where(
            FinLedgerLine.legal_entity_id == legal_entity_id,
            FinLedgerLine.money_account_id.isnot(None),
            FinLedgerLine.cashflow_category_id.isnot(None),
            FinJournalEntry.status.in_(_LIVE_STATUSES),
            FinJournalEntry.date >= date_from,
            FinJournalEntry.date <= date_to,
        )
        .group_by(
            FinCashflowCategory.id,
            FinCashflowCategory.code,
            FinCashflowCategory.name,
            FinCashflowCategory.activity,
            FinCashflowCategory.direction,
        )
        .order_by(FinCashflowCategory.code)
    )
    rows = (await session.execute(q)).all()
    return [
        CashflowRow(
            category_id=cid,
            category_code=code,
            category_name=name,
            activity=activity,
            direction=direction,
            net_func=_q2(net),
        )
        for (cid, code, name, activity, direction, net) in rows
    ]


async def cashflow_totals(
    session: AsyncSession,
    legal_entity_id: int,
    *,
    date_from: date,
    date_to: date,
) -> tuple[Decimal, Decimal, Decimal]:
    """Итоги ДДС за период: (приток, отток, чистый поток) в func-валюте.

    Приток = Σ положительных denmonetary-строк, отток = Σ отрицательных (как
    отрицательное число), net = приток + отток. Тот же фильтр инварианта №8.
    """
    rows = await cashflow_by_category(
        session, legal_entity_id, date_from=date_from, date_to=date_to
    )
    inflow = _q2(sum((r.net_func for r in rows if r.net_func > 0), Decimal("0")))
    outflow = _q2(sum((r.net_func for r in rows if r.net_func < 0), Decimal("0")))
    return inflow, outflow, _q2(inflow + outflow)
