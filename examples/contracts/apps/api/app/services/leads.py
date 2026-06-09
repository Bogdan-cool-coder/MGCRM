"""Lead pipeline (Эпик 1.0).

Воронка лидов с собственными этапами. Сидер идемпотентен (insert-missing),
защищён advisory-lock от гонок concurrent api-реплик.
"""
from __future__ import annotations

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import Pipeline, PipelineStage

# Уникальный seed-key (не пересекается с 728_274_001..006, используемыми другими сидерами)
_SEED_LOCK_LEAD = 728_274_007

LEAD_PIPELINE_NAME = "Воронка лидов"

# (name, code, order). Этапы лида — короткая воронка до квалификации.
LEAD_STAGES: list[tuple[str, str, int]] = [
    ("Новый", "new", 1),
    ("На обработке", "processing", 2),
    ("Квалифицирован", "qualified", 3),
    ("В работу", "in_work", 4),
    ("Архив", "archived", 5),
]


async def seed_lead_pipeline(session: AsyncSession) -> int:
    """Сид воронки лидов и её этапов. Insert-missing pattern + advisory-lock.

    Идемпотентно: можно вызывать многократно, существующие записи не трогает,
    дозаливает только недостающие. Возвращает число добавленных сущностей
    (воронка как одна + добавленные этапы).
    """
    await session.execute(text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_LEAD})
    try:
        added = 0
        pipe = (await session.execute(
            select(Pipeline).where(Pipeline.name == LEAD_PIPELINE_NAME, Pipeline.kind == "lead")
        )).scalar_one_or_none()
        if pipe is None:
            pipe = Pipeline(name=LEAD_PIPELINE_NAME, kind="lead", is_active=True, sort_order=3)
            session.add(pipe)
            await session.flush()
            added += 1

        existing_codes = {
            s.code for s in (await session.execute(
                select(PipelineStage).where(PipelineStage.pipeline_id == pipe.id)
            )).scalars().all() if s.code
        }
        for name, code, order in LEAD_STAGES:
            if code in existing_codes:
                continue
            session.add(PipelineStage(
                pipeline_id=pipe.id,
                name=name,
                code=code,
                sort_order=order,
                is_won=False,
                is_lost=(code == "archived"),
            ))
            added += 1
        await session.commit()
        return added
    finally:
        await session.execute(text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_LEAD})
