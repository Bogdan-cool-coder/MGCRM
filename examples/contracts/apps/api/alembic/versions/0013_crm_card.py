"""contacts, client_notes, client_tasks (CRM-карточка клиента, Фаза 3a)

Аддитивно — safe при rolling-деплое.

Revision ID: 0013_crm_card
Revises: 0012_client_categories
Create Date: 2026-05-29
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0013_crm_card"
down_revision: Union[str, None] = "0012_client_categories"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "contacts",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("counterparty_id", sa.Integer(), sa.ForeignKey("counterparties.id", ondelete="CASCADE"), nullable=False),
        sa.Column("name", sa.String(length=255), nullable=False),
        sa.Column("position", sa.String(length=128), nullable=True),
        sa.Column("phone", sa.String(length=64), nullable=True),
        sa.Column("email", sa.String(length=255), nullable=True),
        sa.Column("messenger", sa.String(length=128), nullable=True),
        sa.Column("is_primary", sa.Boolean(), nullable=False, server_default=sa.false()),
        sa.Column("note", sa.Text(), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_contacts_counterparty_id", "contacts", ["counterparty_id"])

    op.create_table(
        "client_notes",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("counterparty_id", sa.Integer(), sa.ForeignKey("counterparties.id", ondelete="CASCADE"), nullable=False),
        sa.Column("author_user_id", sa.Integer(), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("text", sa.Text(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_client_notes_counterparty_id", "client_notes", ["counterparty_id"])

    op.create_table(
        "client_tasks",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("counterparty_id", sa.Integer(), sa.ForeignKey("counterparties.id", ondelete="CASCADE"), nullable=False),
        sa.Column("title", sa.String(length=255), nullable=False),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("task_type", sa.String(length=32), nullable=False, server_default="other"),
        sa.Column("assignee_user_id", sa.Integer(), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("due_date", sa.Date(), nullable=True),
        sa.Column("status", sa.String(length=16), nullable=False, server_default="open"),
        sa.Column("done_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_by_user_id", sa.Integer(), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_client_tasks_counterparty_id", "client_tasks", ["counterparty_id"])
    op.create_index("ix_client_tasks_assignee_user_id", "client_tasks", ["assignee_user_id"])


def downgrade() -> None:
    op.drop_index("ix_client_tasks_assignee_user_id", table_name="client_tasks")
    op.drop_index("ix_client_tasks_counterparty_id", table_name="client_tasks")
    op.drop_table("client_tasks")
    op.drop_index("ix_client_notes_counterparty_id", table_name="client_notes")
    op.drop_table("client_notes")
    op.drop_index("ix_contacts_counterparty_id", table_name="contacts")
    op.drop_table("contacts")
