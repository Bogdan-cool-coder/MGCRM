"""Финансы Ф5: частичный UNIQUE-индекс «один accrual на документ» (anti-double-post).

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist).

Контекст (concurrency, scale=2): два параллельных issue_invoice / confirm_vendor_bill
могли задвоить accrual-проводку (Дт AR/расход / Кт выручка/AP). Row-lock FOR UPDATE
на документ в orchestrator'ах invoicing.py сериализует это на уровне приложения; данный
индекс — DB-level belt-and-suspenders.

ПОЧЕМУ ЧАСТИЧНЫЙ, А НЕ ПОЛНЫЙ UNIQUE(source, source_ref_id, kind):
  Полный constraint сломал бы ЛЕГИТИМНЫЕ множественные settlement-проводки —
  частичная оплата инвойса создаёт несколько (source='invoice', source_ref_id=N,
  kind='cash_in') строк (оплата 30%, затем 70%). Поэтому уникальность применяется
  ТОЧЕЧНО только к accrual-проводкам:
    kind IN ('revenue_accrual','expense_accrual')  ⇒  ровно одна accrual на документ.
  Settlement (cash_in/cash_out), reversal, operation/manual-проводки — НЕ затронуты.
  Гонку двойного ГАШЕНИЯ AR/AP решает FOR UPDATE + атомарный paid_amount, не индекс
  (индекс не отличил бы дубль-гонку от валидной доплаты).

  source_ref_id IS NOT NULL в предикате — accrual всегда несёт ссылку на документ;
  страхует от случайных NULL-коллизий.

Идемпотентно: CREATE UNIQUE INDEX IF NOT EXISTS под advisory-lock 91_012. На проде
fin_journal_entry пуст (Ф5 ещё не в работе) — нарушающих строк нет; accrual по
construction пишется один раз на документ. downgrade — DROP INDEX IF EXISTS.

Revision ID: 0096_fin_je_accrual_uniq
Revises: 0095_fin_phase5_perms
Create Date: 2026-06-03
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op

revision: str = "0096_fin_je_accrual_uniq"
down_revision: str | None = "0095_fin_phase5_perms"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ. Занятые fin-ключи: 91_001..91_011. Свободен 91_012.
_LOCK_FIN_JE_ACCRUAL_UNIQ = 91_012

#: Имя индекса ≤32 симв (uq_fin_je_accrual = 17).
_INDEX_NAME = "uq_fin_je_accrual"


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _LOCK_FIN_JE_ACCRUAL_UNIQ},
    )
    conn.execute(
        sa.text(
            f"CREATE UNIQUE INDEX IF NOT EXISTS {_INDEX_NAME} "
            "ON fin_journal_entry (source, source_ref_id, kind) "
            "WHERE kind IN ('revenue_accrual', 'expense_accrual') "
            "AND source_ref_id IS NOT NULL"
        )
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _LOCK_FIN_JE_ACCRUAL_UNIQ},
    )
    conn.execute(sa.text(f"DROP INDEX IF EXISTS {_INDEX_NAME}"))
