"""Реестр клиентов / Customer Success (Фаза 4b): подписки, реестр, чек-лист,
модули, активность (ручной ввод), здоровье, дашборд KPI, «требуют внимания»."""
from __future__ import annotations

from datetime import UTC, datetime
from decimal import Decimal
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import Text, and_, cast
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin
from app.jobs.import_registry import run_import
from app.models import (
    ActivitySnapshot,
    ChecklistTemplate,
    ChecklistTemplateItem,
    ClientSubscription,
    Company,
    Counterparty,
    ImplementationItemStatus,
    Module,
    PipelineStage,
    Platform,
    Region,
    SubscriptionModule,
    SubscriptionStageHistory,
    User,
)
from app.schemas import (
    ActivityPut,
    ActivitySnapshotOut,
    ChecklistItemView,
    ChecklistPut,
    ChecklistView,
    RegistryKpiOut,
    RegistryRow,
    SubscriptionIn,
    SubscriptionModuleOut,
    SubscriptionModulesPut,
    SubscriptionOut,
    SubscriptionPatch,
)
from app.services.audit import log_change, snapshot_entity
from app.services.customer_success import (
    compute_kpis,
    item_completion,
    lifecycle_stage_by_code,
    recompute_checklist_pct,
    recompute_subscription_health,
)

# Эпик 8: какие поля Subscription логируем в audit (важные бизнес-поля)
_SUB_AUDIT_FIELDS = [
    "lifecycle_stage_id", "fee_actual", "fee_contract", "tariff",
    "seats", "discount_until", "auto_prolongation", "qa_result",
    "manual_tier_override", "is_active",
]

router = APIRouter(tags=["registry"])

_MONEY_FIELDS = {"fee_actual", "fee_contract"}


def _attention_sql_predicate():
    """SQL-предикат «требует внимания» = health_reasons непустой.

    health_reasons — generic JSON-колонка (default []). Питоновский фильтр
    `if s.health_reasons` truthy при non-None И непустом списке. Здесь зеркалим
    это в SQL, чтобы не грузить ВСЕ подписки в память: отбрасываем NULL и пустой
    массив (текстовое представление JSON-`[]`). Текстовый каст портативен для
    generic JSON на Postgres; финальный Python-guard `if s.health_reasons`
    остаётся как источник истины (на случай вариаций сериализации) — SQL лишь
    сокращает выборку.
    """
    col = ClientSubscription.health_reasons
    return and_(col.isnot(None), cast(col, Text) != "[]")


async def _require_sub(
    session: AsyncSession, sub_id: int, user: User | None = None,
) -> ClientSubscription:
    """Загрузить подписку + (если передан user) проверить object-level scope.

    P0 security (Unit 3b): подписка и её подресурсы (чек-лист/модули/активность)
    не должны утекать вне scope. 404 на чужую подписку (admin/director видят всё).
    """
    sub = (await session.execute(
        select(ClientSubscription).where(ClientSubscription.id == sub_id)
    )).scalar_one_or_none()
    if not sub:
        raise HTTPException(404, "Подписка не найдена")
    if user is not None:
        from app.services.access_control import ensure_object_visible
        await ensure_object_visible(session, sub, "subscription", user)
    return sub


async def _validate_lifecycle_stage(
    session: AsyncSession, stage_id: int | None,
) -> None:
    """P0 security (Unit 3b, C2 CRIT-3): stage_id обязан быть этапом lifecycle-воронки.

    Отклоняем (422) произвольные/чужие stage_id, чтобы в lifecycle_stage_id и в
    историю переходов не попадал мусор (to_stage_code из несвязанной воронки).
    None допустим (сброс этапа).
    """
    if stage_id is None:
        return
    from app.models import Pipeline
    row = (await session.execute(
        select(PipelineStage.id)
        .join(Pipeline, Pipeline.id == PipelineStage.pipeline_id)
        .where(PipelineStage.id == stage_id, Pipeline.kind == "lifecycle")
    )).scalar_one_or_none()
    if row is None:
        raise HTTPException(422, "lifecycle_stage_id не относится к lifecycle-воронке")


