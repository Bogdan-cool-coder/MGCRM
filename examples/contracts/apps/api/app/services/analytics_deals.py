"""Аналитические агрегаторы для отчётов по сделкам DEALS 2.0 Ф3.

Чистые функции (без БД):
- aggregate_awaiting_payment  — группировка платежей по периодам
- compute_hot_forecast        — агрегат pipeline в hot
- select_paid_deals_summary   — итог по оплаченным сделкам

Все функции принимают plain-dict / list[dict] и полностью покрыты unit-тестами
без БД fixture.
"""
from __future__ import annotations

from datetime import date, timedelta
from decimal import Decimal


# ─── Сделки без задач (Wave 2a) ──────────────────────────────────────────────

# «Задача» для контроля = task-like Activity. note не считается задачей (это
# просто заметка, не действие к выполнению). Используется в фильтре /deals и в
# счётчике дашборда «Сделки без задач».
TASK_LIKE_ACTIVITY_KINDS: frozenset[str] = frozenset({"task", "call", "meeting"})


def is_open_task_activity(
    kind: str,
    completed_at: object | None,
    is_closed: bool,
) -> bool:
    """Pure-предикат: считается ли Activity «открытой задачей» для сделки.

    Открытая задача = task-like вид (task/call/meeting) И не завершена
    (completed_at IS NULL) И не закрыта (is_closed=False). note и завершённые/
    закрытые активности — НЕ задача.

    Зеркалит SQL-условие в /deals?no_tasks=true и в счётчике дашборда, чтобы
    определение «без задач» было в одном месте и тестировалось без БД.
    """
    if kind not in TASK_LIKE_ACTIVITY_KINDS:
        return False
    if completed_at is not None:
        return False
    if is_closed:
        return False
    return True


# ─── Awaiting payment ────────────────────────────────────────────────────────

def _week_key(d: date) -> str:
    """ISO-year + ISO-week → строка 'YYYY-WNN'."""
    y, w, _ = d.isocalendar()
    return f"{y}-W{w:02d}"


def _month_key(d: date) -> str:
    return d.strftime("%Y-%m")


def aggregate_awaiting_payment(
    payments: list[dict],
    today: date | None = None,
) -> dict:
    """Агрегация ожидаемых платежей по неделям и месяцам.

    payments — список dict:
      {payment_date: date, amount: Decimal, currency: str,
       deal_id: int, company_name: str, owner_name: str}

    Возвращает:
    {
        by_week:  {week_key: {total: Decimal, currency: str}},
        by_month: {month_key: {total: Decimal, currency: str}},
        grand_total: Decimal,
        primary_currency: str | None,
    }

    Примечание: суммируем в «primary» валюте (самой частой). Для
    multi-currency сделок показываем total каждой валюты отдельно в
    by_week/by_month, grand_total — по primary_currency.
    """
    if today is None:
        today = date.today()

    by_week: dict[str, dict[str, Decimal]] = {}   # week_key -> currency -> total
    by_month: dict[str, dict[str, Decimal]] = {}   # month_key -> currency -> total
    currency_totals: dict[str, Decimal] = {}

    for p in payments:
        pd = p.get("payment_date")
        if not isinstance(pd, date):
            continue
        amt = p.get("amount")
        try:
            amt_d = Decimal(str(amt)) if amt is not None else Decimal(0)
        except Exception:
            amt_d = Decimal(0)
        cur = (p.get("currency") or "").upper() or "—"

        wk = _week_key(pd)
        mk = _month_key(pd)

        by_week.setdefault(wk, {})[cur] = by_week.get(wk, {}).get(cur, Decimal(0)) + amt_d
        by_month.setdefault(mk, {})[cur] = by_month.get(mk, {}).get(cur, Decimal(0)) + amt_d
        currency_totals[cur] = currency_totals.get(cur, Decimal(0)) + amt_d

    # primary_currency — та, что встречается с наибольшей суммой
    primary_currency = (
        max(currency_totals, key=lambda c: currency_totals[c])
        if currency_totals else None
    )
    grand_total = currency_totals.get(primary_currency, Decimal(0)) if primary_currency else Decimal(0)

    return {
        "by_week": {k: {c: float(v) for c, v in cv.items()} for k, cv in sorted(by_week.items())},
        "by_month": {k: {c: float(v) for c, v in cv.items()} for k, cv in sorted(by_month.items())},
        "grand_total": float(grand_total),
        "primary_currency": primary_currency,
    }


