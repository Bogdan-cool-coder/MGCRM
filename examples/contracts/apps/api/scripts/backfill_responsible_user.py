"""Tech Sprint Фаза 0 (задача 1): backfill `counterparties.responsible_user_id`.

Counterparty.responsible_user_id появилось в миграции 0029 (Эпик 10) с NULL по
умолчанию. Это поле позволяет явно держать «ответственного менеджера» на КА
(до того фронт читал владельца свежей сделки — нестабильно).

Этот скрипт — one-time backfill: для всех КА с responsible_user_id IS NULL
проставляет admin (user_id=1 по умолчанию). После запуска админ через UI
(карточка КА → блок «Ответственный менеджер», или bulk-эндпоинт ниже)
переназначает реальных ответственных.

Запуск:
    cd apps/api
    DATABASE_URL=postgresql+asyncpg://... .venv/bin/python scripts/backfill_responsible_user.py
    # Или с явным admin user_id:
    DATABASE_URL=... .venv/bin/python scripts/backfill_responsible_user.py --user-id=3
    # Dry-run:
    DATABASE_URL=... .venv/bin/python scripts/backfill_responsible_user.py --dry-run

Идемпотентно — повторный запуск ничего не меняет (UPDATE WHERE IS NULL).
"""
from __future__ import annotations

import argparse
import asyncio
import sys
from pathlib import Path

# Добавляем apps/api в sys.path для запуска как standalone-скрипта
sys.path.insert(0, str(Path(__file__).parent.parent))

from sqlalchemy import func, select, update  # noqa: E402

from app.db import SessionLocal  # noqa: E402
from app.models import Counterparty, User, UserRole  # noqa: E402


async def backfill(target_user_id: int, dry_run: bool = False) -> dict[str, int]:
    """Установить responsible_user_id для всех КА с NULL.

    Возвращает {"updated": N, "skipped_existing": M, "target_user_id": K}.
    Если dry_run=True, не коммитит — только считает количество кандидатов.
    """
    async with SessionLocal() as session:
        # 1. Убедиться, что target user существует и активен
        target_user = (
            await session.execute(select(User).where(User.id == target_user_id))
        ).scalar_one_or_none()
        if target_user is None:
            raise SystemExit(
                f"Пользователь user_id={target_user_id} не найден. "
                "Передай --user-id=<id существующего admin'а>."
            )
        if not target_user.is_active:
            raise SystemExit(
                f"Пользователь user_id={target_user_id} ({target_user.email}) деактивирован. "
                "Выбери активного админа."
            )
        if target_user.role != UserRole.admin:
            print(
                f"WARNING: user_id={target_user_id} ({target_user.email}) "
                f"не admin (role={target_user.role.value}). Продолжаем по запросу.",
                file=sys.stderr,
            )

        # 2. Посчитать кандидатов: КА без responsible_user_id
        candidates_count = (
            await session.execute(
                select(func.count(Counterparty.id)).where(
                    Counterparty.responsible_user_id.is_(None)
                )
            )
        ).scalar_one()

        # 3. Посчитать уже заполненные (для отчёта)
        already_set = (
            await session.execute(
                select(func.count(Counterparty.id)).where(
                    Counterparty.responsible_user_id.is_not(None)
                )
            )
        ).scalar_one()

        if dry_run:
            print(
                f"[dry-run] кандидатов на backfill: {candidates_count}; "
                f"уже заполнено: {already_set}. Не коммитим (--dry-run).",
            )
            return {
                "updated": 0,
                "skipped_existing": int(already_set),
                "candidates": int(candidates_count),
                "target_user_id": target_user_id,
            }

        # 4. Выполнить UPDATE
        result = await session.execute(
            update(Counterparty)
            .where(Counterparty.responsible_user_id.is_(None))
            .values(responsible_user_id=target_user_id)
        )
        await session.commit()
        updated = int(result.rowcount or 0)

        print(
            f"Backfill: обновлено {updated} КА → responsible_user_id={target_user_id} "
            f"({target_user.email}). Уже было заполнено: {already_set}.",
        )
        return {
            "updated": updated,
            "skipped_existing": int(already_set),
            "candidates": int(candidates_count),
            "target_user_id": target_user_id,
        }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--user-id",
        type=int,
        default=1,
        help="user_id ответственного, по умолчанию 1 (первый admin)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="посчитать кандидатов без UPDATE",
    )
    args = parser.parse_args()
    asyncio.run(backfill(target_user_id=args.user_id, dry_run=args.dry_run))


if __name__ == "__main__":
    main()
