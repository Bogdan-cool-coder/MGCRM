"""Чистые unit-тесты для analytics_deals.py — без БД fixture."""
from datetime import date
from decimal import Decimal

from app.services.analytics_deals import (
    aggregate_awaiting_payment,
    compute_hot_forecast,
    compute_paid_summary,
)

# ─── aggregate_awaiting_payment ───────────────────────────────────────────────

TODAY = date(2026, 6, 3)


def test_aggregate_empty():
    result = aggregate_awaiting_payment([], today=TODAY)
    assert result["by_week"] == {}
    assert result["by_month"] == {}
    assert result["grand_total"] == 0.0
    assert result["primary_currency"] is None


def test_aggregate_single_payment():
    payments = [
        {
            "payment_date": date(2026, 6, 10),
            "amount": Decimal("100000"),
            "currency": "KZT",
            "deal_id": 1,
            "company_name": "ТОО Рога",
            "owner_name": "Иванов",
        }
    ]
    result = aggregate_awaiting_payment(payments, today=TODAY)
    assert result["primary_currency"] == "KZT"
    assert result["grand_total"] == 100000.0
    assert "2026-06" in result["by_month"]
    assert result["by_month"]["2026-06"]["KZT"] == 100000.0
    assert len(result["by_week"]) == 1


def test_aggregate_multi_month():
    payments = [
        {"payment_date": date(2026, 6, 5), "amount": Decimal("50000"), "currency": "KZT", "deal_id": 1, "company_name": "A", "owner_name": "X"},
        {"payment_date": date(2026, 7, 15), "amount": Decimal("70000"), "currency": "KZT", "deal_id": 2, "company_name": "B", "owner_name": "Y"},
        {"payment_date": date(2026, 6, 20), "amount": Decimal("30000"), "currency": "KZT", "deal_id": 3, "company_name": "C", "owner_name": "Z"},
    ]
    result = aggregate_awaiting_payment(payments, today=TODAY)
    assert result["by_month"]["2026-06"]["KZT"] == 80000.0
    assert result["by_month"]["2026-07"]["KZT"] == 70000.0
    # grand_total = 50k + 70k + 30k = 150k
    assert result["grand_total"] == 150000.0


def test_aggregate_multi_currency_primary_is_highest():
    """Primary currency — та, что вносит наибольшую сумму."""
    payments = [
        {"payment_date": date(2026, 6, 5), "amount": Decimal("10000"), "currency": "USD", "deal_id": 1, "company_name": "A", "owner_name": "X"},
        {"payment_date": date(2026, 6, 10), "amount": Decimal("500000"), "currency": "KZT", "deal_id": 2, "company_name": "B", "owner_name": "Y"},
    ]
    result = aggregate_awaiting_payment(payments, today=TODAY)
    assert result["primary_currency"] == "KZT"
    assert result["grand_total"] == 500000.0


def test_aggregate_none_amount_skipped():
    """Payment без amount не крашит и не вносит в total."""
    payments = [
        {"payment_date": date(2026, 6, 10), "amount": None, "currency": "KZT", "deal_id": 1, "company_name": "A", "owner_name": "X"},
    ]
    result = aggregate_awaiting_payment(payments, today=TODAY)
    # amount=None → Decimal(0), но currency будет зарегистрирована с 0
    assert result["grand_total"] == 0.0


def test_aggregate_invalid_date_skipped():
    """Payment с не-date payment_date пропускается без ошибки."""
    payments = [
        {"payment_date": "not-a-date", "amount": Decimal("1000"), "currency": "KZT", "deal_id": 1, "company_name": "A", "owner_name": "X"},
    ]
    result = aggregate_awaiting_payment(payments, today=TODAY)
    assert result["by_week"] == {}


# ─── compute_hot_forecast ─────────────────────────────────────────────────────

def test_hot_forecast_empty():
    result = compute_hot_forecast([], today=TODAY)
    assert result["count"] == 0
    assert result["total_pipeline"] == 0.0
    assert result["overdue_count"] == 0
    assert result["closing_this_week"] == 0
    assert result["avg_amount"] == 0.0


