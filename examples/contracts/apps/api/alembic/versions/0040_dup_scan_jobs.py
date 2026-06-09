"""Эпик 20 — Performance Scale: async dup-scan jobs table.

Раньше POST /api/duplicates/scan был синхронным — для 100 записей это ms,
для 5000+ записей это секунды (с full scan, normalization, union-find).
Long polling блокировал uvicorn worker и забивал API pool.

Решение — async background task:
1. POST /api/duplicates/scan?entity=... — создаёт запись в dup_scan_jobs
   (status=pending), запускает background task через asyncio.create_task,
   возвращает {job_id, status='pending'} сразу.
2. GET /api/duplicates/scan/{job_id} — polling для frontend, возвращает
   статус + result_json когда completed.
3. GET /api/duplicates/scan/recent — последние N сканов (для UI «недавние»).

Redis-кеш: после completion → SET dup_scan:{entity_type} value=JSON.dumps(result)
TTL 3600s. Следующий POST возвращает cached сразу с from_cache=true.

Структура dup_scan_jobs:
- id SERIAL PK
- entity_type VARCHAR(32) — counterparty|contact|company|lead
- status VARCHAR(16) — pending|running|completed|failed
- started_at TIMESTAMPTZ DEFAULT now()
- completed_at TIMESTAMPTZ
- result_json JSONB — кеш групп для history (даже если Redis cache expired)
- error_message TEXT — для failed
- triggered_by_user_id INT FK users(id) ON DELETE SET NULL

Индекс idx_dup_scan_status(status, started_at DESC) — для recent + поиска
зависших pending джоб.

Defensive advisory-lock seed-key 57_857 (0xE201 — Epic 20 dup-scan jobs).

Revision ID: 0040_dup_scan_jobs  (17 chars ≤32 ✓)
Revises: 0039_fts_gin_indexes
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0040_dup_scan_jobs"
down_revision: Union[str, None] = "0039_fts_gin_indexes"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE201 = 57857 — Epic 20 dup-scan jobs seed-key.
_SEED_LOCK_EPIC_20_DUPSCAN = 57_857


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_20_DUPSCAN},
    )

    op.create_table(
        "dup_scan_jobs",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "entity_type", sa.String(length=32), nullable=False,
        ),
        # pending | running | completed | failed — CHECK на БД-уровне
        sa.Column(
            "status",
            sa.String(length=16),
            nullable=False,
            server_default=sa.text("'pending'"),
        ),
        sa.Column(
            "started_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "completed_at",
            sa.DateTime(timezone=True),
            nullable=True,
        ),
        # Полный результат скана (list of DuplicateGroup dict). Хранится в
        # БД для history, даже когда Redis cache expired.
        sa.Column("result_json", JSONB(), nullable=True),
        # Текст ошибки если status=failed (например, ValueError из service).
        sa.Column("error_message", sa.Text(), nullable=True),
        # Кто запустил — для аудита. SET NULL при удалении юзера.
        sa.Column(
            "triggered_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.CheckConstraint(
            "status IN ('pending','running','completed','failed')",
            name="ck_dup_scan_jobs_status",
        ),
        sa.CheckConstraint(
            "entity_type IN ('counterparty','contact','company','lead')",
            name="ck_dup_scan_jobs_entity_type",
        ),
    )
    # Индекс для GET /scan/recent + monitoring зависших pending.
    op.create_index(
        "idx_dup_scan_status",
        "dup_scan_jobs",
        ["status", sa.text("started_at DESC")],
    )
    # Индекс для дедупликации: «нет ли активного скана для этого entity?»
    op.create_index(
        "idx_dup_scan_entity_started",
        "dup_scan_jobs",
        ["entity_type", sa.text("started_at DESC")],
    )


def downgrade() -> None:
    op.drop_index("idx_dup_scan_entity_started", table_name="dup_scan_jobs")
    op.drop_index("idx_dup_scan_status", table_name="dup_scan_jobs")
    op.drop_table("dup_scan_jobs")
