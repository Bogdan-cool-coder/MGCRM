"""Epic 10.5 — Personal cabinet endpoints /api/me/*.

Личный кабинет менеджера:
- GET /api/me/profile — расширенный профиль current_user (для шапки /me)
- GET /api/me/dashboard — главная страница (KPI + hot deals + tasks + notifications)
- GET /api/me/motivational-card — текущая МК (или по year/month)
- GET /api/me/motivational-card/history — последние N МК
- POST /api/me/motivational-card/finalize — финализировать МК (admin)
- POST /api/me/motivational-card/compute — пересчитать МК за период
- GET /api/me/metrics — личная эффективность за N дней
- GET /api/me/subordinates — подопечные менеджера (для руководителей)
- GET /api/me/dashboard-config — сохранённый JSON-конфиг дашборда (Wave 2a)
- PUT /api/me/dashboard-config — сохранить JSON-конфиг дашборда (Wave 2a)
"""
from __future__ import annotations

import json
from datetime import UTC, date, datetime, timedelta
from decimal import Decimal
from typing import Annotated, Any

from fastapi import APIRouter, Body, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict
from sqlalchemy import func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import (
    Activity,
    ClientTask,
    Counterparty,
    Deal,
    Department,
    MotivationalCard,
    Notification,
    PipelineStage,
    User,
    UserRole,
)
from app.services.me_kpi import (
    compute_personal_kpi,
    compute_personal_kpi_batch,
    list_team_member_ids,
    parse_period,
    score_pct,
    team_avg_pct,
    team_rank,
    to_float,
)
from app.services.salary import compute_motivational_card, finalize_motivational_card

router = APIRouter(prefix="/me", tags=["me"])

# Отдельный роутер для ресурса /motivational-cards/* (фронт бьёт сюда напрямую,
# не под /me). Регистрируется в app/main.py тем же prefix='/api'.
mk_router = APIRouter(prefix="/motivational-cards", tags=["motivational-cards"])


# ============ Schemas ============


class MeProfileOut(BaseModel):
    """Расширенный профиль пользователя для шапки личного кабинета (/me).

    Super-set: содержит поля, которые читает фронт (`MeProfile` из
    `apps/web/src/lib/types.ts`) **плюс** дополнительные UX-Profile поля
    (theme/locale/salary/employment), чтобы один endpoint покрывал все
    use-кейсы — и шапку, и табы «Настройки».

    Все nullable-поля возвращаются как None (а не отсутствующие ключи),
    чтобы TS-клиент имел стабильный shape.
    """

    # === Frontend `MeProfile` minimum (используется в MePageHeader) ===
    id: int
    full_name: str
    email: str
    role: str
    job_title: str | None = None
    department_name: str | None = None
    manager_name: str | None = None
    manager_id: int | None = None
    supervisor_name: str | None = None
    supervisor_id: int | None = None
    avatar_path: str | None = None
    subordinates_count: int = 0

    # === Дополнительные поля для табов «Настройки» / зарплата ===
    user_id: int  # дубликат id для совместимости с ТЗ
    avatar_url: str | None = None  # alias avatar_path
    signature_url: str | None = None
    department_id: int | None = None
    salary_currency: str  # default 'RUB' если не задано
    salary_country_code: str | None = None
    employment_start_date: date | None = None
    theme_preference: str  # 'system' | 'light' | 'dark'
    locale: str


