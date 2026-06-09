"""Company (Эпик 1.2): CRUD компаний и список контактов компании.

Новая сущность Company (`crm_companies`) живёт рядом с legacy Counterparty
(`counterparties`) и не заменяет его. Когда новая Company соответствует
существующему Counterparty — обратная связь хранится в `Company.counterparty_id`.
"""
from __future__ import annotations

from datetime import datetime
from decimal import Decimal
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field, field_validator
from sqlalchemy import func, or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import _DEFAULT_ELEVATED, CurrentUser, DirectorOrAdmin
from app.models import (
    ClientGroup,
    Company,
    Contact,
    ContactCompanyLink,
    Contract,
    Counterparty,
    Deal,
    Pipeline,
    PipelineStage,
    User,
)
from app.routers.contacts import (
    ContactContractOut,
    ContactCreate,
    ContactDealOut,
    ContactOut,
    _upsert_link,
    _validate_position_id,
)
from app.routers.contacts_v2_schemas import (
    ContactCompanyLinkCreate,
    ContactCompanyLinkOut,
    ContactCompanyLinkUpdate,
)
from app.services.access_control import ensure_object_visible
from app.services.audit import log_change, snapshot_entity
from app.services.contacts_v2 import (
    detect_parent_conflict,
    sync_company_mirror,
    validate_holding_role,
)

router = APIRouter(prefix="/companies", tags=["companies"])

_COMPANY_AUDIT_FIELDS = [
    "legal_name", "short_name", "tax_id", "country", "city",
    "email", "phone", "category_code", "group_id", "counterparty_id",
]


# ============ Pydantic-схемы ============

class CompanyOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    legal_name: str
    short_name: str | None
    # CONTACTS 2.0 Ф0: обиходное название (отдельно от legal_name)
    name: str | None = None
    tax_id: str | None
    country: str | None
    city: str | None
    website: str | None
    phone: str | None
    email: str | None
    industry: str | None
    notes: str | None
    group_id: int | None
    category_code: str | None
    counterparty_id: int | None
    # CONTACTS 2.0 Ф0: реквизиты стороны договора
    full_legal_form: str | None = None
    legal_form: str | None = None
    gender_ending_oe: str | None = None
    country_code: str | None = None
    director_position: str | None = None
    director_genitive: str | None = None
    director_short: str | None = None
    acts_basis: str | None = None
    tax_id_label: str | None = None
    address: str | None = None
    bank: str | None = None
    bank_code_label: str | None = None
    bank_code: str | None = None
    account: str | None = None
    turnover_rub: Decimal | None = None
    responsible_user_id: int | None = None
    # CONTACTS 2.0 Ф0: классификация
    source: str | None = None
    company_type_id: int | None = None
    holding_role: str | None = None
    tags: list[str] = Field(default_factory=list)
    # Эпик 14: owner + department для scope-фильтра видимости
    owner_user_id: int | None = None
    department_id: int | None = None
    # Эпик 8: extra_fields (custom fields scope='company')
    extra_fields: dict[str, Any] = Field(default_factory=dict)
    created_at: datetime
    updated_at: datetime


class CompanyCreate(BaseModel):
    legal_name: str = Field(min_length=1, max_length=255)
    short_name: str | None = Field(default=None, max_length=128)
    name: str | None = Field(default=None, max_length=255)
    tax_id: str | None = Field(default=None, max_length=32)
    country: str | None = Field(default=None, min_length=2, max_length=2)
    city: str | None = Field(default=None, max_length=128)
    website: str | None = Field(default=None, max_length=255)
    phone: str | None = Field(default=None, max_length=64)
    email: str | None = Field(default=None, max_length=255)
    industry: str | None = Field(default=None, max_length=64)
    notes: str | None = None
    group_id: int | None = None
    category_code: str | None = Field(default=None, max_length=8)
    counterparty_id: int | None = None
    # CONTACTS 2.0 Ф0: реквизиты стороны договора
    full_legal_form: str | None = Field(default=None, max_length=255)
    legal_form: str | None = Field(default=None, max_length=64)
    gender_ending_oe: str | None = Field(default=None, max_length=16)
    country_code: str | None = Field(default=None, min_length=2, max_length=2)
    director_position: str | None = Field(default=None, max_length=128)
    director_genitive: str | None = Field(default=None, max_length=255)
    director_short: str | None = Field(default=None, max_length=128)
    acts_basis: str | None = Field(default=None, max_length=64)
    tax_id_label: str | None = Field(default=None, max_length=16)
    address: str | None = None
    bank: str | None = Field(default=None, max_length=255)
    bank_code_label: str | None = Field(default=None, max_length=32)
    bank_code: str | None = Field(default=None, max_length=64)
    account: str | None = Field(default=None, max_length=64)
    turnover_rub: Decimal | None = None
    responsible_user_id: int | None = None
    # CONTACTS 2.0 Ф0: классификация
    source: str | None = Field(default=None, max_length=32)
    company_type_id: int | None = None
    holding_role: str | None = Field(default=None, max_length=16)
    tags: list[str] = Field(default_factory=list)
    # Эпик 14: owner+department (department подменится из owner если не указан)
    owner_user_id: int | None = None
    department_id: int | None = None

    @field_validator("country", "country_code")
    @classmethod
    def _norm_country(cls, v: str | None) -> str | None:
        # Нормализуем ISO-код к верхнему регистру: фильтры/зеркало используют
        # .upper(), а пользователь может прислать 'kz'. Без этого — рассинхрон.
        return v.upper() if v else v


