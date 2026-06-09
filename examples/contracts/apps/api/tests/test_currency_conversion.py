"""Epic 10.5 — Currency conversion tests.

Pure-function tests — без DB fixture. Тестируем convert_amount.
Edge cases: нулевой курс, одинаковые валюты, округление.
"""
import pytest
from decimal import Decimal

from app.services.currency import convert_amount, SUPPORTED_CURRENCIES


# ============ convert_amount tests ============

def test_convert_same_currency():
    """Одинаковые валюты — сумма не меняется."""
    result = convert_amount(
        Decimal("1000.00"), "RUB", "RUB", Decimal("1.0")
    )
    assert result == Decimal("1000.00")


def test_convert_rub_to_uzs():
    """RUB → UZS по курсу 151."""
    result = convert_amount(
        Decimal("9820.00"), "RUB", "UZS", Decimal("151.0")
    )
    assert result == Decimal("1482820.00")


def test_convert_kzt_to_uzs():
    """KZT → UZS по курсу 26 (из PDF Ильи Рогова)."""
    result = convert_amount(
        Decimal("500000.00"), "KZT", "UZS", Decimal("26.0")
    )
    assert result == Decimal("13000000.00")


def test_convert_zero_amount():
    """Нулевая сумма остаётся нулём."""
    result = convert_amount(
        Decimal("0.00"), "USD", "RUB", Decimal("90.5")
    )
    assert result == Decimal("0.00")


def test_convert_zero_rate():
    """Нулевой курс — возвращает исходную сумму (защита от деления на 0)."""
    result = convert_amount(
        Decimal("1000.00"), "USD", "RUB", Decimal("0")
    )
    assert result == Decimal("1000.00")


def test_convert_fractional_result():
    """Результат с дробью округляется до 2 знаков."""
    # 100 USD × 90.333 = 9033.3 → 9033.30
    result = convert_amount(
        Decimal("100.00"), "USD", "RUB", Decimal("90.333")
    )
    assert result == Decimal("9033.30")


def test_convert_rounding_half_up():
    """Округление ROUND_HALF_UP: 1.005 → 1.01."""
    result = convert_amount(
        Decimal("1.00"), "USD", "RUB", Decimal("1.005")
    )
    assert result == Decimal("1.01")


def test_convert_large_amount():
    """Большая сумма — правильный расчёт."""
    result = convert_amount(
        Decimal("1000000.00"), "USD", "RUB", Decimal("90.00")
    )
    assert result == Decimal("90000000.00")


def test_convert_small_rate():
    """Маленький курс (USD → RUB)."""
    result = convert_amount(
        Decimal("1.00"), "RUB", "USD", Decimal("0.011")
    )
    assert result == Decimal("0.01")


def test_supported_currencies_list():
    """Проверить что список поддерживаемых валют полный."""
    expected = {"RUB", "USD", "EUR", "KZT", "UZS", "AED"}
    assert set(SUPPORTED_CURRENCIES) == expected


def test_convert_usd_to_aed():
    """USD → AED."""
    # 1 USD = 3.67 AED
    result = convert_amount(
        Decimal("100.00"), "USD", "AED", Decimal("3.67")
    )
    assert result == Decimal("367.00")
