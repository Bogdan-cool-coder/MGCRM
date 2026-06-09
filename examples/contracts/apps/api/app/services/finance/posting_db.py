"""Persist-оркестрация постинга (async) — связывает чистое ядро posting.py с БД.

Поток постинга операции/журнала:
  1. assert_period_open(entity, date)            — инвариант №5
  2. resolve GL-счета (code→id) + флаги (requires_counterparty)
  3. предзагрузка строгих курсов (fx.get_rate_strict → FxRateMissing/422)
  4. build_* (чистый билдер) — Σ amount_func=0 (первая линия защиты, инвариант №2)
  5. persist EntryDraft → fin_journal_entry + fin_ledger_line[] (status='posted')
     (DB-триггер fin_assert_entry_balanced — вторая линия защиты, на COMMIT)
  6. проставить operation/manual_journal статусы + journal_entry_id + posted_at

Async-функции НЕ покрываются pure-тестами (нужна БД); вся арифметика/Дт-Кт/баланс
проверены в pure-тестах на posting.py. Здесь — только связывание и I/O.
"""

from __future__ import annotations

from datetime import UTC, date, datetime
from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import (
    FinAccountGl,
    FinJournalEntry,
    FinLedgerLine,
    FinLegalEntity,
    FinManualJournal,
    FinMoneyAccount,
    FinOperation,
    FinOpType,
    FinRequest,
    FinSettings,
)
from app.services.finance import fx, period_lock
from app.services.finance.posting import (
    EntryDraft,
    ImmutablePosted,
    LineDraft,
    ManualLineInput,
    PostingError,
    assert_manual_journal_mutable,
    assert_operation_mutable,
    build_cash_in,
    build_cash_out,
    build_manual_journal,
    build_opening,
    build_reversal,
    build_transfer,
)
from app.services.finance.seed_data import (
    FX_GAIN_CODE,
    FX_LOSS_CODE,
    OPENING_OFFSET_CODE,
)

# ───────────────────────────── вспомогательные ─────────────────────────────


def _dict_resolver(rates: dict[tuple[str, str], Decimal]):
    """Синхронный резолвер курса для чистых билдеров поверх предзагруженного словаря."""

    def resolver(frm: str, to: str, on_date: date) -> Decimal:
        if frm == to:
            return Decimal("1")
        rate = rates.get((frm, to))
        if rate is None:
            raise fx.FxRateMissing(frm, to, on_date)
        return rate

    return resolver


async def _prefetch_rates(
    session: AsyncSession, pairs: set[tuple[str, str]], on_date: date
) -> dict[tuple[str, str], Decimal]:
    """Строго предзагрузить курсы для пар (from→to) на дату (FxRateMissing если нет)."""
    out: dict[tuple[str, str], Decimal] = {}
    for frm, to in pairs:
        if frm == to:
            continue
        out[(frm, to)] = await fx.get_rate_strict(session, frm, to, on_date)
    return out


async def _resolve_account(session: AsyncSession, code: str) -> FinAccountGl:
    acc = (
        await session.execute(select(FinAccountGl).where(FinAccountGl.code == code))
    ).scalar_one_or_none()
    if acc is None:
        raise PostingError(f"Счёт плана счетов {code} не найден")
    return acc


async def _money_account(session: AsyncSession, account_id: int) -> FinMoneyAccount:
    ma = (
        await session.execute(
            select(FinMoneyAccount).where(FinMoneyAccount.id == account_id)
        )
    ).scalar_one_or_none()
    if ma is None:
        raise PostingError(f"Денежный счёт id={account_id} не найден")
    return ma


async def _money_account_gl_code(session: AsyncSession, ma: FinMoneyAccount) -> str:
    acc = (
        await session.execute(
            select(FinAccountGl).where(FinAccountGl.id == ma.gl_account_id)
        )
    ).scalar_one()
    return acc.code


async def _base_currency(session: AsyncSession) -> str | None:
    settings = (await session.execute(select(FinSettings).limit(1))).scalar_one_or_none()
    return settings.base_currency if settings else None


