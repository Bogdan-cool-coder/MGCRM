"""Эпик 21 — UX Upgrade: in-app notifications.

Таблица `notifications` — единая шина уведомлений в UI (badge в шапке,
выпадающий список, ссылки на сущности). НЕ заменяет существующие
TG-уведомления — они продолжают работать параллельно.

Структура:
- id SERIAL PK
- user_id INT NOT NULL FK users(id) ON DELETE CASCADE
    Получатель. CASCADE — при увольнении удаляются всем разом
    (нет смысла хранить нотификации удалённого аккаунта).
- kind VARCHAR(32) NOT NULL
    Тип события. Whitelist на уровне приложения (не в БД CHECK —
    мы хотим легко добавлять новые типы без миграции):
        task_assigned, deal_won, approval_needed, sla_breach,
        course_assigned, contract_signed, mention, system
- title VARCHAR(256) NOT NULL
    Короткий заголовок для строки списка.
- body TEXT NULL
    Опциональное расширенное описание (1-2 предложения).
- link TEXT NULL
    Куда вести клик (/deals/123, /courses/4/play, /contracts/55).
    Относительный URL — фронт сам строит absolute из baseUrl.
- is_read BOOLEAN DEFAULT false
    Прочитано / непрочитано. Менять можно PATCH /{id}/read или
    POST /mark-all-read.
- read_at TIMESTAMPTZ NULL
    Момент прочтения (для аналитики «сколько висели непрочитанные»).
- created_at TIMESTAMPTZ DEFAULT now()
    Время создания нотификации.
- metadata JSONB NULL
    Расширение для конкретного kind: {deal_id, amount, currency} для
    deal_won, {approval_id, contract_id} для approval_needed, и т.п.
    JSONB чтобы можно было фильтровать по ключам в будущем.

Индекс idx_notifications_user(user_id, is_read, created_at DESC) —
основной паттерн запроса: «непрочитанные у юзера X отсортированные
по дате убыванию». Покрывает оба сценария:
- /api/notifications/count (filter is_read=false) — фильтр работает
- /api/notifications?limit=20 (sort by created_at) — sort работает

Defensive advisory-lock seed-key 57_954 (0xE262 — Epic 21 notifications).

Revision ID: 0042_notifications  (17 chars ≤32 ✓)
Revises: 0041_user_ux_profile
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0042_notifications"
down_revision: Union[str, None] = "0041_user_ux_profile"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE262 = 57_954 — Epic 21 notifications seed-key.
_SEED_LOCK_EPIC_21_NOTIFICATIONS = 57_954


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_21_NOTIFICATIONS},
    )

    op.create_table(
        "notifications",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        # Whitelist держим в app-коде (notifications service), не в CHECK —
        # чтобы добавление нового kind не требовало миграции.
        sa.Column("kind", sa.String(length=32), nullable=False),
        sa.Column("title", sa.String(length=256), nullable=False),
        sa.Column("body", sa.Text(), nullable=True),
        # Относительный URL для frontend router (например, /deals/123).
        sa.Column("link", sa.Text(), nullable=True),
        sa.Column(
            "is_read",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("false"),
        ),
        sa.Column(
            "read_at",
            sa.DateTime(timezone=True),
            nullable=True,
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        # Extension data для конкретного kind. JSONB → можно фильтровать
        # по ключам в будущем (например WHERE metadata->>'deal_id' = '123').
        sa.Column("metadata", JSONB(), nullable=True),
    )

    # Главный индекс под все query-паттерны UI: непрочитанные у юзера,
    # отсортированные по убыванию даты. Покрывает count + list endpoints.
    op.create_index(
        "idx_notifications_user",
        "notifications",
        ["user_id", "is_read", sa.text("created_at DESC")],
    )


def downgrade() -> None:
    op.drop_index("idx_notifications_user", table_name="notifications")
    op.drop_table("notifications")
