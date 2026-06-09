"""Sequence (Эпик 4.1): CRUD шаблонов + просмотр запусков (SequenceRun).

Sequences — многошаговые «cadences». Шаблон создаётся вручную (steps_json как JSON),
запуск — через action_kind='start_sequence' в PipelineAutomation либо вручную
через POST /sequences/{id}/start.

Endpoints:
- GET /sequences            — список шаблонов с фильтром is_active.
- POST /sequences           — создать шаблон (LawyerOrAdmin).
- GET /sequences/{id}       — деталь шаблона.
- PATCH /sequences/{id}     — обновить.
- DELETE /sequences/{id}    — удалить (cascade удалит и runs).
- POST /sequences/{id}/start — запустить SequenceRun вручную.
- GET /sequences/{id}/runs  — история запусков шаблона.
- GET /sequence-runs        — read-only список всех запусков (фильтры).

ACL: LawyerOrAdmin для mutation; CurrentUser для чтения.
"""
from __future__ import annotations

from datetime import UTC, datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, LawyerOrAdmin
from app.models import Sequence, SequenceRun
from app.services.sequence_executor import (
    SEQUENCE_RUN_STATUSES,
    SEQUENCE_STEP_KINDS,
    start_sequence_run,
    validate_steps,
)

router = APIRouter(prefix="/sequences", tags=["sequences"])


# ============ Pydantic-схемы ============


class SequenceOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    description: str | None
    steps_json: list[dict[str, Any]]
    is_active: bool
    created_by_user_id: int | None
    created_at: datetime
    updated_at: datetime
    runs_count: int = 0


class SequenceCreate(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    description: str | None = None
    steps_json: list[dict[str, Any]] = Field(default_factory=list)
    is_active: bool = True


class SequenceUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=255)
    description: str | None = None
    steps_json: list[dict[str, Any]] | None = None
    is_active: bool | None = None


class SequenceStartIn(BaseModel):
    target_type: str
    target_id: int


class SequenceRunOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    sequence_id: int
    target_type: str
    target_id: int
    current_step_index: int
    status: str
    started_at: datetime
    next_step_at: datetime | None
    finished_at: datetime | None
    result_json: list[dict[str, Any]] | None
    sequence_name: str | None = None


# ============ Helpers ============


async def _get_or_404(session: AsyncSession, seq_id: int) -> Sequence:
    seq = (
        await session.execute(select(Sequence).where(Sequence.id == seq_id))
    ).scalar_one_or_none()
    if not seq:
        raise HTTPException(404, "Sequence не найдена")
    return seq


def _validate_steps_or_400(steps: list[dict[str, Any]]) -> None:
    ok, err = validate_steps(steps)
    if not ok:
        raise HTTPException(400, err or "Невалидные шаги")


async def _to_out(session: AsyncSession, seq: Sequence) -> SequenceOut:
    runs_count = (
        await session.execute(
            select(func.count(SequenceRun.id)).where(SequenceRun.sequence_id == seq.id)
        )
    ).scalar_one() or 0
    return SequenceOut(
        id=seq.id,
        name=seq.name,
        description=seq.description,
        steps_json=seq.steps_json or [],
        is_active=seq.is_active,
        created_by_user_id=seq.created_by_user_id,
        created_at=seq.created_at,
        updated_at=seq.updated_at,
        runs_count=int(runs_count),
    )


# ============ Endpoints ============


@router.get("", response_model=list[SequenceOut])
async def list_sequences(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    is_active: bool | None = None,
    limit: int = 100,
    offset: int = 0,
):
    """Список Sequence-шаблонов."""
    stmt = select(Sequence).order_by(Sequence.id.desc())
    if is_active is not None:
        stmt = stmt.where(Sequence.is_active.is_(is_active))
    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))
    rows = (await session.execute(stmt)).scalars().all()
    return [await _to_out(session, s) for s in rows]


