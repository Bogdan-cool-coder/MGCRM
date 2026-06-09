"""Финансы Ф0 (миграция F): CHECK-констрейнт на fin_operation.direction (PM флаг W-2).

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist). В чанке 1 (0087) direction заведён как
String(10) без CHECK — допустимое множество гарантировалось только Pydantic-схемой на
уровне API. Для финданных это недостаточно: posting engine и проекции (ДДС/P&L)
ветвятся по direction (in|out|transfer; transfer исключается из ДДС/P&L by
construction), поэтому БД должна гарантировать допустимость значения, чтобы кривой
direction не обошёл логику отчётов в обход API.

  • fin_operation.direction ∈ in|out|transfer

Идемпотентно: ADD CONSTRAINT под guard «IF NOT EXISTS в pg_constraint» + один
advisory-lock (scale=2 safe). Чистый DDL, данных не трогает.

downgrade: DROP CONSTRAINT IF EXISTS (обратимо).

Revision ID: 0089_fin_op_direction_ck  (24 chars ≤32 ✓)
Revises: 0088_fin_status_checks
Create Date: 2026-06-03
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op

revision: str = "0089_fin_op_direction_ck"
down_revision: str | None = "0088_fin_status_checks"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

_SEED_LOCK_FIN_DIRECTION = 91_005

_CONSTRAINT_NAME = "ck_fin_operation_direction"
_CONSTRAINT_EXPR = "direction IN ('in','out','transfer')"


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _SEED_LOCK_FIN_DIRECTION}
    )
    conn.execute(
        sa.text(
            f"""
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = '{_CONSTRAINT_NAME}'
                ) THEN
                    ALTER TABLE fin_operation
                        ADD CONSTRAINT {_CONSTRAINT_NAME} CHECK ({_CONSTRAINT_EXPR});
                END IF;
            END $$;
            """
        )
    )


def downgrade() -> None:
    op.execute(
        f"ALTER TABLE fin_operation DROP CONSTRAINT IF EXISTS {_CONSTRAINT_NAME}"
    )
