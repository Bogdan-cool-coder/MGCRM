"""Pure-function тесты Ф5 модуля «Финансы» (без DB fixture).

Покрыто:
  • build_invoice_issue   — Дт AR / Кт выручка(многострочно) / Кт НДС output, Σ amount_func=0.
  • build_vendor_bill_confirm — Дт расход + Дт НДС input / Кт AP, Σ=0.
  • settlement-арифметика — next_paid_amount/settle_status (partial/full/overpay).
  • НДС-книги — vat_book_from_rows (output/input агрегаты, знаки).
  • aging — aging_bucket + aggregate_aging (бакеты по due_date).
  • line-math — calc_line/sum_totals (net+vat=gross, vat=round(net*rate)).
  • status-переходы документа — assert_doc_editable/issuable/payable/cancellable.
"""

from __future__ import annotations

from datetime import date
from decimal import Decimal

import pytest

from app.services.finance.invoicing import (
    DocumentImmutable,
    DocumentNotIssued,
    OverPayment,
    assert_cancellable,
    assert_doc_editable,
    assert_issuable,
    assert_payable,
    calc_line,
    next_paid_amount,
    settle_status,
    sum_totals,
)
from app.services.finance.posting import (
    DocAmountLine,
    PostingError,
    build_invoice_issue,
    build_vendor_bill_confirm,
)
from app.services.finance.vat_books import (
    _AgingInput,
    _VatRow,
    aggregate_aging,
    aging_bucket,
    vat_book_from_rows,
)

_D = Decimal


def _same_resolver(frm: str, to: str, on_date: date) -> Decimal:
    # func==doc currency в этих тестах → курс 1.
    return _D("1")


# ───────────────────────────── build_invoice_issue ─────────────────────────────


def test_invoice_issue_balanced_dt_ar_kt_revenue_vat():
    """Дт AR(gross) / Кт выручка(net по позициям) / Кт НДС output(vat). Σ amount_func=0."""
    lines = [
        DocAmountLine(account_code="4010", amount_net=_D("100000"), vat_amount=_D("12000")),
        DocAmountLine(account_code="4030", amount_net=_D("50000"), vat_amount=_D("6000")),
    ]
    draft = build_invoice_issue(
        on_date=date(2026, 5, 31),
        func_currency="KZT",
        currency="KZT",
        lines=lines,
        ar_account_code="1210",
        vat_output_account_code="2310",
        counterparty_company_id=7,
        rate_resolver=_same_resolver,
        source_ref_id=77,
    )
    assert draft.kind == "revenue_accrual"
    assert draft.imbalance() == _D("0")
    by_code = {ln.account_code: ln for ln in draft.lines}
    # AR — дебет на gross=168000, единственная строка с контрагентом.
    ar = by_code["1210"]
    assert ar.amount == _D("168000")
    assert ar.counterparty_company_id == 7
    assert ar.money_account_id is None  # accrual, не деньги → вне ДДС
    # Выручка — кредит на net (отрицательная).
    assert by_code["4010"].amount == _D("-100000")
    assert by_code["4030"].amount == _D("-50000")
    # НДС output на 2310 — кредит на сумму НДС всех позиций.
    assert by_code["2310"].amount == _D("-18000")
    # 4 строки: AR + 2 выручки + НДС.
    assert len(draft.lines) == 4


def test_invoice_issue_no_vat_no_2310_line():
    """Режим «без НДС»: vat=0 → строки 2310 нет, Дт AR=Кт выручка."""
    lines = [DocAmountLine(account_code="4030", amount_net=_D("30000"), vat_amount=_D("0"))]
    draft = build_invoice_issue(
        on_date=date(2026, 6, 1), func_currency="UZS", currency="UZS",
        lines=lines, ar_account_code="1210", vat_output_account_code="2310",
        counterparty_company_id=3, rate_resolver=_same_resolver,
    )
    codes = {ln.account_code for ln in draft.lines}
    assert "2310" not in codes
    assert draft.imbalance() == _D("0")
    assert len(draft.lines) == 2


def test_invoice_issue_empty_lines_raises():
    with pytest.raises(PostingError):
        build_invoice_issue(
            on_date=date(2026, 6, 1), func_currency="KZT", currency="KZT",
            lines=[], ar_account_code="1210", vat_output_account_code="2310",
            counterparty_company_id=1, rate_resolver=_same_resolver,
        )