@router.post("", response_model=SequenceOut, status_code=status.HTTP_201_CREATED)
async def create_sequence(
    data: SequenceCreate,
    current_user: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать Sequence-шаблон. LawyerOrAdmin.

    steps_json валидируется через validate_steps (kind whitelist, delay >= 0).
    """
    _validate_steps_or_400(data.steps_json)
    seq = Sequence(
        name=data.name,
        description=data.description,
        steps_json=data.steps_json,
        is_active=data.is_active,
        created_by_user_id=current_user.id,
    )
    session.add(seq)
    await session.commit()
    await session.refresh(seq)
    return await _to_out(session, seq)


@router.get("/{seq_id}", response_model=SequenceOut)
async def get_sequence(
    seq_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    seq = await _get_or_404(session, seq_id)
    return await _to_out(session, seq)


@router.patch("/{seq_id}", response_model=SequenceOut)
async def update_sequence(
    seq_id: int,
    data: SequenceUpdate,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    seq = await _get_or_404(session, seq_id)
    patch = data.model_dump(exclude_unset=True)
    if "steps_json" in patch and patch["steps_json"] is not None:
        _validate_steps_or_400(patch["steps_json"])
    for k, v in patch.items():
        setattr(seq, k, v)
    await session.commit()
    await session.refresh(seq)
    return await _to_out(session, seq)


@router.delete("/{seq_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_sequence(
    seq_id: int,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить шаблон Sequence.

    MEDIUM-фикс: запрещаем hard-delete, если есть НЕзавершённые runs
    (pending/running) — иначе CASCADE снёс бы активные cadences и их аудит.
    Сначала отмени активные runs (POST /sequence-runs/{id}/cancel) или дождись
    завершения. Завершённые/cancelled runs каскадно удаляются вместе с шаблоном
    (это аудит, но удаление шаблона — осознанное действие админа).
    """
    seq = await _get_or_404(session, seq_id)
    active_cnt = (
        await session.execute(
            select(func.count(SequenceRun.id)).where(
                SequenceRun.sequence_id == seq_id,
                SequenceRun.status.in_(("pending", "running")),
            )
        )
    ).scalar_one()
    if active_cnt:
        raise HTTPException(
            409,
            f"Нельзя удалить: {active_cnt} активных запусков (pending/running). "
            "Отмени их через POST /sequence-runs/{id}/cancel или дождись завершения.",
        )
    await session.delete(seq)
    await session.commit()


# ============ Bulk-reorder (Tech Sprint Фаза 0) ============


class _StepsReorderIn(BaseModel):
    """body для steps reorder — массив целиком в желаемом порядке."""

    steps_json: list[dict[str, Any]]


@router.patch("/{seq_id}/steps/reorder", response_model=SequenceOut)
async def reorder_sequence_steps(
    seq_id: int,
    payload: _StepsReorderIn,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Bulk-reorder шагов sequence через PATCH steps_json (LawyerOrAdmin).

    Sequence хранит steps как JSON-массив; порядок шагов = индекс в массиве.
    Не отдельная таблица — поэтому reorder = передача нового массива целиком.
    Caller отправляет шаги в желаемом порядке. Валидация через validate_steps
    (whitelist kind, delay >= 0 и т.д.).
    """
    seq = await _get_or_404(session, seq_id)
    _validate_steps_or_400(payload.steps_json)
    seq.steps_json = payload.steps_json
    await session.commit()
    await session.refresh(seq)
    return await _to_out(session, seq)


@router.post("/{seq_id}/start", response_model=SequenceRunOut)
async def start_sequence(
    seq_id: int,
    data: SequenceStartIn,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Запустить SequenceRun вручную (для UI «прикрепить cadence к лиду»)."""
    if data.target_type not in ("deal", "lead", "subscription"):
        raise HTTPException(400, f"Недопустимый target_type: {data.target_type}")
    run = await start_sequence_run(session, seq_id, data.target_type, data.target_id)
    if run is None:
        raise HTTPException(404, "Sequence не найдена или неактивна")
    await session.commit()
    await session.refresh(run)
    return SequenceRunOut(
        id=run.id,
        sequence_id=run.sequence_id,
        target_type=run.target_type,
        target_id=run.target_id,
        current_step_index=run.current_step_index,
        status=run.status,
        started_at=run.started_at,
        next_step_at=run.next_step_at,
        finished_at=run.finished_at,
        result_json=run.result_json,
    )


@router.get("/{seq_id}/runs", response_model=list[SequenceRunOut])
async def list_runs_for_sequence(
    seq_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    status_filter: str | None = None,
    limit: int = 100,
    offset: int = 0,
):
    """История запусков конкретного шаблона. status_filter — pending/running/.../cancelled."""
    await _get_or_404(session, seq_id)
    if status_filter is not None and status_filter not in SEQUENCE_RUN_STATUSES:
        raise HTTPException(
            400,
            f"Недопустимый status: {status_filter}. Ожидается одно из {list(SEQUENCE_RUN_STATUSES)}",
        )
    stmt = (
        select(SequenceRun)
        .where(SequenceRun.sequence_id == seq_id)
        .order_by(SequenceRun.started_at.desc())
    )
    if status_filter is not None:
        stmt = stmt.where(SequenceRun.status == status_filter)
    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))
    rows = (await session.execute(stmt)).scalars().all()
    return [
        SequenceRunOut(
            id=r.id,
            sequence_id=r.sequence_id,
            target_type=r.target_type,
            target_id=r.target_id,
            current_step_index=r.current_step_index,
            status=r.status,
            started_at=r.started_at,
            next_step_at=r.next_step_at,
            finished_at=r.finished_at,
            result_json=r.result_json,
        )
        for r in rows
    ]


