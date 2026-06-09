"""activities (Эпик 2): полиморфная Activity (call/meeting/task/note) + Timeline.

Эпик 2 — задачи/звонки/встречи/заметки для любой сущности (Lead, Contact, Company,
Counterparty, Deal, Contract, Subscription). Polymorphic через (target_type, target_id):
direct FK не используется намеренно, чтобы одна таблица закрывала все 7 типов целей
без перекрёстных миграций при добавлении новой сущности.

Композитный индекс (target_type, target_id) — критичный, по нему строится Timeline
для конкретной карточки (запрос: WHERE target_type=? AND target_id=? ORDER BY created_at DESC).
Отдельные индексы на kind/responsible_id/due_at/completed_at — под фильтры на /activities.

Revision ID: 0020_activities
Revises: 0019_contact_company
Create Date: 2026-05-30
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0020_activities"
down_revision: Union[str, None] = "0019_contact_company"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "activities",
        sa.Column("id", sa.Integer(), primary_key=True),
        # kind: call / meeting / task / note (валидация на уровне роутера/сервиса)
        sa.Column("kind", sa.String(length=16), nullable=False),
        # target_type: lead / contact / company / counterparty / deal / contract / subscription
        sa.Column("target_type", sa.String(length=32), nullable=False),
        sa.Column("target_id", sa.Integer(), nullable=False),
        sa.Column("title", sa.String(length=255), nullable=False),
        sa.Column("body", sa.Text(), nullable=True),
        sa.Column("due_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("completed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column(
            "completed_by_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "responsible_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "created_by_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=False,
        ),
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
    # Композитный индекс — горячий путь Timeline (по карточке сущности)
    op.create_index(
        "ix_activities_target_type_target_id",
        "activities",
        ["target_type", "target_id"],
    )
    op.create_index("ix_activities_kind", "activities", ["kind"])
    op.create_index("ix_activities_responsible_id", "activities", ["responsible_id"])
    op.create_index("ix_activities_due_at", "activities", ["due_at"])
    op.create_index("ix_activities_completed_at", "activities", ["completed_at"])


def downgrade() -> None:
    op.drop_index("ix_activities_completed_at", table_name="activities")
    op.drop_index("ix_activities_due_at", table_name="activities")
    op.drop_index("ix_activities_responsible_id", table_name="activities")
    op.drop_index("ix_activities_kind", table_name="activities")
    op.drop_index("ix_activities_target_type_target_id", table_name="activities")
    op.drop_table("activities")
