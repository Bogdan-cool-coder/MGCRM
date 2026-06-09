"""Эпик 24.2 hotfix (июнь 2026): google_calendar_event_links.sync_direction
VARCHAR(8) → VARCHAR(20).

Прод-инцидент: GCal 2-way sync cron падал с StringDataRightTruncationError при
INSERT значения 'from_gcal' (9 символов) в колонку VARCHAR(8). Объявленная
длина 8 не вмещала самый длинный допустимый литерал направления синхронизации.

Допустимые значения: 'both' (4), 'to_gcal' (7), 'from_gcal' (9) — все влезают
в 20. Расширение длины VARCHAR безопасно и идемпотентно само по себе; advisory
-lock добавлен по конвенции проекта (scale=2 api-реплики, параллельные стартапы).

Revision ID: 0071_gcal_sync_dir_len
Revises: 0070_contacts_v2_data
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op


revision: str = "0071_gcal_sync_dir_len"
down_revision: Union[str, None] = "0070_contacts_v2_data"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


# 0x18A18 = 100_888 — Epic 24.2 GCal sync_direction len hotfix seed-key.
_LOCK_EPIC_24_2_GCAL_SYNC_DIR = 100_888


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _LOCK_EPIC_24_2_GCAL_SYNC_DIR},
    )
    op.alter_column(
        "google_calendar_event_links",
        "sync_direction",
        existing_type=sa.String(length=8),
        type_=sa.String(length=20),
        existing_nullable=False,
        existing_server_default="both",
    )


def downgrade() -> None:
    # Осторожно: если в проде есть значения длиннее 8 (например 'from_gcal'),
    # обратный ALTER усечёт данные. Это hotfix-форвард; downgrade оставлен
    # симметричным, выполнять только на пустой/совместимой таблице.
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _LOCK_EPIC_24_2_GCAL_SYNC_DIR},
    )
    op.alter_column(
        "google_calendar_event_links",
        "sync_direction",
        existing_type=sa.String(length=20),
        type_=sa.String(length=8),
        existing_nullable=False,
        existing_server_default="both",
    )