async def _registry_rows(session: AsyncSession, subs: list[ClientSubscription]) -> list[RegistryRow]:
    if not subs:
        return []
    sub_ids = [s.id for s in subs]
    stage_ids = {s.lifecycle_stage_id for s in subs if s.lifecycle_stage_id}
    plat_ids = {s.platform_id for s in subs}
    region_ids = {s.region_id for s in subs if s.region_id}
    user_ids = {s.sup_pm_user_id for s in subs} | {s.am_user_id for s in subs}
    user_ids.discard(None)

    # CONTACTS 2.0 Ф3-B: читаем Company как источник истины для имени/страны/категории.
    # Fallback на Counterparty если company_id не заполнен (переходной период до Ф4).
    company_ids = {s.company_id for s in subs if s.company_id}
    cp_ids_fallback = {s.counterparty_id for s in subs if not s.company_id}

    companies: dict[int, Company] = {}
    if company_ids:
        companies = {
            c.id: c for c in (
                await session.execute(select(Company).where(Company.id.in_(company_ids)))
            ).scalars().all()
        }
    # Fallback: load Counterparty только для подписок без company_id
    cps_fallback: dict[int, Counterparty] = {}
    if cp_ids_fallback:
        cps_fallback = {
            c.id: c for c in (
                await session.execute(select(Counterparty).where(Counterparty.id.in_(cp_ids_fallback)))
            ).scalars().all()
        }

    stages = {st.id: st for st in (await session.execute(select(PipelineStage).where(PipelineStage.id.in_(stage_ids)))).scalars().all()} if stage_ids else {}
    plats = {p.id: p for p in (await session.execute(select(Platform).where(Platform.id.in_(plat_ids)))).scalars().all()}
    regions = {r.id: r for r in (await session.execute(select(Region).where(Region.id.in_(region_ids)))).scalars().all()} if region_ids else {}
    users = {u.id: u for u in (await session.execute(select(User).where(User.id.in_(user_ids)))).scalars().all()} if user_ids else {}

    spark: dict[int, list[int]] = {sid: [] for sid in sub_ids}
    rows_act = (await session.execute(
        select(ActivitySnapshot.subscription_id, ActivitySnapshot.value)
        .where(ActivitySnapshot.subscription_id.in_(sub_ids), ActivitySnapshot.metric == "actions")
        .order_by(ActivitySnapshot.period_start)
    )).all()
    for sid, val in rows_act:
        spark[sid].append(val)

    out: list[RegistryRow] = []
    for s in subs:
        # Резолвим клиента: Company (источник истины) или Counterparty (fallback)
        company = companies.get(s.company_id) if s.company_id else None
        cp_fb = cps_fallback.get(s.counterparty_id) if not company else None
        client_name = (
            (company.name or company.legal_name) if company
            else (cp_fb.name if cp_fb else "—")
        )
        # country_code: Company.country (ISO alpha-2) || Company.country_code || Counterparty.country_code
        country_code = (
            (company.country or company.country_code) if company
            else (cp_fb.country_code if cp_fb else None)
        )
        category_code = (
            company.category_code if company
            else (cp_fb.category_code if cp_fb else None)
        )

        st = stages.get(s.lifecycle_stage_id) if s.lifecycle_stage_id else None
        pl = plats.get(s.platform_id)
        rg = regions.get(s.region_id) if s.region_id else None
        sup = users.get(s.sup_pm_user_id) if s.sup_pm_user_id else None
        am = users.get(s.am_user_id) if s.am_user_id else None
        tn = s.team_names or {}
        out.append(RegistryRow(
            id=s.id,
            counterparty_id=s.counterparty_id,
            company_id=s.company_id,
            counterparty_name=client_name,
            country_code=country_code,
            category_code=category_code,
            platform_id=s.platform_id, platform_name=pl.name if pl else "—",
            region_id=s.region_id, region_name=rg.name if rg else None,
            lifecycle_stage_id=s.lifecycle_stage_id,
            status_code=st.code if st else None,
            status_name=st.name if st else None,
            status_color=st.color if st else None,
            impl_pct=float(s.impl_pct) if s.impl_pct is not None else None,
            health_tier=s.health_tier,
            activity_avg=float(s.activity_avg) if s.activity_avg is not None else None,
            activity_trend_pct=float(s.activity_trend_pct) if s.activity_trend_pct is not None else None,
            dormant_periods=s.dormant_periods,
            attention=list(s.health_reasons or []),
            sparkline=spark.get(s.id, [])[-8:],
            seats=s.seats,
            fee_actual=float(s.fee_actual) if s.fee_actual is not None else None,
            fee_currency=s.fee_currency, tariff=s.tariff,
            sup_pm_name=(sup.full_name if sup else tn.get("sup_pm")),
            am_name=(am.full_name if am else tn.get("am")),
            notes=s.notes, updated_at=s.updated_at,
        ))
    return out


