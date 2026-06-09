"""Признание выручки помесячно (revenue recognition, MRR) — Ф4 (G §2 реш.5).

Выручка по активной подписке признаётся ПО НАЧИСЛЕНИЮ, помесячно, НЕЗАВИСИМО от оплаты:
  • строка плана `fin_revenue_schedule` (subscription, year, month) — идемпотентна по
    recognition_key = "{subscription_id}-{YYYY}-{MM}";
  • признание постит accrual-проводку (Дт AR / Кт выручка(4010 MRR) / Кт НДС output) →
    переводит строку в 'recognized'.

ДВЕ ОСИ из одной модели: ДДС смотрит cash-строки (money_account≠NULL), P&L — accrual
(income/expense). Признание и оплата физически разные проводки → двойного учёта нет;
оплата гасит AR (матчинг план↔факт через AR-сальдо, не через хрупкое сопоставление).

ИНВАРИАНТЫ Ф4:
  • период-lock: нельзя признать в ЗАКРЫТЫЙ период (assert_period_open на дату признания
    = последний день периода). H6: откат признанного в закрытом периоде — НЕ стирается,
    а сторнируется reversal-проводкой в ТЕКУЩЕМ открытом периоде (reverse_recognition).
  • идемпотентность ВПЕРЁД: recognition_key UNIQUE + partial-unique JE на (source,
    source_ref_id, kind) — повторный прогон не задваивает.

MRR-сумма: ClientSubscription.fee_actual — это УЖЕ помесячный абонентский платёж (не
итог за период), поэтому признание за месяц = fee_actual (не «сумма/N»). НДС считается
по ставке юрлица при vat_recognition='by_shipment' (начисление); by_payment — НДС в
момент оплаты, тут vat=0 (зеркалит логику инвойса).

Async-оркестраторы не покрыты pure-тестами (нужна БД); вся арифметика/проводка — в
чистых helpers ниже и в posting.build_revenue_recognition (pure).
"""

from __future__ import annotations

import calendar
from dataclasses import dataclass
from datetime import UTC, date, datetime
from decimal import ROUND_HALF_UP, Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import (
    ClientSubscription,
    FinJournalEntry,
    FinLegalEntity,
    FinRevenueSchedule,
)
from app.services.finance import period_lock
from app.services.finance.posting import build_revenue_recognition
from app.services.finance.posting_db import (
    _base_currency,
    _code_to_id,
    _dict_resolver,
    _persist_entry,
    _prefetch_rates,
    _resolve_account,
    reverse_entry,
)
from app.services.finance.seed_data import (
    AR_ACCOUNT_CODE,
    VAT_OUTPUT_ACCOUNT_CODE,
)

DEFAULT_MRR_REVENUE_CODE = "4010"  # Выручка от подписок (MRR)
SOURCE_RECOGNITION = "subscription_recognition"

#: Неймспейс advisory-ключа для single-flight прогона признания (scale=2). Тот же
#: неймспейс 92_xxx, что и у base_currency (92_001) — runtime-локи, отдельно от
#: миграционных 91_xxx. Второй int4 — period_lock_subkey(legal_entity, year, month),
#: чтобы прогоны РАЗНЫХ юрлиц/периодов не блокировали друг друга, а параллельные
#: прогоны ОДНОГО (legal_entity, year, month) сериализовались (защита от гонки
#: recognition_key UNIQUE на scale=2).
RECOGNITION_ADVISORY_NS = 92_002


class RecognitionError(Exception):
    """Ошибка признания выручки (→ 422)."""


# ───────────────────────────── pure helpers ─────────────────────────────


