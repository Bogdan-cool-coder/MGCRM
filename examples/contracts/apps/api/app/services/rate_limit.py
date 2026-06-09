"""Эпик 16 — Security: Token-bucket rate limit для Bearer API tokens.

Алгоритм:
- Ключ Redis: `rate_limit:token:{token_hash}` (sha256 → 64 hex)
- При первом запросе → INCR + EXPIRE 3600s; результат INCR == 1.
- При последующих → только INCR; TTL уже идёт от первого.
- Если INCR > limit_per_hour → reject 429.
- Окно скользящее по-сути почасовое (rolling): TTL обновляется только на
  первом INCR, дальше идёт «затухание». Это token-bucket в простой форме
  (без точного refill rate, но достаточно для anti-abuse целей API).

Graceful fallback:
- Если Redis Noop (REDIS_URL не задан) → всегда allowed.
- Если Redis в рантайме бросил исключение → log warning + allowed.

RateLimitInfo (dataclass) — для проставления HTTP headers:
- X-RateLimit-Limit
- X-RateLimit-Remaining
- X-RateLimit-Reset (unix timestamp когда окно сбросится)
- Retry-After (только на 429, секунды до reset)

Pure-function алгоритм проверяется в tests/test_rate_limit.py с моком
Redis (без реального инстанса).
"""
from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from typing import Any

from app.services.redis_client import get_redis, is_noop_redis

logger = logging.getLogger(__name__)

# Окно rate-limit'а в секундах. Час — стандарт для API quotas.
RATE_LIMIT_WINDOW_SECONDS = 3600


@dataclass(frozen=True)
class RateLimitInfo:
    """Метаданные для HTTP headers (Bearer-rate-limit).

    Поля совместимы со стандартом draft-ietf-httpapi-ratelimit-headers:
    - limit: верхняя граница (X-RateLimit-Limit)
    - remaining: осталось в окне (X-RateLimit-Remaining; не может быть < 0)
    - reset_at: unix-time когда окно сбросится (X-RateLimit-Reset)
    - retry_after_seconds: для 429 (Retry-After header); 0 если allowed
    """

    limit: int
    remaining: int
    reset_at: int
    retry_after_seconds: int

    def to_headers(self) -> dict[str, str]:
        """HTTP headers для добавления в response (allowed или 429)."""
        h = {
            "X-RateLimit-Limit": str(self.limit),
            "X-RateLimit-Remaining": str(max(0, self.remaining)),
            "X-RateLimit-Reset": str(self.reset_at),
        }
        if self.retry_after_seconds > 0:
            h["Retry-After"] = str(self.retry_after_seconds)
        return h


def _rate_limit_key(token_hash: str) -> str:
    """Redis key для rate-limit token-bucket'а данного токена."""
    return f"rate_limit:token:{token_hash}"


def compute_rate_limit_info(
    current_count: int, limit: int, ttl_seconds: int,
) -> tuple[bool, RateLimitInfo]:
    """Pure-function: посчитать allowed/info по сырому INCR + TTL.

    Используется в check_rate_limit (поверх Redis) И в тестах (мок Redis).
    Отдельно вынесено чтобы алгоритм был тестируем без I/O.

    Args:
        current_count: значение, которое INCR вернул (1 = первый запрос
            в окне, 2 — второй, и т.д.).
        limit: rate_limit_per_hour токена (1000 default).
        ttl_seconds: сколько секунд осталось до сброса окна. -2 (no key) →
            трактуем как полное окно (3600).

    Returns:
        (allowed, info). allowed=False если current_count > limit.
    """
    # Если ключ не существовал (-2) или не имел TTL (-1) → новый окно.
    if ttl_seconds < 0:
        ttl_seconds = RATE_LIMIT_WINDOW_SECONDS

    remaining = max(0, limit - current_count)
    reset_at = int(time.time()) + ttl_seconds
    allowed = current_count <= limit

    info = RateLimitInfo(
        limit=limit,
        remaining=remaining,
        reset_at=reset_at,
        retry_after_seconds=ttl_seconds if not allowed else 0,
    )
    return allowed, info