def test_invoice_issue_cross_currency_func_amount():
    """Кросс-валюта: amount в валюте документа, amount_func через курс. Σ amount_func=0."""
    def resolver(frm, to, on_date):
        assert (frm, to) == ("USD", "KZT")
        return _D("450")

    lines = [DocAmountLine(account_code="4030", amount_net=_D("100"), vat_amount=_D("12"))]
    draft = build_invoice_issue(
        on_date=date(2026, 6, 1), func_currency="KZT", currency="USD",
        lines=lines, ar_account_code="1210", vat_output_account_code="2310",
        counterparty_company_id=2, rate_resolver=resolver,
    )
    assert draft.imbalance() == _D("0")
    ar = next(ln for ln in draft.lines if ln.account_code == "1210")
    assert ar.amount == _D("112")  # gross в USD
    assert ar.amount_func == _D("50400.00")  # 112 * 450


# ───────────────────────────── build_vendor_bill_confirm ─────────────────────────────


def test_vendor_bill_confirm_balanced_dt_expense_vat_kt_ap():
    """Дт расход(net) + Дт НДС input(vat) / Кт AP(gross). Σ amount_func=0."""
    lines = [
        DocAmountLine(account_code="5210", amount_net=_D("80000"), vat_amount=_D("9600")),
        DocAmountLine(account_code="5220", amount_net=_D("20000"), vat_amount=_D("2400")),
    ]
    draft = build_vendor_bill_confirm(
        on_date=date(2026, 5, 20),
        func_currency="KZT", currency="KZT",
        lines=lines, ap_account_code="2110", vat_input_account_code="1910",
        supplier_company_id=9, rate_resolver=_same_resolver,
        source_ref_id=55,
    )
    assert draft.kind == "expense_accrual"
    assert draft.imbalance() == _D("0")
    by_code = {ln.account_code: ln for ln in draft.lines}
    # Расход — дебет на net (положительный).
    assert by_code["5210"].amount == _D("80000")
    assert by_code["5220"].amount == _D("20000")
    # НДС input на 1910 — дебет на сумму НДС.
    assert by_code["1910"].amount == _D("12000")
    # AP — кредит на gross=112000, строка с контрагентом-поставщиком.
    ap = by_code["2110"]
    assert ap.amount == _D("-112000")
    assert ap.counterparty_company_id == 9
    assert ap.money_account_id is None  # accrual → вне ДДС
    assert len(draft.lines) == 4


def test_vendor_bill_confirm_no_vat():
    lines = [DocAmountLine(account_code="5990", amount_net=_D("5000"), vat_amount=_D("0"))]
    draft = build_vendor_bill_confirm(
        on_date=date(2026, 6, 1), func_currency="UZS", currency="UZS",
        lines=lines, ap_account_code="2110", vat_input_account_code="1910",
        supplier_company_id=4, rate_resolver=_same_resolver,
    )
    codes = {ln.account_code for ln in draft.lines}
    assert "1910" not in codes
    assert draft.imbalance() == _D("0")
    assert len(draft.lines) == 2


# ───────────────────────────── settlement-арифметика ─────────────────────────────


def test_next_paid_amount_partial_then_full():
    gross = _D("168000")
    p1 = next_paid_amount(_D("0"), _D("100000"), gross)
    assert p1 == _D("100000")
    p2 = next_paid_amount(p1, _D("68000"), gross)
    assert p2 == _D("168000")


def test_next_paid_amount_overpay_raises():
    with pytest.raises(OverPayment):
        next_paid_amount(_D("100000"), _D("100000"), _D("168000"))


def test_settle_status_transitions_invoice():
    g = _D("168000")
    assert settle_status(_D("0"), g, issued_value="issued") == "issued"
    assert settle_status(_D("50000"), g, issued_value="issued") == "partially_paid"
    assert settle_status(_D("168000"), g, issued_value="issued") == "paid"


def test_settle_status_transitions_vendor_bill():
    g = _D("112000")
    assert settle_status(_D("0"), g, issued_value="confirmed") == "confirmed"
    assert settle_status(_D("1"), g, issued_value="confirmed") == "partially_paid"
    assert settle_status(_D("112000"), g, issued_value="confirmed") == "paid"


