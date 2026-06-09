"""signed status + contract_attachments + signed_at

Статус «сделка проведена» (signed), таблица вложений договора (скан подписи клиента)
и поле contracts.signed_at. Скан подписи — условие перехода в статус signed.

Revision ID: 0010_signed_attach
Revises: 0009_arch_reject
Create Date: 2026-05-29
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0010_signed_attach"
down_revision: Union[str, None] = "0009_arch_reject"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # Новое значение enum. IF NOT EXISTS — идемпотентно; PG16 допускает ADD VALUE в транзакции
    # (значение не используется в этой же миграции, поэтому безопасно).
    op.execute("ALTER TYPE contract_status ADD VALUE IF NOT EXISTS 'signed'")

    op.add_column("contracts", sa.Column("signed_at", sa.DateTime(timezone=True), nullable=True))

    op.create_table(
        "contract_attachments",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "contract_id", sa.Integer(),
            sa.ForeignKey("contracts.id", ondelete="CASCADE"), nullable=False,
        ),
        sa.Column("kind", sa.String(length=32), nullable=False, server_default="signed_scan"),
        sa.Column("path", sa.String(length=512), nullable=False),
        sa.Column("original_name", sa.String(length=255), nullable=True),
        sa.Column("content_type", sa.String(length=128), nullable=True),
        sa.Column("uploaded_by_user_id", sa.Integer(), sa.ForeignKey("users.id"), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index(
        "ix_contract_attachments_contract_id", "contract_attachments", ["contract_id"]
    )


def downgrade() -> None:
    op.drop_index("ix_contract_attachments_contract_id", table_name="contract_attachments")
    op.drop_table("contract_attachments")
    op.drop_column("contracts", "signed_at")
    # enum-значение 'signed' не удаляем: PostgreSQL не поддерживает DROP VALUE.
