"""Ф3 — интеграция факта оплаты в GL (write-through), БЕЗ ломки комиссий.

Канон: G §Ф3 / решение 7. Принципы:

  • mark-paid сделки → income `fin_operation` (source='deal', source_ref_id=deal_id),
    идемпотентно по UNIQUE(source, source_ref_id). Ошибочная пометка → reversal.
  • ContractPayment НЕ депрекейтим: при создании платежа создаём зеркальную income-
    операцию (source='contract_payment'). Комиссии ПРОДОЛЖАЮТ читать ContractPayment
    (services/salary.py) — write-through лишь дублирует факт в GL. mark-paid и
    contract_payment — РАЗНЫЕ source → не задваивают (но на одну сделку обычно один из
    путей; см. resolve helpers).
  • MotivationalCard.paid → расходная (payroll) операция (source='motivational_card').
    Дт 5110 ФОТ / 5120 комиссии, Кт деньги. Расчёт МК НЕ трогаем — только генерим
    операцию по факту paid.
  • Активные подписки → плановые (status='planned') income-операции в платёжный
    календарь. Идемпотентность — partial-unique (subscription_id, op_date).

Чистое ядро (pure) — выбор op_type, расчёт периода, проверки — тестируется без БД.
Async-оркестраторы создают draft-операцию и зовут posting_db.post_operation /
reverse_entry (для terminal-факта); планов не постим (planned остаётся документом).
"""

from __future__ import annotations

from datetime import UTC, date, datetime
from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import (
    ClientSubscription,
    Contract,
    ContractPayment,
    Deal,
    FinJournalEntry,
    FinLegalEntity,
    FinMoneyAccount,
    FinOperation,
    FinOpType,
    MotivationalCard,
)

from . import posting_db


class IntegrationError(Exception):
    """Ошибка Ф3-интеграции (→ 422 в роутере, если не PostingError/FxRateMissing)."""


class AlreadyIntegrated(IntegrationError):
    """Источник уже имеет операцию (идемпотентный no-op, не ошибка по умолчанию)."""


class NoMoneyAccount(IntegrationError):
    """У юрлица нет денежного счёта для дефолтной income/payroll-операции."""


class NoLegalEntity(IntegrationError):
    """Нет активного юрлица для интеграции факта оплаты."""


class MissingOpType(IntegrationError):
    """В сиде нет нужного op_type (income_deal / payroll_* / subscription_planned)."""


# ── коды op_type (seed_data.OP_TYPES, Ф3) ──
OP_TYPE_INCOME_DEAL = "income_deal"
OP_TYPE_PAYROLL_SALARY = "payroll_salary"
OP_TYPE_PAYROLL_COMMISSION = "payroll_commission"
OP_TYPE_SUBSCRIPTION_PLANNED = "subscription_planned"

# ── source-маркеры (write-through идемпотентность) ──
SOURCE_DEAL = "deal"
SOURCE_CONTRACT_PAYMENT = "contract_payment"
SOURCE_MOTIVATIONAL_CARD = "motivational_card"
SOURCE_SUBSCRIPTION = "subscription"


# ───────────────────────────── pure helpers ─────────────────────────────


def period_first_day(year: int, month: int) -> date:
    """Первый день периода — ключ идемпотентности планового поступления подписки. Pure."""
    return date(year, month, 1)


def payroll_op_type_code(*, commission: bool) -> str:
    """Выбор op_type расходной выплаты: комиссия → 5120, иначе ФОТ-зарплата 5110. Pure."""
    return OP_TYPE_PAYROLL_COMMISSION if commission else OP_TYPE_PAYROLL_SALARY


def motivational_payout_amount(card: MotivationalCard) -> tuple[Decimal, str] | None:
    """Сумма+валюта выплаты по МК (итог в локальной валюте менеджера). Pure.

    None → нечего выплачивать (нет итоговой суммы / 0). Используем total_amount_local
    как единый payout (оклад+комиссия+бонусы), валюта total_amount_currency_local.
    """
    amount = card.total_amount_local
    ccy = card.total_amount_currency_local
    if amount is None or ccy is None or amount <= Decimal("0"):
        return None
    return amount, ccy


def card_is_commission_dominated(card: MotivationalCard) -> bool:
    """Класс выплаты: True если комиссия — основная компонента (→ 5120), иначе ФОТ (5110).

    Pure. Если комиссия > оклада — относим всю выплату к комиссионному счёту (упрощённо;
    раздельный разнос оклад/комиссия на разные GL — отдельный under-чанк Ф3.1).
    """
    commission = card.fact_commission_amount or Decimal("0")
    base = card.fact_base_salary_amount or Decimal("0")
    return commission > base


