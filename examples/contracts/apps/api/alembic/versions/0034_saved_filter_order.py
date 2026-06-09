"""Tech Sprint Фаза 0 (задача 5): добавить `saved_filters.sort_order`.

В рамках Tech Sprint вводим bulk-reorder endpoint'ы для 8 сущностей. У 7 из 8
уже есть order field (sort_order или order_index). У SavedFilter — нет, потому
что сегменты сортируются по is_pinned DESC + created_at DESC. Чтобы можно было
менять порядок drag-and-drop'ом, нужен явный sort_order.

В UI: pinned идут сверху (как было), но внутри pinned-блока user может менять
порядок drag-n-drop'ом. Аналогично для non-pinned. Сортировка в list endpoint
будет: is_pinned DESC, sort_order ASC, created_at DESC (старая семантика как
tiebreaker).

DDL-only. Advisory-lock env.py уже стоит.

Revision ID: 0034_saved_filter_order  (≤32 chars ✓)
Revises: 0033_webhook_settings
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0034_saved_filter_order"
down_revision: Union[str, None] = "0033_webhook_settings"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column(
        "saved_filters",
        sa.Column(
            "sort_order",
            sa.Integer(),
            nullable=False,
            server_default=sa.text("0"),
        ),
    )


def downgrade() -> None:
    op.drop_column("saved_filters", "sort_order")
