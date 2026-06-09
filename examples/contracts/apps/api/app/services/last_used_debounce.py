"""Эпик 16 — Security: debounce обновления last_used_at для APIToken.

Зачем: текущая логика touch_token() пишет UPDATE api_tokens SET last_used_at
на КАЖДЫЙ Bearer-запрос. При активном использовании токена (тысячи rps)
это создаёт write contention в Postgres + бесполезный bloat.

Решение: Redis-флаг с TTL=600s. Если флаг есть — skip DB update; иначе
ставим флаг + обновляем DB. Точность last_used_at ±10 минут — приемлемо
для UI-индикатора «когда последний раз ходил».

Graceful fallback: без Redis всегда обновляем (бэквард-совместимое
поведение, как раньше).
"""
from __future__ import annotations

import logging
from typing import Final

from app.services.redis_client import get_redis, is_noop_redis

logger = logging.getLogger(__name__)

# Окно debounce: 10 минут. Точность last_used_at в БД будет ±10 минут,
# что приемлемо для UI «5 минут назад» indicator.
DEFAULT_DEBOUNCE_SECONDS: Final[int] = 600


def _debounce_key(token_hash: str) -> str:
    """Redis key для debounce-флага данного токена."""
    return f"token_touch:{token_hash}"


async def should_update_last_used(
    token_hash: str, debounce_seconds: int = DEFAULT_DEBOUNCE_SECONDS,
) -> bool:
    """Проверить, нужно ли обновлять last_used_at в БД.

    Возвращает:
    - True: флаг отсутствует / истёк → обновляем DB + ставим флаг с TTL.
    - False: флаг есть → skip DB update.

    Graceful fallback: без Redis (Noop) → True (всегда обновляем, как
    раньше). Если Redis throw в рантайме — тоже True (fail-safe: пусть
    лучше будет лишний DB write, чем потеря last_used).
    """
    redis = get_redis()
    if is_noop_redis(redis):
        return True

    key = _debounce_key(token_hash)
    try:
        # SET NX = только если ключ ещё не существует; True вернётся если
        # реально создали (т.е. предыдущего флага не было).
        created = await redis.set(key, "1", ex=debounce_seconds, nx=True)
        # set NX возвращает True если установили, None/False если ключ
        # уже существовал. should_update_last_used = created.
        return bool(created)
    except Exception as e:  # noqa: BLE001
        logger.warning("last_used debounce Redis error: %s — fallback to update", e)
        return True
