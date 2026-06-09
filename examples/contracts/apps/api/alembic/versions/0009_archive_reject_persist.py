"""archived_at + reject_prompt_message_id

Архивация договора (Contract.archived_at) и персист маппинга reply→approval
для причины отклонения в Telegram (Approval.reject_prompt_message_id).

Revision ID: 0009_arch_reject
Revises: 0008_tpl_vars
Create Date: 2026-05-28
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0009_arch_reject"
down_revision: Union[str, None] = "0008_tpl_vars"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column("contracts", sa.Column("archived_at", sa.DateTime(timezone=True), nullable=True))
    op.add_column("approvals", sa.Column("reject_prompt_message_id", sa.BigInteger(), nullable=True))
    op.create_index(
        "ix_approvals_reject_prompt_message_id", "approvals", ["reject_prompt_message_id"]
    )


def downgrade() -> None:
    op.drop_index("ix_approvals_reject_prompt_message_id", table_name="approvals")
    op.drop_column("approvals", "reject_prompt_message_id")
    op.drop_column("contracts", "archived_at")