def deal_payment_amount(deal: Deal) -> tuple[Decimal, str] | None:
    """Сумма+валюта поступления по сделке (Deal.amount/currency). Pure.

    None → у сделки нет суммы/валюты (нельзя создать income-операцию по факту).
    """
    if deal.amount is None or deal.currency is None or deal.amount <= Decimal("0"):
        return None
    return deal.amount, deal.currency


# ───────────────────────────── async lookups ─────────────────────────────


async def _op_type_by_code(session: AsyncSession, code: str) -> FinOpType:
    ot = (
        await session.execute(select(FinOpType).where(FinOpType.code == code))
    ).scalar_one_or_none()
    if ot is None:
        raise MissingOpType(
            f"Тип операции {code!r} не найден в сиде — Ф3-интеграция невозможна "
            "(требуется миграция доввода op_types)."
        )
    return ot


async def _default_legal_entity(session: AsyncSession) -> FinLegalEntity:
    """Дефолтное активное юрлицо (минимальный sort_order, затем id).

    Сделка/договор не несут legal_entity_id → факт оплаты учитывается в дефолтном
    юрлице группы. Мультиюрлицовая привязка по контрагенту — under-чанк Ф3.1.
    """
    le = (
        await session.execute(
            select(FinLegalEntity)
            .where(FinLegalEntity.is_active.is_(True))
            .order_by(FinLegalEntity.sort_order, FinLegalEntity.id)
            .limit(1)
        )
    ).scalar_one_or_none()
    if le is None:
        raise NoLegalEntity("Нет активного юрлица (fin_legal_entity) для учёта факта оплаты.")
    return le


async def _default_money_account(
    session: AsyncSession, legal_entity_id: int, currency: str
) -> FinMoneyAccount:
    """Дефолтный денежный счёт юрлица: предпочтительно той же валюты, иначе любой активный."""
    base = (
        select(FinMoneyAccount)
        .where(
            FinMoneyAccount.legal_entity_id == legal_entity_id,
            FinMoneyAccount.is_active.is_(True),
        )
        .order_by(FinMoneyAccount.sort_order, FinMoneyAccount.id)
    )
    same_ccy = (
        await session.execute(base.where(FinMoneyAccount.currency == currency).limit(1))
    ).scalar_one_or_none()
    if same_ccy is not None:
        return same_ccy
    any_acc = (await session.execute(base.limit(1))).scalar_one_or_none()
    if any_acc is None:
        raise NoMoneyAccount(
            f"У юрлица id={legal_entity_id} нет денежного счёта — невозможно создать "
            "income/payroll-операцию факта оплаты."
        )
    return any_acc


async def _deal_has_contract_payment_op(
    session: AsyncSession, deal: Deal
) -> bool:
    """True если по сделке уже есть write-through операция из ContractPayment.

    Связь: contract_payment-операция несёт contract_id (а ContractPayment.contract_id =
    Deal.contract_id). Сверяем по contract_id сделки. Если у сделки нет contract_id —
    связать платёж со сделкой нельзя, считаем что дублей нет (mark-paid создаст op).
    """
    if deal.contract_id is None:
        return False
    found = (
        await session.execute(
            select(FinOperation.id)
            .where(
                FinOperation.source == SOURCE_CONTRACT_PAYMENT,
                FinOperation.contract_id == deal.contract_id,
                FinOperation.status != "reversed",
            )
            .limit(1)
        )
    ).first()
    return found is not None


async def _existing_op(
    session: AsyncSession, source: str, source_ref_id: int
) -> FinOperation | None:
    """Найти существующую операцию по (source, source_ref_id) — основа идемпотентности."""
    return (
        await session.execute(
            select(FinOperation).where(
                FinOperation.source == source,
                FinOperation.source_ref_id == source_ref_id,
            )
        )
    ).scalar_one_or_none()


# ───────────────────────────── income: deal mark-paid ─────────────────────────────


