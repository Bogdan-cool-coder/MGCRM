"""Чистая логика прайса: расчёт итога/скидки и форматирование денег (без БД)."""

from decimal import Decimal

from app.services.pricing import compute_totals, format_money, q2


def test_compute_totals_no_discount():
    subtotal, discount, total = compute_totals([Decimal("100"), Decimal("200")], Decimal("0"))
    assert (str(subtotal), str(discount), str(total)) == ("300.00", "0.00", "300.00")


def test_compute_totals_with_discount():
    subtotal, discount, total = compute_totals([Decimal("100"), Decimal("50.50")], Decimal("10"))
    assert str(subtotal) == "150.50"
    assert str(discount) == "15.05"
    assert str(total) == "135.45"


def test_compute_totals_empty():
    subtotal, discount, total = compute_totals([], Decimal("25"))
    assert (str(subtotal), str(discount), str(total)) == ("0.00", "0.00", "0.00")


def test_q2_rounds_half_up():
    assert str(q2("2.345")) == "2.35"
    assert str(q2(Decimal("1") / Decimal("3"))) == "0.33"


def test_format_money_groups_thousands():
    # разделитель тысяч — неразрывный пробел; нормализуем для устойчивости теста
    assert format_money(2500000).replace(" ", " ") == "2 500 000"
    assert format_money(0) == "0"


def test_format_money_keeps_cents():
    assert format_money(Decimal("113.50")) == "113.50"
