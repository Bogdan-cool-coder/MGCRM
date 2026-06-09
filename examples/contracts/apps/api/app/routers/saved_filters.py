"""Saved filters (Эпик 8 / Card 2.0): CRUD сохранённых сегментов.

Endpoints:
- GET /saved-filters?page_key=...&is_pinned=...    — список (свои + глобальные).
- GET /saved-filters/{id}                          — деталь (если доступен).
- POST /saved-filters                              — создать (CurrentUser; user_id
                                                     только админ может задать NULL).
- PATCH /saved-filters/{id}                        — обновить (свой или admin).
- DELETE /saved-filters/{id}                       — удалить (свой или admin).

Доступ:
- Свой сегмент (user_id == current.id) → R/W/D.
- Глобальный (user_id IS NULL) → R для всех, W/D только admin.
- Чужой сегмент → 404 (как будто не существует).
"""
from __future__ import annotations

from datetime import datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import and_, or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser
from app.models import SavedFilter, User, UserRole
from app.services.bulk_reorder import (
    apply_reorder_simple,
    validate_reorder_payload,
)


router = APIRouter(prefix="/saved-filters", tags=["saved-filters"])


# Допустимые page_key (sync с frontend PageKey)
SAVED_FILTER_PAGE_KEYS: tuple[str, ...] = (
    "leads",
    "contacts",
    "companies",
    "counterparties",
    "deals",
    "registry",
)


# ============ Pydantic-схемы ============


class SavedFilterOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    user_id: int | None
    page_key: str
    name: str
    filter_json: dict[str, Any]
    is_pinned: bool
    sort_order: int = 0
    created_at: datetime


class SavedFilterCreate(BaseModel):
    page_key: str
    name: str = Field(min_length=1, max_length=128)
    filter_json: dict[str, Any] = Field(default_factory=dict)
    is_pinned: bool = False
    # NULL = глобальный (можно создать только admin'у)
    user_id: int | None = None


class SavedFilterUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=128)
    filter_json: dict[str, Any] | None = None
    is_pinned: bool | None = None


# ============ Helpers ============


def _validate_page_key(page_key: str) -> None:
    if page_key not in SAVED_FILTER_PAGE_KEYS:
        raise HTTPException(
            400,
            f"Недопустимый page_key: {page_key}. Ожидается одно из {list(SAVED_FILTER_PAGE_KEYS)}",
        )


def can_write_filter(filter_obj: SavedFilter, user: User) -> bool:
    """Может ли user редактировать/удалять этот фильтр?

    - Свой (user_id == user.id) → True.
    - Глобальный (user_id IS NULL) → только admin.
    - Чужой → False.
    """
    if filter_obj.user_id == user.id:
        return True
    if filter_obj.user_id is None:
        return user.role == UserRole.admin
    return False


def can_read_filter(filter_obj: SavedFilter, user: User) -> bool:
    """Может ли user читать этот фильтр?

    - Свой → True.
    - Глобальный → True для всех.
    - Чужой → False (404).
    """
    if filter_obj.user_id is None:
        return True
    return filter_obj.user_id == user.id


async def _get_filter_or_404(
    session: AsyncSession, filter_id: int, user: User
) -> SavedFilter:
    f = (
        await session.execute(select(SavedFilter).where(SavedFilter.id == filter_id))
    ).scalar_one_or_none()
    if f is None or not can_read_filter(f, user):
        raise HTTPException(404, "Сегмент не найден")
    return f


# ============ Endpoints ============


@router.get("", response_model=list[SavedFilterOut])
async def list_saved_filters(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    page_key: str | None = None,
    is_pinned: bool | None = None,
):
    """Список сегментов: свои + глобальные. Фильтры — page_key, is_pinned."""
    if page_key is not None:
        _validate_page_key(page_key)

    # Свои (user_id == current.id) ИЛИ глобальные (user_id IS NULL)
    stmt = select(SavedFilter).where(
        or_(
            SavedFilter.user_id == current_user.id,
            SavedFilter.user_id.is_(None),
        )
    )
    if page_key is not None:
        stmt = stmt.where(SavedFilter.page_key == page_key)
    if is_pinned is not None:
        stmt = stmt.where(SavedFilter.is_pinned.is_(is_pinned))
    # Tech Sprint Фаза 0: учитываем sort_order между is_pinned и created_at.
    # is_pinned DESC сверху (pinned-блок), потом sort_order ASC (drag-and-drop),
    # потом created_at DESC (tiebreaker для не заданного sort_order).
    stmt = stmt.order_by(
        SavedFilter.is_pinned.desc(),
        SavedFilter.sort_order.asc(),
        SavedFilter.created_at.desc(),
    )
    return (await session.execute(stmt)).scalars().all()


