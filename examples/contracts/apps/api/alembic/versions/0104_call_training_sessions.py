"""Task 8 — call_training_sessions (тренажёр звонков, персистентность сессий)

Аддитивно: новая таблица под сессии тренажёра холодных звонков. Раньше сессии
жили в in-memory dict (app/routers/ai_chat.py) и терялись на рестарте/между
репликами api (scale=2). Теперь source-of-truth — БД: транскрипт, оценка,
покритериальная разбивка, рекомендации и «удачные решения» сохраняются.

Идемпотентно: создаём таблицу только если её ещё нет (повторный прогон при
параллельных стартах scale=2 не падает). Advisory-lock из свободного диапазона
95_1xx.

Revision ID: 0104_call_training
Revises: 0103_wave4_deal_card
Create Date: 2026-06-04
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0104_call_training"
down_revision: str | None = "0103_wave4_deal_card"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ (диапазон 95_1xx свободен).
_LOCK_CALL_TRAINING = 95_104


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_CALL_TRAINING}
    )
    insp = sa.inspect(conn)
    if "call_training_sessions" in insp.get_table_names():
        return

    op.create_table(
        "call_training_sessions",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("scenario_type", sa.String(length=40), nullable=False),
        sa.Column("company_type", sa.String(length=80), nullable=False),
        sa.Column("company_name", sa.String(length=255), nullable=True),
        sa.Column(
            "status",
            sa.String(length=16),
            nullable=False,
            server_default="active",
        ),
        sa.Column(
            "transcript",
            postgresql.JSONB(),
            nullable=False,
            server_default="[]",
        ),
        sa.Column("score", sa.Numeric(4, 1), nullable=True),
        sa.Column("criteria_scores", postgresql.JSONB(), nullable=True),
        sa.Column("recommendations", postgresql.JSONB(), nullable=True),
        sa.Column("good_decisions", postgresql.JSONB(), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            nullable=False,
            server_default=sa.func.now(),
        ),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.CheckConstraint(
            "status IN ('active','finished')",
            name="ck_call_training_session_status",
        ),
    )
    op.create_index(
        "ix_call_training_sessions_user_id",
        "call_training_sessions",
        ["user_id"],
    )


def downgrade() -> None:
    op.drop_index(
        "ix_call_training_sessions_user_id",
        table_name="call_training_sessions",
    )
    op.drop_table("call_training_sessions")
