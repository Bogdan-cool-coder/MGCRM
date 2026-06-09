"""Epic 10.5 — Currency service: курсы валют и конвертация.

Поддерживаемые валюты: RUB, USD, EUR, KZT, UZS, AED.

Архитектурные решения:
- fetch_rates_from_api() — httpx call к exchangerate-api.com/v6/{KEY}/latest/USD.
  Если ключ пуст или запрос упал → возвращает {} (graceful, без Exception наружу).
- update_currency_rates(session) — upsert в currency_rates (insert-missing pattern).
  Безопасен для повторного вызова — ON CONFLICT DO UPDATE.
- get_rate(session, from_cur, to_cur, on_date) — lookup в БД, fallback 1.0.
- convert_amount(amount, from_cur, to_cur, rate) — pure-функция без БД.

Cron: вызывается раз в сутки из jobs/automation_cron.py (или напрямую из
POST /api/admin/currency-rates/refresh для форс-обновления).
"""
from __future__ import annotations

import logging
from dataclasses import dataclass
from datetime import date
from decimal import ROUND_HALF_UP, Decimal
from typing import Any

import httpx
from sqlalchemy import select, text
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncSession

from app.config import get_settings
from app.models import CurrencyRate

logger = logging.getLogger(__name__)

# Поддерживаемые валюты (whitelist для seed + конвертации)
SUPPORTED_CURRENCIES: tuple[str, ...] = ("RUB", "USD", "EUR", "KZT", "UZS", "AED")

# Exchangerate-API endpoint (v6 free tier — latest по USD-базе)
_EXCHANGE_API_BASE = "https://v6.exchangerate-api.com/v6/{key}/latest/USD"


# ============ Результат авто-обновления (явный, без «тихого нуля») ============

#: Машиночитаемые причины, по которым авто-fetch не дал курсов. UI маппит на RU-сообщение.
FETCH_REASON_NO_KEY = "no_api_key"
FETCH_REASON_API_ERROR = "api_error"
FETCH_REASON_EMPTY = "empty_response"
FETCH_REASON_OK = "ok"

#: RU-сообщения для UI по машинной причине.
FETCH_REASON_MESSAGES: dict[str, str] = {
    FETCH_REASON_NO_KEY: (
        "Автозагрузка недоступна: не настроен EXCHANGE_RATE_API_KEY. "
        "Введите курсы вручную или попросите администратора задать ключ."
    ),
    FETCH_REASON_API_ERROR: (
        "Сервис курсов недоступен или вернул ошибку. Попробуйте позже "
        "или введите курсы вручную."
    ),
    FETCH_REASON_EMPTY: (
        "Сервис курсов вернул пустой ответ. Попробуйте позже "
        "или введите курсы вручную."
    ),
    FETCH_REASON_OK: "Курсы успешно загружены.",
}


@dataclass(frozen=True)
class FetchResult:
    """Явный результат скачивания курсов с внешнего API.

    `ok` True ⇒ rates_map содержит пары. Иначе `reason` объясняет ПОЧЕМУ пусто
    (нет ключа / ошибка API / пустой ответ) — вместо тихого {}.
    """

    rates_map: dict[str, dict[str, float]]
    ok: bool
    reason: str


def reason_message(reason: str) -> str:
    """RU-сообщение по машинной причине fetch'а (pure). Неизвестная → дефолт."""
    return FETCH_REASON_MESSAGES.get(reason, "Не удалось загрузить курсы валют.")


# ============ API fetch ============