def period_lock_subkey(legal_entity_id: int, year: int, month: int) -> int:
    """Детерминированный int4-subkey для advisory-lock по (юрлицо, год, месяц).

    Pure. Складывает три компоненты в один знаковый int4 (диапазон PG-int4
    -2_147_483_648..2_147_483_647). Коллизии маловероятны и безвредны (в худшем
    случае два несвязанных периода сериализуются — корректность не страдает, лишь
    лёгкая лишняя сериализация). year*12+month уникален по месяцам, *10_000 даёт
    место под id юрлица.
    """
    raw = ((year * 12 + month) * 100_000 + (legal_entity_id % 100_000)) & 0x7FFFFFFF
    # Привести к знаковому int4-диапазону (pg_advisory_xact_lock(int4, int4)).
    return raw - 0x80000000 if raw >= 0x40000000 else raw


def _q2(value: Decimal) -> Decimal:
    return value.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def recognition_key(subscription_id: int, year: int, month: int) -> str:
    """Ключ идемпотентности признания: "{sub}-{YYYY}-{MM}". Pure."""
    return f"{subscription_id}-{year:04d}-{month:02d}"


def period_last_day(year: int, month: int) -> date:
    """Последний день периода — дата проводки признания (конец месяца). Pure."""
    return date(year, month, calendar.monthrange(year, month)[1])


def split_net_vat(
    gross_or_net: Decimal,
    *,
    vat_rate_pct: Decimal,
    vat_recognition: str,
    fee_is_gross: bool = False,
) -> tuple[Decimal, Decimal]:
    """Разложить помесячную сумму подписки на (net, vat). Pure.

    fee_actual трактуется как НЕТТО (без НДС) по умолчанию (fee_is_gross=False) — в реестре
    подписок абонентка хранится без НДС. При by_payment НДС не начисляется в момент
    признания (vat=0; output-НДС возникнет при оплате — зеркалит инвойс by_payment).

      net = fee (если fee_is_gross=False)
      vat = round(net * rate/100, HALF_UP)   при by_shipment и rate>0, иначе 0
    """
    amount = _q2(gross_or_net)
    if vat_recognition != "by_shipment" or vat_rate_pct <= Decimal("0"):
        # by_payment / нулевая ставка — НДС в момент признания НЕ начисляется.
        net = amount if not fee_is_gross else _q2(amount)
        return net, Decimal("0.00")
    if fee_is_gross:
        # gross → net = gross / (1 + rate); vat = gross − net
        net = _q2(amount / (Decimal("1") + vat_rate_pct / Decimal("100")))
        return net, _q2(amount - net)
    net = amount
    vat = _q2(net * vat_rate_pct / Decimal("100"))
    return net, vat


def subscription_mrr(sub: ClientSubscription) -> tuple[Decimal, str] | None:
    """Помесячная MRR-сумма+валюта подписки (fee_actual). Pure.

    None → подписка без суммы/валюты или неактивна как источник выручки (E/H12:
    NULL fee_actual пропускается, не падает). fee_actual — уже помесячный платёж.
    """
    if not sub.is_active:
        return None
    fee = sub.fee_actual
    ccy = sub.fee_currency
    if fee is None or ccy is None or fee <= Decimal("0"):
        return None
    return _q2(fee), ccy


# ───────────────────────────── async lookups ─────────────────────────────


async def _vat_rate_pct(session: AsyncSession, vat_rate_id: int | None) -> Decimal:
    if vat_rate_id is None:
        return Decimal("0")
    from app.models import FinVatRate

    pct = (
        await session.execute(
            select(FinVatRate.rate_pct).where(FinVatRate.id == vat_rate_id)
        )
    ).scalar_one_or_none()
    return pct if pct is not None else Decimal("0")


async def _existing_schedule(
    session: AsyncSession, sub_id: int, year: int, month: int
) -> FinRevenueSchedule | None:
    return (
        await session.execute(
            select(FinRevenueSchedule).where(
                FinRevenueSchedule.recognition_key
                == recognition_key(sub_id, year, month)
            )
        )
    ).scalar_one_or_none()


@dataclass
class ScheduleResult:
    schedule: FinRevenueSchedule | None
    created: bool
    skipped_reason: str | None = None


