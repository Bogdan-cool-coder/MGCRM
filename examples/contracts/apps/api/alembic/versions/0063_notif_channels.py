"""Epic 21.2 — Notification channels: preferences/templates/broadcasts.

Расширяем эпик 21 (in-app notifications) до multi-channel fan-out:
in_app (existing) + tg (existing telegram bot) + email (SMTP stub).

Создаём 3 таблицы + расширяем `users` 4 колонками:

1) notification_channel_preferences — per-user × kind × channel матрица
   on/off. По дефолту юзеру разрешены все каналы для всех kind'ов; админ
   через UI «Настройки → Уведомления» может приглушить шумные комбинации
   (например, «не дёргать tg для course_assigned»).

   UniqueConstraint(user_id, kind, channel) — primary key для upsert.

   Whitelist channel: in_app | tg | email. Не CHECK на БД (как и kind у
   Notification) — добавление нового канала (e.g. sms, push) идёт через
   app-код без миграции.

2) notification_templates — per kind × channel × locale Jinja-шаблоны.
   subject/body_template/variables JSONB (документация переменных).
   Активный шаблон — is_active=true. UniqueConstraint(kind, channel, locale).

3) notification_broadcasts — массовая рассылка от админа.
   recipient_filter JSONB: {role|department_id|user_ids}.
   Фоновая task увеличивает delivered_count / failed_count;
   статус pending → running → completed | failed.

4) users — 4 новых колонки:
   - email_notifications_enabled BOOLEAN DEFAULT true
       Master switch для email канала (помимо per-kind preferences).
   - tg_quiet_hours_start TIME / tg_quiet_hours_end TIME
       Тихий час для TG-канала (например 21:00..09:00). NULL = всегда шумит.
   - notification_phone VARCHAR(32)
       Резерв под будущий SMS-канал (Эпик 21.3+). Сейчас не используется.

Defensive advisory-lock seed-key 57_995 (0xE28B — Epic 21.2 Notif Channels).
DDL-only (seed preferences/templates делается отдельным сервисом в lifespan).

Revision ID: 0063_notif_channels  (19 chars ≤32 ✓)
Revises: 0061_rights_transfer (последний head на момент эпика 21.2)
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.

Если параллельные эпики (24 / 14.2 vacations) добавят свой head после
0061 — main-сессия делает merge-миграцию (см. пример 0046_merge_ai_history).
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0063_notif_channels"
down_revision: Union[str, None] = "0061_rights_transfer"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE28B = 57_995 — Epic 21.2 Notif Channels seed-key.
_SEED_LOCK_EPIC_21_2 = 57_995


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_21_2},
    )

    # ============ notification_channel_preferences ============
    # Per-user × kind × channel матрица on/off. UniqueConstraint — для upsert.
    op.create_table(
        "notification_channel_preferences",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        # task_assigned | deal_won | approval_needed | sla_breach |
        # course_assigned | contract_signed | mention | system | ...
        # (whitelist на app-уровне в services/notifications.py)
        sa.Column("kind", sa.String(length=32), nullable=False),
        # in_app | tg | email (расширяемо на app-уровне)
        sa.Column("channel", sa.String(length=16), nullable=False),
        sa.Column(
            "is_enabled",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("true"),
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            onupdate=sa.func.now(),
            nullable=False,
        ),
        sa.UniqueConstraint(
            "user_id", "kind", "channel",
            name="uq_ncp_user_kind_channel",
        ),
    )
    # Главный паттерн запроса: «все preferences юзера X» — список / матрица в UI.
    op.create_index(
        "idx_ncp_user",
        "notification_channel_preferences",
        ["user_id"],
    )

    # ============ notification_templates ============
    # Per kind × channel × locale Jinja-шаблоны subject/body. is_active позволяет
    # держать черновики, не активируя их.
    op.create_table(
        "notification_templates",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("kind", sa.String(length=32), nullable=False),
        sa.Column("channel", sa.String(length=16), nullable=False),
        sa.Column(
            "locale",
            sa.String(length=8),
            nullable=False,
            server_default=sa.text("'ru'"),
        ),
        # Subject — для email/in_app; для tg обычно None (один текст body).
        sa.Column("subject", sa.Text(), nullable=True),
        # Jinja-шаблон с {{ var }} — рендерится в services/notification_dispatcher.
        sa.Column("body_template", sa.Text(), nullable=True),
        # Список переменных шаблона для документации в UI:
        # [{"name": "task.title", "type": "string", "required": true}, ...]
        sa.Column(
            "variables",
            JSONB(),
            nullable=True,
        ),
        sa.Column(
            "is_active",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("true"),
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            onupdate=sa.func.now(),
            nullable=False,
        ),
        sa.UniqueConstraint(
            "kind", "channel", "locale",
            name="uq_nt_kind_channel_locale",
        ),
    )
    # Фильтр для dispatcher'а: «нужный активный шаблон по kind/channel/locale».
    op.create_index(
        "idx_nt_kind_channel",
        "notification_templates",
        ["kind", "channel", "is_active"],
    )

    # ============ notification_broadcasts ============
    # Массовая рассылка от админа: новость / напоминание / релиз-нот.
    # Фоновая задача (dispatch_broadcast) идёт по filter, генерирует нотификации
    # для каждого получателя, апдейтит счётчики и переводит статус.
    op.create_table(
        "notification_broadcasts",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "initiated_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        # По дефолту 'system' (системное сообщение). Может быть любым kind'ом
        # из whitelist (на случай если админ хочет насадить детализированный type).
        sa.Column(
            "kind",
            sa.String(length=32),
            nullable=False,
            server_default=sa.text("'system'"),
        ),
        sa.Column("title", sa.String(length=256), nullable=True),
        sa.Column("body", sa.Text(), nullable=True),
        sa.Column("link", sa.Text(), nullable=True),
        # {role: 'manager'} | {department_id: 5} | {user_ids: [1,2,3]}
        # Возможна комбинация (например role + department_id).
        sa.Column(
            "recipient_filter",
            JSONB(),
            nullable=True,
        ),
        # Счётчики. recipients_count — резолвится сразу после filter.
        # delivered/failed — обновляются по мере фонового dispatch'а.
        sa.Column("recipients_count", sa.Integer(), nullable=True),
        sa.Column(
            "delivered_count",
            sa.Integer(),
            nullable=False,
            server_default=sa.text("0"),
        ),
        sa.Column(
            "failed_count",
            sa.Integer(),
            nullable=False,
            server_default=sa.text("0"),
        ),
        # pending | running | completed | failed
        sa.Column(
            "status",
            sa.String(length=16),
            nullable=False,
            server_default=sa.text("'pending'"),
        ),
        # Каналы рассылки: ["in_app"] (default) | ["in_app","tg"] | ...
        # JSONB список строк. Если null — только in_app.
        sa.Column(
            "channels",
            JSONB(),
            nullable=True,
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "completed_at",
            sa.DateTime(timezone=True),
            nullable=True,
        ),
    )
    # Список рассылок в UI — DESC по created_at.
    op.create_index(
        "idx_nb_created",
        "notification_broadcasts",
        [sa.text("created_at DESC")],
    )
    # Фильтр статуса (для cron, если будет автоматический dispatcher).
    op.create_index(
        "idx_nb_status",
        "notification_broadcasts",
        ["status"],
    )

    # ============ users — расширение ============
    # Master switch для email; default=true чтобы existing rows получили реальный
    # значимый default (а не NULL). UI «Настройки → Уведомления → Email» меняет.
    op.add_column(
        "users",
        sa.Column(
            "email_notifications_enabled",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("true"),
        ),
    )
    # Тихий час для TG-канала. Обе колонки NULLABLE (NULL — quiet hours
    # отключены). Если start > end — перевод через полночь (21:00..09:00 — type).
    op.add_column(
        "users",
        sa.Column(
            "tg_quiet_hours_start",
            sa.Time(),
            nullable=True,
        ),
    )
    op.add_column(
        "users",
        sa.Column(
            "tg_quiet_hours_end",
            sa.Time(),
            nullable=True,
        ),
    )
    # Резерв под будущий SMS-канал. Сейчас не используется dispatch'ем —
    # просто храним если юзер захочет ввести.
    op.add_column(
        "users",
        sa.Column(
            "notification_phone",
            sa.String(length=32),
            nullable=True,
        ),
    )


def downgrade() -> None:
    op.drop_column("users", "notification_phone")
    op.drop_column("users", "tg_quiet_hours_end")
    op.drop_column("users", "tg_quiet_hours_start")
    op.drop_column("users", "email_notifications_enabled")

    op.drop_index("idx_nb_status", table_name="notification_broadcasts")
    op.drop_index("idx_nb_created", table_name="notification_broadcasts")
    op.drop_table("notification_broadcasts")

    op.drop_index("idx_nt_kind_channel", table_name="notification_templates")
    op.drop_table("notification_templates")

    op.drop_index("idx_ncp_user", table_name="notification_channel_preferences")
    op.drop_table("notification_channel_preferences")
