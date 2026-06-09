"""Личный кабинет (/me) — KPI-формулы и period-парсинг.

Чистые функции, тестируются без БД (asyncio_mode=auto, pure pytest):
- parse_period      — нормализация ?period= / ?month= в (year, month) ИЛИ диапазон дат
- score_pct         — «Выполнение МК» = personal_income_fact / plan * 100
- team_avg_pct      — средний % выполнения по команде
- team_rank         — 1-based ранг менеджера в команде по % выполнения (desc)
- to_float          — безопасная сериализация Decimal|None → float|None

Async-функции, которым нужна БД (compute_personal_kpi, list_team_members),
живут в роутере app/routers/me.py и переиспользуют salary.compute_motivational_card.
"""
from __future__ import annotations

import calendar
import logging
from dataclasses import dataclass
from datetime import date
from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import SalaryPlan, TeamTarget, User, UserRole
from app.services.salary import compute_motivational_card

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class PeriodRange:
    """Нормализованный период для /me/* endpoints.

    year/month — «якорный» месяц (для MK-расчёта и виджета «Прогресс <месяц>»).
    start/end — границы диапазона для агрегатов по активностям/сделкам:
      - для одного месяца: 1-е..последнее число месяца;
      - для квартала/года: соответствующий диапазон, а year/month указывают
        на ПОСЛЕДНИЙ месяц диапазона (для MK берём именно его).
    """

    year: int
    month: int
    start: date
    end: date