async def ensure_schedule(
    session: AsyncSession,
    sub: ClientSubscription,
    *,
    legal_entity: FinLegalEntity,
    year: int,
    month: int,
    vat_rate_id: int | None = None,
    created_by_user_id: int | None = None,
) -> ScheduleResult:
    """Создать (idempotent) строку плана признания за (подписка, year, month).

    Существует уже → вернуть её (created=False). Нет суммы → skipped. НДС считается по
    ставке (vat_rate_id) при by_shipment. Не постит — только план (status='scheduled').
    """
    existing = await _existing_schedule(session, sub.id, year, month)
    if existing is not None:
        return ScheduleResult(existing, created=False)

    mrr = subscription_mrr(sub)
    if mrr is None:
        return ScheduleResult(None, created=False, skipped_reason="no_fee")
    fee, ccy = mrr

    rate_pct = await _vat_rate_pct(session, vat_rate_id)
    net, vat = split_net_vat(
        fee, vat_rate_pct=rate_pct, vat_recognition=legal_entity.vat_recognition
    )

    sched = FinRevenueSchedule(
        subscription_id=sub.id,
        legal_entity_id=legal_entity.id,
        counterparty_company_id=sub.company_id,
        period_year=year,
        period_month=month,
        amount_net=net,
        vat_amount=vat,
        currency=ccy,
        revenue_account_code=DEFAULT_MRR_REVENUE_CODE,
        vat_rate_id=vat_rate_id,
        status="scheduled",
        recognition_key=recognition_key(sub.id, year, month),
        created_by_user_id=created_by_user_id,
    )
    session.add(sched)
    await session.flush()
    return ScheduleResult(sched, created=True)


async def recognize_schedule(
    session: AsyncSession,
    sched: FinRevenueSchedule,
    *,
    posted_by_user_id: int | None = None,
) -> FinJournalEntry:
    """Признать выручку по строке плана → accrual-проводка (Дт AR / Кт MRR / Кт НДС).

    Идемпотентность: уже 'recognized' → RecognitionError (повторно не постим; партиал-
    unique JE тоже отобьёт). Период-lock на дату признания (конец месяца). Нет
    контрагента → RecognitionError (AR требует контрагента).
    """
    if sched.status == "recognized":
        raise RecognitionError(
            f"Выручка за {sched.period_year}-{sched.period_month:02d} уже признана "
            f"(проводка #{sched.recognized_journal_entry_id})."
        )
    if sched.status == "reversed":
        raise RecognitionError("Признание сторнировано — повторное признание запрещено.")
    if sched.counterparty_company_id is None:
        raise RecognitionError(
            "У подписки нет компании-контрагента — дебиторку (AR) признать нельзя."
        )

    le = (
        await session.execute(
            select(FinLegalEntity).where(FinLegalEntity.id == sched.legal_entity_id)
        )
    ).scalar_one()
    func_ccy = le.functional_currency
    base_ccy = await _base_currency(session)

    on_date = period_last_day(sched.period_year, sched.period_month)
    await period_lock.assert_period_open(session, le.id, on_date)

    rates = await _prefetch_rates(session, {(sched.currency, func_ccy)}, on_date)
    resolver = _dict_resolver(rates)
    draft = build_revenue_recognition(
        on_date=on_date,
        func_currency=func_ccy,
        currency=sched.currency,
        amount_net=sched.amount_net,
        vat_amount=sched.vat_amount,
        revenue_account_code=sched.revenue_account_code or DEFAULT_MRR_REVENUE_CODE,
        ar_account_code=AR_ACCOUNT_CODE,
        vat_output_account_code=VAT_OUTPUT_ACCOUNT_CODE,
        counterparty_company_id=sched.counterparty_company_id,
        rate_resolver=resolver,
        subscription_id=sched.subscription_id,
        cashflow_category_id=sched.cashflow_category_id,
        vat_rate_id=sched.vat_rate_id,
        source=SOURCE_RECOGNITION,
        source_ref_id=sched.id,
        memo=f"Признание выручки за {sched.period_year}-{sched.period_month:02d}",
    )
    codes = {ln.account_code for ln in draft.lines}
    accounts = [await _resolve_account(session, c) for c in codes]
    entry = await _persist_entry(
        session,
        draft,
        legal_entity_id=le.id,
        base_currency=base_ccy,
        posted_by_user_id=posted_by_user_id,
        code_to_id=_code_to_id(accounts),
    )
    sched.status = "recognized"
    sched.recognized_journal_entry_id = entry.id
    sched.recognized_at = datetime.now(UTC)
    await session.flush()
    return entry


