"""Contact (Эпик 1.2): CRUD контактов.

Новая сущность Contact живёт в таблице `crm_contacts` рядом с legacy CRM-карточкой
`contacts` (которая привязана к Counterparty 1:N). Эти две таблицы не пересекаются:
- /api/counterparties/{cp_id}/contacts → legacy (роутер crm.py, модель LegacyContact)
- /api/contacts                        → новая (этот файл, модель Contact)
"""
from __future__ import annotations

from datetime import datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func, or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin, scope_to_user
from app.models import (
    Company,
    Contact,
    ContactCompanyLink,
    ContactPosition,
    Contract,
    Deal,
    Pipeline,
    PipelineStage,
    User,
)
from app.routers.contacts_v2_schemas import (
    ContactCompanyLinkCreate,
    ContactCompanyLinkOut,
    ContactCompanyLinkUpdate,
)
from app.services.audit import log_change, snapshot_entity
from app.services.contacts_v2 import (
    company_to_unified,
    contact_to_unified,
    sort_unified,
)

router = APIRouter(prefix="/contacts", tags=["contacts"])

_CONTACT_AUDIT_FIELDS = [
    "full_name", "email", "phone", "position", "company_id",
    "is_primary", "owner_id",
]


# ============ Pydantic-схемы ============

class ContactOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    full_name: str
    email: str | None
    phone: str | None
    position: str | None
    company_id: int | None
    is_primary: bool
    owner_id: int | None
    tg_username: str | None
    notes: str | None
    # CONTACTS 2.0 Ф0
    source: str | None = None
    tags: list[str] = Field(default_factory=list)
    status: str | None = None
    # Эпик 8: extra_fields (custom fields scope='contact')
    extra_fields: dict[str, Any] = Field(default_factory=dict)
    created_at: datetime
    updated_at: datetime


class ContactCreate(BaseModel):
    full_name: str = Field(min_length=1, max_length=255)
    email: str | None = Field(default=None, max_length=255)
    phone: str | None = Field(default=None, max_length=64)
    position: str | None = Field(default=None, max_length=128)
    company_id: int | None = None
    is_primary: bool = False
    owner_id: int | None = None
    tg_username: str | None = Field(default=None, max_length=64)
    notes: str | None = None
    # CONTACTS 2.0 Ф0
    source: str | None = Field(default=None, max_length=32)
    tags: list[str] = Field(default_factory=list)
    status: str | None = Field(default="active", max_length=32)


class ContactUpdate(BaseModel):
    full_name: str | None = Field(default=None, min_length=1, max_length=255)
    email: str | None = Field(default=None, max_length=255)
    phone: str | None = Field(default=None, max_length=64)
    position: str | None = Field(default=None, max_length=128)
    company_id: int | None = None
    is_primary: bool | None = None
    owner_id: int | None = None
    tg_username: str | None = Field(default=None, max_length=64)
    notes: str | None = None
    # CONTACTS 2.0 Ф0
    source: str | None = Field(default=None, max_length=32)
    tags: list[str] | None = None
    status: str | None = Field(default=None, max_length=32)


class UnifiedItemOut(BaseModel):
    """Элемент unified-списка контактов+компаний (CONTACTS 2.0 Ф1).

    kind различает физлицо (person) и компанию (company). type-specific поля —
    в `extra` (frontend знает kind и берёт нужное).
    """
    id: int
    kind: str  # 'person' | 'company'
    name: str
    phone: str | None = None
    email: str | None = None
    owner_id: int | None = None
    source: str | None = None
    tags: list[str] = Field(default_factory=list)
    extra: dict[str, Any] = Field(default_factory=dict)


# ============ Helpers ============

async def _get_contact_or_404(session: AsyncSession, contact_id: int) -> Contact:
    contact = (await session.execute(
        select(Contact).where(Contact.id == contact_id)
    )).scalar_one_or_none()
    if not contact:
        raise HTTPException(404, "Контакт не найден")
    return contact


