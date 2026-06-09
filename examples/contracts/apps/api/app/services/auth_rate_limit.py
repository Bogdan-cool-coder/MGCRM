"""Эпик 16 — Security: 2FA validate rate limit (5 попыток / 15 минут).

Алгоритм:
- Ключ Redis: `auth_rl:2fa:{ip}:{user_id}` (счётчик попыток в окне).
- При INCR == 1 → EXPIRE 900s.
- Если count > limit (5) → bumpaem retry_after = TTL, возвращаем (False, retry_after).
- Иначе → (True, 0).

Graceful fallback:
- Redis Noop (REDIS_URL не задан) → всегда allowed.
- Redis throw → log warning + allowed.

Pure-function алгоритм `compute_auth_rate_limit_decision` тестируется в
tests/test_auth_rate_limit.py без реального Redis.
"""
from __future__ import annotations

import logging
import threading
import time

from app.services.redis_client import get_redis, is_noop_redis

logger = logging.getLogger(__name__)

# Лимит и окно для 2FA validate (по требованию ТЗ Эпик 16: 5 попыток / 15 мин).
AUTH_2FA_VALIDATE_LIMIT = 5
AUTH_2FA_VALIDATE_WINDOW_SECONDS = 15 * 60  # 900s


# ============ P0 Security: in-process fallback counter (fail-CLOSED) ============
#
# Проблема (C4): сетевой rate-limiter fail-OPEN — если Redis недоступен,
# check_*_rate_limit пропускает всех. Для AUTH-эндпоинтов это значит, что
# падение Redis = окно для brute-force без лимита.
#
# Решение: bounded in-process sliding-window счётчик (per-process). Если
# Redis Noop/упал — auth-эндпоинты переключаются на него вместо полного
# fail-open. Тонкости:
#   - per-process: на scale=2 api лимит делится между репликами (×2 эффективно).
#     Это осознанный tradeoff — лучше слабый throttle, чем его отсутствие.
#   - bounded: словарь самоочищается (выкидываем протухшие окна), не растёт
#     неограниченно даже при распределённой атаке по многим ключам.
#   - thread-safe: Lock, т.к. uvicorn workers могут шарить процесс.

# Жёсткий потолок размера словаря, чтобы атака перебором ключей не съела память.
_FALLBACK_MAX_KEYS = 50_000

_fallback_lock = threading.Lock()
# key -> list[timestamps] (только в пределах текущего окна)
_fallback_hits: dict[str, list[float]] = {}


def _fallback_sliding_window(
    key: str, limit: int, window_seconds: int, now: float | None = None,
) -> bool:
    """In-process sliding-window: True если разрешено, False если превышено.

    Хранит timestamps попыток на key, выкидывает старше window. Если число
    попыток в окне > limit → отказ. Bounded: при переполнении словаря чистим
    протухшие ключи; если всё равно полно — отказываем (fail-CLOSED на auth).
    """
    ts = time.monotonic() if now is None else now
    cutoff = ts - window_seconds
    with _fallback_lock:
        # Periodic GC при разрастании: убираем ключи без свежих hits.
        if len(_fallback_hits) > _FALLBACK_MAX_KEYS:
            for k in list(_fallback_hits.keys()):
                _fallback_hits[k] = [t for t in _fallback_hits[k] if t >= cutoff]
                if not _fallback_hits[k]:
                    del _fallback_hits[k]
            # Если всё ещё переполнено (распределённая атака) — fail-CLOSED.
            if len(_fallback_hits) > _FALLBACK_MAX_KEYS and key not in _fallback_hits:
                return False

        hits = [t for t in _fallback_hits.get(key, []) if t >= cutoff]
        hits.append(ts)
        _fallback_hits[key] = hits
        return len(hits) <= limit


def reset_fallback_counters() -> None:
    """Сбросить in-process fallback (для тестов)."""
    with _fallback_lock:
        _fallback_hits.clear()


def _key_2fa_validate(ip: str, user_id: int) -> str:
    """Redis-key для счётчика 2FA validate попыток.

    IP+user_id одновременно: защита от brute-force с одного IP по разным юзерам
    (через user_id фиксированно), и от distributed brute-force через перебор
    IP (через user_id всё равно один счётчик не наберётся выше лимита).
    """
    return f"auth_rl:2fa:{ip}:{user_id}"


