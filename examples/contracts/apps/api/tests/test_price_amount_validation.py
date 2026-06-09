"""B6 — валидация денежных amount на входных схемах (pure pydantic, без БД).

ProductPriceIn.amount и DealProductIn.quantity/unit_price пишутся в Numeric(18,2):
запрещаем отрицательные, NaN/inf и переполнение колонки.
"""
from __future__ import annotations

import math

import pytest
from pydantic import ValidationError

from app.schemas import DealProductIn, ProductPriceIn


# ---- ProductPriceIn.amount ----

def test_product_price_accepts_valid_amount():
    p = ProductPriceIn(currency="KZT", amount=1000.5)
    assert p.amount == 1000.5


def test_product_price_zero_allowed():
    assert ProductPriceIn(currency="KZT", amount=0).amount == 0


def test_product_price_rejects_negative():
    with pytest.raises(ValidationError):
        ProductPriceIn(currency="KZT", amount=-1)


def test_product_price_rejects_inf():
    with pytest.raises(ValidationError):
        ProductPriceIn(currency="KZT", amount=math.inf)


def test_product_price_rejects_overflow():
    with pytest.raises(ValidationError):
        ProductPriceIn(currency="KZT", amount=1e18)


# ---- DealProductIn.quantity / unit_price ----

def test_deal_product_defaults_valid():
    dp = DealProductIn(product_id=1)
    assert dp.quantity == 1 and dp.unit_price is None


def test_deal_product_rejects_negative_quantity():
    with pytest.raises(ValidationError):
        DealProductIn(product_id=1, quantity=-2)


def test_deal_product_rejects_negative_unit_price():
    with pytest.raises(ValidationError):
        DealProductIn(product_id=1, unit_price=-0.01)


def test_deal_product_rejects_inf_unit_price():
    with pytest.raises(ValidationError):
        DealProductIn(product_id=1, unit_price=math.inf)