def test_hot_forecast_basic():
    deals = [
        {"deal_id": 1, "company_name": "A", "amount": Decimal("200000"), "currency": "KZT",
         "expected_close_date": date(2026, 6, 4), "owner_name": "Иванов", "days_to_close": 1},
        {"deal_id": 2, "company_name": "B", "amount": Decimal("300000"), "currency": "KZT",
         "expected_close_date": date(2026, 6, 2), "owner_name": "Петров", "days_to_close": -1},
        {"deal_id": 3, "company_name": "C", "amount": None, "currency": None,
         "expected_close_date": None, "owner_name": "Сидоров", "days_to_close": None},
    ]
    result = compute_hot_forecast(deals, today=TODAY)
    assert result["count"] == 3
    assert result["primary_currency"] == "KZT"
    assert result["total_pipeline"] == 500000.0
    assert result["overdue_count"] == 1        # days_to_close=-1
    assert result["closing_this_week"] == 1    # days_to_close=1
    assert result["avg_amount"] == 250000.0    # (200k + 300k) / 2


def test_hot_forecast_all_overdue():
    deals = [
        {"deal_id": i, "company_name": "X", "amount": Decimal("100"), "currency": "USD",
         "expected_close_date": date(2026, 5, 1), "owner_name": "X", "days_to_close": -10}
        for i in range(3)
    ]
    result = compute_hot_forecast(deals, today=TODAY)
    assert result["overdue_count"] == 3
    assert result["closing_this_week"] == 0


def test_hot_forecast_closing_this_week_boundary():
    """days_to_close=0 и 6 — входят в closing_this_week; 7 — нет."""
    deals = [
        {"deal_id": 1, "company_name": "A", "amount": Decimal("1"), "currency": "KZT", "expected_close_date": TODAY, "owner_name": "X", "days_to_close": 0},
        {"deal_id": 2, "company_name": "B", "amount": Decimal("1"), "currency": "KZT", "expected_close_date": TODAY, "owner_name": "X", "days_to_close": 6},
        {"deal_id": 3, "company_name": "C", "amount": Decimal("1"), "currency": "KZT", "expected_close_date": TODAY, "owner_name": "X", "days_to_close": 7},
    ]
    result = compute_hot_forecast(deals, today=TODAY)
    assert result["closing_this_week"] == 2


# ─── compute_paid_summary ─────────────────────────────────────────────────────

def test_paid_summary_empty():
    result = compute_paid_summary([])
    assert result["total_count"] == 0
    assert result["grand_total"] == 0.0
    assert result["primary_currency"] is None


def test_paid_summary_single():
    deals = [
        {
            "deal_id": 1,
            "company_name": "ТОО А",
            "contract_number": "ТШК-001",
            "first_payment_date": date(2026, 5, 10),
            "first_payment_amount": Decimal("150000"),
            "first_payment_currency": "KZT",
            "deal_amount": None,
            "currency": None,
        }
    ]
    result = compute_paid_summary(deals)
    assert result["total_count"] == 1
    assert result["primary_currency"] == "KZT"
    assert result["grand_total"] == 150000.0


def test_paid_summary_multi_currency():
    deals = [
        {"deal_id": 1, "company_name": "A", "contract_number": "001",
         "first_payment_date": date(2026, 5, 1), "first_payment_amount": Decimal("50000"),
         "first_payment_currency": "KZT", "deal_amount": None, "currency": None},
        {"deal_id": 2, "company_name": "B", "contract_number": "002",
         "first_payment_date": date(2026, 5, 5), "first_payment_amount": Decimal("2000"),
         "first_payment_currency": "USD", "deal_amount": None, "currency": None},
    ]
    result = compute_paid_summary(deals)
    assert result["total_count"] == 2
    assert result["total_amount_by_currency"]["KZT"] == 50000.0
    assert result["total_amount_by_currency"]["USD"] == 2000.0
    assert result["primary_currency"] == "KZT"  # KZT > USD


def test_paid_summary_fallback_to_deal_amount():
    """Если first_payment_amount=None — используем deal_amount."""
    deals = [
        {
            "deal_id": 1, "company_name": "X", "contract_number": "001",
            "first_payment_date": None, "first_payment_amount": None,
            "first_payment_currency": None, "deal_amount": Decimal("80000"), "currency": "KZT",
        }
    ]
    result = compute_paid_summary(deals)
    assert result["grand_total"] == 80000.0
    assert result["primary_currency"] == "KZT"
