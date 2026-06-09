"""Public API tokens + Outbound Webhooks (Эпик 11.1 + 11.2): Bearer-auth
opt-in поверх существующего cookie-auth + outbound events с HMAC и retry.

Три новые таблицы:

- api_tokens — opaque Bearer-токены для внешних интеграций. Храним только
  SHA256(plaintext) в token_hash; plaintext возвращается клиенту единственный
  раз — при create. Owner — FK на users (CASCADE: удаление пользователя
  чистит его токены, не оставляя «висящих» бессрочных прав).

- webhooks — outbound subscriptions: куда слать события + HMAC-секрет +
  список подписок (event_subscriptions JSON). "*" в списке = все события.

- webhook_deliveries — одна попытка доставки на событие. Cron сканирует
  WHERE status IN ('pending','retrying') AND next_retry_at <= now()
  ORDER BY next_retry_at — для этого композитный (status, next_retry_at).

Миграция — schema-only, без seed (токены и webhooks создаёт админ через
UI/CRUD). Advisory-lock НЕ нужен.

Все три ADD COLUMN/CREATE TABLE безопасны на scale=2 без advisory-lock:
параллельный CREATE TABLE на одном имени даст ошибку у одной из реплик,
но обе реплики стартуют после `alembic upgrade head` в одной командной
строке деплоя — это уже сериализовано на уровне CI/CD.

Revision ID: 0030_api_tokens_webhooks  (≤32 chars ✓)
Revises: 0029_cpty_responsible_user
Create Date: 2026-05-31

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0030_api_tokens_webhooks"
down_revision: Union[str, None] = "0029_cpty_responsible_user"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ---- api_tokens ----
    # token_hash UNIQUE — лукап по SHA256 hex в дип Bearer'а. unique=True даёт
    # implicit btree-index, явный create_index не нужен.
    op.create_table(
        "api_tokens",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("name", sa.String(length=128), nullable=False),
        # SHA256 hex (64 chars). UNIQUE + index для O(1) lookup по plaintext-hash.
        sa.Column("token_hash", sa.String(length=64), nullable=False, unique=True),
        # ["read:leads","write:deals",...] либо ["*"] для admin-уровня.
        sa.Column(
            "scopes",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'[]'::json"),
        ),
        sa.Column("expires_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_used_at", sa.DateTime(timezone=True), nullable=True),
        # IPv6 max — 45 символов.
        sa.Column("last_used_ip", sa.String(length=45), nullable=True),
        sa.Column(
            "is_active",
            sa.Boolean(),
            nullable=False,
            server_default=sa.true(),
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column("revoked_at", sa.DateTime(timezone=True), nullable=True),
    )
    op.create_index("ix_api_tokens_user_id", "api_tokens", ["user_id"])
    op.create_index("ix_api_tokens_is_active", "api_tokens", ["is_active"])

    # ---- webhooks ----
    op.create_table(
        "webhooks",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(length=128), nullable=False),
        sa.Column("url", sa.String(length=512), nullable=False),
        # Общий с подписчиком secret. NOT NULL — без secret не можем подписать.
        sa.Column("secret", sa.String(length=128), nullable=False),
        # ["lead.created","deal.stage_changed",...] или ["*"].
        sa.Column(
            "event_subscriptions",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'[]'::json"),
        ),
        sa.Column(
            "is_active",
            sa.Boolean(),
            nullable=False,
            server_default=sa.true(),
        ),
        # Опциональные кастомные header'ы (Authorization, X-*). nullable=True
        # потому что пустой dict не нужен — большинство webhook'ов без них.
        sa.Column("headers", sa.JSON(), nullable=True),
        sa.Column(
            "created_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
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
            nullable=False,
        ),
    )
    op.create_index("ix_webhooks_is_active", "webhooks", ["is_active"])

    # ---- webhook_deliveries ----
    op.create_table(
        "webhook_deliveries",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "webhook_id",
            sa.Integer(),
            sa.ForeignKey("webhooks.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("event", sa.String(length=64), nullable=False),
        sa.Column("payload", sa.JSON(), nullable=False),
        # 'pending' | 'retrying' | 'success' | 'failed'
        sa.Column("status", sa.String(length=16), nullable=False),
        sa.Column(
            "attempt",
            sa.Integer(),
            nullable=False,
            server_default=sa.text("0"),
        ),
        sa.Column("next_retry_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("last_http_code", sa.Integer(), nullable=True),
        sa.Column("last_error", sa.Text(), nullable=True),
        # Усечено до 2KB перед записью (см. webhook_dispatcher.MAX_BODY_SAVE).
        sa.Column("last_response_body", sa.Text(), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
    )
    op.create_index(
        "ix_webhook_deliveries_webhook_id",
        "webhook_deliveries",
        ["webhook_id"],
    )
    op.create_index(
        "ix_webhook_deliveries_event",
        "webhook_deliveries",
        ["event"],
    )
    op.create_index(
        "ix_webhook_deliveries_next_retry_at",
        "webhook_deliveries",
        ["next_retry_at"],
    )
    # Композитный — hot path cron'а: WHERE status IN ('pending','retrying')
    # AND next_retry_at <= now() ORDER BY next_retry_at.
    op.create_index(
        "ix_webhook_deliveries_status_next",
        "webhook_deliveries",
        ["status", "next_retry_at"],
    )


def downgrade() -> None:
    op.drop_index(
        "ix_webhook_deliveries_status_next",
        table_name="webhook_deliveries",
    )
    op.drop_index(
        "ix_webhook_deliveries_next_retry_at",
        table_name="webhook_deliveries",
    )
    op.drop_index(
        "ix_webhook_deliveries_event",
        table_name="webhook_deliveries",
    )
    op.drop_index(
        "ix_webhook_deliveries_webhook_id",
        table_name="webhook_deliveries",
    )
    op.drop_table("webhook_deliveries")

    op.drop_index("ix_webhooks_is_active", table_name="webhooks")
    op.drop_table("webhooks")

    op.drop_index("ix_api_tokens_is_active", table_name="api_tokens")
    op.drop_index("ix_api_tokens_user_id", table_name="api_tokens")
    op.drop_table("api_tokens")
