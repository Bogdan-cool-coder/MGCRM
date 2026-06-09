"""Финансы Ф0 (миграция E): CHECK-констрейнты на статусы GL-сущностей (PM флаг S-1).

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist). В чанке 1 (0087) статусы
fin_journal_entry / fin_operation / fin_manual_journal заведены как String без
CHECK — posting engine (чанк 2) полагается на иммутабельность по статусу, поэтому
БД должна гарантировать допустимое множество значений (иначе кривой статус обойдёт
проверку assert_*_mutable). Добавляем CHECK-констрейнты:

  • fin_journal_entry.status  ∈ draft|posted|reversed
  • fin_operation.status      ∈ planned|to_pay|on_hold|posted|reversed|rejected|
                                cancelled|partially_paid
  • fin_manual_journal.status ∈ draft|posted|reversed

Идемпотентно: каждый ADD CONSTRAINT под guard «IF NOT EXISTS в pg_constraint» +
один advisory-lock (scale=2 safe). Чистый DDL, данных не трогает.

downgrade: DROP CONSTRAINT IF EXISTS (обратимо).

Revision ID: 0088_fin_status_checks  (22 chars ≤32 ✓)
Revises: 0087_fin_gl_core
Create Date: 2026-06-03
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op

revision: str = "0088_fin_status_checks"
down_revision: str | None = "0087_fin_gl_core"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

_SEED_LOCK_FIN_STATUS = 91_004

_CHECKS: tuple[tuple[str, str, str], ...] = (
    (
        "fin_journal_entry",
        "ck_fin_journal_entry_status",
        "status IN ('draft','posted','reversed')",
    ),
    (
        "fin_operation",
        "ck_fin_operation_status",
        "status IN ('planned','to_pay','on_hold','posted','reversed',"
        "'rejected','cancelled','partially_paid')",
    ),
    (
        "fin_manual_journal",
        "ck_fin_manual_journal_status",
        "status IN ('draft','posted','reversed')",
    ),
)


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _SEED_LOCK_FIN_STATUS})
    for table, name, expr in _CHECKS:
        conn.execute(
            sa.text(
                f"""
                DO $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM pg_constraint WHERE conname = '{name}'
                    ) THEN
                        ALTER TABLE {table} ADD CONSTRAINT {name} CHECK ({expr});
                    END IF;
                END $$;
                """
            )
        )


def downgrade() -> None:
    for table, name, _expr in _CHECKS:
        op.execute(f"ALTER TABLE {table} DROP CONSTRAINT IF EXISTS {name}")