async def _build_profile(
    session: AsyncSession,
    user: User,
) -> MeProfileOut:
    """Собрать MeProfileOut по User-объекту, подгружая department / manager."""
    department_name: str | None = None
    if user.department_id is not None:
        department_name = (
            await session.execute(
                select(Department.name).where(Department.id == user.department_id),
            )
        ).scalar_one_or_none()

    manager_name: str | None = None
    if user.manager_id is not None:
        manager_name = (
            await session.execute(
                select(User.full_name).where(User.id == user.manager_id),
            )
        ).scalar_one_or_none()

    # subordinates_count — сколько активных юзеров с manager_id = user.id
    subordinates_count = (
        await session.execute(
            select(func.count(User.id)).where(
                User.manager_id == user.id,
                User.is_active == True,  # noqa: E712
            ),
        )
    ).scalar_one() or 0

    return MeProfileOut(
        id=user.id,
        user_id=user.id,
        full_name=user.full_name,
        email=user.email,
        role=user.role.value if hasattr(user.role, "value") else str(user.role),
        job_title=user.job_title,
        department_id=user.department_id,
        department_name=department_name,
        manager_id=user.manager_id,
        manager_name=manager_name,
        supervisor_id=user.manager_id,  # alias: пока единая иерархия
        supervisor_name=manager_name,
        avatar_path=user.avatar_path,
        avatar_url=user.avatar_path,
        signature_url=user.signature_url,
        subordinates_count=int(subordinates_count),
        salary_currency=user.salary_currency or "RUB",
        salary_country_code=user.salary_country_code,
        employment_start_date=user.employment_start_date,
        theme_preference=user.theme_preference or "system",
        locale=user.locale or "ru",
    )


class MotivationalCardOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    user_id: int
    period_year: int
    period_month: int
    plan_snapshot_json: dict | None
    fact_base_salary_amount: Decimal | None
    fact_commission_amount: Decimal | None
    fact_commission_currency: str | None
    fact_commission_breakdown: list | None
    fact_team_bonus_proportional_amount: Decimal | None
    fact_team_bonus_equal_amount: Decimal | None
    total_amount_local: Decimal | None
    total_amount_currency_local: str | None
    exchange_rates_snapshot: dict | None
    exchange_rates_date: date | None
    ftm_count_fact: int
    new_income_amount_fact: Decimal | None
    new_income_currency_fact: str | None
    status: str
    finalized_at: datetime | None
    finalized_by_user_id: int | None
    paid_at: datetime | None
    created_at: datetime
    updated_at: datetime


class DashboardDealOut(BaseModel):
    """Активная сделка для виджета «Мои сделки» (MeDashboardDeal в types.ts)."""

    id: int
    title: str
    counterparty_name: str | None = None
    stage_name: str
    amount: float | None = None
    currency: str | None = None
    heat_score: float | None = None  # пока не персистится — всегда null


class DashboardTaskOut(BaseModel):
    """Задача на сегодня для виджета «Задачи» (MeDashboardTask в types.ts)."""

    id: int
    title: str
    due_at: str | None = None
    is_overdue: bool = False
    target_type: str | None = None
    target_name: str | None = None
    completed_at: str | None = None


class DashboardOut(BaseModel):
    """Ответ GET /me/dashboard — соответствует MeDashboard (types.ts).

    KPI-блок (StatsBar / MonthProgressWidget) + активные сделки + задачи дня.
    Период задаётся ?period= / ?month= (см. parse_period).
    """

    personal_income_fact: float | None = None
    personal_income_plan: float | None = None
    team_income_fact: float | None = None
    team_income_plan: float | None = None
    ftm_count_fact: int | None = None
    ftm_count_plan: int | None = None
    score_pct: int | None = None
    personal_income_currency: str | None = None
    active_deals: list[DashboardDealOut] = []
    today_tasks: list[DashboardTaskOut] = []


class MetricsFunnelStage(BaseModel):
    stage_name: str
    count: int
    conversion_pct: float | None = None


class MetricsOut(BaseModel):
    """Ответ GET /me/metrics — соответствует MeMetrics (types.ts).

    Личные метрики (продажи по дням, воронка, цикл) + сравнение с командой.
    """

    sales_by_day: list[dict] | None = None
    funnel: list[MetricsFunnelStage] | None = None
    avg_cycle_days: float | None = None
    personal_pct: int | None = None
    team_avg_pct: int | None = None
    team_rank: int | None = None
    team_size: int | None = None


class ActivityFeedOut(BaseModel):
    """Строка ленты активности (GET /me/activities) — подмножество Activity (types.ts)."""

    id: int
    kind: str
    target_type: str | None = None
    target_id: int | None = None
    title: str
    body: str | None = None
    due_at: str | None = None
    completed_at: str | None = None
    responsible_id: int | None = None
    created_by_id: int | None = None
    created_at: str
    is_first_time_meeting: bool = False
    ftm_counted: bool = False


