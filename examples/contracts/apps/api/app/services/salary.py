"""Epic 10.5 — Salary / Motivational Card computation service.

Реализует алгоритм расчёта мотивационной карты из МК Илья Рогов PDF:
1. Base salary — фикс оклад из SalaryPlan.
2. Commission — % от eligible платежей за период (personal deals, first payment, signed contract).
3. Team bonus — командный бонус при выполнении плана команды >= 80%:
   - Часть 1 (60% пула): пропорционально вкладу менеджера в team_fact
   - Часть 2 (40% пула): поровну между всеми менеджерами в плане

Pure-функции (compute_commission, compute_team_bonus_shares, etc.) тестируются
напрямую без БД. Async-функции используют Session для данных.
"""
from __future__ import annotations

import logging
from datetime import UTC, date, datetime
from decimal import ROUND_HALF_UP, Decimal
from typing import Any

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.models import (
    Activity,
    CommissionRule,
    Contract,
    ContractPayment,
    MotivationalCard,
    SalaryPlan,
    TeamTarget,
    User,
)
from app.services.currency import (
    convert_amount,
    get_rate,
    get_rates_snapshot,
)

logger = logging.getLogger(__name__)

_QUANTIZE_2 = Decimal("0.01")


# ============ Pure helpers ============

def compute_commission(
    eligible_payment_amounts: list[Decimal],
    rate_pct: Decimal,
) -> Decimal:
    """Рассчитать комиссию: сумма платежей × ставка.

    Pure-функция. Платежи уже в базовой валюте правила (конвертированы caller'ом).

    Args:
        eligible_payment_amounts: список сумм платежей в базовой валюте.
        rate_pct: ставка комиссии в процентах (10.00 = 10%).

    Returns:
        Итоговая комиссия с 2 знаками.
    """
    total = sum(eligible_payment_amounts, Decimal("0"))
    return (total * rate_pct / Decimal("100")).quantize(
        _QUANTIZE_2, rounding=ROUND_HALF_UP
    )


def compute_team_bonus_pool(
    base_pool: Decimal,
    n_members: int,
    per_additional_member: Decimal,
    min_members: int = 2,
) -> Decimal:
    """Рассчитать размер командного пула с учётом числа менеджеров.

    Алгоритм: base_pool + max(0, n_members - min_members) * per_additional_member.
    """
    extra = max(0, n_members - min_members)
    return (base_pool + extra * per_additional_member).quantize(
        _QUANTIZE_2, rounding=ROUND_HALF_UP
    )


def compute_team_bonus_shares(
    pool: Decimal,
    proportional_pct: Decimal,
    equal_pct: Decimal,
    n_members: int,
    user_contribution: Decimal,
    team_total_contribution: Decimal,
) -> tuple[Decimal, Decimal]:
    """Рассчитать долю конкретного менеджера в командном бонусе.

    Pure-функция. Возвращает (proportional_share, equal_share).

    Args:
        pool: итоговый размер пула после корректировки на n_members.
        proportional_pct: % пула распределяемый пропорционально (60.00).
        equal_pct: % пула распределяемый поровну (40.00).
        n_members: число участников.
        user_contribution: вклад этого менеджера в сумму поступлений.
        team_total_contribution: общий вклад всей команды.
    """
    if n_members == 0:
        return Decimal("0"), Decimal("0")

    proportional_pool = pool * proportional_pct / Decimal("100")
    equal_pool = pool * equal_pct / Decimal("100")

    # Пропорциональная часть
    if team_total_contribution == Decimal("0"):
        proportional_share = Decimal("0")
    else:
        user_share_ratio = user_contribution / team_total_contribution
        proportional_share = (proportional_pool * user_share_ratio).quantize(
            _QUANTIZE_2, rounding=ROUND_HALF_UP
        )

    # Равная часть
    equal_share = (equal_pool / n_members).quantize(
        _QUANTIZE_2, rounding=ROUND_HALF_UP
    )

    return proportional_share, equal_share


