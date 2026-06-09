"""DEALS 2.0 (Ф0) — schema: новые поля этапов + новые таблицы + Activity-поле.

DDL-only (без seed-данных, ремап в 0075, миграция лидов в 0076). Добавляет:

pipeline_stages:
- hidden_by_default BOOL NOT NULL DEFAULT false — этап скрыт на канбане по умолч.
- parent_stage_id INT NULL FK pipeline_stages(id) ON DELETE SET NULL — подстатусы.
- stage_features JSON DEFAULT '[]' — фичи этапа (send_presentation/meeting_report/…).
- allowed_task_category_ids JSON DEFAULT '[]' — доступные типы задач этапа.
- won_gate BOOL NOT NULL DEFAULT false — гейт на signed_scan/оплату.

activities:
- meeting_report_json JSONB NULL — ответы конструктора отчёта о встрече.

Новые таблицы:
- lost_reasons (реестр причин отказа).
- pipeline_transitions (межворонночные переходы).
- meeting_report_questions / meeting_report_options (конструктор отчёта о встрече).

Advisory-lock seed-key 74_001 (DEALS 2.0 schema). DDL идемпотентен на уровне
alembic (один прогон), advisory-lock защищает concurrent api-реплики (scale=2).

Revision ID: 0074_deals2_schema  (18 chars ≤32 ✓)
Revises: 0073_company_cp_mirror
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0074_deals2_schema"
down_revision: Union[str, None] = "0073_company_cp_mirror"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_DEALS2_SCHEMA = 74_001


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_DEALS2_SCHEMA},
    )

    # ---- pipeline_stages: новые колонки ----
    op.add_column(
        "pipeline_stages",
        sa.Column(
            "hidden_by_default", sa.Boolean(),
            nullable=False, server_default=sa.text("false"),
        ),
    )
    op.add_column(
        "pipeline_stages",
        sa.Column("parent_stage_id", sa.Integer(), nullable=True),
    )
    op.create_foreign_key(
        "fk_pipeline_stages_parent_stage_id",
        "pipeline_stages", "pipeline_stages",
        ["parent_stage_id"], ["id"], ondelete="SET NULL",
    )
    op.create_index(
        "ix_pipeline_stages_parent_stage_id",
        "pipeline_stages", ["parent_stage_id"],
    )
    op.add_column(
        "pipeline_stages",
        sa.Column(
            "stage_features", sa.JSON(),
            nullable=False, server_default=sa.text("'[]'::json"),
        ),
    )
    op.add_column(
        "pipeline_stages",
        sa.Column(
            "allowed_task_category_ids", sa.JSON(),
            nullable=False, server_default=sa.text("'[]'::json"),
        ),
    )
    op.add_column(
        "pipeline_stages",
        sa.Column(
            "won_gate", sa.Boolean(),
            nullable=False, server_default=sa.text("false"),
        ),
    )

    # ---- activities: meeting_report_json ----
    op.add_column(
        "activities",
        sa.Column("meeting_report_json", sa.dialects.postgresql.JSONB(), nullable=True),
    )

    # ---- lost_reasons ----
    op.create_table(
        "lost_reasons",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(length=255), nullable=False),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.Column(
            "is_active", sa.Boolean(), nullable=False, server_default=sa.text("true")
        ),
        sa.Column(
            "created_at", sa.DateTime(timezone=True),
            server_default=sa.func.now(), nullable=False,
        ),
        sa.UniqueConstraint("name", name="uq_lost_reasons_name"),
    )

    # ---- pipeline_transitions ----
    op.create_table(
        "pipeline_transitions",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(length=255), nullable=False),
        sa.Column("from_stage_id", sa.Integer(), nullable=False),
        sa.Column("to_pipeline_id", sa.Integer(), nullable=False),
        sa.Column("to_stage_id", sa.Integer(), nullable=False),
        sa.Column(
            "conditions", sa.JSON(),
            nullable=False, server_default=sa.text("'{}'::json"),
        ),
        sa.Column(
            "is_active", sa.Boolean(), nullable=False, server_default=sa.text("true")
        ),
        sa.Column(
            "created_at", sa.DateTime(timezone=True),
            server_default=sa.func.now(), nullable=False,
        ),
        sa.ForeignKeyConstraint(
            ["from_stage_id"], ["pipeline_stages.id"], ondelete="CASCADE"
        ),
        sa.ForeignKeyConstraint(
            ["to_pipeline_id"], ["pipelines.id"], ondelete="CASCADE"
        ),
        sa.ForeignKeyConstraint(
            ["to_stage_id"], ["pipeline_stages.id"], ondelete="CASCADE"
        ),
    )
    op.create_index(
        "ix_pipeline_transitions_from_stage_id",
        "pipeline_transitions", ["from_stage_id"],
    )
    op.create_index(
        "ix_pipeline_transitions_to_pipeline_id",
        "pipeline_transitions", ["to_pipeline_id"],
    )

    # ---- meeting_report_questions ----
    op.create_table(
        "meeting_report_questions",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("pipeline_id", sa.Integer(), nullable=True),
        sa.Column("text", sa.Text(), nullable=False),
        sa.Column("kind", sa.String(length=16), nullable=False, server_default="text"),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.Column(
            "is_active", sa.Boolean(), nullable=False, server_default=sa.text("true")
        ),
        sa.Column(
            "created_at", sa.DateTime(timezone=True),
            server_default=sa.func.now(), nullable=False,
        ),
        sa.ForeignKeyConstraint(
            ["pipeline_id"], ["pipelines.id"], ondelete="CASCADE"
        ),
    )
    op.create_index(
        "ix_meeting_report_questions_pipeline_id",
        "meeting_report_questions", ["pipeline_id"],
    )

    # ---- meeting_report_options ----
    op.create_table(
        "meeting_report_options",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("question_id", sa.Integer(), nullable=False),
        sa.Column("text", sa.String(length=255), nullable=False),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.ForeignKeyConstraint(
            ["question_id"], ["meeting_report_questions.id"], ondelete="CASCADE"
        ),
    )
    op.create_index(
        "ix_meeting_report_options_question_id",
        "meeting_report_options", ["question_id"],
    )


def downgrade() -> None:
    op.drop_index("ix_meeting_report_options_question_id", "meeting_report_options")
    op.drop_table("meeting_report_options")

    op.drop_index("ix_meeting_report_questions_pipeline_id", "meeting_report_questions")
    op.drop_table("meeting_report_questions")

    op.drop_index("ix_pipeline_transitions_to_pipeline_id", "pipeline_transitions")
    op.drop_index("ix_pipeline_transitions_from_stage_id", "pipeline_transitions")
    op.drop_table("pipeline_transitions")

    op.drop_table("lost_reasons")

    op.drop_column("activities", "meeting_report_json")

    op.drop_column("pipeline_stages", "won_gate")
    op.drop_column("pipeline_stages", "allowed_task_category_ids")
    op.drop_column("pipeline_stages", "stage_features")
    op.drop_index("ix_pipeline_stages_parent_stage_id", "pipeline_stages")
    op.drop_constraint(
        "fk_pipeline_stages_parent_stage_id", "pipeline_stages", type_="foreignkey"
    )
    op.drop_column("pipeline_stages", "parent_stage_id")
    op.drop_column("pipeline_stages", "hidden_by_default")
