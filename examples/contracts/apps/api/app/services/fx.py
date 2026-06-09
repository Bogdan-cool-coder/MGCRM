"""Курсы валют к рублю через ЦБ РФ (daily XML) с кешем в FxRate."""
from __future__ import annotations

import xml.etree.ElementTree as ET
from datetime import date
from decimal import Decimal

import httpx
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import FxRate

CBR_URL = "https://www.cbr.ru/scripts/XML_daily.asp"


async def _fetch_cbr(session: AsyncSession, on_date: date) -> dict[str, Decimal]:
    """Тянет курсы ЦБ РФ на дату, кеширует (best-effort) и возвращает {CharCode: rate_to_rub}."""
    params = {"date_req": on_date.strftime("%d/%m/%Y")}
    async with httpx.AsyncClient(timeout=15) as client:
        resp = await client.get(CBR_URL, params=params)
        resp.raise_for_status()
    root = ET.fromstring(resp.content)
    rates: dict[str, Decimal] = {}
    for val in root.findall("Valute"):
        code = (val.findtext("CharCode") or "").upper()
        try:
            nominal = Decimal((val.findtext("Nominal") or "1").replace(",", "."))
            value = Decimal((val.findtext("Value") or "0").replace(",", "."))
        except Exception:  # noqa: BLE001
            continue
        if code and nominal:
            rates[code] = (value / nominal).quantize(Decimal("0.000001"))
    # Кеш — best-effort: даже если запись не удалась (гонка), курс уже посчитан in-memory
    try:
        existing = set((
            await session.execute(select(FxRate.currency).where(FxRate.rate_date == on_date))
        ).scalars().all())
        for code, rate in rates.items():
            if code not in existing:
                session.add(FxRate(rate_date=on_date, currency=code, rate_to_rub=rate))
        await session.commit()
    except Exception:  # noqa: BLE001
        await session.rollback()
    return rates


async def get_rate_to_rub(session: AsyncSession, currency: str, on_date: date) -> Decimal | None:
    """1 ед. `currency` = ? ₽ на дату. RUB→1. Кеш → ЦБ РФ → ближайший прошлый курс."""
    currency = (currency or "").upper()
    if currency == "RUB":
        return Decimal("1")
    if not currency:
        return None
    row = (
        await session.execute(
            select(FxRate).where(FxRate.rate_date == on_date, FxRate.currency == currency)
        )
    ).scalar_one_or_none()
    if row:
        return row.rate_to_rub
    try:
        rates = await _fetch_cbr(session, on_date)
        if currency in rates:
            return rates[currency]
    except Exception:  # noqa: BLE001
        pass
    # Фолбэк: ближайший прошлый закешированный курс
    fallback = (
        await session.execute(
            select(FxRate)
            .where(FxRate.currency == currency, FxRate.rate_date <= on_date)
            .order_by(FxRate.rate_date.desc())
            .limit(1)
        )
    ).scalar_one_or_none()
    return fallback.rate_to_rub if fallback else None
