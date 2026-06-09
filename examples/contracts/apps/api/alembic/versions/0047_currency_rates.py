"""add currency_rates table + user salary fields

Revision ID: 0047_currency_rates
Revises: 0046_merge_ai_history
Create Date: 2026-06-02 10:00:00

Epic 10.5 — Личный кабинет менеджера:
- currency_rates: хранение курсов валют (RUB/USD/EUR/KZT/UZS/AED)
- users: salary_currency, salary_country_code, employment_start_date

Advisory lock key: 047 — idempotent seed guard.
"""

from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0047_currency_rates"
down_revision: Union[str, Sequence[str], None] = "0046_merge_ai_history"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "currency_rates",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("from_currency", sa.String(8), nullable=False),
        sa.Column("to_currency", sa.String(8), nullable=False),
        sa.Column("rate", sa.Numeric(20, 8), nullable=False),
        sa.Column("rate_date", sa.Date, nullable=False),
        sa.Column("source", sa.String(32), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.UniqueConstraint(
            "from_currency", "to_currency", "rate_date",
            name="uq_currency_rate_from_to_date",
        ),
    )
    op.create_index(
        "idx_currency_rates_date",
        "currency_rates",
        [sa.text("rate_date DESC"), "from_currency", "to_currency"],
    )

    # Extend users with salary / employment fields
    op.add_column(
        "users",
        sa.Column(
            "salary_currency",
            sa.String(8),
            nullable=True,
            server_default="RUB",
        ),
    )
    op.add_column(
        "users",
        sa.Column("salary_country_code", sa.String(2), nullable=True),
    )
    op.add_column(
        "users",
        sa.Column("employment_start_date", sa.Date, nullable=True),
    )


def downgrade() -> None:
    op.drop_column("users", "employment_start_date")
    op.drop_column("users", "salary_country_code")
    op.drop_column("users", "salary_currency")
    op.drop_index("idx_currency_rates_date", table_name="currency_rates")
    op.drop_table("currency_rates")
