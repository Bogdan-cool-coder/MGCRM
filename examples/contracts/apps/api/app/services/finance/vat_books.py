"""НДС-книги + AR/AP с возрастом долга (Ф5) — проекции поверх GL и документов.

КНИГИ НДС — проекции fin_ledger_line (инвариант №1, источник истины):
  • Книга продаж (output): строки счёта 2310 (НДС к уплате), по инвойсам.
  • Книга покупок (input):  строки счёта 1910 (НДС к зачёту), по вендор-счетам.
  • Отчёт НДС: output − input за период по юрлицу (>0 к уплате, <0 к возмещению).
Момент признания: by_shipment ⇒ НДС возникает в accrual-проводке (issue/confirm) →
строка 2310/1910 датируется датой документа. by_payment — упрощение Ф5.1 (сейчас все
юрлица трактуются by_shipment в постинге).

AR/AP AGING — документ-привязанные (замена Ф1 сальдо-агрегата):
  • AR из неоплаченных инвойсов (amount_gross − paid_amount), бакеты по due_date.
  • AP из неоплаченных вендор-счетов, бакеты по due_date.
  Бакеты возраста (от due_date до as_of): 0-30 / 31-60 / 61-90 / 90+ (просрочка).
  Документы с due_date в будущем → бакет 'current' (срок не наступил).

Чистые ядра (vat_book_from_rows, aging_bucket, aggregate_aging) тестируются без БД.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from decimal import ROUND_HALF_UP, Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import (
    FinInvoice,
    FinJournalEntry,
    FinLedgerLine,
    FinVendorBill,
)
from app.services.finance.seed_data import (
    VAT_INPUT_ACCOUNT_CODE,
    VAT_OUTPUT_ACCOUNT_CODE,
)

#: Живые статусы проекции НДС-книг: posted + reversed. Отмена документа сторнирует
#: строку 2310/1910 → обе половины (оригинал reversed + зеркало posted) гасятся в 0.
#: Только 'posted' оставил бы лишь зеркало (−original) — баг B7 CRIT-1. 'draft'
#: исключён. Нулевые строки книги отбрасываются vat_book_from_rows.
_LIVE_STATUSES = ("posted", "reversed")


def _q2(value: Decimal) -> Decimal:
    return Decimal(value).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


# ───────────────────────────── НДС-книги ─────────────────────────────


@dataclass
class VatBookEntry:
    """Строка книги НДС: документ (через source_ref_id проводки) + сумма НДС."""

    entry_id: int
    entry_date: date
    source: str
    source_ref_id: int | None
    counterparty_company_id: int | None
    vat_amount: Decimal  # положительная (модуль НДС-строки)
    memo: str | None


@dataclass
class VatReport:
    output_total: Decimal = Decimal("0")  # НДС с продаж (книга продаж)
    input_total: Decimal = Decimal("0")   # НДС к вычету (книга покупок)
    vat_payable: Decimal = Decimal("0")   # output − input (>0 к уплате, <0 к возмещению)
    sales_book: list[VatBookEntry] = field(default_factory=list)
    purchase_book: list[VatBookEntry] = field(default_factory=list)


@dataclass
class _VatRow:
    """Duck-typed строка для pure-ядра: НДС-строка проводки в func-валюте."""

    entry_id: int
    entry_date: date
    source: str
    source_ref_id: int | None
    counterparty_company_id: int | None
    amount_func: Decimal  # знаковая (output Кт<0, input Дт>0)
    memo: str | None


def vat_book_from_rows(rows: list[_VatRow], *, kind: str) -> tuple[list[VatBookEntry], Decimal]:
    """Собрать книгу НДС из строк счёта 2310/1910. Pure.

    kind='output' (продажи): строки 2310, Кт<0 → НДС = −amount_func (положительный).
    kind='input'  (покупки): строки 1910, Дт>0 → НДС = +amount_func.
    Возвращает (книга, итого НДС). Нулевые строки отбрасываются.
    """
    book: list[VatBookEntry] = []
    total = Decimal("0")
    for r in rows:
        vat = _q2(-r.amount_func) if kind == "output" else _q2(r.amount_func)
        if vat == Decimal("0"):
            continue
        book.append(
            VatBookEntry(
                entry_id=r.entry_id,
                entry_date=r.entry_date,
                source=r.source,
                source_ref_id=r.source_ref_id,
                counterparty_company_id=r.counterparty_company_id,
                vat_amount=vat,
                memo=r.memo,
            )
        )
        total += vat
    return book, _q2(total)


async def _vat_rows(
    session: AsyncSession,
    legal_entity_id: int,
    account_code: str,
    *,
    date_from: date,
    date_to: date,
) -> list[_VatRow]:
    """НДС-строки (2310/1910) за период с разрезом по проводке/документу/контрагенту."""
    from app.models import FinAccountGl

    q = (
        select(
            FinJournalEntry.id,
            FinJournalEntry.date,
            FinJournalEntry.source,
            FinJournalEntry.source_ref_id,
            FinLedgerLine.counterparty_company_id,
            FinLedgerLine.amount_func,
            FinJournalEntry.memo,
        )
        .join(FinJournalEntry, FinLedgerLine.journal_entry_id == FinJournalEntry.id)
        .join(FinAccountGl, FinLedgerLine.account_gl_id == FinAccountGl.id)
        .where(
            FinLedgerLine.legal_entity_id == legal_entity_id,
            FinJournalEntry.status.in_(_LIVE_STATUSES),
            FinAccountGl.code == account_code,
            FinJournalEntry.date >= date_from,
            FinJournalEntry.date <= date_to,
        )
        .order_by(FinJournalEntry.date, FinJournalEntry.id)
    )
    rows = (await session.execute(q)).all()
    return [
        _VatRow(eid, edate, src, srid, cp, _q2(af), memo)
        for (eid, edate, src, srid, cp, af, memo) in rows
    ]


async def vat_report(
    session: AsyncSession,
    legal_entity_id: int,
    *,
    date_from: date,
    date_to: date,
) -> VatReport:
    """Отчёт по НДС за период: книги продаж/покупок + НДС к уплате/возмещению.

    output (книга продаж) = Σ |2310-строк|; input (книга покупок) = Σ 1910-строк;
    vat_payable = output − input. Всё в func-валюте юрлица, posted-строки.
    """
    out_rows = await _vat_rows(
        session, legal_entity_id, VAT_OUTPUT_ACCOUNT_CODE, date_from=date_from, date_to=date_to
    )
    in_rows = await _vat_rows(
        session, legal_entity_id, VAT_INPUT_ACCOUNT_CODE, date_from=date_from, date_to=date_to
    )
    sales_book, output_total = vat_book_from_rows(out_rows, kind="output")
    purchase_book, input_total = vat_book_from_rows(in_rows, kind="input")
    return VatReport(
        output_total=output_total,
        input_total=input_total,
        vat_payable=_q2(output_total - input_total),
        sales_book=sales_book,
        purchase_book=purchase_book,
    )


# ───────────────────────────── AR/AP aging ─────────────────────────────

#: Границы бакетов возраста (дней просрочки от due_date). 'current' — срок не наступил.
AGING_BUCKETS = ("current", "0-30", "31-60", "61-90", "90+")


def aging_bucket(due_date: date | None, as_of: date) -> str:
    """Бакет возраста долга по due_date относительно as_of. Pure.

    due_date пуст или в будущем → 'current' (срок не наступил). Иначе по дням просрочки:
    [0,30]→'0-30', [31,60]→'31-60', [61,90]→'61-90', >90→'90+'.
    """
    if due_date is None or due_date > as_of:
        return "current"
    overdue = (as_of - due_date).days
    if overdue <= 30:
        return "0-30"
    if overdue <= 60:
        return "31-60"
    if overdue <= 90:
        return "61-90"
    return "90+"


@dataclass
class AgingDoc:
    """Открытый документ для aging: id/номер/контрагент/срок/остаток/бакет."""

    doc_id: int
    number: str | None
    counterparty_company_id: int | None
    currency: str
    due_date: date | None
    outstanding: Decimal  # gross − paid (>0)
    bucket: str


@dataclass
class AgingReport:
    docs: list[AgingDoc] = field(default_factory=list)
    by_bucket: dict[str, Decimal] = field(default_factory=dict)
    total: Decimal = Decimal("0")


@dataclass
class _AgingInput:
    doc_id: int
    number: str | None
    counterparty_company_id: int | None
    currency: str
    due_date: date | None
    outstanding: Decimal


def aggregate_aging(inputs: list[_AgingInput], as_of: date) -> AgingReport:
    """Собрать aging-отчёт: каждый документ → бакет, агрегаты по бакетам + итог. Pure.

    Документы с нулевым/отрицательным остатком отбрасываются (полностью оплачены).
    """
    report = AgingReport(by_bucket={b: Decimal("0") for b in AGING_BUCKETS})
    for d in inputs:
        outstanding = _q2(d.outstanding)
        if outstanding <= Decimal("0"):
            continue
        bucket = aging_bucket(d.due_date, as_of)
        report.docs.append(
            AgingDoc(
                doc_id=d.doc_id,
                number=d.number,
                counterparty_company_id=d.counterparty_company_id,
                currency=d.currency,
                due_date=d.due_date,
                outstanding=outstanding,
                bucket=bucket,
            )
        )
        report.by_bucket[bucket] = _q2(report.by_bucket[bucket] + outstanding)
        report.total = _q2(report.total + outstanding)
    return report


async def receivables_aging(
    session: AsyncSession, legal_entity_id: int, *, as_of: date
) -> AgingReport:
    """AR aging: неоплаченные инвойсы (issued/partially_paid), остаток = gross − paid."""
    q = (
        select(
            FinInvoice.id,
            FinInvoice.number,
            FinInvoice.counterparty_company_id,
            FinInvoice.currency,
            FinInvoice.due_date,
            (FinInvoice.amount_gross - FinInvoice.paid_amount),
        )
        .where(
            FinInvoice.legal_entity_id == legal_entity_id,
            FinInvoice.status.in_(("issued", "partially_paid")),
        )
        .order_by(FinInvoice.due_date, FinInvoice.id)
    )
    rows = (await session.execute(q)).all()
    inputs = [
        _AgingInput(iid, num, cp, ccy, due, _q2(out))
        for (iid, num, cp, ccy, due, out) in rows
    ]
    return aggregate_aging(inputs, as_of)


async def payables_aging(
    session: AsyncSession, legal_entity_id: int, *, as_of: date
) -> AgingReport:
    """AP aging: неоплаченные вендор-счета (confirmed/partially_paid), остаток = gross − paid."""
    q = (
        select(
            FinVendorBill.id,
            FinVendorBill.bill_no,
            FinVendorBill.supplier_company_id,
            FinVendorBill.currency,
            FinVendorBill.due_date,
            (FinVendorBill.amount_gross - FinVendorBill.paid_amount),
        )
        .where(
            FinVendorBill.legal_entity_id == legal_entity_id,
            FinVendorBill.status.in_(("confirmed", "partially_paid")),
        )
        .order_by(FinVendorBill.due_date, FinVendorBill.id)
    )
    rows = (await session.execute(q)).all()
    inputs = [
        _AgingInput(bid, num, cp, ccy, due, _q2(out))
        for (bid, num, cp, ccy, due, out) in rows
    ]
    return aggregate_aging(inputs, as_of)