def is_ftm_valid(
    *,
    kind: str,
    is_first_time_meeting: bool,
    ftm_decision_maker_attended: bool,
    ftm_presentation_shown: bool,
    ftm_report_url: str | None,
) -> bool:
    """Проверить, засчитывается ли активность как FTM.

    Все 5 условий должны быть выполнены:
    1. kind='meeting'
    2. is_first_time_meeting=True
    3. ftm_decision_maker_attended=True
    4. ftm_presentation_shown=True
    5. ftm_report_url IS NOT NULL и не пустой

    Pure-функция.
    """
    return (
        kind == "meeting"
        and is_first_time_meeting
        and ftm_decision_maker_attended
        and ftm_presentation_shown
        and bool(ftm_report_url)
    )


# ============ DB helpers ============

async def _get_salary_plan(
    session: AsyncSession, user_id: int, year: int, month: int
) -> SalaryPlan | None:
    return (
        await session.execute(
            select(SalaryPlan)
            .options(
                selectinload(SalaryPlan.commission_rule),
                selectinload(SalaryPlan.team_target),
                selectinload(SalaryPlan.user),
                selectinload(SalaryPlan.supervisor),
            )
            .where(
                SalaryPlan.user_id == user_id,
                SalaryPlan.period_year == year,
                SalaryPlan.period_month == month,
            )
        )
    ).scalar_one_or_none()


async def _get_eligible_payments(
    session: AsyncSession,
    user_id: int,
    year: int,
    month: int,
    rule: CommissionRule,
) -> list[ContractPayment]:
    """Получить платежи, подходящие под правило комиссии, за период."""
    from calendar import monthrange
    _, last_day = monthrange(year, month)
    period_start = date(year, month, 1)
    period_end = date(year, month, last_day)

    q = (
        select(ContractPayment)
        # Perf: eager-load counterparty — без этого breakdown в
        # compute_motivational_card дёргает lazy ContractPayment.counterparty
        # отдельным SELECT на КАЖДЫЙ платёж (N+1). selectinload забирает все
        # counterparty одним батч-запросом.
        .options(selectinload(ContractPayment.counterparty))
        .where(
            ContractPayment.attributed_to_user_id == user_id,
            ContractPayment.payment_date >= period_start,
            ContractPayment.payment_date <= period_end,
        )
    )

    if rule.applies_to_first_payment_only:
        q = q.where(
            ContractPayment.is_first_payment_from_counterparty == True  # noqa: E712
        )

    if rule.requires_signed_contract:
        # Платёж должен быть привязан к подписанному договору
        q = (
            q.join(Contract, ContractPayment.contract_id == Contract.id)
            .where(Contract.signed_at.is_not(None))
        )

    return list((await session.execute(q)).scalars().all())


async def _count_ftm(
    session: AsyncSession, user_id: int, year: int, month: int
) -> int:
    """Считать засчитанные FTM встречи менеджера за период."""
    from calendar import monthrange
    _, last_day = monthrange(year, month)
    period_start = datetime(year, month, 1, tzinfo=UTC)
    period_end = datetime(year, month, last_day, 23, 59, 59, tzinfo=UTC)

    result = await session.execute(
        select(func.count(Activity.id)).where(
            Activity.responsible_id == user_id,
            Activity.kind == "meeting",
            Activity.is_first_time_meeting == True,  # noqa: E712
            Activity.ftm_decision_maker_attended == True,  # noqa: E712
            Activity.ftm_presentation_shown == True,  # noqa: E712
            Activity.ftm_report_url.is_not(None),
            Activity.created_at >= period_start,
            Activity.created_at <= period_end,
        )
    )
    return result.scalar_one() or 0


async def _get_team_members_user_ids(
    session: AsyncSession, team_target: TeamTarget, year: int, month: int
) -> list[int]:
    """Получить user_id всех менеджеров, у которых есть SalaryPlan с этим team_target."""
    rows = await session.execute(
        select(SalaryPlan.user_id).where(
            SalaryPlan.team_target_id == team_target.id,
            SalaryPlan.period_year == year,
            SalaryPlan.period_month == month,
        )
    )
    return [r for (r,) in rows.all()]


