"""Audit log (Эпик 8 / Card 2.0): просмотр истории изменений по сущности.

GET /audit/{entity_type}/{entity_id} → список записей с user_name (joined).

Без write endpoint'ов — запись делает app.services.audit.log_change из других
роутеров (leads/contacts/companies/counterparties/deals/contracts/subscriptions).
"""
from __future__ import annotations

from datetime import datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser
from app.models import EntityAuditLog, User
from app.services.audit import AUDIT_ACTIONS, AUDIT_ENTITY_TYPES, validate_entity_type


router = APIRouter(prefix="/audit", tags=["audit"])


# ============ Pydantic-схемы ============


class AuditLogEntryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    entity_type: str
    entity_id: int
    user_id: int | None
    user_name: str | None = None
    action: str
    diff_json: dict[str, Any] | None
    occurred_at: datetime
    request_id: str | None = None


class AuditLogPage(BaseModel):
    items: list[AuditLogEntryOut]
    total: int


# ============ Endpoints ============


@router.get("/{entity_type}/{entity_id}", response_model=AuditLogPage)
async def list_audit_logs(
    entity_type: str,
    entity_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    action: str | None = None,
    limit: int = 50,
    offset: int = 0,
):
    """История изменений по сущности.

    - entity_type — lead/contact/company/counterparty/deal/contract/subscription.
    - action (optional) — фильтр по типу (create/update/delete/merge/...).
    - Сортировка по occurred_at DESC, limit/offset для пагинации.
    - user_name джойнится (или 'Система' если user_id=null).
    """
    try:
        validate_entity_type(entity_type)
    except ValueError as ex:
        raise HTTPException(400, str(ex)) from None

    # P0 security (C9 CRIT-2): record-level авторизация. Раньше любой юзер читал
    # diff_json (включая PII: email/телефоны лидов) любой записи перебором id.
    # Теперь сверяем видимость родительской сущности через assert_target_visible
    # (404 на чужую/несуществующую).
    #
    # P2 (C9 follow-up): assert_target_visible ТИХО пропускает entity_type вне
    # sales-карты (_TARGET_MODEL_MAP) — fail-open. Это утекало бы diff_json
    # финансовых сущностей (fin_*) любому залогиненному. Fail-CLOSED для всего,
    # что sales-карта не покрывает:
    #   - fin_*  → требуем фин-доступ (view_operations);
    #   - прочие неизвестные → только director/admin.
    from app.services.activities import _TARGET_MODEL_MAP
    from app.services.activity_visibility import assert_target_visible

    if entity_type in _TARGET_MODEL_MAP:
        await assert_target_visible(session, current_user, entity_type, entity_id)
    elif entity_type.startswith("fin_"):
        from app.services.finance.access import fin_can

        if not await fin_can(session, current_user, "view_operations"):
            raise HTTPException(404, "Объект не найден")
    else:
        # Неизвестный entity_type — fail-closed: только elevated-роли.
        if current_user.role is None or current_user.role.value not in (
            "director",
            "admin",
        ):
            raise HTTPException(404, "Объект не найден")

    if action is not None and action not in AUDIT_ACTIONS:
        raise HTTPException(
            400,
            f"Недопустимый action: {action}. Ожидается одно из {list(AUDIT_ACTIONS)}",
        )

    base_where = [
        EntityAuditLog.entity_type == entity_type,
        EntityAuditLog.entity_id == entity_id,
    ]
    if action is not None:
        base_where.append(EntityAuditLog.action == action)

    total = (
        await session.execute(
            select(func.count(EntityAuditLog.id)).where(*base_where)
        )
    ).scalar_one() or 0

    stmt = (
        select(EntityAuditLog)
        .where(*base_where)
        .order_by(EntityAuditLog.occurred_at.desc())
        .limit(max(1, min(500, int(limit))))
        .offset(max(0, int(offset)))
    )
    rows = (await session.execute(stmt)).scalars().all()

    # Подтянуть user names (одним запросом)
    user_ids = {r.user_id for r in rows if r.user_id is not None}
    names: dict[int, str] = {}
    if user_ids:
        users = (
            await session.execute(select(User).where(User.id.in_(user_ids)))
        ).scalars().all()
        names = {u.id: u.full_name for u in users}

    items = [
        AuditLogEntryOut(
            id=r.id,
            entity_type=r.entity_type,
            entity_id=r.entity_id,
            user_id=r.user_id,
            user_name=names.get(r.user_id) if r.user_id else None,
            action=r.action,
            diff_json=r.diff_json,
            occurred_at=r.occurred_at,
            request_id=r.request_id,
        )
        for r in rows
    ]
    return AuditLogPage(items=items, total=int(total))


@router.get("/actions/whitelist")
async def get_actions_whitelist(_: CurrentUser):
    """Список допустимых actions (для UI dropdown фильтра)."""
    return {"actions": list(AUDIT_ACTIONS), "entity_types": list(AUDIT_ENTITY_TYPES)}
