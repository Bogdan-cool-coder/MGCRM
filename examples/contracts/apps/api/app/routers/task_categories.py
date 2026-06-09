"""Эпик 24 — Tasks v2: CRUD справочника категорий задач.

TaskCategory — справочник с настройками по умолчанию: исполнитель,
соисполнители/проверяющие/наблюдатели, шаблон чек-листа, флаги ограничений.

ACL:
- GET /api/task-categories — любой CurrentUser (autocomplete / выбор при создании задачи)
- GET /api/task-categories/{id} — любой CurrentUser (детали + counts)
- POST /api/task-categories — AdminUser
- PATCH /api/task-categories/{id} — AdminUser
- DELETE /api/task-categories/{id} — AdminUser (soft: is_active=false; 409 если есть активные задачи)
"""
from __future__ import annotations

from datetime import UTC, datetime
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import (
    Activity,
    TaskCategory,
    TaskCategoryAuditor,
    TaskCategoryChecklistItem,
    TaskCategoryCoExecutor,
    TaskCategoryObserver,
    User,
)

router = APIRouter(prefix="/task-categories", tags=["task-categories"])


# ============ Pydantic-схемы ============


class ChecklistItemIn(BaseModel):
    title: str = Field(min_length=1, max_length=256)
    sort_order: int = 0


class ChecklistItemOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    title: str
    sort_order: int


class TaskCategoryIn(BaseModel):
    name: str = Field(min_length=1, max_length=128)
    sort_order: int = 0
    default_executor_user_id: int | None = None
    admin_user_id: int | None = None
    description_template: str | None = None
    restrict_close_without_result: bool = False
    auto_title_from_category: bool = False
    required_file_count: int = Field(default=0, ge=0)
    is_active: bool = True
    # Дефолтные роли (user_ids для каждой роли)
    co_executor_user_ids: list[int] = []
    auditor_user_ids: list[int] = []
    observer_user_ids: list[int] = []
    # Шаблон чек-листа
    checklist_items: list[ChecklistItemIn] = []


class TaskCategoryPatch(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=128)
    sort_order: int | None = None
    default_executor_user_id: int | None = None
    admin_user_id: int | None = None
    description_template: str | None = None
    restrict_close_without_result: bool | None = None
    auto_title_from_category: bool | None = None
    required_file_count: int | None = Field(default=None, ge=0)
    is_active: bool | None = None
    co_executor_user_ids: list[int] | None = None
    auditor_user_ids: list[int] | None = None
    observer_user_ids: list[int] | None = None
    checklist_items: list[ChecklistItemIn] | None = None


class TaskCategoryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    sort_order: int
    default_executor_user_id: int | None
    admin_user_id: int | None
    description_template: str | None
    restrict_close_without_result: bool
    auto_title_from_category: bool
    required_file_count: int
    is_active: bool
    created_at: datetime
    updated_at: datetime
    # Денормализованные поля
    co_executor_user_ids: list[int] = []
    auditor_user_ids: list[int] = []
    observer_user_ids: list[int] = []
    checklist_items: list[ChecklistItemOut] = []
    # Счётчик активных задач (заполняется в GET detail)
    active_task_count: int | None = None


# ============ Helpers ============


def _to_out(cat: TaskCategory, active_task_count: int | None = None) -> TaskCategoryOut:
    """ORM → схема. Жунктион-таблицы уже должны быть eager-loaded."""
    return TaskCategoryOut(
        id=cat.id,
        name=cat.name,
        sort_order=cat.sort_order,
        default_executor_user_id=cat.default_executor_user_id,
        admin_user_id=cat.admin_user_id,
        description_template=cat.description_template,
        restrict_close_without_result=cat.restrict_close_without_result,
        auto_title_from_category=cat.auto_title_from_category,
        required_file_count=cat.required_file_count,
        is_active=cat.is_active,
        created_at=cat.created_at,
        updated_at=cat.updated_at,
        co_executor_user_ids=[lnk.user_id for lnk in cat.co_executor_links],
        auditor_user_ids=[lnk.user_id for lnk in cat.auditor_links],
        observer_user_ids=[lnk.user_id for lnk in cat.observer_links],
        checklist_items=[
            ChecklistItemOut(id=i.id, title=i.title, sort_order=i.sort_order)
            for i in sorted(cat.checklist_items, key=lambda x: x.sort_order)
        ],
        active_task_count=active_task_count,
    )


