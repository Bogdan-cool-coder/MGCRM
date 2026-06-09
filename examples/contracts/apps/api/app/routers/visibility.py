"""Эпик 14 — Departments + Visibility ACL: матрица настроек видимости.

Endpoints (admin-only):
  GET   /api/admin/visibility-settings        — все строки матрицы
  PATCH /api/admin/visibility-settings        — bulk-upsert: список
                                                {entity_type, applies_to_role, scope}

Бизнес-правила:
- entity_type ∈ ALLOWED_ENTITY_TYPES (lead/deal/contract/subscription/counterparty/company)
- scope ∈ ALLOWED_SCOPES (personal/department/department_and_children/all)
- applies_to_role ∈ {manager,lawyer,director,admin} | None (NULL = все роли — fallback)
- admin-настройку можно записать, но при apply_scope_filter() admin
  всегда видит всё (override) — настройка для admin role не имеет
  практического смысла, оставлена для полноты матрицы (UI отображает её
  как «admin — всегда all»).

Upsert: каждый item обрабатывается отдельно, UPDATE if exists else INSERT.
В одной транзакции (commit в конце). Audit-логирование одной записью на
весь bulk (как в counterparties bulk_assign_responsible).
"""
from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import VisibilitySetting
from app.services.access_control import ALLOWED_ENTITY_TYPES, ALLOWED_SCOPES

router = APIRouter(prefix="/admin/visibility-settings", tags=["admin", "visibility"])

# Допустимые роли в applies_to_role. None (NULL) — fallback для всех ролей.
# P0 security (Unit 3a): добавлены accountant/cfo (роли модуля «Финансы»), чтобы
# админ мог явно настроить их scope (owner decision «всё на роль явно»).
ALLOWED_APPLIES_TO_ROLES = frozenset({
    "manager", "lawyer", "director", "admin", "accountant", "cfo",
})


# ============ Pydantic schemas ============

class VisibilitySettingOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    entity_type: str
    scope: str
    applies_to_role: str | None


class VisibilityRuleIn(BaseModel):
    """Один элемент матрицы для PATCH bulk-upsert."""
    entity_type: str = Field(min_length=1, max_length=32)
    applies_to_role: str | None = None
    scope: str = Field(min_length=1, max_length=32)


class VisibilityMatrixIn(BaseModel):
    """Body для PATCH — список правил."""
    rules: list[VisibilityRuleIn] = Field(default_factory=list, max_length=200)


class VisibilityMatrixOut(BaseModel):
    """Результат PATCH — обновлённая матрица + статистика."""
    updated: int
    inserted: int
    rules: list[VisibilitySettingOut]


# ============ Endpoints ============

@router.get("", response_model=list[VisibilitySettingOut])
async def list_visibility_settings(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Текущая матрица настроек (без сортировки по role-семантике; UI сам строит).

    Доступно ВСЕМ авторизованным юзерам (не только admin) — фронт может
    показывать «свой scope» в шапке, hint «manager видит только свои сделки».
    Редактировать матрицу может только admin (PATCH).
    """
    rows = (await session.execute(
        select(VisibilitySetting).order_by(
            VisibilitySetting.entity_type, VisibilitySetting.applies_to_role,
        )
    )).scalars().all()
    return rows


@router.patch("", response_model=VisibilityMatrixOut)
async def update_visibility_matrix(
    payload: VisibilityMatrixIn,
    current_admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Bulk-upsert правил видимости (admin).

    Каждое правило в payload.rules:
    - Валидируется (entity_type ∈ ALLOWED, scope ∈ ALLOWED, role ∈ ALLOWED or None).
    - Если (entity_type, applies_to_role) уже есть в БД → UPDATE scope.
    - Иначе → INSERT.

    Транзакция одна на весь bulk: либо все правила применились, либо ни одно.
    """
    # 1) Валидация (до записи в БД)
    for r in payload.rules:
        if r.entity_type not in ALLOWED_ENTITY_TYPES:
            raise HTTPException(
                status.HTTP_400_BAD_REQUEST,
                f"Недопустимый entity_type='{r.entity_type}'. "
                f"Допустимо: {sorted(ALLOWED_ENTITY_TYPES)}",
            )
        if r.scope not in ALLOWED_SCOPES:
            raise HTTPException(
                status.HTTP_400_BAD_REQUEST,
                f"Недопустимый scope='{r.scope}'. "
                f"Допустимо: {sorted(ALLOWED_SCOPES)}",
            )
        if r.applies_to_role is not None and r.applies_to_role not in ALLOWED_APPLIES_TO_ROLES:
            raise HTTPException(
                status.HTTP_400_BAD_REQUEST,
                f"Недопустимая роль applies_to_role='{r.applies_to_role}'. "
                f"Допустимо: {sorted(ALLOWED_APPLIES_TO_ROLES)} или null",
            )

    # 2) Upsert
    inserted = 0
    updated = 0
    for r in payload.rules:
        # find by (entity_type, applies_to_role)
        # IMPORTANT: для NULL applies_to_role — отдельный фильтр (NULL != NULL в SQL)
        stmt = select(VisibilitySetting).where(
            VisibilitySetting.entity_type == r.entity_type,
        )
        if r.applies_to_role is None:
            stmt = stmt.where(VisibilitySetting.applies_to_role.is_(None))
        else:
            stmt = stmt.where(VisibilitySetting.applies_to_role == r.applies_to_role)

        existing = (await session.execute(stmt)).scalar_one_or_none()
        if existing is None:
            session.add(VisibilitySetting(
                entity_type=r.entity_type,
                scope=r.scope,
                applies_to_role=r.applies_to_role,
                updated_by_user_id=current_admin.id,
            ))
            inserted += 1
        else:
            existing.scope = r.scope
            existing.updated_by_user_id = current_admin.id
            updated += 1

    if inserted or updated:
        await session.commit()

    # 3) Возвращаем актуальную матрицу
    rows = (await session.execute(
        select(VisibilitySetting).order_by(
            VisibilitySetting.entity_type, VisibilitySetting.applies_to_role,
        )
    )).scalars().all()
    return VisibilityMatrixOut(
        updated=updated,
        inserted=inserted,
        rules=[VisibilitySettingOut.model_validate(row) for row in rows],
    )
