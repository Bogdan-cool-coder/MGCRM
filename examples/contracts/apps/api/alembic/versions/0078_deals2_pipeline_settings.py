"""DEALS 2.0 (Ф1b) — настройки воронки + видимость воронки.

DDL-only (без seed-данных). Добавляет в pipelines:
- settings JSONB NOT NULL DEFAULT '{}' — настройки воронки (auto_assign для
  «Неразобранное», duplicate_check_enabled, duplicate_check_fields).
- visible_role VARCHAR(16) NULL — если задано, воронка видна только этой роли
  (admin/director/manager/...). NULL = видна всем по обычной stage-видимости.
- visible_user_ids JSON NOT NULL DEFAULT '[]' — whitelist пользователей,
  которым видна воронка (пусто = всем; работает совместно с visible_role).

Связь канал↔воронка НЕ требует новой таблицы: переиспользуем существующий
Channel.default_pipeline_id (Эпик 5). Junction не вводим — у канала одна
воронка-назначение, этого достаточно для «Источников сделок».

Advisory-lock seed-key 78_001 (защита concurrent api-реплик при scale=2; DDL
сам по себе идемпотентен на уровне alembic_version row-lock).

Revision ID: 0078_deals2_pipeline_settings  (30 chars ≤32 ✓)
Revises: 0077_deals2_f1a
Create Date: 2026-06-03
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0078_deals2_pipeline_settings"
down_revision: Union[str, None] = "0077_deals2_f1a"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_PIPELINE_SETTINGS = 78_001


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_PIPELINE_SETTINGS},
    )

    op.add_column(
        "pipelines",
        sa.Column(
            "settings",
            sa.dialects.postgresql.JSONB(),
            nullable=False,
            server_default=sa.text("'{}'::jsonb"),
        ),
    )
    op.add_column(
        "pipelines",
        sa.Column("visible_role", sa.String(length=16), nullable=True),
    )
    op.add_column(
        "pipelines",
        sa.Column(
            "visible_user_ids",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'[]'::json"),
        ),
    )


def downgrade() -> None:
    op.drop_column("pipelines", "visible_user_ids")
    op.drop_column("pipelines", "visible_role")
    op.drop_column("pipelines", "settings")
