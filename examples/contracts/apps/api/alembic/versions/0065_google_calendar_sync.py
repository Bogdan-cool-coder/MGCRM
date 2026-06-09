"""Эпик 24.2 — Google Calendar 2-way sync (per-user OAuth).

Две таблицы:

1) google_calendar_links — per-user привязка к Google Calendar (OAuth 2.0
   refresh-token grant).

   UNIQUE(user_id) — один Google аккаунт на одного MACRO-юзера. Если юзер
   хочет переподключить другой Google аккаунт — сначала DELETE link, потом
   повторный /connect.

   access_token_encrypted / refresh_token_encrypted — Fernet-зашифрованные
   токены (см. services/google_calendar.py::encrypt_token/decrypt_token,
   ключ settings.google_oauth_encryption_key с fallback на
   settings.totp_encryption_key чтобы не плодить новые ENV).

   token_expires_at — UTC момент истечения access_token. До этого момента
   используем без refresh; после — обмениваем refresh → новый access.

   scopes — массив URI scope'ов, что мы выдали (например
   ['https://www.googleapis.com/auth/calendar.events', 'openid', 'email',
   'profile']).

   calendar_id — какой календарь синкаем (default 'primary'). В будущем
   можно дать UI selector — пока только primary.

   sync_enabled — master switch (юзер может приостановить sync без
   удаления токенов).
   sync_meeting / sync_call — что синкать; по умолчанию meeting=True,
   call=False (звонки — личное дело менеджера, встречи — командное).
   sync_only_with_time — синкать только Activity где due_at имеет время
   != 00:00:00 (т.е. реальный слот, а не дата-напоминание).

   last_sync_at / last_sync_token — для incremental pull через
   Google Calendar API events.list?syncToken=... (см.
   https://developers.google.com/calendar/api/guides/sync).

2) google_calendar_event_links — соответствие Activity ↔ Google event.

   UNIQUE(activity_id, google_calendar_id) — одна Activity может быть
   связана с одним event'ом в данном календаре. Если юзер сменил
   calendar_id в link, старые linki остаются orphaned (фронт может их
   почистить).

   etag — для оптимистичной concurrency: при PATCH event'а передаём
   `If-Match: <etag>`; если на стороне Google уже поменялось — получаем
   412 и инвалидируем link для пересинхронизации.

   sync_direction — 'to_gcal' (только пуш из CRM), 'from_gcal' (только
   импорт), 'both' (default; двухсторонняя).

   is_active — soft-delete; при разрыве связи (например, Activity удалили)
   ставим False, событие в Google не трогаем (его удалит cron-задача
   delete_gcal_event если direction=both).

Defensive advisory-lock seed-key 57_996 (0xE28C — Epic 24.2 GCal Sync).
DDL-only, без seed-данных.

Revision ID: 0065_gcal_sync  (14 chars ≤32 ✓)
Revises: 0064_merge_m3w1
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import ARRAY, TEXT

revision: str = "0065_gcal_sync"
down_revision: Union[str, None] = "0064_merge_m3w1"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE28C = 57_996 — Epic 24.2 Google Calendar Sync seed-key.
_SEED_LOCK_EPIC_24_2_GCAL = 57_996


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_24_2_GCAL},
    )

    # ============ google_calendar_links ============
    op.create_table(
        "google_calendar_links",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("google_user_id", sa.String(length=64), nullable=True),
        sa.Column("google_email", sa.String(length=256), nullable=True),
        sa.Column("access_token_encrypted", sa.Text(), nullable=True),
        sa.Column("refresh_token_encrypted", sa.Text(), nullable=True),
        sa.Column(
            "token_expires_at",
            sa.DateTime(timezone=True),
            nullable=True,
        ),
        sa.Column(
            "scopes",
            ARRAY(TEXT),
            nullable=True,
            server_default=sa.text("'{}'::text[]"),
        ),
        sa.Column(
            "calendar_id",
            sa.String(length=128),
            nullable=False,
            server_default=sa.text("'primary'"),
        ),
        sa.Column(
            "sync_enabled",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("true"),
        ),
        sa.Column(
            "sync_meeting",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("true"),
        ),
        sa.Column(
            "sync_call",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("false"),
        ),
        sa.Column(
            "sync_only_with_time",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("true"),
        ),
        sa.Column(
            "last_sync_at",
            sa.DateTime(timezone=True),
            nullable=True,
        ),
        sa.Column("last_sync_token", sa.String(length=256), nullable=True),
        sa.Column(
            "connected_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.UniqueConstraint("user_id", name="uq_gcal_user"),
    )
    op.create_index(
        "idx_gcal_user",
        "google_calendar_links",
        ["user_id"],
    )
    # Partial index — для cron'а sync_all_users (только sync_enabled=true).
    op.create_index(
        "idx_gcal_sync",
        "google_calendar_links",
        ["sync_enabled", "last_sync_at"],
        postgresql_where=sa.text("sync_enabled = true"),
    )

    # ============ google_calendar_event_links ============
    op.create_table(
        "google_calendar_event_links",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "activity_id",
            sa.Integer(),
            sa.ForeignKey("activities.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("google_event_id", sa.String(length=128), nullable=False),
        sa.Column(
            "google_calendar_id",
            sa.String(length=128),
            nullable=False,
        ),
        sa.Column("etag", sa.String(length=128), nullable=True),
        sa.Column(
            "last_synced_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "sync_direction",
            sa.String(length=8),
            nullable=False,
            server_default=sa.text("'both'"),
        ),
        sa.Column(
            "is_active",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("true"),
        ),
        sa.UniqueConstraint(
            "activity_id", "google_calendar_id",
            name="uq_gcal_event_activity_cal",
        ),
        sa.CheckConstraint(
            "sync_direction IN ('to_gcal', 'from_gcal', 'both')",
            name="ck_gcal_event_direction",
        ),
    )
    op.create_index(
        "idx_gcal_event_activity",
        "google_calendar_event_links",
        ["activity_id"],
    )
    op.create_index(
        "idx_gcal_event_id",
        "google_calendar_event_links",
        ["google_event_id"],
    )


def downgrade() -> None:
    op.drop_index(
        "idx_gcal_event_id", table_name="google_calendar_event_links",
    )
    op.drop_index(
        "idx_gcal_event_activity", table_name="google_calendar_event_links",
    )
    op.drop_table("google_calendar_event_links")

    op.drop_index("idx_gcal_sync", table_name="google_calendar_links")
    op.drop_index("idx_gcal_user", table_name="google_calendar_links")
    op.drop_table("google_calendar_links")
