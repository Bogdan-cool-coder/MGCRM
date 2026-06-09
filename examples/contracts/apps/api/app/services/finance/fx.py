"""Курс валют — СТРОГИЙ (Ф0, инвариант №6).

Финмодуль НИКОГДА не использует `services/currency.py::get_rate` — он молча
возвращает 1.0 при отсутствии курса, что в учёте недопустимо (тихо ломает баланс
amount_func и amount_in_base). Здесь — `get_rate_strict`, который БРОСАЕТ
`FxRateMissing`, и конвертеры в функциональную/базовую валюту.

Деньги — Decimal + ROUND_HALF_UP. Курсы — Numeric(20,8) из `currency_rates`.
"""

from __future__ import annotations

from datetime import date
from decimal import ROUND_HALF_UP, Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import CurrencyRate


class FxRateMissing(Exception):
    """Курс не найден на дату — финоперация не может быть проведена (→ 422).

    Несёт пару валют и дату для UX-сообщения «введите курс KZT→RUB на 2026-05-31».
    """

    def __init__(self, from_ccy: str, to_ccy: str, on_date: date) -> None:
        self.from_ccy = from_ccy
        self.to_ccy = to_ccy
        self.on_date = on_date
        super().__init__(
            f"Не найден курс {from_ccy}→{to_ccy} на {on_date.isoformat()}. "
            f"Введите курс в справочнике валют, затем повторите проведение."
        )


def _q2(value: Decimal) -> Decimal:
    return value.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


async def get_rate_strict(
    session: AsyncSession,
    from_ccy: str,
    to_ccy: str,
    on_date: date,
) -> Decimal:
    """Курс from→to на дату (или ближайшую ДО неё). БРОСАЕТ FxRateMissing если нет.

    В отличие от services/currency.get_rate — никакого тихого фоллбэка 1.0.
    Тождественная пара (from==to) → 1, без обращения к БД (это не «отсутствие курса»).
    """
    if from_ccy == to_ccy:
        return Decimal("1")

    rate = (
        await session.execute(
            select(CurrencyRate.rate)
            .where(
                CurrencyRate.from_currency == from_ccy,
                CurrencyRate.to_currency == to_ccy,
                CurrencyRate.rate_date <= on_date,
            )
            .order_by(CurrencyRate.rate_date.desc())
            .limit(1)
        )
    ).scalar_one_or_none()

    if rate is None:
        raise FxRateMissing(from_ccy, to_ccy, on_date)
    return rate


def convert_strict(amount: Decimal, rate: Decimal) -> Decimal:
    """Сумма * курс → 2 знака, ROUND_HALF_UP. Pure. Курс уже получен get_rate_strict.

    Знак суммы сохраняется (Дт>0 / Кт<0 переживают конвертацию).
    """
    return _q2(amount * rate)


async def to_functional(
    session: AsyncSession,
    amount: Decimal,
    from_ccy: str,
    func_ccy: str,
    on_date: date,
) -> tuple[Decimal, Decimal]:
    """Сумма в функциональную валюту юрлица. Возвращает (amount_func, fx_rate).

    Строгий: при отсутствии курса бросает FxRateMissing (проводка не проведётся).
    """
    rate = await get_rate_strict(session, from_ccy, func_ccy, on_date)
    return convert_strict(amount, rate), rate


async def to_base(
    session: AsyncSession,
    amount: Decimal,
    from_ccy: str,
    base_ccy: str,
    on_date: date,
) -> tuple[Decimal | None, Decimal | None, bool]:
    """Сумма в базовую валюту группы. Возвращает (amount_in_base, fx_rate, fx_missing).

    amount_in_base — ПРОЕКЦИЯ (инвариант №2: построчно Σ=0 НЕ требуется). Поэтому,
    в отличие от amount_func, отсутствие курса НЕ бросает: помечаем fx_missing=True,
    amount_in_base=None — отчёт в базе покажет пробел, проводка всё равно проводится
    (баланс держится в func-валюте). Это согласовано с моделью (fx_missing-флаг).
    """
    if from_ccy == base_ccy:
        return convert_strict(amount, Decimal("1")), Decimal("1"), False
    try:
        rate = await get_rate_strict(session, from_ccy, base_ccy, on_date)
    except FxRateMissing:
        return None, None, True
    return convert_strict(amount, rate), rate, False
