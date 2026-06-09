"""Pure-function тесты платёжного календаря (TASK 37) — без DB fixture.

Покрыто:
  • derive_status — paid / overdue / planned (включая due_date=None).
  • outstanding_amount / is_settled — остаток к оплате, полнота оплаты.
  • event_from_invoice / vendor_bill / request / deal / act / registry — мапперы:
    фильтрация draft/paid/cancelled → None, направление, дата (due_date|fallback),
    сумма (остаток), статус.
  • filter_by_direction — in / out / all.
  • day_totals — группировка по дню, суммы по валютам, overdue_count.
"""

from __future__ import annotations

from datetime import date
from decimal import Decimal

from app.services.finance.calendar import (
    DIRECTION_IN,
    DIRECTION_OUT,
    SOURCE_DEAL,
    SOURCE_INVOICE,
    SOURCE_REGISTRY,
    SOURCE_REQUEST,
    SOURCE_VENDOR_BILL,
    STATUS_OVERDUE,
    STATUS_PAID,
    STATUS_PLANNED,
    CalendarEvent,
    day_totals,
    derive_status,
    event_from_act,
    event_from_deal,
    event_from_invoice,
    event_from_registry,
    event_from_request,
    event_from_vendor_bill,
    filter_by_direction,
    is_settled,
    outstanding_amount,
)

TODAY = date(2026, 6, 4)


# ───────────────────────────── derive_status ─────────────────────────────


def test_status_paid_wins():
    assert derive_status(due_date=date(2026, 1, 1), today=TODAY, is_paid=True) == STATUS_PAID


def test_status_overdue_when_due_passed_and_unpaid():
    assert (
        derive_status(due_date=date(2026, 5, 1), today=TODAY, is_paid=False)
        == STATUS_OVERDUE
    )


def test_status_planned_when_due_future():
    assert (
        derive_status(due_date=date(2026, 7, 1), today=TODAY, is_paid=False)
        == STATUS_PLANNED
    )


def test_status_planned_when_due_today():
    # due == today не просрочено (граница).
    assert derive_status(due_date=TODAY, today=TODAY, is_paid=False) == STATUS_PLANNED


def test_status_no_due_date_never_overdue():
    assert derive_status(due_date=None, today=TODAY, is_paid=False) == STATUS_PLANNED


# ───────────────────────────── outstanding / settled ─────────────────────────────


def test_outstanding_amount():
    assert outstanding_amount(Decimal("100"), Decimal("30")) == Decimal("70")


def test_outstanding_amount_never_negative():
    assert outstanding_amount(Decimal("100"), Decimal("150")) == Decimal("0")


def test_is_settled_true_when_paid_ge_gross():
    assert is_settled(Decimal("100"), Decimal("100")) is True
    assert is_settled(Decimal("100"), Decimal("120")) is True


def test_is_settled_false_when_partial():
    assert is_settled(Decimal("100"), Decimal("99")) is False


def test_is_settled_zero_gross_is_settled():
    assert is_settled(Decimal("0"), Decimal("0")) is True


# ───────────────────────────── invoice mapper ─────────────────────────────


def _invoice(**kw):
    base = dict(
        invoice_id=1, number="INV-1", counterparty_name="ООО Ромашка",
        issue_date=date(2026, 5, 20), due_date=date(2026, 6, 10),
        currency="RUB", amount_gross=Decimal("12000"), paid_amount=Decimal("0"),
        status="issued", legal_entity_id=1, counterparty_company_id=7, today=TODAY,
    )
    base.update(kw)
    return event_from_invoice(**base)


def test_invoice_event_basic():
    ev = _invoice()
    assert ev is not None
    assert ev.direction == DIRECTION_IN
    assert ev.source_type == SOURCE_INVOICE
    assert ev.date == date(2026, 6, 10)  # due_date
    assert ev.amount == Decimal("12000")
    assert ev.status == STATUS_PLANNED
    assert ev.counterparty_company_id == 7


def test_invoice_overdue_when_due_passed():
    ev = _invoice(due_date=date(2026, 5, 1))
    assert ev is not None and ev.status == STATUS_OVERDUE


