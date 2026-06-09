"""Epic 14.2 — Production calendar для RU/KZ/UZ/AE.

Используется для расчёта `due_at + N рабочих дней`: учитывает выходные
(Сб/Вс) + государственные праздники конкретной страны.

Праздники хранятся в БД (таблица production_calendar). Сидер для 2026 года
жёстко прописан в DEFAULT_HOLIDAYS_2026 — заливается через
POST /api/admin/production-calendar/seed?country_code=&year=.

Чистые функции тут (для unit-тестов pure-function):
- next_working_day(d, holidays_set) → date
- add_working_days(start, n, holidays_set) → date
- is_working_day(d, holidays_set) → bool

DB-функции (нечистые):
- get_holidays(session, country_code, year) → list[ProductionCalendarDay]
- get_holidays_set(session, country_code, year_range) → set[date]
- seed_default(session, country_code, year) → int (inserted count)
"""
from __future__ import annotations

from datetime import date, timedelta
from typing import Iterable

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import ProductionCalendarDay

# ============ Pure functions (для тестов pure-function) ============


def is_working_day(d: date, holidays: Iterable[date]) -> bool:
    """День рабочий: не Сб (5), не Вс (6) и не входит в праздники."""
    # weekday(): Mon=0..Sun=6
    if d.weekday() >= 5:
        return False
    holidays_set = holidays if isinstance(holidays, (set, frozenset)) else set(holidays)
    return d not in holidays_set


def next_working_day(d: date, holidays: Iterable[date]) -> date:
    """Следующий рабочий день после d (не включая d). Скипает выходные + праздники."""
    holidays_set = holidays if isinstance(holidays, (set, frozenset)) else set(holidays)
    cur = d + timedelta(days=1)
    while not is_working_day(cur, holidays_set):
        cur += timedelta(days=1)
    return cur


def add_working_days(start: date, n_days: int, holidays: Iterable[date]) -> date:
    """Прибавить N рабочих дней к start.

    - n_days=0 → возвращает start, если start — рабочий; иначе следующий рабочий.
    - n_days>0 → start + n рабочих (с учётом праздников).
    - n_days<0 → вычитание; ищем рабочий день N назад.

    Не включает сам start в счёт (если start — рабочий и n=1, вернётся
    следующий рабочий). Это match'ит ожидание «через N рабочих дней» в UX.
    """
    holidays_set = holidays if isinstance(holidays, (set, frozenset)) else set(holidays)
    if n_days == 0:
        # 0 рабочих дней = «сейчас», но клиент ожидает рабочий день.
        if is_working_day(start, holidays_set):
            return start
        return next_working_day(start, holidays_set)

    cur = start
    step = 1 if n_days > 0 else -1
    remaining = abs(n_days)
    while remaining > 0:
        cur = cur + timedelta(days=step)
        if is_working_day(cur, holidays_set):
            remaining -= 1
    return cur


# ============ DB-функции ============


async def get_holidays(
    session: AsyncSession, country_code: str, year: int,
) -> list[ProductionCalendarDay]:
    """Праздники / сокращённые дни конкретной страны на год."""
    return list(
        (
            await session.execute(
                select(ProductionCalendarDay)
                .where(
                    ProductionCalendarDay.country_code == country_code.upper(),
                    ProductionCalendarDay.year == year,
                )
                .order_by(ProductionCalendarDay.date)
            )
        )
        .scalars()
        .all()
    )


async def get_holidays_set(
    session: AsyncSession, country_code: str, years: Iterable[int],
) -> set[date]:
    """Множество праздничных дат для нескольких лет (для add_working_days).

    Берёт только is_holiday=true (сокращённые дни не скипаются).
    """
    years_list = list(years)
    if not years_list:
        return set()
    rows = (
        await session.execute(
            select(ProductionCalendarDay.date)
            .where(
                ProductionCalendarDay.country_code == country_code.upper(),
                ProductionCalendarDay.year.in_(years_list),
                ProductionCalendarDay.is_holiday.is_(True),
            )
        )
    ).scalars().all()
    return set(rows)


async def add_working_days_db(
    session: AsyncSession,
    start: date,
    n_days: int,
    country_code: str,
) -> date:
    """Обёртка над add_working_days, подтягивающая праздники из БД.

    Берёт праздники на год(а), которые могут понадобиться (start.year и
    start.year ± 1 — на случай переходов через Новый Год при больших N).
    """
    years = {start.year, start.year + 1, start.year - 1}
    holidays = await get_holidays_set(session, country_code, years)
    return add_working_days(start, n_days, holidays)


# ============ Seed: жёстко прописанные праздники 2026 ============