class FinalizeIn(BaseModel):
    year: int
    month: int
    user_id: int | None = None


class ComputeIn(BaseModel):
    year: int
    month: int
    user_id: int | None = None


class SubordinateOut(BaseModel):
    """Подопечный менеджера (GET /me/subordinates) — соответствует MeSubordinate (types.ts).

    Period-aware: income plan/fact/pct + ftm считаются за выбранный период.
    """

    id: int
    full_name: str
    department_name: str | None = None
    personal_income_plan: float = 0.0
    personal_income_fact: float = 0.0
    personal_income_currency: str = "RUB"
    personal_income_pct: int = 0
    ftm_count_fact: int = 0
    ftm_count_plan: int = 0


# ============ Permission helper ============


async def _resolve_target_user(
    session: AsyncSession,
    viewer: User,
    user_id: int | None,
) -> User:
    """Вернуть пользователя, чьи данные просматриваем, с проверкой прав.

    Правила scope:
      - user_id is None / == viewer.id → сам viewer (всегда можно);
      - admin/director → могут смотреть любого;
      - manager → только своих прямых подчинённых (User.manager_id == viewer.id);
      - иначе → 403.
    404 если целевой пользователь не найден.
    """
    if user_id is None or user_id == viewer.id:
        return viewer

    target = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    if target is None:
        raise HTTPException(status_code=404, detail="Пользователь не найден")

    if viewer.role in (UserRole.admin, UserRole.director):
        return target
    if target.manager_id == viewer.id:
        return target
    raise HTTPException(
        status_code=403,
        detail="Нет доступа к данным этого пользователя",
    )


# ============ Endpoints ============


@router.get("/profile", response_model=MeProfileOut)
async def get_me_profile(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> MeProfileOut:
    """Расширенный профиль current_user для шапки /me и табов «Настройки».

    Возвращает имя, должность, отдел (с JOIN), руководителя (с JOIN),
    число подчинённых, аватар/подпись, UX-настройки (тема/локаль),
    и блок зарплаты (валюта/страна/дата найма).

    Все nullable-поля = None, а не отсутствие ключа — чтобы TS-клиент
    имел стабильный shape.
    """
    return await _build_profile(session, current_user)


@router.get("/dashboard", response_model=DashboardOut)
async def get_my_dashboard(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    period: Annotated[
        str | None,
        Query(
            description=(
                "Период KPI: 'YYYY-MM' | 'current_month' | 'last_month' | "
                "'current_quarter' | 'current_year'. Дефолт — текущий месяц."
            ),
        ),
    ] = None,
    month: Annotated[
        str | None,
        Query(description="Alias для period (формат 'YYYY-MM'). Имеет приоритет если задан."),
    ] = None,
    user_id: Annotated[
        int | None,
        Query(description="Просмотр данных подчинённого (manager/director/admin)."),
    ] = None,
) -> DashboardOut:
    """KPI-дашборд личного кабинета: «Выполнение МК», личные/командные продажи,
    FTM, активные сделки и задачи дня.

    Период: ?period= (пресет или YYYY-MM) или ?month=YYYY-MM. Дефолт — текущий
    месяц. Виджет «Прогресс <месяц>» использует ?period=YYYY-MM для прошлых месяцев.

    score_pct = round(personal_income_fact / personal_income_plan * 100)
    (0 при отсутствии плана если факт=0, иначе 100). Источник fact — расчёт МК.
    """
    target = await _resolve_target_user(session, current_user, user_id)
    today = datetime.now(tz=UTC).date()
    try:
        pr = parse_period(month or period, today=today)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))

    kpi = await compute_personal_kpi(session, target.id, pr.year, pr.month)

    # ---- Активные сделки (не won/lost) с именем контрагента и этапа ----
    deals_rows = list(
        (
            await session.execute(
                select(Deal, PipelineStage, Counterparty)
                .join(PipelineStage, Deal.stage_id == PipelineStage.id)
                .outerjoin(Counterparty, Deal.counterparty_id == Counterparty.id)
                .where(
                    Deal.owner_user_id == target.id,
                    PipelineStage.is_won == False,  # noqa: E712
                    PipelineStage.is_lost == False,  # noqa: E712
                )
                .order_by(Deal.created_at.desc())
                .limit(20)
            )
        ).all()
    )
    active_deals = [
        DashboardDealOut(
            id=d.id,
            title=d.title,
            counterparty_name=cp.name if cp else None,
            stage_name=st.name,
            amount=to_float(d.amount),
            currency=d.currency,
            heat_score=None,
        )
        for (d, st, cp) in deals_rows
    ]

    # ---- Задачи дня (Activity kind=task/call/meeting с дедлайном сегодня/просрочены, не закрытые) ----
    start_dt = datetime(today.year, today.month, today.day, tzinfo=UTC)
    end_dt = start_dt + timedelta(days=1)
    task_rows = list(
        (
            await session.execute(
                select(Activity)
                .where(
                    Activity.responsible_id == target.id,
                    Activity.completed_at.is_(None),
                    Activity.due_at.is_not(None),
                    Activity.due_at < end_dt,
                )
                .order_by(Activity.due_at.asc())
                .limit(20)
            )
        ).scalars().all()
    )
    today_tasks = [
        DashboardTaskOut(
            id=a.id,
            title=a.title,
            due_at=a.due_at.isoformat() if a.due_at else None,
            is_overdue=bool(a.due_at and a.due_at < start_dt),
            target_type=a.target_type,
            target_name=None,
            completed_at=a.completed_at.isoformat() if a.completed_at else None,
        )
        for a in task_rows
    ]

    return DashboardOut(
        personal_income_fact=to_float(kpi.personal_income_fact),
        personal_income_plan=to_float(kpi.personal_income_plan),
        team_income_fact=to_float(kpi.team_income_fact),
        team_income_plan=to_float(kpi.team_income_plan),
        ftm_count_fact=kpi.ftm_count_fact,
        ftm_count_plan=kpi.ftm_count_plan,
        score_pct=kpi.score_pct,
        personal_income_currency=kpi.personal_income_currency,
        active_deals=active_deals,
        today_tasks=today_tasks,
    )


