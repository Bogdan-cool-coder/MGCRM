"""Lead (Эпик 1.0): CRUD + конверсия Lead → Counterparty + Deal.

Lead — отдельная сущность для входящего трафика до квалификации. Живёт в воронке
Pipeline.kind="lead" со своими этапами. При /convert создаёт Counterparty и Deal
в воронке продаж, помечает себя status=converted (не удаляется — сохраняем
историю/таймлайн для analytics-specialist в эпике 2).
"""
from __future__ import annotations

import logging
from datetime import UTC, datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import (
    CurrentUser,
    CurrentUserFlexible,
    DirectorOrAdmin,
    require_scope,
    scope_to_user,
)
from app.models import (
    Company,
    Contact,
    Counterparty,
    Deal,
    Lead,
    Pipeline,
    PipelineStage,
)
from app.services.audit import log_change, snapshot_entity
from app.services.country_resolver import infer_country_from_phone
from app.services.duplicates import normalize_email, normalize_phone

# Эпик 8: какие поля Lead логируем в audit при update/delete.
# Эпик 4.2: добавлены score / converted_deal_id (новые поля HubSpot-style).
_LEAD_AUDIT_FIELDS = [
    "name", "contact_email", "contact_phone", "owner_id",
    "pipeline_id", "stage_id", "status",
    "score", "converted_deal_id",
]

_logger = logging.getLogger(__name__)


async def _fire_automations_on_enter_stage(
    session: AsyncSession, pipeline_id: int, stage_id: int, target_type: str, target_id: int
) -> None:
    """Эпик 4: пройти автоматизации on_enter_stage. Не блокирует ответ — catch на всё."""
    try:
        from app.services.automation_executor import run_on_enter_stage
        await run_on_enter_stage(session, pipeline_id, stage_id, target_type, target_id)
        await session.commit()
    except Exception as e:  # noqa: BLE001
        _logger.warning(
            "automation on_enter_stage failed (pipeline=%s, stage=%s, %s=%s): %s",
            pipeline_id, stage_id, target_type, target_id, e,
        )


async def _fire_automations_on_create(
    session: AsyncSession, target_type: str, target_id: int
) -> None:
    """Эпик 4.1: пройти автоматизации on_create. Не блокирует ответ — catch на всё.

    Используется для лид-роутинга из Inbox и форм (change_owner round_robin /
    by_product / by_country) и любых других reactive-сценариев на момент INSERT.
    """
    try:
        from app.services.automation_executor import run_on_create_automations
        await run_on_create_automations(session, target_type, target_id)
        await session.commit()
    except Exception as e:  # noqa: BLE001
        _logger.warning(
            "automation on_create failed (%s=%s): %s",
            target_type, target_id, e,
        )

router = APIRouter(prefix="/leads", tags=["leads"])

# Допустимые источники лида (валидация на in)
_ALLOWED_SOURCES = {"manual", "form", "import", "api", "email", "tg", "wa"}
# Допустимые статусы лида
_ALLOWED_STATUSES = {"active", "converted", "archived", "lost"}


# ============ Pydantic-схемы ============

class LeadOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    contact_email: str | None
    contact_phone: str | None
    source: str
    owner_id: int | None
    pipeline_id: int
    stage_id: int
    status: str
    tags: list[str]
    notes: str | None
    converted_to_counterparty_id: int | None
    # CONTACTS 2.0 Ф3-C: company_id при конверсии (источник истины).
    converted_to_company_id: int | None = None
    # Эпик 4.2: прямая ссылка на созданный Deal (без JOIN'а через КА).
    converted_deal_id: int | None = None
    converted_at: datetime | None
    # Эпик 4.2: HubSpot lead scoring (0..100). CHECK enforced на БД-уровне.
    score: int | None = None
    # Эпик 8: extra_fields (custom fields scope='lead')
    extra_fields: dict[str, Any] = Field(default_factory=dict)
    created_at: datetime
    updated_at: datetime