class CompanyUpdate(BaseModel):
    legal_name: str | None = Field(default=None, min_length=1, max_length=255)
    short_name: str | None = Field(default=None, max_length=128)
    name: str | None = Field(default=None, max_length=255)
    tax_id: str | None = Field(default=None, max_length=32)
    country: str | None = Field(default=None, min_length=2, max_length=2)
    city: str | None = Field(default=None, max_length=128)
    website: str | None = Field(default=None, max_length=255)
    phone: str | None = Field(default=None, max_length=64)
    email: str | None = Field(default=None, max_length=255)
    industry: str | None = Field(default=None, max_length=64)
    notes: str | None = None
    group_id: int | None = None
    category_code: str | None = Field(default=None, max_length=8)
    counterparty_id: int | None = None
    # CONTACTS 2.0 Ф0: реквизиты стороны договора
    full_legal_form: str | None = Field(default=None, max_length=255)
    legal_form: str | None = Field(default=None, max_length=64)
    gender_ending_oe: str | None = Field(default=None, max_length=16)
    country_code: str | None = Field(default=None, min_length=2, max_length=2)
    director_position: str | None = Field(default=None, max_length=128)
    director_genitive: str | None = Field(default=None, max_length=255)
    director_short: str | None = Field(default=None, max_length=128)
    acts_basis: str | None = Field(default=None, max_length=64)
    tax_id_label: str | None = Field(default=None, max_length=16)
    address: str | None = None
    bank: str | None = Field(default=None, max_length=255)
    bank_code_label: str | None = Field(default=None, max_length=32)
    bank_code: str | None = Field(default=None, max_length=64)
    account: str | None = Field(default=None, max_length=64)
    turnover_rub: Decimal | None = None
    responsible_user_id: int | None = None
    # CONTACTS 2.0 Ф0: классификация
    source: str | None = Field(default=None, max_length=32)
    company_type_id: int | None = None
    holding_role: str | None = Field(default=None, max_length=16)
    tags: list[str] | None = None
    # Эпик 14: owner+department
    owner_user_id: int | None = None
    department_id: int | None = None

    @field_validator("country", "country_code")
    @classmethod
    def _norm_country(cls, v: str | None) -> str | None:
        return v.upper() if v else v


# ============ Helpers ============

# Поля Company, нужные pure-function company_to_counterparty_fields для
# построения зеркала-Counterparty. Держим список явно, чтобы не тащить весь
# ORM-инстанс в чистую функцию.
_MIRROR_SOURCE_FIELDS: tuple[str, ...] = (
    "name", "legal_name", "country", "country_code", "city",
    "legal_form", "full_legal_form", "gender_ending_oe",
    "director_position", "director_genitive", "director_short", "acts_basis",
    "tax_id", "tax_id_label", "address",
    "bank", "bank_code_label", "bank_code", "account",
    "phone", "email", "website",
    "group_id", "category_code",
    "owner_user_id", "responsible_user_id", "department_id",
)


def company_to_dict(company: Company) -> dict[str, Any]:
    """Company ORM → dict нужных для зеркала полей (вход pure-function маппера)."""
    return {f: getattr(company, f, None) for f in _MIRROR_SOURCE_FIELDS}


