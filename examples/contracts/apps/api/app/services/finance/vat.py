"""НДС: чистый валидатор Ф0 (J §5.4).

В Ф0 — только справочник ставок + валидатор net+vat=gross + флаг vat_enabled на
юрлице. Output/input-проводки (2310/1910) и книги покупок/продаж — Ф5.

Деньги — Decimal + ROUND_HALF_UP. Никакого float.
"""

from __future__ import annotations

from decimal import ROUND_HALF_UP, Decimal


def q2(value: Decimal | str | int) -> Decimal:
    """Округление денежной величины до копеек, ROUND_HALF_UP."""
    return Decimal(value).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def compute_vat(amount_net: Decimal | str, rate_pct: Decimal | str) -> Decimal:
    """Сумма НДС от net по ставке (%). vat = round(net * rate/100, HALF_UP)."""
    net = Decimal(amount_net)
    rate = Decimal(rate_pct)
    return q2(net * rate / Decimal("100"))


def validate_vat(
    amount_net: Decimal | str,
    vat_amount: Decimal | str,
    amount_gross: Decimal | str,
    rate_pct: Decimal | str,
) -> bool:
    """True ⇔ net+vat==gross И vat==round(net*rate,HALF_UP) (всё в копейках).

    Чистый предикат: вызывающий слой бросает 422 при False.
    """
    net = q2(amount_net)
    vat = q2(vat_amount)
    gross = q2(amount_gross)
    if q2(net + vat) != gross:
        return False
    if vat != compute_vat(net, rate_pct):
        return False
    return True
