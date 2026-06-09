"""Эпик 20 — Performance Scale: async background dup-scan + Redis cache.

Раньше POST /api/duplicates/scan был синхронным — для 5000+ записей это
секунды long-polling, блокирующих uvicorn worker. Новый флоу:

1. POST /api/duplicates/scan:
   - Если в Redis есть кеш `dup_scan:{entity_type}` (TTL 3600s) → вернуть
     immediately с from_cache=True. job_id не создаётся.
   - Иначе создать DupScanJob (status=pending), запустить background task
     через asyncio.create_task, вернуть {job_id, status=pending,
     from_cache=False}.
2. GET /api/duplicates/scan/{job_id}: polling из frontend (раз в 2s),
   возвращает {status, result_json (если completed), error_message (failed)}.
3. GET /api/duplicates/scan/recent?entity_type=&limit=10: история сканов
   для UI «когда последний раз сканировали».

Cache TTL = 3600s (1ч). Это не строгий SLA — фронт всё равно показывает
«данные на момент XX:XX», юзер может force-rescan через query param
`?force=true`.

Background task design:
- create_task → НЕ привязан к response request lifecycle (response уходит
  пользователю сразу).
- Внутри task — отдельная DB-сессия через SessionLocal() (нельзя реюзать
  request session, она закрывается при return из endpoint).
- Sentry/log на любую Exception в task → status=failed + error_message.
- Locking: используем pg_advisory_xact_lock на entity-type-specific key,
  чтобы 2 параллельных POST одновременно не запустили одинаковый скан.

Тестируем pure-function парты (cache key, serialization, status transitions).
БД-интеграция test'ится через ручной smoke на dev (заведомо).
"""
from __future__ import annotations

import asyncio
import json
import logging
from datetime import datetime, timezone
from typing import Any

from sqlalchemy import desc, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.db import SessionLocal
from app.models import DupScanJob
from app.services.duplicates import DUPLICATE_ENTITY_TYPES, scan_for_entity
from app.services.redis_client import get_redis

logger = logging.getLogger(__name__)


# ============ Constants ============

# Redis cache TTL для результата скана. 3600s = 1ч — баланс между свежестью
# и избежанием повторных тяжёлых сканов одного entity_type подряд.
REDIS_CACHE_TTL_SECONDS = 3600

# Префикс Redis-ключей — `dup_scan:{entity_type}`. Все ключи живут в общем
# Redis namespace (нет отдельной DB для dup-scan), поэтому осмысленный
# префикс важен для grep'а / FLUSHDB.
REDIS_KEY_PREFIX = "dup_scan:"

# Допустимые statuses (зеркалит CHECK CONSTRAINT в migration 0040).
ALLOWED_STATUSES: frozenset[str] = frozenset(
    {"pending", "running", "completed", "failed"}
)

# Маппинг переходов: какие статусы → в какие можно перевести.
# Это для валидации в update_job_status (защита от race race race).
STATUS_TRANSITIONS: dict[str, frozenset[str]] = {
    "pending": frozenset({"running", "failed"}),
    "running": frozenset({"completed", "failed"}),
    "completed": frozenset(),  # terminal
    "failed": frozenset(),     # terminal
}


# ============ Pure-function helpers ============


def build_cache_key(entity_type: str) -> str:
    """Построить Redis-ключ для кеша скана: `dup_scan:{entity_type}`.

    Pure-функция, тестируется без Redis.
    """
    if not entity_type or not entity_type.strip():
        raise ValueError("entity_type must be non-empty")
    return f"{REDIS_KEY_PREFIX}{entity_type.strip().lower()}"


def serialize_scan_result(groups: list[Any]) -> dict[str, Any]:
    """Сериализовать list[DuplicateGroup] → JSON-совместимый dict.

    Каждая группа уже имеет to_dict() (см. app/services/duplicates.py).
    Pure-функция: тестируется на mock-объектах.
    """
    return {
        "groups": [
            g.to_dict() if hasattr(g, "to_dict") else g
            for g in groups
        ],
        "scanned_at": _now_iso(),
        "group_count": len(groups),
    }


def deserialize_scan_result(raw: str | bytes) -> dict[str, Any] | None:
    """Распарсить кешированный JSON-результат из Redis.

    Возвращает None при невалидном JSON (вместо raise — кеш-промах).
    """
    if not raw:
        return None
    try:
        if isinstance(raw, bytes):
            raw = raw.decode("utf-8")
        return json.loads(raw)
    except (json.JSONDecodeError, UnicodeDecodeError):
        logger.warning("Невалидный JSON в Redis-кеше dup-scan: %r", raw[:80])
        return None


