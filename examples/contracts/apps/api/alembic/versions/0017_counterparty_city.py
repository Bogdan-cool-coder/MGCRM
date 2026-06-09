"""counterparties.city (для фильтрации реестра CS по городу)

Фаза 4e (импорт). Аддитивно.

Revision ID: 0017_counterparty_city
Revises: 0016_pipeline_kind
Create Date: 2026-05-29
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0017_counterparty_city"
down_revision: Union[str, None] = "0016_pipeline_kind"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column("counterparties", sa.Column("city", sa.String(length=128), nullable=True))


def downgrade() -> None:
    op.drop_column("counterparties", "city")