class LeadCreate(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    contact_email: str | None = None
    contact_phone: str | None = None
    source: str = "manual"
    owner_id: int | None = None
    pipeline_id: int | None = None  # пусто → Lead pipeline по kind
    stage_id: int | None = None     # пусто → первый этап Lead pipeline
    status: str = "active"
    tags: list[str] = Field(default_factory=list)
    notes: str | None = None
    # Эпик 4.2: scoring (опционально на create — заполняется маркетологом)
    score: int | None = Field(default=None, ge=0, le=100)


class LeadUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=255)
    contact_email: str | None = None
    contact_phone: str | None = None
    source: str | None = None
    owner_id: int | None = None
    pipeline_id: int | None = None
    stage_id: int | None = None
    status: str | None = None
    tags: list[str] | None = None
    notes: str | None = None
    # Эпик 4.2: scoring (по обновлению; CHECK 0..100 enforced на БД)
    score: int | None = Field(default=None, ge=0, le=100)


class LeadConvert(BaseModel):
    counterparty_name: str | None = None
    counterparty_id: int | None = None  # привязка к существующему КА (legacy)
    # CONTACTS 2.0 Ф3-C: привязка к существующей Company (источник истины).
    # Если передан company_id — counterparty_id резолвится через company.counterparty_id.
    company_id: int | None = None
    sales_pipeline_id: int | None = None
    sales_stage_id: int | None = None
    # Эпик 4.2: explicit country override (если пользователь знает страну, не
    # доверяемся резолверу по телефону). ISO-2 (KZ/UZ/AE/...).
    country_code: str | None = Field(default=None, min_length=2, max_length=2)
    # Эпик 4.2: confirm создание нового КА даже если найден возможный дубль.
    # Без флага — 409 с инфой о дубле; с флагом — игнорируем и создаём новый.
    confirm_create_new: bool = False


class LeadConvertOut(BaseModel):
    lead: LeadOut
    counterparty_id: int
    deal_id: int
    created_new_counterparty: bool
    # CONTACTS 2.0 Ф3-C: company_id созданной/найденной Company.
    company_id: int | None = None


# ============ Helpers ============

async def _lead_pipeline(session: AsyncSession) -> Pipeline:
    pipe = (await session.execute(
        select(Pipeline).where(Pipeline.kind == "lead").order_by(Pipeline.sort_order)
    )).scalars().first()
    if not pipe:
        raise HTTPException(500, "Lead pipeline ещё не засеян (seed_lead_pipeline)")
    return pipe


async def _first_stage(session: AsyncSession, pipeline_id: int) -> PipelineStage:
    stage = (await session.execute(
        select(PipelineStage)
        .where(PipelineStage.pipeline_id == pipeline_id, PipelineStage.is_active.is_(True))
        .order_by(PipelineStage.sort_order)
    )).scalars().first()
    if not stage:
        raise HTTPException(500, "В воронке нет активных этапов")
    return stage


async def _get_lead_or_404(session: AsyncSession, lead_id: int) -> Lead:
    lead = (await session.execute(select(Lead).where(Lead.id == lead_id))).scalar_one_or_none()
    if not lead:
        raise HTTPException(404, "Лид не найден")
    return lead


async def _require_lead_visible(session: AsyncSession, lead_id: int, user) -> Lead:
    """Загрузить лид с проверкой видимости. 404 при отсутствии/нет доступа.

    P0 security (B2): зеркало deals._deal_or_403. Раньше item-эндпоинты лида
    (get/patch/convert/ai-prefill) грузили лид по прямому id без owner-scope —
    менеджер A читал/правил/конвертировал/AI-префиллил чужие лиды менеджера B.
    Теперь прогоняем owner-scope через ensure_object_visible("lead") и палим 404
    (не 403) на чужой лид, чтобы не раскрывать его существование. LIST-эндпоинт
    уже скоупится через scope_query; DELETE — DirectorOrAdmin (scope='all').
    """
    from app.services.access_control import ensure_object_visible

    lead = await _get_lead_or_404(session, lead_id)
    await ensure_object_visible(session, lead, "lead", user)
    return lead


def _validate_source(source: str) -> None:
    if source not in _ALLOWED_SOURCES:
        raise HTTPException(400, f"Недопустимый источник: {source}")


def _validate_status(s: str) -> None:
    if s not in _ALLOWED_STATUSES:
        raise HTTPException(400, f"Недопустимый статус: {s}")


