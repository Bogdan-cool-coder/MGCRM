"""Backfill скрипт для subscription_stage_history (Эпик 22).

Создаёт одну начальную запись для каждой существующей подписки:
- to_stage_code = текущий stage.code подписки
- from_stage_code = NULL (история до деплоя неизвестна)
- changed_at = subscription.updated_at (приближение — могло быть давно)
- changed_by_user_id = NULL (системная запись)

ВАЖНО:
- Запускать ОДИН РАЗ вручную после деплоя миграции 0045.
- Идемпотентен: пропускает подписки, у которых уже есть запись в history.
- Не перезаписывает существующую историю.
- После backfill история будет неполной (только текущий stage), но
  когортная аналитика начнёт накапливать точную историю с момента деплоя.

Запуск:
    cd apps/api
    .venv/bin/python scripts/backfill_subscription_history.py
"""
from __future__ import annotations

import asyncio
import sys
from pathlib import Path

# Добавляем apps/api в PYTHONPATH чтобы import app.* работал без install -e
sys.path.insert(0, str(Path(__file__).parent.parent))

from sqlalchemy import select, text
from sqlalchemy.ext.asyncio import AsyncSession

from app.db import SessionLocal
from app.models import ClientSubscription, PipelineStage, SubscriptionStageHistory


async def backfill(session: AsyncSession) -> int:
    """Вернуть число созданных записей."""
    # Подписки у которых уже есть хотя бы одна запись истории — пропускаем
    existing_ids_result = await session.execute(
        text("SELECT DISTINCT subscription_id FROM subscription_stage_history")
    )
    already_filled: set[int] = {row[0] for row in existing_ids_result}

    # Все подписки с lifecycle_stage_id (у остальных нет чего записывать)
    subs = (await session.execute(
        select(ClientSubscription).where(
            ClientSubscription.lifecycle_stage_id.is_not(None)
        )
    )).scalars().all()

    if not subs:
        print("Подписок с lifecycle_stage_id не найдено. Выход.")
        return 0

    # Загружаем все stage codes одним запросом
    stage_ids = {s.lifecycle_stage_id for s in subs if s.lifecycle_stage_id}
    stages = (await session.execute(
        select(PipelineStage).where(PipelineStage.id.in_(stage_ids))
    )).scalars().all()
    code_by_id: dict[int, str] = {s.id: s.code for s in stages}

    created = 0
    for sub in subs:
        if sub.id in already_filled:
            continue
        stage_code = code_by_id.get(sub.lifecycle_stage_id)
        if not stage_code:
            continue
        entry = SubscriptionStageHistory(
            subscription_id=sub.id,
            from_stage_code=None,
            to_stage_code=stage_code,
            changed_at=sub.updated_at,
            changed_by_user_id=None,
        )
        session.add(entry)
        created += 1

    await session.commit()
    return created


async def main() -> None:
    async with SessionLocal() as session:
        count = await backfill(session)
        print(f"Backfill завершён. Создано записей: {count}")


if __name__ == "__main__":
    asyncio.run(main())