@router.get("/motivational-card", response_model=MotivationalCardOut | None)
async def get_my_motivational_card(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    year: int | None = Query(None, description="Год периода (legacy, c month)."),
    month: str | None = Query(
        None,
        description=(
            "Месяц периода как '1'..'12' (legacy, c year) ИЛИ строка 'YYYY-MM' "
            "(alias period). 'YYYY-MM' имеет приоритет над year."
        ),
    ),
    period: Annotated[
        str | None,
        Query(description="Период 'YYYY-MM' (приоритетнее year/month если задан)."),
    ] = None,
    user_id: Annotated[
        int | None,
        Query(description="МК подчинённого (manager/director/admin), иначе 403."),
    ] = None,
) -> MotivationalCardOut | None:
    """МК текущего пользователя ИЛИ подчинённого за период.

    B5 CRITICAL: раньше эндпоинт жёстко фильтровал current_user.id и игнорировал
    ?user_id=/?period= → руководитель видел СВОЮ карточку под именем подчинённого.
    Теперь:
      - user_id → _resolve_target_user (self / свой подчинённый / admin-director),
        иначе 403/404;
      - period='YYYY-MM' (или month как строка 'YYYY-MM') резолвится через
        parse_period; legacy year+month(int) — сохранены для совместимости.
    """
    target = await _resolve_target_user(session, current_user, user_id)
    today = datetime.now(tz=UTC).date()

    # period (или month-как-строка 'YYYY-MM') приоритетнее legacy year/month.
    period_str = period
    if period_str is None and month is not None and "-" in month:
        period_str = month

    if period_str is not None:
        try:
            pr = parse_period(period_str, today=today)
        except ValueError as e:
            raise HTTPException(status_code=422, detail=str(e))
        target_year, target_month = pr.year, pr.month
    else:
        # legacy: year + month как '1'..'12'.
        target_year = year or today.year
        if month is not None:
            try:
                target_month = int(month)
            except ValueError:
                raise HTTPException(status_code=422, detail=f"Некорректный month: {month!r}")
        else:
            target_month = today.month

    card = (
        await session.execute(
            select(MotivationalCard).where(
                MotivationalCard.user_id == target.id,
                MotivationalCard.period_year == target_year,
                MotivationalCard.period_month == target_month,
            )
        )
    ).scalar_one_or_none()
    return card