def compute_auth_rate_limit_decision(
    current_count: int, ttl_seconds: int, limit: int,
) -> tuple[bool, int]:
    """Pure-function: посчитать allowed/retry_after по сырому INCR + TTL.

    Args:
        current_count: значение которое INCR вернул (0 = Noop fallback,
            1 = первый запрос в окне, ...).
        ttl_seconds: остаток жизни ключа (-2 = нет ключа, -1 = без TTL).
        limit: верхняя граница попыток (5 для 2FA validate).

    Returns:
        (allowed, retry_after_seconds). retry_after=0 если allowed.
    """
    # Noop fallback (count=0) → всегда allowed.
    if current_count <= 0:
        return True, 0
    if current_count > limit:
        retry_after = ttl_seconds if ttl_seconds > 0 else AUTH_2FA_VALIDATE_WINDOW_SECONDS
        return False, retry_after
    return True, 0


async def check_2fa_validate_rate_limit(
    ip: str, user_id: int,
) -> tuple[bool, int]:
    """Проверить лимит 5 попыток / 15 мин на 2FA validate (per IP+user).

    Возвращает (allowed, retry_after_seconds). retry_after=0 если allowed.
    Graceful fallback на Noop Redis → всегда allowed.
    """
    redis = get_redis()
    if is_noop_redis(redis):
        return True, 0
    key = _key_2fa_validate(ip, user_id)
    try:
        count = await redis.incr(key)
        if count == 1:
            await redis.expire(key, AUTH_2FA_VALIDATE_WINDOW_SECONDS)
        ttl = await redis.ttl(key)
    except Exception as e:  # noqa: BLE001
        logger.warning("2FA validate rate-limit Redis error: %s", e)
        return True, 0
    return compute_auth_rate_limit_decision(
        count, ttl, AUTH_2FA_VALIDATE_LIMIT,
    )


# ============ P0 Security: login brute-force rate-limit (fail-CLOSED) ============
#
# Брутфорс-защита /auth/login: per-IP И per-account (email). Окно — минута,
# лимит 10 попыток. Считаем ВСЕ попытки (успешные тоже) — не выдаём оракул,
# увеличивает ли неверный пароль счётчик иначе чем верный.
#
# fail-CLOSED: если Redis недоступен → переключаемся на in-process
# fallback-счётчик (а не пропускаем всех). Так brute-force throttled даже
# при сломанном Redis.

LOGIN_RATE_LIMIT_PER_WINDOW = 10
LOGIN_RATE_LIMIT_WINDOW_SECONDS = 60


def _key_login_ip(ip: str) -> str:
    return f"auth_rl:login:ip:{ip}"


def _key_login_email(email: str) -> str:
    return f"auth_rl:login:email:{email.strip().lower()}"


async def _incr_window(key: str, window_seconds: int) -> int | None:
    """INCR+EXPIRE через Redis. Возвращает count, или None если Redis
    Noop/упал (caller переключится на in-process fallback)."""
    redis = get_redis()
    if is_noop_redis(redis):
        return None
    try:
        count = await redis.incr(key)
        if count == 1:
            await redis.expire(key, window_seconds)
        return count
    except Exception as e:  # noqa: BLE001
        logger.warning("Login rate-limit Redis error: %s", e)
        return None


async def check_login_rate_limit(ip: str, email: str) -> tuple[bool, int]:
    """Брутфорс-лимит login: per-IP + per-account. fail-CLOSED.

    Возвращает (allowed, retry_after_seconds). allowed=False если ЛИБО
    per-IP, ЛИБО per-email окно превышено.

    Redis недоступен → in-process fallback (bounded, per-process). Это
    осознанно слабее распределённого лимита, но не fail-OPEN.
    """
    ip_key = _key_login_ip(ip or "0.0.0.0")
    email_key = _key_login_email(email or "")

    ip_count = await _incr_window(ip_key, LOGIN_RATE_LIMIT_WINDOW_SECONDS)
    email_count = await _incr_window(email_key, LOGIN_RATE_LIMIT_WINDOW_SECONDS)

    if ip_count is None or email_count is None:
        # Redis недоступен хотя бы для одного ключа → fail-CLOSED in-process.
        ip_ok = _fallback_sliding_window(
            ip_key, LOGIN_RATE_LIMIT_PER_WINDOW, LOGIN_RATE_LIMIT_WINDOW_SECONDS,
        )
        email_ok = _fallback_sliding_window(
            email_key, LOGIN_RATE_LIMIT_PER_WINDOW, LOGIN_RATE_LIMIT_WINDOW_SECONDS,
        )
        allowed = ip_ok and email_ok
        return allowed, (0 if allowed else LOGIN_RATE_LIMIT_WINDOW_SECONDS)

    over = (
        ip_count > LOGIN_RATE_LIMIT_PER_WINDOW
        or email_count > LOGIN_RATE_LIMIT_PER_WINDOW
    )
    return (not over), (LOGIN_RATE_LIMIT_WINDOW_SECONDS if over else 0)


