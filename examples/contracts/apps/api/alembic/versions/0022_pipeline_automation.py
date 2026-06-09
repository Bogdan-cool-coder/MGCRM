"""pipeline_automations + automation_runs (Эпик 4): универсальный движок автоматизаций.

Эпик 4 — PipelineAutomation Engine, центральная «нервная система» CRM. Триггеры
(on_enter_stage / idle_in_stage_days / date_field_approaching) запускают действия
(tg_notify / create_task / set_field / generate_document) на сделках, лидах и
подписках. Работает на любой воронке (sales / lifecycle / lead / renewal).

Архитектурно:
- pipeline_automations — описание автоматизации (триггер + действие + конфиг).
  stage_id NULL = автоматизация работает на всех этапах указанной воронки.
- automation_runs — журнал выполнений (audit + защита от повторного срабатывания
  для cron-триггеров: idle_in_stage_days / date_field_approaching).

Индексы:
- pipeline_automations.(pipeline_id) — главный фильтр на «автоматизации воронки»;
- (stage_id) — для on_enter_stage executor (фильтр по этапу);
- (trigger_kind) — для cron сканера (выборка idle / date_field);
- (is_active) — обязателен на всех путях, неактивные не запускаются.
- automation_runs.(automation_id) — история выполнений автоматизации;
- (target_type, target_id) — история по сущности;
- (status), (started_at) — фильтры списка.

Миграция аддитивная (schema-only, без seed) — advisory-lock НЕ нужен. См.
backend-specialist.md: «advisory-lock seed-key — только если миграция сидит данные
или создаёт уникальные значения по умолчанию».

Revision ID: 0022_pipeline_automation
Revises: 0021_tpl_categories
Create Date: 2026-05-30
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0022_pipeline_automation"
down_revision: Union[str, None] = "0021_tpl_categories"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ---- pipeline_automations ----
    op.create_table(
        "pipeline_automations",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(length=255), nullable=False),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column(
            "pipeline_id",
            sa.Integer(),
            sa.ForeignKey("pipelines.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "stage_id",
            sa.Integer(),
            sa.ForeignKey("pipeline_stages.id", ondelete="CASCADE"),
            nullable=True,
        ),
        # 'on_enter_stage' | 'idle_in_stage_days' | 'date_field_approaching'
        sa.Column("trigger_kind", sa.String(length=32), nullable=False),
        sa.Column(
            "trigger_config",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'{}'::json"),
        ),
        # 'tg_notify' | 'create_task' | 'set_field' | 'generate_document'
        sa.Column("action_kind", sa.String(length=32), nullable=False),
        sa.Column(
            "action_config",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'{}'::json"),
        ),
        sa.Column(
            "is_active",
            sa.Boolean(),
            nullable=False,
            server_default=sa.true(),
        ),
        sa.Column(
            "created_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("last_run_at", sa.DateTime(timezone=True), nullable=True),
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
    op.create_index(
        "ix_pipeline_automations_pipeline_id",
        "pipeline_automations",
        ["pipeline_id"],
    )
    op.create_index(
        "ix_pipeline_automations_stage_id",
        "pipeline_automations",
        ["stage_id"],
    )
    op.create_index(
        "ix_pipeline_automations_trigger_kind",
        "pipeline_automations",
        ["trigger_kind"],
    )
    op.create_index(
        "ix_pipeline_automations_is_active",
        "pipeline_automations",
        ["is_active"],
    )

    # ---- automation_runs ----
    op.create_table(
        "automation_runs",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "automation_id",
            sa.Integer(),
            sa.ForeignKey("pipeline_automations.id", ondelete="CASCADE"),
            nullable=False,
        ),
        # 'deal' | 'lead' | 'subscription'
        sa.Column("target_type", sa.String(length=32), nullable=False),
        sa.Column("target_id", sa.Integer(), nullable=False),
        # 'pending' | 'success' | 'failed' | 'skipped'
        sa.Column("status", sa.String(length=16), nullable=False),
        sa.Column(
            "started_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("error_text", sa.Text(), nullable=True),
        sa.Column("result_json", sa.JSON(), nullable=True),
    )
    op.create_index(
        "ix_automation_runs_automation_id",
        "automation_runs",
        ["automation_id"],
    )
    op.create_index(
        "ix_automation_runs_target",
        "automation_runs",
        ["target_type", "target_id"],
    )
    op.create_index(
        "ix_automation_runs_status",
        "automation_runs",
        ["status"],
    )
    op.create_index(
        "ix_automation_runs_started_at",
        "automation_runs",
        ["started_at"],
    )


def downgrade() -> None:
    op.drop_index("ix_automation_runs_started_at", table_name="automation_runs")
    op.drop_index("ix_automation_runs_status", table_name="automation_runs")
    op.drop_index("ix_automation_runs_target", table_name="automation_runs")
    op.drop_index("ix_automation_runs_automation_id", table_name="automation_runs")
    op.drop_table("automation_runs")

    op.drop_index("ix_pipeline_automations_is_active", table_name="pipeline_automations")
    op.drop_index("ix_pipeline_automations_trigger_kind", table_name="pipeline_automations")
    op.drop_index("ix_pipeline_automations_stage_id", table_name="pipeline_automations")
    op.drop_index("ix_pipeline_automations_pipeline_id", table_name="pipeline_automations")
    op.drop_table("pipeline_automations")
