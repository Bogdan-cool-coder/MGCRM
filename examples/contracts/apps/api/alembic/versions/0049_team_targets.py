"""add team_targets table

Revision ID: 0049_team_targets
Revises: 0048_commission_rules
Create Date: 2026-06-02 10:10:00

Epic 10.5 — TeamTarget: плановые цели команды (отдела) на месяц.
Используются для расчёта командного бонуса (60/40% split).
"""

from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0049_team_targets"
down_revision: Union[str, Sequence[str], None] = "0048_commission_rules"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "team_targets",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column(
            "department_id",
            sa.Integer,
            sa.ForeignKey("departments.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "pipeline_id",
            sa.Integer,
            sa.ForeignKey("pipelines.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("period_year", sa.Integer, nullable=False),
        sa.Column("period_month", sa.Integer, nullable=False),
        sa.Column("metric", sa.String(32), nullable=False),
        sa.Column("target_amount", sa.Numeric(15, 2), nullable=False),
        sa.Column(
            "target_currency", sa.String(8), nullable=False, server_default="RUB"
        ),
        sa.Column("bonus_pool_amount", sa.Numeric(15, 2), nullable=True),
        sa.Column(
            "bonus_pool_currency", sa.String(8), nullable=True, server_default="KZT"
        ),
        sa.Column(
            "bonus_per_additional_member",
            sa.Numeric(15, 2),
            nullable=True,
            server_default="100000",
        ),
        sa.Column(
            "bonus_min_completion_pct",
            sa.Numeric(5, 2),
            nullable=False,
            server_default="80.00",
        ),
        sa.Column(
            "bonus_split_proportional_pct",
            sa.Numeric(5, 2),
            nullable=False,
            server_default="60.00",
        ),
        sa.Column(
            "bonus_split_equal_pct",
            sa.Numeric(5, 2),
            nullable=False,
            server_default="40.00",
        ),
        sa.Column(
            "is_active", sa.Boolean, nullable=False, server_default="true"
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.UniqueConstraint(
            "department_id",
            "pipeline_id",
            "period_year",
            "period_month",
            "metric",
            name="uq_team_target_dept_pipeline_period_metric",
        ),
    )
    op.create_index(
        "idx_team_targets_period",
        "team_targets",
        ["period_year", "period_month"],
    )


def downgrade() -> None:
    op.drop_index("idx_team_targets_period", table_name="team_targets")
    op.drop_table("team_targets")
