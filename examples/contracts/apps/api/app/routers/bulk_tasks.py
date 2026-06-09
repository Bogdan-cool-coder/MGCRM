"""BulkTask CRUD + запуск bulk-генерации документов (Эпик 6 MVP).

Эндпоинты:
- GET    /bulk-tasks                          — список (с фильтрами kind/status/mine)
- POST   /bulk-tasks/generate-documents       — создать задачу + запустить async
- GET    /bulk-tasks/{id}                     — карточка задачи (с прогрессом)
- GET    /bulk-tasks/{id}/download            — скачать .zip (если status='success')
- DELETE /bulk-tasks/{id}                     — отмена (pending/running → cancelled)
                                                либо удаление записи (finished)

Авторизация — LawyerOrAdmin (admin/lawyer создают и видят все; админ — может
скачивать чужие). Менеджеры в MVP не работают с bulk — отложено до Эпика 10.

Async через FastAPI BackgroundTasks: задача выполняется в той же uvicorn-реплике,
которая приняла запрос. В проде scale=2 — race-condition при дабл-клике
обрабатывается через монотонные status (см. bulk_generator). Полная queue —
выход в Celery/RQ — план эпика 12 (отдельный воркер).
"""
from __future__ import annotations

from datetime import datetime
from pathlib import Path
from typing import Annotated

from fastapi import (
    APIRouter,
    BackgroundTasks,
    Depends,
    HTTPException,
    Query,
    status,
)
from fastapi.responses import FileResponse
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, LawyerOrAdmin, load_bulk_task, require_owner_or_role
from app.models import BulkTask, UserRole
from app.services.bulk_generator import (
    BULK_KINDS,
    BULK_STATUSES,
    BULK_TARGET_TYPES,
    execute_bulk_generation,
)

router = APIRouter(prefix="/bulk-tasks", tags=["bulk-tasks"])

# Эпик 0 (RBAC централизация, май 2026): единый dep для get/download/delete bulk-task.
# Manager видит/качает только свои; admin/director/lawyer — все. Идентично прежним
# inline-проверкам, но в одном месте.
_RequireBulkTaskOwner = Depends(
    require_owner_or_role(
        load_bulk_task,
        owner_field="created_by_user_id",
        elevated=(UserRole.admin, UserRole.director, UserRole.lawyer),
    )
)


# ============ Pydantic schemas ============


class BulkTaskOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    kind: str
    status: str
    template_code: str | None
    target_type: str
    target_ids: list[int]
    total_count: int
    success_count: int
    failed_count: int
    result_zip_path: str | None
    error_text: str | None
    created_by_user_id: int | None
    created_at: datetime
    started_at: datetime | None
    finished_at: datetime | None


class BulkGenerateRequest(BaseModel):
    """Тело POST /bulk-tasks/generate-documents."""
    template_code: str = Field(min_length=1, max_length=64)
    target_type: str = Field(min_length=1, max_length=32)
    target_ids: list[int] = Field(min_length=1, max_length=500)


class BulkGenerateResponse(BaseModel):
    task_id: int
    status: str
    total_count: int


# ============ Endpoints ============


@router.get("", response_model=list[BulkTaskOut])
async def list_bulk_tasks(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    kind: str | None = Query(None, description="фильтр по kind"),
    status_eq: str | None = Query(None, alias="status", description="фильтр по статусу"),
    mine: bool = Query(False, description="только мои задачи (created_by_user_id = current)"),
    limit: int = Query(100, ge=1, le=500),
):
    """Список задач (по умолчанию — все, доступно LawyerOrAdmin; manager видит только свои)."""
    stmt = select(BulkTask).order_by(BulkTask.created_at.desc()).limit(limit)
    if kind:
        if kind not in BULK_KINDS:
            raise HTTPException(400, f"unknown kind: {kind}")
        stmt = stmt.where(BulkTask.kind == kind)
    if status_eq:
        if status_eq not in BULK_STATUSES:
            raise HTTPException(400, f"unknown status: {status_eq}")
        stmt = stmt.where(BulkTask.status == status_eq)
    # mine=True → принудительно «только мои» для любой роли. Иначе manager автоматически
    # фильтруется через scope_to_user (admin/director/lawyer видят всё).
    if mine:
        stmt = stmt.where(BulkTask.created_by_user_id == current_user.id)
    else:
        from app.deps import scope_to_user
        stmt = scope_to_user(stmt, BulkTask, current_user, "created_by_user_id")
    rows = (await session.execute(stmt)).scalars().all()
    return list(rows)


