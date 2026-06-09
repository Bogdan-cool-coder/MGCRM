"""approval stages + licensor bank accounts

Revision ID: 0006_stages_acc
Revises: 0005_cp_contacts
Create Date: 2026-05-26
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0006_stages_acc"
down_revision: Union[str, None] = "0005_cp_contacts"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column("approval_routes", sa.Column("stages", sa.JSON, nullable=False, server_default="[]"))
    op.add_column("approvals", sa.Column("stage_order", sa.Integer, nullable=False, server_default="0"))
    op.add_column("approvals", sa.Column("attempt", sa.Integer, nullable=False, server_default="1"))
    # unique constraint больше не подходит — теперь user может голосовать в разных attempts
    op.drop_constraint("uq_approval_contract_user", "approvals", type_="unique")
    op.create_unique_constraint(
        "uq_approval_contract_user_attempt",
        "approvals",
        ["contract_id", "user_id", "attempt"],
    )

    op.create_table(
        "licensor_bank_accounts",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("licensor_id", sa.Integer, sa.ForeignKey("licensor_entities.id", ondelete="CASCADE"), nullable=False),
        sa.Column("currency", sa.String(8), nullable=False),
        sa.Column("bank", sa.String(255), nullable=False),
        sa.Column("bank_code_label", sa.String(32), nullable=False),
        sa.Column("bank_code", sa.String(64), nullable=False),
        sa.Column("account", sa.String(64), nullable=False),
        sa.Column("swift", sa.String(32), nullable=True),
        sa.Column("is_primary", sa.Boolean, nullable=False, server_default=sa.false()),
        sa.Column("note", sa.String(255), nullable=True),
    )
    op.create_index("ix_licensor_bank_accounts_licensor_id", "licensor_bank_accounts", ["licensor_id"])


def downgrade() -> None:
    op.drop_index("ix_licensor_bank_accounts_licensor_id", table_name="licensor_bank_accounts")
    op.drop_table("licensor_bank_accounts")
    op.drop_constraint("uq_approval_contract_user_attempt", "approvals", type_="unique")
    op.create_unique_constraint("uq_approval_contract_user", "approvals", ["contract_id", "user_id"])
    op.drop_column("approvals", "attempt")
    op.drop_column("approvals", "stage_order")
    op.drop_column("approval_routes", "stages")