# ============ Отдельный роутер для глобального списка SequenceRun ============

runs_router = APIRouter(prefix="/sequence-runs", tags=["sequence-runs"])


@runs_router.get("", response_model=list[SequenceRunOut])
async def list_all_sequence_runs(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    sequence_id: int | None = None,
    target_type: str | None = None,
    target_id: int | None = None,
    status_filter: str | None = None,
    limit: int = 100,
    offset: int = 0,
):
    """Глобальный список SequenceRun с фильтрами. Используется в карточке сделки
    («какие cadences активны на этом deal»)."""
    if target_type is not None and target_type not in ("deal", "lead", "subscription"):
        raise HTTPException(400, f"Недопустимый target_type: {target_type}")
    if status_filter is not None and status_filter not in SEQUENCE_RUN_STATUSES:
        raise HTTPException(400, f"Недопустимый status: {status_filter}")
    stmt = select(SequenceRun).order_by(SequenceRun.started_at.desc())
    if sequence_id is not None:
        stmt = stmt.where(SequenceRun.sequence_id == sequence_id)
    if target_type is not None:
        stmt = stmt.where(SequenceRun.target_type == target_type)
    if target_id is not None:
        stmt = stmt.where(SequenceRun.target_id == target_id)
    if status_filter is not None:
        stmt = stmt.where(SequenceRun.status == status_filter)
    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))
    rows = (await session.execute(stmt)).scalars().all()
    # Денорм sequence_name
    seq_ids = {r.sequence_id for r in rows}
    names: dict[int, str] = {}
    if seq_ids:
        for s in (
            await session.execute(
                select(Sequence).where(Sequence.id.in_(seq_ids))
            )
        ).scalars().all():
            names[s.id] = s.name
    return [
        SequenceRunOut(
            id=r.id,
            sequence_id=r.sequence_id,
            target_type=r.target_type,
            target_id=r.target_id,
            current_step_index=r.current_step_index,
            status=r.status,
            started_at=r.started_at,
            next_step_at=r.next_step_at,
            finished_at=r.finished_at,
            result_json=r.result_json,
            sequence_name=names.get(r.sequence_id),
        )
        for r in rows
    ]


@runs_router.post("/{run_id}/cancel", response_model=SequenceRunOut)
async def cancel_sequence_run(
    run_id: int,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """MEDIUM-фикс: отменить отдельный SequenceRun (status → cancelled).

    Cron-сканер (scan_pending_sequence_runs) выбирает только pending/running,
    так что cancelled run больше не продвигается. Идемпотентно: повторный cancel
    уже cancelled run возвращает его как есть. Завершённые (completed/failed)
    отменять нельзя — 409.
    """
    run = (
        await session.execute(select(SequenceRun).where(SequenceRun.id == run_id))
    ).scalar_one_or_none()
    if run is None:
        raise HTTPException(404, "SequenceRun не найден")
    if run.status == "cancelled":
        pass  # идемпотентно
    elif run.status in ("completed", "failed"):
        raise HTTPException(
            409,
            f"Нельзя отменить завершённый run (статус: {run.status})",
        )
    else:
        run.status = "cancelled"
        run.next_step_at = None
        run.finished_at = datetime.now(UTC)
        await session.commit()
        await session.refresh(run)
    return SequenceRunOut(
        id=run.id,
        sequence_id=run.sequence_id,
        target_type=run.target_type,
        target_id=run.target_id,
        current_step_index=run.current_step_index,
        status=run.status,
        started_at=run.started_at,
        next_step_at=run.next_step_at,
        finished_at=run.finished_at,
        result_json=run.result_json,
    )


@runs_router.get("/step-kinds")
async def get_supported_step_kinds(_: CurrentUser):
    """Whitelist допустимых kind для шагов sequence (для UI dropdown'ов)."""
    return {"kinds": list(SEQUENCE_STEP_KINDS)}
