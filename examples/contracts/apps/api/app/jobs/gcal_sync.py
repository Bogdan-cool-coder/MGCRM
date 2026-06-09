"""Эпик 24.2 — Google Calendar cron-задача (sync_all_users).

Раз в N минут просыпается, поднимает SessionLocal и зовёт sync_all_users.
Не падает наружу — один сломанный link не блокирует остальные. Lifecycle —
fire-and-forget в app/main.lifespan, как automation_cron.

Период sync — 10 минут. Это компромисс:
- быстрее (1-2 мин) — много лишних API вызовов к Google (rate-limit
  10k requests/day/user)
- медленнее (30+ мин) — заметная задержка между добавлением встречи в
  Google Calendar и её появлением в CRM (плохой UX)

Hook'и из routers/activities (POST/PATCH/DELETE) делают push мгновенно
(см. sync_activity_to_gcal), а cron нужен для:
- pull новых событий из Google (юзер сам добавил встречу в GCal)
- ретрая упавших push'ей
- token refresh
"""
from __future__ import annotations

import asyncio
import logging

from app.config import get_settings
from app.db import SessionLocal
from app.services.gcal_sync import sync_all_users

logger = logging.getLogger(__name__)

# Период sync — 10 минут. Trade-off между нагрузкой на Google API и UX.
GCAL_SYNC_INTERVAL_SECONDS = 10 * 60


async def _sync_once() -> None:
    """Один проход sync_all_users. Не падает наружу."""
    try:
        async with SessionLocal() as session:
            try:
                result = await sync_all_users(session)
                if (
                    result.pulled_in
                    or result.pushed_out
                    or result.deleted_out
                    or result.errors
                ):
                    logger.info(
                        "gcal cron: pulled=%d pushed=%d deleted=%d errors=%d "
                        "full_resync=%s",
                        result.pulled_in,
                        result.pushed_out,
                        result.deleted_out,
                        result.errors,
                        result.full_resync,
                    )
            except Exception as e:  # noqa: BLE001
                logger.exception("gcal cron: sync_all_users failed: %s", e)
    except Exception as e:  # noqa: BLE001
        logger.exception("gcal cron: session-level failure: %s", e)


async def start_gcal_cron() -> None:
    """Бесконечный fire-and-forget loop. Запускается из lifespan.

    Если Google OAuth не настроен — не стартуем (нет смысла грузить БД).
    """
    settings = get_settings()
    if not settings.gcal_ready:
        logger.info(
            "gcal cron: Google OAuth не настроен (GOOGLE_OAUTH_CLIENT_ID "
            "пуст), cron не стартует",
        )
        return

    logger.info(
        "gcal cron started (interval=%ds)", GCAL_SYNC_INTERVAL_SECONDS,
    )
    try:
        while True:
            await _sync_once()
            await asyncio.sleep(GCAL_SYNC_INTERVAL_SECONDS)
    except (asyncio.CancelledError, KeyboardInterrupt):
        logger.info("gcal cron stopped")
        raise
    except Exception as e:  # noqa: BLE001
        logger.exception("gcal cron crashed: %s", e)
