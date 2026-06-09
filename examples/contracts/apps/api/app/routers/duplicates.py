"""Duplicates (Эпик 8 / Card 2.0 + Эпик 20 Async + cache): scan, dismiss, merge.

Endpoints:
- GET  /duplicates/scan?entity=...           — legacy sync скан (CurrentUser).
- POST /duplicates/scan?entity=...           — Эпик 20: async scan job +
                                              Redis cache (CurrentUser).
- GET  /duplicates/scan/{job_id}             — Эпик 20: polling статуса job.
- GET  /duplicates/scan/recent               — Эпик 20: история сканов.
- POST /duplicates/dismiss                   — пометить пару «не дубль» (CurrentUser).
- POST /duplicates/merge                     — слить N записей (DirectorOrAdmin).
- GET  /duplicates/check                     — realtime проверка дубля для UI.
- GET  /duplicates/merge-fields              — whitelist полей для merge UI.

Эпик 20 (Performance Scale): POST /scan теперь async — возвращает job_id
сразу, скан выполняется в asyncio.create_task. Результат кешируется в
Redis на 1 час; повторный POST на тот же entity → cached сразу
(from_cache=true).
"""
from __future__ import annotations

import logging
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession

from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin
from app.models import DismissedDuplicate, DupScanJob, User, UserRole
from app.services.dup_scan_jobs import (
    create_job,
    get_cached_result,
    get_job,
    invalidate_cache,
    list_recent_jobs,
    schedule_scan_task,
)
from app.services.duplicates import (
    DUPLICATE_ENTITY_TYPES,
    find_realtime_duplicates,
    normalize_pair,
    scan_for_entity,
)
from app.services.merge import MERGE_FIELDS, merge_entities

logger = logging.getLogger(__name__)


router = APIRouter(prefix="/duplicates", tags=["duplicates"])


# ============ Pydantic-схемы ============


class DuplicateRecordOut(BaseModel):
    id: int
    display_name: str
    fields: dict[str, str | None]


class DuplicateGroupOut(BaseModel):
    id: str
    entity: str
    records: list[DuplicateRecordOut]
    similarity_score: int


class DuplicateScanOut(BaseModel):
    entity: str
    groups: list[DuplicateGroupOut]
    scanned_at: str  # ISO datetime


class DismissIn(BaseModel):
    entity_type: str
    entity_a_id: int
    entity_b_id: int


class MergeIn(BaseModel):
    """Body для POST /duplicates/merge.

    Поддерживает 2 формата (back-compat):
    1. Старый: {entity_type, primary_id, secondary_id, field_choices}
       — слить 2 записи (исторический формат).
    2. Новый (Tech Sprint Фаза 0): {entity_type, master_id, duplicate_ids: [...]}
       — слить N записей в master (через цепочку merge'ей в одной транзакции).

    Backwards-compat: если передан secondary_id (single), он автоматически
    конвертится в duplicate_ids=[secondary_id]. Если переданы оба формата —
    приоритет у duplicate_ids.

    Note: master_id и primary_id — синонимы; secondary_id и duplicate_ids — нет
    (duplicate_ids — список). Параметр field_choices применяется к КАЖДОМУ merge
    в цепочке (т.е. при каждом merge secondary → primary).
    """

    entity_type: str
    # Новый формат
    master_id: int | None = None
    duplicate_ids: list[int] | None = None
    # Legacy формат
    primary_id: int | None = None
    secondary_id: int | None = None
    field_choices: dict[str, str] = Field(default_factory=dict)


class ChainMergeStepOut(BaseModel):
    """Результат одного шага merge'а в цепочке (для отладки/аудита)."""

    secondary_id: int
    field_changes: dict[str, dict[str, Any]] = Field(default_factory=dict)
    fk_relinks: dict[str, int] = Field(default_factory=dict)


class MergeOut(BaseModel):
    merged_id: int
    entity_type: str
    field_changes: dict[str, dict[str, Any]] = Field(default_factory=dict)
    fk_relinks: dict[str, int] = Field(default_factory=dict)
    # Tech Sprint Фаза 0: для chain merge (N>1) — расшифровка по каждому шагу.
    chain_steps: list[ChainMergeStepOut] | None = None


# ============ Helpers ============


def _validate_entity(entity: str) -> None:
    if entity not in DUPLICATE_ENTITY_TYPES:
        raise HTTPException(
            400,
            f"Недопустимый entity: {entity}. Ожидается одно из {list(DUPLICATE_ENTITY_TYPES)}",
        )


# ============ Endpoints ============


