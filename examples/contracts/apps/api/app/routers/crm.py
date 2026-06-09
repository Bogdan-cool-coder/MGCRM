"""CRM-карточка клиента: договоры клиента (Фаза 3a).

Legacy-эндпоинты контактов/заметок/задач удалены — фронт перешёл на
companies/contacts модули. Остался только список договоров контрагента.
"""
from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, scope_to_user
from app.models import Contract, Counterparty, User
from app.schemas import ContractOut

router = APIRouter(prefix="/counterparties", tags=["crm"])


async def _require_cp(
    session: AsyncSession, cp_id: int, user: User | None = None,
) -> Counterparty:
    """Загрузить контрагента + (если передан user) проверить object-level scope.

    P0 security (Unit 3b): подресурсы (контакты/заметки/задачи/договоры) контрагента
    не должны утекать вне scope пользователя. Counterparty — зеркало Company, так что
    этот маршрут — байпас. 404 на чужой КА (admin/director/lawyer видят всё).
    """
    cp = (await session.execute(select(Counterparty).where(Counterparty.id == cp_id))).scalar_one_or_none()
    if not cp:
        raise HTTPException(404, "Контрагент не найден")
    if user is not None:
        from app.services.access_control import ensure_object_visible
        await ensure_object_visible(session, cp, "counterparty", user)
    return cp


# ---------- Договоры клиента (вкладка «Документы») ----------

@router.get("/{cp_id}/contracts", response_model=list[ContractOut])
async def client_contracts(cp_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    await _require_cp(session, cp_id, current_user)
    stmt = select(Contract).where(Contract.counterparty_id == cp_id).order_by(Contract.created_at.desc())
    # Manager видит только свои договоры; admin/director/lawyer — все.
    stmt = scope_to_user(stmt, Contract, current_user, "author_user_id")
    return (await session.execute(stmt)).scalars().all()