def test_invoice_partial_paid_amount_is_remainder():
    ev = _invoice(paid_amount=Decimal("5000"))
    assert ev is not None and ev.amount == Decimal("7000")


def test_invoice_draft_paid_cancelled_skipped():
    assert _invoice(status="draft") is None
    assert _invoice(status="paid") is None
    assert _invoice(status="cancelled") is None


def test_invoice_fully_paid_remainder_skipped():
    assert _invoice(paid_amount=Decimal("12000")) is None


def test_invoice_no_due_date_uses_issue_date():
    ev = _invoice(due_date=None)
    assert ev is not None and ev.date == date(2026, 5, 20)


# ───────────────────────────── vendor bill mapper ─────────────────────────────


def test_vendor_bill_event_out_and_overdue():
    ev = event_from_vendor_bill(
        bill_id=3, number="VB-3", supplier_name="ООО Поставщик",
        bill_date=date(2026, 5, 1), due_date=date(2026, 5, 20), currency="USD",
        amount_gross=Decimal("500"), paid_amount=Decimal("0"), status="confirmed",
        legal_entity_id=1, supplier_company_id=9, today=TODAY,
    )
    assert ev is not None
    assert ev.direction == DIRECTION_OUT
    assert ev.source_type == SOURCE_VENDOR_BILL
    assert ev.status == STATUS_OVERDUE
    assert ev.currency == "USD"


def test_vendor_bill_draft_skipped():
    ev = event_from_vendor_bill(
        bill_id=3, number=None, supplier_name=None, bill_date=date(2026, 5, 1),
        due_date=None, currency="RUB", amount_gross=Decimal("500"),
        paid_amount=Decimal("0"), status="draft", legal_entity_id=1,
        supplier_company_id=9, today=TODAY,
    )
    assert ev is None


# ───────────────────────────── request mapper ─────────────────────────────


def test_request_event_out_planned():
    ev = event_from_request(
        request_id=5, number="REQ-5", request_type="salary",
        desired_date=date(2026, 6, 30), created_date=date(2026, 6, 1),
        currency="RUB", amount=Decimal("80000"), status="approved",
        legal_entity_id=1, counterparty_company_id=None, today=TODAY,
    )
    assert ev is not None
    assert ev.direction == DIRECTION_OUT
    assert ev.source_type == SOURCE_REQUEST
    assert ev.date == date(2026, 6, 30)
    assert "Зарплата" in ev.title
    assert ev.status == STATUS_PLANNED


def test_request_paid_status():
    ev = event_from_request(
        request_id=5, number="REQ-5", request_type="payment",
        desired_date=date(2026, 5, 1), created_date=date(2026, 5, 1),
        currency="RUB", amount=Decimal("1000"), status="paid",
        legal_entity_id=1, counterparty_company_id=None, today=TODAY,
    )
    assert ev is not None and ev.status == STATUS_PAID


def test_request_draft_rejected_cancelled_skipped():
    for st in ("draft", "rejected", "cancelled"):
        assert event_from_request(
            request_id=5, number=None, request_type="payment", desired_date=None,
            created_date=date(2026, 6, 1), currency="RUB", amount=Decimal("1000"),
            status=st, legal_entity_id=1, counterparty_company_id=None, today=TODAY,
        ) is None


def test_request_no_desired_date_uses_created():
    ev = event_from_request(
        request_id=5, number=None, request_type="commission", desired_date=None,
        created_date=date(2026, 6, 2), currency="RUB", amount=Decimal("1000"),
        status="submitted", legal_entity_id=1, counterparty_company_id=None, today=TODAY,
    )
    assert ev is not None and ev.date == date(2026, 6, 2)


# ───────────────────────────── deal mapper ─────────────────────────────


def test_deal_event_in():
    ev = event_from_deal(
        deal_id=11, title="Сделка X", expected_payment_date=date(2026, 6, 20),
        currency="RUB", amount=Decimal("250000"), legal_entity_id=None, today=TODAY,
    )
    assert ev is not None
    assert ev.direction == DIRECTION_IN
    assert ev.source_type == SOURCE_DEAL
    assert ev.amount == Decimal("250000")
    assert ev.status == STATUS_PLANNED