def parse_period(
    raw: str | None,
    *,
    today: date,
) -> PeriodRange:
    """Распарсить значение ?period= / ?month= в PeriodRange.

    Поддерживаемые форматы (frontend шлёт оба класса значений):
      - "YYYY-MM"          → конкретный месяц (MetricsTab/SummaryTab);
      - "current_month"    → текущий месяц (StatsBar, дефолт);
      - "last_month"       → предыдущий месяц;
      - "current_quarter"  → текущий календарный квартал;
      - "current_year"     → текущий календарный год;
      - None / ""          → текущий месяц.

    Для квартала/года year/month = последний месяц диапазона (для MK-расчёта —
    самый свежий месяц периода, т.к. МК помесячные).

    Бросает ValueError на некорректный "YYYY-MM" (роутер мапит в 422).
    """
    key = (raw or "").strip().lower()

    if not key or key == "current_month":
        return _month_range(today.year, today.month)

    if key == "last_month":
        y, m = today.year, today.month - 1
        if m == 0:
            y, m = y - 1, 12
        return _month_range(y, m)

    if key == "current_quarter":
        q_start_month = ((today.month - 1) // 3) * 3 + 1
        q_end_month = q_start_month + 2
        start = date(today.year, q_start_month, 1)
        _, last_day = calendar.monthrange(today.year, q_end_month)
        end = date(today.year, q_end_month, last_day)
        return PeriodRange(year=today.year, month=q_end_month, start=start, end=end)

    if key == "current_year":
        start = date(today.year, 1, 1)
        end = date(today.year, 12, 31)
        return PeriodRange(year=today.year, month=12, start=start, end=end)

    # "YYYY-MM"
    parts = key.split("-")
    if len(parts) == 2 and parts[0].isdigit() and parts[1].isdigit():
        y, m = int(parts[0]), int(parts[1])
        if 1 <= m <= 12 and 1900 <= y <= 9999:
            return _month_range(y, m)

    raise ValueError(f"Некорректный период: {raw!r} (ожидается YYYY-MM или пресет)")


def _month_range(year: int, month: int) -> PeriodRange:
    _, last_day = calendar.monthrange(year, month)
    return PeriodRange(
        year=year,
        month=month,
        start=date(year, month, 1),
        end=date(year, month, last_day),
    )


def score_pct(
    fact: Decimal | float | None,
    plan: Decimal | float | None,
) -> int:
    """«Выполнение МК» (score_pct) = round(fact / plan * 100).

    Правила (зеркалят фронтовый safePct в StatsBar.tsx):
      - plan отсутствует / 0 → 100 если fact>0, иначе 0;
      - иначе round(fact/plan*100), не отрицательный.

    Pure-функция. Возвращает int (проценты).
    """
    f = Decimal(str(fact)) if fact is not None else Decimal("0")
    p = Decimal(str(plan)) if plan is not None else Decimal("0")
    if p <= 0:
        return 100 if f > 0 else 0
    pct = (f / p) * Decimal("100")
    return max(0, int(pct.to_integral_value(rounding="ROUND_HALF_UP")))


def team_avg_pct(member_pcts: list[int]) -> int:
    """Средний % выполнения по команде = round(mean(member_pcts)).

    Pure-функция. Пустой список → 0.
    """
    if not member_pcts:
        return 0
    return round(sum(member_pcts) / len(member_pcts))


def team_rank(user_pct: int, all_pcts: list[int]) -> int:
    """1-based ранг менеджера в команде по % выполнения (desc).

    Ранг = 1 + (число членов команды со СТРОГО большим %). Ничья → одинаковый
    ранг (стандартный competition ranking). Если user_pct не в списке —
    считаем его как отдельного участника. Пустой список → 1.

    Pure-функция.
    """
    higher = sum(1 for p in all_pcts if p > user_pct)
    return higher + 1


def to_float(value: Decimal | float | int | None) -> float | None:
    """Decimal|None → float|None (для JSON-ответа). None остаётся None."""
    if value is None:
        return None
    return float(value)


# ============ DB helpers (требуют AsyncSession) ============


@dataclass
class PersonalKpi:
    """Личные KPI менеджера за месяц (для дашборда/метрик/команды).

    Все суммы — в personal_income_currency (валюта плана/локали менеджера).
    Если у менеджера нет SalaryPlan на период — fact/plan = None,
    pct = 0 (нечего выполнять).
    """

    personal_income_fact: Decimal | None
    personal_income_plan: Decimal | None
    personal_income_currency: str | None
    ftm_count_fact: int
    ftm_count_plan: int | None
    team_income_fact: Decimal | None
    team_income_plan: Decimal | None
    score_pct: int


async def compute_personal_kpi(
    session: AsyncSession,
    user_id: int,
    year: int,
    month: int,
) -> PersonalKpi:
    """Собрать личные KPI менеджера за период, переиспользуя salary.py.

    Источник fact'а — расчёт мотивационной карты (compute_motivational_card):
      - personal_income_fact = card.new_income_amount_fact
        (сумма eligible-платежей: personal, первый платёж, signed contract);
      - ftm_count_fact        = card.ftm_count_fact;
      - personal_income_plan / ftm_count_plan / team plan — из SalaryPlan/TeamTarget.

    Расчёт НЕ коммитит и НЕ финализирует МК (только читает draft-расчёт).
    Если SalaryPlan на период нет — возвращаем пустые KPI (fact/plan = None,
    score_pct = 0), не падаем (дашборд должен открываться даже без настроенной МК).
    """
    plan = (
        await session.execute(
            select(SalaryPlan).where(
                SalaryPlan.user_id == user_id,
                SalaryPlan.period_year == year,
                SalaryPlan.period_month == month,
            )
        )
    ).scalar_one_or_none()

    if plan is None:
        return PersonalKpi(
            personal_income_fact=None,
            personal_income_plan=None,
            personal_income_currency=None,
            ftm_count_fact=0,
            ftm_count_plan=None,
            team_income_fact=None,
            team_income_plan=None,
            score_pct=0,
        )

    # Валюта показателей — личная валюта менеджера (как в МК).
    user = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    currency = (user.salary_currency if user else None) or "RUB"

    income_fact: Decimal | None = None
    ftm_fact = 0
    try:
        card = await compute_motivational_card(session, user_id, year, month)
        income_fact = card.new_income_amount_fact
        ftm_fact = card.ftm_count_fact or 0
    except ValueError:
        # План есть, но расчёт МК невозможен (нет правила и т.п.) — деградируем.
        logger.warning(
            "compute_personal_kpi: MK compute failed for user=%s %s-%s",
            user_id, year, month,
        )

    income_plan = plan.personal_income_plan_amount
    ftm_plan = plan.personal_ftm_plan

    # Командный план/факт (если привязан team_target).
    team_plan: Decimal | None = None
    team_fact: Decimal | None = None
    if plan.team_target_id is not None:
        tt = (
            await session.execute(
                select(TeamTarget).where(TeamTarget.id == plan.team_target_id)
            )
        ).scalar_one_or_none()
        if tt is not None:
            team_plan = tt.target_amount
            team_fact = await _team_income_fact(session, tt, year, month)

    return PersonalKpi(
        personal_income_fact=income_fact,
        personal_income_plan=income_plan,
        personal_income_currency=currency,
        ftm_count_fact=ftm_fact,
        ftm_count_plan=ftm_plan,
        team_income_fact=team_fact,
        team_income_plan=team_plan,
        score_pct=score_pct(income_fact, income_plan),
    )


async def compute_personal_kpi_batch(
    session: AsyncSession,
    user_ids: list[int],
    year: int,
    month: int,
) -> dict[int, PersonalKpi]:
    """Посчитать личные KPI для НЕСКОЛЬКИХ менеджеров за период, забатчив общие входы.

    Эндпоинты /me/subordinates и /me/metrics раньше вызывали compute_personal_kpi
    в per-member цикле. Это давало два уровня N+1:
      1. На каждого члена — отдельные SELECT'ы SalaryPlan/User.
      2. team_income_fact пересчитывался ВНУТРИ каждого compute_personal_kpi —
         а он суммирует вклад ВСЕЙ команды → team_size × team_size (team_size²).

    Эта функция:
      • грузит все SalaryPlan и User одним запросом каждый (`.in_(user_ids)`);
      • кеширует team_income_fact / TeamTarget по team_target_id, чтобы вклад
        команды считался ОДИН раз на команду, а не на каждого её члена;
      • для членов без SalaryPlan сразу отдаёт пустой KPI (не дёргая тяжёлый
        compute_motivational_card).

    Результат идентичен поэлементному вызову compute_personal_kpi — меняется
    только число запросов. Возвращает {user_id: PersonalKpi} для всех user_ids.
    """
    if not user_ids:
        return {}

    uniq_ids = list(dict.fromkeys(user_ids))

    # 1. Все планы периода — одним запросом.
    plans = (
        await session.execute(
            select(SalaryPlan).where(
                SalaryPlan.user_id.in_(uniq_ids),
                SalaryPlan.period_year == year,
                SalaryPlan.period_month == month,
            )
        )
    ).scalars().all()
    plan_by_user: dict[int, SalaryPlan] = {p.user_id: p for p in plans}

    # 2. Все пользователи — одним запросом (валюта показателей).
    users = (
        await session.execute(select(User).where(User.id.in_(uniq_ids)))
    ).scalars().all()
    user_by_id: dict[int, User] = {u.id: u for u in users}

    # 3. Кеши общекомандных величин (считаются один раз на team_target_id).
    team_target_cache: dict[int, TeamTarget | None] = {}
    team_fact_cache: dict[int, Decimal] = {}

    async def _team_target(tt_id: int) -> TeamTarget | None:
        if tt_id not in team_target_cache:
            team_target_cache[tt_id] = (
                await session.execute(
                    select(TeamTarget).where(TeamTarget.id == tt_id)
                )
            ).scalar_one_or_none()
        return team_target_cache[tt_id]

    out: dict[int, PersonalKpi] = {}
    for uid in uniq_ids:
        plan = plan_by_user.get(uid)
        if plan is None:
            out[uid] = PersonalKpi(
                personal_income_fact=None,
                personal_income_plan=None,
                personal_income_currency=None,
                ftm_count_fact=0,
                ftm_count_plan=None,
                team_income_fact=None,
                team_income_plan=None,
                score_pct=0,
            )
            continue

        u = user_by_id.get(uid)
        currency = (u.salary_currency if u else None) or "RUB"

        income_fact: Decimal | None = None
        ftm_fact = 0
        try:
            card = await compute_motivational_card(session, uid, year, month)
            income_fact = card.new_income_amount_fact
            ftm_fact = card.ftm_count_fact or 0
        except ValueError:
            logger.warning(
                "compute_personal_kpi_batch: MK compute failed for user=%s %s-%s",
                uid, year, month,
            )

        team_plan: Decimal | None = None
        team_fact: Decimal | None = None
        if plan.team_target_id is not None:
            tt = await _team_target(plan.team_target_id)
            if tt is not None:
                team_plan = tt.target_amount
                # team_income_fact одинаков для всех членов одной команды —
                # считаем ровно один раз на team_target_id (убирает team_size²).
                if plan.team_target_id not in team_fact_cache:
                    team_fact_cache[plan.team_target_id] = await _team_income_fact(
                        session, tt, year, month
                    )
                team_fact = team_fact_cache[plan.team_target_id]

        out[uid] = PersonalKpi(
            personal_income_fact=income_fact,
            personal_income_plan=plan.personal_income_plan_amount,
            personal_income_currency=currency,
            ftm_count_fact=ftm_fact,
            ftm_count_plan=plan.personal_ftm_plan,
            team_income_fact=team_fact,
            team_income_plan=team_plan,
            score_pct=score_pct(income_fact, plan.personal_income_plan_amount),
        )

    return out


async def _team_income_fact(
    session: AsyncSession,
    team_target: TeamTarget,
    year: int,
    month: int,
) -> Decimal:
    """Суммарный факт поступлений команды за период (сумма вкладов менеджеров).

    Переиспользует helpers из salary.py, чтобы определение «команды» и «вклада»
    совпадало с расчётом командного бонуса.
    """
    from app.services.salary import (
        _get_team_contribution,
        _get_team_members_user_ids,
    )

    member_ids = await _get_team_members_user_ids(session, team_target, year, month)
    if not member_ids:
        return Decimal("0")
    # target_currency обязателен (добавлен в P1: мультивалютная конвертация
    # вкладов в валюту цели команды). Берём ту же валюту, что и расчёт командного
    # бонуса в compute_motivational_card (team_target.target_currency или RUB),
    # чтобы team_fact и completion_pct считались в одной валюте.
    target_currency = team_target.target_currency or "RUB"
    contributions = await _get_team_contribution(
        session, member_ids, year, month, None, target_currency
    )
    return sum(contributions.values(), Decimal("0"))


async def list_team_member_ids(
    session: AsyncSession,
    user: User,
) -> list[int]:
    """Определить «команду» менеджера для сравнения (team_avg_pct/rank).

    Определение команды (DOCUMENTED):
      Команда = все активные менеджеры (role='manager') того же отдела
      (User.department_id == user.department_id). Сам пользователь включён.
      Если у пользователя нет department_id — команда = только он сам
      (сравнивать не с кем).

    Выбран отдел (а не manager_id-иерархия), т.к. это совпадает с тем, как
    строятся командные планы (TeamTarget привязан к отделу) и scope-фильтры
    (access_control.py, scope='department_and_children').
    """
    if user.department_id is None:
        return [user.id]
    rows = await session.execute(
        select(User.id).where(
            User.department_id == user.department_id,
            User.role == UserRole.manager,
            User.is_active == True,  # noqa: E712
        )
    )
    ids = [r for (r,) in rows.all()]
    if user.id not in ids:
        ids.append(user.id)
    return ids
