"""Нумерация финдокументов (Ф0).

`fin_number_sequence(doc_type, legal_entity_id, year, prefix, next_value)` —
атомарная выдача следующего номера под scale=2: `UPDATE ... SET next_value =
next_value + 1 RETURNING next_value` под строковой блокировкой (атомарно в БД,
без advisory-lock — конкурентные транзакции сериализуются на UPDATE строки).

Формат номера — чистая функция `format_number` (тестируема без БД).
"""

from __future__ import annotations

from datetime import date

from sqlalchemy import update
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import FinNumberSequence

#: Префикс по типу документа (J: operation/registry/invoice/act/request/journal).
DEFAULT_PREFIX: dict[str, str] = {
    "operation": "ОП",
    "journal": "Ж",
    "registry": "Р",
    "invoice": "СЧ",
    "act": "АКТ",
    "request": "ЗАЯВ",
}


def format_number(prefix: str, year: int, value: int, *, width: int = 5) -> str:
    """Формат финномера: «ПРЕФИКС-ГОД-ПОРЯДКОВЫЙ» (порядковый дополнен нулями).

    Пример: format_number("ОП", 2026, 7) → 'ОП-2026-00007'. Pure.
    """
    return f"{prefix}-{year}-{value:0{width}d}"


async def next_number(
    session: AsyncSession,
    *,
    doc_type: str,
    legal_entity_id: int | None,
    year: int | None = None,
    prefix: str | None = None,
) -> str:
    """Атомарно выдаёт следующий номер документа (insert-missing + UPDATE RETURNING).

    Идемпотентно создаёт строку sequence при первом обращении (ON CONFLICT DO NOTHING
    по uq_fin_number_seq), затем инкрементирует под блокировкой строки → корректно
    при scale=2 (две реплики не выдадут один номер). Возвращает форматированный номер.
    """
    yr = year or date.today().year
    pfx = prefix or DEFAULT_PREFIX.get(doc_type, doc_type.upper())

    # insert-missing: создаём sequence-строку, если её ещё нет (next_value=1).
    await session.execute(
        pg_insert(FinNumberSequence)
        .values(
            doc_type=doc_type,
            legal_entity_id=legal_entity_id,
            year=yr,
            prefix=pfx,
            next_value=1,
        )
        .on_conflict_do_nothing(constraint="uq_fin_number_seq")
    )

    # атомарный инкремент: возвращаем ВЫДАННОЕ значение, затем двигаем next_value.
    cond = [
        FinNumberSequence.doc_type == doc_type,
        FinNumberSequence.year == yr,
    ]
    if legal_entity_id is None:
        cond.append(FinNumberSequence.legal_entity_id.is_(None))
    else:
        cond.append(FinNumberSequence.legal_entity_id == legal_entity_id)

    issued = (
        await session.execute(
            update(FinNumberSequence)
            .where(*cond)
            .values(next_value=FinNumberSequence.next_value + 1)
            .returning(FinNumberSequence.next_value)
        )
    ).scalar_one()

    # returning отдаёт уже инкрементированное next_value → выданный = next_value - 1.
    return format_number(pfx, yr, issued - 1)
