"""Сделки: список/доска/создание/перевод этапа с ACL по видимости этапов.

DEALS 2.0 Ф1a:
- Board: расширенные фильтры + next_task (без N+1, батчем).
- List: плоская таблица сделок с фильтрами.
- Bulk: change_owner / change_stage / set_tags / delete.
- Move: win-gate enforcement + substatus + lost_reason обязательна.
- Meeting-report: сохранение отчёта о встрече в Activity.meeting_report_json.
- mark-paid: ручная пометка оплаты (авто-move await_payment → paid).
"""
from __future__ import annotations

import logging
from datetime import UTC, date, datetime
from decimal import Decimal
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import and_, func as sqlfunc, or_
from sqlalchemy.exc import DBAPIError
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, CurrentUserFlexible, DirectorOrAdmin, require_scope
from app.models import (
    Activity,
    ClientTask,
    Company,
    Contact,
    ContactCompanyLink,
    Contract,
    ContractAttachment,
    ContractPayment,
    ContractStatus,
    Counterparty,
    Deal,
    DealContact,
    DealProduct,
    DealStageHistory,
    LostReason,
    MeetingReportQuestion,
    Pipeline,
    PipelineStage,
    Product,
    ProductPlan,
    ProductPrice,
    UserRole,
)

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
    """Эпик 4.1: пройти автоматизации on_create. Не блокирует ответ — catch на всё."""
    try:
        from app.services.automation_executor import run_on_create_automations
        await run_on_create_automations(session, target_type, target_id)
        await session.commit()
    except Exception as e:  # noqa: BLE001
        _logger.warning(
            "automation on_create failed (%s=%s): %s",
            target_type, target_id, e,
        )


async def _dispatch_deal_stage_events(
    session: AsyncSession, deal: Deal, from_stage_id: int, to_stage: PipelineStage,
) -> None:
    """Эпик 11.2: outbound webhooks при смене этапа сделки.

    Шлёт deal.stage_changed всегда + deal.won / deal.lost если этап is_won/is_lost.
    Не блокирует ответ — safe_dispatch_event catch'ит ошибки.

    Эпик 21: при is_won дополнительно создаём in-app notification у owner'а
    (поздравление). НЕ заменяет TG-нотификации/webhooks — параллельно.
    """
    from app.services.webhook_dispatcher import deal_to_payload, safe_dispatch_event
    payload = deal_to_payload(deal)
    payload["from_stage_id"] = from_stage_id
    payload["to_stage_id"] = to_stage.id
    await safe_dispatch_event(
        session, "deal.stage_changed", "deal", deal.id, payload,
    )
    if to_stage.is_won:
        await safe_dispatch_event(
            session, "deal.won", "deal", deal.id, deal_to_payload(deal),
        )
        # Эпик 21: in-app notification owner'у.
        if deal.owner_user_id:
            try:
                from app.services.notifications import (
                    build_deal_won_notification,
                    safe_create_notification,
                )
                notif_data = build_deal_won_notification(
                    deal_id=deal.id,
                    deal_title=deal.title or f"#{deal.id}",
                    owner_user_id=deal.owner_user_id,
                    amount=float(deal.amount) if deal.amount is not None else None,
                    currency=deal.currency,
                )
                await safe_create_notification(session, **notif_data)
                await session.commit()
            except Exception as e:  # noqa: BLE001
                _logger.warning(
                    "deal_won notification dispatch failed for deal %s: %s",
                    deal.id, e,
                )
    elif to_stage.is_lost:
        await safe_dispatch_event(
            session, "deal.lost", "deal", deal.id, deal_to_payload(deal),
        )
from app.schemas import (
    BoardColumn,
    BoardDealOut,
    BoardOut,
    ClientTaskIn,
    ClientTaskOut,
    ClientTaskUpdate,
    DealBulkIn,
    DealBulkResult,
    DealContactIn,
    DealContactOut,
    DealIn,
    DealListRow,
    DealMoveIn,
    DealOut,
    DealPatch,
    DealProductIn,
    DealProductOut,
    DealProductPatch,
    DealStageHistoryOut,
    FunnelAnalytics,
    HotDealOut,
    MeetingReportIn,
    MeetingReportOut,
    NextTaskOut,
    StageAnalytics,
)
from app.services.deals_wave4 import (
    deal_total,
    line_amount,
    missing_required_fields,
    required_fields_for_stage,
)
from app.services.analytics_deals import TASK_LIKE_ACTIVITY_KINDS
from app.services.audit import log_change, snapshot_entity
from app.services.deals import (
    avg_days_in_stage,
    compute_heat_reason,
    is_redundant_stage_move,
    ordered_lock_ids,
    stage_visible_to,
    visible_stage_ids,
)
from app.services.access_control import ensure_object_visible
from app.services.owner_resolver import resolve_owner_param

# Эпик 8: какие поля Deal логируем в audit
# DEALS 2.0 Ф1a: добавлены tags, product, lost_reason_id.
_DEAL_AUDIT_FIELDS = [
    "title", "amount", "currency", "owner_user_id",
    "counterparty_id", "company_id", "stage_id", "pipeline_id", "contract_id",
    "lost_reason", "expected_close_date", "tags", "product", "lost_reason_id",
    "expected_sign_date", "expected_payment_date",
]

router = APIRouter(prefix="/deals", tags=["deals"])


async def _resolve_company_counterparty(
    session: AsyncSession,
    company_id: int | None,
    counterparty_id: int | None,
) -> tuple[int | None, int | None]:
    """CONTACTS 2.0 Ф3-C: резолв пары company_id / counterparty_id.

    Правила:
    - Если пришёл только company_id → counterparty_id = company.counterparty_id.
    - Если пришёл только counterparty_id → ищем Company по counterparty_id, берём её id.
    - Если пришли оба → оставляем как есть (доверяем caller'у).
    - Если ни один → (None, None).
    """
    if company_id is not None and counterparty_id is not None:
        return company_id, counterparty_id

    if company_id is not None:
        company = (await session.execute(
            select(Company).where(Company.id == company_id)
        )).scalar_one_or_none()
        if company is None:
            raise HTTPException(404, f"Компания {company_id} не найдена")
        return company_id, company.counterparty_id

    if counterparty_id is not None:
        company_row = (await session.execute(
            select(Company).where(Company.counterparty_id == counterparty_id)
        )).scalar_one_or_none()
        resolved_company_id = company_row.id if company_row else None
        return resolved_company_id, counterparty_id

    return None, None


async def _stage(session: AsyncSession, stage_id: int) -> PipelineStage | None:
    return (await session.execute(select(PipelineStage).where(PipelineStage.id == stage_id))).scalar_one_or_none()


async def _batch_next_tasks(
    session: AsyncSession, deal_ids: list[int]
) -> dict[int, NextTaskOut]:
    """Батчевая загрузка ближайшей незакрытой Activity для каждой сделки.

    Алгоритм: одним SQL достаём все незакрытые Activity по deal_ids
    (target_type='deal', completed_at IS NULL, is_closed=False),
    сортируем по (due_at ASC NULLS LAST, id ASC), берём первую на каждый deal.
    Без N+1 запросов.
    """
    if not deal_ids:
        return {}
    now = datetime.now(UTC)
    rows = (await session.execute(
        select(Activity)
        .where(
            Activity.target_type == "deal",
            Activity.target_id.in_(deal_ids),
            Activity.completed_at.is_(None),
            Activity.is_closed.is_(False),
        )
        .order_by(Activity.target_id, Activity.due_at.asc().nulls_last(), Activity.id.asc())
    )).scalars().all()

    result: dict[int, NextTaskOut] = {}
    for act in rows:
        tid = act.target_id
        if tid in result:
            continue  # уже взяли первую (sorted by due_at)
        is_overdue = bool(act.due_at and act.due_at < now)
        result[tid] = NextTaskOut(
            activity_id=act.id,
            title=act.title,
            kind=act.kind,
            due_at=act.due_at,
            is_overdue=is_overdue,
        )
    return result


def _apply_no_tasks_filter(stmt):
    """Wave 2a: ограничить выборку сделок «без задач».

    «Без задач» = ОТКРЫТАЯ сделка (этап не won и не lost) без единой ОТКРЫТОЙ
    task-like Activity (kind ∈ {task,call,meeting}, completed_at IS NULL,
    is_closed=False). Определение «открытой задачи» зеркалит pure-предикат
    analytics_deals.is_open_task_activity (kinds + completed_at + is_closed).

    Реализация:
      - JOIN на PipelineStage и фильтр is_won/is_lost = False (открытая сделка);
      - NOT EXISTS подзапрос по activities (нет открытой task-like активности).
    """
    open_stage_subq = (
        select(PipelineStage.id)
        .where(
            PipelineStage.is_won.is_(False),
            PipelineStage.is_lost.is_(False),
        )
    )
    open_task_exists = (
        select(Activity.id)
        .where(
            Activity.target_type == "deal",
            Activity.target_id == Deal.id,
            Activity.kind.in_(tuple(TASK_LIKE_ACTIVITY_KINDS)),
            Activity.completed_at.is_(None),
            Activity.is_closed.is_(False),
        )
    )
    return stmt.where(
        Deal.stage_id.in_(open_stage_subq),
        ~open_task_exists.exists(),
    )


async def _build_board_deal(deal: Deal, next_task: NextTaskOut | None) -> BoardDealOut:
    """Собрать BoardDealOut из ORM Deal + next_task."""
    return BoardDealOut(
        id=deal.id,
        pipeline_id=deal.pipeline_id,
        stage_id=deal.stage_id,
        counterparty_id=deal.counterparty_id,
        company_id=deal.company_id,
        title=deal.title,
        amount=float(deal.amount) if deal.amount is not None else None,
        currency=deal.currency,
        owner_user_id=deal.owner_user_id,
        contract_id=deal.contract_id,
        stage_changed_at=deal.stage_changed_at,
        closed_at=deal.closed_at,
        lost_reason=deal.lost_reason,
        expected_close_date=deal.expected_close_date,
        extra_fields=deal.extra_fields or {},
        created_at=deal.created_at,
        tags=list(deal.tags or []),
        product=deal.product,
        lost_reason_id=deal.lost_reason_id,
        next_task=next_task,
    )