def test_paid_amount_reconciliation_after_series_of_settlements():
    """СОГЛАСОВАННОСТЬ #4: материализованный paid_amount == Σ settlement-платежей по
    документу. Эмулируем read-modify-write paid_amount как в pay_invoice (под FOR
    UPDATE, атомарно с проводкой) — каждый settlement добавляет своё гашение AR.
    paid_amount обязан равняться сумме всех cash_in по инвойсу (= GL-сальдо гашения AR).
    """
    gross = _D("168000")
    payments = [_D("40000"), _D("60000.50"), _D("67999.50")]
    paid = _D("0")
    for pmt in payments:
        # ровно та же арифметика, что в pay_invoice → invoice.paid_amount = new_paid.
        paid = next_paid_amount(paid, pmt, gross)
    # инвариант: материализованное paid_amount == Σ проведённых settlement-платежей.
    assert paid == sum(payments, _D("0")) == _D("168000.00")
    assert settle_status(paid, gross, issued_value="issued") == "paid"


def test_paid_amount_reconciliation_partial_stops_at_partially_paid():
    """Согласованность при неполной оплате: paid_amount == Σ платежей, статус
    partially_paid (остаток к гашению AR = gross − paid > 0)."""
    gross = _D("168000")
    payments = [_D("40000"), _D("60000")]
    paid = _D("0")
    for pmt in payments:
        paid = next_paid_amount(paid, pmt, gross)
    assert paid == sum(payments, _D("0")) == _D("100000.00")
    assert settle_status(paid, gross, issued_value="issued") == "partially_paid"
    # остаток непогашенной дебиторки (для aging) = gross − paid.
    assert gross - paid == _D("68000.00")


# ───────────────────────────── line-math ─────────────────────────────


def test_calc_line_net_vat_gross():
    c = calc_line(_D("2"), _D("50000"), _D("12"))
    assert c.amount_net == _D("100000.00")
    assert c.vat_amount == _D("12000.00")
    assert c.amount_gross == _D("112000.00")


def test_calc_line_zero_rate():
    c = calc_line(_D("1"), _D("30000"), _D("0"))
    assert c.vat_amount == _D("0.00")
    assert c.amount_gross == c.amount_net


def test_calc_line_rounding_half_up():
    # net=333.33*1, rate 12% → 39.9996 → 40.00 HALF_UP.
    c = calc_line(_D("1"), _D("333.33"), _D("12"))
    assert c.vat_amount == _D("40.00")
    assert c.amount_gross == _D("373.33")


def test_sum_totals_aggregates_lines():
    lines = [
        calc_line(_D("1"), _D("100000"), _D("12")),
        calc_line(_D("1"), _D("50000"), _D("12")),
    ]
    t = sum_totals(lines)
    assert t.amount_net == _D("150000.00")
    assert t.vat_amount == _D("18000.00")
    assert t.amount_gross == _D("168000.00")


def test_bill_line_math_qty_gt_one_with_vat():
    """РЕГРЕССИЯ #1: _apply_bill_lines/_apply_invoice_lines раньше ставили
    amount_net=amount_gross=unit_price «в лоб», игнорируя qty и НДС (верно лишь
    благодаря последующему recompute). Теперь суммы считаются через calc_line на
    месте — net=qty×price, vat по ставке. Эта же формула — в роутере и в recompute.
    """
    c = calc_line(_D("3"), _D("100"), _D("20"))
    # qty учтён: net=3×100=300 (а не 100=unit_price), vat=60, gross=360.
    assert c.amount_net == _D("300.00")
    assert c.vat_amount == _D("60.00")
    assert c.amount_gross == _D("360.00")
    # многострочный документ сворачивается корректно (как в _apply_*_lines).
    lines = [
        calc_line(_D("3"), _D("100"), _D("20")),
        calc_line(_D("2"), _D("250"), _D("0")),  # позиция без НДС
    ]
    t = sum_totals(lines)
    assert t.amount_net == _D("800.00")   # 300 + 500
    assert t.vat_amount == _D("60.00")    # 60 + 0
    assert t.amount_gross == _D("860.00")


# ───────────────────────────── НДС-книги ─────────────────────────────


def test_vat_book_output_from_negative_ledger():
    """Книга продаж: строки 2310 Кт<0 → НДС положительный (модуль)."""
    rows = [
        _VatRow(1, date(2026, 5, 31), "invoice", 77, 7, _D("-18000"), None),
        _VatRow(2, date(2026, 5, 15), "invoice", 78, 8, _D("-6000"), None),
    ]
    book, total = vat_book_from_rows(rows, kind="output")
    assert total == _D("24000.00")
    assert all(e.vat_amount > 0 for e in book)
    assert book[0].source_ref_id == 77