async def _persist_entry(
    session: AsyncSession,
    draft: EntryDraft,
    *,
    legal_entity_id: int,
    base_currency: str | None,
    posted_by_user_id: int | None,
    code_to_id: dict[str, int],
) -> FinJournalEntry:
    """EntryDraft → fin_journal_entry + fin_ledger_line[] (status='posted').

    amount_in_base — проекция: fx.to_base (НЕ строго; fx_missing допустим).
    amount_func уже посчитан билдером строго.
    """
    entry = FinJournalEntry(
        legal_entity_id=legal_entity_id,
        date=draft.date,
        kind=draft.kind,
        status="posted",
        source=draft.source,
        source_ref_id=draft.source_ref_id,
        reverses_entry_id=draft.reverses_entry_id,
        func_currency=draft.func_currency,
        base_currency=base_currency,
        posted_at=datetime.now(UTC),
        posted_by_user_id=posted_by_user_id,
        memo=draft.memo,
    )
    session.add(entry)
    await session.flush()

    for ln in draft.lines:
        amount_in_base: Decimal | None = None
        base_missing = False
        if base_currency is not None:
            amount_in_base, _, base_missing = await fx.to_base(
                session, ln.amount, ln.currency, base_currency, draft.date
            )
        session.add(
            FinLedgerLine(
                journal_entry_id=entry.id,
                legal_entity_id=legal_entity_id,
                account_gl_id=code_to_id[ln.account_code],
                amount=ln.amount,
                currency=ln.currency,
                amount_func=ln.amount_func,
                amount_in_base=amount_in_base,
                base_currency=base_currency,
                fx_rate=ln.fx_rate,
                fx_rate_date=ln.fx_rate_date,
                fx_missing=base_missing,
                money_account_id=ln.money_account_id,
                cashflow_category_id=ln.cashflow_category_id,
                counterparty_company_id=ln.counterparty_company_id,
                employee_user_id=ln.employee_user_id,
                vat_rate_id=ln.vat_rate_id,
                deal_id=ln.deal_id,
                contract_id=ln.contract_id,
                subscription_id=ln.subscription_id,
                comment=ln.comment,
            )
        )
    await session.flush()
    return entry


def _code_to_id(accounts: list[FinAccountGl]) -> dict[str, int]:
    return {a.code: a.id for a in accounts}


# ───────────────────────────── post_operation ─────────────────────────────


async def post_operation(
    session: AsyncSession,
    operation: FinOperation,
    *,
    posted_by_user_id: int | None = None,
) -> FinJournalEntry:
    """Провести операцию (приход/расход/перевод/opening) → проводка.

    Иммутабельность: если операция уже posted/reversed → ImmutablePosted.
    Period-lock проверяется на op_date. Шаблон выбирается СТРОГО из
    op_type.posting_template (никаких догадок по direction).

    Конкурентность (B1): берём row-lock (FOR UPDATE) на строку операции и
    перечитываем её status ПОД локом, прежде чем строить проводку. Без этого два
    параллельных POST/post/decision/fulfill при scale=2 проходили in-memory-проверку
    `assert_operation_mutable` на STALE-копии и оба писали GL-проводку (дубль).
    Второй конкурент теперь ждёт COMMIT первого, перечитывает status='posted' и
    отбивается ImmutablePosted (→ 409). Лок на уровне движка ⇒ покрывает ВСЕ
    вызывающие (create+auto_post, /post, decision, fulfill, provision_registry).
    """
    await session.execute(
        select(FinOperation.id)
        .where(FinOperation.id == operation.id)
        .with_for_update()
    )
    await session.refresh(operation, attribute_names=["status"])
    assert_operation_mutable(operation.status)

    le = (
        await session.execute(
            select(FinLegalEntity).where(FinLegalEntity.id == operation.legal_entity_id)
        )
    ).scalar_one()
    func_ccy = le.functional_currency
    base_ccy = await _base_currency(session)

    await period_lock.assert_period_open(session, le.id, operation.op_date)

    op_type = await _operation_op_type(session, operation)
    template = _operation_template(op_type)

    if template in ("cash_in", "cash_out"):
        draft = await _build_cash(session, operation, op_type, func_ccy, template)
    elif template == "transfer":
        draft = await _build_transfer(session, operation, func_ccy)
    elif template == "opening":
        draft = await _build_opening(session, operation, func_ccy)
    else:
        raise PostingError(f"Неизвестный posting_template={template!r} для операции")

    # резолв всех счетов проводки
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
    operation.status = "posted"
    operation.journal_entry_id = entry.id
    operation.posted_at = entry.posted_at
    await session.flush()

    # WARNING #3: если операция рождена из заявки — заявка доходит до 'paid' в момент
    # фактической проводки (а не только при auto_post в fulfill). Идемпотентно.
    if operation.fin_request_id is not None:
        req = (
            await session.execute(
                select(FinRequest).where(FinRequest.id == operation.fin_request_id)
            )
        ).scalar_one_or_none()
        if req is not None and req.status != "paid":
            req.status = "paid"
            req.resulting_operation_id = operation.id
            await session.flush()

    return entry


