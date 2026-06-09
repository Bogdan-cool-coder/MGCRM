"""Эпик 21 — UX Upgrade: in-app notifications endpoints.

Простой CRUD-набор для UI inbox юзера:
- GET /api/notifications — список с пагинацией + unread_count + total
- GET /api/notifications/count — только unread_count (для badge)
- PATCH /api/notifications/{id}/read — пометить одну прочитанной
- POST /api/notifications/mark-all-read — пометить все
- DELETE /api/notifications/{id} — удалить (если фронту нужно)

Все endpoints требуют cookie auth (CurrentUser). Юзер видит ТОЛЬКО свои
нотификации — никаких admin-«посмотреть чужие». Если нужно — отдельный
admin endpoint, но в MVP его нет.
"""
from __future__ import annotations

from datetime import UTC, datetime
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, Field
from sqlalchemy import update
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser
from app.models import Notification
from app.schemas import (
    NotificationCountOut,
    NotificationListOut,
    NotificationOut,
)
from app.services.notifications import (
    get_unread_count,
    list_notifications_for_user,
)

router = APIRouter(prefix="/notifications", tags=["notifications"])


@router.get("", response_model=NotificationListOut)
async def list_notifications(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    limit: Annotated[int, Query(ge=1, le=100)] = 20,
    offset: Annotated[int, Query(ge=0)] = 0,
    unread_only: Annotated[bool, Query()] = False,
) -> NotificationListOut:
    """Постраничный список нотификаций текущего юзера.

    items отсортированы по created_at DESC (свежие сверху). unread_count и
    total считаются ВСЕГДА (без фильтра unread_only) — фронту нужен badge
    даже когда отображается только страница «прочитанные».
    """
    items, unread, total = await list_notifications_for_user(
        session, current_user.id,
        limit=limit, offset=offset, unread_only=unread_only,
    )
    return NotificationListOut(
        items=[NotificationOut.model_validate(n) for n in items],
        unread_count=unread,
        total=total,
    )


@router.get("/count", response_model=NotificationCountOut)
async def notifications_count(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> NotificationCountOut:
    """Только unread_count для badge в шапке.

    Дешевле, чем /api/notifications — один COUNT(*), не SELECT. Фронт polling-ает
    этот endpoint каждые 30s в MVP (без SSE).
    """
    unread = await get_unread_count(session, current_user.id)
    return NotificationCountOut(unread_count=unread)


@router.patch("/{notif_id}/read", response_model=NotificationOut)
async def mark_read(
    notif_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> NotificationOut:
    """Пометить одну нотификацию прочитанной.

    404 если не найдена ИЛИ принадлежит другому юзеру — НЕ выдаём 403, чтобы
    не палить факт существования чужой нотификации.
    """
    notif = (
        await session.execute(
            select(Notification).where(
                Notification.id == notif_id,
                Notification.user_id == current_user.id,
            )
        )
    ).scalar_one_or_none()
    if not notif:
        raise HTTPException(404, "Уведомление не найдено")
    if not notif.is_read:
        notif.is_read = True
        notif.read_at = datetime.now(UTC)
        await session.commit()
        await session.refresh(notif)
    return NotificationOut.model_validate(notif)


@router.post("/mark-all-read", response_model=NotificationCountOut)
async def mark_all_read(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> NotificationCountOut:
    """Пометить все непрочитанные нотификации юзера прочитанными.

    Возвращает текущий unread_count (обычно 0 сразу после операции). Если
    параллельно прилетела новая нотификация (race), вернёт её.
    """
    now = datetime.now(UTC)
    await session.execute(
        update(Notification)
        .where(
            Notification.user_id == current_user.id,
            Notification.is_read.is_(False),
        )
        .values(is_read=True, read_at=now)
    )
    await session.commit()
    unread = await get_unread_count(session, current_user.id)
    return NotificationCountOut(unread_count=unread)


class BulkReadIn(BaseModel):
    """Тело POST /notifications/bulk-read — список id для пометки прочитанными."""

    ids: list[int] = Field(default_factory=list)


class BulkReadOut(BaseModel):
    """Сколько нотификаций реально перевели в read + актуальный unread_count."""

    marked: int
    unread_count: int


@router.post("/bulk-read", response_model=BulkReadOut)
async def bulk_read(
    data: BulkReadIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> BulkReadOut:
    """Пометить прочитанными конкретные нотификации по списку ids.

    СТРОГО scoped к current_user.id: чужие id молча игнорируются (никакого
    палева существования и никакого IDOR — WHERE user_id = me). Пустой ids →
    no-op. Возвращает число помеченных + актуальный unread_count.
    """
    ids = [int(i) for i in data.ids if isinstance(i, int) or str(i).lstrip("-").isdigit()]
    marked = 0
    if ids:
        now = datetime.now(UTC)
        res = await session.execute(
            update(Notification)
            .where(
                Notification.id.in_(ids),
                Notification.user_id == current_user.id,
                Notification.is_read.is_(False),
            )
            .values(is_read=True, read_at=now)
        )
        await session.commit()
        marked = res.rowcount or 0
    unread = await get_unread_count(session, current_user.id)
    return BulkReadOut(marked=marked, unread_count=unread)


@router.delete("/{notif_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_notification(
    notif_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> None:
    """Удалить нотификацию.

    404 если не найдена или чужая — без 403, см. mark_read.
    """
    notif = (
        await session.execute(
            select(Notification).where(
                Notification.id == notif_id,
                Notification.user_id == current_user.id,
            )
        )
    ).scalar_one_or_none()
    if not notif:
        raise HTTPException(404, "Уведомление не найдено")
    await session.delete(notif)
    await session.commit()