def test_vat_book_input_from_positive_ledger():
    """Книга покупок: строки 1910 Дт>0 → НДС положительный."""
    rows = [_VatRow(3, date(2026, 5, 20), "vendor_bill", 55, 9, _D("12000"), None)]
    book, total = vat_book_from_rows(rows, kind="input")
    assert total == _D("12000.00")
    assert book[0].vat_amount == _D("12000.00")


def test_vat_book_drops_zero():
    rows = [_VatRow(4, date(2026, 5, 1), "invoice", 1, 1, _D("0"), None)]
    book, total = vat_book_from_rows(rows, kind="output")
    assert book == []
    assert total == _D("0")


def test_vat_payable_is_output_minus_input():
    out_rows = [_VatRow(1, date(2026, 5, 31), "invoice", 77, 7, _D("-18000"), None)]
    in_rows = [_VatRow(2, date(2026, 5, 20), "vendor_bill", 55, 9, _D("12000"), None)]
    _, output = vat_book_from_rows(out_rows, kind="output")
    _, inp = vat_book_from_rows(in_rows, kind="input")
    assert output - inp == _D("6000.00")  # к уплате


# ───────────────────────────── aging ─────────────────────────────


def test_aging_bucket_boundaries():
    as_of = date(2026, 6, 30)
    assert aging_bucket(None, as_of) == "current"
    assert aging_bucket(date(2026, 7, 10), as_of) == "current"  # будущее
    assert aging_bucket(date(2026, 6, 30), as_of) == "0-30"     # 0 дней
    assert aging_bucket(date(2026, 6, 1), as_of) == "0-30"      # 29 дней
    assert aging_bucket(date(2026, 5, 20), as_of) == "31-60"    # 41 день
    assert aging_bucket(date(2026, 4, 20), as_of) == "61-90"    # 71 день
    assert aging_bucket(date(2026, 2, 1), as_of) == "90+"       # >90


def test_aggregate_aging_buckets_and_total():
    as_of = date(2026, 6, 30)
    inputs = [
        _AgingInput(1, "СЧ-1", 7, "KZT", date(2026, 7, 10), _D("50000")),   # current
        _AgingInput(2, "СЧ-2", 8, "KZT", date(2026, 6, 1), _D("30000")),    # 0-30
        _AgingInput(3, "СЧ-3", 9, "KZT", date(2026, 5, 20), _D("20000")),   # 31-60
        _AgingInput(4, "СЧ-4", 9, "KZT", date(2026, 6, 30), _D("0")),       # отброшено (0)
    ]
    report = aggregate_aging(inputs, as_of)
    assert len(report.docs) == 3  # нулевой остаток отброшен
    assert report.by_bucket["current"] == _D("50000.00")
    assert report.by_bucket["0-30"] == _D("30000.00")
    assert report.by_bucket["31-60"] == _D("20000.00")
    assert report.total == _D("100000.00")


# ───────────────────────────── status-переходы ─────────────────────────────


def test_assert_doc_editable_only_draft():
    assert_doc_editable("draft")  # ok
    for s in ("issued", "confirmed", "partially_paid", "paid", "cancelled"):
        with pytest.raises(DocumentImmutable):
            assert_doc_editable(s)


def test_assert_issuable_only_draft():
    assert_issuable("draft")
    with pytest.raises(DocumentImmutable):
        assert_issuable("issued")


def test_assert_payable_requires_issued():
    assert_payable("issued", issued_statuses=("issued", "partially_paid"))
    assert_payable("partially_paid", issued_statuses=("issued", "partially_paid"))
    with pytest.raises(DocumentNotIssued):
        assert_payable("draft", issued_statuses=("issued", "partially_paid"))
    with pytest.raises(DocumentNotIssued):
        assert_payable("paid", issued_statuses=("issued", "partially_paid"))


def test_assert_cancellable_blocks_paid():
    assert_cancellable("draft")
    assert_cancellable("issued")
    assert_cancellable("confirmed")
    for s in ("paid", "cancelled"):
        with pytest.raises(DocumentImmutable):
            assert_cancellable(s)


def test_assert_cancellable_blocks_partially_paid():
    """partially_paid имеет оплату → отмена запрещена ПЕРВЫМ guard (не только вторым в
    cancel_invoice по paid_amount). Любая оплата блокирует отмену — сначала возврат."""
    with pytest.raises(DocumentImmutable):
        assert_cancellable("partially_paid")