async def integrate_deal_paid(
    session: AsyncSession,
    deal: Deal,
    *,
    op_date: date | None = None,
    posted_by_user_id: int | None = None,
) -> FinOperation | None:
    """mark-paid сделки → проведённая income-операция (идемпотентно).

    Возвращает операцию (новую или ранее созданную). None — если у сделки нет суммы
    (нечего проводить; не ошибка — DEALS 2.0 не должен падать). Идемпотентность:
    UNIQUE(source='deal', source_ref_id=deal.id) — повторный вызов под scale=2 не
    задваивает (вернёт существующую). Сторнированная операция НЕ воссоздаётся.
    """
    existing = await _existing_op(session, SOURCE_DEAL, deal.id)
    if existing is not None:
        return existing

    # АНТИ-ДВОЙНОЙ-УЧЁТ: если по этой сделке уже есть write-through операции из
    # ContractPayment (source='contract_payment', привязка по contract_id или deal_id),
    # факт оплаты уже в GL — НЕ создаём вторую deal-операцию (иначе задвоение P&L).
    # В проде win-gate пишет ContractPayment → это основной путь; mark-paid тогда no-op.
    if await _deal_has_contract_payment_op(session, deal):
        return None

    amt = deal_payment_amount(deal)
    if amt is None:
        return None
    amount, currency = amt

    le = await _default_legal_entity(session)
    ot = await _op_type_by_code(session, OP_TYPE_INCOME_DEAL)
    money = await _default_money_account(session, le.id, currency)
    on_date = op_date or date.today()

    op = FinOperation(
        legal_entity_id=le.id,
        op_type_id=ot.id,
        direction="in",
        status="draft",
        op_date=on_date,
        amount=amount,
        currency=currency,
        account_to_id=money.id,
        cashflow_category_id=ot.default_cat_id,
        counterparty_company_id=deal.company_id,
        deal_id=deal.id,
        contract_id=deal.contract_id,
        purpose=f"Оплата по сделке #{deal.id}: {deal.title}",
        source=SOURCE_DEAL,
        source_ref_id=deal.id,
        created_by_user_id=posted_by_user_id,
    )
    session.add(op)
    await session.flush()
    await posting_db.post_operation(session, op, posted_by_user_id=posted_by_user_id)
    return op


async def reverse_deal_paid(
    session: AsyncSession,
    deal_id: int,
    *,
    posted_by_user_id: int | None = None,
) -> FinJournalEntry | None:
    """Снятие пометки оплаты сделки → сторно income-операции (если была проведена).

    None — если операции нет или она уже reversed (идемпотентно). Reversal — единственный
    способ «отменить» проведённый факт (инвариант №4).
    """
    op = await _existing_op(session, SOURCE_DEAL, deal_id)
    if op is None or op.journal_entry_id is None or op.status == "reversed":
        return None
    src_entry = (
        await session.execute(
            select(FinJournalEntry).where(FinJournalEntry.id == op.journal_entry_id)
        )
    ).scalar_one_or_none()
    if src_entry is None or src_entry.status != "posted":
        return None
    entry = await posting_db.reverse_entry(
        session, src_entry, posted_by_user_id=posted_by_user_id,
        memo=f"Сторно оплаты сделки #{deal_id}",
    )
    op.status = "reversed"
    await session.flush()
    return entry


# ───────────────────────────── income: ContractPayment write-through ─────────────────────────────


async def integrate_contract_payment(
    session: AsyncSession,
    payment: ContractPayment,
    *,
    posted_by_user_id: int | None = None,
) -> FinOperation | None:
    """ContractPayment → зеркальная income-операция (write-through, идемпотентно).

    КЛЮЧЕВОЕ: НЕ трогает сам ContractPayment и НЕ влияет на комиссии — комиссии
    продолжают читать ContractPayment (salary.py). Это лишь дублирование факта в GL.
    source='contract_payment' (≠ 'deal') → один платёж = одна операция; если по этой же
    сделке уже была deal-операция, это РАЗНЫЕ source и оба пути не складываются в P&L
    автоматически — поэтому в проде используется ОДИН путь (см. router-гейт).
    """
    existing = await _existing_op(session, SOURCE_CONTRACT_PAYMENT, payment.id)
    if existing is not None:
        return existing
    if payment.amount is None or payment.amount <= Decimal("0"):
        return None

    le = await _default_legal_entity(session)
    ot = await _op_type_by_code(session, OP_TYPE_INCOME_DEAL)
    money = await _default_money_account(session, le.id, payment.currency)

    contract_company_id = None
    if payment.contract_id is not None:
        contract = (
            await session.execute(
                select(Contract).where(Contract.id == payment.contract_id)
            )
        ).scalar_one_or_none()
        if contract is not None:
            contract_company_id = getattr(contract, "company_id", None)

    op = FinOperation(
        legal_entity_id=le.id,
        op_type_id=ot.id,
        direction="in",
        status="draft",
        op_date=payment.payment_date,
        amount=payment.amount,
        currency=payment.currency,
        account_to_id=money.id,
        cashflow_category_id=ot.default_cat_id,
        counterparty_company_id=contract_company_id,
        contract_id=payment.contract_id,
        purpose=f"Оплата по договору (ContractPayment #{payment.id})",
        source=SOURCE_CONTRACT_PAYMENT,
        source_ref_id=payment.id,
        created_by_user_id=posted_by_user_id,
    )
    session.add(op)
    await session.flush()
    await posting_db.post_operation(session, op, posted_by_user_id=posted_by_user_id)
    return op


