"""Эпик 24 — Tasks v2: таблицы категорий задач.

Создаёт:
- task_categories — справочник категорий задач с настройками по умолчанию
  (исполнитель, наблюдатели, шаблон чек-листа, флаги ограничений)
- task_category_co_executors — соисполнители по умолчанию для категории
- task_category_auditors — проверяющие по умолчанию для категории
- task_category_observers — наблюдатели по умолчанию для категории
- task_category_checklist_items — пункты чек-листа шаблона категории

Также добавляет FK constraint на pipeline_stages.default_task_category_id
→ task_categories(id), обещанный миграцией 0055.

Defensive advisory-lock seed-key 57_993 (0xE289 — Epic 24 Task Categories).
DDL-only, без seed-данных.

Revision ID: 0057_task_categories  (21 chars ≤32 ✓)
Revises: 0056_category_options
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0057_task_categories"
down_revision: Union[str, None] = "0056_category_options"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE289 = 57_993 — Epic 24 Task Categories seed-key.
_SEED_LOCK_EPIC_24_CAT = 57_993


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_24_CAT},
    )

    # Основная таблица категорий задач.
    op.create_table(
        "task_categories",
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column("name", sa.String(128), nullable=False),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.Column(
            "default_executor_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "admin_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("description_template", sa.Text(), nullable=True),
        sa.Column(
            "restrict_close_without_result",
            sa.Boolean(),
            nullable=False,
            server_default="false",
        ),
        sa.Column(
            "auto_title_from_category",
            sa.Boolean(),
            nullable=False,
            server_default="false",
        ),
        sa.Column("required_file_count", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default="true"),
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
        sa.PrimaryKeyConstraint("id"),
    )

    # Junction: соисполнители по умолчанию для категории.
    op.create_table(
        "task_category_co_executors",
        sa.Column(
            "category_id",
            sa.Integer(),
            sa.ForeignKey("task_categories.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.PrimaryKeyConstraint("category_id", "user_id"),
    )

    # Junction: проверяющие по умолчанию для категории.
    op.create_table(
        "task_category_auditors",
        sa.Column(
            "category_id",
            sa.Integer(),
            sa.ForeignKey("task_categories.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.PrimaryKeyConstraint("category_id", "user_id"),
    )

    # Junction: наблюдатели по умолчанию для категории.
    op.create_table(
        "task_category_observers",
        sa.Column(
            "category_id",
            sa.Integer(),
            sa.ForeignKey("task_categories.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.PrimaryKeyConstraint("category_id", "user_id"),
    )

    # Пункты чек-листа шаблона категории.
    op.create_table(
        "task_category_checklist_items",
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column(
            "category_id",
            sa.Integer(),
            sa.ForeignKey("task_categories.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("title", sa.String(256), nullable=False),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index(
        "idx_task_cat_checklist_cat",
        "task_category_checklist_items",
        ["category_id", "sort_order"],
    )

    # FK constraint pipeline_stages.default_task_category_id → task_categories(id).
    # Колонка уже существует (добавлена в 0055 как plain Integer); добавляем FK.
    op.create_foreign_key(
        "fk_pipeline_stage_task_category",
        "pipeline_stages",
        "task_categories",
        ["default_task_category_id"],
        ["id"],
        ondelete="SET NULL",
    )


def downgrade() -> None:
    op.drop_constraint(
        "fk_pipeline_stage_task_category",
        "pipeline_stages",
        type_="foreignkey",
    )
    op.drop_index("idx_task_cat_checklist_cat", "task_category_checklist_items")
    op.drop_table("task_category_checklist_items")
    op.drop_table("task_category_observers")
    op.drop_table("task_category_auditors")
    op.drop_table("task_category_co_executors")
    op.drop_table("task_categories")
