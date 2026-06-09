"""Эпик 16 — Security: Redis client (lazy singleton с graceful fallback).

Зачем: rate-limit (token-bucket) и last_used debounce для Bearer-токенов
требуют быстрого in-memory K/V с TTL. PostgreSQL для INCR/EXPIRE на каждый
запрос — overkill (создаёт нагрузку на DB pool, тормозит при scale=2 api).

Поведение:
- Если REDIS_URL не задан → возвращаем `_NoopRedis` — stub, у которого
  все методы — no-op (вернут None / False / 0). Это позволяет dev/CI
  работать без Redis-инстанса.
- Если REDIS_URL задан, но соединение упало → один раз залогируем
  warning (с дебаунсом 5 минут), вернём `_NoopRedis` для этого вызова.

Singleton: get_redis() возвращает один и тот же объект на процесс.
Сбрасывать через reset_redis_client() — для тестов.
"""
from __future__ import annotations

import logging
import time
from typing import Any

from app.config import get_settings

logger = logging.getLogger(__name__)

# Сколько секунд держим тишину между warning'ами «Redis недоступен»,
# чтобы не залогировать миллион строк при downtime'е.
_WARNING_DEBOUNCE_SECONDS = 300

_last_warning_at: float | None = None


def _maybe_warn(message: str) -> None:
    """Залогировать warning, но не чаще раза в N секунд."""
    global _last_warning_at
    now = time.time()
    if _last_warning_at is None or (now - _last_warning_at) > _WARNING_DEBOUNCE_SECONDS:
        logger.warning(message)
        _last_warning_at = now


class _NoopRedis:
    """Stub-реализация Redis client'а: все методы — no-op.

    Используется когда REDIS_URL не задан или Redis недоступен. Гарантирует,
    что приложение НЕ падает при отсутствии Redis — rate-limit всегда
    разрешает, debounce не работает (last_used обновляется на каждый запрос).
    """

    async def get(self, key: str) -> None:
        return None

    async def set(
        self,
        key: str,
        value: Any,
        ex: int | None = None,
        nx: bool = False,
    ) -> bool:
        return False

    async def incr(self, key: str) -> int:
        # 0 = first call (а не 1) — token-bucket сам решает что делать
        # с graceful fallback (всегда allowed).
        return 0

    async def expire(self, key: str, ttl: int) -> bool:
        return False

    async def ttl(self, key: str) -> int:
        return -2  # Redis-convention: -2 = key не существует

    async def delete(self, *keys: str) -> int:
        return 0

    async def ping(self) -> bool:
        return False

    async def aclose(self) -> None:
        return None

    async def close(self) -> None:
        return None


_redis_instance: Any = None


def get_redis() -> Any:
    """Lazy singleton: возвращает Redis client или Noop fallback.

    На первом вызове читает REDIS_URL из настроек. Если URL пустой →
    кэширует Noop. Если задан → пытается импортнуть redis.asyncio и создать
    клиент. При любой ошибке (нет либы, неверный URL) → Noop.

    Сам HTTP/network сбой при выполнении операции (Redis упал в рантайме)
    обрабатывается на стороне вызывающего кода (rate_limit / last_used),
    а не здесь — здесь только init.
    """
    global _redis_instance
    if _redis_instance is not None:
        return _redis_instance

    settings = get_settings()
    url = (settings.redis_url or "").strip()
    if not url:
        _redis_instance = _NoopRedis()
        return _redis_instance

    try:
        import redis.asyncio as redis_asyncio  # type: ignore[import-not-found]
    except ImportError:
        _maybe_warn(
            "redis package не установлен — fallback на noop. "
            "Установите 'redis>=5.0' в pyproject."
        )
        _redis_instance = _NoopRedis()
        return _redis_instance

    try:
        _redis_instance = redis_asyncio.from_url(
            url,
            encoding="utf-8",
            decode_responses=True,
            socket_connect_timeout=2,
            socket_timeout=2,
        )
    except Exception as e:  # noqa: BLE001
        _maybe_warn(f"Не удалось создать Redis client из {url!r}: {e}")
        _redis_instance = _NoopRedis()
    return _redis_instance


def reset_redis_client() -> None:
    """Сбросить singleton — для тестов и переконфигурации.

    После reset следующий get_redis() переинициализирует с актуальными
    settings (например, после monkey-patch REDIS_URL).
    """
    global _redis_instance, _last_warning_at
    _redis_instance = None
    _last_warning_at = None


def is_noop_redis(client: Any) -> bool:
    """True если client — fallback Noop (не настоящий Redis).

    Используется в rate_limit для best-effort short-circuit на always-allow.
    """
    return isinstance(client, _NoopRedis)
