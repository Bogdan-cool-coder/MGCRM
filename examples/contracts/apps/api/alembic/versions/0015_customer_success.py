"""customer success registry: platforms, regions, modules, checklist templates,
client_subscriptions, subscription_modules, implementation_item_status,
activity_snapshots, registry_kpi_snapshots + pipeline_stages.code

Фаза 4a: структура реестра клиентов (Customer Success). Аддитивно.

Revision ID: 0015_customer_success
Revises: 0014_deals_pipelines
Create Date: 2026-05-29
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0015_customer_success"
down_revision: Union[str, None] = "0014_deals_pipelines"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # --- код этапа воронки (для маппинга тира активности на этап ЖЦ) ---
    op.add_column("pipeline_stages", sa.Column("code", sa.String(length=16), nullable=True))
    op.create_index("ix_pipeline_stages_code", "pipeline_stages", ["code"])

    # --- справочники ---
    op.create_table(
        "platforms",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("code", sa.String(length=32), nullable=False, unique=True),
        sa.Column("name", sa.String(length=128), nullable=False),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()),
    )
    op.create_table(
        "regions",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("code", sa.String(length=16), nullable=False, unique=True),
        sa.Column("name", sa.String(length=128), nullable=False),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
    )
    op.create_table(
        "modules",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("code", sa.String(length=48), nullable=False),
        sa.Column("name", sa.String(length=128), nullable=False),
        sa.Column("platform_id", sa.Integer(), sa.ForeignKey("platforms.id", ondelete="CASCADE"), nullable=True),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.UniqueConstraint("platform_id", "code", name="uq_module_platform_code"),
    )
    op.create_index("ix_modules_code", "modules", ["code"])
    op.create_index("ix_modules_platform_id", "modules", ["platform_id"])

    op.create_table(
        "checklist_templates",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("platform_id", sa.Integer(), sa.ForeignKey("platforms.id", ondelete="CASCADE"), nullable=False),
        sa.Column("name", sa.String(length=128), nullable=False),
    )
    op.create_index("ix_checklist_templates_platform_id", "checklist_templates", ["platform_id"])

    op.create_table(
        "checklist_template_items",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("template_id", sa.Integer(), sa.ForeignKey("checklist_templates.id", ondelete="CASCADE"), nullable=False),
        sa.Column("code", sa.String(length=64), nullable=False),
        sa.Column("label", sa.String(length=255), nullable=False),
        sa.Column("group", sa.String(length=64), nullable=True),
        sa.Column("kind", sa.String(length=16), nullable=False, server_default="status"),
        sa.Column("optional", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
    )
    op.create_index("ix_checklist_template_items_template_id", "checklist_template_items", ["template_id"])

    # --- подписки (центральная сущность) ---
    op.create_table(
        "client_subscriptions",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("counterparty_id", sa.Integer(), sa.ForeignKey("counterparties.id", ondelete="CASCADE"), nullable=False),
        sa.Column("platform_id", sa.Integer(), sa.ForeignKey("platforms.id"), nullable=False),
        sa.Column("region_id", sa.Integer(), sa.ForeignKey("regions.id", ondelete="SET NULL"), nullable=True),
        sa.Column("external_client_id", sa.String(length=128), nullable=True),
        sa.Column("lifecycle_stage_id", sa.Integer(), sa.ForeignKey("pipeline_stages.id", ondelete="SET NULL"), nullable=True),
        sa.Column("stage_changed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("imp_pm_user_id", sa.Integer(), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("sup_pm_user_id", sa.Integer(), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("am_user_id", sa.Integer(), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("team_names", sa.JSON(), nullable=False, server_default="{}"),
        sa.Column("seats", sa.Integer(), nullable=True),
        sa.Column("fee_actual", sa.Numeric(precision=18, scale=2), nullable=True),
        sa.Column("fee_contract", sa.Numeric(precision=18, scale=2), nullable=True),
        sa.Column("fee_currency", sa.String(length=8), nullable=True),
        sa.Column("tariff", sa.String(length=64), nullable=True),
        sa.Column("discount_until", sa.Date(), nullable=True),
        sa.Column("auto_prolongation", sa.Boolean(), nullable=False, server_default=sa.false()),
        sa.Column("on_premise", sa.Boolean(), nullable=False, server_default=sa.false()),
        sa.Column("last_fee_increase_at", sa.Date(), nullable=True),
        sa.Column("impl_start_date", sa.Date(), nullable=True),
        sa.Column("act_signed_date", sa.Date(), nullable=True),
        sa.Column("impl_pct", sa.Numeric(precision=5, scale=2), nullable=True),
        sa.Column("qa_result", sa.String(length=32), nullable=True),
        sa.Column("qa_date", sa.Date(), nullable=True),
        sa.Column("health_tier", sa.String(length=8), nullable=True),
        sa.Column("health_score", sa.Numeric(precision=8, scale=2), nullable=True),
        sa.Column("activity_avg", sa.Numeric(precision=12, scale=2), nullable=True),
        sa.Column("activity_trend_pct", sa.Numeric(precision=8, scale=2), nullable=True),
        sa.Column("dormant_periods", sa.Integer(), nullable=True),
        sa.Column("health_reasons", sa.JSON(), nullable=False, server_default="[]"),
        sa.Column("manual_tier_override", sa.String(length=8), nullable=True),
        sa.Column("health_computed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column("notes", sa.Text(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.UniqueConstraint("counterparty_id", "platform_id", "region_id", name="uq_subscription_cp_platform_region"),
    )
    op.create_index("ix_client_subscriptions_counterparty_id", "client_subscriptions", ["counterparty_id"])
    op.create_index("ix_client_subscriptions_platform_id", "client_subscriptions", ["platform_id"])
    op.create_index("ix_client_subscriptions_region_id", "client_subscriptions", ["region_id"])
    op.create_index("ix_client_subscriptions_lifecycle_stage_id", "client_subscriptions", ["lifecycle_stage_id"])
    op.create_index("ix_client_subscriptions_external_client_id", "client_subscriptions", ["external_client_id"])
    op.create_index("ix_client_subscriptions_health_tier", "client_subscriptions", ["health_tier"])

    op.create_table(
        "subscription_modules",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("subscription_id", sa.Integer(), sa.ForeignKey("client_subscriptions.id", ondelete="CASCADE"), nullable=False),
        sa.Column("module_id", sa.Integer(), sa.ForeignKey("modules.id", ondelete="CASCADE"), nullable=False),
        sa.Column("enabled", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column("status", sa.String(length=32), nullable=True),
        sa.UniqueConstraint("subscription_id", "module_id", name="uq_sub_module"),
    )
    op.create_index("ix_subscription_modules_subscription_id", "subscription_modules", ["subscription_id"])
    op.create_index("ix_subscription_modules_module_id", "subscription_modules", ["module_id"])

    op.create_table(
        "implementation_item_status",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("subscription_id", sa.Integer(), sa.ForeignKey("client_subscriptions.id", ondelete="CASCADE"), nullable=False),
        sa.Column("template_item_id", sa.Integer(), sa.ForeignKey("checklist_template_items.id", ondelete="CASCADE"), nullable=False),
        sa.Column("status", sa.String(length=16), nullable=False, server_default="not_started"),
        sa.Column("num_done", sa.Integer(), nullable=True),
        sa.Column("num_total", sa.Integer(), nullable=True),
        sa.Column("pct", sa.Numeric(precision=5, scale=4), nullable=True),
        sa.Column("value_date", sa.Date(), nullable=True),
        sa.Column("note", sa.Text(), nullable=True),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.UniqueConstraint("subscription_id", "template_item_id", name="uq_impl_item"),
    )
    op.create_index("ix_implementation_item_status_subscription_id", "implementation_item_status", ["subscription_id"])
    op.create_index("ix_implementation_item_status_template_item_id", "implementation_item_status", ["template_item_id"])

    op.create_table(
        "activity_snapshots",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("subscription_id", sa.Integer(), sa.ForeignKey("client_subscriptions.id", ondelete="CASCADE"), nullable=False),
        sa.Column("period_start", sa.Date(), nullable=False),
        sa.Column("period_end", sa.Date(), nullable=True),
        sa.Column("metric", sa.String(length=32), nullable=False, server_default="actions"),
        sa.Column("value", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("source", sa.String(length=16), nullable=False, server_default="manual"),
        sa.Column("ingested_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.UniqueConstraint("subscription_id", "period_start", "metric", name="uq_activity_period"),
    )
    op.create_index("ix_activity_snapshots_subscription_id", "activity_snapshots", ["subscription_id"])
    op.create_index("ix_activity_snapshots_period_start", "activity_snapshots", ["period_start"])

    op.create_table(
        "registry_kpi_snapshots",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("snapshot_date", sa.Date(), nullable=False),
        sa.Column("platform_id", sa.Integer(), sa.ForeignKey("platforms.id", ondelete="CASCADE"), nullable=True),
        sa.Column("region_id", sa.Integer(), sa.ForeignKey("regions.id", ondelete="SET NULL"), nullable=True),
        sa.Column("metrics", sa.JSON(), nullable=False, server_default="{}"),
        sa.UniqueConstraint("snapshot_date", "platform_id", "region_id", name="uq_kpi_snapshot"),
    )
    op.create_index("ix_registry_kpi_snapshots_snapshot_date", "registry_kpi_snapshots", ["snapshot_date"])


def downgrade() -> None:
    op.drop_table("registry_kpi_snapshots")
    op.drop_index("ix_activity_snapshots_period_start", table_name="activity_snapshots")
    op.drop_index("ix_activity_snapshots_subscription_id", table_name="activity_snapshots")
    op.drop_table("activity_snapshots")
    op.drop_index("ix_implementation_item_status_template_item_id", table_name="implementation_item_status")
    op.drop_index("ix_implementation_item_status_subscription_id", table_name="implementation_item_status")
    op.drop_table("implementation_item_status")
    op.drop_index("ix_subscription_modules_module_id", table_name="subscription_modules")
    op.drop_index("ix_subscription_modules_subscription_id", table_name="subscription_modules")
    op.drop_table("subscription_modules")
    for ix in (
        "ix_client_subscriptions_health_tier",
        "ix_client_subscriptions_external_client_id",
        "ix_client_subscriptions_lifecycle_stage_id",
        "ix_client_subscriptions_region_id",
        "ix_client_subscriptions_platform_id",
        "ix_client_subscriptions_counterparty_id",
    ):
        op.drop_index(ix, table_name="client_subscriptions")
    op.drop_table("client_subscriptions")
    op.drop_index("ix_checklist_template_items_template_id", table_name="checklist_template_items")
    op.drop_table("checklist_template_items")
    op.drop_index("ix_checklist_templates_platform_id", table_name="checklist_templates")
    op.drop_table("checklist_templates")
    op.drop_index("ix_modules_platform_id", table_name="modules")
    op.drop_index("ix_modules_code", table_name="modules")
    op.drop_table("modules")
    op.drop_table("regions")
    op.drop_table("platforms")
    op.drop_index("ix_pipeline_stages_code", table_name="pipeline_stages")
    op.drop_column("pipeline_stages", "code")