async def resync_contract_payment(
    session: AsyncSession,
    payment: ContractPayment,
    *,
    posted_by_user_id: int | None = None,
) -> FinOperation | None:
    """Пере-синхронизация GL-зеркала после PATCH платежа (B3 WARN-4).

    Если зеркальной операции ещё нет — создаём (как при POST). Если есть и её сумма/
    дата/валюта/контракт совпадают с платежом — no-op (идемпотентно). Если расходятся —
    сторнируем старую проводку и перепроводим ТУ ЖЕ операцию (source, source_ref_id
    сохраняются → UNIQUE не нарушается) с новыми значениями. P&L снова сходится.

    КЛЮЧЕВОЕ: комиссии тут НЕ участвуют — они читают ContractPayment напрямую (salary.py);
    мы лишь чиним GL-зеркало дохода. Best-effort вызывается из роутера в отдельном commit.
    """
    op = await _existing_op(session, SOURCE_CONTRACT_PAYMENT, payment.id)
    if op is None:
        return await integrate_contract_payment(
            session, payment, posted_by_user_id=posted_by_user_id
        )
    if payment.amount is None or payment.amount <= Decimal("0"):
        # платёж обнулён/невалиден — сторнируем зеркало, новую не создаём
        await void_contract_payment(session, payment.id, posted_by_user_id=posted_by_user_id)
        return None

    contract_company_id = None
    if payment.contract_id is not None:
        contract = (
            await session.execute(
                select(Contract).where(Contract.id == payment.contract_id)
            )
        ).scalar_one_or_none()
        if contract is not None:
            contract_company_id = getattr(contract, "company_id", None)

    unchanged = (
        op.status == "posted"
        and op.amount == payment.amount
        and op.currency == payment.currency
        and op.op_date == payment.payment_date
        and op.contract_id == payment.contract_id
    )
    if unchanged:
        return op

    # сторнируем текущую проводку (если проведена), переводим операцию в draft и
    # перепроводим её с актуальными значениями платежа.
    if op.journal_entry_id is not None and op.status == "posted":
        src_entry = (
            await session.execute(
                select(FinJournalEntry).where(FinJournalEntry.id == op.journal_entry_id)
            )
        ).scalar_one_or_none()
        if src_entry is not None and src_entry.status == "posted":
            await posting_db.reverse_entry(
                session, src_entry, posted_by_user_id=posted_by_user_id,
                memo=f"Сторно зеркала ContractPayment #{payment.id} (правка)",
            )

    le = await _default_legal_entity(session)
    money = await _default_money_account(session, le.id, payment.currency)

    op.status = "draft"
    op.journal_entry_id = None
    op.posted_at = None
    op.legal_entity_id = le.id
    op.op_date = payment.payment_date
    op.amount = payment.amount
    op.currency = payment.currency
    op.account_to_id = money.id
    op.counterparty_company_id = contract_company_id
    op.contract_id = payment.contract_id
    op.purpose = f"Оплата по договору (ContractPayment #{payment.id})"
    await session.flush()
    await posting_db.post_operation(session, op, posted_by_user_id=posted_by_user_id)
    return op


async def void_contract_payment(
    session: AsyncSession,
    payment_id: int,
    *,
    posted_by_user_id: int | None = None,
) -> FinJournalEntry | None:
    """Сторнирование GL-зеркала при DELETE платежа (B3 WARN-4).

    Reversal — единственный способ «отменить» проведённый факт (инвариант №4): сама
    проводка остаётся, рождается зеркальная сторно-проводка, op → 'reversed'. P&L
    снимает удалённый доход. None — если зеркала нет / уже reversed (идемпотентно).
    Комиссии не затрагиваются (read ContractPayment).
    """
    op = await _existing_op(session, SOURCE_CONTRACT_PAYMENT, payment_id)
    if op is None or op.journal_entry_id is None or op.status == "reversed":
        return None
    src_entry = (
        await session.execute(
            select(FinJournalEntry).where(FinJournalEntry.id == op.journal_entry_id)
        )
    ).scalar_one_or_none()
    if src_entry is None or src_entry.status != "posted":
        return None
    entry = await posting_db.reverse_entry(
        session, src_entry, posted_by_user_id=posted_by_user_id,
        memo=f"Сторно зеркала ContractPayment #{payment_id} (удаление)",
    )
    op.status = "reversed"
    await session.flush()
    return entry


