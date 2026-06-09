from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import func
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import _DEFAULT_ELEVATED, CurrentUser, DirectorOrAdmin
from app.models import Contract, Counterparty, User


def _is_elevated(user: User) -> bool:
    """Может ли пользователь переназначать owner/department (admin или director)."""
    return user.role in _DEFAULT_ELEVATED
from app.schemas import CounterpartyIn, CounterpartyOut
from app.services.audit import log_change, snapshot_entity
from app.services.categories import assign_for_counterparty

router = APIRouter(prefix="/counterparties", tags=["counterparties"])

# Эпик 8: какие поля Counterparty логируем в audit (важные бизнес-поля)
# Эпик 10: добавлен responsible_user_id (трекаем смену ответственного для аудита).
_CP_AUDIT_FIELDS = [
    "name", "country_code", "city", "tax_id",
    "email", "phone", "address", "group_id", "category_code",
    "responsible_user_id",
]


@router.get("", response_model=list[CounterpartyOut])
async def list_counterparties(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    country_code: str | None = None,
    q: str | None = None,
):
    """Эпик 14: Counterparty получил owner_user_id + department_id (миграция 0036).
    Scope-фильтр применяется по матрице visibility_settings. Default — 'all'
    (бэквард-совместимое поведение: до настройки админом всё видно всем).
    """
    stmt = select(Counterparty).order_by(Counterparty.name)
    if country_code:
        stmt = stmt.where(Counterparty.country_code == country_code)
    if q:
        stmt = stmt.where(Counterparty.name.ilike(f"%{q}%"))
    # Эпик 14: scope-фильтр (visibility_settings → personal/department/...).
    from app.services.access_control import scope_query
    stmt = await scope_query(session, stmt, Counterparty, "counterparty", current_user)
    return (await session.execute(stmt)).scalars().all()


