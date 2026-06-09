"""Точка входа отдельного bot-сервиса: только Telegram long-polling, без FastAPI.

В проде api масштабируется (replicas:2) и НЕ поллит (RUN_TELEGRAM_POLLING=false);
поллер живёт здесь в единственном экземпляре (compose-сервис `bot`, replicas:1),
иначе несколько параллельных getUpdates дают Telegram 409 Conflict и теряют апдейты.
Миграции и сиды здесь НЕ запускаются — это делают api-реплики (alembic в CMD).
"""

from __future__ import annotations

import asyncio
import logging

from app.config import get_settings
from app.services.telegram import start_polling

settings = get_settings()
logging.basicConfig(level=settings.log_level)
logger = logging.getLogger(__name__)


async def _idle() -> None:
    while True:
        await asyncio.sleep(3600)


def main() -> None:
    if not settings.telegram_bot_token:
        # Без токена не падаем в crash-loop (restart:unless-stopped), просто простаиваем.
        logger.warning("TELEGRAM_BOT_TOKEN не задан — bot-сервис idle")
        asyncio.run(_idle())
        return
    asyncio.run(start_polling())


if __name__ == "__main__":
    main()