async def _get_company_or_404(session: AsyncSession, company_id: int) -> Company:
    company = (await session.execute(
        select(Company).where(Company.id == company_id)
    )).scalar_one_or_none()
    if not company:
        raise HTTPException(404, "Компания не найдена")
    return company


def _is_elevated(user: User) -> bool:
    """admin/director могут задавать произвольный owner/department (reassignment)."""
    return user.role in _DEFAULT_ELEVATED


async def _validate_group_id(session: AsyncSession, group_id: int | None) -> None:
    if group_id is None:
        return
    exists = (await session.execute(
        select(ClientGroup.id).where(ClientGroup.id == group_id)
    )).scalar_one_or_none()
    if not exists:
        raise HTTPException(404, f"Группа клиентов {group_id} не найдена")


async def _validate_counterparty_id(session: AsyncSession, counterparty_id: int | None) -> None:
    if counterparty_id is None:
        return
    exists = (await session.execute(
        select(Counterparty.id).where(Counterparty.id == counterparty_id)
    )).scalar_one_or_none()
    if not exists:
        raise HTTPException(404, f"Контрагент {counterparty_id} не найден")


async def _sync_mirror_group_id(session: AsyncSession, company: Company) -> None:
    """Протянуть company.group_id в Counterparty-зеркало.

    sync_company_mirror (for_sync=True) НЕ синхронизирует ownership/scope-поля,
    включая group_id — это зона CS, чтобы PATCH компании не перетирал её.
    Но смена холдинга (holding-link эндпоинты) — это легитимное изменение группы
    самой компании, и её надо явно отразить в зеркале: категоризация/реестр
    читают принадлежность к группе по Counterparty.group_id. No-op, если зеркала
    нет. Caller отвечает за commit.
    """
    if company.counterparty_id is None:
        return
    mirror = (await session.execute(
        select(Counterparty).where(Counterparty.id == company.counterparty_id)
    )).scalar_one_or_none()
    if mirror is not None:
        mirror.group_id = company.group_id


# ============ Endpoints ============

@router.get("", response_model=list[CompanyOut])
async def list_companies(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    country: str | None = None,
    category_code: str | None = None,
    group_id: int | None = None,
    q: str | None = None,
    limit: int = 100,
    offset: int = 0,
):
    """Список компаний с фильтрами (country, category_code, group_id, q по legal_name/short_name/tax_id/email).

    Эпик 14: Company получил owner_user_id + department_id (миграция 0036).
    Scope-фильтр через visibility_settings (default 'all' — бэквард-совместимо).
    """
    stmt = select(Company).order_by(Company.legal_name)
    if country:
        # Нормализуем фильтр к верхнему регистру — данные хранятся uppercase
        # (валидатор на create/update), а UI может прислать 'kz'.
        stmt = stmt.where(Company.country == country.upper())
    if category_code:
        stmt = stmt.where(Company.category_code == category_code)
    if group_id is not None:
        stmt = stmt.where(Company.group_id == group_id)
    if q:
        like = f"%{q}%"
        stmt = stmt.where(or_(
            Company.legal_name.ilike(like),
            Company.short_name.ilike(like),
            Company.tax_id.ilike(like),
            Company.email.ilike(like),
        ))
    # Эпик 14: scope-фильтр
    from app.services.access_control import scope_query
    stmt = await scope_query(session, stmt, Company, "company", current_user)
    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))
    return (await session.execute(stmt)).scalars().all()


