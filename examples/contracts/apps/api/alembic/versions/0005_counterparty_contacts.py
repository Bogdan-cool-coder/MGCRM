"""counterparty: phone/email/website для шаблона

Revision ID: 0005_cp_contacts
Revises: 0004_routes_lic_tpl
Create Date: 2026-05-26
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0005_cp_contacts"
down_revision: Union[str, None] = "0004_routes_lic_tpl"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column("counterparties", sa.Column("phone", sa.String(64), nullable=True))
    op.add_column("counterparties", sa.Column("email", sa.String(255), nullable=True))
    op.add_column("counterparties", sa.Column("website", sa.String(255), nullable=True))


def downgrade() -> None:
    op.drop_column("counterparties", "website")
    op.drop_column("counterparties", "email")
    op.drop_column("counterparties", "phone")
