"""add commission_rules table

Revision ID: 0048_commission_rules
Revises: 0047_currency_rates
Create Date: 2026-06-02 10:05:00

Epic 10.5 — CommissionRule: правила начисления комиссии менеджерам.
Пример: «10% от новых поступлений, зачисленных на РС в текущем месяце».
"""

from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0048_commission_rules"
down_revision: Union[str, Sequence[str], None] = "0047_currency_rates"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "commission_rules",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("name", sa.String(128), nullable=False),
        sa.Column("rate_pct", sa.Numeric(5, 2), nullable=False),
        sa.Column("base_metric", sa.String(32), nullable=False),
        sa.Column(
            "base_currency", sa.String(8), nullable=False, server_default="RUB"
        ),
        sa.Column("scope", sa.String(32), nullable=False),
        sa.Column(
            "applies_to_first_payment_only",
            sa.Boolean,
            nullable=False,
            server_default="true",
        ),
        sa.Column(
            "requires_signed_contract",
            sa.Boolean,
            nullable=False,
            server_default="true",
        ),
        sa.Column(
            "requires_amount_match_payment_plan",
            sa.Boolean,
            nullable=False,
            server_default="true",
        ),
        sa.Column(
            "payment_trigger",
            sa.String(32),
            nullable=True,
            server_default="immediate",
        ),
        sa.Column("payment_note", sa.Text, nullable=True),
        sa.Column(
            "is_active", sa.Boolean, nullable=False, server_default="true"
        ),
        sa.Column(
            "created_by_user_id",
            sa.Integer,
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
    )


def downgrade() -> None:
    op.drop_table("commission_rules")