async def _require_contact_visible(
    session: AsyncSession, contact_id: int, user: User,
) -> Contact:
    """P0 security (Unit 3b): загрузить контакт + проверить object-level scope.

    404 на чужой контакт. Используется в item/subresource эндпоинтах, чтобы
    данные контакта (компании/сделки/договоры) не утекали вне scope.
    """
    from app.services.access_control import ensure_object_visible
    contact = await _get_contact_or_404(session, contact_id)
    await ensure_object_visible(session, contact, "contact", user)
    return contact


async def _validate_company_id(session: AsyncSession, company_id: int | None) -> None:
    if company_id is None:
        return
    exists = (await session.execute(
        select(Company.id).where(Company.id == company_id)
    )).scalar_one_or_none()
    if not exists:
        raise HTTPException(404, f"Компания {company_id} не найдена")


async def _require_company(session: AsyncSession, company_id: int) -> Company:
    company = (await session.execute(
        select(Company).where(Company.id == company_id)
    )).scalar_one_or_none()
    if not company:
        raise HTTPException(404, f"Компания {company_id} не найдена")
    return company


async def _validate_position_id(session: AsyncSession, position_id: int | None) -> None:
    if position_id is None:
        return
    exists = (await session.execute(
        select(ContactPosition.id).where(ContactPosition.id == position_id)
    )).scalar_one_or_none()
    if not exists:
        raise HTTPException(404, f"Должность {position_id} не найдена")


async def _clear_other_primary_for_company(
    session: AsyncSession, company_id: int, keep_contact_id: int,
) -> None:
    """Снять is_primary у всех контактов компании, кроме keep_contact_id.

    Гарантирует «единственный primary» в M2M-связях компании. Не коммитит —
    делается в одной транзакции с upsert'ом основной связи.
    """
    others = (await session.execute(
        select(ContactCompanyLink).where(
            ContactCompanyLink.company_id == company_id,
            ContactCompanyLink.contact_id != keep_contact_id,
            ContactCompanyLink.is_primary.is_(True),
        )
    )).scalars().all()
    for link in others:
        link.is_primary = False


async def _upsert_link(
    session: AsyncSession,
    *,
    contact_id: int,
    company_id: int,
    position: str | None = None,
    position_id: int | None = None,
    employment_status: str | None = None,
    is_primary: bool | None = None,
) -> ContactCompanyLink:
    """Создать или обновить связь contact↔company (UNIQUE contact_id+company_id).

    is_primary=True → снимает primary у других контактов этой компании
    (транзакционно). Не коммитит — caller управляет транзакцией.
    """
    link = (await session.execute(
        select(ContactCompanyLink).where(
            ContactCompanyLink.contact_id == contact_id,
            ContactCompanyLink.company_id == company_id,
        )
    )).scalar_one_or_none()
    if link is None:
        link = ContactCompanyLink(contact_id=contact_id, company_id=company_id)
        session.add(link)
    if position is not None:
        link.position = position
    if position_id is not None:
        link.position_id = position_id
    if employment_status is not None:
        link.employment_status = employment_status
    if is_primary is not None:
        link.is_primary = is_primary
        if is_primary:
            await _clear_other_primary_for_company(session, company_id, contact_id)
    return link


# ============ Endpoints ============

@router.get("", response_model=list[ContactOut])
async def list_contacts(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    company_id: int | None = None,
    owner_id: int | None = None,
    q: str | None = None,
    limit: int = 100,
    offset: int = 0,
):
    """Список контактов с фильтрами (по компании, по владельцу, поиск по имени/email/phone).

    Owner-scope (Эпик 0, RBAC): manager видит только свои контакты; admin/director/lawyer — все.
    """
    stmt = select(Contact).order_by(Contact.updated_at.desc())
    if company_id is not None:
        stmt = stmt.where(Contact.company_id == company_id)
    if owner_id is not None:
        stmt = stmt.where(Contact.owner_id == owner_id)
    if q:
        like = f"%{q}%"
        stmt = stmt.where(or_(
            Contact.full_name.ilike(like),
            Contact.email.ilike(like),
            Contact.phone.ilike(like),
        ))
    # Manager видит только своих; admin/director/lawyer — все.
    stmt = scope_to_user(stmt, Contact, current_user, "owner_id")
    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))
    return (await session.execute(stmt)).scalars().all()


