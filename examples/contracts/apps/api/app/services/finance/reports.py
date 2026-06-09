"""Отчёты ядра GL (Ф1) — проекции fin_ledger_line.

ВСЕ отчёты — ПРОЕКЦИИ единой таблицы проводок (инвариант №1): фильтр по
status IN ('posted','reversed') — учитываем ОБЕ половины сторно. Проведённая
проводка (posted) и её сторно (posted) гасятся, давая 0. ОРИГИНАЛ сторно помечен
'reversed', но его строки реальны и должны участвовать в сумме — иначе в проекции
останется только зеркало сторно (−original вместо 0). Из проекций исключаются лишь
'draft' (черновики/планы — ещё не проведены). Переводы исключаются из ДДС/P&L by
construction (cashflow_category_id=NULL и отсутствие income/expense-строки). Деньги —
Decimal, считаем в ФУНКЦИОНАЛЬНОЙ валюте юрлица (`amount_func`) — единственная
балансируемая величина (инвариант №2).

Чистые ядра (`pnl_from_rows`, `arap_from_rows`, `trial_balance_rows`) тестируются без
БД на duck-typed строках; async-функции читают fin_ledger_line + fin_account_gl.

Граница Ф1 (см. I_phase0_dev_plan §0):
  • P&L-lite       — доход (4xxx) − расход (5xxx) по счетам/типам.
  • Trial balance  — дебет/кредит обороты + сальдо по GL-счёту; Σ Дт = Σ Кт.
  • GL-журнал      — листинг проводок со строками (read-only «главная книга»).
  • AR/AP-сальдо   — остаток 1210/1290 (дебиторка) / 2110/2210 (кредиторка) по
                     контрагенту. ОГРАНИЧЕНИЕ: это сальдо-агрегат из ledger lines;
                     полные книги/инвойсные AR/AP (по документам, возраст долга) — Ф5.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from decimal import ROUND_HALF_UP, Decimal

from sqlalchemy import exists, func, select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import aliased

from app.models import FinAccountGl, FinJournalEntry, FinLedgerLine

#: Статусы проводок, ЖИВЫХ в проекциях: posted (проведено) + reversed (оригинал
#: сторно — реален, гасится своим reversal-entry). 'draft' исключён by design.
#: Сумма строк posted+reversed по сторнированной паре = 0 (B7 CRIT-1 fix).
_LIVE_STATUSES = ("posted", "reversed")
_INCOME = "income"
_EXPENSE = "expense"
#: GL-классы AR (дебиторка, normal_side=Дт) и AP (кредиторка, normal_side=Кт).
AR_SUBTYPES = ("ar",)
AP_SUBTYPES = ("ap",)

# P&L basis (Ф4): accrual (по начислению) vs cash (по деньгам).
#   accrual — КАНОНИЧЕСКИЙ P&L (G §1.6): ВСЕ income/expense-строки, posted, за период.
#     Выручка признаётся по начислению (инвойс/recognition), расход — по акту/выплате.
#   cash    — выручка/расход признаётся ТОЛЬКО когда двигались деньги: income/expense-
#     строки, чья ПРОВОДКА содержит денежную строку (money_account≠NULL) — это cash_in/
#     cash_out и их сторно. Чисто-начисленная (неоплаченная) выручка инвойса/recognition
#     (money_account=NULL во всей проводке) в cash-P&L НЕ попадает; оплата гасит AR (не
#     income) → не задваивает. Разница accrual−cash = неоплаченные начисления периода.
BASIS_ACCRUAL = "accrual"
BASIS_CASH = "cash"


def _q2(value: Decimal) -> Decimal:
    return value.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


# ───────────────────────────── P&L-lite ─────────────────────────────


@dataclass
class PnlLine:
    """Строка P&L: счёт + сумма в func-валюте (доход/расход, всегда положительная)."""

    account_id: int
    account_code: str
    account_name: str
    account_type: str  # income|expense
    amount: Decimal


@dataclass
class PnlReport:
    income_lines: list[PnlLine] = field(default_factory=list)
    expense_lines: list[PnlLine] = field(default_factory=list)
    total_income: Decimal = Decimal("0")
    total_expense: Decimal = Decimal("0")
    profit: Decimal = Decimal("0")  # total_income − total_expense


@dataclass
class _AggRow:
    """Duck-typed строка для pure-ядра: (account_id, code, name, type, sum_amount_func)."""

    account_id: int
    account_code: str
    account_name: str
    account_type: str
    sum_amount_func: Decimal


def pnl_from_rows(rows: list[_AggRow]) -> PnlReport:
    """Собрать P&L из агрегатов по счёту. Pure (тестируется без БД).

    Знак amount_func: income-строки кредитуют доход (Кт<0), expense дебетуют расход
    (Дт>0). В отчёте показываем ПОЛОЖИТЕЛЬНЫЕ величины:
      • доход счёта = −Σ amount_func (Кт<0 → положителен);
      • расход счёта = +Σ amount_func (Дт>0 → положителен).
    profit = Σ доход − Σ расход. (Это естественная формула P&L: прибыль увеличивает
    капитал, доход кредитовый, расход дебетовый — их разница и есть финрезультат.)
    """
    report = PnlReport()
    for r in rows:
        if r.account_type == _INCOME:
            amount = _q2(-r.sum_amount_func)
            report.income_lines.append(
                PnlLine(r.account_id, r.account_code, r.account_name, _INCOME, amount)
            )
            report.total_income += amount
        elif r.account_type == _EXPENSE:
            amount = _q2(r.sum_amount_func)
            report.expense_lines.append(
                PnlLine(r.account_id, r.account_code, r.account_name, _EXPENSE, amount)
            )
            report.total_expense += amount
    report.total_income = _q2(report.total_income)
    report.total_expense = _q2(report.total_expense)
    report.profit = _q2(report.total_income - report.total_expense)
    return report


async def profit_and_loss(
    session: AsyncSession,
    legal_entity_id: int,
    *,
    date_from: date,
    date_to: date,
    basis: str = BASIS_ACCRUAL,
) -> PnlReport:
    """P&L-lite за период [date_from, date_to] по income(4xxx)/expense(5xxx)-счетам.

    basis='accrual' (default) — КАНОНИЧЕСКИЙ P&L: все income/expense posted-строки. basis=
    'cash' — только строки, чья проводка содержит денежную (money_account≠NULL): выручка/
    расход по факту движения денег. Переводы исключены by construction (нет income/expense-
    строки), reversed-оригинал не posted. Период по entry.date.
    """
    rows = await _aggregate_by_account(
        session,
        legal_entity_id,
        account_types=(_INCOME, _EXPENSE),
        date_from=date_from,
        date_to=date_to,
        basis=basis,
    )
    return pnl_from_rows(rows)


async def _aggregate_by_account(
    session: AsyncSession,
    legal_entity_id: int,
    *,
    account_types: tuple[str, ...],
    date_from: date | None,
    date_to: date | None,
    basis: str = BASIS_ACCRUAL,
) -> list[_AggRow]:
    q = (
        select(
            FinAccountGl.id,
            FinAccountGl.code,
            FinAccountGl.name,
            FinAccountGl.type,
            func.coalesce(func.sum(FinLedgerLine.amount_func), Decimal("0")),
        )
        .join(FinJournalEntry, FinLedgerLine.journal_entry_id == FinJournalEntry.id)
        .join(FinAccountGl, FinLedgerLine.account_gl_id == FinAccountGl.id)
        .where(
            FinLedgerLine.legal_entity_id == legal_entity_id,
            FinJournalEntry.status.in_(_LIVE_STATUSES),
            FinAccountGl.type.in_(account_types),
        )
        .group_by(FinAccountGl.id, FinAccountGl.code, FinAccountGl.name, FinAccountGl.type)
        .order_by(FinAccountGl.code)
    )
    if date_from is not None:
        q = q.where(FinJournalEntry.date >= date_from)
    if date_to is not None:
        q = q.where(FinJournalEntry.date <= date_to)
    if basis == BASIS_CASH:
        # Cash-basis: оставляем только income/expense из проводок, где есть денежная
        # строка (money_account≠NULL) — выручка/расход признаны движением денег.
        money_line = aliased(FinLedgerLine)
        q = q.where(
            exists(
                select(money_line.id).where(
                    money_line.journal_entry_id == FinLedgerLine.journal_entry_id,
                    money_line.money_account_id.isnot(None),
                )
            )
        )
    rows = (await session.execute(q)).all()
    return [
        _AggRow(aid, code, name, atype, _q2(s))
        for (aid, code, name, atype, s) in rows
    ]


# ───────────────────────────── Trial balance ─────────────────────────────


@dataclass
class TrialBalanceRow:
    """Обороты по GL-счёту: дебет/кредит (положительные) + сальдо (знаковое)."""

    account_id: int
    account_code: str
    account_name: str
    account_type: str
    debit: Decimal  # Σ положительных amount_func (Дт)
    credit: Decimal  # Σ |отрицательных| amount_func (Кт), положительное число
    balance: Decimal  # debit − credit (знаковое сальдо)


@dataclass
class TrialBalanceReport:
    rows: list[TrialBalanceRow] = field(default_factory=list)
    total_debit: Decimal = Decimal("0")
    total_credit: Decimal = Decimal("0")
    is_balanced: bool = True  # total_debit == total_credit (инвариант №2)


@dataclass
class _TbAggRow:
    """Duck-typed: (account_id, code, name, type, debit_sum, credit_sum)."""

    account_id: int
    account_code: str
    account_name: str
    account_type: str
    debit_sum: Decimal  # Σ amount_func WHERE amount_func>0
    credit_sum: Decimal  # Σ amount_func WHERE amount_func<0 (отрицательная)


def trial_balance_rows(rows: list[_TbAggRow]) -> TrialBalanceReport:
    """Собрать оборотно-сальдовую из дебет/кредит-агрегатов по счёту. Pure.

    debit = Σ(amount_func>0); credit = |Σ(amount_func<0)|; balance = debit − credit.
    Σ debit по всем счетам == Σ credit ⇔ GL сбалансирован (инвариант №2). Строки с
    нулевыми оборотами отбрасываются (не засоряем отчёт).
    """
    report = TrialBalanceReport()
    for r in rows:
        debit = _q2(r.debit_sum)
        credit = _q2(-r.credit_sum)  # credit_sum отрицательна → делаем положительной
        if debit == Decimal("0") and credit == Decimal("0"):
            continue
        report.rows.append(
            TrialBalanceRow(
                account_id=r.account_id,
                account_code=r.account_code,
                account_name=r.account_name,
                account_type=r.account_type,
                debit=debit,
                credit=credit,
                balance=_q2(debit - credit),
            )
        )
        report.total_debit += debit
        report.total_credit += credit
    report.total_debit = _q2(report.total_debit)
    report.total_credit = _q2(report.total_credit)
    report.is_balanced = report.total_debit == report.total_credit
    return report


async def trial_balance_report(
    session: AsyncSession,
    legal_entity_id: int,
    *,
    on_date: date | None = None,
) -> TrialBalanceReport:
    """Оборотно-сальдовая ведомость юрлица: дебет/кредит обороты + сальдо по счёту.

    Накопительно (с начала истории) до on_date включительно (None = весь период).
    Σ Дт = Σ Кт ⇔ GL сбалансирован (детектор багов поверх БД-триггера, инвариант №2).
    """
    debit_sum = func.coalesce(
        func.sum(
            func.greatest(FinLedgerLine.amount_func, Decimal("0"))
        ),
        Decimal("0"),
    )
    credit_sum = func.coalesce(
        func.sum(
            func.least(FinLedgerLine.amount_func, Decimal("0"))
        ),
        Decimal("0"),
    )
    q = (
        select(
            FinAccountGl.id,
            FinAccountGl.code,
            FinAccountGl.name,
            FinAccountGl.type,
            debit_sum,
            credit_sum,
        )
        .join(FinJournalEntry, FinLedgerLine.journal_entry_id == FinJournalEntry.id)
        .join(FinAccountGl, FinLedgerLine.account_gl_id == FinAccountGl.id)
        .where(
            FinLedgerLine.legal_entity_id == legal_entity_id,
            FinJournalEntry.status.in_(_LIVE_STATUSES),
        )
        .group_by(FinAccountGl.id, FinAccountGl.code, FinAccountGl.name, FinAccountGl.type)
        .order_by(FinAccountGl.code)
    )
    if on_date is not None:
        q = q.where(FinJournalEntry.date <= on_date)
    rows = (await session.execute(q)).all()
    return trial_balance_rows(
        [
            _TbAggRow(aid, code, name, atype, _q2(d), _q2(c))
            for (aid, code, name, atype, d, c) in rows
        ]
    )


# ───────────────────────────── AR/AP-сальдо ─────────────────────────────


@dataclass
class ArApRow:
    """Сальдо расчётов с контрагентом по AR/AP-счёту (в func-валюте)."""

    counterparty_company_id: int | None
    account_id: int
    account_code: str
    account_name: str
    balance: Decimal  # Σ amount_func (AR: Дт>0 — нам должны; AP: Кт<0 — мы должны)


@dataclass
class _ArApAggRow:
    counterparty_company_id: int | None
    account_id: int
    account_code: str
    account_name: str
    sum_amount_func: Decimal


def arap_from_rows(rows: list[_ArApAggRow]) -> list[ArApRow]:
    """Собрать AR/AP-сальдо из агрегатов (счёт×контрагент). Pure.

    Нулевые сальдо (полностью погашенные) отбрасываются. Знак сохраняется:
    дебиторка (AR) положительна (нам должны), кредиторка (AP) отрицательна (мы должны).
    """
    out: list[ArApRow] = []
    for r in rows:
        bal = _q2(r.sum_amount_func)
        if bal == Decimal("0"):
            continue
        out.append(
            ArApRow(
                counterparty_company_id=r.counterparty_company_id,
                account_id=r.account_id,
                account_code=r.account_code,
                account_name=r.account_name,
                balance=bal,
            )
        )
    return out


async def receivables_payables(
    session: AsyncSession,
    legal_entity_id: int,
    *,
    kind: str,  # 'ar' (дебиторка) | 'ap' (кредиторка)
    on_date: date | None = None,
) -> list[ArApRow]:
    """Сальдо AR/AP по контрагенту из ledger lines (Ф1 — простой агрегат).

    kind='ar' → счета subtype='ar' (1210/1290, дебиторка);
    kind='ap' → счета subtype='ap' (2110/2210, кредиторка).

    ОГРАНИЧЕНИЕ Ф1: это сальдо-агрегат по проводкам, БЕЗ привязки к документам
    (инвойсам/вендор-счетам), без возраста долга и разбивки по счёту-фактуре —
    полные книги AR/AP появятся в Ф5 вместе с fin_invoice/fin_vendor_bill.
    """
    subtypes = AR_SUBTYPES if kind == "ar" else AP_SUBTYPES
    q = (
        select(
            FinLedgerLine.counterparty_company_id,
            FinAccountGl.id,
            FinAccountGl.code,
            FinAccountGl.name,
            func.coalesce(func.sum(FinLedgerLine.amount_func), Decimal("0")),
        )
        .join(FinJournalEntry, FinLedgerLine.journal_entry_id == FinJournalEntry.id)
        .join(FinAccountGl, FinLedgerLine.account_gl_id == FinAccountGl.id)
        .where(
            FinLedgerLine.legal_entity_id == legal_entity_id,
            FinJournalEntry.status.in_(_LIVE_STATUSES),
            FinAccountGl.subtype.in_(subtypes),
        )
        .group_by(
            FinLedgerLine.counterparty_company_id,
            FinAccountGl.id,
            FinAccountGl.code,
            FinAccountGl.name,
        )
        .order_by(FinAccountGl.code, FinLedgerLine.counterparty_company_id)
    )
    if on_date is not None:
        q = q.where(FinJournalEntry.date <= on_date)
    rows = (await session.execute(q)).all()
    return arap_from_rows(
        [
            _ArApAggRow(cp, aid, code, name, _q2(s))
            for (cp, aid, code, name, s) in rows
        ]
    )


# ───────────────────────────── GL-журнал (главная книга) ─────────────────────────────


async def list_entries(
    session: AsyncSession,
    *,
    legal_entity_id: int | None = None,
    account_gl_id: int | None = None,
    status: str | None = None,
    date_from: date | None = None,
    date_to: date | None = None,
    limit: int = 100,
    offset: int = 0,
) -> tuple[list[FinJournalEntry], int]:
    """Листинг проводок (read-only «главная книга») со строками — для аудита.

    Возвращает (страница entries с подгруженными lines через selectinload, total).
    Фильтр account_gl_id — проводки, у которых ХОТЯ БЫ одна строка по этому счёту
    (EXISTS-подзапрос, не дублирует entry). Без N+1 (lines загружаются selectinload).
    """
    from sqlalchemy.orm import selectinload

    conds = []
    if legal_entity_id is not None:
        conds.append(FinJournalEntry.legal_entity_id == legal_entity_id)
    if status is not None:
        conds.append(FinJournalEntry.status == status)
    if date_from is not None:
        conds.append(FinJournalEntry.date >= date_from)
    if date_to is not None:
        conds.append(FinJournalEntry.date <= date_to)
    if account_gl_id is not None:
        conds.append(
            select(FinLedgerLine.id)
            .where(
                FinLedgerLine.journal_entry_id == FinJournalEntry.id,
                FinLedgerLine.account_gl_id == account_gl_id,
            )
            .exists()
        )

    base = select(FinJournalEntry)
    for c in conds:
        base = base.where(c)

    total = (
        await session.execute(select(func.count()).select_from(base.subquery()))
    ).scalar_one()

    page_stmt = (
        base.options(selectinload(FinJournalEntry.lines))
        .order_by(FinJournalEntry.date.desc(), FinJournalEntry.id.desc())
        .limit(limit)
        .offset(offset)
    )
    entries = (await session.execute(page_stmt)).scalars().all()
    return list(entries), total


async def get_entry(
    session: AsyncSession, entry_id: int
) -> FinJournalEntry | None:
    """Одна проводка со строками (selectinload) для read-by-id главной книги."""
    from sqlalchemy.orm import selectinload

    return (
        await session.execute(
            select(FinJournalEntry)
            .options(selectinload(FinJournalEntry.lines))
            .where(FinJournalEntry.id == entry_id)
        )
    ).scalar_one_or_none()
