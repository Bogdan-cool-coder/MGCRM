"""Ф0 ЧАНК 1 — НДС-валидатор (pure, ROUND_HALF_UP)."""

from __future__ import annotations

from decimal import Decimal

from app.services.finance.vat import compute_vat, validate_vat


def test_compute_vat_12pct():
    assert compute_vat("1000.00", "12.00") == Decimal("120.00")


def test_compute_vat_rounds_half_up():
    # 1234.55 * 12% = 148.146 → 148.15 (HALF_UP)
    assert compute_vat("1234.55", "12") == Decimal("148.15")


def test_compute_vat_zero_rate():
    assert compute_vat("5000", "0") == Decimal("0.00")


def test_validate_vat_ok():
    assert validate_vat("1000.00", "120.00", "1120.00", "12.00") is True


def test_validate_vat_gross_mismatch():
    # net+vat != gross
    assert validate_vat("1000.00", "120.00", "1100.00", "12.00") is False


def test_validate_vat_wrong_vat_amount():
    # gross сходится, но vat != round(net*rate)
    assert validate_vat("1000.00", "100.00", "1100.00", "12.00") is False


def test_validate_vat_no_vat_regime():
    # «Без НДС»: rate 0, vat 0, net==gross.
    assert validate_vat("999.99", "0.00", "999.99", "0.00") is True


def test_validate_vat_rounding_edge():
    net = "1234.55"
    vat = str(compute_vat(net, "12"))  # 148.15
    gross = str(Decimal(net) + Decimal(vat))  # 1382.70
    assert validate_vat(net, vat, gross, "12") is True
