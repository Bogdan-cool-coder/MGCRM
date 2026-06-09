"""add contract_payments table + FTM fields on activities

Revision ID: 0052_contract_payments_ftm
Revises: 0051_motivational_cards
Create Date: 2026-06-02 10:25:00

Epic 10.5:
- contract_payments: отдельная таблица платежей по договорам (для расчёта комиссии)
- activities: поля FTM (First Time Meeting) — is_first_time_meeting + 4 флага
"""

from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0052_contract_payments_ftm"
down_revision: Union[str, Sequence[str], None] = "0051_motivational_cards"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "contract_payments",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column(
            "contract_id",
            sa.Integer,
            sa.ForeignKey("contracts.id", ondelete="CASCADE"),
            nullable=True,
            index=True,
        ),
        sa.Column(
            "counterparty_id",
            sa.Integer,
            sa.ForeignKey("counterparties.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("amount", sa.Numeric(15, 2), nullable=False),
        sa.Column("currency", sa.String(8), nullable=False),
        sa.Column("payment_date", sa.Date, nullable=False),
        sa.Column(
            "attributed_to_user_id",
            sa.Integer,
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "is_first_payment_from_counterparty",
            sa.Boolean,
            nullable=False,
            server_default="false",
        ),
        sa.Column("notes", sa.Text, nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
    )
    op.create_index(
        "idx_payments_user_date",
        "contract_payments",
        ["attributed_to_user_id", sa.text("payment_date DESC")],
    )
    op.create_index(
        "idx_payments_counterparty",
        "contract_payments",
        ["counterparty_id"],
    )

    # FTM fields on activities
    op.add_column(
        "activities",
        sa.Column(
            "is_first_time_meeting",
            sa.Boolean,
            nullable=False,
            server_default="false",
        ),
    )
    op.add_column(
        "activities",
        sa.Column(
            "ftm_decision_maker_attended",
            sa.Boolean,
            nullable=False,
            server_default="false",
        ),
    )
    op.add_column(
        "activities",
        sa.Column(
            "ftm_presentation_shown",
            sa.Boolean,
            nullable=False,
            server_default="false",
        ),
    )
    op.add_column(
        "activities",
        sa.Column("ftm_report_url", sa.Text, nullable=True),
    )
    op.add_column(
        "activities",
        sa.Column(
            "ftm_telegram_announced",
            sa.Boolean,
            nullable=False,
            server_default="false",
        ),
    )
    op.create_index(
        "idx_activities_ftm",
        "activities",
        ["is_first_time_meeting"],
        postgresql_where=sa.text("is_first_time_meeting = true"),
    )


def downgrade() -> None:
    op.drop_index("idx_activities_ftm", table_name="activities")
    op.drop_column("activities", "ftm_telegram_announced")
    op.drop_column("activities", "ftm_report_url")
    op.drop_column("activities", "ftm_presentation_shown")
    op.drop_column("activities", "ftm_decision_maker_attended")
    op.drop_column("activities", "is_first_time_meeting")
    op.drop_index("idx_payments_counterparty", table_name="contract_payments")
    op.drop_index("idx_payments_user_date", table_name="contract_payments")
    op.drop_table("contract_payments")