def validate_status_transition(from_status: str, to_status: str) -> None:
    """Проверить, что переход from→to разрешён. Иначе ValueError.

    Pure-функция (без БД). Используется в update_job_status для защиты от
    race conditions (два параллельных worker'а оба пытаются complete).
    """
    if from_status not in ALLOWED_STATUSES:
        raise ValueError(f"Unknown source status: {from_status!r}")
    if to_status not in ALLOWED_STATUSES:
        raise ValueError(f"Unknown target status: {to_status!r}")
    allowed_next = STATUS_TRANSITIONS.get(from_status, frozenset())
    if to_status not in allowed_next:
        raise ValueError(
            f"Invalid transition: {from_status!r} → {to_status!r}. "
            f"Allowed from {from_status!r}: {sorted(allowed_next)}"
        )


def validate_entity_type(entity_type: str) -> None:
    """Проверить, что entity_type входит в whitelist. Иначе ValueError."""
    if entity_type not in DUPLICATE_ENTITY_TYPES:
        raise ValueError(
            f"Invalid entity_type: {entity_type!r}. "
            f"Allowed: {sorted(DUPLICATE_ENTITY_TYPES)}"
        )


def _now_iso() -> str:
    """Текущее UTC-время в ISO 8601 (для timestamps в JSON-результате)."""
    return datetime.now(timezone.utc).isoformat()


# ============ Redis cache helpers ============


async def get_cached_result(entity_type: str) -> dict[str, Any] | None:
    """Получить кешированный результат из Redis (если есть и не expired).

    Возвращает None если: кеш-мисс, Redis недоступен (Noop fallback), битый
    JSON. Безопасно вызывать без Redis настроенного — вернётся None всегда.
    """
    try:
        validate_entity_type(entity_type)
    except ValueError:
        return None

    key = build_cache_key(entity_type)
    redis = get_redis()
    try:
        raw = await redis.get(key)
    except Exception as e:  # noqa: BLE001 — Redis-операция, любые сетевые ошибки
        logger.warning("Redis GET %r failed: %s", key, e)
        return None
    if raw is None:
        return None
    return deserialize_scan_result(raw)


async def set_cached_result(
    entity_type: str, result: dict[str, Any]
) -> bool:
    """Записать результат скана в Redis с TTL.

    Возвращает True если запись успешна; False если Redis недоступен или
    ошибка сериализации. Не raises (best-effort cache).
    """
    try:
        validate_entity_type(entity_type)
    except ValueError:
        return False
    key = build_cache_key(entity_type)
    try:
        payload = json.dumps(result, ensure_ascii=False, default=str)
    except (TypeError, ValueError) as e:
        logger.warning("Сериализация dup-scan result в JSON упала: %s", e)
        return False
    redis = get_redis()
    try:
        await redis.set(key, payload, ex=REDIS_CACHE_TTL_SECONDS)
        return True
    except Exception as e:  # noqa: BLE001
        logger.warning("Redis SET %r failed: %s", key, e)
        return False


async def invalidate_cache(entity_type: str) -> None:
    """Принудительно очистить кеш для entity_type (force-rescan).

    Best-effort. Не raises при ошибках Redis.
    """
    try:
        validate_entity_type(entity_type)
    except ValueError:
        return
    key = build_cache_key(entity_type)
    redis = get_redis()
    try:
        await redis.delete(key)
    except Exception as e:  # noqa: BLE001
        logger.warning("Redis DELETE %r failed: %s", key, e)


# ============ Job CRUD ============


async def create_job(
    session: AsyncSession,
    *,
    entity_type: str,
    triggered_by_user_id: int | None,
) -> DupScanJob:
    """Создать новую DupScanJob (status=pending). Возвращает persisted объект.

    НЕ commit'ит сессию — caller отвечает за транзакцию (или используем
    session.flush() + commit отдельно).
    """
    validate_entity_type(entity_type)
    job = DupScanJob(
        entity_type=entity_type,
        status="pending",
        triggered_by_user_id=triggered_by_user_id,
    )
    session.add(job)
    await session.flush()  # получить job.id
    return job


async def get_job(session: AsyncSession, job_id: int) -> DupScanJob | None:
    """Получить DupScanJob по id (или None)."""
    return (
        await session.execute(
            select(DupScanJob).where(DupScanJob.id == job_id)
        )
    ).scalar_one_or_none()