@router.get("/motivational-card/history", response_model=list[MotivationalCardOut])
async def get_motivational_card_history(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    limit: int = Query(12, le=36),
) -> list[MotivationalCardOut]:
    """История МК (последние N месяцев)."""
    rows = list(
        (
            await session.execute(
                select(MotivationalCard)
                .where(MotivationalCard.user_id == current_user.id)
                .order_by(
                    MotivationalCard.period_year.desc(),
                    MotivationalCard.period_month.desc(),
                )
                .limit(limit)
            )
        ).scalars().all()
    )
    return rows


@router.post("/motivational-card/compute", response_model=MotivationalCardOut)
async def compute_my_motivational_card(
    body: ComputeIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> MotivationalCardOut:
    """Запустить пересчёт МК. Менеджер — только свою. Admin — любую."""
    target_user_id = body.user_id or current_user.id

    if (
        target_user_id != current_user.id
        and current_user.role not in (UserRole.admin, UserRole.director)
    ):
        raise HTTPException(status_code=403, detail="Not authorized to compute other user's MK")

    try:
        card = await compute_motivational_card(
            session, target_user_id, body.year, body.month
        )
        await session.commit()
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))
    return card


@router.post("/motivational-card/finalize", response_model=MotivationalCardOut)
async def finalize_mk(
    body: FinalizeIn,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> MotivationalCardOut:
    """Финализировать МК: фиксирует курсы и данные. Только admin."""
    target_user_id = body.user_id or admin.id
    try:
        card = await finalize_motivational_card(
            session, target_user_id, body.year, body.month, admin.id
        )
        await session.commit()
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))
    return card


@router.get("/metrics", response_model=MetricsOut)
async def get_my_metrics(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    period: Annotated[
        str | None,
        Query(description="Период 'YYYY-MM' или пресет. Дефолт — текущий месяц."),
    ] = None,
    month: Annotated[str | None, Query(description="Alias для period (YYYY-MM).")] = None,
    user_id: Annotated[int | None, Query(description="Просмотр метрик подчинённого.")] = None,
) -> MetricsOut:
    """Личные метрики + «Сравнение с командой».

    Сравнение с командой (DOCUMENTED):
      - personal_pct = % выполнения МК пользователя (= score_pct,
        round(personal_income_fact / personal_income_plan * 100));
      - team = активные менеджеры (role='manager') того же отдела
        (User.department_id); сам пользователь включён;
      - team_avg_pct = round(mean(% выполнения каждого члена команды));
      - team_rank = 1 + (число членов со СТРОГО большим %), competition ranking;
      - team_size = число членов команды.

    sales_by_day / funnel / avg_cycle_days пока возвращаются пустыми (TBD:
    вынести в analytics-сервис), фронт корректно отрисовывает пустые состояния.
    """
    target = await _resolve_target_user(session, current_user, user_id)
    today = datetime.now(tz=UTC).date()
    try:
        pr = parse_period(month or period, today=today)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))

    # Команда: считаем % выполнения по каждому члену. Perf — забатчиваем KPI
    # всех членов команды (включая target) одним проходом вместо per-member
    # цикла (устраняет N+1 и team_size² на team-bonus). list_team_member_ids
    # всегда включает target.id, так что personal берётся из того же батча.
    member_ids = await list_team_member_ids(session, target)
    kpis = await compute_personal_kpi_batch(session, member_ids, pr.year, pr.month)
    personal = kpis[target.id].score_pct
    member_pcts: list[int] = [kpis[mid].score_pct for mid in member_ids]

    avg = team_avg_pct(member_pcts)
    rank = team_rank(personal, member_pcts)

    return MetricsOut(
        sales_by_day=[],
        funnel=[],
        avg_cycle_days=None,
        personal_pct=personal,
        team_avg_pct=avg,
        team_rank=rank,
        team_size=len(member_ids),
    )