async def _operation_op_type(
    session: AsyncSession, operation: FinOperation
) -> FinOpType:
    """Строго загрузить op_type операции (по op_type_id). Без него постить нельзя.

    Нет relationship на FinOperation → грузим явным select по op_type_id (не полагаемся
    на eager-load и НИКОГДА не угадываем шаблон по direction — это давало тихий неверный
    шаблон, напр. opening(direction='in') → cash_in вместо opening).
    """
    if operation.op_type_id is None:
        raise PostingError(
            "Операция не имеет типа (op_type_id пуст) — невозможно выбрать posting_template; "
            "проводка не создаётся."
        )
    op_type = (
        await session.execute(
            select(FinOpType).where(FinOpType.id == operation.op_type_id)
        )
    ).scalar_one_or_none()
    if op_type is None:
        raise PostingError(
            f"Тип операции id={operation.op_type_id} не найден — невозможно выбрать "
            "posting_template; проводка не создаётся."
        )
    return op_type


def _operation_template(op_type: FinOpType) -> str:
    """posting_template СТРОГО из op_type. Пустой шаблон → PostingError (без догадок)."""
    template = op_type.posting_template
    if not template:
        raise PostingError(
            f"У типа операции {op_type.code!r} пустой posting_template — проводка не создаётся."
        )
    return template


async def _build_cash(
    session: AsyncSession,
    op: FinOperation,
    op_type: FinOpType,
    func_ccy: str,
    template: str,
) -> EntryDraft:
    money_id = op.account_to_id if template == "cash_in" else op.account_from_id
    if money_id is None:
        raise PostingError("Не задан денежный счёт операции")
    ma = await _money_account(session, money_id)
    money_code = await _money_account_gl_code(session, ma)

    counter_code = await _counter_account_code(session, op_type, template)
    counter_acc = await _resolve_account(session, counter_code)

    rates = await _prefetch_rates(session, {(op.currency, func_ccy)}, op.op_date)
    resolver = _dict_resolver(rates)

    common = dict(
        on_date=op.op_date,
        func_currency=func_ccy,
        amount=op.amount,
        currency=op.currency,
        money_account_id=ma.id,
        money_account_code=money_code,
        counter_account_code=counter_code,
        cashflow_category_id=op.cashflow_category_id,
        counterparty_company_id=op.counterparty_company_id,
        counter_requires_counterparty=counter_acc.requires_counterparty,
        vat_rate_id=op.vat_rate_id,
        rate_resolver=resolver,
        source="operation",
        source_ref_id=op.id,
        deal_id=op.deal_id,
        contract_id=op.contract_id,
        subscription_id=op.subscription_id,
        memo=op.purpose,
    )
    if template == "cash_in":
        return build_cash_in(**common)
    return build_cash_out(**common)