@router.post("", response_model=ContactOut, status_code=status.HTTP_201_CREATED)
async def create_contact(
    data: ContactCreate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    await _validate_company_id(session, data.company_id)
    contact = Contact(
        full_name=data.full_name,
        email=data.email,
        phone=data.phone,
        position=data.position,
        company_id=data.company_id,
        is_primary=data.is_primary,
        owner_id=data.owner_id if data.owner_id is not None else current_user.id,
        tg_username=data.tg_username,
        notes=data.notes,
    )
    session.add(contact)
    await session.flush()
    # CONTACTS 2.0 Ф1: при создании с company_id — завести M2M-связь.
    # is_primary транзакционно снимается у других контактов компании.
    if data.company_id is not None:
        await _upsert_link(
            session,
            contact_id=contact.id,
            company_id=data.company_id,
            position=data.position,
            employment_status="works",
            is_primary=data.is_primary,
        )
    await session.commit()
    await session.refresh(contact)
    await log_change(
        session, entity_type="contact", entity_id=contact.id,
        user_id=current_user.id, action="create",
        after=snapshot_entity(contact, _CONTACT_AUDIT_FIELDS),
    )
    await session.commit()
    return contact


@router.get("/unified", response_model=list[UnifiedItemOut])
async def list_unified(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    type: str = Query(default="all", description="person | company | all"),
    q: str | None = None,
    source: str | None = None,
    owner_id: int | None = None,
    limit: int = 200,
    offset: int = 0,
):
    """Unified-список физлиц (Contact) и компаний (Company) в одном ответе.

    CONTACTS 2.0 Ф1: фронт показывает единый раздел «Контакты». Каждый элемент
    несёт `kind` ('person'|'company') и общие поля; type-specific — в `extra`.

    Фильтры: type, q (поиск по имени/email/phone/legal_name/tax_id),
    source, owner_id. Owner-scope: manager видит только свои (owner_id /
    owner_user_id); admin/director/lawyer — все.

    Сортировка — по name (case-insensitive), пустые имена в конец. limit cap=1000.

    Perf: раньше грузили ВСЕ Contact + ВСЕ Company без LIMIT, сортировали и
    резали в Python (unbounded). Теперь ORDER BY + LIMIT протолкнуты в SQL:
      - single-type (person|company): берём ровно `off+cap` строк, уже
        отсортированных по тому же ключу, что и sort_unified, затем срез [off:];
      - all: каждую сторону ограничиваем `off+cap` (ordered by name), мёржим и
        пересортировываем sort_unified, затем срез — merge двух bounded-наборов
        даёт корректный top-(off+cap) объединения.
    Формат ответа и порядок сортировки сохранены.
    """
    cap = max(1, min(1000, limit))
    off = max(0, offset)
    bound = off + cap  # сколько строк максимум нужно с каждой стороны
    items: list[dict[str, Any]] = []

    want_person = type in ("person", "all")
    want_company = type in ("company", "all")

    # SQL-выражения сортировки, зеркалящие sort_unified (пустое имя → в конец,
    # затем lower(name) по возрастанию).
    contact_name = func.coalesce(Contact.full_name, "")
    contact_order = (
        (func.length(func.trim(contact_name)) == 0).asc(),
        func.lower(contact_name).asc(),
        Contact.id.asc(),
    )
    # Display-имя компании = name (обиходное) || legal_name (fallback) — как в
    # company_to_unified. NULLIF(TRIM(name),'') отбрасывает пустой обиходный name.
    company_name = func.coalesce(
        func.nullif(func.trim(func.coalesce(Company.name, "")), ""),
        Company.legal_name,
        "",
    )
    company_order = (
        (func.length(func.trim(company_name)) == 0).asc(),
        func.lower(company_name).asc(),
        Company.id.asc(),
    )

    if want_person:
        cstmt = select(Contact)
        if source:
            cstmt = cstmt.where(Contact.source == source)
        if owner_id is not None:
            cstmt = cstmt.where(Contact.owner_id == owner_id)
        if q:
            like = f"%{q}%"
            cstmt = cstmt.where(or_(
                Contact.full_name.ilike(like),
                Contact.email.ilike(like),
                Contact.phone.ilike(like),
            ))
        cstmt = scope_to_user(cstmt, Contact, current_user, "owner_id")
        cstmt = cstmt.order_by(*contact_order).limit(bound)
        for c in (await session.execute(cstmt)).scalars().all():
            items.append(contact_to_unified({
                "id": c.id, "full_name": c.full_name, "phone": c.phone,
                "email": c.email, "owner_id": c.owner_id, "source": c.source,
                "tags": c.tags, "position": c.position, "status": c.status,
                "company_id": c.company_id,
            }))

    if want_company:
        from app.services.access_control import scope_query
        cmstmt = select(Company)
        if source:
            cmstmt = cmstmt.where(Company.source == source)
        if owner_id is not None:
            cmstmt = cmstmt.where(Company.owner_user_id == owner_id)
        if q:
            like = f"%{q}%"
            cmstmt = cmstmt.where(or_(
                Company.name.ilike(like),
                Company.legal_name.ilike(like),
                Company.email.ilike(like),
                Company.phone.ilike(like),
                Company.tax_id.ilike(like),
            ))
        cmstmt = await scope_query(session, cmstmt, Company, "company", current_user)
        cmstmt = cmstmt.order_by(*company_order).limit(bound)
        for cm in (await session.execute(cmstmt)).scalars().all():
            items.append(company_to_unified({
                "id": cm.id, "name": cm.name, "legal_name": cm.legal_name,
                "phone": cm.phone, "email": cm.email,
                "owner_user_id": cm.owner_user_id, "source": cm.source,
                "tags": cm.tags, "tax_id": cm.tax_id, "country": cm.country,
                "city": cm.city, "counterparty_id": cm.counterparty_id,
                "company_type_id": cm.company_type_id,
                "holding_role": cm.holding_role, "group_id": cm.group_id,
            }))

    ordered = sort_unified(items)
    return [UnifiedItemOut(**it) for it in ordered[off:off + cap]]


@router.get("/{contact_id}", response_model=ContactOut)
async def get_contact(
    contact_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    contact = await _get_contact_or_404(session, contact_id)
    # P0 security (Unit 3b): object-level IDOR — 404 на чужой контакт вне scope.
    from app.services.access_control import ensure_object_visible
    await ensure_object_visible(session, contact, "contact", current_user)
    return contact


@router.patch("/{contact_id}", response_model=ContactOut)
async def update_contact(
    contact_id: int,
    data: ContactUpdate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    contact = await _get_contact_or_404(session, contact_id)
    # P0 security (Unit 3b): object-level IDOR — нельзя править чужой контакт.
    from app.services.access_control import ensure_object_visible
    await ensure_object_visible(session, contact, "contact", current_user)
    patch = data.model_dump(exclude_unset=True)
    if "company_id" in patch:
        await _validate_company_id(session, patch["company_id"])
    # Эпик 8: audit snapshot
    before = snapshot_entity(contact, _CONTACT_AUDIT_FIELDS)
    for k, v in patch.items():
        setattr(contact, k, v)
    await session.commit()
    await session.refresh(contact)
    after = snapshot_entity(contact, _CONTACT_AUDIT_FIELDS)
    await log_change(
        session, entity_type="contact", entity_id=contact.id,
        user_id=current_user.id, action="update", before=before, after=after,
    )
    await session.commit()
    return contact


@router.delete("/{contact_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_contact(
    contact_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    contact = await _get_contact_or_404(session, contact_id)
    # Эпик 8: audit
    before = snapshot_entity(contact, _CONTACT_AUDIT_FIELDS)
    await log_change(
        session, entity_type="contact", entity_id=contact.id,
        user_id=current_user.id, action="delete", before=before,
    )
    await session.delete(contact)
    await session.commit()


# ============ CONTACTS 2.0 Ф1: M2M контакт↔компания ============

class ContactCompanyLinkRich(ContactCompanyLinkOut):
    """Связь + денормализованные поля компании для отображения в списке."""
    company_name: str | None = None
    company_phone: str | None = None
    company_email: str | None = None


@router.get("/{contact_id}/companies", response_model=list[ContactCompanyLinkRich])
async def list_contact_companies(
    contact_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Компании, в которых работает (работал) контакт — через M2M-связи."""
    await _require_contact_visible(session, contact_id, current_user)
    rows = (await session.execute(
        select(ContactCompanyLink, Company)
        .join(Company, ContactCompanyLink.company_id == Company.id)
        .where(ContactCompanyLink.contact_id == contact_id)
        .order_by(ContactCompanyLink.is_primary.desc(), Company.legal_name)
    )).all()
    out: list[ContactCompanyLinkRich] = []
    for link, company in rows:
        item = ContactCompanyLinkRich.model_validate(link)
        item.company_name = (company.name or company.legal_name)
        item.company_phone = company.phone
        item.company_email = company.email
        out.append(item)
    return out


@router.post(
    "/{contact_id}/companies",
    response_model=ContactCompanyLinkOut,
    status_code=status.HTTP_201_CREATED,
)
async def link_contact_company(
    contact_id: int,
    data: ContactCompanyLinkCreate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Привязать контакт к компании (upsert при повторе — UNIQUE contact+company)."""
    await _require_contact_visible(session, contact_id, current_user)
    await _require_company(session, data.company_id)
    await _validate_position_id(session, data.position_id)
    link = await _upsert_link(
        session,
        contact_id=contact_id,
        company_id=data.company_id,
        position=data.position,
        position_id=data.position_id,
        employment_status=data.employment_status,
        is_primary=data.is_primary,
    )
    await session.commit()
    await session.refresh(link)
    return link


@router.patch(
    "/{contact_id}/companies/{company_id}",
    response_model=ContactCompanyLinkOut,
)
async def update_contact_company_link(
    contact_id: int,
    company_id: int,
    data: ContactCompanyLinkUpdate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Изменить должность/занятость/primary связи. is_primary=true снимает у других."""
    # P0 security: M2M-мутатор берёт оба id из path — огораживаем оба anchor'а
    # (контакт И компанию). 404 если хоть один невидим вне scope.
    from app.services.access_control import ensure_object_visible
    await _require_contact_visible(session, contact_id, current_user)
    company = await _require_company(session, company_id)
    await ensure_object_visible(session, company, "company", current_user)
    link = (await session.execute(
        select(ContactCompanyLink).where(
            ContactCompanyLink.contact_id == contact_id,
            ContactCompanyLink.company_id == company_id,
        )
    )).scalar_one_or_none()
    if not link:
        raise HTTPException(404, "Связь контакт↔компания не найдена")
    patch = data.model_dump(exclude_unset=True)
    if "position_id" in patch:
        await _validate_position_id(session, patch["position_id"])
    for k, v in patch.items():
        setattr(link, k, v)
    if patch.get("is_primary") is True:
        await _clear_other_primary_for_company(session, company_id, contact_id)
    await session.commit()
    await session.refresh(link)
    return link


@router.delete(
    "/{contact_id}/companies/{company_id}",
    status_code=status.HTTP_204_NO_CONTENT,
)
async def unlink_contact_company(
    contact_id: int,
    company_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить связь контакт↔компания."""
    # P0 security: M2M-мутатор берёт оба id из path — огораживаем оба anchor'а
    # (контакт И компанию). 404 если хоть один невидим вне scope.
    from app.services.access_control import ensure_object_visible
    await _require_contact_visible(session, contact_id, current_user)
    company = await _require_company(session, company_id)
    await ensure_object_visible(session, company, "company", current_user)
    link = (await session.execute(
        select(ContactCompanyLink).where(
            ContactCompanyLink.contact_id == contact_id,
            ContactCompanyLink.company_id == company_id,
        )
    )).scalar_one_or_none()
    if not link:
        raise HTTPException(404, "Связь контакт↔компания не найдена")
    await session.delete(link)
    await session.commit()


# ============ CONTACTS 2.0 Ф1: сделки/договоры контакта (read) ============

class ContactDealOut(BaseModel):
    """Сделка, деривированная для контакта через его компании."""
    id: int
    title: str
    pipeline_id: int
    pipeline_name: str | None = None
    stage_id: int
    stage_name: str | None = None
    amount: float | None = None
    currency: str | None = None
    owner_user_id: int | None = None
    responsible_name: str | None = None
    company_id: int | None = None
    counterparty_id: int | None = None
    created_at: datetime | None = None


class ContactContractOut(BaseModel):
    id: int
    number: str | None = None
    title: str | None = None
    status: str
    counterparty_id: int | None = None
    created_at: datetime


async def _company_ids_for_contact(session: AsyncSession, contact_id: int) -> list[int]:
    """ID компаний контакта (через M2M-связи)."""
    return list((await session.execute(
        select(ContactCompanyLink.company_id).where(
            ContactCompanyLink.contact_id == contact_id
        )
    )).scalars().all())


async def _counterparty_ids_for_companies(
    session: AsyncSession, company_ids: list[int],
) -> list[int]:
    """counterparty_id (зеркало) для списка компаний — для договоров/сделок."""
    if not company_ids:
        return []
    return list((await session.execute(
        select(Company.counterparty_id).where(
            Company.id.in_(company_ids),
            Company.counterparty_id.is_not(None),
        )
    )).scalars().all())


@router.get("/{contact_id}/deals", response_model=list[ContactDealOut])
async def list_contact_deals(
    contact_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Сделки контакта: через связанные компании → company_id / counterparty_id."""
    await _require_contact_visible(session, contact_id, current_user)
    company_ids = await _company_ids_for_contact(session, contact_id)
    if not company_ids:
        return []
    cp_ids = await _counterparty_ids_for_companies(session, company_ids)
    conds = [Deal.company_id.in_(company_ids)]
    if cp_ids:
        conds.append(Deal.counterparty_id.in_(cp_ids))
    rows = (await session.execute(
        select(Deal, PipelineStage, Pipeline, User)
        .outerjoin(PipelineStage, Deal.stage_id == PipelineStage.id)
        .outerjoin(Pipeline, Deal.pipeline_id == Pipeline.id)
        .outerjoin(User, Deal.owner_user_id == User.id)
        .where(or_(*conds))
        .order_by(Deal.updated_at.desc())
    )).all()
    return [
        ContactDealOut(
            id=d.id, title=d.title, pipeline_id=d.pipeline_id, stage_id=d.stage_id,
            pipeline_name=p.name if p else None,
            stage_name=s.name if s else None,
            amount=float(d.amount) if d.amount is not None else None,
            currency=d.currency, owner_user_id=d.owner_user_id,
            responsible_name=u.full_name if u else None,
            company_id=d.company_id, counterparty_id=d.counterparty_id,
            created_at=d.created_at,
        )
        for d, s, p, u in rows
    ]


@router.get("/{contact_id}/contracts", response_model=list[ContactContractOut])
async def list_contact_contracts(
    contact_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Договоры контакта: через counterparty_id (зеркало) связанных компаний."""
    await _require_contact_visible(session, contact_id, current_user)
    company_ids = await _company_ids_for_contact(session, contact_id)
    cp_ids = await _counterparty_ids_for_companies(session, company_ids)
    if not cp_ids:
        return []
    rows = (await session.execute(
        select(Contract)
        .where(Contract.counterparty_id.in_(cp_ids))
        .order_by(Contract.created_at.desc())
    )).scalars().all()
    return [
        ContactContractOut(
            id=c.id, number=c.number, title=c.title,
            status=c.status.value if hasattr(c.status, "value") else str(c.status),
            counterparty_id=c.counterparty_id, created_at=c.created_at,
        )
        for c in rows
    ]