async def _get_team_contribution(
    session: AsyncSession,
    member_user_ids: list[int],
    year: int,
    month: int,
    rule: CommissionRule | None,
    target_currency: str,
) -> dict[int, Decimal]:
    """Суммировать вклад каждого менеджера команды в поступления за период.

    Вклад = сумма eligible платежей (attributed_to_user_id, first_payment,
    signed_contract) за период, КОНВЕРТИРОВАННЫХ в `target_currency` команды.

    B5 (HIGH money-fix): раньше суммировали `p.amount` как есть — смешивая KZT/
    RUB/USD как равные. Это искажало и completion_pct (team_total против
    target_amount в target_currency), и пропорциональные доли бонуса (вклад
    менеджера с USD-платежами раздувался против KZT). Теперь конвертируем КАЖДЫЙ
    платёж в target_currency по курсу на дату платежа (тот же per-payment паттерн,
    что в personal-commission loop) ПЕРЕД суммированием.
    """
    if not member_user_ids:
        return {}

    from calendar import monthrange
    _, last_day = monthrange(year, month)
    period_start = date(year, month, 1)
    period_end = date(year, month, last_day)

    contributions: dict[int, Decimal] = {uid: Decimal("0") for uid in member_user_ids}

    # Perf (N+1 fix): раньше на КАЖДОГО члена команды делался отдельный SELECT
    # платежей (team_size запросов). Теперь забираем платежи ВСЕХ членов одним
    # запросом через .in_(member_user_ids), затем группируем/конвертируем в Python.
    # Семантика P1 (мультивалютная конвертация per-payment по курсу на дату
    # платежа) сохранена 1:1 — только устранены повторные SELECT'ы.
    q = select(ContractPayment).where(
        ContractPayment.attributed_to_user_id.in_(member_user_ids),
        ContractPayment.payment_date >= period_start,
        ContractPayment.payment_date <= period_end,
        ContractPayment.is_first_payment_from_counterparty == True,  # noqa: E712
    )
    if rule and rule.requires_signed_contract:
        q = (
            q.join(Contract, ContractPayment.contract_id == Contract.id)
            .where(Contract.signed_at.is_not(None))
        )
    payments = list((await session.execute(q)).scalars().all())

    # FX rate cache: get_rate дёргает БД на каждый (currency, date) — а в команде
    # много платежей одной валюты/даты. Кешируем по ключу (currency, payment_date),
    # чтобы каждый уникальный курс брался из БД ровно один раз (не per-payment).
    rate_cache: dict[tuple[str, date], Decimal] = {}
    for p in payments:
        key = (p.currency, p.payment_date)
        rate = rate_cache.get(key)
        if rate is None:
            rate = await get_rate(session, p.currency, target_currency, p.payment_date)
            rate_cache[key] = rate
        contributions[p.attributed_to_user_id] += convert_amount(
            p.amount, p.currency, target_currency, rate
        )

    return contributions


# ============ Main compute ============