@router.post("", response_model=CompanyOut, status_code=status.HTTP_201_CREATED)
async def create_company(
    data: CompanyCreate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    await _validate_group_id(session, data.group_id)
    await _validate_counterparty_id(session, data.counterparty_id)
    from app.services.access_control import autofill_department_from_owner
    body = data.model_dump()
    # P0 security: mass-assignment owner/department. Non-elevated роли НЕ могут
    # создавать компанию на чужого владельца / чужой отдел — форсим себя и
    # деривируем department из своего owner. admin/director — reassignment ок.
    if not _is_elevated(current_user):
        body["owner_user_id"] = current_user.id
        body["department_id"] = None  # перебьётся autofill из owner ниже
    # Эпик 14: автозаливка department_id из owner.department_id (если не указан).
    body["department_id"] = await autofill_department_from_owner(
        session, body.get("owner_user_id"), body.get("department_id"),
    )
    company = Company(**body)
    session.add(company)
    await session.flush()  # получить company.id до создания зеркала

    # CONTACTS 2.0 mirror-инвариант: у каждой Company должно быть Counterparty-
    # зеркало. Многие пути (подписки, sign-flow, дормантные хендлеры) зависят от
    # counterparty_id; client_subscriptions.counterparty_id — NOT NULL. Если
    # явное counterparty_id не передано — создаём зеркало в той же транзакции.
    mirror_created = False
    if company.counterparty_id is None:
        from app.services.contacts_v2 import company_to_counterparty_fields
        mirror = Counterparty(**company_to_counterparty_fields(company_to_dict(company)))
        session.add(mirror)
        await session.flush()
        company.counterparty_id = mirror.id
        mirror_created = True

    # Эпик 8: audit — пишем ДО единственного commit (атомарность: компания,
    # зеркало и audit-события коммитятся вместе).
    await log_change(
        session, entity_type="company", entity_id=company.id,
        user_id=current_user.id, action="create",
        after=snapshot_entity(company, _COMPANY_AUDIT_FIELDS),
    )
    if mirror_created:
        await log_change(
            session, entity_type="counterparty", entity_id=company.counterparty_id,
            user_id=current_user.id, action="create",
            diff_override={"created_as_company_mirror": company.id},
        )
    await session.commit()
    await session.refresh(company)
    return company


@router.get("/by-counterparty/{counterparty_id}", response_model=CompanyOut)
async def get_company_by_counterparty(
    counterparty_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """CONTACTS 2.0 Ф3-C: резолв Company по legacy counterparty_id.

    Используется страницей /counterparties/[id] для клиентского редиректа
    на /companies/[company_id]. Возвращает 404 если зеркало не найдено.
    """
    company = (await session.execute(
        select(Company).where(Company.counterparty_id == counterparty_id)
    )).scalar_one_or_none()
    if not company:
        raise HTTPException(404, f"Компания для контрагента {counterparty_id} не найдена")
    await ensure_object_visible(session, company, "company", current_user)
    return company


@router.get("/{company_id}", response_model=CompanyOut)
async def get_company(
    company_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    company = await _get_company_or_404(session, company_id)
    await ensure_object_visible(session, company, "company", current_user)
    return company


@router.get("/{company_id}/contacts", response_model=list[ContactOut])
async def list_company_contacts(
    company_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Список контактов, привязанных к компании. Primary контакт — первым."""
    company = await _get_company_or_404(session, company_id)
    # P0 security: object-level IDOR — 404 на чужую компанию вне scope.
    await ensure_object_visible(session, company, "company", current_user)
    return (await session.execute(
        select(Contact)
        .where(Contact.company_id == company_id)
        .order_by(Contact.is_primary.desc(), Contact.full_name)
    )).scalars().all()


@router.patch("/{company_id}", response_model=CompanyOut)
async def update_company(
    company_id: int,
    data: CompanyUpdate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    company = await _get_company_or_404(session, company_id)
    # P0 security: WRITE-IDOR — нельзя править чужую компанию (и её зеркало) вне scope.
    await ensure_object_visible(session, company, "company", current_user)
    patch = data.model_dump(exclude_unset=True)
    if "group_id" in patch:
        await _validate_group_id(session, patch["group_id"])
    if "counterparty_id" in patch:
        await _validate_counterparty_id(session, patch["counterparty_id"])
    # P0 security: mass-assignment — non-elevated роли не могут переназначить
    # owner/department чужому/произвольному. Игнорируем эти ключи в PATCH.
    if not _is_elevated(current_user):
        patch.pop("owner_user_id", None)
        patch.pop("department_id", None)
    # Эпик 14: автозаливка department_id если меняется owner_user_id, но
    # department_id явно не передан в PATCH.
    if "owner_user_id" in patch and "department_id" not in patch:
        from app.services.access_control import autofill_department_from_owner
        patch["department_id"] = await autofill_department_from_owner(
            session, patch.get("owner_user_id"), None,
        )
    # Эпик 8: snapshot
    before = snapshot_entity(company, _COMPANY_AUDIT_FIELDS)
    for k, v in patch.items():
        setattr(company, k, v)
    # CONTACTS 2.0: синхронизировать реквизиты/имя/город обратно в
    # Counterparty-зеркало — реестр CS / зарплата / аналитика читают оттуда.
    await sync_company_mirror(session, company)
    after = snapshot_entity(company, _COMPANY_AUDIT_FIELDS)
    await log_change(
        session, entity_type="company", entity_id=company.id,
        user_id=current_user.id, action="update", before=before, after=after,
    )
    await session.commit()
    await session.refresh(company)
    return company


@router.delete("/{company_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_company(
    company_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить компанию (admin/director). Защищает данные так же, как
    delete_counterparty:

    - запрет при наличии договоров (через counterparty-зеркало) или активных
      подписок (company_id или зеркало);
    - удаление физических файлов с диска + записей Folder/File (полиморфный
      owner='company');
    - удаление мирор-Counterparty (если на нём нет договоров; иначе запрет).
    """
    import asyncio

    from app.models import ClientSubscription, File as CrmFile, Folder
    from app.routers.contacts_v2 import _safe_unlink

    company = await _get_company_or_404(session, company_id)
    mirror_id = company.counterparty_id

    # (а) блокировка при договорах / активных подписках.
    contract_ids = [mirror_id] if mirror_id is not None else []
    if contract_ids:
        n_contracts = (await session.execute(
            select(func.count()).select_from(Contract).where(
                Contract.counterparty_id.in_(contract_ids)
            )
        )).scalar_one()
        if n_contracts:
            raise HTTPException(
                400,
                f"Нельзя удалить: к компании привязаны договоры ({n_contracts}). "
                "Сначала открепите или удалите их.",
            )
    # Активные подписки — по company_id ИЛИ по зеркалу.
    sub_conds = [ClientSubscription.company_id == company_id]
    if mirror_id is not None:
        sub_conds.append(ClientSubscription.counterparty_id == mirror_id)
    n_active_subs = (await session.execute(
        select(func.count()).select_from(ClientSubscription).where(
            ClientSubscription.is_active.is_(True),
            or_(*sub_conds),
        )
    )).scalar_one()
    if n_active_subs:
        raise HTTPException(
            400,
            f"Нельзя удалить: у компании есть активные подписки ({n_active_subs}). "
            "Сначала закройте их в реестре.",
        )

    # (б) удалить физические файлы с диска (записи Folder/File уйдут ниже).
    files = (await session.execute(
        select(CrmFile).where(
            CrmFile.owner_entity_type == "company",
            CrmFile.owner_entity_id == company_id,
        )
    )).scalars().all()
    for f in files:
        await asyncio.to_thread(_safe_unlink, f.file_path)
    for f in files:
        await session.delete(f)
    folders = (await session.execute(
        select(Folder).where(
            Folder.owner_entity_type == "company",
            Folder.owner_entity_id == company_id,
        )
    )).scalars().all()
    for fl in folders:
        await session.delete(fl)

    before = snapshot_entity(company, _COMPANY_AUDIT_FIELDS)
    await log_change(
        session, entity_type="company", entity_id=company.id,
        user_id=current_user.id, action="delete", before=before,
    )
    await session.delete(company)
    await session.flush()

    # (в) удалить мирор-Counterparty (договоров на нём нет — проверено выше).
    if mirror_id is not None:
        mirror = (await session.execute(
            select(Counterparty).where(Counterparty.id == mirror_id)
        )).scalar_one_or_none()
        if mirror is not None:
            await log_change(
                session, entity_type="counterparty", entity_id=mirror_id,
                user_id=current_user.id, action="delete",
                diff_override={"deleted_with_company": company_id},
            )
            await session.delete(mirror)

    await session.commit()


# ============ CONTACTS 2.0 Ф1: сотрудники компании (M2M, обратная сторона) ============

class CompanyEmployeeOut(ContactCompanyLinkOut):
    """Связь + денормализованные поля контакта для списка сотрудников компании."""
    contact_full_name: str | None = None
    contact_phone: str | None = None
    contact_email: str | None = None


class CompanyEmployeeAttach(BaseModel):
    """Body POST /companies/{id}/contacts.

    Либо привязать существующий контакт (contact_id), либо создать новый
    (передать new_contact). Один из двух обязателен.
    """
    contact_id: int | None = None
    new_contact: ContactCreate | None = None
    position: str | None = Field(default=None, max_length=128)
    position_id: int | None = None
    employment_status: str = Field(default="works", max_length=16)
    is_primary: bool = False


@router.get("/{company_id}/employees", response_model=list[CompanyEmployeeOut])
async def list_company_employees(
    company_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Сотрудники компании через M2M-связи (primary первым).

    NB: legacy `GET /companies/{id}/contacts` (выше) возвращает старые
    Contact.company_id-привязки. Этот эндпоинт — новая M2M-модель.
    """
    company = await _get_company_or_404(session, company_id)
    await ensure_object_visible(session, company, "company", current_user)
    rows = (await session.execute(
        select(ContactCompanyLink, Contact)
        .join(Contact, ContactCompanyLink.contact_id == Contact.id)
        .where(ContactCompanyLink.company_id == company_id)
        .order_by(ContactCompanyLink.is_primary.desc(), Contact.full_name)
    )).all()
    out: list[CompanyEmployeeOut] = []
    for link, contact in rows:
        item = CompanyEmployeeOut.model_validate(link)
        item.contact_full_name = contact.full_name
        item.contact_phone = contact.phone
        item.contact_email = contact.email
        out.append(item)
    return out


@router.post(
    "/{company_id}/contacts",
    response_model=ContactCompanyLinkOut,
    status_code=status.HTTP_201_CREATED,
)
async def attach_company_contact(
    company_id: int,
    data: CompanyEmployeeAttach,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Привязать существующий контакт или создать нового сотрудника компании."""
    company = await _get_company_or_404(session, company_id)
    # P0 security: object-level IDOR — нельзя цеплять контакт к чужой компании.
    await ensure_object_visible(session, company, "company", current_user)
    await _validate_position_id(session, data.position_id)

    if data.contact_id is not None:
        contact = (await session.execute(
            select(Contact).where(Contact.id == data.contact_id)
        )).scalar_one_or_none()
        if not contact:
            raise HTTPException(404, f"Контакт {data.contact_id} не найден")
        contact_id = contact.id
    elif data.new_contact is not None:
        nc = data.new_contact
        # P0 security: mass-assignment owner_id. Non-elevated роли не могут
        # создавать контакт на чужого владельца — форсим себя. admin/director ок.
        new_owner_id = (
            nc.owner_id if (_is_elevated(current_user) and nc.owner_id is not None)
            else current_user.id
        )
        contact = Contact(
            full_name=nc.full_name, email=nc.email, phone=nc.phone,
            position=data.position or nc.position,
            owner_id=new_owner_id,
            tg_username=nc.tg_username, notes=nc.notes,
            source=nc.source, tags=nc.tags, status=nc.status,
        )
        session.add(contact)
        await session.flush()
        contact_id = contact.id
    else:
        raise HTTPException(400, "Передай contact_id или new_contact")

    link = await _upsert_link(
        session,
        contact_id=contact_id,
        company_id=company_id,
        position=data.position,
        position_id=data.position_id,
        employment_status=data.employment_status,
        is_primary=data.is_primary,
    )
    await session.commit()
    await session.refresh(link)
    return link


@router.patch(
    "/{company_id}/contacts/{contact_id}",
    response_model=ContactCompanyLinkOut,
)
async def update_company_contact_link(
    company_id: int,
    contact_id: int,
    data: ContactCompanyLinkUpdate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Изменить связь сотрудник↔компания (обратная сторона M2M)."""
    company = await _get_company_or_404(session, company_id)
    # P0 security: object-level IDOR — нельзя править связи чужой компании.
    await ensure_object_visible(session, company, "company", current_user)
    link = (await session.execute(
        select(ContactCompanyLink).where(
            ContactCompanyLink.company_id == company_id,
            ContactCompanyLink.contact_id == contact_id,
        )
    )).scalar_one_or_none()
    if not link:
        raise HTTPException(404, "Связь компания↔контакт не найдена")
    patch = data.model_dump(exclude_unset=True)
    if "position_id" in patch:
        await _validate_position_id(session, patch["position_id"])
    for k, v in patch.items():
        setattr(link, k, v)
    if patch.get("is_primary") is True:
        # снять primary у других сотрудников этой компании
        others = (await session.execute(
            select(ContactCompanyLink).where(
                ContactCompanyLink.company_id == company_id,
                ContactCompanyLink.contact_id != contact_id,
                ContactCompanyLink.is_primary.is_(True),
            )
        )).scalars().all()
        for o in others:
            o.is_primary = False
    await session.commit()
    await session.refresh(link)
    return link


@router.delete(
    "/{company_id}/contacts/{contact_id}",
    status_code=status.HTTP_204_NO_CONTENT,
)
async def detach_company_contact(
    company_id: int,
    contact_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Отвязать сотрудника от компании (удалить M2M-связь)."""
    company = await _get_company_or_404(session, company_id)
    # P0 security: object-level IDOR — нельзя удалять связи чужой компании.
    await ensure_object_visible(session, company, "company", current_user)
    link = (await session.execute(
        select(ContactCompanyLink).where(
            ContactCompanyLink.company_id == company_id,
            ContactCompanyLink.contact_id == contact_id,
        )
    )).scalar_one_or_none()
    if not link:
        raise HTTPException(404, "Связь компания↔контакт не найдена")
    await session.delete(link)
    await session.commit()


# ============ CONTACTS 2.0 Ф1: холдинг компании ============

class HoldingLinkIn(BaseModel):
    """Body POST /companies/{id}/holding-links — поместить компанию в холдинг."""
    holding_id: int  # ClientGroup.id
    role: str = Field(default="subsidiary")  # parent | subsidiary
    confirm: bool = False  # перезаписать существующего parent


class HoldingLinkPatch(BaseModel):
    role: str  # parent | subsidiary
    confirm: bool = False


class HoldingMemberOut(BaseModel):
    company_id: int
    name: str | None = None
    legal_name: str
    holding_role: str | None = None
    country: str | None = None
    city: str | None = None


class CompanyHoldingOut(BaseModel):
    holding_id: int | None = None
    holding_name: str | None = None
    members: list[HoldingMemberOut] = Field(default_factory=list)


async def _holding_members(session: AsyncSession, group_id: int) -> list[Company]:
    return list((await session.execute(
        select(Company).where(Company.group_id == group_id).order_by(Company.legal_name)
    )).scalars().all())


@router.get("/{company_id}/holding", response_model=CompanyHoldingOut)
async def get_company_holding(
    company_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Холдинг компании + все компании в нём с holding_role."""
    company = await _get_company_or_404(session, company_id)
    # P0 security: object-level IDOR — не палить холдинг чужой компании.
    await ensure_object_visible(session, company, "company", current_user)
    if company.group_id is None:
        return CompanyHoldingOut()
    group = (await session.execute(
        select(ClientGroup).where(ClientGroup.id == company.group_id)
    )).scalar_one_or_none()
    members = await _holding_members(session, company.group_id)
    return CompanyHoldingOut(
        holding_id=company.group_id,
        holding_name=group.name if group else None,
        members=[
            HoldingMemberOut(
                company_id=m.id, name=m.name, legal_name=m.legal_name,
                holding_role=m.holding_role,
                country=m.country, city=m.city,
            )
            for m in members
        ],
    )


@router.post("/{company_id}/holding-links", response_model=CompanyHoldingOut)
async def set_company_holding(
    company_id: int,
    data: HoldingLinkIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Поместить компанию в холдинг (group_id) с ролью parent/subsidiary.

    Если role=parent и в холдинге уже есть другой parent — 409, пока не передан
    confirm=true (тогда старый parent → subsidiary).
    """
    company = await _get_company_or_404(session, company_id)
    # P0 security: object-level IDOR — нельзя двигать чужую компанию в холдинг.
    await ensure_object_visible(session, company, "company", current_user)
    try:
        validate_holding_role(data.role)
    except ValueError as ex:
        raise HTTPException(400, str(ex)) from None
    await _validate_group_id(session, data.holding_id)

    # Существующие роли в целевом холдинге (учитывая, что компания может туда
    # переезжать — её текущую роль тоже считаем).
    members = await _holding_members(session, data.holding_id)
    existing = [(m.id, m.holding_role) for m in members]
    # company пока может не быть в этом холдинге — добавим её гипотетически.
    if company.group_id != data.holding_id:
        existing.append((company.id, None))
    conflict_id = detect_parent_conflict(existing, company.id, data.role)
    if conflict_id is not None and not data.confirm:
        raise HTTPException(
            409,
            f"В холдинге уже есть головная компания (id={conflict_id}). "
            f"Передай confirm=true, чтобы переназначить её в дочернюю.",
        )
    if conflict_id is not None and data.confirm:
        old_parent = (await session.execute(
            select(Company).where(Company.id == conflict_id)
        )).scalar_one_or_none()
        if old_parent is not None:
            old_parent.holding_role = "subsidiary"

    before = snapshot_entity(company, _COMPANY_AUDIT_FIELDS)
    company.group_id = data.holding_id
    company.holding_role = data.role
    # Реквизиты/display → зеркало. group_id sync_company_mirror НЕ трогает
    # (зона CS), поэтому смену холдинга протягиваем в зеркало явно ниже.
    await sync_company_mirror(session, company)
    await _sync_mirror_group_id(session, company)
    await log_change(
        session, entity_type="company", entity_id=company.id,
        user_id=current_user.id, action="update", before=before,
        after=snapshot_entity(company, _COMPANY_AUDIT_FIELDS),
    )
    await session.commit()
    return await get_company_holding(company_id, current_user, session)


@router.patch("/{company_id}/holding-links/{linked_id}", response_model=CompanyHoldingOut)
async def update_company_holding_role(
    company_id: int,
    linked_id: int,
    data: HoldingLinkPatch,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Сменить роль компании linked_id внутри холдинга company_id.

    company_id — любая компания холдинга (используем её group_id как холдинг).
    linked_id — компания, которой меняем роль.
    """
    anchor = await _get_company_or_404(session, company_id)
    # P0 security: object-level IDOR — огораживаем и anchor, и target.
    await ensure_object_visible(session, anchor, "company", current_user)
    if anchor.group_id is None:
        raise HTTPException(400, "Компания не состоит в холдинге")
    target = await _get_company_or_404(session, linked_id)
    await ensure_object_visible(session, target, "company", current_user)
    if target.group_id != anchor.group_id:
        raise HTTPException(400, "Компании в разных холдингах")
    try:
        validate_holding_role(data.role)
    except ValueError as ex:
        raise HTTPException(400, str(ex)) from None
    members = await _holding_members(session, anchor.group_id)
    existing = [(m.id, m.holding_role) for m in members]
    conflict_id = detect_parent_conflict(existing, target.id, data.role)
    if conflict_id is not None and not data.confirm:
        raise HTTPException(
            409,
            f"В холдинге уже есть головная компания (id={conflict_id}). "
            f"Передай confirm=true для переназначения.",
        )
    if conflict_id is not None and data.confirm:
        old_parent = next((m for m in members if m.id == conflict_id), None)
        if old_parent is not None:
            old_parent.holding_role = "subsidiary"
    target.holding_role = data.role
    await session.commit()
    return await get_company_holding(company_id, current_user, session)


@router.delete(
    "/{company_id}/holding-links/{linked_id}",
    status_code=status.HTTP_204_NO_CONTENT,
)
async def remove_company_from_holding(
    company_id: int,
    linked_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Убрать компанию linked_id из холдинга (group_id=null, holding_role=null)."""
    target = await _get_company_or_404(session, linked_id)
    # P0 security: object-level IDOR — нельзя выдёргивать чужую компанию из холдинга.
    await ensure_object_visible(session, target, "company", current_user)
    target.group_id = None
    target.holding_role = None
    await sync_company_mirror(session, target)  # реквизиты/display
    await _sync_mirror_group_id(session, target)  # group_id — явно (sync не трогает)
    await session.commit()


# ============ CONTACTS 2.0 Ф1: сделки/договоры компании (read) ============

@router.get("/{company_id}/deals", response_model=list[ContactDealOut])
async def list_company_deals(
    company_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Сделки компании: через deal.company_id ИЛИ counterparty_id (зеркало)."""
    company = await _get_company_or_404(session, company_id)
    await ensure_object_visible(session, company, "company", current_user)
    conds = [Deal.company_id == company_id]
    if company.counterparty_id is not None:
        conds.append(Deal.counterparty_id == company.counterparty_id)
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


@router.get("/{company_id}/contracts", response_model=list[ContactContractOut])
async def list_company_contracts(
    company_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Договоры компании: через counterparty_id (зеркало). Переиспользует
    тот же источник, что и /counterparties/{id}/contracts."""
    company = await _get_company_or_404(session, company_id)
    await ensure_object_visible(session, company, "company", current_user)
    if company.counterparty_id is None:
        return []
    rows = (await session.execute(
        select(Contract)
        .where(Contract.counterparty_id == company.counterparty_id)
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
