"""add user.avatar_path

Revision ID: 0002_user_avatar
Revises: 0001_initial
Create Date: 2026-05-26
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0002_user_avatar"
down_revision: Union[str, None] = "0001_initial"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column("users", sa.Column("avatar_path", sa.String(512), nullable=True))


def downgrade() -> None:
    op.drop_column("users", "avatar_path")