async def compute_motivational_card(
    session: AsyncSession,
    user_id: int,
    year: int,
    month: int,
) -> MotivationalCard:
    """Рассчитать или пересчитать МК за период.

    Создаёт или обновляет запись MotivationalCard. Статус остаётся 'draft'
    (финализацию делает отдельный endpoint /finalize). При финализации —
    фиксирует курсы и snapshot плана.

    Алгоритм из МК PDF:
    1. Base salary из plan.
    2. Commission = Σ eligible_payments × rate_pct / 100.
    3. Team bonus при completion >= 80%:
       a. Pool = base_pool + (n-2)*per_additional
       b. Proportional share = pool*60% * (user_contribution/team_total)
       c. Equal share = pool*40% / n_members
    4. Конвертация всего в salary_currency пользователя.
    """
    # 1. Получить план
    plan = await _get_salary_plan(session, user_id, year, month)
    if plan is None:
        raise ValueError(
            f"SalaryPlan not found for user_id={user_id} year={year} month={month}"
        )

    user = plan.user
    local_currency = user.salary_currency or "RUB"
    rates_date = date(year, month, 1)  # курс на 1-е число месяца

    # ---- Base salary ----
    base_salary = plan.base_salary_amount
    base_salary_local = await _convert(
        session, base_salary, plan.base_salary_currency, local_currency, rates_date
    )

    # ---- Commission ----
    commission_fact = Decimal("0")
    commission_local = Decimal("0")
    commission_currency = plan.base_salary_currency
    breakdown: list[dict[str, Any]] = []

    rule = plan.commission_rule
    if rule and rule.is_active:
        commission_currency = rule.base_currency
        eligible = await _get_eligible_payments(session, user_id, year, month, rule)

        # Perf: курс base_currency→local_currency на rates_date НЕ зависит от
        # платежа (loop-invariant) — раньше он перезапрашивался из БД на каждой
        # итерации. Берём один раз перед циклом.
        rate_to_local = await get_rate(
            session, rule.base_currency, local_currency, rates_date
        )

        for pmt in eligible:
            # Конвертируем каждый платёж в base_currency правила
            rate = await get_rate(session, pmt.currency, rule.base_currency, pmt.payment_date)
            amount_in_base = convert_amount(pmt.amount, pmt.currency, rule.base_currency, rate)
            # Комиссия по этому платежу
            commission_for_payment = (
                amount_in_base * rule.rate_pct / Decimal("100")
            ).quantize(_QUANTIZE_2, rounding=ROUND_HALF_UP)
            commission_fact += amount_in_base

            # Конвертируем в локальную валюту менеджера (курс предрассчитан выше)
            commission_local_for_payment = convert_amount(
                commission_for_payment, rule.base_currency, local_currency, rate_to_local
            )
            commission_local += commission_local_for_payment

            # Counterparty name для breakdown
            cp_name = None
            if pmt.counterparty:
                cp_name = pmt.counterparty.name

            breakdown.append({
                "payment_id": pmt.id,
                "contract_id": pmt.contract_id,
                "counterparty_name": cp_name,
                "payment_amount": float(pmt.amount),
                "payment_currency": pmt.currency,
                "amount_in_base": float(amount_in_base),
                "commission_base": float(commission_for_payment),
                "commission_local": float(commission_local_for_payment),
                "payment_date": pmt.payment_date.isoformat(),
            })

        # Итоговая комиссия = compute_commission над уже накопленной базой
        # (commission_fact — сумма в base_currency, commission_local — уже в local)
        commission_total = compute_commission(
            [Decimal(str(b["amount_in_base"])) for b in breakdown],
            rule.rate_pct,
        )
        commission_fact = commission_total

    # ---- FTM count ----
    ftm_count = await _count_ftm(session, user_id, year, month)

    # ---- Team bonus ----
    bonus_proportional = Decimal("0")
    bonus_equal = Decimal("0")
    team_target = plan.team_target

    if team_target and team_target.is_active and team_target.bonus_pool_amount:
        member_ids = await _get_team_members_user_ids(session, team_target, year, month)
        n_members = len(member_ids)

        if n_members > 0:
            # Суммарное поступление команды, конвертированное в валюту цели
            # команды (target_currency) — completion_pct и пропорциональные доли
            # сравниваются с target_amount в одной валюте (B5 fix).
            target_currency = team_target.target_currency or "RUB"
            contributions = await _get_team_contribution(
                session, member_ids, year, month, rule, target_currency
            )
            team_total = sum(contributions.values(), Decimal("0"))
            team_target_amount = team_target.target_amount
            completion_pct = (
                (team_total / team_target_amount * 100)
                if team_target_amount > 0
                else Decimal("0")
            )
            threshold = team_target.bonus_min_completion_pct

            if completion_pct >= threshold:
                per_add = team_target.bonus_per_additional_member or Decimal("100000")
                pool = compute_team_bonus_pool(
                    team_target.bonus_pool_amount,
                    n_members,
                    per_add,
                )
                user_contrib = contributions.get(user_id, Decimal("0"))

                prop_share, eq_share = compute_team_bonus_shares(
                    pool=pool,
                    proportional_pct=team_target.bonus_split_proportional_pct,
                    equal_pct=team_target.bonus_split_equal_pct,
                    n_members=n_members,
                    user_contribution=user_contrib,
                    team_total_contribution=team_total,
                )

                pool_currency = team_target.bonus_pool_currency or "KZT"
                rate_prop = await get_rate(
                    session, pool_currency, local_currency, rates_date
                )
                bonus_proportional = convert_amount(
                    prop_share, pool_currency, local_currency, rate_prop
                )
                bonus_equal = convert_amount(
                    eq_share, pool_currency, local_currency, rate_prop
                )

    # ---- Total ----
    total_local = (
        base_salary_local + commission_local + bonus_proportional + bonus_equal
    ).quantize(_QUANTIZE_2, rounding=ROUND_HALF_UP)

    # ---- Exchange rates snapshot ----
    currencies_used = list({
        plan.base_salary_currency,
        commission_currency,
        team_target.bonus_pool_currency if team_target and team_target.bonus_pool_currency else "RUB",
    })
    rates_snapshot = await get_rates_snapshot(
        session, currencies_used, local_currency, rates_date
    )

    # ---- Snapshot плана ----
    plan_snapshot = {
        "salary_plan_id": plan.id,
        "base_salary_amount": float(plan.base_salary_amount),
        "base_salary_currency": plan.base_salary_currency,
        "commission_rule_id": plan.commission_rule_id,
        "commission_rate_pct": float(rule.rate_pct) if rule else None,
        "personal_income_plan_amount": float(plan.personal_income_plan_amount) if plan.personal_income_plan_amount else None,
        "personal_income_plan_currency": plan.personal_income_plan_currency,
        "personal_ftm_plan": plan.personal_ftm_plan,
        "team_target_id": plan.team_target_id,
    }

    # ---- Upsert MK ----
    existing = (
        await session.execute(
            select(MotivationalCard).where(
                MotivationalCard.user_id == user_id,
                MotivationalCard.period_year == year,
                MotivationalCard.period_month == month,
            )
        )
    ).scalar_one_or_none()

    if existing and existing.status == "finalized":
        # Финализированную МК не пересчитываем
        return existing

    card_data = {
        "user_id": user_id,
        "period_year": year,
        "period_month": month,
        "plan_snapshot_json": plan_snapshot,
        "fact_base_salary_amount": base_salary,
        "fact_commission_amount": commission_fact,
        "fact_commission_currency": commission_currency,
        "fact_commission_breakdown": breakdown if breakdown else None,
        "fact_team_bonus_proportional_amount": bonus_proportional,
        "fact_team_bonus_equal_amount": bonus_equal,
        "total_amount_local": total_local,
        "total_amount_currency_local": local_currency,
        "exchange_rates_snapshot": {k: float(v) for k, v in rates_snapshot.items()},
        "exchange_rates_date": rates_date,
        "ftm_count_fact": ftm_count,
        "new_income_amount_fact": commission_fact,
        "new_income_currency_fact": commission_currency,
        "status": "draft",
    }

    if existing is None:
        card = MotivationalCard(**card_data)
        session.add(card)
    else:
        for k, v in card_data.items():
            setattr(existing, k, v)
        existing.updated_at = datetime.now(tz=UTC)
        card = existing

    await session.flush()
    return card


async def finalize_motivational_card(
    session: AsyncSession,
    user_id: int,
    year: int,
    month: int,
    finalized_by_user_id: int,
) -> MotivationalCard:
    """Финализировать МК: пересчитать, зафиксировать курсы, пометить finalized."""
    # Пересчитываем
    card = await compute_motivational_card(session, user_id, year, month)
    if card.status == "finalized":
        raise ValueError("MotivationalCard already finalized")

    card.status = "finalized"
    card.finalized_at = datetime.now(tz=UTC)
    card.finalized_by_user_id = finalized_by_user_id
    await session.flush()
    return card


async def _convert(
    session: AsyncSession,
    amount: Decimal,
    from_cur: str,
    to_cur: str,
    on_date: date,
) -> Decimal:
    """Конвертировать с DB lookup. Вспомогательный метод."""
    if from_cur == to_cur:
        return amount.quantize(_QUANTIZE_2, rounding=ROUND_HALF_UP)
    rate = await get_rate(session, from_cur, to_cur, on_date)
    return convert_amount(amount, from_cur, to_cur, rate)
