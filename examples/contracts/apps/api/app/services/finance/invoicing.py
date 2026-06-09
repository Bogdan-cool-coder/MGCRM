"""Инвойсы / акты / вендор-счета (Ф5) — AR/AP-цикл поверх double-entry GL.

ГРАНИЦА Ф5 (самостоятельна, Ф3/Ф4 пропущены): инвойс при issue постит accrual-by-
invoice (выручка+AR+НДС output); оплата гасит AR. Вендор-счёт при confirm постит
расход+НДС input+AP; оплата гасит AP. Акт — документ выполнения БЕЗ проводки (выручку
признаёт инвойс — не задваиваем).

Чистое ядро (line-math, status-переходы, settlement-арифметика) тестируется без БД.
Async-оркестраторы поверх posting_db (issue/confirm) и build_cash_in/out (settlement).

ИНВАРИАНТЫ:
  • net+vat=gross на каждой позиции и в шапке (vat=round(net*rate)).
  • paid_amount/status — производные от settlement, материализуются для листинга.
  • Иммутабельность: issued инвойс/confirmed вендор-счёт правке не подлежат
    (только cancel → сторно проводки). draft — мутабельный.
  • Момент НДС: by_shipment (РФ-стандарт) — НДС в accrual-проводке при issue/confirm.
    by_payment — отмечен как упрощение Ф5.1 (см. NOTE), сейчас всегда by_shipment.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, date, datetime
from decimal import ROUND_HALF_UP, Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import (
    FinInvoice,
    FinJournalEntry,
    FinLegalEntity,
    FinVatRate,
    FinVendorBill,
)
from app.services.finance import period_lock, posting_db
from app.services.finance.posting import (
    DocAmountLine,
    PostingError,
    build_cash_in,
    build_cash_out,
    build_invoice_issue,
    build_vendor_bill_confirm,
)
from app.services.finance.posting_db import (
    _base_currency,
    _code_to_id,
    _dict_resolver,
    _money_account,
    _money_account_gl_code,
    _persist_entry,
    _prefetch_rates,
    _resolve_account,
)
from app.services.finance.seed_data import (
    AP_ACCOUNT_CODE,
    AR_ACCOUNT_CODE,
    DEFAULT_EXPENSE_CODE,
    DEFAULT_REVENUE_CODE,
    VAT_INPUT_ACCOUNT_CODE,
    VAT_OUTPUT_ACCOUNT_CODE,
)


def q2(value: Decimal | str | int) -> Decimal:
    return Decimal(value).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


# ───────────────────────────── исключения флоу Ф5 ─────────────────────────────


class DocumentError(Exception):
    """Ошибка флоу документа Ф5 (→ 409/422 в роутере)."""


class DocumentImmutable(DocumentError):
    """Документ в статусе, не допускающем эту операцию (issued/confirmed/cancelled/paid)."""


class DocumentNotIssued(DocumentError):
    """Оплата/действие требует выставленного (issued/confirmed) документа."""


class OverPayment(DocumentError):
    """Сумма оплаты превышает остаток к погашению по документу."""


# ───────────────────────────── line-math (pure) ─────────────────────────────


@dataclass
class LineCalc:
    """Результат расчёта позиции: нетто/НДС/всего (все в копейках, ROUND_HALF_UP)."""

    amount_net: Decimal
    vat_amount: Decimal
    amount_gross: Decimal


def calc_line(qty: Decimal | str, unit_price: Decimal | str, rate_pct: Decimal | str) -> LineCalc:
    """Посчитать позицию: net=round(qty*price), vat=round(net*rate/100), gross=net+vat. Pure.

    rate_pct=0 ⇒ vat=0, gross=net (режим «без НДС»). Все деньги — Decimal HALF_UP.
    """
    net = q2(Decimal(qty) * Decimal(unit_price))
    vat = q2(net * Decimal(rate_pct) / Decimal("100"))
    return LineCalc(amount_net=net, vat_amount=vat, amount_gross=q2(net + vat))


@dataclass
class DocTotals:
    amount_net: Decimal
    vat_amount: Decimal
    amount_gross: Decimal


def sum_totals(lines: list[LineCalc]) -> DocTotals:
    """Свернуть итоги документа из позиций. gross считается из суммы net+vat (а не
    суммы gross позиций) — но они совпадают, т.к. gross позиции = net+vat. Pure.
    """
    net = q2(sum((li.amount_net for li in lines), Decimal("0")))
    vat = q2(sum((li.vat_amount for li in lines), Decimal("0")))
    return DocTotals(amount_net=net, vat_amount=vat, amount_gross=q2(net + vat))


# ───────────────────────────── settlement-арифметика (pure) ─────────────────────────────


def next_paid_amount(current_paid: Decimal, payment: Decimal, gross_total: Decimal) -> Decimal:
    """Новый paid_amount после оплаты. Бросает OverPayment, если превысит gross. Pure."""
    new_paid = q2(Decimal(current_paid) + Decimal(payment))
    if new_paid > q2(gross_total):
        raise OverPayment(
            f"Оплата {q2(payment)} превышает остаток по документу "
            f"(оплачено {q2(current_paid)} из {q2(gross_total)})"
        )
    return new_paid


def settle_status(paid_amount: Decimal, gross_total: Decimal, *, issued_value: str) -> str:
    """Производный статус документа по оплате. Pure.

    paid==0          → issued_value ('issued' для инвойса / 'confirmed' для вендор-счёта)
    0<paid<gross     → 'partially_paid'
    paid>=gross      → 'paid'
    """
    paid = q2(paid_amount)
    gross = q2(gross_total)
    if paid <= Decimal("0"):
        return issued_value
    if paid >= gross:
        return "paid"
    return "partially_paid"


def assert_doc_editable(status: str) -> None:
    """Править/удалять документ можно только в draft. Pure."""
    if status != "draft":
        raise DocumentImmutable(
            f"Документ в статусе {status!r} иммутабелен — правка только в черновике"
        )


def assert_issuable(status: str) -> None:
    """Выставить/подтвердить документ можно только из draft. Pure."""
    if status != "draft":
        raise DocumentImmutable(
            f"Документ уже обработан (статус {status!r}) — повторное выставление запрещено"
        )


def assert_payable(status: str, *, issued_statuses: tuple[str, ...]) -> None:
    """Оплатить можно только выставленный/частично оплаченный документ. Pure."""
    if status not in issued_statuses:
        raise DocumentNotIssued(
            f"Документ в статусе {status!r} нельзя оплатить — сначала выставите его"
        )


def assert_cancellable(status: str) -> None:
    """Отменить можно draft/issued/confirmed (не paid/partially_paid/cancelled). Pure.

    Любая оплата (partially_paid/paid) блокирует отмену — сначала проведите возврат.
    Это первый guard; cancel_* дополнительно проверяет paid_amount != 0 (защита от
    рассинхрона статуса и материализованного paid_amount).
    """
    if status in ("partially_paid", "paid", "cancelled"):
        raise DocumentImmutable(f"Документ в статусе {status!r} нельзя отменить")


# ───────────────────────────── async-помощники ─────────────────────────────


async def _lock_invoice(session: AsyncSession, invoice_id: int) -> None:
    """Взять row-lock (FOR UPDATE) на строку инвойса — сериализует issue/pay в гонке
    при scale=2. Второй конкурент ждёт COMMIT первого, затем перечитывает свежий
    статус/paid_amount и корректно отбивается assert_*. Status/paid_amount —
    материализованное производное, поэтому read-modify-write обязан быть под локом.
    """
    await session.execute(
        select(FinInvoice.id).where(FinInvoice.id == invoice_id).with_for_update()
    )


async def _lock_vendor_bill(session: AsyncSession, bill_id: int) -> None:
    """Взять row-lock (FOR UPDATE) на строку вендор-счёта — см. _lock_invoice."""
    await session.execute(
        select(FinVendorBill.id).where(FinVendorBill.id == bill_id).with_for_update()
    )


async def _vat_rate_pct(session: AsyncSession, vat_rate_id: int | None) -> Decimal:
    """Ставка НДС (%) по vat_rate_id. None/не найдено → 0 (режим «без НДС»)."""
    if vat_rate_id is None:
        return Decimal("0")
    vr = (
        await session.execute(select(FinVatRate).where(FinVatRate.id == vat_rate_id))
    ).scalar_one_or_none()
    return vr.rate_pct if vr is not None else Decimal("0")


async def recompute_invoice_lines(session: AsyncSession, invoice: FinInvoice) -> None:
    """Пересчитать суммы позиций + итоги инвойса (вызывать в draft при edit). НЕ commit."""
    calcs: list[LineCalc] = []
    for ln in invoice.lines:
        rate = await _vat_rate_pct(session, ln.vat_rate_id)
        c = calc_line(ln.qty, ln.unit_price, rate)
        ln.amount_net = c.amount_net
        ln.vat_amount = c.vat_amount
        ln.amount_gross = c.amount_gross
        calcs.append(c)
    totals = sum_totals(calcs)
    invoice.amount_net = totals.amount_net
    invoice.vat_amount = totals.vat_amount
    invoice.amount_gross = totals.amount_gross


async def recompute_bill_lines(session: AsyncSession, bill: FinVendorBill) -> None:
    """Пересчитать суммы позиций + итоги вендор-счёта (вызывать в draft при edit). НЕ commit."""
    calcs: list[LineCalc] = []
    for ln in bill.lines:
        rate = await _vat_rate_pct(session, ln.vat_rate_id)
        c = calc_line(ln.qty, ln.unit_price, rate)
        ln.amount_net = c.amount_net
        ln.vat_amount = c.vat_amount
        ln.amount_gross = c.amount_gross
        calcs.append(c)
    totals = sum_totals(calcs)
    bill.amount_net = totals.amount_net
    bill.vat_amount = totals.vat_amount
    bill.amount_gross = totals.amount_gross


# ───────────────────────────── issue / confirm (accrual-проводка) ─────────────────────────────


async def issue_invoice(
    session: AsyncSession,
    invoice: FinInvoice,
    *,
    posted_by_user_id: int | None = None,
) -> FinJournalEntry:
    """Выставить счёт клиенту → accrual-проводка (Дт AR / Кт выручка / Кт НДС output).

    Иммутабельность: только из draft. Period-lock на issue_date. После — status='issued',
    journal_entry_id проставлен. Пустой/нулевой инвойс → PostingError.

    Конкурентность: FOR UPDATE на строку инвойса + refresh статуса — два параллельных
    issue не задвоят accrual (второй увидит 'issued' и отобьётся). Доп. защита —
    частичный UNIQUE-индекс на (source, source_ref_id, kind) для accrual-проводок.
    """
    await _lock_invoice(session, invoice.id)
    await session.refresh(invoice, attribute_names=["status"])
    assert_issuable(invoice.status)

    le = (
        await session.execute(
            select(FinLegalEntity).where(FinLegalEntity.id == invoice.legal_entity_id)
        )
    ).scalar_one()
    func_ccy = le.functional_currency
    base_ccy = await _base_currency(session)
    await period_lock.assert_period_open(session, le.id, invoice.issue_date)

    if not invoice.lines:
        raise PostingError("Инвойс без позиций — провести нельзя")

    doc_lines = [
        DocAmountLine(
            account_code=(ln.revenue_account_code or invoice.revenue_account_code or DEFAULT_REVENUE_CODE),
            amount_net=ln.amount_net,
            vat_amount=ln.vat_amount,
            vat_rate_id=ln.vat_rate_id,
            cashflow_category_id=ln.cashflow_category_id,
        )
        for ln in invoice.lines
    ]
    rates = await _prefetch_rates(session, {(invoice.currency, func_ccy)}, invoice.issue_date)
    resolver = _dict_resolver(rates)
    draft = build_invoice_issue(
        on_date=invoice.issue_date,
        func_currency=func_ccy,
        currency=invoice.currency,
        lines=doc_lines,
        ar_account_code=AR_ACCOUNT_CODE,
        vat_output_account_code=VAT_OUTPUT_ACCOUNT_CODE,
        counterparty_company_id=invoice.counterparty_company_id,
        rate_resolver=resolver,
        source="invoice",
        source_ref_id=invoice.id,
        deal_id=invoice.deal_id,
        contract_id=invoice.contract_id,
        subscription_id=invoice.subscription_id,
        memo=invoice.purpose or (invoice.number and f"Счёт {invoice.number}"),
    )
    codes = {ln.account_code for ln in draft.lines}
    accounts = [await _resolve_account(session, c) for c in codes]
    entry = await _persist_entry(
        session, draft,
        legal_entity_id=le.id, base_currency=base_ccy,
        posted_by_user_id=posted_by_user_id, code_to_id=_code_to_id(accounts),
    )
    invoice.status = "issued"
    invoice.journal_entry_id = entry.id
    invoice.issued_at = datetime.now(UTC)
    await session.flush()
    return entry


async def confirm_vendor_bill(
    session: AsyncSession,
    bill: FinVendorBill,
    *,
    posted_by_user_id: int | None = None,
) -> FinJournalEntry:
    """Подтвердить входящий счёт поставщика → accrual (Дт расход + Дт НДС input / Кт AP).

    Иммутабельность: только из draft. Period-lock на bill_date. После — status='confirmed'.

    Конкурентность: FOR UPDATE на строку вендор-счёта + refresh статуса — см. issue_invoice.
    """
    await _lock_vendor_bill(session, bill.id)
    await session.refresh(bill, attribute_names=["status"])
    assert_issuable(bill.status)

    le = (
        await session.execute(
            select(FinLegalEntity).where(FinLegalEntity.id == bill.legal_entity_id)
        )
    ).scalar_one()
    func_ccy = le.functional_currency
    base_ccy = await _base_currency(session)
    await period_lock.assert_period_open(session, le.id, bill.bill_date)

    if not bill.lines:
        raise PostingError("Вендор-счёт без позиций — провести нельзя")

    doc_lines = [
        DocAmountLine(
            account_code=(ln.expense_account_code or bill.expense_account_code or DEFAULT_EXPENSE_CODE),
            amount_net=ln.amount_net,
            vat_amount=ln.vat_amount,
            vat_rate_id=ln.vat_rate_id,
            cashflow_category_id=ln.cashflow_category_id,
        )
        for ln in bill.lines
    ]
    rates = await _prefetch_rates(session, {(bill.currency, func_ccy)}, bill.bill_date)
    resolver = _dict_resolver(rates)
    draft = build_vendor_bill_confirm(
        on_date=bill.bill_date,
        func_currency=func_ccy,
        currency=bill.currency,
        lines=doc_lines,
        ap_account_code=AP_ACCOUNT_CODE,
        vat_input_account_code=VAT_INPUT_ACCOUNT_CODE,
        supplier_company_id=bill.supplier_company_id,
        rate_resolver=resolver,
        source="vendor_bill",
        source_ref_id=bill.id,
        memo=bill.purpose or (bill.bill_no and f"Счёт поставщика {bill.bill_no}"),
    )
    codes = {ln.account_code for ln in draft.lines}
    accounts = [await _resolve_account(session, c) for c in codes]
    entry = await _persist_entry(
        session, draft,
        legal_entity_id=le.id, base_currency=base_ccy,
        posted_by_user_id=posted_by_user_id, code_to_id=_code_to_id(accounts),
    )
    bill.status = "confirmed"
    bill.journal_entry_id = entry.id
    bill.confirmed_at = datetime.now(UTC)
    await session.flush()
    return entry


# ───────────────────────────── settlement (оплата гасит AR/AP) ─────────────────────────────


async def pay_invoice(
    session: AsyncSession,
    invoice: FinInvoice,
    *,
    money_account_id: int,
    amount: Decimal,
    on_date: date,
    cashflow_category_id: int | None = None,
    posted_by_user_id: int | None = None,
) -> FinJournalEntry:
    """Оплата инвойса клиентом → Дт деньги / Кт AR(1210). Гасит дебиторку.

    Settlement: paid_amount += amount (бросает OverPayment если > gross); статус
    пересчитывается (issued→partially_paid→paid). Денежная (дебетовая) строка несёт
    статью ДДС → приток корректно попадает в ДДС.

    Конкурентность: берём FOR UPDATE на строку инвойса и перечитываем свежий
    status/paid_amount под локом — read-modify-write paid_amount атомарен в одной
    транзакции с settlement-проводкой (два параллельных pay_invoice сериализуются,
    двойного гашения AR не будет).
    """
    await _lock_invoice(session, invoice.id)
    await session.refresh(invoice)
    assert_payable(invoice.status, issued_statuses=("issued", "partially_paid"))
    new_paid = next_paid_amount(invoice.paid_amount, amount, invoice.amount_gross)

    le = (
        await session.execute(
            select(FinLegalEntity).where(FinLegalEntity.id == invoice.legal_entity_id)
        )
    ).scalar_one()
    func_ccy = le.functional_currency
    base_ccy = await _base_currency(session)
    await period_lock.assert_period_open(session, le.id, on_date)

    ma = await _money_account(session, money_account_id)
    # B4: paid_amount/AR ведутся в валюте инвойса, а платёж постится в валюте денежного
    # счёта. Если они различаются — оплата USD-инвойса с RUB-счёта исказила бы
    # paid_amount/AR (сравнение/гашение в разных валютах). До полноценной FX-конвертации
    # платежа (Ф5.1) требуем совпадения валют документа и счёта.
    if ma.currency != invoice.currency:
        raise DocumentError(
            f"Валюта платежа ({ma.currency}) должна совпадать с валютой счёта "
            f"({invoice.currency}) — оплата в иной валюте пока не поддерживается"
        )
    money_code = await _money_account_gl_code(session, ma)
    rates = await _prefetch_rates(session, {(ma.currency, func_ccy)}, on_date)
    resolver = _dict_resolver(rates)
    # Дт деньги / Кт AR (counter=AR с контрагентом). build_cash_in: Дт money / Кт counter.
    draft = build_cash_in(
        on_date=on_date,
        func_currency=func_ccy,
        amount=amount,
        currency=ma.currency,
        money_account_id=ma.id,
        money_account_code=money_code,
        counter_account_code=AR_ACCOUNT_CODE,
        cashflow_category_id=cashflow_category_id,
        counterparty_company_id=invoice.counterparty_company_id,
        counter_requires_counterparty=True,
        rate_resolver=resolver,
        source="invoice",
        source_ref_id=invoice.id,
        deal_id=invoice.deal_id,
        contract_id=invoice.contract_id,
        subscription_id=invoice.subscription_id,
        memo=f"Оплата счёта {invoice.number or invoice.id}",
    )
    codes = {ln.account_code for ln in draft.lines}
    accounts = [await _resolve_account(session, c) for c in codes]
    entry = await _persist_entry(
        session, draft,
        legal_entity_id=le.id, base_currency=base_ccy,
        posted_by_user_id=posted_by_user_id, code_to_id=_code_to_id(accounts),
    )
    invoice.paid_amount = new_paid
    invoice.status = settle_status(new_paid, invoice.amount_gross, issued_value="issued")
    await session.flush()
    return entry


async def pay_vendor_bill(
    session: AsyncSession,
    bill: FinVendorBill,
    *,
    money_account_id: int,
    amount: Decimal,
    on_date: date,
    cashflow_category_id: int | None = None,
    posted_by_user_id: int | None = None,
) -> FinJournalEntry:
    """Оплата вендор-счёта поставщику → Дт AP(2110) / Кт деньги. Гасит кредиторку.

    Settlement: paid_amount += amount; статус confirmed→partially_paid→paid. Денежная
    (кредитовая) строка несёт статью ДДС → отток в ДДС.

    Конкурентность: FOR UPDATE на строку вендор-счёта + refresh — см. pay_invoice.
    """
    await _lock_vendor_bill(session, bill.id)
    await session.refresh(bill)
    assert_payable(bill.status, issued_statuses=("confirmed", "partially_paid"))
    new_paid = next_paid_amount(bill.paid_amount, amount, bill.amount_gross)

    le = (
        await session.execute(
            select(FinLegalEntity).where(FinLegalEntity.id == bill.legal_entity_id)
        )
    ).scalar_one()
    func_ccy = le.functional_currency
    base_ccy = await _base_currency(session)
    await period_lock.assert_period_open(session, le.id, on_date)

    ma = await _money_account(session, money_account_id)
    # B4: см. pay_invoice — paid_amount/AP в валюте вендор-счёта, платёж в валюте счёта;
    # при расхождении гашение AP исказилось бы. Требуем совпадения валют.
    if ma.currency != bill.currency:
        raise DocumentError(
            f"Валюта платежа ({ma.currency}) должна совпадать с валютой счёта "
            f"поставщика ({bill.currency}) — оплата в иной валюте пока не поддерживается"
        )
    money_code = await _money_account_gl_code(session, ma)
    rates = await _prefetch_rates(session, {(ma.currency, func_ccy)}, on_date)
    resolver = _dict_resolver(rates)
    # Дт AP / Кт деньги (counter=AP с контрагентом). build_cash_out: Дт counter / Кт money.
    draft = build_cash_out(
        on_date=on_date,
        func_currency=func_ccy,
        amount=amount,
        currency=ma.currency,
        money_account_id=ma.id,
        money_account_code=money_code,
        counter_account_code=AP_ACCOUNT_CODE,
        cashflow_category_id=cashflow_category_id or bill.cashflow_category_id,
        counterparty_company_id=bill.supplier_company_id,
        counter_requires_counterparty=True,
        rate_resolver=resolver,
        source="vendor_bill",
        source_ref_id=bill.id,
        memo=f"Оплата счёта поставщика {bill.bill_no or bill.id}",
    )
    codes = {ln.account_code for ln in draft.lines}
    accounts = [await _resolve_account(session, c) for c in codes]
    entry = await _persist_entry(
        session, draft,
        legal_entity_id=le.id, base_currency=base_ccy,
        posted_by_user_id=posted_by_user_id, code_to_id=_code_to_id(accounts),
    )
    bill.paid_amount = new_paid
    bill.status = settle_status(new_paid, bill.amount_gross, issued_value="confirmed")
    await session.flush()
    return entry


# ───────────────────────────── cancel (сторно accrual-проводки) ─────────────────────────────


async def cancel_invoice(
    session: AsyncSession, invoice: FinInvoice, *, posted_by_user_id: int | None = None
) -> None:
    """Отменить счёт. draft → просто status='cancelled'. issued (без оплат) → сторно
    accrual-проводки + status='cancelled'. С оплатами — запрещено (сначала верните деньги).
    """
    assert_cancellable(invoice.status)
    if invoice.status == "draft":
        invoice.status = "cancelled"
        await session.flush()
        return
    if q2(invoice.paid_amount) != Decimal("0"):
        raise DocumentImmutable(
            "Нельзя отменить частично/полностью оплаченный счёт — сначала проведите возврат"
        )
    if invoice.journal_entry_id is not None:
        entry = (
            await session.execute(
                select(FinJournalEntry).where(FinJournalEntry.id == invoice.journal_entry_id)
            )
        ).scalar_one_or_none()
        if entry is not None and entry.status == "posted":
            await posting_db.reverse_entry(
                session, entry, posted_by_user_id=posted_by_user_id,
                memo=f"Сторно счёта {invoice.number or invoice.id}",
            )
    invoice.status = "cancelled"
    await session.flush()


async def cancel_vendor_bill(
    session: AsyncSession, bill: FinVendorBill, *, posted_by_user_id: int | None = None
) -> None:
    """Отменить вендор-счёт. draft → cancelled. confirmed без оплат → сторно + cancelled."""
    assert_cancellable(bill.status)
    if bill.status == "draft":
        bill.status = "cancelled"
        await session.flush()
        return
    if q2(bill.paid_amount) != Decimal("0"):
        raise DocumentImmutable(
            "Нельзя отменить частично/полностью оплаченный вендор-счёт — сначала проведите возврат"
        )
    if bill.journal_entry_id is not None:
        entry = (
            await session.execute(
                select(FinJournalEntry).where(FinJournalEntry.id == bill.journal_entry_id)
            )
        ).scalar_one_or_none()
        if entry is not None and entry.status == "posted":
            await posting_db.reverse_entry(
                session, entry, posted_by_user_id=posted_by_user_id,
                memo=f"Сторно вендор-счёта {bill.bill_no or bill.id}",
            )
    bill.status = "cancelled"
    await session.flush()
