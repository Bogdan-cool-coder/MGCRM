"""Epic 24.3 - TG NL Intent Log table

Revision ID: 0066_tg_intent_log
Revises: 0064_merge_m3w1
Create Date: 2026-06-02 18:00:00

Creates tg_intent_logs table для хранения NL-парсинга сообщений TG-бота.
Под advisory-lock key=66 (идемпотентность при parallel api replicas startup).
"""

from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0066_tg_intent_log"
down_revision: Union[str, Sequence[str], None] = "0064_merge_m3w1"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_LOCK_KEY = 66


def upgrade() -> None:
    op.execute(f"SELECT pg_advisory_xact_lock({_LOCK_KEY})")

    op.create_table(
        "tg_intent_logs",
        sa.Column("id", sa.Integer(), primary_key=True, autoincrement=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("tg_user_id", sa.BigInteger(), nullable=True),
        sa.Column("tg_chat_id", sa.BigInteger(), nullable=True),
        sa.Column("raw_message", sa.Text(), nullable=False),
        sa.Column("intent", sa.String(32), nullable=True),
        sa.Column("intent_confidence", sa.Numeric(3, 2), nullable=True),
        sa.Column("parsed_params", JSONB(), nullable=True),
        sa.Column("claude_response_text", sa.Text(), nullable=True),
        sa.Column("claude_tokens_used", sa.Integer(), nullable=True),
        sa.Column("duration_ms", sa.Integer(), nullable=True),
        sa.Column(
            "status",
            sa.String(16),
            nullable=False,
            server_default="processed",
        ),
        sa.Column("result_action_taken", sa.String(64), nullable=True),
        sa.Column("error_message", sa.Text(), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
    )

    # Индексы
    op.create_index(
        "idx_tg_intent_user",
        "tg_intent_logs",
        ["user_id", sa.text("created_at DESC")],
    )
    op.create_index(
        "idx_tg_intent_status",
        "tg_intent_logs",
        ["status", "created_at"],
        postgresql_where=sa.text("status != 'processed'"),
    )


def downgrade() -> None:
    op.drop_index("idx_tg_intent_status", table_name="tg_intent_logs")
    op.drop_index("idx_tg_intent_user", table_name="tg_intent_logs")
    op.drop_table("tg_intent_logs")
