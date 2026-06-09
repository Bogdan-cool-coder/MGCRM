"""Смена базовой валюты группы + пересчёт amount_in_base — Ф4 (G §2 реш.1, H4).

`fin_settings.base_currency` настраиваема. При смене — служба `recompute_base_amounts`
идёт по всем `fin_ledger_line` и пересчитывает `amount_in_base` по дате КАЖДОЙ строки
через get_rate_strict (исторические отчёты не «уплывают» — курс на дату операции, не на
сегодня).

ИНВАРИАНТЫ:
  • amount_func НЕ трогается (база — ПРОЕКЦИЯ, инвариант №2). Меняем только amount_in_base
    + base_currency-снимок на строке. Σ amount_func=0 на проводку сохраняется тождественно.
  • Σ amount_in_base на проводку может стать ≠0 (округление пер-строка) — это ДОПУСТИМО,
    amount_in_base проекция, не балансовый инвариант. Trial balance держится в func.
  • Курса нет на дату → строка fx_missing=true, amount_in_base=NULL, job→'partial', UI
    показывает «нет курса» для ручного ввода (как в fx.to_base).

ПОЛИТИКА ЗАКРЫТЫХ ПЕРИОДОВ (H4 — решение владельца, обоснование):
  Пересчитываются ТОЛЬКО ОТКРЫТЫЕ периоды. Закрытые периоды сохраняют исторический
  base_currency/amount_in_base НЕТРОНУТЫМИ. Обоснование: amount_in_base закрытого периода —
  часть уже показанной директору отчётности; пересчёт изменил бы исторические цифры
  закрытого периода, что конфликтует с period-lock философией («закрыто = заморожено»).
  Новая база применяется вперёд + к открытым периодам. Закрытые остаются в исторической
  базе (отчёт-консолидация по группе за закрытый период считается в его исторической базе).
  Это безопасный default; снятие защиты (пересчёт закрытых) — отдельная явная политика
  (не в Ф4).

Идемпотентность: job-таблица `fin_base_recompute_job`. Повтор при том же target —
строки уже с нужным base_currency, конвертация даёт тот же результат (no-op по значению).
Single-flight — advisory-lock у вызывающего (эндпоинт/cron).
"""

from __future__ import annotations

from datetime import UTC, date, datetime
from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import (
    FinBaseRecomputeJob,
    FinJournalEntry,
    FinLedgerLine,
    FinPeriodLock,
    FinSettings,
)
from app.services.finance import fx

#: Фиксированный advisory-lock ключ для single-flight пересчёта базы (scale=2). НЕ
#: пересекается с миграционными ключами 91_xxx — отдельный runtime-неймспейс 92_001.
RECOMPUTE_ADVISORY_KEY = 92_001


class BaseCurrencyError(Exception):
    """Ошибка смены базовой валюты (→ 422)."""


async def _locked_periods(session: AsyncSession) -> set[tuple[int, int, int]]:
    """Множество закрытых (legal_entity_id, year, month) — для исключения из пересчёта."""
    rows = (
        await session.execute(
            select(
                FinPeriodLock.legal_entity_id,
                FinPeriodLock.year,
                FinPeriodLock.month,
            )
        )
    ).all()
    return {(le, y, m) for (le, y, m) in rows}


def line_is_in_closed_period(
    *,
    legal_entity_id: int,
    on_date: date,
    locked: set[tuple[int, int, int]],
) -> bool:
    """True ⇔ строка принадлежит закрытому периоду (исключается из пересчёта). Pure."""
    return (legal_entity_id, on_date.year, on_date.month) in locked


async def recompute_base_amounts(
    session: AsyncSession,
    *,
    target_currency: str,
    started_by_user_id: int | None = None,
    batch_size: int = 500,
) -> FinBaseRecomputeJob:
    """Пересчитать amount_in_base всех ledger-строк ОТКРЫТЫХ периодов под новую базу.

    Создаёт job, идёт батчами по entry.date, конвертирует через get_rate_strict (lenient —
    fx_missing допустим). Закрытые периоды пропускаются (H4). Обновляет fin_settings
    (base_currency + base_currency_changed_at). НЕ трогает amount_func. НЕ commit (caller).

    Возвращает job со счётчиками (total/processed/skipped_closed/missing_rate, status).
    """
    settings = (await session.execute(select(FinSettings).limit(1))).scalar_one_or_none()
    previous = settings.base_currency if settings else None

    job = FinBaseRecomputeJob(
        target_currency=target_currency,
        previous_currency=previous,
        status="running",
        started_by_user_id=started_by_user_id,
    )
    session.add(job)
    await session.flush()

    locked = await _locked_periods(session)

    # Грузим строки порциями (id, entry.date, legal_entity, currency, amount).
    last_id = 0
    total = processed = skipped_closed = missing = 0
    while True:
        rows = (
            await session.execute(
                select(
                    FinLedgerLine.id,
                    FinLedgerLine.legal_entity_id,
                    FinLedgerLine.currency,
                    FinLedgerLine.amount,
                    FinJournalEntry.date,
                )
                .join(FinJournalEntry, FinLedgerLine.journal_entry_id == FinJournalEntry.id)
                .where(FinLedgerLine.id > last_id)
                .order_by(FinLedgerLine.id)
                .limit(batch_size)
            )
        ).all()
        if not rows:
            break
        for (line_id, le_id, ccy, amount, entry_date) in rows:
            last_id = line_id
            total += 1
            if line_is_in_closed_period(
                legal_entity_id=le_id, on_date=entry_date, locked=locked
            ):
                skipped_closed += 1
                continue
            amount_in_base, _rate, fx_missing = await fx.to_base(
                session, amount, ccy, target_currency, entry_date
            )
            if fx_missing:
                missing += 1
            ln = await session.get(FinLedgerLine, line_id)
            if ln is not None:
                ln.amount_in_base = amount_in_base
                ln.base_currency = target_currency
                ln.fx_missing = fx_missing
            processed += 1
        await session.flush()

    if settings is not None:
        settings.base_currency = target_currency
        settings.base_currency_changed_at = datetime.now(UTC)

    job.total_lines = total
    job.processed_lines = processed
    job.skipped_closed = skipped_closed
    job.missing_rate_lines = missing
    job.status = "partial" if missing > 0 else "done"
    job.finished_at = datetime.now(UTC)
    await session.flush()
    return job


# ───────────────────────────── pure: проекция Σ_base ─────────────────────────────


def base_imbalance(amount_in_base_values: list[Decimal | None]) -> Decimal:
    """Σ amount_in_base проводки (None трактуем как 0). Pure.

    ВНИМАНИЕ: это НЕ инвариант — Σ_base может быть ≠0 из-за пер-строчного округления при
    пересчёте. Хелпер нужен только для отчётности/диагностики (показать «хвост округления
    в базе»), НЕ для проверки баланса (баланс держится amount_func).
    """
    total = Decimal("0")
    for v in amount_in_base_values:
        if v is not None:
            total += v
    return total.quantize(Decimal("0.01"))