async def _counter_account_code(
    session: AsyncSession, op_type: FinOpType, template: str
) -> str:
    """Контр-счёт операции: из op_type.default_gl_account_id, иначе разумный дефолт.

    cash_in → доход (4090 «Прочие доходы») если нет default; cash_out → расход
    (5990 «Прочие расходы»). default_gl_account задаётся в сиде op-type.
    """
    if op_type.default_gl_account_id is not None:
        acc = (
            await session.execute(
                select(FinAccountGl).where(FinAccountGl.id == op_type.default_gl_account_id)
            )
        ).scalar_one_or_none()
        if acc is not None:
            return acc.code
    return "4090" if template == "cash_in" else "5990"


async def _build_transfer(
    session: AsyncSession, op: FinOperation, func_ccy: str
) -> EntryDraft:
    if op.account_from_id is None or op.account_to_id is None:
        raise PostingError("Перевод требует счёта-источника и счёта-получателя")
    ma_from = await _money_account(session, op.account_from_id)
    ma_to = await _money_account(session, op.account_to_id)
    code_from = await _money_account_gl_code(session, ma_from)
    code_to = await _money_account_gl_code(session, ma_to)

    amount_to = op.to_amount if op.to_amount is not None else op.amount
    pairs = {(ma_from.currency, func_ccy), (ma_to.currency, func_ccy)}
    rates = await _prefetch_rates(session, pairs, op.op_date)
    resolver = _dict_resolver(rates)

    return build_transfer(
        on_date=op.op_date,
        func_currency=func_ccy,
        amount_from=op.amount,
        currency_from=ma_from.currency,
        account_from_id=ma_from.id,
        account_from_code=code_from,
        amount_to=amount_to,
        currency_to=ma_to.currency,
        account_to_id=ma_to.id,
        account_to_code=code_to,
        rate_resolver=resolver,
        fx_gain_code=FX_GAIN_CODE,
        fx_loss_code=FX_LOSS_CODE,
        source="operation",
        source_ref_id=op.id,
        memo=op.purpose,
    )


async def _build_opening(
    session: AsyncSession, op: FinOperation, func_ccy: str
) -> EntryDraft:
    if op.account_to_id is None:
        raise PostingError("Ввод остатка требует денежного счёта (account_to)")
    ma = await _money_account(session, op.account_to_id)
    money_code = await _money_account_gl_code(session, ma)
    rates = await _prefetch_rates(session, {(op.currency, func_ccy)}, op.op_date)
    resolver = _dict_resolver(rates)
    return build_opening(
        on_date=op.op_date,
        func_currency=func_ccy,
        amount=op.amount,
        currency=op.currency,
        money_account_id=ma.id,
        money_account_code=money_code,
        opening_offset_code=OPENING_OFFSET_CODE,
        rate_resolver=resolver,
        source="operation",
        source_ref_id=op.id,
        memo=op.purpose,
    )


# ───────────────────────────── post_manual_journal ─────────────────────────────


