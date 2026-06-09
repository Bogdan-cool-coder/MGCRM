"""add salary_plans table

Revision ID: 0050_salary_plans
Revises: 0049_team_targets
Create Date: 2026-06-02 10:15:00

Epic 10.5 — SalaryPlan: план зарплаты конкретного менеджера на месяц.
Привязывает commission_rule + team_target к конкретному пользователю+периоду.
"""

from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0050_salary_plans"
down_revision: Union[str, Sequence[str], None] = "0049_team_targets"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "salary_plans",
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
        sa.Column(
            "supervisor_user_id",
            sa.Integer,
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("base_salary_amount", sa.Numeric(15, 2), nullable=False),
        sa.Column("base_salary_currency", sa.String(8), nullable=False),
        sa.Column("base_salary_payment_note", sa.Text, nullable=True),
        sa.Column(
            "commission_rule_id",
            sa.Integer,
            sa.ForeignKey("commission_rules.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "personal_income_plan_amount", sa.Numeric(15, 2), nullable=True
        ),
        sa.Column(
            "personal_income_plan_currency", sa.String(8), nullable=True
        ),
        sa.Column("personal_ftm_plan", sa.Integer, nullable=True),
        sa.Column(
            "team_target_id",
            sa.Integer,
            sa.ForeignKey("team_targets.id", ondelete="SET NULL"),
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
        sa.UniqueConstraint(
            "user_id", "period_year", "period_month",
            name="uq_salary_plan_user_period",
        ),
    )


def downgrade() -> None:
    op.drop_table("salary_plans")
