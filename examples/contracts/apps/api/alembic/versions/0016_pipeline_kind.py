"""pipelines.kind (sales|lifecycle) + backfill воронки ЖЦ

Разделяет воронку продаж и воронку «Жизненный цикл клиента»: lifecycle не
показывается в /deals. Аддитивно.

Revision ID: 0016_pipeline_kind
Revises: 0015_customer_success
Create Date: 2026-05-29
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0016_pipeline_kind"
down_revision: Union[str, None] = "0015_customer_success"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column("pipelines", sa.Column("kind", sa.String(length=16), nullable=False, server_default="sales"))
    # бэкафилл: уже засеянная воронка ЖЦ на проде получила бы kind=sales по дефолту
    op.execute("UPDATE pipelines SET kind='lifecycle' WHERE name='Жизненный цикл клиента'")


def downgrade() -> None:
    op.drop_column("pipelines", "kind")