def _merge_lead_notes(lead: Lead) -> str:
    """Эпик 4.2: собрать notes для нового Deal: префикс «Из лида #N (source=...)»
    + оригинальные notes лида (если есть)."""
    parts = [f"Из лида #{lead.id} (source={lead.source})"]
    if lead.notes:
        parts.append(lead.notes)
    return "\n\n".join(parts)


def _build_deal_extra_fields(lead: Lead) -> dict[str, Any]:
    """Эпик 4.2: при конверсии переносим в Deal.extra_fields:
    - все существующие extra_fields лида (custom fields клонируются);
    - lead_tags (если есть);
    - lead_source (для дашбордов «откуда пришёл клиент»);
    - lead_id (для бэк-связи и дедупа).
    """
    extra: dict[str, Any] = dict(lead.extra_fields or {})
    if lead.tags:
        extra["lead_tags"] = list(lead.tags)
    extra["lead_source"] = lead.source
    extra["lead_id"] = lead.id
    return extra


async def _find_counterparty_by_contact(
    session: AsyncSession, email: str | None, phone: str | None
) -> Company | None:
    """CONTACTS 2.0 Ф4: дедуп по нормализованному email/phone — ищем Company.

    Использует normalize_email/normalize_phone из services/duplicates.py (одинаковая
    логика с дублесканом /admin/duplicates). Возвращает первый match (если есть)
    или None. None также если оба контакта пустые после нормализации.

    NB: для phone используется LIKE по последним 10 цифрам — это совпадает с
    логикой normalize_phone (берёт последние 10 цифр). На stage MVP — full scan
    Company с фильтрацией in-Python; при росте > 10k компаний перейти на
    индексированную колонку normalized_phone.
    """
    norm_email = normalize_email(email)
    norm_phone = normalize_phone(phone)
    if not norm_email and not norm_phone:
        return None

    companies = (await session.execute(select(Company))).scalars().all()
    for co in companies:
        if norm_email and normalize_email(co.email) == norm_email:
            return co
        if norm_phone and normalize_phone(co.phone) == norm_phone:
            return co
    return None


# ============ Endpoints ============

@router.get("", response_model=list[LeadOut])
async def list_leads(
    current_user: CurrentUserFlexible,
    _scope: Annotated[None, Depends(require_scope("read:leads"))],
    session: Annotated[AsyncSession, Depends(get_session)],
    pipeline_id: int | None = None,
    stage_id: int | None = None,
    owner_id: int | None = None,
    status: str | None = None,
    source: str | None = None,
    q: str | None = None,
    min_score: int | None = Query(default=None, ge=0, le=100),
    sort: str | None = Query(
        default=None,
        description=(
            "Опциональный сорт: 'created_at_desc' (default), "
            "'score_desc', 'score_asc'. Для score_* NULL — всегда в конце."
        ),
    ),
    limit: int = 100,
    offset: int = 0,
):
    """Список лидов с фильтрами. Default pipeline_id = Lead pipeline по kind.

    Owner-scope (Эпик 0, RBAC): manager видит только свои лиды (где owner_id =
    его id). Admin/director/lawyer — все. Реализовано через app.deps.scope_to_user.

    Эпик 10: добавлены `min_score` (фильтр HubSpot lead-score >= X) и `sort`
    (по score asc/desc для дашборда «hot leads»).

    Эпик 11.1: auth — cookie ИЛИ Bearer (`Authorization: Bearer mc_...`),
    Bearer-токены проверяются по scope 'read:leads'. UI-flow (cookie) не меняется.
    """
    if pipeline_id is None:
        pipe = await _lead_pipeline(session)
        pipeline_id = pipe.id

    stmt = select(Lead).where(Lead.pipeline_id == pipeline_id)
    if stage_id is not None:
        stmt = stmt.where(Lead.stage_id == stage_id)
    if owner_id is not None:
        stmt = stmt.where(Lead.owner_id == owner_id)
    if status is not None:
        stmt = stmt.where(Lead.status == status)
    if source is not None:
        stmt = stmt.where(Lead.source == source)
    if min_score is not None:
        # Если score IS NULL → не подпадает (фильтр строгий: «лид без оценки —
        # не hot»).
        stmt = stmt.where(Lead.score.is_not(None))
        stmt = stmt.where(Lead.score >= min_score)
    if q:
        like = f"%{q}%"
        stmt = stmt.where(or_(
            Lead.name.ilike(like),
            Lead.contact_email.ilike(like),
            Lead.contact_phone.ilike(like),
        ))
    # Manager видит только своих лидов; admin/director/lawyer — все.
    stmt = scope_to_user(stmt, Lead, current_user, "owner_id")
    # Эпик 14: дополнительный scope-фильтр по матрице visibility_settings.
    # По умолчанию (scope='all') stmt не меняется — поведение совместимо.
    from app.services.access_control import scope_query
    stmt = await scope_query(session, stmt, Lead, "lead", current_user)

    # Sort: default — created_at desc (через updated_at для совместимости с
    # текущим поведением, где «свежеотредактированные» наверху).
    if sort == "score_desc":
        stmt = stmt.order_by(Lead.score.desc().nulls_last(), Lead.id.desc())
    elif sort == "score_asc":
        stmt = stmt.order_by(Lead.score.asc().nulls_last(), Lead.id.desc())
    elif sort == "created_at_desc":
        stmt = stmt.order_by(Lead.created_at.desc(), Lead.id.desc())
    else:
        # default — backwards compatible (updated_at, как было до Эпика 10)
        stmt = stmt.order_by(Lead.updated_at.desc())

    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))
    return (await session.execute(stmt)).scalars().all()


