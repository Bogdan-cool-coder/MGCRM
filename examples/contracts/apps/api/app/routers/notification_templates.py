"""Эпик 21.2 — Admin CRUD для notification templates.

UI «/admin/notifications/templates» позволяет:
- посмотреть список шаблонов с фильтрами по kind/channel/locale;
- создать новый шаблон;
- отредактировать subject/body_template;
- деактивировать (is_active=false) старый шаблон;
- удалить (hard delete) если не используется.

Endpoints (все под AdminUser):
- GET     /api/admin/notification-templates       → list с фильтрами
- POST    /api/admin/notification-templates       → create
- GET     /api/admin/notification-templates/{id}  → details (для preview)
- PATCH   /api/admin/notification-templates/{id}  → update
- DELETE  /api/admin/notification-templates/{id}  → hard delete
"""
from __future__ import annotations

from datetime import UTC, datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser
from app.models import NotificationTemplate
from app.services.notification_dispatcher import NOTIFICATION_CHANNELS
from app.services.notifications import NOTIFICATION_KINDS

router = APIRouter(
    prefix="/admin/notification-templates",
    tags=["admin-notification-templates"],
)

# Поддерживаемые locale. Сейчас ru-only, en — резерв на i18n-эпик.
SUPPORTED_LOCALES: tuple[str, ...] = ("ru", "en")


# ============ Schemas ============


class TemplateOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    kind: str
    channel: str
    locale: str
    subject: str | None = None
    body_template: str | None = None
    variables: list[dict[str, Any]] | None = None
    is_active: bool
    created_at: datetime
    updated_at: datetime


class TemplateIn(BaseModel):
    kind: str = Field(..., max_length=32)
    channel: str = Field(..., max_length=16)
    locale: str = Field(default="ru", max_length=8)
    subject: str | None = None
    body_template: str | None = None
    variables: list[dict[str, Any]] | None = None
    is_active: bool = True


class TemplatePatch(BaseModel):
    subject: str | None = None
    body_template: str | None = None
    variables: list[dict[str, Any]] | None = None
    is_active: bool | None = None


# ============ Endpoints ============


@router.get("", response_model=list[TemplateOut])
async def list_templates(
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    kind: Annotated[str | None, Query()] = None,
    channel: Annotated[str | None, Query()] = None,
    locale: Annotated[str | None, Query()] = None,
    is_active: Annotated[bool | None, Query()] = None,
) -> list[TemplateOut]:
    """List с опциональными фильтрами kind/channel/locale/is_active."""
    stmt = select(NotificationTemplate)
    if kind:
        stmt = stmt.where(NotificationTemplate.kind == kind)
    if channel:
        stmt = stmt.where(NotificationTemplate.channel == channel)
    if locale:
        stmt = stmt.where(NotificationTemplate.locale == locale)
    if is_active is not None:
        stmt = stmt.where(NotificationTemplate.is_active.is_(is_active))
    stmt = stmt.order_by(
        NotificationTemplate.kind,
        NotificationTemplate.channel,
        NotificationTemplate.locale,
    )
    rows = (await session.execute(stmt)).scalars().all()
    return [TemplateOut.model_validate(r) for r in rows]


@router.get("/{template_id}", response_model=TemplateOut)
async def get_template(
    template_id: int,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> TemplateOut:
    row = (
        await session.execute(
            select(NotificationTemplate).where(
                NotificationTemplate.id == template_id,
            )
        )
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(404, "Шаблон не найден")
    return TemplateOut.model_validate(row)


@router.post("", response_model=TemplateOut, status_code=status.HTTP_201_CREATED)
async def create_template(
    body: TemplateIn,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> TemplateOut:
    """Create. Валидирует kind/channel/locale по whitelist.
    Уникальность (kind, channel, locale) проверяется БД-constraint'ом
    (uq_nt_kind_channel_locale) — на дубль вернём 409.
    """
    if body.kind not in NOTIFICATION_KINDS:
        raise HTTPException(400, f"Неизвестный kind: {body.kind!r}")
    if body.channel not in NOTIFICATION_CHANNELS:
        raise HTTPException(400, f"Неизвестный channel: {body.channel!r}")
    if body.locale not in SUPPORTED_LOCALES:
        raise HTTPException(400, f"Неизвестный locale: {body.locale!r}")

    # Pre-check на дубль (более явный error чем IntegrityError).
    existing = (
        await session.execute(
            select(NotificationTemplate).where(
                NotificationTemplate.kind == body.kind,
                NotificationTemplate.channel == body.channel,
                NotificationTemplate.locale == body.locale,
            )
        )
    ).scalar_one_or_none()
    if existing is not None:
        raise HTTPException(
            status.HTTP_409_CONFLICT,
            (
                f"Шаблон для (kind={body.kind!r}, channel={body.channel!r}, "
                f"locale={body.locale!r}) уже существует (id={existing.id})"
            ),
        )

    row = NotificationTemplate(
        kind=body.kind,
        channel=body.channel,
        locale=body.locale,
        subject=body.subject,
        body_template=body.body_template,
        variables=body.variables,
        is_active=body.is_active,
    )
    session.add(row)
    await session.commit()
    await session.refresh(row)
    return TemplateOut.model_validate(row)


@router.patch("/{template_id}", response_model=TemplateOut)
async def update_template(
    template_id: int,
    body: TemplatePatch,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> TemplateOut:
    row = (
        await session.execute(
            select(NotificationTemplate).where(
                NotificationTemplate.id == template_id,
            )
        )
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(404, "Шаблон не найден")

    if body.subject is not None:
        row.subject = body.subject
    if body.body_template is not None:
        row.body_template = body.body_template
    if body.variables is not None:
        row.variables = body.variables
    if body.is_active is not None:
        row.is_active = body.is_active
    row.updated_at = datetime.now(UTC)

    await session.commit()
    await session.refresh(row)
    return TemplateOut.model_validate(row)


@router.delete("/{template_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_template(
    template_id: int,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> None:
    """Hard delete. Если хочется сохранить историю — лучше deactivate
    (PATCH is_active=false). Этот endpoint для дефинитивного удаления
    (например legacy шаблон под отключённый kind)."""
    row = (
        await session.execute(
            select(NotificationTemplate).where(
                NotificationTemplate.id == template_id,
            )
        )
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(404, "Шаблон не найден")
    await session.delete(row)
    await session.commit()