async def list_recent_jobs(
    session: AsyncSession,
    *,
    entity_type: str | None = None,
    limit: int = 10,
) -> list[DupScanJob]:
    """Последние N сканов (опционально фильтр по entity_type).

    Сортировка: started_at DESC. Limit обрезается до [1, 50].
    """
    limit = max(1, min(50, int(limit)))
    stmt = select(DupScanJob)
    if entity_type:
        validate_entity_type(entity_type)
        stmt = stmt.where(DupScanJob.entity_type == entity_type)
    stmt = stmt.order_by(desc(DupScanJob.started_at)).limit(limit)
    return list((await session.execute(stmt)).scalars().all())


async def update_job_status(
    session: AsyncSession,
    job: DupScanJob,
    *,
    new_status: str,
    result_json: dict[str, Any] | None = None,
    error_message: str | None = None,
) -> None:
    """Обновить status джобы с валидацией transition.

    - При new_status='completed' — установить completed_at = now, result_json.
    - При new_status='failed' — установить completed_at = now, error_message.
    - При new_status='running' — только status.

    Raises ValueError если transition не разрешён. НЕ commit'ит — caller
    отвечает за транзакцию.
    """
    validate_status_transition(job.status, new_status)
    job.status = new_status
    if new_status == "completed":
        job.completed_at = datetime.now(timezone.utc)
        if result_json is not None:
            job.result_json = result_json
    elif new_status == "failed":
        job.completed_at = datetime.now(timezone.utc)
        if error_message:
            job.error_message = error_message[:4000]  # cap на разумный лимит
    elif new_status == "running":
        # ничего дополнительно
        pass
    await session.flush()


# ============ Background task ============


async def _run_scan_in_background(job_id: int) -> None:
    """Worker: открыть отдельную DB-сессию, выполнить скан, сохранить результат.

    Запускается через asyncio.create_task из endpoint'а. НЕ привязан к
    lifecycle request'а — response уйдёт юзеру сразу, task выполнится потом.

    Гарантии:
    - Любая Exception → job.status='failed' + error_message (best-effort).
    - Успех → job.status='completed' + result_json + Redis cache (best-effort).
    - Сессия отдельная (SessionLocal) — её закрытие управляется этим
      task'ом, не request'ом.
    """
    logger.info("dup-scan job=%d: starting background scan", job_id)
    async with SessionLocal() as session:
        # 1. Загрузить job, проставить running.
        job = await get_job(session, job_id)
        if job is None:
            logger.error("dup-scan job=%d: not found in DB, abort", job_id)
            return
        try:
            await update_job_status(session, job, new_status="running")
            await session.commit()
        except Exception as e:  # noqa: BLE001
            logger.exception("dup-scan job=%d: failed to set running: %s", job_id, e)
            await session.rollback()
            return

        # 2. Выполнить scan_for_entity (тяжёлая работа).
        try:
            groups = await scan_for_entity(session, job.entity_type)
            result = serialize_scan_result(groups)
        except Exception as e:  # noqa: BLE001
            logger.exception(
                "dup-scan job=%d: scan_for_entity failed: %s", job_id, e
            )
            # Перезагрузить job (running уже закоммитили) и пометить failed.
            await session.rollback()
            job = await get_job(session, job_id)
            if job is None:
                return
            try:
                await update_job_status(
                    session, job,
                    new_status="failed",
                    error_message=f"{type(e).__name__}: {e}",
                )
                await session.commit()
            except Exception as inner:  # noqa: BLE001
                logger.exception(
                    "dup-scan job=%d: failed to set failed: %s", job_id, inner
                )
                await session.rollback()
            return

        # 3. Сохранить result + completed.
        try:
            await update_job_status(
                session, job,
                new_status="completed",
                result_json=result,
            )
            await session.commit()
        except Exception as e:  # noqa: BLE001
            logger.exception(
                "dup-scan job=%d: failed to save result: %s", job_id, e
            )
            await session.rollback()
            return

        # 4. Best-effort: записать в Redis cache. Failure здесь некритична.
        cached = await set_cached_result(job.entity_type, result)
        logger.info(
            "dup-scan job=%d: completed in background, cached=%s, groups=%d",
            job_id, cached, result.get("group_count", 0),
        )


def schedule_scan_task(job_id: int) -> asyncio.Task[None]:
    """Запустить background task для скана. Возвращает Task object.

    Caller обычно НЕ ждёт результат — task fire-and-forget. asyncio сам
    GC'ит завершённые task'и; для долгоживущих можно прокинуть в registry,
    но для скана это излишне.
    """
    return asyncio.create_task(_run_scan_in_background(job_id))