# ---------- Реестр ----------

@router.get("/registry", response_model=list[RegistryRow])
async def registry(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    platform_id: int | None = None,
    region_id: int | None = None,
    category_code: str | None = None,
    tier: str | None = None,
    status_code: str | None = None,
    owner_user_id: int | None = None,
    attention: bool = False,
    q: str | None = None,
    include_inactive: bool = False,
    limit: int = Query(default=200, ge=1, le=1000, description="Размер страницы (макс. 1000)."),
    offset: int = Query(default=0, ge=0, description="Смещение для пагинации."),
):
    stmt = select(ClientSubscription)
    if not include_inactive:
        stmt = stmt.where(ClientSubscription.is_active.is_(True))
    if platform_id:
        stmt = stmt.where(ClientSubscription.platform_id == platform_id)
    if region_id:
        stmt = stmt.where(ClientSubscription.region_id == region_id)
    if tier:
        stmt = stmt.where(ClientSubscription.health_tier == tier)
    if owner_user_id:
        stmt = stmt.where(
            (ClientSubscription.sup_pm_user_id == owner_user_id)
            | (ClientSubscription.am_user_id == owner_user_id)
            | (ClientSubscription.imp_pm_user_id == owner_user_id)
        )
    if category_code or q:
        # CONTACTS 2.0 Ф3-B: ищем сначала по Company (источник истины),
        # с fallback на Counterparty для подписок без company_id.
        # Результат объединяем через OR: sub.company_id IN <company_ids> OR sub.counterparty_id IN <cp_ids>
        from sqlalchemy import or_

        # Company-поиск
        co_stmt = select(Company.id)
        if category_code:
            co_stmt = co_stmt.where(Company.category_code == category_code)
        if q:
            co_stmt = co_stmt.where(
                (Company.name.ilike(f"%{q}%")) | (Company.legal_name.ilike(f"%{q}%"))
            )
        co_ids = [r for (r,) in (await session.execute(co_stmt)).all()]

        # Counterparty-поиск (fallback для подписок без company_id)
        cp_stmt = select(Counterparty.id)
        if category_code:
            cp_stmt = cp_stmt.where(Counterparty.category_code == category_code)
        if q:
            cp_stmt = cp_stmt.where(Counterparty.name.ilike(f"%{q}%"))
        cp_ids = [r for (r,) in (await session.execute(cp_stmt)).all()]

        if not co_ids and not cp_ids:
            stmt = stmt.where(ClientSubscription.id == -1)  # пусто
        else:
            conditions = []
            if co_ids:
                conditions.append(ClientSubscription.company_id.in_(co_ids))
            if cp_ids:
                # только для подписок без company_id (fallback)
                conditions.append(
                    (ClientSubscription.company_id.is_(None))
                    & (ClientSubscription.counterparty_id.in_(cp_ids))
                )
            stmt = stmt.where(or_(*conditions))
    if status_code:
        stage_ids = [r for (r,) in (await session.execute(
            select(PipelineStage.id).where(PipelineStage.code == status_code)
        )).all()]
        stmt = stmt.where(ClientSubscription.lifecycle_stage_id.in_(stage_ids or [-1]))
    # Эпик 14: scope-фильтр по матрице visibility_settings (default 'all').
    from app.services.access_control import scope_query
    stmt = await scope_query(session, stmt, ClientSubscription, "subscription", current_user)
    # Perf: attention-фильтр и пагинацию проталкиваем в SQL ДО загрузки строк —
    # раньше грузились ВСЕ активные подписки в память, затем фильтр/срез в Python.
    if attention:
        stmt = stmt.where(_attention_sql_predicate())
    stmt = stmt.order_by(ClientSubscription.updated_at.desc()).limit(limit).offset(offset)
    subs = list((await session.execute(stmt)).scalars().all())
    if attention:
        # Финальный guard на пустой массив (на случай вариаций JSON-сериализации,
        # которые текстовый каст не отсёк) — источник истины тот же, что и раньше.
        subs = [s for s in subs if s.health_reasons]
    return await _registry_rows(session, subs)