@router.post(
    "/generate-documents",
    response_model=BulkGenerateResponse,
    status_code=status.HTTP_201_CREATED,
)
async def generate_documents(
    payload: BulkGenerateRequest,
    background_tasks: BackgroundTasks,
    current_user: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать BulkTask(pending) + запустить execute_bulk_generation в фоне.

    Возвращает task_id. Прогресс — через GET /bulk-tasks/{id}, скачивание — через
    GET /bulk-tasks/{id}/download (когда status='success').
    """
    # Эпик 13: soft-gate — overdue mandatory курсы с completion_policy='soft_gate'
    # блокируют bulk-действия. Юзеру предлагаем сначала закрыть онбординг.
    from app.services.onboarding.progress import enforce_soft_gate
    if not await enforce_soft_gate(session, current_user, "bulk_generate_documents"):
        raise HTTPException(
            403,
            {
                "code": "onboarding_required",
                "message": (
                    "Заверши обязательные курсы онбординга — есть просроченные. "
                    "Открой раздел «Обучение» (/onboarding)."
                ),
            },
        )

    if payload.target_type not in BULK_TARGET_TYPES:
        raise HTTPException(
            400, f"target_type должен быть одним из: {sorted(BULK_TARGET_TYPES)}",
        )
    # Дедуп target_ids — пользователь мог случайно передать дубли
    target_ids = sorted({int(x) for x in payload.target_ids if int(x) > 0})
    if not target_ids:
        raise HTTPException(400, "target_ids не должен быть пустым")

    task = BulkTask(
        kind="document_generation",
        status="pending",
        template_code=payload.template_code,
        target_type=payload.target_type,
        target_ids=target_ids,
        total_count=len(target_ids),
        success_count=0,
        failed_count=0,
        created_by_user_id=current_user.id,
    )
    session.add(task)
    await session.commit()

    # Запуск в фоне — не блокируем response. Сессия закрыта; execute_bulk_generation
    # открывает свою через SessionLocal.
    background_tasks.add_task(execute_bulk_generation, task.id)

    return BulkGenerateResponse(
        task_id=task.id, status=task.status, total_count=task.total_count,
    )


@router.get("/{bulk_task_id}", response_model=BulkTaskOut)
async def get_bulk_task(
    bulk_task_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    task: Annotated[BulkTask, _RequireBulkTaskOwner],
):
    """Карточка задачи. Менеджер видит только свои.

    URL остался `/bulk-tasks/{task_id}` для клиентов — переименовали внутреннее имя
    параметра в `bulk_task_id`, чтобы единый dep `require_owner_or_role(load_bulk_task)`
    мог его подцепить (см. app.deps).
    """
    return task


@router.get("/{bulk_task_id}/download")
async def download_bulk_task(
    bulk_task_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    task: Annotated[BulkTask, _RequireBulkTaskOwner],
):
    """Скачать .zip с результатами. 409 если задача ещё не завершилась."""
    if task.status in ("pending", "running"):
        raise HTTPException(409, f"Задача ещё в работе (status={task.status})")
    if task.status == "cancelled":
        raise HTTPException(409, "Задача отменена — архива нет")
    if not task.result_zip_path:
        raise HTTPException(404, "Архив не сформирован")
    path = Path(task.result_zip_path)
    if not path.exists():
        raise HTTPException(404, "Файл архива не найден на диске")
    filename = f"bulk_task_{bulk_task_id}.zip"
    return FileResponse(
        path,
        media_type="application/zip",
        filename=filename,
    )


@router.delete("/{bulk_task_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_or_cancel_bulk_task(
    bulk_task_id: int,
    current_user: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Отмена (pending/running → cancelled) либо удаление записи (finished).

    Cancel — фактически только меняет status; фоновая задача не прерывается
    (FastAPI BackgroundTasks не поддерживает cancellation в MVP). Просто
    .zip не будет показан пользователю и финальное состояние перепишется
    в `cancelled`, если задача успеет завершиться позже.

    Уровень доступа выше чем у GET (LawyerOrAdmin), потому отдельной owner-проверки
    нет: lawyer и admin удаляют любые задачи как curator'ы пайплайна.
    """
    task = (await session.execute(
        select(BulkTask).where(BulkTask.id == bulk_task_id)
    )).scalar_one_or_none()
    if not task:
        raise HTTPException(404, "Задача не найдена")
    if task.status in ("pending", "running"):
        task.status = "cancelled"
        await session.commit()
        return
    # Финализованная задача — удаляем запись; артефакты на диске trim'ом cron'а
    # (план эпика 12 — отдельный очиститель storage/bulk_tasks).
    await session.delete(task)
    await session.commit()