@router.get("/activities", response_model=list[ActivityFeedOut])
async def get_my_activities(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    kind: Annotated[
        str | None,
        Query(description="Фильтр по типу: call|meeting|task|note."),
    ] = None,
    period: Annotated[
        str,
        Query(description="Окно ленты: today|week|month. Дефолт — week."),
    ] = "week",
    ftm_only: Annotated[
        bool,
        Query(description="Только FTM-встречи (meeting + is_first_time_meeting)."),
    ] = False,
    user_id: Annotated[int | None, Query(description="Лента подчинённого.")] = None,
) -> list[ActivityFeedOut]:
    """Лента активностей пользователя (вкладка «Активность»).

    Метрика «Активность» (для отчёта владельца): это ЛЕНТА / счётчик записей
    Activity, за которые пользователь ОТВЕТСТВЕНЕН (Activity.responsible_id =
    user) за период. Это feed строк, НЕ агрегат.

    Фильтры:
      - kind: точное совпадение по типу активности;
      - period: today (с начала дня) | week (последние 7 дней) | month (30 дней),
        по Activity.created_at;
      - ftm_only: kind='meeting' AND is_first_time_meeting=True;
      - user_id: лента подчинённого (manager/director/admin), с проверкой прав.

    ftm_counted в ответе — засчитана ли встреча как FTM (все 5 условий FTM).
    """
    target = await _resolve_target_user(session, current_user, user_id)
    now = datetime.now(tz=UTC)
    if period == "today":
        since = datetime(now.year, now.month, now.day, tzinfo=UTC)
    elif period == "month":
        since = now - timedelta(days=30)
    else:  # week (default)
        since = now - timedelta(days=7)

    stmt = (
        select(Activity)
        .where(
            Activity.responsible_id == target.id,
            Activity.created_at >= since,
        )
        .order_by(Activity.created_at.desc())
        .limit(500)
    )
    if kind:
        stmt = stmt.where(Activity.kind == kind)
    if ftm_only:
        stmt = stmt.where(
            Activity.kind == "meeting",
            Activity.is_first_time_meeting == True,  # noqa: E712
        )

    rows = list((await session.execute(stmt)).scalars().all())

    def _ftm_counted(a: Activity) -> bool:
        return (
            a.kind == "meeting"
            and a.is_first_time_meeting
            and a.ftm_decision_maker_attended
            and a.ftm_presentation_shown
            and bool(a.ftm_report_url)
        )

    return [
        ActivityFeedOut(
            id=a.id,
            kind=a.kind,
            target_type=a.target_type,
            target_id=a.target_id,
            title=a.title,
            body=a.body,
            due_at=a.due_at.isoformat() if a.due_at else None,
            completed_at=a.completed_at.isoformat() if a.completed_at else None,
            responsible_id=a.responsible_id,
            created_by_id=a.created_by_id,
            created_at=a.created_at.isoformat(),
            is_first_time_meeting=bool(a.is_first_time_meeting),
            ftm_counted=_ftm_counted(a),
        )
        for a in rows
    ]


