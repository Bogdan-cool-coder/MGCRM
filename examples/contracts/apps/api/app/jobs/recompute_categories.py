"""Ночной пересчёт категорий клиентов по обороту.

Запуск (в контейнере api): python -m app.jobs.recompute_categories
Ставится в cron через deploy/setup_cron.sh.
"""
import asyncio

from app.db import SessionLocal
from app.services.categories import recompute_all


async def _main() -> None:
    async with SessionLocal() as session:
        n = await recompute_all(session)
        print(f"client categories recomputed: {n}")


if __name__ == "__main__":
    asyncio.run(_main())
