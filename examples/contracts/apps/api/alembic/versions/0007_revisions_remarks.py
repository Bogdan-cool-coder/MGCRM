"""contract revisions + remarks

Revision ID: 0007_rev_remarks
Revises: 0006_stages_acc
Create Date: 2026-05-28
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision: str = "0007_rev_remarks"
down_revision: Union[str, None] = "0006_stages_acc"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "contract_revisions",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("contract_id", sa.Integer, sa.ForeignKey("contracts.id", ondelete="CASCADE"), nullable=False),
        sa.Column("version_number", sa.Integer, nullable=False),
        sa.Column("attempt", sa.Integer, nullable=False, server_default="1"),
        sa.Column("context_snapshot", postgresql.JSONB, nullable=False, server_default="{}"),
        sa.Column("template_version", sa.String(64), nullable=True),
        sa.Column("docx_path", sa.String(512), nullable=True),
        sa.Column("pdf_path", sa.String(512), nullable=True),
        sa.Column("note", sa.String(512), nullable=True),
        sa.Column("created_by_user_id", sa.Integer, sa.ForeignKey("users.id"), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.UniqueConstraint("contract_id", "version_number", name="uq_revision_contract_version"),
    )
    op.create_index("ix_contract_revisions_contract_id", "contract_revisions", ["contract_id"])

    op.create_table(
        "contract_remarks",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("contract_id", sa.Integer, sa.ForeignKey("contracts.id", ondelete="CASCADE"), nullable=False),
        sa.Column("attempt", sa.Integer, nullable=False, server_default="1"),
        sa.Column("stage_order", sa.Integer, nullable=False, server_default="0"),
        sa.Column("author_user_id", sa.Integer, sa.ForeignKey("users.id"), nullable=False),
        sa.Column("text", sa.Text, nullable=False),
        sa.Column("is_resolved", sa.Boolean, nullable=False, server_default=sa.false()),
        sa.Column("resolved_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("resolved_by_user_id", sa.Integer, sa.ForeignKey("users.id"), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_contract_remarks_contract_id", "contract_remarks", ["contract_id"])


def downgrade() -> None:
    op.drop_index("ix_contract_remarks_contract_id", table_name="contract_remarks")
    op.drop_table("contract_remarks")
    op.drop_index("ix_contract_revisions_contract_id", table_name="contract_revisions")
    op.drop_table("contract_revisions")