async def check_rate_limit(
    token_hash: str, limit_per_hour: int,
) -> tuple[bool, RateLimitInfo]:
    """Проверить и инкрементировать rate-limit для данного токена.

    Алгоритм:
    1. INCR rate_limit:token:{hash} → count.
    2. Если count == 1 (новое окно) → EXPIRE 3600s.
    3. TTL → reset_at.
    4. compute_rate_limit_info → (allowed, info).

    Graceful fallback:
    - Noop Redis (REDIS_URL не задан) → (True, info с remaining=limit).
    - Redis throw exception → log warning, (True, info с remaining=limit).

    Args:
        token_hash: SHA256 hex от plaintext Bearer (64 chars).
        limit_per_hour: APIToken.rate_limit_per_hour владельца.
    """
    redis = get_redis()

    # Noop fallback — без Redis. Всегда allowed, headers «как будто новое окно».
    if is_noop_redis(redis):
        return True, RateLimitInfo(
            limit=limit_per_hour,
            remaining=limit_per_hour,
            reset_at=int(time.time()) + RATE_LIMIT_WINDOW_SECONDS,
            retry_after_seconds=0,
        )

    key = _rate_limit_key(token_hash)
    try:
        # INCR — атомарный; первый вызов создаёт ключ с value=1 и без TTL,
        # дальше инкрементит. EXPIRE ставим только на первой инкарнации,
        # чтобы не сбрасывать окно на каждом запросе.
        count = await redis.incr(key)
        if count == 1:
            await redis.expire(key, RATE_LIMIT_WINDOW_SECONDS)
        ttl = await redis.ttl(key)
    except Exception as e:  # noqa: BLE001
        # Redis упал в рантайме — fail-open (не блокируем юзера если
        # инфра broken). Логируем для алертов.
        logger.warning("Rate-limit Redis error: %s", e)
        return True, RateLimitInfo(
            limit=limit_per_hour,
            remaining=limit_per_hour,
            reset_at=int(time.time()) + RATE_LIMIT_WINDOW_SECONDS,
            retry_after_seconds=0,
        )

    return compute_rate_limit_info(count, limit_per_hour, ttl)


# ============ Public form submit: per-IP rate limit (баг #8 код-аудита) ============

# Окно и лимит для публичных форм. Дробим окно мельче (минута), чтобы спам-бот
# быстро упёрся, но легитимная отправка раз в несколько секунд проходила.
FORM_RATE_LIMIT_WINDOW_SECONDS = 60
FORM_RATE_LIMIT_PER_WINDOW = 10


def _form_rate_limit_key(slug: str, ip: str) -> str:
    """Redis key для rate-limit публичной формы (per slug+IP)."""
    return f"rate_limit:form:{slug}:{ip}"


async def check_form_rate_limit(slug: str, ip: str | None) -> bool:
    """Token-bucket rate-limit для public form submit (per slug+IP).

    Возвращает True если разрешено, False если превышен лимит / Redis-сбой.

    Fail-policy (баг C4 WARN-2): публичный (unauth) эндпоинт.
    - Noop Redis (REDIS_URL не задан) → fail-OPEN (True): осознанная dev/локальная
      деградация, логируется в is_noop_redis при старте.
    - Redis сконфигурен, но упал в рантайме → fail-CLOSED (False): не оставляем
      anti-spam защиту молча выключенной при инфра-сбое.
    Если IP неизвестен → True (нечего ключевать; защита по IP неприменима).
    """
    if not ip:
        return True
    redis = get_redis()
    if is_noop_redis(redis):
        return True
    key = _form_rate_limit_key(slug, ip)
    try:
        count = await redis.incr(key)
        if count == 1:
            await redis.expire(key, FORM_RATE_LIMIT_WINDOW_SECONDS)
    except Exception as e:  # noqa: BLE001
        # Публичный эндпоинт + Redis сконфигурен, но недоступен → fail-closed.
        logger.error("Form rate-limit Redis error (fail-closed): %s", e)
        return False
    return count <= FORM_RATE_LIMIT_PER_WINDOW


# ============ Inbound webhook: per-channel+IP rate limit (C4 WARN-1) ============