_LOAD_OPTIONS = [
    selectinload(TaskCategory.checklist_items),
    selectinload(TaskCategory.co_executor_links),
    selectinload(TaskCategory.auditor_links),
    selectinload(TaskCategory.observer_links),
]


async def _get_category_or_404(session: AsyncSession, cat_id: int) -> TaskCategory:
    cat = (await session.execute(
        select(TaskCategory).where(TaskCategory.id == cat_id).options(*_LOAD_OPTIONS)
    )).scalar_one_or_none()
    if not cat:
        raise HTTPException(404, "Категория задачи не найдена")
    return cat


async def _validate_user_ids(session: AsyncSession, user_ids: list[int]) -> None:
    """Проверяет что все user_id существуют и активны."""
    if not user_ids:
        return
    existing = (await session.execute(
        select(User.id).where(User.id.in_(user_ids), User.is_active.is_(True))
    )).scalars().all()
    missing = set(user_ids) - set(existing)
    if missing:
        raise HTTPException(400, f"Пользователи не найдены или неактивны: {sorted(missing)}")


async def _sync_junctions(
    session: AsyncSession,
    cat: TaskCategory,
    data: TaskCategoryIn | TaskCategoryPatch,
) -> None:
    """Синхронизирует junction-таблицы при create/patch."""
    # co_executors
    if hasattr(data, "co_executor_user_ids") and data.co_executor_user_ids is not None:
        await _validate_user_ids(session, data.co_executor_user_ids)
        # Удаляем старые
        for link in list(cat.co_executor_links):
            await session.delete(link)
        # Добавляем новые
        for uid in data.co_executor_user_ids:
            session.add(TaskCategoryCoExecutor(category_id=cat.id, user_id=uid))

    # auditors
    if hasattr(data, "auditor_user_ids") and data.auditor_user_ids is not None:
        await _validate_user_ids(session, data.auditor_user_ids)
        for link in list(cat.auditor_links):
            await session.delete(link)
        for uid in data.auditor_user_ids:
            session.add(TaskCategoryAuditor(category_id=cat.id, user_id=uid))

    # observers
    if hasattr(data, "observer_user_ids") and data.observer_user_ids is not None:
        await _validate_user_ids(session, data.observer_user_ids)
        for link in list(cat.observer_links):
            await session.delete(link)
        for uid in data.observer_user_ids:
            session.add(TaskCategoryObserver(category_id=cat.id, user_id=uid))

    # checklist_items
    if hasattr(data, "checklist_items") and data.checklist_items is not None:
        for item in list(cat.checklist_items):
            await session.delete(item)
        for i, ci in enumerate(data.checklist_items):
            session.add(TaskCategoryChecklistItem(
                category_id=cat.id,
                title=ci.title,
                sort_order=ci.sort_order if ci.sort_order != 0 else i,
            ))


# ============ Endpoints ============


@router.get("", response_model=list[TaskCategoryOut])
async def list_task_categories(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    active_only: bool = True,
    q: str | None = None,
    limit: Annotated[int, Query(ge=1, le=200)] = 100,
    offset: Annotated[int, Query(ge=0)] = 0,
):
    """Список категорий задач с фильтрацией.

    active_only=true (default) — только активные категории (для autocomplete).
    q — поиск по имени (ILIKE).
    """
    stmt = select(TaskCategory).options(*_LOAD_OPTIONS).order_by(
        TaskCategory.sort_order, TaskCategory.name
    )
    if active_only:
        stmt = stmt.where(TaskCategory.is_active.is_(True))
    if q:
        stmt = stmt.where(TaskCategory.name.ilike(f"%{q}%"))
    stmt = stmt.limit(limit).offset(offset)
    cats = (await session.execute(stmt)).scalars().all()
    return [_to_out(c) for c in cats]