# Подмножество главных гос. праздников. Сокращённые дни помечаем
# is_short_day=True. Если ровно праздник + короткий день одновременно —
# is_holiday приоритет (короткий день не имеет смысла на выходной).
#
# Источник:
# - RU: ПП 1402 + ТК РФ ст.112 (Новый Год, Рождество, 23 фев, 8 мар, 1 мая,
#   9 мая, 12 июня, 4 ноября + переносы)
# - KZ: Закон о праздниках; Наурыз (21-23 мар), День Защитника (7 мая),
#   День Конституции (30 авг), День Республики (25 окт)
# - UZ: новогодние (1-2 янв), Навруз (21 мар), День Защитников (14 янв),
#   8 марта, Хайит (Ramazan/Курбан-Хайит — даты подвижные, берём 2026 года)
# - AE: новогодний (1 янв), Eid al-Fitr / Eid al-Adha (даты подвижные),
#   National Day (2 дек)
DEFAULT_HOLIDAYS_2026: dict[str, list[tuple[date, bool, str]]] = {
    # tuple: (date, is_short_day, name)
    "RU": [
        (date(2026, 1, 1), False, "Новогодние каникулы"),
        (date(2026, 1, 2), False, "Новогодние каникулы"),
        (date(2026, 1, 5), False, "Новогодние каникулы"),
        (date(2026, 1, 6), False, "Новогодние каникулы"),
        (date(2026, 1, 7), False, "Рождество Христово"),
        (date(2026, 1, 8), False, "Новогодние каникулы"),
        (date(2026, 2, 23), False, "День защитника Отечества"),
        (date(2026, 3, 9), False, "Перенос с 8 марта"),
        (date(2026, 5, 1), False, "Праздник Весны и Труда"),
        (date(2026, 5, 11), False, "Перенос с 9 мая"),
        (date(2026, 6, 12), False, "День России"),
        (date(2026, 11, 4), False, "День народного единства"),
    ],
    "KZ": [
        (date(2026, 1, 1), False, "Новый год"),
        (date(2026, 1, 2), False, "Новый год"),
        (date(2026, 3, 8), False, "Международный женский день"),
        (date(2026, 3, 21), False, "Наурыз мейрамы"),
        (date(2026, 3, 22), False, "Наурыз мейрамы"),
        (date(2026, 3, 23), False, "Наурыз мейрамы"),
        (date(2026, 5, 1), False, "Праздник единства народа Казахстана"),
        (date(2026, 5, 7), False, "День защитника Отечества"),
        (date(2026, 5, 9), False, "День Победы"),
        (date(2026, 7, 6), False, "День Столицы"),
        (date(2026, 8, 30), False, "День Конституции"),
        (date(2026, 10, 25), False, "День Республики"),
        (date(2026, 12, 16), False, "День Независимости"),
    ],
    "UZ": [
        (date(2026, 1, 1), False, "Новый год"),
        (date(2026, 1, 14), False, "День Защитников Родины"),
        (date(2026, 3, 8), False, "Международный женский день"),
        (date(2026, 3, 21), False, "Навруз"),
        (date(2026, 5, 9), False, "День Памяти и Почестей"),
        # Ramadan Hayit / Eid al-Fitr 2026 — приблизительная дата.
        (date(2026, 3, 20), False, "Рамадан Хайит"),
        # Курбан Хайит / Eid al-Adha 2026 — приблизительная дата.
        (date(2026, 5, 27), False, "Курбан Хайит"),
        (date(2026, 9, 1), False, "День Независимости"),
        (date(2026, 10, 1), False, "День Учителей и Наставников"),
        (date(2026, 12, 8), False, "День Конституции"),
    ],
    "AE": [
        (date(2026, 1, 1), False, "New Year's Day"),
        # Eid al-Fitr 2026 — ориентировочно 19-22 марта.
        (date(2026, 3, 20), False, "Eid al-Fitr"),
        (date(2026, 3, 21), False, "Eid al-Fitr"),
        (date(2026, 3, 22), False, "Eid al-Fitr"),
        # Eid al-Adha 2026 — ориентировочно 26-29 мая.
        (date(2026, 5, 26), False, "Arafat Day"),
        (date(2026, 5, 27), False, "Eid al-Adha"),
        (date(2026, 5, 28), False, "Eid al-Adha"),
        (date(2026, 5, 29), False, "Eid al-Adha"),
        # Islamic New Year — ориентировочно июнь.
        (date(2026, 6, 17), False, "Islamic New Year"),
        # Prophet Muhammad's Birthday — ориентировочно август.
        (date(2026, 8, 26), False, "Prophet Muhammad's Birthday"),
        (date(2026, 12, 1), False, "Commemoration Day"),
        (date(2026, 12, 2), False, "National Day"),
        (date(2026, 12, 3), False, "National Day"),
    ],
}


def get_default_holidays(country_code: str, year: int) -> list[tuple[date, bool, str]]:
    """Возвращает список (date, is_short_day, name) для country/year.

    Сейчас данные есть только за 2026. На другие годы — пустой список.
    """
    if year != 2026:
        return []
    return DEFAULT_HOLIDAYS_2026.get(country_code.upper(), [])


async def seed_default(
    session: AsyncSession, country_code: str, year: int,
) -> int:
    """Insert-missing сид праздников для country/year. Возвращает count новых.

    Идемпотентно: при повторном вызове ничего не вставит (UNIQUE по
    country_code+date).
    """
    rows = get_default_holidays(country_code, year)
    if not rows:
        return 0

    # Что уже есть в БД на этот country+year.
    existing_dates = set(
        (
            await session.execute(
                select(ProductionCalendarDay.date).where(
                    ProductionCalendarDay.country_code == country_code.upper(),
                    ProductionCalendarDay.year == year,
                )
            )
        ).scalars().all()
    )

    inserted = 0
    for d, is_short, name in rows:
        if d in existing_dates:
            continue
        session.add(
            ProductionCalendarDay(
                country_code=country_code.upper(),
                year=year,
                date=d,
                is_holiday=not is_short,  # если короткий день — это не выходной
                is_short_day=is_short,
                name=name,
            )
        )
        inserted += 1

    if inserted:
        await session.commit()
    return inserted
