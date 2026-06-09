"""Wave 2a: per-user dashboard_config JSON column

Хранилище под кастомизируемый дашборд (видимость + порядок виджетов).
Аддитивно: новая nullable JSONB-колонка на users. Existing rows получают NULL =
дефолтная раскладка. Схему JSON владеет фронт; бэкенд только хранит/валидирует
размер. Данные не сидим.

Revision ID: 0101_dashboard_cfg
Revises: 0100_status_rework
Create Date: 2026-06-04
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0101_dashboard_cfg"
down_revision: str | None = "0100_status_rework"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ (диапазон 92_xxx свободен).
_LOCK_DASHBOARD_CFG = 92_002


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_DASHBOARD_CFG}
    )
    # IF NOT EXISTS-семантику обеспечиваем проверкой через inspector: повторный
    # прогон на уже мигрированной БД (scale=2 параллельные старты) не упадёт.
    insp = sa.inspect(conn)
    cols = {c["name"] for c in insp.get_columns("users")}
    if "dashboard_config" not in cols:
        op.add_column(
            "users",
            sa.Column("dashboard_config", postgresql.JSONB(), nullable=True),
        )


def downgrade() -> None:
    op.drop_column("users", "dashboard_config")