@router.post("", response_model=TaskCategoryOut, status_code=status.HTTP_201_CREATED)
async def create_task_category(
    data: TaskCategoryIn,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создаёт категорию задачи. Только admin."""
    if data.default_executor_user_id:
        await _validate_user_ids(session, [data.default_executor_user_id])
    if data.admin_user_id:
        await _validate_user_ids(session, [data.admin_user_id])

    cat = TaskCategory(
        name=data.name,
        sort_order=data.sort_order,
        default_executor_user_id=data.default_executor_user_id,
        admin_user_id=data.admin_user_id,
        description_template=data.description_template,
        restrict_close_without_result=data.restrict_close_without_result,
        auto_title_from_category=data.auto_title_from_category,
        required_file_count=data.required_file_count,
        is_active=data.is_active,
    )
    session.add(cat)
    await session.flush()  # Нужен cat.id для junction-записей

    # Инициализируем пустые relationships для _sync_junctions
    cat.co_executor_links = []
    cat.auditor_links = []
    cat.observer_links = []
    cat.checklist_items = []

    await _sync_junctions(session, cat, data)
    await session.commit()

    fresh = await _get_category_or_404(session, cat.id)
    return _to_out(fresh)


@router.get("/{cat_id}", response_model=TaskCategoryOut)
async def get_task_category(
    cat_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Детали категории + количество активных задач."""
    cat = await _get_category_or_404(session, cat_id)

    # Подсчёт активных задач этой категории.
    active_task_count_row = (await session.execute(
        select(func.count(Activity.id)).where(
            Activity.category_id == cat_id,
            Activity.is_closed.is_(False),
        )
    )).scalar_one()

    return _to_out(cat, active_task_count=active_task_count_row)


@router.patch("/{cat_id}", response_model=TaskCategoryOut)
async def patch_task_category(
    cat_id: int,
    data: TaskCategoryPatch,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Частичное обновление категории. Только admin."""
    cat = await _get_category_or_404(session, cat_id)
    patch = data.model_dump(exclude_unset=True)

    # Валидируем FK-поля.
    for fk_field in ("default_executor_user_id", "admin_user_id"):
        if fk_field in patch and patch[fk_field] is not None:
            await _validate_user_ids(session, [patch[fk_field]])

    # Применяем простые поля.
    simple_fields = {
        "name", "sort_order", "default_executor_user_id", "admin_user_id",
        "description_template", "restrict_close_without_result",
        "auto_title_from_category", "required_file_count", "is_active",
    }
    for key, val in patch.items():
        if key in simple_fields:
            setattr(cat, key, val)

    await _sync_junctions(session, cat, data)
    cat.updated_at = datetime.now(UTC)
    await session.commit()

    fresh = await _get_category_or_404(session, cat.id)
    return _to_out(fresh)


@router.delete("/{cat_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_task_category(
    cat_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Soft-delete категории (is_active=false). 409 если есть активные задачи."""
    cat = await _get_category_or_404(session, cat_id)

    active_count = (await session.execute(
        select(func.count(Activity.id)).where(
            Activity.category_id == cat_id,
            Activity.is_closed.is_(False),
        )
    )).scalar_one()

    if active_count > 0:
        raise HTTPException(
            status.HTTP_409_CONFLICT,
            f"Нельзя деактивировать категорию: {active_count} активных задач. "
            "Сначала закройте или переназначьте задачи.",
        )

    cat.is_active = False
    cat.updated_at = datetime.now(UTC)
    await session.commit()
