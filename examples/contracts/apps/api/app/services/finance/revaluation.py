"""Переоценка валютных остатков (FX revaluation) — Ф4 (G §2 реш.1, H10, E/F10).

На конец периода остаток счёта/AR/AP в валюте, ОТЛИЧНОЙ от функц.валюты юрлица,
пересчитывается по курсу на дату. Расхождение с замороженной балансовой оценкой
(amount_func) — нереализованная курсовая разница, и она ДОЛЖНА попасть в P&L отдельной
строкой (счёт 4910 доход / 5910 расход), иначе «всего по компании» плавает без
объяснения.

  delta_func = balance_fc × rate(fc→func, on_date) − book_value_func
  delta>0  → Дт счёт / Кт 4910 (gain)     (актив подорожал / обязательство подешевело)
  delta<0  → Кт счёт / Дт 5910 (loss)

Σ=0 by construction (см. posting.build_fx_revaluation). Идемпотентно per (счёт, период):
дважды переоценить тот же счёт за тот же месяц нельзя (партиал-unique JE на source/
source_ref_id/kind — НЕ ставится здесь; идемпотентность даёт явная проверка существующей
fx_reval-проводки за период). period-lock: нельзя переоценить в закрытый период.

Чистое ядро — `revaluation_delta` (тестируемо без БД); async-оркестратор связывает с
балансом и постингом.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date
from decimal import ROUND_HALF_UP, Decimal

from sqlalchemy import func as sa_func
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import (
    FinAccountGl,
    FinJournalEntry,
    FinLedgerLine,
    FinLegalEntity,
    FinMoneyAccount,
)
from app.services.finance import fx, period_lock
from app.services.finance.posting import build_fx_revaluation
from app.services.finance.posting_db import (
    _base_currency,
    _code_to_id,
    _persist_entry,
    _resolve_account,
)
from app.services.finance.recognition import period_last_day
from app.services.finance.seed_data import FX_GAIN_CODE, FX_LOSS_CODE

_POSTED = "posted"
#: Живые статусы для расчёта БАЛАНСА переоцениваемого счёта: posted + reversed
#: (обе половины сторно гасятся → книжная оценка верна). NB: idempotency-guard
#: _already_revalued намеренно использует _POSTED — сторнированная (reversed)
#: прошлая переоценка считается отменённой и НЕ блокирует новую. См. B7 CRIT-1.
_LIVE_STATUSES = ("posted", "reversed")
SOURCE_FX_REVAL = "fx_reval"

#: Неймспейс advisory-ключа для single-flight прогона переоценки (scale=2). Второй
#: int4 — recognition.period_lock_subkey(legal_entity, year, month): параллельные
#: прогоны одного (юрлицо, период) сериализуются (защита от двойной fx_reval-проводки
#: на счёт за период), разные периоды/юрлица не блокируют друг друга.
REVALUATION_ADVISORY_NS = 92_003


class RevaluationError(Exception):
    """Ошибка переоценки (→ 422)."""


def _q2(value: Decimal) -> Decimal:
    return value.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


# ───────────────────────────── pure ─────────────────────────────


@dataclass
class RevalDelta:
    balance_fc: Decimal  # остаток в валюте счёта (foreign currency)
    book_value_func: Decimal  # балансовая оценка в функц.валюте (Σ amount_func)
    revalued_func: Decimal  # переоценённая стоимость = balance_fc × rate
    delta_func: Decimal  # revalued − book (знаковая; >0 gain актива)


def revaluation_delta(
    *, balance_fc: Decimal, book_value_func: Decimal, rate: Decimal
) -> RevalDelta:
    """Дельта переоценки в функц.валюте. Pure.

    `rate` — курс fc→func на дату переоценки (строгий, получен вызывающим). delta>0
    означает рост func-оценки актива (или удешевление обязательства — обязательства
    book_value_func<0, тогда знак работает симметрично через Σ).
    """
    revalued = _q2(balance_fc * rate)
    delta = _q2(revalued - _q2(book_value_func))
    return RevalDelta(
        balance_fc=_q2(balance_fc),
        book_value_func=_q2(book_value_func),
        revalued_func=revalued,
        delta_func=delta,
    )


# ───────────────────────────── async ─────────────────────────────


async def _account_balance_fc_and_func(
    session: AsyncSession,
    *,
    account_gl_id: int,
    legal_entity_id: int,
    account_currency: str,
    on_date: date,
    counterparty_company_id: int | None = None,
) -> tuple[Decimal, Decimal]:
    """(Σ amount [валюта счёта], Σ amount_func) по ЖИВЫМ строкам счёта до on_date.

    Для денежного счёта суммируем по money_account-строкам нельзя напрямую (account_gl_id
    общий для нескольких счетов) — поэтому переоценка денежных идёт по конкретному
    money_account (см. revalue_money_account). Здесь — общий хелпер по GL-счёту (AR/AP),
    опционально по контрагенту (для пер-контрагентной AR/AP-переоценки).
    """
    q = (
        select(
            sa_func.coalesce(sa_func.sum(FinLedgerLine.amount), Decimal("0")),
            sa_func.coalesce(sa_func.sum(FinLedgerLine.amount_func), Decimal("0")),
        )
        .join(FinJournalEntry, FinLedgerLine.journal_entry_id == FinJournalEntry.id)
        .where(
            FinLedgerLine.account_gl_id == account_gl_id,
            FinLedgerLine.legal_entity_id == legal_entity_id,
            FinLedgerLine.currency == account_currency,
            FinJournalEntry.status.in_(_LIVE_STATUSES),
            FinJournalEntry.date <= on_date,
        )
    )
    if counterparty_company_id is not None:
        q = q.where(FinLedgerLine.counterparty_company_id == counterparty_company_id)
    fc_sum, func_sum = (await session.execute(q)).one()
    return _q2(fc_sum), _q2(func_sum)


async def _already_revalued(
    session: AsyncSession,
    *,
    legal_entity_id: int,
    source_ref_id: int,
    on_date: date,
) -> bool:
    """True если за этот (счёт, период-дата) уже есть fx_reval-проводка (идемпотентность)."""
    found = (
        await session.execute(
            select(FinJournalEntry.id).where(
                FinJournalEntry.legal_entity_id == legal_entity_id,
                FinJournalEntry.kind == "fx_reval",
                FinJournalEntry.source == SOURCE_FX_REVAL,
                FinJournalEntry.source_ref_id == source_ref_id,
                FinJournalEntry.date == on_date,
                FinJournalEntry.status == _POSTED,
            ).limit(1)
        )
    ).first()
    return found is not None


async def revalue_money_account(
    session: AsyncSession,
    money_account: FinMoneyAccount,
    *,
    year: int,
    month: int,
    posted_by_user_id: int | None = None,
) -> FinJournalEntry | None:
    """Переоценить денежный счёт на конец периода. None — если переоценивать нечего.

    Только счета, валюта которых ≠ функц.валюте юрлица. Дельта в func → проводка
    Дт/Кт счёт vs 4910/5910. Идемпотентно (нет двойной fx_reval за тот же период).
    Период-lock на дату переоценки (конец месяца).
    """
    le = (
        await session.execute(
            select(FinLegalEntity).where(FinLegalEntity.id == money_account.legal_entity_id)
        )
    ).scalar_one()
    func_ccy = le.functional_currency
    if money_account.currency == func_ccy:
        return None  # счёт в функц.валюте — курсовой разницы быть не может

    on_date = period_last_day(year, month)
    await period_lock.assert_period_open(session, le.id, on_date)
    if await _already_revalued(
        session, legal_entity_id=le.id, source_ref_id=money_account.id, on_date=on_date
    ):
        return None

    # Остаток денежного счёта в валюте счёта + балансовая func-оценка (по money_account_id).
    q = (
        select(
            sa_func.coalesce(sa_func.sum(FinLedgerLine.amount), Decimal("0")),
            sa_func.coalesce(sa_func.sum(FinLedgerLine.amount_func), Decimal("0")),
        )
        .join(FinJournalEntry, FinLedgerLine.journal_entry_id == FinJournalEntry.id)
        .where(
            FinLedgerLine.money_account_id == money_account.id,
            FinJournalEntry.status.in_(_LIVE_STATUSES),
            FinJournalEntry.date <= on_date,
        )
    )
    fc_sum, func_sum = (await session.execute(q)).one()
    initial = money_account.initial_balance or Decimal("0")
    balance_fc = _q2(fc_sum + initial)
    book_func = _q2(func_sum)  # initial=0 by design Ф0; не дёргаем курс для нуля
    if initial != Decimal("0"):
        init_rate = await fx.get_rate_strict(session, money_account.currency, func_ccy, on_date)
        book_func = _q2(func_sum + _q2(initial * init_rate))

    rate = await fx.get_rate_strict(session, money_account.currency, func_ccy, on_date)
    delta = revaluation_delta(balance_fc=balance_fc, book_value_func=book_func, rate=rate)
    if delta.delta_func == Decimal("0"):
        return None

    return await _post_reval(
        session,
        legal_entity=le,
        account_code=await _gl_code(session, money_account.gl_account_id),
        delta_func=delta.delta_func,
        account_currency=money_account.currency,
        on_date=on_date,
        source_ref_id=money_account.id,
        new_rate=rate,
        posted_by_user_id=posted_by_user_id,
        memo=f"Переоценка остатка счёта «{money_account.name}» на {on_date.isoformat()}",
    )


async def _gl_code(session: AsyncSession, account_gl_id: int) -> str:
    return (
        await session.execute(
            select(FinAccountGl.code).where(FinAccountGl.id == account_gl_id)
        )
    ).scalar_one()


async def _post_reval(
    session: AsyncSession,
    *,
    legal_entity: FinLegalEntity,
    account_code: str,
    delta_func: Decimal,
    account_currency: str,
    on_date: date,
    source_ref_id: int,
    new_rate: Decimal | None,
    counterparty_company_id: int | None = None,
    posted_by_user_id: int | None = None,
    memo: str | None = None,
) -> FinJournalEntry:
    base_ccy = await _base_currency(session)
    draft = build_fx_revaluation(
        on_date=on_date,
        func_currency=legal_entity.functional_currency,
        account_code=account_code,
        delta_func=delta_func,
        fx_gain_code=FX_GAIN_CODE,
        fx_loss_code=FX_LOSS_CODE,
        account_currency=account_currency,
        counterparty_company_id=counterparty_company_id,
        new_rate=new_rate,
        source=SOURCE_FX_REVAL,
        source_ref_id=source_ref_id,
        memo=memo,
    )
    codes = {ln.account_code for ln in draft.lines}
    accounts = [await _resolve_account(session, c) for c in codes]
    return await _persist_entry(
        session,
        draft,
        legal_entity_id=legal_entity.id,
        base_currency=base_ccy,
        posted_by_user_id=posted_by_user_id,
        code_to_id=_code_to_id(accounts),
    )


async def run_revaluation_period(
    session: AsyncSession,
    *,
    legal_entity: FinLegalEntity,
    year: int,
    month: int,
    posted_by_user_id: int | None = None,
) -> dict[str, int]:
    """Переоценить все валютные денежные счета юрлица за период. Идемпотентно.

    AR/AP-переоценка пер-контрагентно — отложена в Ф4.1 (нужен разрез по контрагенту +
    валюте, дороже; денежные счета — основной кейс курсовых разниц). Возвращает счётчики.
    """
    accounts = (
        await session.execute(
            select(FinMoneyAccount).where(
                FinMoneyAccount.legal_entity_id == legal_entity.id,
                FinMoneyAccount.is_active.is_(True),
            )
        )
    ).scalars().all()
    counters = {"processed": 0, "revalued": 0, "no_change": 0, "skipped_func_ccy": 0}
    for acc in accounts:
        counters["processed"] += 1
        if acc.currency == legal_entity.functional_currency:
            counters["skipped_func_ccy"] += 1
            continue
        entry = await revalue_money_account(
            session, acc, year=year, month=month, posted_by_user_id=posted_by_user_id
        )
        if entry is not None:
            counters["revalued"] += 1
        else:
            counters["no_change"] += 1
    return counters