def test_deal_no_amount_skipped():
    assert event_from_deal(
        deal_id=11, title="X", expected_payment_date=date(2026, 6, 20),
        currency="RUB", amount=None, legal_entity_id=None, today=TODAY,
    ) is None


def test_deal_default_currency_rub():
    ev = event_from_deal(
        deal_id=11, title="X", expected_payment_date=date(2026, 6, 20),
        currency=None, amount=Decimal("100"), legal_entity_id=None, today=TODAY,
    )
    assert ev is not None and ev.currency == "RUB"


# ───────────────────────────── act / registry mappers ─────────────────────────────


def test_act_event_planned_in():
    ev = event_from_act(
        act_id=2, number="ACT-2", counterparty_name="ООО К", act_date=date(2026, 6, 5),
        currency="RUB", amount_gross=Decimal("9000"), status="issued",
        legal_entity_id=1, counterparty_company_id=7, today=TODAY,
    )
    assert ev is not None and ev.direction == DIRECTION_IN and ev.status == STATUS_PLANNED


def test_act_draft_skipped():
    assert event_from_act(
        act_id=2, number=None, counterparty_name=None, act_date=date(2026, 6, 5),
        currency="RUB", amount_gross=Decimal("9000"), status="draft",
        legal_entity_id=1, counterparty_company_id=7, today=TODAY,
    ) is None


def test_registry_event_out():
    ev = event_from_registry(
        registry_id=4, number="REG-4", title="Аренда+связь",
        registry_date=date(2026, 5, 25), legal_entity_id=1,
        amount_total=Decimal("33000"), currency="RUB",
        payment_status="new", today=TODAY,
    )
    assert ev is not None
    assert ev.direction == DIRECTION_OUT
    assert ev.source_type == SOURCE_REGISTRY
    assert ev.status == STATUS_OVERDUE  # registry_date < today, not paid
    assert ev.title == "Аренда+связь"


def test_registry_paid_status():
    ev = event_from_registry(
        registry_id=4, number="REG-4", title=None, registry_date=date(2026, 5, 25),
        legal_entity_id=1, amount_total=Decimal("33000"), currency="RUB",
        payment_status="paid", today=TODAY,
    )
    assert ev is not None and ev.status == STATUS_PAID


def test_registry_zero_amount_skipped():
    assert event_from_registry(
        registry_id=4, number=None, title=None, registry_date=date(2026, 5, 25),
        legal_entity_id=1, amount_total=Decimal("0"), currency="RUB",
        payment_status="new", today=TODAY,
    ) is None


# ───────────────────────────── filter + aggregates ─────────────────────────────


def _ev(d, direction, amount, currency="RUB", status=STATUS_PLANNED):
    return CalendarEvent(
        date=d, direction=direction, title="x", amount=Decimal(amount),
        currency=currency, source_type="invoice", source_id=1, status=status,
    )


def test_filter_by_direction():
    evs = [
        _ev(date(2026, 6, 1), DIRECTION_IN, "10"),
        _ev(date(2026, 6, 1), DIRECTION_OUT, "20"),
    ]
    assert len(filter_by_direction(evs, "all")) == 2
    assert all(e.direction == DIRECTION_IN for e in filter_by_direction(evs, "in"))
    assert all(e.direction == DIRECTION_OUT for e in filter_by_direction(evs, "out"))


def test_day_totals_groups_by_currency_and_direction():
    evs = [
        _ev(date(2026, 6, 1), DIRECTION_IN, "100", "RUB"),
        _ev(date(2026, 6, 1), DIRECTION_IN, "50", "USD"),
        _ev(date(2026, 6, 1), DIRECTION_OUT, "30", "RUB", status=STATUS_OVERDUE),
        _ev(date(2026, 6, 2), DIRECTION_OUT, "70", "RUB"),
    ]
    totals = day_totals(evs)
    assert len(totals) == 2
    d1 = totals[0]
    assert d1.date == date(2026, 6, 1)
    assert d1.total_in == {"RUB": Decimal("100"), "USD": Decimal("50")}
    assert d1.total_out == {"RUB": Decimal("30")}
    assert d1.overdue_count == 1
    assert d1.event_count == 3
    # отсортировано по дате
    assert totals[1].date == date(2026, 6, 2)
    assert totals[1].total_out == {"RUB": Decimal("70")}