# ─── Hot deals forecast ───────────────────────────────────────────────────────

def compute_hot_forecast(
    deals: list[dict],
    today: date | None = None,
) -> dict:
    """Агрегат pipeline в этапе hot.

    deals — список dict:
      {deal_id: int, company_name: str, amount: Decimal|None,
       currency: str|None, expected_close_date: date|None,
       owner_name: str, days_to_close: int|None}

    Возвращает:
    {
        total_pipeline: float,           -- сумма amount по primary currency
        primary_currency: str | None,
        count: int,
        overdue_count: int,              -- deals где days_to_close < 0
        closing_this_week: int,          -- days_to_close in [0..6]
        avg_amount: float,               -- средний чек (только с суммой)
    }
    """
    if today is None:
        today = date.today()

    total_by_cur: dict[str, Decimal] = {}
    count = 0
    overdue = 0
    closing_week = 0
    amounts: list[Decimal] = []

    for d in deals:
        count += 1
        amt = d.get("amount")
        try:
            amt_d = Decimal(str(amt)) if amt is not None else None
        except Exception:
            amt_d = None
        cur = (d.get("currency") or "").upper() or None

        if amt_d is not None and cur:
            total_by_cur[cur] = total_by_cur.get(cur, Decimal(0)) + amt_d
            amounts.append(amt_d)

        dtc = d.get("days_to_close")
        if dtc is not None:
            if dtc < 0:
                overdue += 1
            elif dtc <= 6:
                closing_week += 1

    primary_currency = (
        max(total_by_cur, key=lambda c: total_by_cur[c])
        if total_by_cur else None
    )
    total_pipeline = float(total_by_cur.get(primary_currency, Decimal(0))) if primary_currency else 0.0
    avg_amount = float(sum(amounts) / len(amounts)) if amounts else 0.0

    return {
        "total_pipeline": total_pipeline,
        "primary_currency": primary_currency,
        "count": count,
        "overdue_count": overdue,
        "closing_this_week": closing_week,
        "avg_amount": avg_amount,
    }


# ─── Paid deals summary ───────────────────────────────────────────────────────

def compute_paid_summary(paid_deals: list[dict]) -> dict:
    """Итоговый агрегат по оплаченным сделкам.

    paid_deals — список dict:
      {deal_id, company_name, contract_number, first_payment_date,
       first_payment_amount, first_payment_currency, deal_amount, currency}

    Возвращает:
    {
        total_count: int,
        total_amount_by_currency: {currency: float},
        primary_currency: str | None,
        grand_total: float,
    }
    """
    total_by_cur: dict[str, Decimal] = {}
    for d in paid_deals:
        amt = d.get("first_payment_amount") or d.get("deal_amount")
        cur = (d.get("first_payment_currency") or d.get("currency") or "").upper() or None
        try:
            amt_d = Decimal(str(amt)) if amt is not None else None
        except Exception:
            amt_d = None
        if amt_d is not None and cur:
            total_by_cur[cur] = total_by_cur.get(cur, Decimal(0)) + amt_d

    primary_currency = (
        max(total_by_cur, key=lambda c: total_by_cur[c])
        if total_by_cur else None
    )
    grand_total = float(total_by_cur.get(primary_currency, Decimal(0))) if primary_currency else 0.0

    return {
        "total_count": len(paid_deals),
        "total_amount_by_currency": {c: float(v) for c, v in total_by_cur.items()},
        "primary_currency": primary_currency,
        "grand_total": grand_total,
    }
