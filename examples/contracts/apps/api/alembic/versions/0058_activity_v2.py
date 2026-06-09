"""Эпик 24 — Tasks v2: расширение таблицы activities.

Добавляет в `activities`:
- category_id — FK на task_categories(id)
- parent_activity_id — иерархия задач (подзадачи)
- priority — low|normal|high|critical (default normal)
- status — new|in_progress|done|rejected (default new)
- is_closed — задача финально закрыта постановщиком
- progress_pct — % выполнения (0..100)
- planned_hours / actual_hours — плановые и фактические часы
- result_text — результат работы (required if restrict_close_without_result)
- tags — массив тегов (GIN-index)
- recurrence_rule — правило повторения (daily|weekly|monthly)
- recurrence_until — дата завершения серии повторений
- recurrence_parent_id — FK на activities(id), шаблон серии
- rejected_at / rejected_by_user_id — кто и когда отклонил
- color_label — цветовая метка (hex)
- is_favorite / is_pinned — персональные флаги

Создаёт:
- activity_collaborators — соисполнители / проверяющие / наблюдатели на активность
- activity_checklist_items — пункты чек-листа конкретной активности

Defensive advisory-lock seed-key 57_994 (0xE28A — Epic 24 Activity v2).
DDL-only, без seed-данных.

Revision ID: 0058_activity_v2  (15 chars ≤32 ✓)
Revises: 0057_task_categories
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import ARRAY, TEXT

revision: str = "0058_activity_v2"
down_revision: Union[str, None] = "0057_task_categories"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE28A = 57_994 — Epic 24 Activity v2 seed-key.
_SEED_LOCK_EPIC_24_ACT = 57_994


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_24_ACT},
    )

    # Расширяем activities новыми колонками.
    op.add_column(
        "activities",
        sa.Column(
            "category_id",
            sa.Integer(),
            sa.ForeignKey("task_categories.id", ondelete="SET NULL"),
            nullable=True,
        ),
    )
    op.add_column(
        "activities",
        sa.Column(
            "parent_activity_id",
            sa.Integer(),
            sa.ForeignKey("activities.id", ondelete="SET NULL"),
            nullable=True,
        ),
    )
    op.add_column(
        "activities",
        sa.Column(
            "priority",
            sa.String(16),
            nullable=False,
            server_default="normal",
        ),
    )
    op.add_column(
        "activities",
        sa.Column(
            "status",
            sa.String(16),
            nullable=False,
            server_default="new",
        ),
    )
    op.add_column(
        "activities",
        sa.Column(
            "is_closed",
            sa.Boolean(),
            nullable=False,
            server_default="false",
        ),
    )
    op.add_column(
        "activities",
        sa.Column(
            "progress_pct",
            sa.Integer(),
            nullable=False,
            server_default="0",
        ),
    )
    op.add_column(
        "activities",
        sa.Column("planned_hours", sa.Numeric(5, 2), nullable=True),
    )
    op.add_column(
        "activities",
        sa.Column("actual_hours", sa.Numeric(5, 2), nullable=True),
    )
    op.add_column(
        "activities",
        sa.Column("result_text", sa.Text(), nullable=True),
    )
    op.add_column(
        "activities",
        sa.Column(
            "tags",
            ARRAY(TEXT),
            nullable=True,
            server_default=sa.text("'{}'::text[]"),
        ),
    )
    op.add_column(
        "activities",
        sa.Column("recurrence_rule", sa.String(16), nullable=True),
    )
    op.add_column(
        "activities",
        sa.Column("recurrence_until", sa.Date(), nullable=True),
    )
    op.add_column(
        "activities",
        sa.Column(
            "recurrence_parent_id",
            sa.Integer(),
            sa.ForeignKey("activities.id", ondelete="SET NULL"),
            nullable=True,
        ),
    )
    op.add_column(
        "activities",
        sa.Column("rejected_at", sa.DateTime(timezone=True), nullable=True),
    )
    op.add_column(
        "activities",
        sa.Column(
            "rejected_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
    )
    op.add_column(
        "activities",
        sa.Column("color_label", sa.String(8), nullable=True),
    )
    op.add_column(
        "activities",
        sa.Column(
            "is_favorite",
            sa.Boolean(),
            nullable=False,
            server_default="false",
        ),
    )
    op.add_column(
        "activities",
        sa.Column(
            "is_pinned",
            sa.Boolean(),
            nullable=False,
            server_default="false",
        ),
    )

    # Составные / специализированные индексы на activities.
    op.create_index(
        "idx_activities_status_due",
        "activities",
        ["status", "due_at"],
        postgresql_where=sa.text("NOT is_closed"),
    )
    op.create_index(
        "idx_activities_parent",
        "activities",
        ["parent_activity_id"],
    )
    op.create_index(
        "idx_activities_category",
        "activities",
        ["category_id"],
    )
    op.create_index(
        "idx_activities_tags_gin",
        "activities",
        ["tags"],
        postgresql_using="gin",
    )

    # activity_collaborators — соисполнители / проверяющие / наблюдатели.
    op.create_table(
        "activity_collaborators",
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column(
            "activity_id",
            sa.Integer(),
            sa.ForeignKey("activities.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "role",
            sa.String(16),
            nullable=False,
        ),  # co_executor|auditor|observer
        sa.Column(
            "added_at",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.PrimaryKeyConstraint("id"),
        sa.UniqueConstraint("activity_id", "user_id", "role", name="uq_collab_activity_user_role"),
    )
    op.create_index("idx_collab_activity", "activity_collaborators", ["activity_id"])
    op.create_index("idx_collab_user_role", "activity_collaborators", ["user_id", "role"])

    # activity_checklist_items — пункты чек-листа конкретной активности.
    op.create_table(
        "activity_checklist_items",
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column(
            "activity_id",
            sa.Integer(),
            sa.ForeignKey("activities.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("title", sa.String(512), nullable=False),
        sa.Column(
            "is_done",
            sa.Boolean(),
            nullable=False,
            server_default="false",
        ),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.Column(
            "completed_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("completed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index(
        "idx_checklist_activity",
        "activity_checklist_items",
        ["activity_id", "sort_order"],
    )


def downgrade() -> None:
    op.drop_index("idx_checklist_activity", "activity_checklist_items")
    op.drop_table("activity_checklist_items")
    op.drop_index("idx_collab_user_role", "activity_collaborators")
    op.drop_index("idx_collab_activity", "activity_collaborators")
    op.drop_table("activity_collaborators")

    op.drop_index("idx_activities_tags_gin", "activities")
    op.drop_index("idx_activities_category", "activities")
    op.drop_index("idx_activities_parent", "activities")
    op.drop_index("idx_activities_status_due", "activities")

    for col in (
        "is_pinned",
        "is_favorite",
        "color_label",
        "rejected_by_user_id",
        "rejected_at",
        "recurrence_parent_id",
        "recurrence_until",
        "recurrence_rule",
        "tags",
        "result_text",
        "actual_hours",
        "planned_hours",
        "progress_pct",
        "is_closed",
        "status",
        "priority",
        "parent_activity_id",
        "category_id",
    ):
        op.drop_column("activities", col)