@router.get("/registry/dashboard", response_model=RegistryKpiOut)
async def dashboard(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    platform_id: int | None = None,
    region_id: int | None = None,
):
    stmt = select(ClientSubscription.lifecycle_stage_id).where(ClientSubscription.is_active.is_(True))
    if platform_id:
        stmt = stmt.where(ClientSubscription.platform_id == platform_id)
    if region_id:
        stmt = stmt.where(ClientSubscription.region_id == region_id)
    # P0 security (Unit 3b): KPI-дашборд считал ГЛОБАЛЬНО, игнорируя scope —
    # scoped-пользователь видел чужие сводные цифры. Теперь — только свой портфель
    # (admin/director — все).
    from app.services.access_control import scope_query
    stmt = await scope_query(session, stmt, ClientSubscription, "subscription", current_user)
    stage_ids_used = [r for (r,) in (await session.execute(stmt)).all()]
    id_to_code: dict[int, str] = {}
    present = {sid for sid in stage_ids_used if sid}
    if present:
        for st in (await session.execute(select(PipelineStage).where(PipelineStage.id.in_(present)))).scalars().all():
            if st.code:
                id_to_code[st.id] = st.code
    codes = [id_to_code.get(sid) for sid in stage_ids_used]
    return RegistryKpiOut(**compute_kpis(codes))


@router.get("/registry/attention", response_model=list[RegistryRow])
async def attention_list(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    platform_id: int | None = None,
):
    stmt = select(ClientSubscription).where(ClientSubscription.is_active.is_(True))
    if platform_id:
        stmt = stmt.where(ClientSubscription.platform_id == platform_id)
    # Perf: attention-предикат (health_reasons непустой) проталкиваем в SQL —
    # раньше грузились ВСЕ активные подписки в память и фильтровались в Python.
    stmt = stmt.where(_attention_sql_predicate())
    # P0 security (Unit 3b): «требуют внимания» утекал риск-клиентов вне scope.
    # Теперь — только свой портфель (admin/director — все).
    from app.services.access_control import scope_query
    stmt = await scope_query(session, stmt, ClientSubscription, "subscription", current_user)
    # Финальный Python-guard на пустой массив — тот же источник истины, что и
    # раньше (SQL-каст лишь сокращает выборку, не меняет результат).
    subs = [s for s in (await session.execute(stmt)).scalars().all() if s.health_reasons]
    return await _registry_rows(session, subs)