@router.get("/board", response_model=BoardOut)
async def board(
    pipeline_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    # DEALS 2.0 Ф1a: тумблеры видимости скрытых этапов
    show_lost: bool = Query(default=False, description="Показать этапы hidden_by_default=True (lost/cold)"),
    show_cold: bool = Query(default=False, description="Показать этап Холодные (код cold)"),
    # Фильтры сделок
    owner_id: int | None = Query(default=None),
    city: str | None = Query(default=None),
    country: str | None = Query(default=None),
    source: str | None = Query(default=None, description="Источник (Company.source)"),
    product: str | None = Query(default=None, description="Фильтр по Deal.product (contains)"),
    tag: str | None = Query(default=None, description="Фильтр по тегу (ANY(deals.tags))"),
    amount_from: float | None = Query(default=None),
    amount_to: float | None = Query(default=None),
    task_kind: str | None = Query(default=None, description="Только сделки с next_task.kind=task_kind"),
    no_tasks: bool = Query(default=False, description="Только сделки БЕЗ незакрытых задач"),
    stage_ids: str | None = Query(default=None, description="Comma-separated stage_id белого списка"),
    q: str | None = Query(default=None, description="Поиск по названию сделки (contains, case-insensitive)"),
):
    """Kanban-доска сделок.

    По умолчанию hidden_by_default этапы (Проиграна/Холодные) скрыты — включаются
    тумблерами show_lost / show_cold. Подстатусы (parent_stage_id IS NOT NULL) всегда
    возвращаются — фронт вкладывает их под родительский столбец.
    """
    pipe = (await session.execute(select(Pipeline).where(Pipeline.id == pipeline_id))).scalar_one_or_none()
    if not pipe:
        raise HTTPException(404, "Воронка не найдена")

    stages = (await session.execute(
        select(PipelineStage)
        .where(PipelineStage.pipeline_id == pipeline_id, PipelineStage.is_active.is_(True))
        .order_by(PipelineStage.sort_order)
    )).scalars().all()

    # Парсим stage_ids whitelist
    stage_ids_filter: set[int] | None = None
    if stage_ids:
        try:
            stage_ids_filter = {int(x.strip()) for x in stage_ids.split(",") if x.strip()}
        except ValueError:
            raise HTTPException(400, "stage_ids — список целых чисел через запятую")

    # Загрузка сделок всего pipeline одним запросом + фильтры
    deal_stmt = select(Deal).where(Deal.pipeline_id == pipeline_id)
    if owner_id is not None:
        deal_stmt = deal_stmt.where(Deal.owner_user_id == owner_id)
    if product is not None:
        deal_stmt = deal_stmt.where(Deal.product.ilike(f"%{product}%"))
    if tag is not None:
        deal_stmt = deal_stmt.where(Deal.tags.any(tag))  # type: ignore[arg-type]
    if amount_from is not None:
        deal_stmt = deal_stmt.where(Deal.amount >= amount_from)
    if amount_to is not None:
        deal_stmt = deal_stmt.where(Deal.amount <= amount_to)
    if q is not None:
        deal_stmt = deal_stmt.where(Deal.title.ilike(f"%{q}%"))

    # city / country / source — фильтр через Company JOIN
    need_company_join = bool(city or country or source)
    if need_company_join:
        deal_stmt = deal_stmt.join(Company, Deal.company_id == Company.id, isouter=True)
        if city:
            deal_stmt = deal_stmt.where(Company.city.ilike(f"%{city}%"))
        if country:
            deal_stmt = deal_stmt.where(Company.country == country.upper())
        if source:
            deal_stmt = deal_stmt.where(Company.source == source)

    deal_stmt = deal_stmt.order_by(Deal.updated_at.desc())

    # Эпик 14 (фикс): scope-фильтр видимости по матрице visibility_settings.
    # Раньше board скоупил только на уровне этапов (stage_visible_to) — личные/
    # отдельские скоупы утекали (чужие сделки на видимых этапах). Теперь как в list.
    from app.services.access_control import scope_query
    deal_stmt = await scope_query(session, deal_stmt, Deal, "deal", current_user)

    all_deals = (await session.execute(deal_stmt)).scalars().all()

    # Батч next_task
    all_deal_ids = [d.id for d in all_deals]
    next_tasks = await _batch_next_tasks(session, all_deal_ids)

    # Фильтр task_kind / no_tasks
    if task_kind or no_tasks:
        filtered_deals: list[Deal] = []
        for d in all_deals:
            nt = next_tasks.get(d.id)
            if no_tasks and nt is not None:
                continue
            if task_kind and (nt is None or nt.kind != task_kind):
                continue
            filtered_deals.append(d)
        all_deals = filtered_deals

    # Группировка по stage_id
    by_stage: dict[int, list[Deal]] = {}
    for d in all_deals:
        by_stage.setdefault(d.stage_id, []).append(d)

    columns: list[BoardColumn] = []
    for s in stages:
        if not stage_visible_to(s, current_user):
            continue
        # hidden_by_default — скрываем если тумблер не включён
        if s.hidden_by_default:
            if s.is_lost and not show_lost:
                continue
            if not s.is_lost and not show_cold:
                continue
        if stage_ids_filter and s.id not in stage_ids_filter:
            continue

        stage_deals = by_stage.get(s.id, [])
        board_deals = [
            await _build_board_deal(d, next_tasks.get(d.id))
            for d in stage_deals
        ]
        columns.append(BoardColumn(stage=s, deals=board_deals))

    return BoardOut(pipeline=pipe, columns=columns)


@router.get("/list", response_model=list[DealListRow])
async def list_deals_flat(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    pipeline_id: int | None = Query(default=None),
    stage_id: int | None = Query(default=None),
    owner_id: int | None = Query(default=None),
    company_id: int | None = Query(default=None),
    city: str | None = Query(default=None),
    country: str | None = Query(default=None),
    product: str | None = Query(default=None),
    tag: str | None = Query(default=None),
    amount_from: float | None = Query(default=None),
    amount_to: float | None = Query(default=None),
    q: str | None = Query(default=None),
    no_tasks: bool = Query(
        default=False,
        description=(
            "Wave 2a: вернуть только ОТКРЫТЫЕ сделки (этап не won/lost) без "
            "открытой task-like Activity (task/call/meeting, не завершена и не "
            "закрыта). Для виджета дашборда «Сделки без задач»."
        ),
    ),
    sort: str | None = Query(default=None, description="updated_at_desc | close_date_asc | close_date_desc | amount_desc"),
    limit: int = Query(default=100, ge=1, le=500),
    offset: int = Query(default=0, ge=0),
):
    """Плоская таблица сделок с расширенными фильтрами + next_task.

    Используется для list-view /deals (альтернатива kanban). Поддерживает
    те же фильтры что /board, плюс pagination (limit/offset).
    """
    stmt = select(Deal)
    if pipeline_id:
        stmt = stmt.where(Deal.pipeline_id == pipeline_id)
    if no_tasks:
        stmt = _apply_no_tasks_filter(stmt)
    if stage_id:
        stmt = stmt.where(Deal.stage_id == stage_id)
    if owner_id:
        stmt = stmt.where(Deal.owner_user_id == owner_id)
    if company_id:
        stmt = stmt.where(Deal.company_id == company_id)
    if product:
        stmt = stmt.where(Deal.product.ilike(f"%{product}%"))
    if tag:
        stmt = stmt.where(Deal.tags.any(tag))  # type: ignore[arg-type]
    if amount_from is not None:
        stmt = stmt.where(Deal.amount >= amount_from)
    if amount_to is not None:
        stmt = stmt.where(Deal.amount <= amount_to)
    if q:
        stmt = stmt.where(Deal.title.ilike(f"%{q}%"))

    need_company = bool(city or country)
    if need_company:
        stmt = stmt.join(Company, Deal.company_id == Company.id, isouter=True)
        if city:
            stmt = stmt.where(Company.city.ilike(f"%{city}%"))
        if country:
            stmt = stmt.where(Company.country == country.upper())

    if sort == "close_date_asc":
        stmt = stmt.order_by(Deal.expected_close_date.asc().nulls_last(), Deal.id.asc())
    elif sort == "close_date_desc":
        stmt = stmt.order_by(Deal.expected_close_date.desc().nulls_last(), Deal.id.desc())
    elif sort == "amount_desc":
        stmt = stmt.order_by(Deal.amount.desc().nulls_last(), Deal.id.desc())
    else:
        stmt = stmt.order_by(Deal.updated_at.desc())

    # ACL: scope-фильтр видимости (Эпик 14)
    from app.services.access_control import scope_query
    stmt = await scope_query(session, stmt, Deal, "deal", current_user)

    stmt = stmt.offset(offset).limit(limit)
    deals = (await session.execute(stmt)).scalars().all()

    # Фильтр по видимым этапам
    vis = await visible_stage_ids(session, current_user)
    deals = [d for d in deals if d.stage_id in vis]

    # Батч next_task
    deal_ids = [d.id for d in deals]
    next_tasks = await _batch_next_tasks(session, deal_ids)

    # Батч денормализации: company_name/city + stage_name/color (без N+1).
    company_ids = {d.company_id for d in deals if d.company_id is not None}
    companies: dict[int, Company] = {}
    if company_ids:
        for co in (await session.execute(
            select(Company).where(Company.id.in_(company_ids))
        )).scalars().all():
            companies[co.id] = co
    stage_ids_set = {d.stage_id for d in deals}
    stage_map: dict[int, PipelineStage] = {}
    if stage_ids_set:
        for st in (await session.execute(
            select(PipelineStage).where(PipelineStage.id.in_(stage_ids_set))
        )).scalars().all():
            stage_map[st.id] = st

    rows: list[DealListRow] = []
    for d in deals:
        co = companies.get(d.company_id) if d.company_id is not None else None
        st = stage_map.get(d.stage_id)
        rows.append(DealListRow(
            id=d.id,
            pipeline_id=d.pipeline_id,
            stage_id=d.stage_id,
            company_id=d.company_id,
            counterparty_id=d.counterparty_id,
            company_name=(co.name or co.legal_name) if co else None,
            stage_name=st.name if st else None,
            stage_color=st.color if st else None,
            city=co.city if co else None,
            title=d.title,
            amount=float(d.amount) if d.amount is not None else None,
            currency=d.currency,
            owner_user_id=d.owner_user_id,
            stage_changed_at=d.stage_changed_at,
            closed_at=d.closed_at,
            expected_close_date=d.expected_close_date,
            lost_reason=d.lost_reason,
            tags=list(d.tags or []),
            product=d.product,
            created_at=d.created_at,
            next_task=next_tasks.get(d.id),
        ))
    return rows


