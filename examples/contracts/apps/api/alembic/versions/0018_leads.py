"""leads (CRM Lead): отдельная сущность для входящего трафика до квалификации

Эпик 1.0 (упрощённый): Lead-сущность + Lead pipeline. Без рефакторинга Counterparty
на Contact+Company (тот скоуп — эпики 1.1/1.2). Аддитивно.

Воронка лидов и её этапы НЕ создаются здесь — это делает `seed_lead_pipeline` в
сервисе (advisory-lock pattern, чтобы безопасно работало с concurrent api replicas).

Revision ID: 0018_leads
Revises: 0017_counterparty_city
Create Date: 2026-05-30
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0018_leads"
down_revision: Union[str, None] = "0017_counterparty_city"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "leads",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(length=255), nullable=False),
        sa.Column("contact_email", sa.String(length=255), nullable=True),
        sa.Column("contact_phone", sa.String(length=64), nullable=True),
        sa.Column("source", sa.String(length=32), nullable=False, server_default="manual"),
        sa.Column(
            "owner_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "pipeline_id",
            sa.Integer(),
            sa.ForeignKey("pipelines.id", ondelete="RESTRICT"),
            nullable=False,
        ),
        sa.Column(
            "stage_id",
            sa.Integer(),
            sa.ForeignKey("pipeline_stages.id", ondelete="RESTRICT"),
            nullable=False,
        ),
        sa.Column("status", sa.String(length=32), nullable=False, server_default="active"),
        sa.Column("tags", sa.JSON(), nullable=False, server_default="[]"),
        sa.Column("notes", sa.Text(), nullable=True),
        sa.Column(
            "converted_to_counterparty_id",
            sa.Integer(),
            sa.ForeignKey("counterparties.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("converted_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
    )
    op.create_index("ix_leads_contact_email", "leads", ["contact_email"])
    op.create_index("ix_leads_contact_phone", "leads", ["contact_phone"])
    op.create_index("ix_leads_owner_id", "leads", ["owner_id"])
    op.create_index("ix_leads_pipeline_id", "leads", ["pipeline_id"])
    op.create_index("ix_leads_stage_id", "leads", ["stage_id"])
    op.create_index("ix_leads_status", "leads", ["status"])


def downgrade() -> None:
    op.drop_index("ix_leads_status", table_name="leads")
    op.drop_index("ix_leads_stage_id", table_name="leads")
    op.drop_index("ix_leads_pipeline_id", table_name="leads")
    op.drop_index("ix_leads_owner_id", table_name="leads")
    op.drop_index("ix_leads_contact_phone", table_name="leads")
    op.drop_index("ix_leads_contact_email", table_name="leads")
    op.drop_table("leads")