@router.post("", response_model=CounterpartyOut, status_code=status.HTTP_201_CREATED)
async def create_counterparty(
    payload: CounterpartyIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    # Эпик 14: автозаливка department_id из owner.department_id (если не указан).
    from app.services.access_control import autofill_department_from_owner
    data = payload.model_dump()
    # Fix mass-assignment: non-elevated роли не могут создавать КА на чужого
    # владельца или произвольный отдел — форсим себя как owner. admin/director —
    # могут передавать owner_user_id/department_id явно.
    if not _is_elevated(current_user):
        data["owner_user_id"] = current_user.id
        data["department_id"] = None  # перебьётся autofill из owner ниже
    data["department_id"] = await autofill_department_from_owner(
        session, data.get("owner_user_id"), data.get("department_id"),
    )
    cp = Counterparty(**data)
    session.add(cp)
    await session.commit()
    await session.refresh(cp)
    await assign_for_counterparty(session, cp.id)  # категория по обороту (для группы — по холдингу)
    await session.commit()
    await session.refresh(cp)
    # Эпик 8: audit create
    await log_change(
        session, entity_type="counterparty", entity_id=cp.id,
        user_id=current_user.id, action="create",
        after=snapshot_entity(cp, _CP_AUDIT_FIELDS),
    )
    await session.commit()
    # Эпик 11.2: outbound webhook counterparty.created
    from app.services.webhook_dispatcher import (
        counterparty_to_payload,
        safe_dispatch_event,
    )
    await safe_dispatch_event(
        session, "counterparty.created", "counterparty", cp.id,
        counterparty_to_payload(cp),
    )
    return cp


@router.get("/{cp_id}", response_model=CounterpartyOut)
async def get_counterparty(
    cp_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    cp = (await session.execute(select(Counterparty).where(Counterparty.id == cp_id))).scalar_one_or_none()
    if not cp:
        raise HTTPException(404, "Не найден")
    # P0 security (Unit 3b): object-level IDOR. Каждая Company имеет зеркало-
    # Counterparty, поэтому этот маршрут — байпас к данным компании вне scope.
    from app.services.access_control import ensure_object_visible
    await ensure_object_visible(session, cp, "counterparty", current_user)
    return cp


@router.patch("/{cp_id}", response_model=CounterpartyOut)
async def update_counterparty(
    cp_id: int,
    payload: CounterpartyIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    cp = (await session.execute(select(Counterparty).where(Counterparty.id == cp_id))).scalar_one_or_none()
    if not cp:
        raise HTTPException(404, "Не найден")
    # P0 security (Unit 3b): нельзя править чужого контрагента вне scope.
    from app.services.access_control import ensure_object_visible
    await ensure_object_visible(session, cp, "counterparty", current_user)
    # Эпик 8: snapshot до изменений
    before = snapshot_entity(cp, _CP_AUDIT_FIELDS)
    # Fix PUT→PATCH: используем exclude_unset=True, чтобы не затирать поля,
    # которые клиент не передал (owner_user_id, bank, address, и т.д.).
    data = payload.model_dump(exclude_unset=True)
    # Fix mass-assignment: non-elevated роли не могут переназначить owner/department.
    # Игнорируем эти ключи в PATCH если роль не позволяет.
    if not _is_elevated(current_user):
        data.pop("owner_user_id", None)
        data.pop("department_id", None)
    # Эпик 14: автозаливка department_id если меняется owner_user_id, но
    # department_id явно не передан в PATCH.
    if "owner_user_id" in data and "department_id" not in data:
        from app.services.access_control import autofill_department_from_owner
        data["department_id"] = await autofill_department_from_owner(
            session, data.get("owner_user_id"), None,
        )
    for k, v in data.items():
        setattr(cp, k, v)
    await session.commit()
    await assign_for_counterparty(session, cp.id)  # пересчёт категории (учтёт смену группы)
    await session.commit()
    await session.refresh(cp)
    after = snapshot_entity(cp, _CP_AUDIT_FIELDS)
    await log_change(
        session, entity_type="counterparty", entity_id=cp.id,
        user_id=current_user.id, action="update", before=before, after=after,
    )
    await session.commit()
    return cp


@router.delete("/{cp_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_counterparty(
    cp_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить контрагента (admin/director). Запрещено, если есть договоры — защита реальных клиентов.
    Подписки/контакты/заметки/задачи удалятся каскадом; сделки отвяжутся (SET NULL)."""
    cp = (await session.execute(select(Counterparty).where(Counterparty.id == cp_id))).scalar_one_or_none()
    if not cp:
        raise HTTPException(404, "Не найден")
    n = (await session.execute(
        select(func.count()).select_from(Contract).where(Contract.counterparty_id == cp_id)
    )).scalar_one()
    if n:
        raise HTTPException(
            status.HTTP_409_CONFLICT,
            f"Нельзя удалить: к контрагенту привязаны договоры ({n}). Сначала перенесите или удалите их.",
        )
    # CONTACTS 2.0: запретить удаление Counterparty, который является зеркалом
    # для Company — иначе зеркало осиротеет, а реестр/подписки сломаются.
    # Удаление должно идти через карточку компании (delete_company чистит оба).
    from app.models import Company
    mirror_company = (await session.execute(
        select(Company.id).where(Company.counterparty_id == cp_id)
    )).scalar_one_or_none()
    if mirror_company is not None:
        raise HTTPException(
            400,
            f"Нельзя удалить: контрагент — зеркало компании (id={mirror_company}). "
            "Удаляйте через карточку компании.",
        )
    # Эпик 8: audit delete
    before = snapshot_entity(cp, _CP_AUDIT_FIELDS)
    await log_change(
        session, entity_type="counterparty", entity_id=cp.id,
        user_id=current_user.id, action="delete", before=before,
    )
    # P2: миграция 0106 поставила ON DELETE RESTRICT на contracts.counterparty_id.
    # Pre-check выше закрывает обычный путь, но при гонке (договор создали между
    # проверкой и delete) БД бросит IntegrityError — ловим и отдаём 409 вместо 500.
    try:
        await session.delete(cp)
        await session.commit()
    except IntegrityError:
        await session.rollback()
        raise HTTPException(
            status.HTTP_409_CONFLICT,
            "Нельзя удалить: есть связанные договоры/записи. Сначала перенесите или удалите их.",
        )
