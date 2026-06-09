"""departments, pipelines, stages, deals, history + user.department_id + client_tasks.deal_id

Фаза 3b-1: конфигурируемые воронки сделок с per-этап доступами. Аддитивно.

Revision ID: 0014_deals_pipelines
Revises: 0013_crm_card
Create Date: 2026-05-29
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0014_deals_pipelines"
down_revision: Union[str, None] = "0013_crm_card"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "departments",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(length=128), nullable=False),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
    )
    op.create_table(
        "pipelines",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(length=128), nullable=False),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
    )
    op.create_table(
        "pipeline_stages",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("pipeline_id", sa.Integer(), sa.ForeignKey("pipelines.id", ondelete="CASCADE"), nullable=False),
        sa.Column("name", sa.String(length=128), nullable=False),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("color", sa.String(length=16), nullable=True),
        sa.Column("is_won", sa.Boolean(), nullable=False, server_default=sa.false()),
        sa.Column("is_lost", sa.Boolean(), nullable=False, server_default=sa.false()),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column("responsible_user_ids", sa.JSON(), nullable=False, server_default="[]"),
        sa.Column("task_types", sa.JSON(), nullable=False, server_default="[]"),
        sa.Column("visible_department_ids", sa.JSON(), nullable=False, server_default="[]"),
        sa.Column("visible_user_ids", sa.JSON(), nullable=False, server_default="[]"),
    )
    op.create_index("ix_pipeline_stages_pipeline_id", "pipeline_stages", ["pipeline_id"])

    op.create_table(
        "deals",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("pipeline_id", sa.Integer(), sa.ForeignKey("pipelines.id"), nullable=False),
        sa.Column("stage_id", sa.Integer(), sa.ForeignKey("pipeline_stages.id"), nullable=False),
        sa.Column("counterparty_id", sa.Integer(), sa.ForeignKey("counterparties.id", ondelete="SET NULL"), nullable=True),
        sa.Column("title", sa.String(length=255), nullable=False),
        sa.Column("amount", sa.Numeric(precision=18, scale=2), nullable=True),
        sa.Column("currency", sa.String(length=8), nullable=True),
        sa.Column("owner_user_id", sa.Integer(), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("contract_id", sa.Integer(), sa.ForeignKey("contracts.id", ondelete="SET NULL"), nullable=True),
        sa.Column("stage_changed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("closed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_deals_pipeline_id", "deals", ["pipeline_id"])
    op.create_index("ix_deals_stage_id", "deals", ["stage_id"])
    op.create_index("ix_deals_counterparty_id", "deals", ["counterparty_id"])
    op.create_index("ix_deals_owner_user_id", "deals", ["owner_user_id"])

    op.create_table(
        "deal_stage_history",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("deal_id", sa.Integer(), sa.ForeignKey("deals.id", ondelete="CASCADE"), nullable=False),
        sa.Column("from_stage_id", sa.Integer(), sa.ForeignKey("pipeline_stages.id"), nullable=True),
        sa.Column("to_stage_id", sa.Integer(), sa.ForeignKey("pipeline_stages.id"), nullable=False),
        sa.Column("user_id", sa.Integer(), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_deal_stage_history_deal_id", "deal_stage_history", ["deal_id"])

    op.add_column("users", sa.Column("department_id", sa.Integer(), sa.ForeignKey("departments.id", ondelete="SET NULL"), nullable=True))
    op.add_column("client_tasks", sa.Column("deal_id", sa.Integer(), sa.ForeignKey("deals.id", ondelete="CASCADE"), nullable=True))
    op.create_index("ix_client_tasks_deal_id", "client_tasks", ["deal_id"])


def downgrade() -> None:
    op.drop_index("ix_client_tasks_deal_id", table_name="client_tasks")
    op.drop_column("client_tasks", "deal_id")
    op.drop_column("users", "department_id")
    op.drop_index("ix_deal_stage_history_deal_id", table_name="deal_stage_history")
    op.drop_table("deal_stage_history")
    op.drop_index("ix_deals_owner_user_id", table_name="deals")
    op.drop_index("ix_deals_counterparty_id", table_name="deals")
    op.drop_index("ix_deals_stage_id", table_name="deals")
    op.drop_index("ix_deals_pipeline_id", table_name="deals")
    op.drop_table("deals")
    op.drop_index("ix_pipeline_stages_pipeline_id", table_name="pipeline_stages")
    op.drop_table("pipeline_stages")
    op.drop_table("pipelines")
    op.drop_table("departments")