@router.get("/analytics", response_model=FunnelAnalytics)
async def analytics(
    pipeline_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    stages = (await session.execute(
        select(PipelineStage).where(PipelineStage.pipeline_id == pipeline_id, PipelineStage.is_active.is_(True)).order_by(PipelineStage.sort_order)
    )).scalars().all()
    deals = (await session.execute(select(Deal).where(Deal.pipeline_id == pipeline_id))).scalars().all()
    by_stage: dict[int, list[Deal]] = {}
    for d in deals:
        by_stage.setdefault(d.stage_id, []).append(d)
    now = datetime.now(UTC)
    rows: list[StageAnalytics] = []
    won = lost = in_progress = 0
    for s in stages:
        if not stage_visible_to(s, current_user):
            continue
        ds = by_stage.get(s.id, [])
        rows.append(StageAnalytics(
            stage_id=s.id, name=s.name, color=s.color, count=len(ds),
            amount_sum=float(sum((d.amount or 0) for d in ds)),
            avg_days_in_stage=avg_days_in_stage([d.stage_changed_at for d in ds], now),
        ))
        if s.is_won:
            won += len(ds)
        elif s.is_lost:
            lost += len(ds)
        else:
            in_progress += len(ds)
    conversion = round(won / (won + lost), 3) if (won + lost) else 0.0
    return FunnelAnalytics(stages=rows, won=won, lost=lost, in_progress=in_progress, conversion=conversion)


@router.get("/hot", response_model=list[HotDealOut])
async def hot_deals(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    owner: str | None = Query(default="me", description='"me" или user.id'),
    limit: int = Query(default=5, ge=1, le=20),
):
    """Эпик 10: список «горячих» сделок текущего (или указанного) владельца.

    Критерии «горячести» (компьютится в `compute_heat_reason`):
    - `idle_days > HOT_IDLE_DAYS_THRESHOLD` (>3 дня в этапе без движения), или
    - `days_to_close < HOT_DEADLINE_DAYS` (<7 дней до целевой даты закрытия).

    Приоритет deadline над idle. Закрытые (won/lost) — исключаются.

    Sort: idle_days DESC, затем days_to_close ASC NULLS LAST (горячие deadline'ы
    подплывают наверх среди сделок с одинаковым idle).

    Owner-резолвер: `owner="me"` → current_user.id; `owner="42"` → 42. Если
    значение не распарсилось — silent fallback на current_user.id (защита от того,
    что фронт пришлёт мусор).
    """
    owner_id = resolve_owner_param(owner, user_id=current_user.id) or current_user.id

    # CONTACTS 2.0 Ф4: JOIN deals × stages × Company (источник истины).
    # Fallback через counterparty_id зеркало если company_id IS NULL (сделки до Ф3).
    # Один query, без N+1. Фильтр на is_won/is_lost — на уровне SQL.
    stmt = (
        select(Deal, PipelineStage, Company)
        .join(PipelineStage, Deal.stage_id == PipelineStage.id)
        .outerjoin(Company, Deal.company_id == Company.id)
        .where(
            Deal.owner_user_id == owner_id,
            PipelineStage.is_won.is_(False),
            PipelineStage.is_lost.is_(False),
        )
    )
    rows = (await session.execute(stmt)).all()

    # Применяем ACL видимости этапов (stage_visible_to — учитывает roles +
    # visible_department_ids / visible_user_ids). admin/director видят всё.
    today = date.today()
    now = datetime.now(UTC)

    # Для сделок без company_id (до Ф3) нужен fallback через counterparty.
    # Соберём counterparty_id тех сделок, у которых company LEFT JOIN вернул NULL.
    fallback_cp_ids: list[int] = [
        d.counterparty_id
        for d, _s, co in rows
        if co is None and d.counterparty_id is not None
    ]
    cp_names: dict[int, str] = {}
    if fallback_cp_ids:
        cp_rows = (await session.execute(
            select(Counterparty).where(Counterparty.id.in_(fallback_cp_ids))
        )).scalars().all()
        cp_names = {cp.id: cp.name for cp in cp_rows}

    items: list[tuple[int, int | None, HotDealOut]] = []
    for d, s, co in rows:
        if not stage_visible_to(s, current_user):
            continue
        # idle_days: now - stage_changed_at (если задано), иначе now - created_at
        ref_time = d.stage_changed_at or d.created_at
        if ref_time is None:
            continue
        idle_days = (now - ref_time).days
        days_to_close: int | None = None
        if d.expected_close_date is not None:
            days_to_close = (d.expected_close_date - today).days
        reason = compute_heat_reason(idle_days, days_to_close)
        if reason is None:
            continue  # не горячая
        # Имя клиента: Company.name || Company.legal_name → fallback counterparty_id зеркало.
        if co is not None:
            client_name: str | None = co.name or co.legal_name or None
            resolved_company_id: int | None = co.id
        else:
            client_name = cp_names.get(d.counterparty_id) if d.counterparty_id else None
            resolved_company_id = None
        items.append((
            idle_days,
            days_to_close,
            HotDealOut(
                id=d.id,
                title=d.title,
                amount=float(d.amount) if d.amount is not None else None,
                currency=d.currency,
                stage_name=s.name,
                stage_color=s.color,
                idle_days=idle_days,
                days_to_close=days_to_close,
                heat_reason=reason,
                counterparty_name=client_name,
                company_id=resolved_company_id,
            ),
        ))

    # Сорт: idle_days DESC, days_to_close ASC NULLS LAST.
    # NULLS LAST реализуем через ключ-картеж (None_first_flag, value).
    items.sort(
        key=lambda x: (
            -x[0],  # idle_days desc
            (x[1] is None, x[1] if x[1] is not None else 0),  # days_to_close asc NULLS LAST
        )
    )
    return [out for _, _, out in items[:limit]]


@router.get("", response_model=list[DealOut])
async def list_deals(
    current_user: CurrentUserFlexible,
    _scope: Annotated[None, Depends(require_scope("read:deals"))],
    session: Annotated[AsyncSession, Depends(get_session)],
    pipeline_id: int | None = None,
    counterparty_id: int | None = None,
    # CONTACTS 2.0 Ф3-C: company_id — новый фильтр (источник истины).
    company_id: int | None = None,
    stage_id: int | None = None,
    close_before: date | None = Query(
        default=None,
        description="Фильтр: expected_close_date <= close_before (для forecast).",
    ),
    sort: str | None = Query(
        default=None,
        description=(
            "Опциональный сорт. Допустимо: "
            "'updated_at_desc' (default), 'close_date_asc', 'close_date_desc'. "
            "Для close_date_* NULL — всегда в конце."
        ),
    ),
    limit: int = Query(
        default=100, ge=1, le=500,
        description="Размер страницы (макс. 500). Дефолт 100.",
    ),
    offset: int = Query(default=0, ge=0, description="Смещение для пагинации."),
):
    """Эпик 10: добавлен `close_before` (forecast) + `sort` (по
    expected_close_date asc/desc).

    Эпик 11.1: auth — cookie ИЛИ Bearer; Bearer проверяется на scope 'read:deals'.

    CONTACTS 2.0 Ф3-C: добавлен `company_id` фильтр (источник истины). Старый
    `counterparty_id` остаётся как fallback для сделок, у которых ещё не заполнен
    company_id (созданных до миграции 0070).
    """
    from sqlalchemy import or_ as _or

    stmt = select(Deal)
    if pipeline_id:
        stmt = stmt.where(Deal.pipeline_id == pipeline_id)
    if company_id is not None:
        # Фильтр по company_id. Дополнительно включаем сделки, у которых company_id
        # ещё NULL но counterparty_id совпадает с зеркалом (fallback для сделок до 0070).
        company_row = (await session.execute(
            select(Company).where(Company.id == company_id)
        )).scalar_one_or_none()
        if company_row and company_row.counterparty_id is not None:
            stmt = stmt.where(_or(
                Deal.company_id == company_id,
                Deal.counterparty_id == company_row.counterparty_id,
            ))
        else:
            stmt = stmt.where(Deal.company_id == company_id)
    elif counterparty_id:
        stmt = stmt.where(Deal.counterparty_id == counterparty_id)
    if stage_id:
        stmt = stmt.where(Deal.stage_id == stage_id)
    if close_before is not None:
        stmt = stmt.where(Deal.expected_close_date.is_not(None))
        stmt = stmt.where(Deal.expected_close_date <= close_before)

    if sort == "close_date_asc":
        stmt = stmt.order_by(
            Deal.expected_close_date.asc().nulls_last(), Deal.id.asc()
        )
    elif sort == "close_date_desc":
        stmt = stmt.order_by(
            Deal.expected_close_date.desc().nulls_last(), Deal.id.desc()
        )
    else:
        stmt = stmt.order_by(Deal.updated_at.desc())

    # Эпик 14: scope-фильтр по матрице visibility_settings (default 'all' —
    # бэквард-совместимо). Не заменяет stage_visible_to ACL (он на уровне этапов).
    from app.services.access_control import scope_query
    stmt = await scope_query(session, stmt, Deal, "deal", current_user)

    # Perf: stage-visibility проталкиваем в SQL WHERE (Deal.stage_id IN <visible>)
    # ДО limit/offset — иначе post-filter «if d.stage_id in vis» ужимал бы уже
    # отрезанную страницу (часть строк терялась). admin/director видят все этапы,
    # тогда фильтр не нужен (visible_stage_ids всё равно вернёт все id, но для
    # больших пайплайнов экономим раздувание IN-списка пропуском фильтра).
    vis = await visible_stage_ids(session, current_user)
    if current_user.role not in (UserRole.admin, UserRole.director):
        stmt = stmt.where(Deal.stage_id.in_(vis))

    stmt = stmt.limit(limit).offset(offset)
    deals = (await session.execute(stmt)).scalars().all()
    return list(deals)


@router.post("", response_model=DealOut, status_code=status.HTTP_201_CREATED)
async def create_deal(
    data: DealIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    pipe = (await session.execute(select(Pipeline).where(Pipeline.id == data.pipeline_id))).scalar_one_or_none()
    if not pipe:
        raise HTTPException(404, "Воронка не найдена")
    # этап: заданный (должен быть видим) или первый видимый активный
    stage_id = data.stage_id
    if stage_id:
        s = await _stage(session, stage_id)
        if not s or s.pipeline_id != pipe.id:
            raise HTTPException(400, "Этап не из этой воронки")
        if not stage_visible_to(s, current_user):
            raise HTTPException(403, "Нет доступа к этому этапу")
    else:
        stages = (await session.execute(
            select(PipelineStage).where(PipelineStage.pipeline_id == pipe.id, PipelineStage.is_active.is_(True)).order_by(PipelineStage.sort_order)
        )).scalars().all()
        visible = [s for s in stages if stage_visible_to(s, current_user)]
        if not visible:
            raise HTTPException(403, "Нет доступных этапов в воронке")
        stage_id = visible[0].id
    # Эпик 14: автозаливка department_id из owner.department_id.
    from app.services.access_control import autofill_department_from_owner
    owner_user_id = data.owner_user_id or current_user.id
    dept_id = await autofill_department_from_owner(
        session, owner_user_id, current_department_id=None,
    )
    # CONTACTS 2.0 Ф3-C: резолв пары company_id / counterparty_id (dual-write).
    resolved_company_id, resolved_counterparty_id = await _resolve_company_counterparty(
        session, data.company_id, data.counterparty_id,
    )
    d = Deal(
        pipeline_id=pipe.id, stage_id=stage_id,
        counterparty_id=resolved_counterparty_id,
        company_id=resolved_company_id,
        title=data.title, amount=Decimal(str(data.amount)) if data.amount is not None else None,
        currency=data.currency, owner_user_id=owner_user_id,
        contract_id=data.contract_id, stage_changed_at=datetime.now(UTC),
        # Эпик 4.2: Pipedrive expected_close_date (опционально на create)
        expected_close_date=data.expected_close_date,
        # Wave 4: ожидаемые даты подписания / оплаты
        expected_sign_date=data.expected_sign_date,
        expected_payment_date=data.expected_payment_date,
        # Эпик 14: department_id из owner
        department_id=dept_id,
        # DEALS 2.0 Ф1a
        tags=list(data.tags) if data.tags else [],
        product=data.product,
    )
    session.add(d)
    await session.commit()
    await session.refresh(d)
    # Эпик 8: audit create
    await log_change(
        session, entity_type="deal", entity_id=d.id,
        user_id=current_user.id, action="create",
        after=snapshot_entity(d, _DEAL_AUDIT_FIELDS),
    )
    await session.commit()
    # Эпик 4.1: автоматизации on_create (например, change_owner round_robin
    # для распределения новых сделок).
    await _fire_automations_on_create(session, "deal", d.id)
    # Эпик 11.2: outbound webhook deal.created
    from app.services.webhook_dispatcher import deal_to_payload, safe_dispatch_event
    await safe_dispatch_event(
        session, "deal.created", "deal", d.id, deal_to_payload(d),
    )
    await session.refresh(d)
    return d


@router.patch("/{deal_id}", response_model=DealOut)
async def update_deal(
    deal_id: int,
    data: DealPatch,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    d = await _deal_or_403(session, deal_id, current_user)
    patch = data.model_dump(exclude_unset=True)
    # P0 security (B1 CRITICAL): stage_id больше нет в DealPatch, но на случай
    # старых клиентов/будущих регрессий — жёстко выкидываем его из patch-пути.
    # Смена этапа идёт ТОЛЬКО через POST /deals/{id}/move (win-gate + история).
    patch.pop("stage_id", None)
    if "amount" in patch and patch["amount"] is not None:
        patch["amount"] = Decimal(str(patch["amount"]))
    # CONTACTS 2.0 Ф3-C: если в PATCH есть company_id или counterparty_id —
    # резолвим пару и пишем оба поля (dual-write).
    if "company_id" in patch or "counterparty_id" in patch:
        raw_company = patch.pop("company_id", d.company_id)
        raw_counterparty = patch.pop("counterparty_id", d.counterparty_id)
        new_company_id, new_cp_id = await _resolve_company_counterparty(
            session, raw_company, raw_counterparty,
        )
        patch["company_id"] = new_company_id
        patch["counterparty_id"] = new_cp_id
    # Фикс: при смене owner_user_id пере-autofill department_id из нового owner,
    # иначе visibility-scope рассинхронится (department остаётся от старого owner).
    if "owner_user_id" in patch and patch["owner_user_id"] is not None and patch["owner_user_id"] != d.owner_user_id:
        from app.services.access_control import autofill_department_from_owner
        patch["department_id"] = await autofill_department_from_owner(
            session, int(patch["owner_user_id"]), current_department_id=None,
        )
    # Эпик 8: snapshot до изменений
    before = snapshot_entity(d, _DEAL_AUDIT_FIELDS)
    for k, v in patch.items():
        setattr(d, k, v)
    await session.commit()
    await session.refresh(d)
    after = snapshot_entity(d, _DEAL_AUDIT_FIELDS)
    await log_change(
        session, entity_type="deal", entity_id=d.id,
        user_id=current_user.id, action="update", before=before, after=after,
    )
    await session.commit()
    return d


async def _check_win_gate(session: AsyncSession, deal: Deal) -> dict[str, object]:
    """Проверяет условия win-gate для сделки.

    Возвращает dict с ключами:
    - has_signed_scan: True если у договора сделки есть ContractAttachment(kind='signed_scan')
    - has_payment: True если есть ContractPayment по договору сделки
    - contract_id: id договора, по которому прошёл гейт (для SuccessGateModal). Если
      deal.contract_id задан — он; иначе первый подходящий договор компании сделки.
    - passed: True если хотя бы одно условие выполнено

    BUG-2 fix: договор, созданный из kebab «Создать КП/договор», мог не быть привязан
    к deal.contract_id. Поэтому при пустом deal.contract_id ищем скан/оплату среди ВСЕХ
    договоров компании сделки (Contract.company_id == deal.company_id ИЛИ зеркало по
    Contract.counterparty_id == deal.counterparty_id).
    """
    # Список договоров-кандидатов: явный из сделки либо все договоры компании.
    candidate_ids: list[int] = []
    if deal.contract_id:
        candidate_ids = [deal.contract_id]
    else:
        conds = []
        if deal.company_id is not None:
            conds.append(Contract.company_id == deal.company_id)
        if deal.counterparty_id is not None:
            conds.append(Contract.counterparty_id == deal.counterparty_id)
        if conds:
            rows = (await session.execute(
                select(Contract.id).where(or_(*conds))
            )).scalars().all()
            candidate_ids = list(rows)

    has_signed_scan = False
    has_payment = False
    matched_contract_id: int | None = deal.contract_id

    if candidate_ids:
        # Скан с подписью клиента по любому договору-кандидату.
        scan = (await session.execute(
            select(ContractAttachment.contract_id).where(
                ContractAttachment.contract_id.in_(candidate_ids),
                ContractAttachment.kind == "signed_scan",
            ).limit(1)
        )).scalar_one_or_none()
        has_signed_scan = scan is not None

        # Хотя бы один платёж по любому договору-кандидату.
        payment = (await session.execute(
            select(ContractPayment.contract_id).where(
                ContractPayment.contract_id.in_(candidate_ids),
            ).limit(1)
        )).scalar_one_or_none()
        has_payment = payment is not None

        # contract_id для модалки: тот, у которого нашёлся скан/оплата;
        # иначе — первый кандидат (если deal.contract_id пуст).
        if matched_contract_id is None:
            matched_contract_id = scan or payment or (
                candidate_ids[0] if candidate_ids else None
            )

    return {
        "has_signed_scan": has_signed_scan,
        "has_payment": has_payment,
        "contract_id": matched_contract_id,
        "passed": has_signed_scan or has_payment,
    }


async def _deal_field_values(session: AsyncSession, deal: Deal) -> dict[str, Any]:
    """Снимок значений полей сделки для required-field валидации.

    'contacts' = список привязанных DealContact (пустой = поле «контакты» пусто).
    """
    contacts_count = (await session.execute(
        select(sqlfunc.count(DealContact.id)).where(DealContact.deal_id == deal.id)
    )).scalar_one()
    return {
        "amount": deal.amount,
        "currency": deal.currency,
        "owner_user_id": deal.owner_user_id,
        "expected_close_date": deal.expected_close_date,
        "expected_sign_date": deal.expected_sign_date,
        "expected_payment_date": deal.expected_payment_date,
        "product": deal.product,
        "tags": list(deal.tags or []),
        "contacts": list(range(contacts_count)),  # непустой список = поле заполнено
        **(deal.extra_fields or {}),  # custom-поля
    }


async def _check_required_fields(
    session: AsyncSession, deal: Deal, pipeline: Pipeline, target_stage_id: int,
) -> list[str]:
    """Какие required-поля для target-этапа пусты на сделке. Пусто = ok."""
    config = (pipeline.settings or {})
    required = required_fields_for_stage(config, target_stage_id)
    if not required:
        return []
    values = await _deal_field_values(session, deal)
    return missing_required_fields(required, values)


@router.post("/{deal_id}/move", response_model=DealOut)
async def move_deal(
    deal_id: int,
    data: DealMoveIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Перевод сделки в другой этап.

    DEALS 2.0 Ф1a:
    - won_gate=True: требует signed_scan ИЛИ payment по договору (409 если нет).
    - is_lost=True: обязателен lost_reason (текст) или lost_reason_id из реестра.
    - substage_id: при is_won переходе — опциональный выбор подстатуса
      (await_payment / paid) вместо самого won-этапа.
    """
    # P0 security (B1 CRIT-2): owner-scope на исходную сделку (404 на чужую).
    # P1 concurrency (audit S6 B1): row lock на сделку — две реплики/двойной
    # клик не могут одновременно двигать одну сделку. Lock держится до commit'а.
    d = await _deal_or_403(session, deal_id, current_user, for_update=True)
    target = await _stage(session, data.stage_id)
    if not target or target.pipeline_id != d.pipeline_id:
        raise HTTPException(400, "Этап не из этой воронки")
    if not stage_visible_to(target, current_user):
        raise HTTPException(403, "Нет доступа к целевому этапу")

    # P1 concurrency (audit S6 B1): idempotent no-op. Под row lock'ом перечитываем
    # текущий stage_id; если сделка уже в целевом этапе (другая реплика/повторный
    # клик нас опередил) — не пишем дублирующую DealStageHistory, не перефайриваем
    # автоматизации/вебхуки. Возвращаем сделку как есть. Substage-уточнение
    # (data.substage_id) обрабатываем ниже даже на no-op основного перехода:
    # если запрошен переход прямо в подстатус, target уже == текущий этап,
    # и блок substage ниже сам решит, нужен ли второй переход.
    if is_redundant_stage_move(d.stage_id, target.id, data.substage_id):
        return d

    # Wave 4: required-field валидация ПЕРЕД win-gate (рекомендуемый порядок —
    # сначала проверяем заполненность карточки, потом договор/оплату). 422 со
    # структурным телом, чтобы фронт подсветил незаполненные поля красным.
    pipe = (await session.execute(
        select(Pipeline).where(Pipeline.id == d.pipeline_id)
    )).scalar_one_or_none()
    if pipe is not None:
        missing = await _check_required_fields(session, d, pipe, target.id)
        if missing:
            raise HTTPException(
                status.HTTP_422_UNPROCESSABLE_ENTITY,
                detail={
                    "code": "REQUIRED_FIELDS_MISSING",
                    "message": "Заполните обязательные поля для перехода в этап",
                    "missing_fields": missing,
                },
            )

    # DEALS 2.0 Ф1a: Win-gate enforcement.
    # Гейт включён, если у целевого этапа won_gate=True ЛИБО его родитель
    # (parent_stage) имеет won_gate=True. Это закрывает обход гейта через прямой
    # drag/StagePopover на подстатусы await_payment/paid (is_won=True,
    # won_gate=False), которые лежат под won-этапом с won_gate=True.
    gate_required = target.won_gate
    if not gate_required and target.parent_stage_id:
        parent = await _stage(session, target.parent_stage_id)
        if parent and parent.won_gate:
            gate_required = True
    if gate_required:
        gate = await _check_win_gate(session, d)
        if not gate["passed"]:
            detail = {
                "code": "WIN_GATE_FAILED",
                "message": "Перевод в «Успех» заблокирован: нужен подписанный скан ИЛИ зафиксированная оплата",
                "has_signed_scan": gate["has_signed_scan"],
                "has_payment": gate["has_payment"],
                "contract_id": gate["contract_id"],
            }
            raise HTTPException(status.HTTP_409_CONFLICT, detail=detail)

    # DEALS 2.0 Ф1a: Lost-reason обязательна при переходе в is_lost этап
    if target.is_lost:
        if not data.lost_reason and not data.lost_reason_id:
            raise HTTPException(
                status.HTTP_422_UNPROCESSABLE_ENTITY,
                "При переводе в этап «Проиграна» обязательно укажите причину (lost_reason или lost_reason_id)"
            )
        # Резолвим текст из реестра если передан only id
        if data.lost_reason_id and not data.lost_reason:
            lr = (await session.execute(
                select(LostReason).where(LostReason.id == data.lost_reason_id)
            )).scalar_one_or_none()
            if not lr:
                raise HTTPException(404, f"Причина отказа {data.lost_reason_id} не найдена")
            data = data.model_copy(update={"lost_reason": lr.name})

    from_id = d.stage_id
    # Эпик 8: snapshot
    before = snapshot_entity(d, _DEAL_AUDIT_FIELDS)
    # P1 concurrency (audit S6 B1): основной переход применяем только если этап
    # реально меняется. Сюда можем попасть с from_id == target.id, когда сделка
    # уже в целевом этапе, но запрошено уточнение substage_id — тогда основной
    # move — no-op (без дубля DealStageHistory), а реальный переход сделает блок
    # substage ниже.
    #
    # P1 concurrency follow-up: НЕ коммитим здесь. Раньше первый commit отпускал
    # row-lock сразу после основного перехода, а блок substage ниже делал второй
    # read-modify-write уже БЕЗ блокировки — два одинаковых won+substage запроса
    # могли оба до него дойти и продублировать DealStageHistory. Теперь весь move
    # (основной этап + подстатус) идёт под одним row-lock'ом и одним commit'ом в
    # конце — lock держится до самого финального commit.
    if from_id != target.id:
        d.stage_id = target.id
        d.stage_changed_at = datetime.now(UTC)
        d.closed_at = datetime.now(UTC) if (target.is_won or target.is_lost) else None

        # DEALS 2.0 Ф1a: сохраняем причину отказа в поля Deal
        if target.is_lost:
            if data.lost_reason:
                d.lost_reason = data.lost_reason
            if data.lost_reason_id:
                d.lost_reason_id = data.lost_reason_id

        session.add(DealStageHistory(deal_id=d.id, from_stage_id=from_id, to_stage_id=target.id, user_id=current_user.id))

    # DEALS 2.0 Ф1a: если указан substage_id — сразу делаем второй переход в подстатус.
    # Выполняется в той же транзакции под тем же row-lock'ом (commit отложен ниже).
    if data.substage_id and target.is_won:
        substage = await _stage(session, data.substage_id)
        if substage and substage.parent_stage_id == target.id:
            d.stage_id = substage.id
            d.stage_changed_at = datetime.now(UTC)
            session.add(DealStageHistory(
                deal_id=d.id, from_stage_id=target.id,
                to_stage_id=substage.id, user_id=current_user.id,
            ))
            target = substage  # для dispatch

    after = snapshot_entity(d, _DEAL_AUDIT_FIELDS)
    await log_change(
        session, entity_type="deal", entity_id=d.id,
        user_id=current_user.id, action="update", before=before, after=after,
    )
    # Единственный commit: основной переход + подстатус + audit атомарны, row-lock
    # отпускается только здесь.
    await session.commit()
    await session.refresh(d)
    # Эпик 4: автоматизации on_enter_stage (после commit'а основной транзакции)
    if from_id != target.id:
        await _fire_automations_on_enter_stage(
            session, d.pipeline_id, target.id, "deal", d.id
        )
        # Эпик 11.2: outbound webhooks stage_changed + won/lost
        await _dispatch_deal_stage_events(session, d, from_id, target)
    return d


@router.delete("/{deal_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_deal(
    deal_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    d = await _deal_or_403(session, deal_id, current_user)
    # Эпик 8: audit delete
    before = snapshot_entity(d, _DEAL_AUDIT_FIELDS)
    await log_change(
        session, entity_type="deal", entity_id=d.id,
        user_id=current_user.id, action="delete", before=before,
    )
    await session.delete(d)
    await session.commit()


async def _deal_or_403(
    session: AsyncSession, deal_id: int, user, *, for_update: bool = False,
) -> Deal:
    """Загрузить сделку с проверкой видимости. 404 при отсутствии/нет доступа.

    P0 security (B1 CRIT-2): раньше проверялась ТОЛЬКО видимость ЭТАПА
    (stage_visible_to → True для этапов без явного ACL), owner-scope не
    применялся → менеджер A читал/правил/двигал/удалял сделки менеджера B по
    прямому id. Теперь дополнительно прогоняем owner-scope через
    ensure_object_visible("deal") и палим 404 (не 403) на чужую сделку, чтобы
    не раскрывать её существование. Stage-видимость сохранена (оба условия).

    P1 concurrency (audit S6 B1): при ``for_update=True`` сделка читается
    ``SELECT ... FOR UPDATE`` (row lock). Используется на write-path'ах, где две
    реплики api (scale=2) могут гоняться за одну строку: move_deal (stage-move)
    и пересчёт Deal.amount при правке позиций. Lock держится до commit'а
    транзакции, сериализуя конкурентные операции над одной сделкой. Read-path'ы
    оставлены без блокировки (for_update=False по умолчанию).
    """
    stmt = select(Deal).where(Deal.id == deal_id)
    if for_update:
        stmt = stmt.with_for_update()
    d = (await session.execute(stmt)).scalar_one_or_none()
    if not d:
        raise HTTPException(404, "Сделка не найдена")
    s = await _stage(session, d.stage_id)
    if s and not stage_visible_to(s, user):
        raise HTTPException(404, "Сделка не найдена")
    await ensure_object_visible(session, d, "deal", user)
    return d


@router.post("/bulk", response_model=DealBulkResult)
async def bulk_deals(
    data: DealBulkIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Bulk-операции над несколькими сделками.

    DEALS 2.0 Ф1a. Транзакционно (одна транзакция на все ids).
    Проверка видимости/прав для каждой сделки (stage_visible_to). Audit-лог.

    Действия:
    - change_owner: payload.owner_user_id (int, required)
    - change_stage:  payload.stage_id (int, required). Won-gate НЕ проверяется
                    в bulk — только при ручном move_deal.
    - set_tags:      payload.tags (list[str], required), payload.mode (add|replace|remove)
    - delete:        только admin/director
    """
    action = data.action
    if action not in ("change_owner", "change_stage", "set_tags", "delete"):
        raise HTTPException(400, f"Неизвестное действие: {action}")

    if action == "delete":
        # Только admin / director
        from app.models import UserRole
        if current_user.role not in (UserRole.admin, UserRole.director):
            raise HTTPException(403, "Массовое удаление доступно только admin/director")

    updated = 0
    errors: list[str] = []

    # P1 concurrency follow-up: берём row-lock'и в детерминированном порядке
    # (id ascending, дедуп), чтобы два конкурентных bulk с пересекающимися id в
    # обратном порядке не словили deadlock (40P01). См. ordered_lock_ids.
    for deal_id in ordered_lock_ids(data.ids):
        try:
            # P1 concurrency (audit S6 B1): per-row lock. Bulk идёт в одной
            # транзакции (commit в конце), поэтому каждая сделка блокируется по
            # мере обработки и держится до общего commit'а — конкурентный
            # move_deal/bulk над теми же id сериализуется.
            d = (await session.execute(
                select(Deal).where(Deal.id == deal_id).with_for_update()
            )).scalar_one_or_none()
            if not d:
                errors.append(f"#{deal_id}: сделка не найдена")
                continue
            s = await _stage(session, d.stage_id)
            if s and not stage_visible_to(s, current_user):
                errors.append(f"#{deal_id}: нет доступа")
                continue
            # P0 security (B1 CRIT-2): owner-scope per-item — менеджер не может
            # bulk-править чужие сделки. ensure_object_visible бросает 404 на
            # чужую → ловим и помечаем как «нет доступа», не прерывая весь bulk.
            try:
                await ensure_object_visible(session, d, "deal", current_user)
            except HTTPException:
                errors.append(f"#{deal_id}: нет доступа")
                continue

            before = snapshot_entity(d, _DEAL_AUDIT_FIELDS)

            if action == "change_owner":
                new_owner = data.payload.get("owner_user_id")
                if new_owner is None:
                    raise HTTPException(400, "payload.owner_user_id обязателен")
                new_owner_id = int(new_owner)
                d.owner_user_id = new_owner_id
                # Фикс: при смене владельца пере-autofill department_id из нового
                # owner.department_id, иначе visibility-scope рассинхронится.
                from app.services.access_control import autofill_department_from_owner
                d.department_id = await autofill_department_from_owner(
                    session, new_owner_id, current_department_id=None,
                )

            elif action == "change_stage":
                new_stage_id = data.payload.get("stage_id")
                if new_stage_id is None:
                    raise HTTPException(400, "payload.stage_id обязателен")
                new_stage = await _stage(session, int(new_stage_id))
                if not new_stage or new_stage.pipeline_id != d.pipeline_id:
                    errors.append(f"#{deal_id}: этап {new_stage_id} не из воронки сделки")
                    continue
                # Фикс: перевод в is_lost через bulk требует причину отказа,
                # иначе закрытые сделки уходят без lost_reason (искажает аналитику).
                if new_stage.is_lost:
                    bulk_lost_reason = data.payload.get("lost_reason")
                    bulk_lost_reason_id = data.payload.get("lost_reason_id")
                    if not bulk_lost_reason and not bulk_lost_reason_id:
                        errors.append(
                            f"#{deal_id}: перевод в «Проиграна» требует "
                            "payload.lost_reason или lost_reason_id"
                        )
                        continue
                    if bulk_lost_reason_id and not bulk_lost_reason:
                        lr = (await session.execute(
                            select(LostReason).where(LostReason.id == int(bulk_lost_reason_id))
                        )).scalar_one_or_none()
                        if not lr:
                            errors.append(f"#{deal_id}: причина отказа {bulk_lost_reason_id} не найдена")
                            continue
                        bulk_lost_reason = lr.name
                    d.lost_reason = bulk_lost_reason
                    if bulk_lost_reason_id:
                        d.lost_reason_id = int(bulk_lost_reason_id)
                old_stage_id = d.stage_id
                d.stage_id = new_stage.id
                d.stage_changed_at = datetime.now(UTC)
                d.closed_at = datetime.now(UTC) if (new_stage.is_won or new_stage.is_lost) else None
                session.add(DealStageHistory(
                    deal_id=d.id, from_stage_id=old_stage_id,
                    to_stage_id=new_stage.id, user_id=current_user.id,
                ))

            elif action == "set_tags":
                mode = data.payload.get("mode", "replace")
                new_tags: list[str] = [str(t) for t in (data.payload.get("tags") or [])]
                existing = list(d.tags or [])
                if mode == "add":
                    merged = list(dict.fromkeys(existing + new_tags))  # сохраняем порядок, дедуп
                    d.tags = merged
                elif mode == "remove":
                    remove_set = set(new_tags)
                    d.tags = [t for t in existing if t not in remove_set]
                else:  # replace
                    d.tags = new_tags

            elif action == "delete":
                await log_change(
                    session, entity_type="deal", entity_id=d.id,
                    user_id=current_user.id, action="delete", before=before,
                )
                await session.delete(d)
                await session.commit()
                updated += 1
                continue

            after = snapshot_entity(d, _DEAL_AUDIT_FIELDS)
            await log_change(
                session, entity_type="deal", entity_id=d.id,
                user_id=current_user.id, action="update", before=before, after=after,
            )
            await session.commit()
            updated += 1

        except HTTPException:
            raise
        except DBAPIError as exc:  # noqa: BLE001
            # P1 concurrency follow-up: deadlock (40P01) или иная DBAPI-ошибка на
            # одной строке деградирует в errors, а не валит весь batch 500-ым.
            # ordered_lock_ids делает deadlock маловероятным, но под высокой
            # конкуренцией остаётся остаточный риск — ловим явно.
            await session.rollback()
            errors.append(f"#{deal_id}: конфликт блокировки, повторите ({exc.__class__.__name__})")
        except Exception as exc:  # noqa: BLE001
            errors.append(f"#{deal_id}: {exc}")
            await session.rollback()

    return DealBulkResult(updated=updated, errors=errors)


@router.get("/{deal_id}", response_model=DealOut)
async def get_deal(deal_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    return await _deal_or_403(session, deal_id, current_user)


@router.get("/{deal_id}/tasks", response_model=list[ClientTaskOut])
async def deal_tasks(deal_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    await _deal_or_403(session, deal_id, current_user)
    return (await session.execute(
        select(ClientTask).where(ClientTask.deal_id == deal_id).order_by(ClientTask.status, ClientTask.id.desc())
    )).scalars().all()


@router.post("/{deal_id}/tasks", response_model=ClientTaskOut, status_code=status.HTTP_201_CREATED)
async def create_deal_task(deal_id: int, data: ClientTaskIn, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    d = await _deal_or_403(session, deal_id, current_user)
    # CONTACTS 2.0 Ф3-C: принимаем либо counterparty_id, либо company_id (через зеркало).
    task_counterparty_id = d.counterparty_id
    if not task_counterparty_id and d.company_id:
        company_row = (await session.execute(
            select(Company).where(Company.id == d.company_id)
        )).scalar_one_or_none()
        task_counterparty_id = company_row.counterparty_id if company_row else None
    if not task_counterparty_id:
        raise HTTPException(400, "Привяжите клиента к сделке, чтобы добавлять задачи")
    t = ClientTask(counterparty_id=task_counterparty_id, deal_id=d.id, created_by_user_id=current_user.id, **data.model_dump())
    session.add(t)
    await session.commit()
    await session.refresh(t)
    return t


@router.patch("/{deal_id}/tasks/{task_id}", response_model=ClientTaskOut)
async def update_deal_task(deal_id: int, task_id: int, data: ClientTaskUpdate, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    await _deal_or_403(session, deal_id, current_user)
    t = (await session.execute(select(ClientTask).where(ClientTask.id == task_id, ClientTask.deal_id == deal_id))).scalar_one_or_none()
    if not t:
        raise HTTPException(404, "Задача не найдена")
    patch = data.model_dump(exclude_unset=True)
    if "status" in patch:
        t.status = patch.pop("status")
        t.done_at = datetime.now(UTC) if t.status == "done" else None
    for k, v in patch.items():
        setattr(t, k, v)
    await session.commit()
    await session.refresh(t)
    return t


@router.delete("/{deal_id}/tasks/{task_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_deal_task(deal_id: int, task_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    await _deal_or_403(session, deal_id, current_user)
    t = (await session.execute(select(ClientTask).where(ClientTask.id == task_id, ClientTask.deal_id == deal_id))).scalar_one_or_none()
    if not t:
        raise HTTPException(404, "Задача не найдена")
    await session.delete(t)
    await session.commit()


@router.post("/{deal_id}/mark-paid", response_model=DealOut)
async def mark_deal_paid(
    deal_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """DEALS 2.0 Ф1a: ручная пометка оплаты.

    Если сделка находится в этапе await_payment — авто-перевод в paid (подстатус).
    Если уже в paid — идемпотентно (200, без изменений).
    Если в другом won-этапе — 400.
    """
    d = await _deal_or_403(session, deal_id, current_user)
    current_stage = await _stage(session, d.stage_id)
    if not current_stage:
        raise HTTPException(404, "Этап сделки не найден")

    # Ищем этапы await_payment / paid в той же воронке
    pipeline_stages = (await session.execute(
        select(PipelineStage)
        .where(PipelineStage.pipeline_id == d.pipeline_id, PipelineStage.is_active.is_(True))
    )).scalars().all()
    stages_by_code = {s.code: s for s in pipeline_stages if s.code}

    paid_stage = stages_by_code.get("paid")
    await_payment_stage = stages_by_code.get("await_payment")

    # Уже в paid — идемпотентно
    if current_stage.code == "paid":
        return d

    # Только из await_payment переводим в paid
    if current_stage.code != "await_payment":
        if not current_stage.is_won:
            raise HTTPException(400, "mark-paid применим только к won-сделкам (await_payment → paid)")
        # Если won без подстатуса — переводим в await_payment → paid
        if not await_payment_stage or not paid_stage:
            raise HTTPException(400, "Подстатусы await_payment/paid не найдены в воронке")
        # Переводим в paid напрямую
    elif paid_stage is None:
        raise HTTPException(400, "Этап paid не найден в воронке")

    target_stage = paid_stage
    from_id = d.stage_id
    before = snapshot_entity(d, _DEAL_AUDIT_FIELDS)
    d.stage_id = target_stage.id
    d.stage_changed_at = datetime.now(UTC)
    session.add(DealStageHistory(
        deal_id=d.id, from_stage_id=from_id,
        to_stage_id=target_stage.id, user_id=current_user.id,
    ))
    await session.commit()
    await session.refresh(d)
    after = snapshot_entity(d, _DEAL_AUDIT_FIELDS)
    await log_change(
        session, entity_type="deal", entity_id=d.id,
        user_id=current_user.id, action="update", before=before, after=after,
    )
    await session.commit()
    # Ф3 (финансы): факт оплаты сделки → income fin_operation (идемпотентно по
    # source='deal'). Best-effort: финансовая интеграция НЕ должна ломать mark-paid
    # DEALS 2.0 — при сбое логируем и продолжаем (факт можно провести вручную позже).
    await _integrate_deal_paid_safe(session, d, current_user.id)
    # Автоматизации и webhooks
    await _fire_automations_on_enter_stage(session, d.pipeline_id, target_stage.id, "deal", d.id)
    await _dispatch_deal_stage_events(session, d, from_id, target_stage)
    return d


async def _integrate_deal_paid_safe(session: AsyncSession, deal: Deal, user_id: int) -> None:
    """Best-effort write-through факта оплаты сделки в GL (Ф3). Не бросает наружу."""
    from app.services.finance import pay_integration

    try:
        await pay_integration.integrate_deal_paid(
            session, deal, posted_by_user_id=user_id
        )
        await session.commit()
    except Exception as e:  # noqa: BLE001 — финансовый сбой не должен валить mark-paid
        await session.rollback()
        import sentry_sdk
        sentry_sdk.capture_exception(e)
        logging.getLogger("finance.integration").warning(
            "Ф3: не удалось провести оплату сделки #%s в GL (отложено на ручную проводку)",
            deal.id,
            exc_info=True,
        )


@router.post("/{deal_id}/meeting-report", response_model=MeetingReportOut)
async def save_meeting_report(
    deal_id: int,
    data: MeetingReportIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """DEALS 2.0 Ф1a: сохранение отчёта о встрече.

    Создаёт или обновляет Activity(kind='meeting', target_type='deal') с
    meeting_report_json = {answers: [...]} и добавляет в Timeline.

    Если data.activity_id передан — обновляем существующую активность.
    Иначе — создаём новую meeting-активность.

    Валидация: проверяем что question_id из answers присутствует в реестре
    MeetingReportQuestion (global + pipeline_id=pipeline).
    """
    d = await _deal_or_403(session, deal_id, current_user)

    # Загружаем реестр вопросов (глобальные + привязанные к воронке сделки)
    questions = (await session.execute(
        select(MeetingReportQuestion).where(
            MeetingReportQuestion.is_active.is_(True),
            or_(
                MeetingReportQuestion.pipeline_id.is_(None),
                MeetingReportQuestion.pipeline_id == d.pipeline_id,
            )
        )
    )).scalars().all()
    valid_question_ids = {q.id for q in questions}

    # Валидируем ответы
    for answer in data.answers:
        qid = answer.get("question_id")
        if qid is None:
            raise HTTPException(400, "Каждый ответ должен содержать question_id")
        if valid_question_ids and int(qid) not in valid_question_ids:
            raise HTTPException(400, f"Вопрос {qid} не существует или не активен")

    comment = (data.comment or "").strip() or None

    # Отчёт = только комментарий — валидный кейс (когда вопросы не настроены).
    # Но совсем пустой отчёт (ни ответов, ни комментария) сохранять нет смысла.
    if not data.answers and comment is None:
        raise HTTPException(400, "Отчёт пуст: заполните вопросы или добавьте комментарий")

    report_json: dict[str, Any] = {"answers": data.answers, "comment": comment}

    # Тело активности для Timeline: комментарий + краткая сводка по ответам
    body_lines: list[str] = []
    if comment:
        body_lines.append(comment)
    for answer in data.answers:
        text = answer.get("text") or answer.get("question") or f"Вопрос {answer.get('question_id')}"
        body_lines.append(f"{text}: {answer.get('answer', '')}")
    body = "\n".join(body_lines) or None

    if data.activity_id:
        # Обновляем существующую
        act = (await session.execute(
            select(Activity).where(
                Activity.id == data.activity_id,
                Activity.target_type == "deal",
                Activity.target_id == deal_id,
            )
        )).scalar_one_or_none()
        if not act:
            raise HTTPException(404, f"Активность {data.activity_id} не найдена для данной сделки")
        act.meeting_report_json = report_json
        act.body = body
        await session.commit()
        await session.refresh(act)
    else:
        # Создаём новую meeting-активность
        act = Activity(
            kind="meeting",
            target_type="deal",
            target_id=deal_id,
            title=f"Отчёт о встрече — {d.title}",
            body=body,
            created_by_id=current_user.id,
            responsible_id=current_user.id,
            meeting_report_json=report_json,
        )
        session.add(act)
        await session.commit()
        await session.refresh(act)

    return MeetingReportOut(
        activity_id=act.id,
        deal_id=deal_id,
        meeting_report_json=report_json,
    )


@router.get("/{deal_id}/history", response_model=list[DealStageHistoryOut])
async def deal_history(deal_id: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    await _deal_or_403(session, deal_id, current_user)
    rows = (await session.execute(
        select(DealStageHistory).where(DealStageHistory.deal_id == deal_id).order_by(DealStageHistory.created_at)
    )).scalars().all()
    ids = {r.to_stage_id for r in rows} | {r.from_stage_id for r in rows if r.from_stage_id}
    names: dict[int, str] = {}
    if ids:
        for s in (await session.execute(select(PipelineStage).where(PipelineStage.id.in_(ids)))).scalars().all():
            names[s.id] = s.name
    return [
        DealStageHistoryOut(
            id=r.id, from_stage_id=r.from_stage_id, from_stage_name=names.get(r.from_stage_id) if r.from_stage_id else None,
            to_stage_id=r.to_stage_id, to_stage_name=names.get(r.to_stage_id), user_id=r.user_id, created_at=r.created_at,
        )
        for r in rows
    ]


# ============ Wave 4 — позиции-продукты сделки + авто-сумма ============


async def _recompute_deal_amount(session: AsyncSession, deal: Deal) -> None:
    """Авто-сумма Deal.amount по позициям в валюте сделки.

    Прецеденс:
    - если у сделки нет currency — берём валюту первой позиции и проставляем её;
    - Deal.amount = сумма amount позиций В ВАЛЮТЕ СДЕЛКИ (мультивалютные строки
      в другой валюте игнорируются);
    - если позиций нет — amount НЕ трогаем (ручное значение остаётся);
    - Deal.amount остаётся ручно-редактируемым через PATCH /deals/{id}; но любое
      изменение состава позиций пересчитывает его (line changes win).
    """
    lines = (await session.execute(
        select(DealProduct).where(DealProduct.deal_id == deal.id)
    )).scalars().all()
    if not lines:
        return
    if deal.currency is None:
        deal.currency = lines[0].currency
    total = deal_total(
        [(ln.amount, ln.currency) for ln in lines], deal.currency,
    )
    deal.amount = total


async def _resolve_unit_price(
    session: AsyncSession, product_id: int, plan_id: int | None, currency: str,
) -> Decimal | None:
    """Цена из ProductPrice по (product_id, plan_id, currency).

    Fallback: если для plan_id цены нет — берём base-цену (plan_id IS NULL).
    """
    price = (await session.execute(
        select(ProductPrice.amount).where(
            ProductPrice.product_id == product_id,
            ProductPrice.plan_id == plan_id,
            ProductPrice.currency == currency,
        )
    )).scalar_one_or_none()
    if price is None and plan_id is not None:
        price = (await session.execute(
            select(ProductPrice.amount).where(
                ProductPrice.product_id == product_id,
                ProductPrice.plan_id.is_(None),
                ProductPrice.currency == currency,
            )
        )).scalar_one_or_none()
    return price


async def _deal_product_out(session: AsyncSession, ln: DealProduct) -> DealProductOut:
    product = (await session.execute(
        select(Product).where(Product.id == ln.product_id)
    )).scalar_one_or_none()
    plan = None
    if ln.plan_id is not None:
        plan = (await session.execute(
            select(ProductPlan).where(ProductPlan.id == ln.plan_id)
        )).scalar_one_or_none()
    return DealProductOut(
        id=ln.id, deal_id=ln.deal_id, product_id=ln.product_id, plan_id=ln.plan_id,
        product_name=product.name if product else None,
        plan_name=plan.name if plan else None,
        quantity=float(ln.quantity), unit_price=float(ln.unit_price),
        currency=ln.currency, amount=float(ln.amount), sort_order=ln.sort_order,
    )


@router.get("/{deal_id}/products", response_model=list[DealProductOut])
async def list_deal_products(
    deal_id: int, current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    await _deal_or_403(session, deal_id, current_user)
    lines = (await session.execute(
        select(DealProduct).where(DealProduct.deal_id == deal_id)
        .order_by(DealProduct.sort_order, DealProduct.id)
    )).scalars().all()
    return [await _deal_product_out(session, ln) for ln in lines]


@router.post("/{deal_id}/products", response_model=DealProductOut, status_code=status.HTTP_201_CREATED)
async def add_deal_product(
    deal_id: int, data: DealProductIn, current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    # P1 concurrency (audit S6 B1): row lock — пересчёт Deal.amount по позициям
    # сериализуем, чтобы конкурентные правки строк не теряли пересчёт (line
    # changes win, но под lock'ом).
    d = await _deal_or_403(session, deal_id, current_user, for_update=True)
    product = (await session.execute(
        select(Product).where(Product.id == data.product_id)
    )).scalar_one_or_none()
    if not product:
        raise HTTPException(404, f"Продукт {data.product_id} не найден")
    if data.plan_id is not None:
        plan = (await session.execute(
            select(ProductPlan).where(
                ProductPlan.id == data.plan_id, ProductPlan.product_id == data.product_id,
            )
        )).scalar_one_or_none()
        if not plan:
            raise HTTPException(400, "Тариф не из этого продукта")
    # Валюта позиции: явная → валюта сделки → дефолт через первую цену продукта.
    currency = data.currency or d.currency
    if currency is None:
        any_price = (await session.execute(
            select(ProductPrice.currency).where(ProductPrice.product_id == data.product_id).limit(1)
        )).scalar_one_or_none()
        currency = any_price
    if currency is None:
        raise HTTPException(400, "Не удалось определить валюту: задайте currency или валюту сделки")
    # Цена: ручной override → из прайса. Если ни то ни другое — 400.
    if data.unit_price is not None:
        unit_price = Decimal(str(data.unit_price))
    else:
        resolved = await _resolve_unit_price(session, data.product_id, data.plan_id, currency)
        if resolved is None:
            raise HTTPException(400, f"Нет цены продукта в валюте {currency}: задайте unit_price вручную")
        unit_price = resolved
    qty = Decimal(str(data.quantity))
    max_order = (await session.execute(
        select(sqlfunc.max(DealProduct.sort_order)).where(DealProduct.deal_id == deal_id)
    )).scalar_one()
    ln = DealProduct(
        deal_id=deal_id, product_id=data.product_id, plan_id=data.plan_id,
        quantity=qty, unit_price=unit_price, currency=currency,
        amount=line_amount(qty, unit_price),
        sort_order=(max_order or 0) + 1,
    )
    session.add(ln)
    await session.flush()
    await _recompute_deal_amount(session, d)
    await session.commit()
    await session.refresh(ln)
    return await _deal_product_out(session, ln)


@router.patch("/{deal_id}/products/{line_id}", response_model=DealProductOut)
async def update_deal_product(
    deal_id: int, line_id: int, data: DealProductPatch, current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    # P1 concurrency (audit S6 B1): row lock на сделку для безопасного пересчёта.
    d = await _deal_or_403(session, deal_id, current_user, for_update=True)
    ln = (await session.execute(
        select(DealProduct).where(DealProduct.id == line_id, DealProduct.deal_id == deal_id)
    )).scalar_one_or_none()
    if not ln:
        raise HTTPException(404, "Позиция не найдена")
    if data.quantity is not None:
        ln.quantity = Decimal(str(data.quantity))
    if data.unit_price is not None:
        ln.unit_price = Decimal(str(data.unit_price))
    ln.amount = line_amount(ln.quantity, ln.unit_price)
    await session.flush()
    await _recompute_deal_amount(session, d)
    await session.commit()
    await session.refresh(ln)
    return await _deal_product_out(session, ln)


@router.delete("/{deal_id}/products/{line_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_deal_product(
    deal_id: int, line_id: int, current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    # P1 concurrency (audit S6 B1): row lock на сделку для безопасного пересчёта.
    d = await _deal_or_403(session, deal_id, current_user, for_update=True)
    ln = (await session.execute(
        select(DealProduct).where(DealProduct.id == line_id, DealProduct.deal_id == deal_id)
    )).scalar_one_or_none()
    if not ln:
        raise HTTPException(404, "Позиция не найдена")
    await session.delete(ln)
    await session.flush()
    await _recompute_deal_amount(session, d)
    await session.commit()


# ============ Wave 4 — контакты сделки (с пропагацией в company) ============


async def _ensure_contact_company_link(
    session: AsyncSession, contact_id: int, company_id: int,
) -> None:
    """Идемпотентно создать ContactCompanyLink (контакт↔company сделки)."""
    exists = (await session.execute(
        select(ContactCompanyLink.id).where(
            ContactCompanyLink.contact_id == contact_id,
            ContactCompanyLink.company_id == company_id,
        )
    )).scalar_one_or_none()
    if exists is None:
        session.add(ContactCompanyLink(contact_id=contact_id, company_id=company_id))


async def _deal_contact_out(session: AsyncSession, link: DealContact) -> DealContactOut:
    c = (await session.execute(
        select(Contact).where(Contact.id == link.contact_id)
    )).scalar_one_or_none()
    # position — берём из ContactCompanyLink под company сделки, fallback Contact.position.
    return DealContactOut(
        id=link.id, contact_id=link.contact_id,
        full_name=c.full_name if c else "—",
        phone=c.phone if c else None, email=c.email if c else None,
        position=c.position if c else None, sort_order=link.sort_order,
    )


@router.get("/{deal_id}/contacts", response_model=list[DealContactOut])
async def list_deal_contacts(
    deal_id: int, current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    await _deal_or_403(session, deal_id, current_user)
    links = (await session.execute(
        select(DealContact).where(DealContact.deal_id == deal_id)
        .order_by(DealContact.sort_order, DealContact.id)
    )).scalars().all()
    return [await _deal_contact_out(session, link) for link in links]


@router.post("/{deal_id}/contacts", response_model=DealContactOut, status_code=status.HTTP_201_CREATED)
async def add_deal_contact(
    deal_id: int, data: DealContactIn, current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Привязать контакт к сделке. Если contact_id не передан — создаём новый
    Contact из full_name/phone/position. Также создаём ContactCompanyLink между
    контактом и company сделки (идемпотентно), чтобы он попал в «Сотрудники»."""
    d = await _deal_or_403(session, deal_id, current_user)
    contact_id = data.contact_id
    if contact_id is not None:
        c = (await session.execute(
            select(Contact).where(Contact.id == contact_id)
        )).scalar_one_or_none()
        if not c:
            raise HTTPException(404, f"Контакт {contact_id} не найден")
        await ensure_object_visible(session, c, "contact", current_user)
    else:
        if not data.full_name or not data.full_name.strip():
            raise HTTPException(400, "Укажите contact_id или full_name нового контакта")
        c = Contact(
            full_name=data.full_name.strip(), phone=data.phone,
            position=data.position, owner_id=current_user.id,
        )
        session.add(c)
        await session.flush()
        contact_id = c.id
    # Дедуп связи deal↔contact
    existing = (await session.execute(
        select(DealContact).where(
            DealContact.deal_id == deal_id, DealContact.contact_id == contact_id,
        )
    )).scalar_one_or_none()
    if existing is not None:
        return await _deal_contact_out(session, existing)
    max_order = (await session.execute(
        select(sqlfunc.max(DealContact.sort_order)).where(DealContact.deal_id == deal_id)
    )).scalar_one()
    link = DealContact(
        deal_id=deal_id, contact_id=contact_id, sort_order=(max_order or 0) + 1,
    )
    session.add(link)
    # Пропагация в company сделки (если есть)
    if d.company_id is not None:
        await _ensure_contact_company_link(session, contact_id, d.company_id)
        # position на новом контакте — также прокинем в ContactCompanyLink.position
        if data.position:
            cc = (await session.execute(
                select(ContactCompanyLink).where(
                    ContactCompanyLink.contact_id == contact_id,
                    ContactCompanyLink.company_id == d.company_id,
                )
            )).scalar_one_or_none()
            if cc is not None and not cc.position:
                cc.position = data.position
    await session.commit()
    await session.refresh(link)
    return await _deal_contact_out(session, link)


@router.delete("/{deal_id}/contacts/{contact_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_deal_contact(
    deal_id: int, contact_id: int, current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Отвязать контакт от сделки. ContactCompanyLink НЕ удаляем — контакт
    остаётся сотрудником компании независимо от сделки."""
    await _deal_or_403(session, deal_id, current_user)
    link = (await session.execute(
        select(DealContact).where(
            DealContact.deal_id == deal_id, DealContact.contact_id == contact_id,
        )
    )).scalar_one_or_none()
    if not link:
        raise HTTPException(404, "Контакт не привязан к сделке")
    await session.delete(link)
    await session.commit()


# ============ Эпик 18 — AI Deal Prefill ============

from fastapi import HTTPException, status as fastapi_status

from app.schemas import DealPrefillOut, DealPrefillSuggestion
from app.services.ai_features import prefill_for_target
from app.services.anthropic_client import (
    AINotConfiguredError,
    AIResponseError,
    AIServiceError,
)


@router.post("/{deal_id}/ai-prefill", response_model=DealPrefillOut)
async def ai_prefill_deal(
    deal_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    source: str = Query(
        default="all",
        description="Источник данных: all | tg | email | notes | activities | messages",
    ),
    period_days: int = Query(
        default=30,
        description="Период в днях. 0 = всё время. 7 / 30 / 90 — типовые значения.",
        ge=0, le=365,
    ),
):
    """Сгенерировать AI-предложения по полям сделки на основе истории активностей.

    Anti-cache: каждый вызов — свежий запрос Claude (данные постоянно меняются).
    Не модифицирует deal — клиент применяет выбранные suggestions через
    PATCH /api/deals/{id}.

    503 если AI не настроен; 502 если Claude недоступен/невалидный JSON.
    """
    # Проверка доступа к сделке (видимость этапа).
    d = await _deal_or_403(session, deal_id, current_user)

    try:
        result = await prefill_for_target(
            session, "deal", d.id,
            user_id=current_user.id,
            source=source, period_days=period_days,
        )
    except AINotConfiguredError:
        await session.commit()
        raise HTTPException(
            fastapi_status.HTTP_503_SERVICE_UNAVAILABLE,
            "AI not configured: установите ANTHROPIC_API_KEY на бэкенде",
        )
    except (AIResponseError, AIServiceError) as e:
        await session.commit()
        raise HTTPException(
            fastapi_status.HTTP_502_BAD_GATEWAY,
            f"AI service error: {e}",
        )
    await session.commit()

    return DealPrefillOut(
        deal_id=d.id,
        source=source,
        period_days=period_days,
        summary=result.get("summary", ""),
        suggestions=[
            DealPrefillSuggestion.model_validate(s)
            for s in result.get("suggestions", [])
        ],
        ai_tokens_used=result.get("ai_tokens_used"),
        model=result.get("model"),
    )
