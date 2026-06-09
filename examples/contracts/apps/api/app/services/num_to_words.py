"""Сумма прописью через num2words."""

from __future__ import annotations

from num2words import num2words


CURRENCY_FORMS = {
    "KZT": ("тенге", "тенге", "тенге"),
    "UZS": ("сум", "сума", "сумов"),
    "RUB": ("рубль", "рубля", "рублей"),
    "USD": ("доллар", "доллара", "долларов"),
    "EUR": ("евро", "евро", "евро"),
}


def amount_to_words_ru(amount: float | int | str, currency: str | None = None) -> str:
    """Перевод суммы в текст на русском. Если currency задан — приписывает название."""
    try:
        n = float(str(amount).replace(" ", "").replace(",", "."))
    except (TypeError, ValueError):
        return ""

    integer_part = int(n)
    fractional = int(round((n - integer_part) * 100))

    words = num2words(integer_part, lang="ru")
    result = words

    if currency and currency in CURRENCY_FORMS:
        forms = CURRENCY_FORMS[currency]
        result += f" {_plural_form(integer_part, forms)}"

    if fractional:
        result += f" {fractional:02d} коп." if currency in ("RUB", "KZT") else ""

    return result


def _plural_form(n: int, forms: tuple[str, str, str]) -> str:
    """Русские плюральные формы: 1 рубль, 2 рубля, 5 рублей."""
    n = abs(n) % 100
    if 11 <= n <= 19:
        return forms[2]
    n = n % 10
    if n == 1:
        return forms[0]
    if 2 <= n <= 4:
        return forms[1]
    return forms[2]