# ============ P0 Security: tg-bot intent rate-limit ============
#
# Кап на /api/tg-bot/intent per tg_user_id (атакер с валидным bot-секретом
# или сам бот не должны заспамить Claude). Окно — минута, 30 запросов.

TG_INTENT_RATE_LIMIT_PER_WINDOW = 30
TG_INTENT_RATE_LIMIT_WINDOW_SECONDS = 60


def _key_tg_intent(tg_user_id: int) -> str:
    return f"auth_rl:tg_intent:{tg_user_id}"


async def check_tg_intent_rate_limit(tg_user_id: int) -> tuple[bool, int]:
    """Per-tg_user кап на intent. fail-CLOSED на in-process при сбое Redis."""
    key = _key_tg_intent(tg_user_id)
    count = await _incr_window(key, TG_INTENT_RATE_LIMIT_WINDOW_SECONDS)
    if count is None:
        ok = _fallback_sliding_window(
            key, TG_INTENT_RATE_LIMIT_PER_WINDOW, TG_INTENT_RATE_LIMIT_WINDOW_SECONDS,
        )
        return ok, (0 if ok else TG_INTENT_RATE_LIMIT_WINDOW_SECONDS)
    over = count > TG_INTENT_RATE_LIMIT_PER_WINDOW
    return (not over), (TG_INTENT_RATE_LIMIT_WINDOW_SECONDS if over else 0)


# ============ P0 Security: AI per-user denial-of-wallet cap ============
#
# Кап на платные Claude-вызовы per-user (assistant/cold-call/training).
# Окно — минута, 20 запросов. fail-OPEN допустим (это не auth и не утечка —
# просто стоимость), но используем тот же in-process fallback для consistency.

AI_USER_RATE_LIMIT_PER_WINDOW = 20
AI_USER_RATE_LIMIT_WINDOW_SECONDS = 60


def _key_ai_user(user_id: int, bucket: str) -> str:
    return f"auth_rl:ai:{bucket}:{user_id}"


async def check_ai_user_rate_limit(
    user_id: int, bucket: str = "default",
) -> tuple[bool, int]:
    """Per-user кап на AI-вызовы. bucket разделяет лимиты по фиче (assistant,
    cold_call, training). fail-CLOSED на in-process при сбое Redis."""
    key = _key_ai_user(user_id, bucket)
    count = await _incr_window(key, AI_USER_RATE_LIMIT_WINDOW_SECONDS)
    if count is None:
        ok = _fallback_sliding_window(
            key, AI_USER_RATE_LIMIT_PER_WINDOW, AI_USER_RATE_LIMIT_WINDOW_SECONDS,
        )
        return ok, (0 if ok else AI_USER_RATE_LIMIT_WINDOW_SECONDS)
    over = count > AI_USER_RATE_LIMIT_PER_WINDOW
    return (not over), (AI_USER_RATE_LIMIT_WINDOW_SECONDS if over else 0)


# ============ P0 Security: broadcast send per-user cap ============
#
# Кап на admin broadcast send (mass-send abuse). Окно — час, 20 рассылок.

BROADCAST_RATE_LIMIT_PER_WINDOW = 20
BROADCAST_RATE_LIMIT_WINDOW_SECONDS = 3600


def _key_broadcast(user_id: int) -> str:
    return f"auth_rl:broadcast:{user_id}"


async def check_broadcast_rate_limit(user_id: int) -> tuple[bool, int]:
    """Per-user кап на рассылки. fail-CLOSED на in-process при сбое Redis."""
    key = _key_broadcast(user_id)
    count = await _incr_window(key, BROADCAST_RATE_LIMIT_WINDOW_SECONDS)
    if count is None:
        ok = _fallback_sliding_window(
            key, BROADCAST_RATE_LIMIT_PER_WINDOW, BROADCAST_RATE_LIMIT_WINDOW_SECONDS,
        )
        return ok, (0 if ok else BROADCAST_RATE_LIMIT_WINDOW_SECONDS)
    over = count > BROADCAST_RATE_LIMIT_PER_WINDOW
    return (not over), (BROADCAST_RATE_LIMIT_WINDOW_SECONDS if over else 0)