@router.get("/subordinates", response_model=list[SubordinateOut])
async def get_my_subordinates(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    period: Annotated[
        str | None,
        Query(description="Период 'YYYY-MM' или пресет. Дефолт — текущий месяц."),
    ] = None,
    month: Annotated[str | None, Query(description="Alias для period (YYYY-MM).")] = None,
) -> list[SubordinateOut]:
    """Подчинённые (manager_id = current_user.id) с period-aware KPI.

    Для каждого подчинённого считаем (за период): personal income plan/fact/pct,
    ftm fact/plan (через compute_personal_kpi → salary.py) + название отдела.
    """
    today = datetime.now(tz=UTC).date()
    try:
        pr = parse_period(month or period, today=today)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))

    rows = list(
        (
            await session.execute(
                select(User).where(
                    User.manager_id == current_user.id,
                    User.is_active == True,  # noqa: E712
                )
                .order_by(User.full_name)
            )
        ).scalars().all()
    )

    # department_name lookup (одним запросом)
    dept_ids = {u.department_id for u in rows if u.department_id is not None}
    dept_names: dict[int, str] = {}
    if dept_ids:
        for did, dname in (
            await session.execute(
                select(Department.id, Department.name).where(Department.id.in_(dept_ids))
            )
        ).all():
            dept_names[did] = dname

    # Perf: KPI всех подчинённых считаем одним батчем (общие SalaryPlan/User
    # одним запросом + team_income_fact кешируется на команду), вместо вызова
    # compute_personal_kpi в per-member цикле (N+1 + team_size²).
    kpis = await compute_personal_kpi_batch(
        session, [u.id for u in rows], pr.year, pr.month
    )

    out: list[SubordinateOut] = []
    for u in rows:
        kpi = kpis[u.id]
        out.append(
            SubordinateOut(
                id=u.id,
                full_name=u.full_name,
                department_name=(
                    dept_names.get(u.department_id) if u.department_id else None
                ),
                personal_income_plan=to_float(kpi.personal_income_plan) or 0.0,
                personal_income_fact=to_float(kpi.personal_income_fact) or 0.0,
                personal_income_currency=kpi.personal_income_currency or "RUB",
                personal_income_pct=kpi.score_pct,
                ftm_count_fact=kpi.ftm_count_fact,
                ftm_count_plan=kpi.ftm_count_plan or 0,
            )
        )
    return out


# ============ Wave 2a — Customizable dashboard config ============

# Лимит размера сериализованного конфига (защита от раздувания строки users).
# Фронт хранит компактный массив виджетов — 32КБ с огромным запасом.
_DASHBOARD_CONFIG_MAX_BYTES = 32 * 1024
# Максимальная глубина вложенности JSON. Размерный лимит (32КБ) не ловит
# глубоко вложенные структуры вида [[[[...]]]] — они компактны, но рекурсивная
# обработка/сериализация такого конфига может уронить интерпретатор
# (RecursionError / stack overflow). 32 уровня с запасом покрывают любую
# легитимную раскладку виджетов (плоский список / grid из вложенных секций).
_DASHBOARD_CONFIG_MAX_DEPTH = 32


class DashboardConfigOut(BaseModel):
    """Ответ GET /me/dashboard-config. config = freeform JSON (object|array) или
    None если пользователь ещё ничего не сохранял (дефолтная раскладка)."""

    config: Any | None = None


def _json_depth(value: Any, _limit: int) -> int:
    """Глубина вложенности JSON-значения (итеративно, без рекурсии — чтобы сам
    обход не упал на враждебном входе). Возвращает фактическую глубину, но
    прекращает обход как только она превысила _limit (early-exit для DoS-входа,
    не разворачивая всю структуру)."""
    max_seen = 0
    # Стек кадров: (value, depth). Листья (примитивы/пустые контейнеры) глубину
    # дальше не увеличивают.
    stack: list[tuple[Any, int]] = [(value, 1)]
    while stack:
        node, depth = stack.pop()
        if depth > max_seen:
            max_seen = depth
        if depth > _limit:
            # Дальше можно не разворачивать — лимит уже превышен.
            return max_seen
        if isinstance(node, dict):
            for v in node.values():
                stack.append((v, depth + 1))
        elif isinstance(node, list):
            for v in node:
                stack.append((v, depth + 1))
    return max_seen