# Inbound webhook'и шлют провайдеры/боты — допускаем заметно больший поток, чем
# у публичной формы, но всё же ограничиваем (anti-flood: каждое сообщение
# создаёт Company/Deal + dispatch + automations).
INBOUND_WEBHOOK_RATE_LIMIT_WINDOW_SECONDS = 60
INBOUND_WEBHOOK_RATE_LIMIT_PER_WINDOW = 120


def _inbound_webhook_rate_limit_key(channel_id: int, ip: str) -> str:
    """Redis key для rate-limit inbound webhook'а (per channel+IP)."""
    return f"rate_limit:inbound:{channel_id}:{ip}"


async def check_inbound_webhook_rate_limit(channel_id: int, ip: str | None) -> bool:
    """Token-bucket rate-limit для unauth inbound webhook (per channel+IP).

    Баг C4 WARN-1: /api/inbox/webhook/{channel_id} не имел лимита → при утечке
    secret_token можно было флудить авто-созданием Company/Deal.

    Fail-policy идентична check_form_rate_limit (публичный эндпоинт):
    - Noop Redis → fail-OPEN (dev/локально без Redis).
    - Redis-сбой при сконфигуренном Redis → fail-CLOSED.
    Если IP неизвестен → True.
    """
    if not ip:
        return True
    redis = get_redis()
    if is_noop_redis(redis):
        return True
    key = _inbound_webhook_rate_limit_key(channel_id, ip)
    try:
        count = await redis.incr(key)
        if count == 1:
            await redis.expire(key, INBOUND_WEBHOOK_RATE_LIMIT_WINDOW_SECONDS)
    except Exception as e:  # noqa: BLE001
        logger.error("Inbound webhook rate-limit Redis error (fail-closed): %s", e)
        return False
    return count <= INBOUND_WEBHOOK_RATE_LIMIT_PER_WINDOW


# ============ UptimeRobot webhook: per-IP rate limit ============

# UptimeRobot шлёт DOWN/UP события редко (раз в несколько минут на монитор), но
# секрет передаётся в URL — при утечке можно флудить Telegram-алертами. Лимит
# щедрый под легитимные ретраи UptimeRobot, но отсекает спам.
UPTIME_WEBHOOK_RATE_LIMIT_WINDOW_SECONDS = 60
UPTIME_WEBHOOK_RATE_LIMIT_PER_WINDOW = 30


def _uptime_webhook_rate_limit_key(ip: str) -> str:
    """Redis key для rate-limit uptime-webhook'а (per IP)."""
    return f"rate_limit:uptime:{ip}"


async def check_uptime_webhook_rate_limit(ip: str | None) -> bool:
    """Token-bucket rate-limit для unauth uptime-webhook (per IP).

    Fail-policy идентична check_form_rate_limit (публичный эндпоинт):
    - Noop Redis (REDIS_URL не задан) → fail-OPEN (dev/локально без Redis).
    - Redis сконфигурен, но упал → fail-CLOSED.
    Если IP неизвестен → True.
    """
    if not ip:
        return True
    redis = get_redis()
    if is_noop_redis(redis):
        return True
    key = _uptime_webhook_rate_limit_key(ip)
    try:
        count = await redis.incr(key)
        if count == 1:
            await redis.expire(key, UPTIME_WEBHOOK_RATE_LIMIT_WINDOW_SECONDS)
    except Exception as e:  # noqa: BLE001
        logger.error("Uptime webhook rate-limit Redis error (fail-closed): %s", e)
        return False
    return count <= UPTIME_WEBHOOK_RATE_LIMIT_PER_WINDOW


async def reset_rate_limit(token_hash: str) -> None:
    """Сбросить rate-limit окно (для тестов / admin override).

    Никогда не вызывается из endpoint'ов; нужен только для админ-операции
    «разблокировать токен» если кто-то нечаянно лочнулся.
    """
    redis = get_redis()
    if is_noop_redis(redis):
        return
    try:
        await redis.delete(_rate_limit_key(token_hash))
    except Exception as e:  # noqa: BLE001
        logger.warning("Rate-limit reset Redis error: %s", e)
