"""add motivational_cards table

Revision ID: 0051_motivational_cards
Revises: 0050_salary_plans
Create Date: 2026-06-02 10:20:00

Epic 10.5 — MotivationalCard: рассчитанная мотивационная карта за месяц.
Хранит plan snapshot + факт (оклад / комиссия / командный бонус) + итог в
локальной валюте менеджера. Статусы: draft / finalized / paid.
"""

from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0051_motivational_cards"
down_revision: Union[str, Sequence[str], None] = "0050_salary_plans"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "motivational_cards",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer,
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
            index=True,
        ),
        sa.Column("period_year", sa.Integer, nullable=False),
        sa.Column("period_month", sa.Integer, nullable=False),
        # Snapshot плана на момент расчёта
        sa.Column("plan_snapshot_json", JSONB, nullable=True),
        # Факт
        sa.Column("fact_base_salary_amount", sa.Numeric(15, 2), nullable=True),
        sa.Column("fact_commission_amount", sa.Numeric(15, 2), nullable=True),
        sa.Column("fact_commission_currency", sa.String(8), nullable=True),
        sa.Column("fact_commission_breakdown", JSONB, nullable=True),
        sa.Column(
            "fact_team_bonus_proportional_amount", sa.Numeric(15, 2), nullable=True
        ),
        sa.Column(
            "fact_team_bonus_equal_amount", sa.Numeric(15, 2), nullable=True
        ),
        # Итог в локальной валюте
        sa.Column("total_amount_local", sa.Numeric(15, 2), nullable=True),
        sa.Column("total_amount_currency_local", sa.String(8), nullable=True),
        # Курсы на дату расчёта
        sa.Column("exchange_rates_snapshot", JSONB, nullable=True),
        sa.Column("exchange_rates_date", sa.Date, nullable=True),
        # Метрики работы
        sa.Column(
            "ftm_count_fact", sa.Integer, nullable=False, server_default="0"
        ),
        sa.Column("new_income_amount_fact", sa.Numeric(15, 2), nullable=True),
        sa.Column("new_income_currency_fact", sa.String(8), nullable=True),
        # Статус
        sa.Column(
            "status", sa.String(16), nullable=False, server_default="draft"
        ),
        sa.Column("finalized_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column(
            "finalized_by_user_id",
            sa.Integer,
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("paid_at", sa.DateTime(timezone=True), nullable=True),
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
        sa.UniqueConstraint(
            "user_id", "period_year", "period_month",
            name="uq_motivational_card_user_period",
        ),
    )
    op.create_index(
        "idx_motivational_cards_status",
        "motivational_cards",
        ["status"],
    )


def downgrade() -> None:
    op.drop_index("idx_motivational_cards_status", table_name="motivational_cards")
    op.drop_table("motivational_cards")