def validate_dashboard_config(config: Any) -> None:
    """Pure-функция валидации входного dashboard-config.

    Правила (бэкенд НЕ знает схему виджетов — её владеет фронт):
      - config обязан быть JSON object (dict) или array (list);
        примитивы (str/int/bool/None) — 400;
      - глубина вложенности ≤ 32 (защита от deep-nested JSON-bomb, который
        обходит размерный лимит);
      - сериализованный размер ≤ 32КБ.
    Бросает ValueError с RU-сообщением на нарушение (роутер мапит в HTTP 400).
    """
    if not isinstance(config, (dict, list)):
        raise ValueError("dashboard_config должен быть JSON-объектом или массивом")
    # Сначала проверяем глубину (дёшево, итеративно) — до json.dumps, чтобы
    # сериализация враждебно-вложенного входа не упала по RecursionError.
    depth = _json_depth(config, _DASHBOARD_CONFIG_MAX_DEPTH)
    if depth > _DASHBOARD_CONFIG_MAX_DEPTH:
        raise ValueError(
            f"dashboard_config слишком глубоко вложен (глубина {depth}, лимит "
            f"{_DASHBOARD_CONFIG_MAX_DEPTH})"
        )
    # Размер считаем по компактной сериализации (ensure_ascii=False — кириллица
    # в виджетах не должна раздувать счётчик в разы).
    size = len(json.dumps(config, ensure_ascii=False).encode("utf-8"))
    if size > _DASHBOARD_CONFIG_MAX_BYTES:
        raise ValueError(
            f"dashboard_config слишком большой ({size} байт, лимит "
            f"{_DASHBOARD_CONFIG_MAX_BYTES})"
        )


@router.get("/dashboard-config", response_model=DashboardConfigOut)
async def get_dashboard_config(
    current_user: CurrentUser,
) -> DashboardConfigOut:
    """Wave 2a: вернуть сохранённый конфиг дашборда current_user (или None).

    None = пользователь не кастомизировал — фронт показывает дефолтную раскладку.
    Cookie-auth, только свой конфиг.
    """
    return DashboardConfigOut(config=current_user.dashboard_config)


@router.put("/dashboard-config", response_model=DashboardConfigOut)
async def put_dashboard_config(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    config: Annotated[
        Any,
        Body(
            ...,
            embed=True,
            description=(
                "Freeform JSON-конфиг дашборда (object|array). Схему владеет "
                "фронт; бэкенд валидирует только тип и размер (≤32КБ)."
            ),
        ),
    ],
) -> DashboardConfigOut:
    """Wave 2a: сохранить конфиг дашборда current_user.

    Тело запроса: {"config": <object|array>}. Валидация типа и размера —
    при нарушении 400. Cookie-auth, только свой конфиг.
    """
    try:
        validate_dashboard_config(config)
    except ValueError as e:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, str(e))

    current_user.dashboard_config = config
    await session.commit()
    await session.refresh(current_user)
    return DashboardConfigOut(config=current_user.dashboard_config)


# ============ B5 — Motivational card PDF (coming soon) ============


@mk_router.get("/{card_id}/pdf")
async def get_motivational_card_pdf(
    card_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Скачать МК как PDF.

    B5: фронт бьёт сюда («Скачать МК PDF»), но рендер пока НЕ реализован —
    в окружении нет ни reportlab/weasyprint, ни soffice-биндинга для МК-шаблона.
    Возвращаем чистый 501 (не 404), чтобы UX-кнопка показывала корректное
    «скоро будет», а не молчаливый битый запрос. Реализация PDF — отдельной
    задачей (нужен PDF-движок + вёрстка карточки).

    Доступ всё равно строго проверяется (IDOR-safe): сначала находим карточку,
    затем — вправе ли пользователь её видеть (self / подчинённый / admin-director).
    Так мы не палим, что карточки не существует, и не отдаём «coming soon» для
    чужих недоступных карточек.
    """
    card = (
        await session.execute(
            select(MotivationalCard).where(MotivationalCard.id == card_id)
        )
    ).scalar_one_or_none()
    if card is None:
        raise HTTPException(status_code=404, detail="Мотивационная карта не найдена")

    # Проверка доступа: владелец карты — self / подчинённый / admin-director.
    # _resolve_target_user бросит 403/404 при отсутствии прав.
    await _resolve_target_user(session, current_user, card.user_id)

    raise HTTPException(
        status_code=status.HTTP_501_NOT_IMPLEMENTED,
        detail="Экспорт мотивационной карты в PDF появится позже.",
    )
