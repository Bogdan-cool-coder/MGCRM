"""Epic 14.2 — Production calendar pure-function tests."""
from __future__ import annotations

from datetime import date

from app.services.production_calendar import (
    DEFAULT_HOLIDAYS_2026,
    add_working_days,
    get_default_holidays,
    is_working_day,
    next_working_day,
)


# ============ is_working_day ============


def test_is_working_day_weekday_no_holidays():
    # Понедельник 2026-06-01 — рабочий.
    assert is_working_day(date(2026, 6, 1), set()) is True


def test_is_working_day_saturday():
    # Суббота 2026-06-06 — не рабочий.
    assert is_working_day(date(2026, 6, 6), set()) is False


def test_is_working_day_sunday():
    # Воскресенье 2026-06-07 — не рабочий.
    assert is_working_day(date(2026, 6, 7), set()) is False


def test_is_working_day_holiday_overrides():
    # Понедельник, который объявлен праздником — не рабочий.
    d = date(2026, 6, 1)
    holidays = {d}
    assert is_working_day(d, holidays) is False


def test_is_working_day_accepts_list_as_holidays():
    # Должно работать на list/set/iterable.
    d = date(2026, 6, 1)
    assert is_working_day(d, [d]) is False
    assert is_working_day(d, [date(2026, 1, 1)]) is True


# ============ next_working_day ============


def test_next_working_day_simple():
    # После понедельника → вторник.
    assert next_working_day(date(2026, 6, 1), set()) == date(2026, 6, 2)


def test_next_working_day_skips_weekend():
    # После пятницы 2026-06-05 → понедельник 2026-06-08.
    assert next_working_day(date(2026, 6, 5), set()) == date(2026, 6, 8)


def test_next_working_day_skips_holiday():
    # После 2026-06-01, если 2026-06-02 — праздник → 2026-06-03.
    holidays = {date(2026, 6, 2)}
    assert next_working_day(date(2026, 6, 1), holidays) == date(2026, 6, 3)


def test_next_working_day_skips_multiple_holidays_and_weekend():
    # После пятницы 2026-06-05, если понедельник 2026-06-08 праздник →
    # вторник 2026-06-09.
    holidays = {date(2026, 6, 8)}
    assert next_working_day(date(2026, 6, 5), holidays) == date(2026, 6, 9)


# ============ add_working_days ============


def test_add_working_days_zero_on_working_day():
    # 0 рабочих дней + рабочий день = тот же день.
    assert add_working_days(date(2026, 6, 1), 0, set()) == date(2026, 6, 1)


def test_add_working_days_zero_on_weekend():
    # 0 рабочих дней + суббота = ближайший понедельник.
    assert add_working_days(date(2026, 6, 6), 0, set()) == date(2026, 6, 8)


def test_add_working_days_one_simple():
    # Пн + 1 = Вт.
    assert add_working_days(date(2026, 6, 1), 1, set()) == date(2026, 6, 2)


def test_add_working_days_crosses_weekend():
    # Пт + 1 = Пн.
    assert add_working_days(date(2026, 6, 5), 1, set()) == date(2026, 6, 8)


def test_add_working_days_with_holidays_ru():
    # 2026-12-30 (Среда) + 1 раб день, при условии новогодних каникул 1-8 янв,
    # должен дать 2026-12-31 (четверг).
    holidays = {
        date(2026, 1, 1), date(2026, 1, 2), date(2026, 1, 5),
        date(2026, 1, 6), date(2026, 1, 7), date(2026, 1, 8),
    }
    assert add_working_days(date(2026, 12, 30), 1, holidays) == date(2026, 12, 31)


def test_add_working_days_negative_simple():
    # Вт - 1 = Пн.
    assert add_working_days(date(2026, 6, 2), -1, set()) == date(2026, 6, 1)


def test_add_working_days_negative_crosses_weekend():
    # Пн - 1 = Пт.
    assert add_working_days(date(2026, 6, 8), -1, set()) == date(2026, 6, 5)


# ============ Default holidays для 2026 ============


def test_default_holidays_ru_2026_contains_new_year():
    holidays = get_default_holidays("RU", 2026)
    dates = {d for d, _, _ in holidays}
    assert date(2026, 1, 1) in dates
    assert date(2026, 1, 7) in dates  # Рождество


def test_default_holidays_kz_2026_contains_nauryz():
    holidays = get_default_holidays("KZ", 2026)
    dates = {d for d, _, _ in holidays}
    # Наурыз — 21,22,23 марта
    assert date(2026, 3, 21) in dates
    assert date(2026, 3, 22) in dates
    assert date(2026, 3, 23) in dates


def test_default_holidays_uz_2026_contains_navruz():
    holidays = get_default_holidays("UZ", 2026)
    dates = {d for d, _, _ in holidays}
    assert date(2026, 3, 21) in dates  # Навруз
    assert date(2026, 9, 1) in dates  # Независимость


def test_default_holidays_ae_2026_contains_national_day():
    holidays = get_default_holidays("AE", 2026)
    dates = {d for d, _, _ in holidays}
    assert date(2026, 12, 2) in dates


def test_default_holidays_unknown_country_empty():
    assert get_default_holidays("XX", 2026) == []


def test_default_holidays_wrong_year_empty():
    # Сид определён только для 2026 на сейчас.
    assert get_default_holidays("RU", 2027) == []
    assert get_default_holidays("RU", 2025) == []


def test_default_holidays_case_insensitive_country():
    assert get_default_holidays("ru", 2026) == get_default_holidays("RU", 2026)


def test_default_holidays_2026_all_countries_present():
    # Все 4 страны должны иметь хоть какой-то набор праздников.
    for cc in ("RU", "KZ", "UZ", "AE"):
        assert len(DEFAULT_HOLIDAYS_2026[cc]) > 0, f"No holidays for {cc}"