@router.post("/registry/import")
async def import_registry_from_file(current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    """Ф4e: импорт реестра из app/data/registry_import.tsv. Идемпотентно (match-or-create). admin/director."""
    return await run_import(session)


# ---------- Подписки (CRUD) ----------

@router.get("/counterparties/{cp_id}/subscriptions", response_model=list[SubscriptionOut])
async def client_subscriptions(cp_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    # P0 security (Unit 3b): visibility родительского контрагента (зеркало Company).
    cp = (await session.execute(
        select(Counterparty).where(Counterparty.id == cp_id)
    )).scalar_one_or_none()
    if not cp:
        raise HTTPException(404, "Контрагент не найден")
    from app.services.access_control import ensure_object_visible
    await ensure_object_visible(session, cp, "counterparty", current_user)
    return (await session.execute(
        select(ClientSubscription).where(ClientSubscription.counterparty_id == cp_id).order_by(ClientSubscription.id)
    )).scalars().all()


@router.get("/companies/{company_id}/subscriptions", response_model=list[SubscriptionOut])
async def company_subscriptions(company_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    """CONTACTS 2.0 Ф3-B: подписки компании по company_id (источник истины)."""
    # P0 security (Unit 3b): visibility родительской компании.
    company = (await session.execute(
        select(Company).where(Company.id == company_id)
    )).scalar_one_or_none()
    if not company:
        raise HTTPException(404, "Компания не найдена")
    from app.services.access_control import ensure_object_visible
    await ensure_object_visible(session, company, "company", current_user)
    return (await session.execute(
        select(ClientSubscription).where(ClientSubscription.company_id == company_id).order_by(ClientSubscription.id)
    )).scalars().all()


@router.post("/subscriptions", response_model=SubscriptionOut, status_code=status.HTTP_201_CREATED)
async def create_subscription(data: SubscriptionIn, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    # CONTACTS 2.0 Ф3-B: резолвим company_id ↔ counterparty_id (зеркало).
    # Принимаем оба поля; если пришёл только один — дополняем второй через зеркало.
    resolved_company_id: int | None = data.company_id
    resolved_cp_id: int | None = data.counterparty_id

    if resolved_company_id and not resolved_cp_id:
        # company_id дан → резолвим counterparty_id через Company.counterparty_id
        company_row = (await session.execute(
            select(Company).where(Company.id == resolved_company_id)
        )).scalar_one_or_none()
        if not company_row:
            raise HTTPException(404, "Компания не найдена")
        resolved_cp_id = company_row.counterparty_id
    elif resolved_cp_id and not resolved_company_id:
        # counterparty_id дан → резолвим company_id через обратную связь
        cp_row = (await session.execute(
            select(Counterparty).where(Counterparty.id == resolved_cp_id)
        )).scalar_one_or_none()
        if not cp_row:
            raise HTTPException(404, "Контрагент не найден")
        # Ищем Company по зеркалу counterparty_id
        company_row2 = (await session.execute(
            select(Company).where(Company.counterparty_id == resolved_cp_id)
        )).scalar_one_or_none()
        if company_row2:
            resolved_company_id = company_row2.id
    elif not resolved_company_id and not resolved_cp_id:
        raise HTTPException(400, "Необходимо указать company_id или counterparty_id")
    else:
        # Оба даны — просто проверяем существование
        if not (await session.execute(select(Counterparty.id).where(Counterparty.id == resolved_cp_id))).first():
            raise HTTPException(404, "Контрагент не найден")

    # CS-hotfix (0080): counterparty_id теперь NULLABLE — новая Company без
    # legacy-зеркала может завести подписку только по company_id. Раньше тут
    # был 400; теперь company-first резолв допустим, counterparty_id опционален.
    if not resolved_cp_id and not resolved_company_id:
        raise HTTPException(400, "Необходимо указать company_id или counterparty_id")

    if not (await session.execute(select(Platform.id).where(Platform.id == data.platform_id))).first():
        raise HTTPException(404, "Платформа не найдена")
    # P0 security (Unit 3b, C2 CRIT-3): lifecycle_stage_id обязан быть из lifecycle-воронки.
    await _validate_lifecycle_stage(session, data.lifecycle_stage_id)
    payload = data.model_dump()
    payload["counterparty_id"] = resolved_cp_id
    payload["company_id"] = resolved_company_id
    for f in _MONEY_FIELDS:
        if payload.get(f) is not None:
            payload[f] = Decimal(str(payload[f]))
    # Эпик 14: если owner_user_id не задан, подменяем из sup_pm_user_id
    # (sup_pm — это «ответственный ПМ» в реестре CS; самый близкий к семантике owner'а).
    if payload.get("owner_user_id") is None:
        payload["owner_user_id"] = payload.get("sup_pm_user_id")
    # Автозаливка department_id из owner.department_id (если не указан).
    from app.services.access_control import autofill_department_from_owner
    payload["department_id"] = await autofill_department_from_owner(
        session, payload.get("owner_user_id"), payload.get("department_id"),
    )
    sub = ClientSubscription(**payload)
    if sub.lifecycle_stage_id is not None:
        sub.stage_changed_at = datetime.now(UTC)
    session.add(sub)
    try:
        await session.commit()
    except IntegrityError:
        await session.rollback()
        raise HTTPException(400, "Подписка на эту платформу/регион у клиента уже есть")
    await session.refresh(sub)
    return sub


@router.patch("/subscriptions/{sub_id}", response_model=SubscriptionOut)
async def update_subscription(sub_id: int, data: SubscriptionPatch, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    sub = await _require_sub(session, sub_id, current_user)
    patch = data.model_dump(exclude_unset=True)
    for f in _MONEY_FIELDS:
        if f in patch and patch[f] is not None:
            patch[f] = Decimal(str(patch[f]))

    # P0 security (Unit 3b, C2 CRIT-3): валидируем lifecycle_stage_id ДО мутации,
    # чтобы в историю/поле не попал stage из чужой воронки.
    if "lifecycle_stage_id" in patch:
        await _validate_lifecycle_stage(session, patch["lifecycle_stage_id"])

    # Эпик 22: если lifecycle_stage_id меняется — пишем запись в историю переходов.
    # Защита от дублей: записываем только если новый stage_id != текущего.
    stage_history_entry: SubscriptionStageHistory | None = None
    new_stage_id = patch.get("lifecycle_stage_id")
    if new_stage_id is not None and new_stage_id != sub.lifecycle_stage_id:
        sub.stage_changed_at = datetime.now(UTC)
        # Резолвим stage_code для обоих этапов (from/to) — нужен human-readable код
        # для когортной аналитики (GROUP BY to_stage_code = 'C0' etc.)
        from_code: str | None = None
        to_code: str | None = None
        stage_ids_to_fetch = {s for s in [sub.lifecycle_stage_id, new_stage_id] if s}
        if stage_ids_to_fetch:
            stages_rows = (await session.execute(
                select(PipelineStage).where(PipelineStage.id.in_(stage_ids_to_fetch))
            )).scalars().all()
            code_by_id = {s.id: s.code for s in stages_rows}
            from_code = code_by_id.get(sub.lifecycle_stage_id) if sub.lifecycle_stage_id else None
            to_code = code_by_id.get(new_stage_id)
        stage_history_entry = SubscriptionStageHistory(
            subscription_id=sub.id,
            from_stage_code=from_code,
            to_stage_code=to_code or str(new_stage_id),
            changed_at=datetime.now(UTC),
            changed_by_user_id=current_user.id,
        )

    # Эпик 14: если меняется owner_user_id и department_id не передан явно — autofill
    if "owner_user_id" in patch and "department_id" not in patch:
        from app.services.access_control import autofill_department_from_owner
        patch["department_id"] = await autofill_department_from_owner(
            session, patch.get("owner_user_id"), None,
        )
    # Эпик 8: snapshot
    before = snapshot_entity(sub, _SUB_AUDIT_FIELDS)
    for k, v in patch.items():
        setattr(sub, k, v)
    if stage_history_entry is not None:
        session.add(stage_history_entry)
    await session.commit()
    await session.refresh(sub)
    after = snapshot_entity(sub, _SUB_AUDIT_FIELDS)
    await log_change(
        session, entity_type="subscription", entity_id=sub.id,
        user_id=current_user.id, action="update", before=before, after=after,
    )
    await session.commit()
    return sub


@router.delete("/subscriptions/{sub_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_subscription(sub_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    sub = await _require_sub(session, sub_id, current_user)
    before = snapshot_entity(sub, _SUB_AUDIT_FIELDS)
    await log_change(
        session, entity_type="subscription", entity_id=sub.id,
        user_id=current_user.id, action="delete", before=before,
    )
    await session.delete(sub)
    await session.commit()


@router.get("/subscriptions/{sub_id}", response_model=SubscriptionOut)
async def get_subscription(sub_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    return await _require_sub(session, sub_id, current_user)


@router.post("/subscriptions/{sub_id}/recompute-health", response_model=SubscriptionOut)
async def recompute_health(sub_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    sub = await _require_sub(session, sub_id, current_user)
    await recompute_subscription_health(session, sub)
    await session.commit()
    await session.refresh(sub)
    return sub


# ---------- Чек-лист внедрения ----------

async def _checklist_view(session: AsyncSession, sub: ClientSubscription) -> ChecklistView:
    tpl = (await session.execute(
        select(ChecklistTemplate).where(ChecklistTemplate.platform_id == sub.platform_id)
    )).scalars().first()
    items: list[ChecklistItemView] = []
    overall = 0.0
    if tpl:
        titems = (await session.execute(
            select(ChecklistTemplateItem).where(ChecklistTemplateItem.template_id == tpl.id).order_by(ChecklistTemplateItem.sort_order)
        )).scalars().all()
        statuses = {
            s.template_item_id: s
            for s in (await session.execute(
                select(ImplementationItemStatus).where(ImplementationItemStatus.subscription_id == sub.id)
            )).scalars().all()
        }
        applicable = 0
        done = 0.0
        for it in titems:
            st = statuses.get(it.id)
            s_status = st.status if st else "not_started"
            num_done = st.num_done if st else None
            num_total = st.num_total if st else None
            pct = float(st.pct) if (st and st.pct is not None) else None
            ok, completion = item_completion(s_status, num_done, num_total, pct)
            if ok:
                applicable += 1
                done += completion
            items.append(ChecklistItemView(
                template_item_id=it.id, code=it.code, label=it.label, group=it.group,
                kind=it.kind, optional=it.optional, sort_order=it.sort_order,
                status=s_status, num_done=num_done, num_total=num_total, pct=pct,
                value_date=st.value_date if st else None, note=st.note if st else None,
                completion=round(completion, 3),
            ))
        overall = round(done / applicable * 100, 1) if applicable else 0.0
    return ChecklistView(subscription_id=sub.id, overall_pct=overall, items=items)


@router.get("/subscriptions/{sub_id}/checklist", response_model=ChecklistView)
async def get_checklist(sub_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    sub = await _require_sub(session, sub_id, current_user)
    return await _checklist_view(session, sub)


@router.put("/subscriptions/{sub_id}/checklist", response_model=ChecklistView)
async def put_checklist(sub_id: int, data: ChecklistPut, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    sub = await _require_sub(session, sub_id, current_user)
    valid_item_ids = {
        i for (i,) in (await session.execute(
            select(ChecklistTemplateItem.id)
            .join(ChecklistTemplate, ChecklistTemplate.id == ChecklistTemplateItem.template_id)
            .where(ChecklistTemplate.platform_id == sub.platform_id)
        )).all()
    }
    existing = {
        s.template_item_id: s
        for s in (await session.execute(
            select(ImplementationItemStatus).where(ImplementationItemStatus.subscription_id == sub.id)
        )).scalars().all()
    }
    for item in data.items:
        if item.template_item_id not in valid_item_ids:
            continue  # пункт не из шаблона этой платформы — пропускаем
        pct = Decimal(str(item.pct)) if item.pct is not None else None
        row = existing.get(item.template_item_id)
        if row is None:
            row = ImplementationItemStatus(subscription_id=sub.id, template_item_id=item.template_item_id)
            session.add(row)
        row.status = item.status
        row.num_done = item.num_done
        row.num_total = item.num_total
        row.pct = pct
        row.value_date = item.value_date
        row.note = item.note
    await session.flush()
    await recompute_checklist_pct(session, sub.id)
    await session.commit()
    await session.refresh(sub)
    return await _checklist_view(session, sub)


# ---------- Модули на подписке ----------

@router.get("/subscriptions/{sub_id}/modules", response_model=list[SubscriptionModuleOut])
async def get_modules(sub_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    sub = await _require_sub(session, sub_id, current_user)
    mods = {m.id: m for m in (await session.execute(select(Module).where((Module.platform_id == sub.platform_id) | (Module.platform_id.is_(None))))).scalars().all()}
    chosen = {
        sm.module_id: sm
        for sm in (await session.execute(
            select(SubscriptionModule).where(SubscriptionModule.subscription_id == sub.id)
        )).scalars().all()
    }
    out: list[SubscriptionModuleOut] = []
    for mid, m in mods.items():
        sm = chosen.get(mid)
        out.append(SubscriptionModuleOut(
            module_id=mid, code=m.code, name=m.name,
            enabled=bool(sm and sm.enabled), status=sm.status if sm else None,
        ))
    return out


@router.put("/subscriptions/{sub_id}/modules", response_model=list[SubscriptionModuleOut])
async def put_modules(sub_id: int, data: SubscriptionModulesPut, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    sub = await _require_sub(session, sub_id, current_user)
    existing = {
        sm.module_id: sm
        for sm in (await session.execute(
            select(SubscriptionModule).where(SubscriptionModule.subscription_id == sub.id)
        )).scalars().all()
    }
    for m in data.modules:
        row = existing.get(m.module_id)
        if row is None:
            session.add(SubscriptionModule(subscription_id=sub.id, module_id=m.module_id, enabled=m.enabled, status=m.status))
        else:
            row.enabled = m.enabled
            row.status = m.status
    await session.commit()
    return await get_modules(sub_id, current_user, session)


# ---------- Активность (ручной ввод/просмотр) ----------

@router.get("/subscriptions/{sub_id}/activity", response_model=list[ActivitySnapshotOut])
async def get_activity(sub_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    await _require_sub(session, sub_id, current_user)
    return (await session.execute(
        select(ActivitySnapshot).where(ActivitySnapshot.subscription_id == sub_id).order_by(ActivitySnapshot.period_start)
    )).scalars().all()


@router.put("/subscriptions/{sub_id}/activity", response_model=ActivitySnapshotOut)
async def put_activity(sub_id: int, data: ActivityPut, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    sub = await _require_sub(session, sub_id, current_user)
    snap = (await session.execute(
        select(ActivitySnapshot).where(
            ActivitySnapshot.subscription_id == sub_id,
            ActivitySnapshot.period_start == data.period_start,
            ActivitySnapshot.metric == data.metric,
        )
    )).scalar_one_or_none()
    if snap is None:
        snap = ActivitySnapshot(subscription_id=sub_id, period_start=data.period_start, metric=data.metric)
        session.add(snap)
    snap.value = data.value
    snap.period_end = data.period_end
    snap.source = "manual"
    snap.ingested_at = datetime.now(UTC)
    await session.flush()
    await recompute_subscription_health(session, sub)
    await session.commit()
    await session.refresh(snap)
    return snap