async def reverse_recognition(
    session: AsyncSession,
    sched: FinRevenueSchedule,
    *,
    on_date: date | None = None,
    posted_by_user_id: int | None = None,
) -> FinJournalEntry:
    """Откат признанной выручки (H6) — сторно в ТЕКУЩЕМ открытом периоде.

    Признанная выручка ЗАКРЫТОГО периода НЕ стирается (period-lock). Корректировка =
    reversal-проводка в открытом периоде (по умолчанию on_date = сегодня, вызывающий
    передаёт дату открытого периода). Это бухгалтерски верно: корректировка прошлого
    периода в текущем. status строки → 'reversed'.
    """
    if sched.status != "recognized" or sched.recognized_journal_entry_id is None:
        raise RecognitionError(
            "Сторнировать можно только признанную выручку (status='recognized')."
        )
    entry = (
        await session.execute(
            select(FinJournalEntry).where(
                FinJournalEntry.id == sched.recognized_journal_entry_id
            )
        )
    ).scalar_one()
    rev_date = on_date or datetime.now(UTC).date()
    rev_entry = await reverse_entry(
        session,
        entry,
        on_date=rev_date,
        posted_by_user_id=posted_by_user_id,
        memo=(
            f"Корректировка признания выручки за "
            f"{sched.period_year}-{sched.period_month:02d}"
        ),
    )
    sched.status = "reversed"
    await session.flush()
    return rev_entry


async def run_recognition_period(
    session: AsyncSession,
    *,
    legal_entity: FinLegalEntity,
    year: int,
    month: int,
    subscription_id: int | None = None,
    vat_rate_id: int | None = None,
    posted_by_user_id: int | None = None,
) -> dict[str, int]:
    """Прогон признания за период: ensure_schedule + recognize по активным подпискам.

    Идемпотентно: уже признанные — пропускаются (existing). Нет суммы/контрагента —
    skipped. Возвращает счётчики. Single-flight (advisory-lock) обеспечивает вызывающий
    cron/эндпоинт. Период-lock проверяется внутри recognize (бросит PeriodLocked).
    """
    q = select(ClientSubscription).where(ClientSubscription.is_active.is_(True))
    if subscription_id is not None:
        q = q.where(ClientSubscription.id == subscription_id)
    subs = (await session.execute(q)).scalars().all()

    counters = {"processed": 0, "scheduled": 0, "recognized": 0, "skipped": 0, "existing": 0}
    for sub in subs:
        counters["processed"] += 1
        sr = await ensure_schedule(
            session, sub,
            legal_entity=legal_entity, year=year, month=month,
            vat_rate_id=vat_rate_id, created_by_user_id=posted_by_user_id,
        )
        if sr.schedule is None:
            counters["skipped"] += 1
            continue
        if sr.created:
            counters["scheduled"] += 1
        if sr.schedule.status == "recognized":
            counters["existing"] += 1
            continue
        if sr.schedule.status in ("skipped", "reversed"):
            counters["skipped"] += 1
            continue
        await recognize_schedule(session, sr.schedule, posted_by_user_id=posted_by_user_id)
        counters["recognized"] += 1
    return counters