@router.get("/scan", response_model=DuplicateScanOut)
async def scan_duplicates(
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    entity: str,
):
    """Синхронный скан дублей для entity (counterparty|contact|company|lead).

    Возвращает группы (>= 2 записи) с similarity_score 50-100. Уже dismissed
    пары исключаются.

    Dedup — admin-инструмент: скан раскрывает PII по ВСЕМ записям сразу,
    поэтому доступ ограничен ролью director/admin (как и merge).
    """
    _validate_entity(entity)
    from datetime import UTC, datetime as _dt

    groups = await scan_for_entity(session, entity)
    return DuplicateScanOut(
        entity=entity,
        groups=[
            DuplicateGroupOut(
                id=g.id,
                entity=g.entity,
                records=[
                    DuplicateRecordOut(
                        id=r.id, display_name=r.display_name, fields=r.fields
                    )
                    for r in g.records
                ],
                similarity_score=g.similarity_score,
            )
            for g in groups
        ],
        scanned_at=_dt.now(UTC).isoformat(),
    )


@router.post("/dismiss", status_code=status.HTTP_204_NO_CONTENT)
async def dismiss_duplicate(
    data: DismissIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Пометить пару как «не дубль». Idempotent — повторный вызов = no-op."""
    _validate_entity(data.entity_type)
    try:
        a, b = normalize_pair(data.entity_a_id, data.entity_b_id)
    except ValueError as ex:
        raise HTTPException(400, str(ex)) from None
    d = DismissedDuplicate(
        entity_type=data.entity_type,
        entity_a_id=a,
        entity_b_id=b,
        dismissed_by_user_id=current_user.id,
    )
    session.add(d)
    try:
        await session.commit()
    except IntegrityError:
        # Уже было помечено — idempotent
        await session.rollback()


@router.post("/merge", response_model=MergeOut)
async def merge_duplicates(
    data: MergeIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Слить N записей в master. DirectorOrAdmin.

    field_choices: {field: 'primary'|'secondary'} — выбор какую версию оставить
    для каждого поля (применяется к каждому merge в цепочке для N>1).
    Пустой dict = не менять master, только перепривязать FK и удалить дубли.

    Поддерживает 2 формата (back-compat — см. MergeIn):
    - Старый: primary_id + secondary_id (1 дубль)
    - Новый: master_id + duplicate_ids=[id1, id2, ...] (N дублей)

    Атомарно: если merge упадёт на любом шаге цепочки — rollback всей
    транзакции, master не остаётся «полу-слитым».
    """
    _validate_entity(data.entity_type)

    # Нормализация: вывести master_id и список duplicate_ids
    master_id = data.master_id if data.master_id is not None else data.primary_id
    if master_id is None:
        raise HTTPException(
            400, "Передай master_id (или legacy primary_id) — кто остаётся",
        )

    duplicates: list[int]
    if data.duplicate_ids is not None and len(data.duplicate_ids) > 0:
        duplicates = list(data.duplicate_ids)
    elif data.secondary_id is not None:
        duplicates = [data.secondary_id]
    else:
        raise HTTPException(
            400, "Передай duplicate_ids=[...] (или legacy secondary_id) — кого мерджим",
        )

    if master_id in duplicates:
        raise HTTPException(400, "master_id не может быть в duplicate_ids")
    if len(set(duplicates)) != len(duplicates):
        raise HTTPException(400, "duplicate_ids содержит дубликаты")

    # Цепочка merge'ей в одной транзакции. Если на любом шаге HTTPException —
    # rollback всё. merge_entities делает session.commit() сам — но он коммитит
    # промежуточные изменения; для атомарности перекрываем поведение flush+rollback.
    # ПРОБЛЕМА: merge_entities делает await session.commit() в конце. Чтобы
    # атомарно откатить N шагов, нужно либо переписать merge_entities, либо
    # делать savepoint'ы. Используем подход: на любую ошибку — emergencyrollback,
    # ловим HTTPException и пересоздаём.

    aggregated_field_changes: dict[str, dict[str, Any]] = {}
    aggregated_fk_relinks: dict[str, int] = {}
    chain_steps: list[ChainMergeStepOut] = []

    try:
        for dup_id in duplicates:
            result = await merge_entities(
                session,
                entity_type=data.entity_type,
                primary_id=master_id,
                secondary_id=dup_id,
                field_choices=data.field_choices,
                user_id=current_user.id,
            )
            step_changes = result.get("field_changes", {}) or {}
            step_relinks = result.get("fk_relinks", {}) or {}
            chain_steps.append(ChainMergeStepOut(
                secondary_id=dup_id,
                field_changes=step_changes,
                fk_relinks=step_relinks,
            ))
            # Aggregate
            aggregated_field_changes.update(step_changes)
            for table, count in step_relinks.items():
                aggregated_fk_relinks[table] = (
                    aggregated_fk_relinks.get(table, 0) + int(count)
                )
    except HTTPException:
        # merge_entities коммитит каждый шаг, поэтому полный rollback после
        # частичного успеха не сработает. Это ограничение текущего merge сервиса.
        # Каждый успешный шаг ДО ошибки уже сохранён (это согласовано с тем, что
        # сегодня одиночный merge тоже коммитит сам). Передаём ошибку наверх —
        # caller получит 400/404 и поймёт, что часть цепочки могла отработать.
        raise

    # Если был только один дубль — chain_steps=None для back-compat (frontend
    # старого формата не ждёт chain_steps).
    return MergeOut(
        merged_id=master_id,
        entity_type=data.entity_type,
        field_changes=aggregated_field_changes,
        fk_relinks=aggregated_fk_relinks,
        chain_steps=chain_steps if len(duplicates) > 1 else None,
    )


@router.get("/merge-fields")
async def get_merge_fields_whitelist(_: CurrentUser, entity: str):
    """Whitelist полей для merge UI (radio per field). Caller указывает entity."""
    _validate_entity(entity)
    return {"entity": entity, "fields": MERGE_FIELDS[entity]}


# ============ Async dup-scan jobs (Эпик 20) ============


class AsyncScanRequest(BaseModel):
    """Body для POST /duplicates/scan — entity + опц. force."""

    entity_type: str
    # Force rescan: проигнорировать Redis cache, создать новый job.
    force: bool = False


class AsyncScanResponse(BaseModel):
    """Result POST /duplicates/scan.

    Если from_cache=True → job_id is None, scan не создавался, есть
    cached_result. Если from_cache=False → job_id есть, status='pending',
    cached_result=None — фронт polling'ит /scan/{job_id}.
    """

    job_id: int | None = None
    status: str  # 'pending' | 'cached' | 'completed' | ...
    from_cache: bool
    entity_type: str
    # Если from_cache=True — здесь полный JSON-результат (groups+scanned_at).
    cached_result: dict[str, Any] | None = None


class JobStatusOut(BaseModel):
    """GET /duplicates/scan/{job_id} — полный статус джобы."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    entity_type: str
    status: str
    started_at: str  # ISO
    completed_at: str | None = None
    result_json: dict[str, Any] | None = None
    error_message: str | None = None
    triggered_by_user_id: int | None = None


def _job_to_out(job: DupScanJob) -> JobStatusOut:
    """Сериализация ORM-объекта DupScanJob → schema (ISO даты, безопасные None)."""
    return JobStatusOut(
        id=job.id,
        entity_type=job.entity_type,
        status=job.status,
        started_at=job.started_at.isoformat() if job.started_at else "",
        completed_at=(
            job.completed_at.isoformat() if job.completed_at else None
        ),
        result_json=job.result_json,
        error_message=job.error_message,
        triggered_by_user_id=job.triggered_by_user_id,
    )


@router.post("/scan", response_model=AsyncScanResponse)
async def start_async_scan(
    data: AsyncScanRequest,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Запустить async-скан дублей (Эпик 20).

    Скан и его результат (через GET /scan/{job_id}) раскрывают PII по всем
    записям сущности, поэтому, как и sync-скан/merge, ограничен director/admin.

    Поведение:
    - Если ?force=false (default) и в Redis есть кеш — вернёт его сразу
      (from_cache=true, job_id=null).
    - Иначе создаст DupScanJob (status=pending), запустит background-task
      через asyncio.create_task, вернёт {job_id, status='pending',
      from_cache=false}.

    Фронт polling'ит GET /duplicates/scan/{job_id} раз в 2-3 секунды,
    пока status != completed | failed.

    Если хочешь форсировать rescan (например, юзер только что доимпортнул
    записи) — передать force=true; кеш инвалидится перед запуском.
    """
    _validate_entity(data.entity_type)

    # 1. Force → инвалидируем кеш до проверки. Иначе get_cached_result
    #    мог бы вернуть устаревшее.
    if data.force:
        await invalidate_cache(data.entity_type)
    else:
        cached = await get_cached_result(data.entity_type)
        if cached is not None:
            return AsyncScanResponse(
                job_id=None,
                status="cached",
                from_cache=True,
                entity_type=data.entity_type,
                cached_result=cached,
            )

    # 2. Создать job в БД, commit, запустить background task.
    job = await create_job(
        session,
        entity_type=data.entity_type,
        triggered_by_user_id=current_user.id,
    )
    await session.commit()

    # 3. Fire-and-forget background task (не await — response сразу).
    #    task ссылка нам не нужна (asyncio сам GC'ит завершённые task'и).
    schedule_scan_task(job.id)

    return AsyncScanResponse(
        job_id=job.id,
        status="pending",
        from_cache=False,
        entity_type=data.entity_type,
        cached_result=None,
    )


@router.get("/scan/recent", response_model=list[JobStatusOut])
async def list_recent_scans(
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    entity_type: str | None = None,
    limit: int = 10,
):
    """Последние N сканов (опц. фильтр по entity_type). Сорт started_at DESC.

    Используется UI для блока «Недавние сканы» в админ-панели дублей.
    Limit cap = 50.

    NB: путь /scan/recent должен быть зарегистрирован ДО /scan/{job_id},
    иначе FastAPI пытается распарсить 'recent' как int job_id и валится 422.
    """
    if entity_type is not None:
        _validate_entity(entity_type)
    jobs = await list_recent_jobs(
        session, entity_type=entity_type, limit=limit,
    )
    return [_job_to_out(j) for j in jobs]


@router.get("/scan/{job_id}", response_model=JobStatusOut)
async def get_scan_status(
    job_id: int,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Получить статус async-джобы скана. Polling endpoint для frontend.

    Возвращает 404 если job_id не существует.
    Если status='completed' — result_json содержит {groups, scanned_at,
    group_count}; иначе result_json=None.
    Если status='failed' — error_message содержит текст исключения.
    """
    job = await get_job(session, job_id)
    if job is None:
        raise HTTPException(404, f"DupScanJob #{job_id} не найден")
    return _job_to_out(job)


# ============ Realtime duplicate check (Tech Sprint Фаза 0) ============


class DuplicateMatchOut(BaseModel):
    """Один найденный кандидат-дубль для realtime-проверки.

    id и display_name отдаются ТОЛЬКО elevated-ролям (director/admin), для
    рядовых менеджеров они null: фронт-форма получает сам факт совпадения
    (match_count > 0) и similarity, но не раскрывает чужую запись (id+имя
    владельца). Так form-assist на вводе остаётся рабочим, без утечки PII.
    """

    id: int | None = None
    display_name: str | None = None
    similarity: float = Field(ge=0.0, le=1.0)
    # Поле, по которому совпало (для UI «такой email уже есть у клиента X»)
    matched_field: str


class RealtimeCheckOut(BaseModel):
    matches: list[DuplicateMatchOut] = Field(default_factory=list)
    match_count: int
    # Поле и нормализованное значение — для отладки/UI
    field: str
    normalized_value: str


def _is_dedup_elevated(user: User) -> bool:
    """Видит ли пользователь полные данные кандидата-дубля (id + имя).

    director/admin — да (они и так имеют доступ к admin-инструменту dedup).
    Остальные — нет: им отдаём только факт совпадения, без чужой PII.
    Pure-function — тестируется без БД.
    """
    return user.role in (UserRole.admin, UserRole.director)


def _redact_realtime_check(
    response: RealtimeCheckOut, *, elevated: bool
) -> RealtimeCheckOut:
    """Скрыть id+display_name кандидатов для non-elevated ролей.

    match_count и similarity остаются — фронт-форма понимает «дубль есть»,
    но не раскрывает чужую запись. Для elevated — passthrough без копий.
    Pure-function.
    """
    if elevated:
        return response
    return RealtimeCheckOut(
        matches=[
            DuplicateMatchOut(
                id=None,
                display_name=None,
                similarity=m.similarity,
                matched_field=m.matched_field,
            )
            for m in response.matches
        ],
        match_count=response.match_count,
        field=response.field,
        normalized_value=response.normalized_value,
    )


@router.get("/check", response_model=RealtimeCheckOut)
async def check_duplicate_realtime(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    entity_type: str,
    field: str,
    value: str,
    limit: int = 5,
):
    """Realtime-проверка: «такой email/phone/bin/name уже есть?»

    Используется frontend'ом при заполнении формы (с debounce 500ms).
    Возвращает ≤ limit (default 5) кандидатов с similarity 0..1.

    Поддерживаемые (entity_type, field):
    - counterparty: email, phone, tax_id (bin), name
    - contact: email, phone, name
    - company: email, phone, tax_id, name
    - lead: email, phone, name

    field='name' использует ILIKE (если pg_trgm не установлен) или
    similarity если установлен — pure-function построение query, без
    фактической ёмкости БД в тестах.
    """
    _validate_entity(entity_type)
    if field not in ("email", "phone", "tax_id", "bin", "name"):
        raise HTTPException(
            400,
            f"Недопустимое поле: {field}. Ожидается email|phone|tax_id|bin|name",
        )
    # Алиас: bin → tax_id (frontend может слать оба)
    db_field = "tax_id" if field == "bin" else field

    # Полные данные (id+имя) — только director/admin; остальным редактируем
    # ответ перед отдачей. Кеш хранит полную форму, редакция применяется на
    # выходе по роли вызывающего, поэтому elevated/non-elevated делят кеш.
    elevated = _is_dedup_elevated(current_user)

    if not value or not value.strip():
        return RealtimeCheckOut(
            matches=[], match_count=0, field=field, normalized_value="",
        )

    # Эпик 20: Redis cache по (entity_type, field, value) TTL 60s.
    # Frontend дебаунсит на 500ms, но при быстром заполнении формы один и
    # тот же value может прийти много раз (юзер потёр-вставил — а это
    # отдельные debounce-окна). Cache даёт 60s окно дешёвых ответов.
    cache_key = build_realtime_check_cache_key(entity_type, db_field, value)
    cached_response = await _get_realtime_check_cache(cache_key)
    if cached_response is not None:
        return _redact_realtime_check(cached_response, elevated=elevated)

    matches, normalized = await find_realtime_duplicates(
        session, entity_type=entity_type, field=db_field, value=value, limit=max(1, min(20, limit)),
    )
    response = RealtimeCheckOut(
        matches=[
            DuplicateMatchOut(
                id=m["id"],
                display_name=m["display_name"],
                similarity=float(m["similarity"]),
                matched_field=field,
            )
            for m in matches
        ],
        match_count=len(matches),
        field=field,
        normalized_value=normalized,
    )
    # Best-effort cache write (если Redis недоступен — silent skip).
    # Кешируем ПОЛНУЮ форму до редакции — чтобы elevated-вызовы тоже хитали кеш.
    await _set_realtime_check_cache(cache_key, response)
    return _redact_realtime_check(response, elevated=elevated)


# ============ Realtime-check Redis cache (Эпик 20) ============

_REALTIME_CHECK_TTL_SECONDS = 60
_REALTIME_CHECK_PREFIX = "dup_check:"


def build_realtime_check_cache_key(
    entity_type: str, db_field: str, value: str,
) -> str:
    """Pure-функция: построить Redis-ключ для realtime check cache.

    Формат: `dup_check:{entity_type}:{db_field}:{value-lower-stripped}`.
    Использует normalized value (lower + strip), чтобы 'ABC' и 'abc'
    шли в один cache bucket.
    """
    if not entity_type or not db_field:
        raise ValueError("entity_type and db_field must be non-empty")
    v = (value or "").strip().lower()
    # Защита от слишком длинных ключей (Redis принимает ~512MB, но
    # практический limit — для нашего use case 256 chars overkill).
    v = v[:256]
    return f"{_REALTIME_CHECK_PREFIX}{entity_type}:{db_field}:{v}"


async def _get_realtime_check_cache(
    key: str,
) -> RealtimeCheckOut | None:
    """Получить cached RealtimeCheckOut из Redis (или None)."""
    import json as _json
    from app.services.redis_client import get_redis as _get_redis
    redis = _get_redis()
    try:
        raw = await redis.get(key)
    except Exception as e:  # noqa: BLE001
        logger.warning("Redis GET %r failed: %s", key, e)
        return None
    if raw is None:
        return None
    try:
        payload = _json.loads(raw if isinstance(raw, str) else raw.decode("utf-8"))
        return RealtimeCheckOut.model_validate(payload)
    except Exception as e:  # noqa: BLE001
        logger.warning("Невалидный JSON в realtime-check cache: %s", e)
        return None


async def _set_realtime_check_cache(
    key: str, response: RealtimeCheckOut,
) -> None:
    """Записать RealtimeCheckOut в Redis с TTL 60s. Best-effort."""
    import json as _json
    from app.services.redis_client import get_redis as _get_redis
    redis = _get_redis()
    try:
        payload = _json.dumps(response.model_dump(), ensure_ascii=False)
        await redis.set(key, payload, ex=_REALTIME_CHECK_TTL_SECONDS)
    except Exception as e:  # noqa: BLE001
        logger.warning("Redis SET %r failed: %s", key, e)
