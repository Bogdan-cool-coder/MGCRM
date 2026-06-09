"""Платёжный календарь — агрегация ожидаемых поступлений/выплат (read-only проекция).

НЕ постит проводок и НЕ меняет состояние — это чистая проекция предстоящих и прошедших
ожидаемых платежей из разных источников в плоский список событий календаря:

  • FinInvoice (исходящие счета клиентам, неоплаченные)        → direction='in'
  • FinVendorBill (входящие счета поставщиков, неоплаченные)   → direction='out'
  • FinAct (акты выполненных работ с датой)                   → direction='in' (информ.)
  • FinPaymentRegistry (реестры платежей, расход)              → direction='out'
  • FinRequest (заявки менеджеров на платёж/ЗП/комиссию)       → direction='out'
  • Deal.expected_payment_date (ожидаемая оплата сделки)       → direction='in'

Статус события (planned / paid / overdue) выводится pure-функцией: overdue ⇔ срок
оплаты прошёл (due_date < today) И позиция ещё не оплачена. Денежные суммы — Decimal.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date
from decimal import Decimal

# ───────────────────────────── константы ─────────────────────────────

#: Направление денежного потока события.
DIRECTION_IN = "in"
DIRECTION_OUT = "out"

#: Статусы события календаря.
STATUS_PLANNED = "planned"
STATUS_PAID = "paid"
STATUS_OVERDUE = "overdue"

#: Типы источников (для drill-down в UI: тип + id → карточка сущности).
SOURCE_INVOICE = "invoice"
SOURCE_VENDOR_BILL = "vendor_bill"
SOURCE_ACT = "act"
SOURCE_REGISTRY = "payment_registry"
SOURCE_REQUEST = "request"
SOURCE_DEAL = "deal"

#: Статусы инвойса/вендор-счёта, которые НЕ создают ожидаемого платежа (закрытые/отменённые).
_INVOICE_DONE_STATUSES = frozenset({"paid", "cancelled", "draft"})
_VENDOR_BILL_DONE_STATUSES = frozenset({"paid", "cancelled", "draft"})


@dataclass(frozen=True)
class CalendarEvent:
    """Событие платёжного календаря (плоская проекция, без БД)."""

    date: date
    direction: str  # in | out
    title: str
    amount: Decimal
    currency: str
    source_type: str
    source_id: int
    status: str  # planned | paid | overdue
    legal_entity_id: int | None = None
    counterparty_company_id: int | None = None


# ───────────────────────────── pure: вывод статуса ─────────────────────────────


def derive_status(
    *,
    due_date: date | None,
    today: date,
    is_paid: bool,
    is_partial: bool = False,
) -> str:
    """Статус события: paid / overdue / planned (pure).

    • is_paid (полностью оплачено)                → 'paid'
    • срок прошёл (due_date < today) и не оплачено → 'overdue' (вкл. частичную оплату)
    • иначе                                        → 'planned'

    due_date=None ⇒ срок не задан, overdue не наступает (только planned/paid).
    """
    if is_paid:
        return STATUS_PAID
    if due_date is not None and due_date < today:
        return STATUS_OVERDUE
    return STATUS_PLANNED


def outstanding_amount(amount_gross: Decimal, paid_amount: Decimal) -> Decimal:
    """Остаток к оплате = gross − paid, но не меньше 0 (pure)."""
    rest = amount_gross - paid_amount
    if rest < Decimal("0"):
        return Decimal("0")
    return rest


def is_settled(amount_gross: Decimal, paid_amount: Decimal) -> bool:
    """True ⇔ счёт полностью оплачен (paid ≥ gross), gross>0 (pure)."""
    if amount_gross <= Decimal("0"):
        return True
    return paid_amount >= amount_gross


# ───────────────────────────── pure: мапперы источник → событие ─────────────────────────────


def event_from_invoice(
    *,
    invoice_id: int,
    number: str | None,
    counterparty_name: str | None,
    issue_date: date,
    due_date: date | None,
    currency: str,
    amount_gross: Decimal,
    paid_amount: Decimal,
    status: str,
    legal_entity_id: int,
    counterparty_company_id: int,
    today: date,
) -> CalendarEvent | None:
    """Инвойс → событие 'in'. Возвращает None для draft/paid/cancelled (нет ожидания).

    Дата события = due_date (срок оплаты) или issue_date, если срок не задан.
    Сумма = остаток к оплате (gross − paid).
    """
    if status in _INVOICE_DONE_STATUSES:
        return None
    rest = outstanding_amount(amount_gross, paid_amount)
    if rest <= Decimal("0"):
        return None
    paid = is_settled(amount_gross, paid_amount)
    st = derive_status(due_date=due_date, today=today, is_paid=paid)
    title = f"Счёт {number}" if number else f"Счёт №{invoice_id}"
    if counterparty_name:
        title += f" — {counterparty_name}"
    return CalendarEvent(
        date=due_date or issue_date,
        direction=DIRECTION_IN,
        title=title,
        amount=rest,
        currency=currency,
        source_type=SOURCE_INVOICE,
        source_id=invoice_id,
        status=st,
        legal_entity_id=legal_entity_id,
        counterparty_company_id=counterparty_company_id,
    )


def event_from_vendor_bill(
    *,
    bill_id: int,
    number: str | None,
    supplier_name: str | None,
    bill_date: date,
    due_date: date | None,
    currency: str,
    amount_gross: Decimal,
    paid_amount: Decimal,
    status: str,
    legal_entity_id: int,
    supplier_company_id: int,
    today: date,
) -> CalendarEvent | None:
    """Вендор-счёт → событие 'out'. None для draft/paid/cancelled."""
    if status in _VENDOR_BILL_DONE_STATUSES:
        return None
    rest = outstanding_amount(amount_gross, paid_amount)
    if rest <= Decimal("0"):
        return None
    paid = is_settled(amount_gross, paid_amount)
    st = derive_status(due_date=due_date, today=today, is_paid=paid)
    title = f"Счёт поставщика {number}" if number else f"Счёт поставщика №{bill_id}"
    if supplier_name:
        title += f" — {supplier_name}"
    return CalendarEvent(
        date=due_date or bill_date,
        direction=DIRECTION_OUT,
        title=title,
        amount=rest,
        currency=currency,
        source_type=SOURCE_VENDOR_BILL,
        source_id=bill_id,
        status=st,
        legal_entity_id=legal_entity_id,
        counterparty_company_id=supplier_company_id,
    )


def event_from_request(
    *,
    request_id: int,
    number: str | None,
    request_type: str,
    desired_date: date | None,
    created_date: date,
    currency: str,
    amount: Decimal,
    status: str,
    legal_entity_id: int,
    counterparty_company_id: int | None,
    today: date,
) -> CalendarEvent | None:
    """Заявка менеджера → событие 'out'. Учитываем только submitted/approved (ожидание
    выплаты). draft/rejected/cancelled — не события; paid → событие со статусом 'paid'.
    """
    if status in ("draft", "rejected", "cancelled"):
        return None
    is_paid = status == "paid"
    st = derive_status(
        due_date=desired_date, today=today, is_paid=is_paid
    )
    type_label = {
        "salary": "Зарплата",
        "commission": "Комиссия",
        "expense_reimbursement": "Возмещение расходов",
        "payment": "Платёж",
    }.get(request_type, "Заявка")
    title = f"{type_label} (заявка {number})" if number else f"{type_label} (заявка №{request_id})"
    return CalendarEvent(
        date=desired_date or created_date,
        direction=DIRECTION_OUT,
        title=title,
        amount=amount,
        currency=currency,
        source_type=SOURCE_REQUEST,
        source_id=request_id,
        status=st,
        legal_entity_id=legal_entity_id,
        counterparty_company_id=counterparty_company_id,
    )


def event_from_deal(
    *,
    deal_id: int,
    title: str,
    expected_payment_date: date,
    currency: str | None,
    amount: Decimal | None,
    legal_entity_id: int | None,
    today: date,
) -> CalendarEvent | None:
    """Сделка с expected_payment_date → ожидаемое поступление 'in'. None если нет суммы."""
    if amount is None or amount <= Decimal("0"):
        return None
    # Сделка — план: оплата ещё не зафиксирована в финмодуле ⇒ paid=False.
    st = derive_status(due_date=expected_payment_date, today=today, is_paid=False)
    return CalendarEvent(
        date=expected_payment_date,
        direction=DIRECTION_IN,
        title=f"Ожидаемая оплата: {title}" if title else f"Сделка №{deal_id}",
        amount=amount,
        currency=currency or "RUB",
        source_type=SOURCE_DEAL,
        source_id=deal_id,
        status=st,
        legal_entity_id=legal_entity_id,
        counterparty_company_id=None,
    )


def event_from_act(
    *,
    act_id: int,
    number: str | None,
    counterparty_name: str | None,
    act_date: date,
    currency: str,
    amount_gross: Decimal,
    status: str,
    legal_entity_id: int,
    counterparty_company_id: int,
    today: date,
) -> CalendarEvent | None:
    """Акт → информационное событие 'in' (подтверждение оказания услуги). None для
    draft/cancelled. Акт не несёт собственного срока оплаты ⇒ статус всегда 'planned'.
    """
    if status in ("draft", "cancelled"):
        return None
    if amount_gross <= Decimal("0"):
        return None
    title = f"Акт {number}" if number else f"Акт №{act_id}"
    if counterparty_name:
        title += f" — {counterparty_name}"
    return CalendarEvent(
        date=act_date,
        direction=DIRECTION_IN,
        title=title,
        amount=amount_gross,
        currency=currency,
        source_type=SOURCE_ACT,
        source_id=act_id,
        status=STATUS_PLANNED,
        legal_entity_id=legal_entity_id,
        counterparty_company_id=counterparty_company_id,
    )


def event_from_registry(
    *,
    registry_id: int,
    number: str | None,
    title: str | None,
    registry_date: date,
    legal_entity_id: int,
    amount_total: Decimal,
    currency: str,
    payment_status: str,
    today: date,
) -> CalendarEvent | None:
    """Реестр платежей → событие 'out' (батч расходов). None если сумма 0.

    Реестр не несёт отдельного due_date ⇒ дата = registry_date; paid ⇔ payment_status='paid'.
    """
    if amount_total <= Decimal("0"):
        return None
    is_paid = payment_status == "paid"
    st = derive_status(due_date=registry_date, today=today, is_paid=is_paid)
    label = title or (f"Реестр {number}" if number else f"Реестр №{registry_id}")
    return CalendarEvent(
        date=registry_date,
        direction=DIRECTION_OUT,
        title=label,
        amount=amount_total,
        currency=currency,
        source_type=SOURCE_REGISTRY,
        source_id=registry_id,
        status=st,
        legal_entity_id=legal_entity_id,
        counterparty_company_id=None,
    )


# ───────────────────────────── pure: фильтр + агрегаты ─────────────────────────────


def in_window(event_date: date, date_from: date, date_to: date) -> bool:
    """True ⇔ date_from ≤ event_date ≤ date_to (pure)."""
    return date_from <= event_date <= date_to


def filter_by_direction(events: list[CalendarEvent], direction: str) -> list[CalendarEvent]:
    """Отфильтровать события по направлению ('in' | 'out' | 'all'). Pure."""
    if direction == "all":
        return events
    return [e for e in events if e.direction == direction]


@dataclass(frozen=True)
class DayTotal:
    """Агрегат по дню: суммы in/out по валютам + кол-во событий."""

    date: date
    total_in: dict[str, Decimal]
    total_out: dict[str, Decimal]
    overdue_count: int
    event_count: int


def day_totals(events: list[CalendarEvent]) -> list[DayTotal]:
    """Сгруппировать события по дню → суммы in/out по валютам (pure).

    Возвращает список DayTotal, отсортированный по дате. Суммы держатся пер-валюта
    (без конвертации — календарь мультивалютный, конверсию делает UI при необходимости).
    """
    by_day: dict[date, dict[str, object]] = {}
    for e in events:
        bucket = by_day.setdefault(
            e.date,
            {
                "in": {},
                "out": {},
                "overdue": 0,
                "count": 0,
            },
        )
        side = "in" if e.direction == DIRECTION_IN else "out"
        sums: dict[str, Decimal] = bucket[side]  # type: ignore[assignment]
        sums[e.currency] = sums.get(e.currency, Decimal("0")) + e.amount
        bucket["count"] = int(bucket["count"]) + 1  # type: ignore[arg-type]
        if e.status == STATUS_OVERDUE:
            bucket["overdue"] = int(bucket["overdue"]) + 1  # type: ignore[arg-type]

    out: list[DayTotal] = []
    for d in sorted(by_day.keys()):
        b = by_day[d]
        out.append(
            DayTotal(
                date=d,
                total_in=dict(b["in"]),  # type: ignore[arg-type]
                total_out=dict(b["out"]),  # type: ignore[arg-type]
                overdue_count=int(b["overdue"]),  # type: ignore[arg-type]
                event_count=int(b["count"]),  # type: ignore[arg-type]
            )
        )
    return out
