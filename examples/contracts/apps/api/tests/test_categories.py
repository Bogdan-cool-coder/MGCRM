"""Логика категорий клиентов (чистая, без БД)."""

from decimal import Decimal

from app.services.categories import match_category, seed_categories_payload


def _bands():
    return [
        (c["code"], Decimal(str(c["min_amount"])), Decimal(str(c["max_amount"])) if c["max_amount"] is not None else None)
        for c in seed_categories_payload()
    ]


def test_match_category_bands():
    b = _bands()
    assert match_category(Decimal("5000000"), b) == "L"        # ≥ 2M
    assert match_category(Decimal("2000000"), b) == "L"        # граница L включительно
    assert match_category(Decimal("1999999"), b) == "M"        # < 2M
    assert match_category(Decimal("500000"), b) == "M"         # граница M
    assert match_category(Decimal("499999"), b) == "S1"        # < 500K
    assert match_category(Decimal("300000"), b) == "S1"
    assert match_category(Decimal("299999"), b) == "S2"
    assert match_category(Decimal("0"), b) == "S2"


def test_seed_payload_bands_are_contiguous():
    # L/M/S1/S2 — без пробелов и пересечений от 0 до ∞
    cats = {c["code"]: c for c in seed_categories_payload()}
    assert cats["S2"]["min_amount"] == 0
    assert cats["S2"]["max_amount"] == cats["S1"]["min_amount"] == 300_000
    assert cats["S1"]["max_amount"] == cats["M"]["min_amount"] == 500_000
    assert cats["M"]["max_amount"] == cats["L"]["min_amount"] == 2_000_000
    assert cats["L"]["max_amount"] is None  # верхней границы нет