@router.get("/{filter_id}", response_model=SavedFilterOut)
async def get_saved_filter(
    filter_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    return await _get_filter_or_404(session, filter_id, current_user)


@router.post("", response_model=SavedFilterOut, status_code=status.HTTP_201_CREATED)
async def create_saved_filter(
    data: SavedFilterCreate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать сегмент.

    - user_id=None в payload → глобальный, только admin может создать.
    - user_id явно задан и != current.id → 403 (нельзя создавать «за других»).
    - user_id=current.id или не передан → свой сегмент.
    """
    _validate_page_key(data.page_key)

    effective_user_id: int | None
    if data.user_id is None:
        # Если не указан — это «свой» сегмент. Глобальный требует явного user_id=None
        # отдельным sentinel? Нет: фронт по дизайну не отправляет user_id вообще,
        # backend выставляет current.id. Если admin хочет глобальный — он должен
        # явно отправить user_id: null (Pydantic None) И иметь admin-роль.
        # Но т.к. None ≠ "явно отправленный None", надёжнее различать через
        # model_dump(exclude_unset=True):
        if "user_id" in data.model_fields_set:
            # Явно отправлен null → глобальный, только admin
            if current_user.role != UserRole.admin:
                raise HTTPException(
                    403, "Только администратор может создавать глобальные сегменты"
                )
            effective_user_id = None
        else:
            effective_user_id = current_user.id
    else:
        if data.user_id != current_user.id and current_user.role != UserRole.admin:
            raise HTTPException(
                403, "Нельзя создавать сегменты от имени другого пользователя"
            )
        effective_user_id = data.user_id

    f = SavedFilter(
        user_id=effective_user_id,
        page_key=data.page_key,
        name=data.name,
        filter_json=data.filter_json,
        is_pinned=data.is_pinned,
    )
    session.add(f)
    await session.commit()
    await session.refresh(f)
    return f


@router.patch("/{filter_id}", response_model=SavedFilterOut)
async def update_saved_filter(
    filter_id: int,
    data: SavedFilterUpdate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    f = await _get_filter_or_404(session, filter_id, current_user)
    if not can_write_filter(f, current_user):
        raise HTTPException(403, "Нет прав на редактирование этого сегмента")
    patch = data.model_dump(exclude_unset=True)
    for k, v in patch.items():
        setattr(f, k, v)
    await session.commit()
    await session.refresh(f)
    return f


@router.delete("/{filter_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_saved_filter(
    filter_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    f = await _get_filter_or_404(session, filter_id, current_user)
    if not can_write_filter(f, current_user):
        raise HTTPException(403, "Нет прав на удаление этого сегмента")
    await session.delete(f)
    await session.commit()


# ============ Bulk-reorder (Tech Sprint Фаза 0) ============


class ReorderItem(BaseModel):
    id: int
    sort_order: int


class ReorderIn(BaseModel):
    """Body bulk-reorder + scope (только per user×page_key)."""

    page_key: str
    items: list[ReorderItem]


class ReorderOut(BaseModel):
    updated: int


@router.patch("/reorder", response_model=ReorderOut)
async def reorder_saved_filters(
    payload: ReorderIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Bulk-обновить sort_order своих сегментов на одном page_key.

    Scope: only own filters (user_id == current.id) для данного page_key.
    Глобальные/чужие — не reorderим (admin может через бэкдор отдельно).
    """
    _validate_page_key(payload.page_key)
    items_dict = [it.model_dump() for it in payload.items]
    pairs = validate_reorder_payload(items_dict, order_field="sort_order")
    # Scope: только свои фильтры на page_key
    scope = and_(
        SavedFilter.user_id == current_user.id,
        SavedFilter.page_key == payload.page_key,
    )
    count = await apply_reorder_simple(
        session, SavedFilter, pairs, scope_filter=scope, order_field="sort_order",
    )
    await session.commit()
    return ReorderOut(updated=count)