@router.post("", response_model=LeadOut, status_code=status.HTTP_201_CREATED)
async def create_lead(
    data: LeadCreate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    _validate_source(data.source)
    _validate_status(data.status)

    # Pipeline
    if data.pipeline_id is not None:
        pipe = (await session.execute(
            select(Pipeline).where(Pipeline.id == data.pipeline_id)
        )).scalar_one_or_none()
        if not pipe:
            raise HTTPException(404, "Воронка не найдена")
    else:
        pipe = await _lead_pipeline(session)

    # Stage
    if data.stage_id is not None:
        stg = (await session.execute(
            select(PipelineStage).where(PipelineStage.id == data.stage_id)
        )).scalar_one_or_none()
        if not stg or stg.pipeline_id != pipe.id:
            raise HTTPException(400, "Этап не из этой воронки")
    else:
        stg = await _first_stage(session, pipe.id)

    # Эпик 14: автозаливка department_id из owner.department_id (если не указан).
    from app.services.access_control import autofill_department_from_owner
    owner_id = data.owner_id if data.owner_id is not None else current_user.id
    dept_id = await autofill_department_from_owner(
        session, owner_id, current_department_id=None,
    )

    lead = Lead(
        name=data.name,
        contact_email=data.contact_email,
        contact_phone=data.contact_phone,
        source=data.source,
        owner_id=owner_id,
        pipeline_id=pipe.id,
        stage_id=stg.id,
        status=data.status,
        tags=data.tags,
        notes=data.notes,
        # Эпик 4.2: HubSpot lead scoring (опционально)
        score=data.score,
        # Эпик 14: department_id из owner (или None если у owner нет отдела)
        department_id=dept_id,
    )
    session.add(lead)
    await session.commit()
    await session.refresh(lead)
    # Эпик 8: audit create
    await log_change(
        session, entity_type="lead", entity_id=lead.id, user_id=current_user.id,
        action="create", after=snapshot_entity(lead, _LEAD_AUDIT_FIELDS),
    )
    await session.commit()
    # Эпик 4.1: автоматизации on_create (lead routing — change_owner round_robin
    # и т.п.). Catch на всё, не блокирует ответ.
    await _fire_automations_on_create(session, "lead", lead.id)
    # Эпик 11.2: outbound webhook lead.created
    from app.services.webhook_dispatcher import lead_to_payload, safe_dispatch_event
    await safe_dispatch_event(
        session, "lead.created", "lead", lead.id, lead_to_payload(lead),
    )
    await session.refresh(lead)
    return lead


@router.get("/{lead_id}", response_model=LeadOut)
async def get_lead(
    lead_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    # P0 security (B2): owner-scope guard — 404 на чужой лид.
    return await _require_lead_visible(session, lead_id, current_user)


@router.patch("/{lead_id}", response_model=LeadOut)
async def update_lead(
    lead_id: int,
    data: LeadUpdate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    # P0 security (B2): owner-scope guard — 404 на чужой лид перед мутацией.
    lead = await _require_lead_visible(session, lead_id, current_user)
    patch = data.model_dump(exclude_unset=True)

    if "source" in patch and patch["source"] is not None:
        _validate_source(patch["source"])
    if "status" in patch and patch["status"] is not None:
        _validate_status(patch["status"])
    if "stage_id" in patch and patch["stage_id"] is not None:
        new_pipeline_id = patch.get("pipeline_id", lead.pipeline_id)
        stg = (await session.execute(
            select(PipelineStage).where(PipelineStage.id == patch["stage_id"])
        )).scalar_one_or_none()
        if not stg or stg.pipeline_id != new_pipeline_id:
            raise HTTPException(400, "Этап не из этой воронки")
    if "pipeline_id" in patch and patch["pipeline_id"] is not None:
        new_pipeline_id = int(patch["pipeline_id"])
        pipe = (await session.execute(
            select(Pipeline).where(Pipeline.id == new_pipeline_id)
        )).scalar_one_or_none()
        if not pipe:
            raise HTTPException(404, "Воронка не найдена")
        # MEDIUM: при смене pipeline без явного stage_id — сбросить на первый
        # активный этап новой воронки, иначе stage_id останется от старой воронки
        # (orphaned cross-pipeline stage).
        if "stage_id" not in patch and new_pipeline_id != lead.pipeline_id:
            first = await _first_stage(session, new_pipeline_id)
            patch["stage_id"] = first.id

    # H8 HIGH: при смене owner_id пересчитать department_id из нового owner,
    # иначе лид остаётся в старом отделе (scope desync, visibility-матрица ломается).
    if "owner_id" in patch and patch["owner_id"] is not None and patch["owner_id"] != lead.owner_id:
        from app.services.access_control import autofill_department_from_owner
        patch["department_id"] = await autofill_department_from_owner(
            session, int(patch["owner_id"]), current_department_id=None,
        )

    # Эпик 4: засекаем смену этапа для on_enter_stage автоматизаций после commit
    stage_changed_to: int | None = None
    if "stage_id" in patch and patch["stage_id"] is not None and patch["stage_id"] != lead.stage_id:
        stage_changed_to = int(patch["stage_id"])

    # Эпик 8: snapshot перед изменениями (для audit)
    before = snapshot_entity(lead, _LEAD_AUDIT_FIELDS)
    for k, v in patch.items():
        setattr(lead, k, v)
    await session.commit()
    await session.refresh(lead)
    # Audit update — фиксируем diff по whitelist полей
    after = snapshot_entity(lead, _LEAD_AUDIT_FIELDS)
    await log_change(
        session, entity_type="lead", entity_id=lead.id, user_id=current_user.id,
        action="update", before=before, after=after,
    )
    await session.commit()
    if stage_changed_to is not None:
        await _fire_automations_on_enter_stage(
            session, lead.pipeline_id, stage_changed_to, "lead", lead.id
        )
    return lead


@router.delete("/{lead_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_lead(
    lead_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    lead = await _get_lead_or_404(session, lead_id)
    # Эпик 8: audit delete (snapshot до удаления)
    before = snapshot_entity(lead, _LEAD_AUDIT_FIELDS)
    await log_change(
        session, entity_type="lead", entity_id=lead.id, user_id=current_user.id,
        action="delete", before=before,
    )
    await session.delete(lead)
    await session.commit()


@router.post("/{lead_id}/convert", response_model=LeadConvertOut)
async def convert_lead(
    lead_id: int,
    data: LeadConvert,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Конвертация лида: создать Counterparty + Contact + Deal в sales pipeline.

    Эпик 4.2 — polish (закрытие 6 findings аудита):
    1. SELECT FOR UPDATE на Lead — защита от двух конкурентных конверсий.
    2. Country resolution: explicit `data.country_code` > infer_country_from_phone
       > 'ZZ' sentinel → HTTPException 400 (вместо плохого placeholder '--').
    3. Дедуп КА по email/phone (normalize_email/normalize_phone) — без
       `confirm_create_new` 409 с counterparty_id найденного дубля.
    4. Создаётся `Contact` (Эпик 1.2) с привязкой к owner лида.
    5. Notes из лида копируются в Deal.notes (через extra_fields, т.к. Deal
       не имеет колонки notes); tags/source попадают в Deal.extra_fields.
    6. Lead.converted_deal_id — прямая ссылка (без JOIN'а через КА).
    7. on_create автоматизация для нового Deal — лид-роутинг и уведомления.
    """
    # 1) Lock на Lead (без with_for_update второй параллельный POST увидит
    # status='active' и сделает второй Deal). На SQLite this становится noop.
    lead = (
        await session.execute(
            select(Lead).where(Lead.id == lead_id).with_for_update()
        )
    ).scalar_one_or_none()
    if not lead:
        raise HTTPException(404, "Лид не найден")
    # P0 security (B2): owner-scope guard ПЕРЕД конверсией — 404 на чужой лид.
    # Худший кейс IDOR: конверсия чужого лида в Counterparty+Contact+Deal.
    from app.services.access_control import ensure_object_visible
    await ensure_object_visible(session, lead, "lead", current_user)
    if lead.status == "converted":
        raise HTTPException(409, "Лид уже сконвертирован")

    # 2) Country resolution. Explicit override > infer > ZZ sentinel.
    country_code = (
        (data.country_code.upper() if data.country_code else None)
        or infer_country_from_phone(lead.contact_phone)
        or "ZZ"
    )
    if country_code == "ZZ":
        raise HTTPException(
            400,
            {
                "message": (
                    "Укажи страну контрагента (country_code) — не удалось "
                    "определить по телефону"
                ),
                "code": "country_required",
            },
        )

    # 3) Дедуп КА по email/phone (только если пользователь НЕ указал
    # counterparty_id/company_id явно). 409 с подсказкой; обойти confirm_create_new.
    if data.counterparty_id is None and data.company_id is None and (lead.contact_email or lead.contact_phone):
        matched = await _find_counterparty_by_contact(
            session, lead.contact_email, lead.contact_phone
        )
        if matched is not None and not data.confirm_create_new:
            # CONTACTS 2.0 Ф4: matched — Company (источник истины).
            # Возвращаем company_id для привязки через LeadConvert.company_id.
            # counterparty_id — зеркало (для backward-compat с legacy клиентами API).
            raise HTTPException(
                409,
                {
                    "message": (
                        "Найден возможный дубль компании. Передай "
                        "company_id для привязки или confirm_create_new=true."
                    ),
                    "company_id": matched.id,
                    "counterparty_id": matched.counterparty_id,
                    "counterparty_name": matched.name or matched.legal_name,
                    "code": "duplicate_found",
                },
            )

    # Sales pipeline + stage (по умолчанию — первая активная sales-воронка
    # и её первый этап).
    if data.sales_pipeline_id is not None:
        sales_pipe = (
            await session.execute(
                select(Pipeline).where(Pipeline.id == data.sales_pipeline_id)
            )
        ).scalar_one_or_none()
        if not sales_pipe:
            raise HTTPException(404, "Sales pipeline не найден")
    else:
        sales_pipe = (
            await session.execute(
                select(Pipeline)
                .where(Pipeline.kind == "sales")
                .order_by(Pipeline.sort_order)
            )
        ).scalars().first()
        if not sales_pipe:
            raise HTTPException(500, "Sales pipeline не настроен")

    if data.sales_stage_id is not None:
        sales_stg = (
            await session.execute(
                select(PipelineStage).where(
                    PipelineStage.id == data.sales_stage_id
                )
            )
        ).scalar_one_or_none()
        if not sales_stg or sales_stg.pipeline_id != sales_pipe.id:
            raise HTTPException(400, "Этап не из sales pipeline")
    else:
        sales_stg = await _first_stage(session, sales_pipe.id)

    # 4) Counterparty + Company: либо привязываем к существующим, либо создаём.
    # CONTACTS 2.0 Ф3-C: если передан company_id — резолвим через company.
    # Создаём зеркало Counterparty если его нет (чтобы договоры/реестр работали до Ф4).
    created_new_counterparty = False
    resolved_company: Company | None = None

    if data.company_id is not None:
        # Привязка к существующей Company (источник истины).
        resolved_company = (
            await session.execute(
                select(Company).where(Company.id == data.company_id)
            )
        ).scalar_one_or_none()
        if not resolved_company:
            raise HTTPException(404, f"Компания {data.company_id} не найдена")
        # Резолв Counterparty через зеркало.
        if resolved_company.counterparty_id is not None:
            counterparty = (
                await session.execute(
                    select(Counterparty).where(Counterparty.id == resolved_company.counterparty_id)
                )
            ).scalar_one_or_none()
            if not counterparty:
                raise HTTPException(500, "Зеркало counterparty для компании не найдено")
        else:
            # Зеркало ещё не создано — создаём.
            counterparty = Counterparty(
                name=resolved_company.name or resolved_company.legal_name,
                country_code=country_code,
                phone=resolved_company.phone or lead.contact_phone,
                email=resolved_company.email or lead.contact_email,
            )
            session.add(counterparty)
            await session.flush()
            resolved_company.counterparty_id = counterparty.id
            created_new_counterparty = True
        deal_title = resolved_company.name or resolved_company.legal_name
    elif data.counterparty_id is not None:
        counterparty = (
            await session.execute(
                select(Counterparty).where(Counterparty.id == data.counterparty_id)
            )
        ).scalar_one_or_none()
        if not counterparty:
            raise HTTPException(
                404, f"Контрагент {data.counterparty_id} не найден"
            )
        # Резолв Company по counterparty_id (зеркало).
        resolved_company = (
            await session.execute(
                select(Company).where(Company.counterparty_id == counterparty.id)
            )
        ).scalar_one_or_none()
        deal_title = counterparty.name
    else:
        # Создаём новый Counterparty + Company.
        cp_name = (data.counterparty_name or lead.name).strip()
        if not cp_name:
            raise HTTPException(400, "Имя контрагента пусто")
        counterparty = Counterparty(
            name=cp_name,
            country_code=country_code,  # реальный ISO-2, не placeholder
            phone=lead.contact_phone,
            email=lead.contact_email,
        )
        session.add(counterparty)
        await session.flush()  # нужен counterparty.id
        # Создаём зеркало Company для новой записи.
        resolved_company = Company(
            legal_name=cp_name,
            name=cp_name,
            country_code=country_code,
            phone=lead.contact_phone,
            email=lead.contact_email,
            counterparty_id=counterparty.id,
        )
        session.add(resolved_company)
        await session.flush()
        created_new_counterparty = True
        deal_title = cp_name

    # 5) Contact (Эпик 1.2): создаём ВСЕГДА — отдельная сущность для физлица.
    # Если КА существующий, контакт всё равно создаётся (бывает несколько
    # контактных лиц на одного КА).
    contact = Contact(
        full_name=lead.name,
        email=lead.contact_email,
        phone=lead.contact_phone,
        owner_id=lead.owner_id,
    )
    session.add(contact)
    await session.flush()

    # 6) Deal в sales-воронке с notes/tags/source из лида.
    deal_extra = _build_deal_extra_fields(lead)
    deal_notes = _merge_lead_notes(lead)
    # Deal не имеет колонки notes — кладём в extra_fields["notes_from_lead"]
    # (в карточке выводится в timeline-блоке).
    deal_extra["notes_from_lead"] = deal_notes

    deal = Deal(
        pipeline_id=sales_pipe.id,
        stage_id=sales_stg.id,
        counterparty_id=counterparty.id,
        # CONTACTS 2.0 Ф3-C: company_id — источник истины.
        company_id=resolved_company.id if resolved_company else None,
        title=deal_title,
        amount=None,
        currency=None,
        owner_user_id=lead.owner_id or current_user.id,
        stage_changed_at=datetime.now(UTC),
        extra_fields=deal_extra,
    )
    session.add(deal)
    await session.flush()  # нужен deal.id для Lead.converted_deal_id

    # 7) Помечаем лид как сконвертированный + сохраняем прямую ссылку на Deal.
    before_lead = snapshot_entity(lead, _LEAD_AUDIT_FIELDS)
    lead.status = "converted"
    lead.converted_to_counterparty_id = counterparty.id
    # CONTACTS 2.0 Ф3-C: company_id при конверсии.
    lead.converted_to_company_id = resolved_company.id if resolved_company else None
    lead.converted_deal_id = deal.id  # Эпик 4.2: прямая ссылка
    lead.converted_at = datetime.now(UTC)

    # Audit log — фиксируем факт конверсии (entity_type=lead, action=update).
    await log_change(
        session,
        entity_type="lead",
        entity_id=lead.id,
        user_id=current_user.id,
        action="update",
        before=before_lead,
        after=snapshot_entity(lead, _LEAD_AUDIT_FIELDS),
    )

    await session.commit()
    await session.refresh(lead)

    # 8) Эпик 4.2 — закрытие критического разрыва: дёргаем on_create
    # автоматизации для созданного Deal. Без этого автоматизации
    # «новая сделка — назначить owner round_robin» / «уведомить в TG» НЕ
    # срабатывали на конверсии из лида (срабатывали только на ручной POST /deals).
    await _fire_automations_on_create(session, "deal", deal.id)

    # Эпик 11.2: outbound webhook'и lead.converted и deal.created
    from app.services.webhook_dispatcher import (
        deal_to_payload,
        lead_to_payload,
        safe_dispatch_event,
    )
    lead_payload = lead_to_payload(lead)
    lead_payload["counterparty_id"] = counterparty.id
    lead_payload["deal_id"] = deal.id
    await safe_dispatch_event(
        session, "lead.converted", "lead", lead.id, lead_payload,
    )
    await safe_dispatch_event(
        session, "deal.created", "deal", deal.id, deal_to_payload(deal),
    )

    await session.refresh(lead)
    return LeadConvertOut(
        lead=LeadOut.model_validate(lead),
        counterparty_id=counterparty.id,
        deal_id=deal.id,
        created_new_counterparty=created_new_counterparty,
        company_id=resolved_company.id if resolved_company else None,
    )


# ============ Эпик 18 — AI Lead Prefill ============

from fastapi import Query as _Query  # noqa: E402

from app.schemas import (  # noqa: E402
    DealPrefillSuggestion as _DealPrefillSuggestion,
    LeadPrefillOut as _LeadPrefillOut,
)
from app.services.ai_features import prefill_for_target as _prefill_for_target  # noqa: E402
from app.services.anthropic_client import (  # noqa: E402
    AINotConfiguredError as _AINotConfiguredError,
    AIResponseError as _AIResponseError,
    AIServiceError as _AIServiceError,
)


@router.post("/{lead_id}/ai-prefill", response_model=_LeadPrefillOut)
async def ai_prefill_lead(
    lead_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    source: str = _Query(
        default="all",
        description="Источник данных: all | tg | email | notes | activities | messages",
    ),
    period_days: int = _Query(
        default=30,
        description="Период в днях. 0 = всё время. 7 / 30 / 90 — типовые значения.",
        ge=0, le=365,
    ),
):
    """Сгенерировать AI-предложения по полям лида на основе истории активностей.

    503 если AI не настроен; 502 если Claude недоступен/невалидный JSON.
    """
    # P0 security (B2): owner-scope guard ПЕРЕД вызовом Claude — 404 на чужой
    # лид (иначе denial-of-wallet: AI-расход на чужих лидах по прямому id).
    lead = await _require_lead_visible(session, lead_id, current_user)

    try:
        result = await _prefill_for_target(
            session, "lead", lead.id,
            user_id=current_user.id,
            source=source, period_days=period_days,
        )
    except _AINotConfiguredError:
        await session.commit()
        raise HTTPException(
            status.HTTP_503_SERVICE_UNAVAILABLE,
            "AI not configured: установите ANTHROPIC_API_KEY на бэкенде",
        )
    except (_AIResponseError, _AIServiceError) as e:
        await session.commit()
        raise HTTPException(
            status.HTTP_502_BAD_GATEWAY,
            f"AI service error: {e}",
        )
    await session.commit()

    return _LeadPrefillOut(
        lead_id=lead.id,
        source=source,
        period_days=period_days,
        summary=result.get("summary", ""),
        suggestions=[
            _DealPrefillSuggestion.model_validate(s)
            for s in result.get("suggestions", [])
        ],
        ai_tokens_used=result.get("ai_tokens_used"),
        model=result.get("model"),
    )