async def fetch_rates_from_api() -> FetchResult:
    """Скачать актуальные курсы с exchangerate-api.com.

    Returns:
        FetchResult(rates_map, ok, reason). rates_map — {from: {to: rate}} матрица
        по поддерживаемым валютам через USD-базу. При пустом ключе или ошибке —
        ok=False + машинная reason (no_api_key / api_error / empty_response),
        вместо тихого {} (вызывающий может сообщить пользователю ПОЧЕМУ).
    """
    settings = get_settings()
    api_key = settings.exchange_rate_api_key if hasattr(settings, "exchange_rate_api_key") else ""
    if not api_key:
        logger.info("EXCHANGE_RATE_API_KEY not set — skipping rate fetch")
        return FetchResult({}, ok=False, reason=FETCH_REASON_NO_KEY)

    url = _EXCHANGE_API_BASE.format(key=api_key)
    try:
        async with httpx.AsyncClient(timeout=10.0) as client:
            resp = await client.get(url)
            resp.raise_for_status()
            data: dict[str, Any] = resp.json()
    except Exception as e:  # noqa: BLE001
        logger.warning("Failed to fetch currency rates from API: %s", e)
        return FetchResult({}, ok=False, reason=FETCH_REASON_API_ERROR)

    if data.get("result") != "success":
        logger.warning(
            "Exchange API returned non-success: %s", data.get("result")
        )
        return FetchResult({}, ok=False, reason=FETCH_REASON_API_ERROR)

    usd_rates: dict[str, float] = data.get("conversion_rates", {})
    if not usd_rates:
        return FetchResult({}, ok=False, reason=FETCH_REASON_EMPTY)

    # Строим полную матрицу для поддерживаемых валют через USD как базу
    result: dict[str, dict[str, float]] = {}
    for from_cur in SUPPORTED_CURRENCIES:
        usd_to_from = usd_rates.get(from_cur, 0.0)
        if usd_to_from == 0.0:
            continue
        result[from_cur] = {}
        for to_cur in SUPPORTED_CURRENCIES:
            if from_cur == to_cur:
                result[from_cur][to_cur] = 1.0
                continue
            usd_to_to = usd_rates.get(to_cur, 0.0)
            if usd_to_to == 0.0:
                continue
            # from → to = (1/usd_to_from) * usd_to_to
            cross = usd_to_to / usd_to_from
            result[from_cur][to_cur] = cross

    if not result:
        return FetchResult({}, ok=False, reason=FETCH_REASON_EMPTY)
    return FetchResult(result, ok=True, reason=FETCH_REASON_OK)


# ============ DB upsert ============

@dataclass(frozen=True)
class UpdateResult:
    """Явный результат update_currency_rates: сколько обновлено + почему, если 0."""

    updated: int
    ok: bool
    reason: str


async def update_currency_rates(session: AsyncSession) -> UpdateResult:
    """Скачать курсы и upsert в currency_rates.

    Returns:
        UpdateResult(updated, ok, reason). ok=False + машинная reason, если fetch не дал
        пар (нет ключа / ошибка API / пустой ответ) — НЕ тихий 0. Idempotent:
        ON CONFLICT (from, to, date) DO UPDATE rate, source ('exchangerate-api').
    """
    fetched = await fetch_rates_from_api()
    if not fetched.ok or not fetched.rates_map:
        logger.info("No rates fetched (%s) — skipping DB update", fetched.reason)
        return UpdateResult(updated=0, ok=False, reason=fetched.reason)
    rates_map = fetched.rates_map

    today = date.today()
    rows: list[dict[str, Any]] = []
    for from_cur, targets in rates_map.items():
        for to_cur, rate in targets.items():
            rows.append({
                "from_currency": from_cur,
                "to_currency": to_cur,
                "rate": Decimal(str(round(rate, 8))),
                "rate_date": today,
                "source": "exchangerate-api",
            })

    if not rows:
        return UpdateResult(updated=0, ok=False, reason=FETCH_REASON_EMPTY)

    stmt = pg_insert(CurrencyRate).values(rows)
    stmt = stmt.on_conflict_do_update(
        constraint="uq_currency_rate_from_to_date",
        set_={
            "rate": stmt.excluded.rate,
            "source": stmt.excluded.source,
        },
    )
    await session.execute(stmt)
    await session.commit()
    logger.info("Updated %d currency rate pairs for %s", len(rows), today)
    return UpdateResult(updated=len(rows), ok=True, reason=FETCH_REASON_OK)


