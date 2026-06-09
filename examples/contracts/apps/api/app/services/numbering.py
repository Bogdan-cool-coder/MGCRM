"""Генерация номеров договоров: ТШК-219/UZ."""

from __future__ import annotations

from sqlalchemy.dialects.postgresql import insert as pg_insert

from sqlalchemy.ext.asyncio import AsyncSession

from app.models import ContractNumberSequence

# Транслитерация: первые 3 буквы города → латиница (если хотим единый формат)
# Но Богдан в примере дал кириллицу (ТШК), оставим кириллицу. Латинский только для country.

CITY_CODE_LEN = 3


def city_code_from_name(city_name: str) -> str:
    """Берём первые 3 буквы названия города в верхнем регистре."""
    cleaned = city_name.strip().upper()
    # уберём пробелы/тире, возьмём чисто буквенный префикс
    letters = "".join(ch for ch in cleaned if ch.isalpha())
    return letters[:CITY_CODE_LEN]


def country_suffix(country_code: str) -> str:
    """KZ или UZ — заглавные две латинские буквы. UZ по требованию Богдана может быть 'УЗ' (кириллица).
    Делаем латиницу по умолчанию (стандарт для номеров)."""
    return country_code.upper()


async def next_contract_number(
    session: AsyncSession,
    city_name: str,
    country_code: str,
    start_number: int = 220,
) -> tuple[str, str, int]:
    """
    Атомарно выдаёт следующий номер договора по (city, country).

    Возвращает: (full_number, city_code, sequence_number)

    Пример: ("ТШК-220/UZ", "ТШК", 220)

    B9 (race-fix): атомарный upsert вместо `SELECT ... FOR UPDATE` + INSERT.
    Старый код лочил только СУЩЕСТВУЮЩУЮ строку; при первом обращении две
    конкурентные транзакции (scale=2) обе шли в INSERT → IntegrityError
    (uq_seq_city_country) → необработанный 500. Теперь —
    `INSERT ... ON CONFLICT (city_code, country_code) DO UPDATE SET
    current_number = current_number + 1 RETURNING current_number`: первый
    insert возвращает start_number, каждый конкурентный/последующий — атомарно
    инкрементированное значение под строковой блокировкой ON CONFLICT.
    Семантика нумерации сохранена (первый номер = start_number, далее +1).
    """
    city_code = city_code_from_name(city_name)
    suffix = country_suffix(country_code)

    # Атомарный upsert: на первой вставке отдаём start_number; при конфликте
    # (строка уже есть — в т.ч. созданная конкурентной транзакцией) инкрементим
    # current_number и возвращаем новое значение. ON CONFLICT DO UPDATE берёт
    # строковую блокировку → сериализует конкурентные выдачи без 500.
    stmt = (
        pg_insert(ContractNumberSequence)
        .values(
            city_code=city_code,
            country_code=suffix,
            start_number=start_number,
            current_number=start_number,
        )
        .on_conflict_do_update(
            constraint="uq_seq_city_country",
            set_={
                "current_number": ContractNumberSequence.current_number + 1,
            },
        )
        .returning(ContractNumberSequence.current_number)
    )
    number = (await session.execute(stmt)).scalar_one()

    full_number = f"{city_code}-{number}/{suffix}"
    return full_number, city_code, number