async def post_manual_journal(
    session: AsyncSession,
    journal: FinManualJournal,
    *,
    posted_by_user_id: int | None = None,
) -> FinJournalEntry:
    """Провести ручную adjustment-проводку → проводка. Иммутабельность + period-lock."""
    assert_manual_journal_mutable(journal.status)

    le = (
        await session.execute(
            select(FinLegalEntity).where(FinLegalEntity.id == journal.legal_entity_id)
        )
    ).scalar_one()
    func_ccy = le.functional_currency
    base_ccy = await _base_currency(session)
    await period_lock.assert_period_open(session, le.id, journal.date)

    # строки журнала + флаг requires_counterparty по счёту
    inputs: list[ManualLineInput] = []
    pairs: set[tuple[str, str]] = set()
    for li in journal.lines:
        acc = (
            await session.execute(
                select(FinAccountGl).where(FinAccountGl.id == li.account_gl_id)
            )
        ).scalar_one()
        pairs.add((li.currency, func_ccy))
        inputs.append(
            ManualLineInput(
                account_code=acc.code,
                side=li.side,
                amount=li.amount,
                currency=li.currency,
                requires_counterparty=acc.requires_counterparty,
                counterparty_company_id=li.counterparty_company_id,
                money_account_id=li.money_account_id,
                cashflow_category_id=li.cashflow_category_id,
                comment=li.comment,
            )
        )

    rates = await _prefetch_rates(session, pairs, journal.date)
    resolver = _dict_resolver(rates)
    draft = build_manual_journal(
        on_date=journal.date,
        func_currency=func_ccy,
        lines=inputs,
        rate_resolver=resolver,
        source="manual",
        source_ref_id=journal.id,
        memo=journal.memo,
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
    journal.status = "posted"
    journal.journal_entry_id = entry.id
    journal.posted_at = entry.posted_at
    await session.flush()
    return entry


# ───────────────────────────── reverse_entry ─────────────────────────────


async def reverse_entry(
    session: AsyncSession,
    entry: FinJournalEntry,
    *,
    on_date: date | None = None,
    posted_by_user_id: int | None = None,
    memo: str | None = None,
) -> FinJournalEntry:
    """Сторнировать проводку: зеркальные строки + исходная → status='reversed'.

    Только posted можно сторнировать (draft нечего; reversed уже сторнировано).
    Дата сторно — в ОТКРЫТОМ периоде (по умолчанию = дата исходной; если её период
    закрыт — вызывающий передаёт сегодняшнюю в открытом). Проверяем оба периода.

    Конкурентность (B2): берём row-lock (FOR UPDATE) на исходную проводку и
    перечитываем её status ПОД локом перед построением зеркальных строк. Без этого
    два параллельных reverse при scale=2 читали STALE status='posted', и оба писали
    сторно — счёт нёс −original (двойное сторнирование). Второй конкурент теперь
    ждёт COMMIT первого, видит status='reversed' и отбивается ImmutablePosted (→ 409).
    Лок на уровне движка покрывает всех вызывающих (operation/journal/entry reverse,
    cancel_invoice/cancel_vendor_bill).
    """
    await session.execute(
        select(FinJournalEntry.id)
        .where(FinJournalEntry.id == entry.id)
        .with_for_update()
    )
    await session.refresh(entry, attribute_names=["status"])
    if entry.status != "posted":
        raise ImmutablePosted("Сторнировать можно только проведённую проводку")

    rev_date = on_date or entry.date
    await period_lock.assert_period_open(session, entry.legal_entity_id, rev_date)

    # загрузить строки исходной проводки → LineDraft (с кодами счетов)
    rows = (
        await session.execute(
            select(FinLedgerLine, FinAccountGl.code)
            .join(FinAccountGl, FinLedgerLine.account_gl_id == FinAccountGl.id)
            .where(FinLedgerLine.journal_entry_id == entry.id)
        )
    ).all()
    original_lines = [
        LineDraft(
            account_code=code,
            amount=ll.amount,
            currency=ll.currency,
            amount_func=ll.amount_func,
            money_account_id=ll.money_account_id,
            cashflow_category_id=ll.cashflow_category_id,
            counterparty_company_id=ll.counterparty_company_id,
            employee_user_id=ll.employee_user_id,
            vat_rate_id=ll.vat_rate_id,
            deal_id=ll.deal_id,
            contract_id=ll.contract_id,
            subscription_id=ll.subscription_id,
            fx_rate=ll.fx_rate,
            fx_rate_date=ll.fx_rate_date,
        )
        for (ll, code) in rows
    ]

    base_ccy = await _base_currency(session)
    draft = build_reversal(
        on_date=rev_date,
        func_currency=entry.func_currency,
        original_lines=original_lines,
        reverses_entry_id=entry.id,
        source=entry.source,
        source_ref_id=entry.source_ref_id,
        memo=memo or f"Сторно проводки #{entry.id}",
    )
    codes = {ln.account_code for ln in draft.lines}
    accounts = [await _resolve_account(session, c) for c in codes]
    rev_entry = await _persist_entry(
        session,
        draft,
        legal_entity_id=entry.legal_entity_id,
        base_currency=base_ccy,
        posted_by_user_id=posted_by_user_id,
        code_to_id=_code_to_id(accounts),
    )
    entry.status = "reversed"
    await session.flush()
    return rev_entry