# ============ Lookup ============

async def get_rate(
    session: AsyncSession,
    from_cur: str,
    to_cur: str,
    on_date: date,
) -> Decimal:
    """Получить курс from_cur → to_cur на дату on_date (или ближайшую до неё).

    Fallback: если записей нет — возвращает Decimal("1.0") с предупреждением.
    """
    if from_cur == to_cur:
        return Decimal("1.0")

    row = (
        await session.execute(
            select(CurrencyRate)
            .where(
                CurrencyRate.from_currency == from_cur,
                CurrencyRate.to_currency == to_cur,
                CurrencyRate.rate_date <= on_date,
            )
            .order_by(CurrencyRate.rate_date.desc())
            .limit(1)
        )
    ).scalar_one_or_none()

    if row is None:
        logger.warning(
            "No currency rate found for %s→%s on %s, using fallback 1.0",
            from_cur,
            to_cur,
            on_date,
        )
        return Decimal("1.0")

    return row.rate


async def get_rates_snapshot(
    session: AsyncSession,
    currencies: list[str],
    target_currency: str,
    on_date: date,
) -> dict[str, Decimal]:
    """Получить словарь {currency → rate_to_target} для набора валют на дату.

    Используется при финализации МК для exchange_rates_snapshot.
    """
    result: dict[str, Decimal] = {}
    for cur in currencies:
        result[cur] = await get_rate(session, cur, target_currency, on_date)
    return result


# ============ Pure conversion ============

def convert_amount(
    amount: Decimal,
    from_cur: str,
    to_cur: str,
    rate: Decimal,
) -> Decimal:
    """Конвертировать сумму по заданному курсу. Pure-функция, без БД.

    Args:
        amount: исходная сумма
        from_cur: исходная валюта
        to_cur: целевая валюта
        rate: курс from_cur → to_cur (сколько to_cur в 1 from_cur)

    Returns:
        Конвертированная сумма с 2 знаками после запятой.
    """
    if from_cur == to_cur or rate == Decimal("0"):
        return amount.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)
    return (amount * rate).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


async def convert_amount_db(
    session: AsyncSession,
    amount: Decimal,
    from_cur: str,
    to_cur: str,
    on_date: date,
) -> Decimal:
    """Конвертировать сумму через БД курс на дату. Комбинирует get_rate + convert_amount."""
    if from_cur == to_cur:
        return amount.quantize(Decimal("0.01"))
    rate = await get_rate(session, from_cur, to_cur, on_date)
    return convert_amount(amount, from_cur, to_cur, rate)


# ============ Seed helper ============

async def seed_currency_rates(session: AsyncSession) -> None:
    """Seed начальных курсов при пустой таблице. Advisory lock key: 470.

    Создаёт дефолтные записи с rate=1.0 (placeholder) для всех поддерживаемых
    пар. В проде перезаписывается при первом fetch_rates_from_api.
    """
    async with session.begin_nested():
        await session.execute(text("SELECT pg_advisory_xact_lock(470)"))

        existing_count = (
            await session.execute(
                select(CurrencyRate).limit(1)
            )
        ).scalar_one_or_none()

        if existing_count is not None:
            return  # уже есть данные

        today = date.today()
        rows: list[dict[str, Any]] = []
        for from_cur in SUPPORTED_CURRENCIES:
            for to_cur in SUPPORTED_CURRENCIES:
                if from_cur == to_cur:
                    continue
                rows.append({
                    "from_currency": from_cur,
                    "to_currency": to_cur,
                    "rate": Decimal("1.0"),
                    "rate_date": today,
                    "source": "seed_placeholder",
                })

        if rows:
            stmt = pg_insert(CurrencyRate).values(rows)
            stmt = stmt.on_conflict_do_nothing(
                constraint="uq_currency_rate_from_to_date"
            )
            await session.execute(stmt)
            logger.info(
                "Seeded %d placeholder currency rate pairs", len(rows)
            )