# ───────────────────────────── expense: MotivationalCard payroll ─────────────────────────────


async def integrate_motivational_card_paid(
    session: AsyncSession,
    card: MotivationalCard,
    *,
    posted_by_user_id: int | None = None,
) -> FinOperation | None:
    """MotivationalCard.paid → расходная (payroll) операция (идемпотентно).

    Дт 5110 ФОТ или 5120 комиссии (по доминирующей компоненте) / Кт деньги. Σ=0
    гарантирует build_cash_out. Расчёт МК НЕ трогаем. None — если выплачивать нечего.
    Идемпотентность: UNIQUE(source='motivational_card', source_ref_id=card.id).
    """
    existing = await _existing_op(session, SOURCE_MOTIVATIONAL_CARD, card.id)
    if existing is not None:
        return existing

    payout = motivational_payout_amount(card)
    if payout is None:
        return None
    amount, currency = payout

    le = await _default_legal_entity(session)
    code = payroll_op_type_code(commission=card_is_commission_dominated(card))
    ot = await _op_type_by_code(session, code)
    money = await _default_money_account(session, le.id, currency)
    on_date = (card.paid_at or datetime.now(UTC)).date()

    op = FinOperation(
        legal_entity_id=le.id,
        op_type_id=ot.id,
        direction="out",
        status="draft",
        op_date=on_date,
        amount=amount,
        currency=currency,
        account_from_id=money.id,
        cashflow_category_id=ot.default_cat_id,
        purpose=(
            f"Выплата по мотивационной карте #{card.id} "
            f"(user {card.user_id}, {card.period_year}-{card.period_month:02d})"
        ),
        source=SOURCE_MOTIVATIONAL_CARD,
        source_ref_id=card.id,
        created_by_user_id=posted_by_user_id,
    )
    session.add(op)
    await session.flush()
    await posting_db.post_operation(session, op, posted_by_user_id=posted_by_user_id)
    return op


# ───────────────────────────── plan: subscription planned income ─────────────────────────────


def subscription_planned_amount(sub: ClientSubscription) -> tuple[Decimal, str] | None:
    """Сумма+валюта ожидаемого поступления по подписке (fee_actual). Pure.

    None → нет fee_actual/валюты/неактивна (нечего планировать).
    """
    if not sub.is_active:
        return None
    if sub.fee_actual is None or sub.fee_currency is None or sub.fee_actual <= Decimal("0"):
        return None
    return sub.fee_actual, sub.fee_currency


async def integrate_subscription_planned(
    session: AsyncSession,
    sub: ClientSubscription,
    *,
    year: int,
    month: int,
    created_by_user_id: int | None = None,
) -> FinOperation | None:
    """Активная подписка → ПЛАНОВАЯ income-операция за период (status='planned', НЕ постим).

    Платёжный календарь по fee_actual. Идемпотентность — partial-unique
    (subscription_id, op_date) для планов (миграция 0098): повторный cron под scale=2 не
    задваивает. Матчинг план↔факт — через AR-сальдо (Ф4), не через хрупкое сопоставление
    плановых операций; здесь только «не задвоить план». Плановую операцию НЕ проводим —
    она остаётся документом-ожиданием (ДДС план/факт читает их в Ф4).
    """
    plan = subscription_planned_amount(sub)
    if plan is None:
        return None
    amount, currency = plan
    on_date = period_first_day(year, month)

    existing = (
        await session.execute(
            select(FinOperation).where(
                FinOperation.subscription_id == sub.id,
                FinOperation.source == SOURCE_SUBSCRIPTION,
                FinOperation.op_date == on_date,
                FinOperation.status == "planned",
            )
        )
    ).scalar_one_or_none()
    if existing is not None:
        return existing

    le = await _default_legal_entity(session)
    ot = await _op_type_by_code(session, OP_TYPE_SUBSCRIPTION_PLANNED)
    money = await _default_money_account(session, le.id, currency)

    op = FinOperation(
        legal_entity_id=le.id,
        op_type_id=ot.id,
        direction="in",
        status="planned",
        op_date=on_date,
        due_date=on_date,
        amount=amount,
        currency=currency,
        account_to_id=money.id,
        cashflow_category_id=ot.default_cat_id,
        counterparty_company_id=sub.company_id,
        subscription_id=sub.id,
        purpose=f"Плановое поступление по подписке #{sub.id} за {year}-{month:02d}",
        source=SOURCE_SUBSCRIPTION,
        source_ref_id=sub.id,
        created_by_user_id=created_by_user_id,
    )
    session.add(op)
    await session.flush()
    return op
