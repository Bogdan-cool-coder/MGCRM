"""inbox + channels + forms (Эпик 5 MVP): входящие сообщения → авто-Lead

Эпик 5 — Inbox и каналы. Универсальный путь любого внешнего сигнала: webhook
(/api/inbox/webhook/{channel_id}) или публичная форма (/api/forms/public/{slug})
создаёт InboundMessage и сразу автогенерирует Lead в воронке kind="lead".

Три новые таблицы:
- channels — описание канала (tg/wa/email/web_form/api), secret_token для
  верификации webhook'ов, дефолтные pipeline/stage/owner для авто-Lead.
- inbound_messages — лог входящих сообщений (с raw_payload для аудита), связь
  на созданный Lead (target_lead_id). Композитный (channel_id, external_id)
  для дедупа повторных доставок webhook.
- forms — публичные формы (uniq public_slug), список полей в JSON, привязка
  к каналу (kind='web_form'); приём в /api/forms/public/{slug}/submit без auth.

Миграция — schema-only, без seed (сидеть нечего: каналы и формы создаёт
админ через UI/CRUD). Advisory-lock НЕ нужен.

Revision ID: 0023_inbox_channels
Revises: 0022_pipeline_automation
Create Date: 2026-05-30
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0023_inbox_channels"
down_revision: Union[str, None] = "0022_pipeline_automation"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ---- channels ----
    op.create_table(
        "channels",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(length=255), nullable=False),
        # 'tg' | 'wa' | 'email' | 'web_form' | 'api'
        sa.Column("kind", sa.String(length=16), nullable=False),
        # 32-байтный URL-safe токен (см. inbox.generate_channel_token) — для signature check
        sa.Column("secret_token", sa.String(length=64), nullable=False),
        sa.Column(
            "config",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'{}'::json"),
        ),
        # source для авто-Lead: 'tg'|'wa'|'email'|'form'|'api'|'manual'|'import'
        sa.Column(
            "default_lead_source",
            sa.String(length=16),
            nullable=False,
            server_default="api",
        ),
        sa.Column(
            "default_owner_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "default_pipeline_id",
            sa.Integer(),
            sa.ForeignKey("pipelines.id", ondelete="RESTRICT"),
            nullable=True,
        ),
        sa.Column(
            "default_stage_id",
            sa.Integer(),
            sa.ForeignKey("pipeline_stages.id", ondelete="SET NULL"),
            nullable=True,
        ),
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
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
    )
    op.create_index("ix_channels_kind", "channels", ["kind"])
    op.create_index("ix_channels_is_active", "channels", ["is_active"])
    op.create_index("ix_channels_secret_token", "channels", ["secret_token"])

    # ---- inbound_messages ----
    op.create_table(
        "inbound_messages",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "channel_id",
            sa.Integer(),
            sa.ForeignKey("channels.id", ondelete="CASCADE"),
            nullable=False,
        ),
        # id во внешней системе (TG update_id, WA message id, email Message-ID) — для дедупа
        sa.Column("external_id", sa.String(length=128), nullable=True),
        # tg_username | phone | email | form submission UUID
        sa.Column("from_identifier", sa.String(length=255), nullable=True),
        sa.Column("from_name", sa.String(length=255), nullable=True),
        sa.Column("subject", sa.String(length=255), nullable=True),
        sa.Column("body", sa.Text(), nullable=True),
        sa.Column("raw_payload", sa.JSON(), nullable=True),
        sa.Column(
            "target_lead_id",
            sa.Integer(),
            sa.ForeignKey("leads.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "target_lead_created",
            sa.Boolean(),
            nullable=False,
            server_default=sa.false(),
        ),
        sa.Column(
            "received_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
    )
    op.create_index(
        "ix_inbound_messages_channel_id",
        "inbound_messages",
        ["channel_id"],
    )
    op.create_index(
        "ix_inbound_messages_target_lead_id",
        "inbound_messages",
        ["target_lead_id"],
    )
    op.create_index(
        "ix_inbound_messages_received_at",
        "inbound_messages",
        ["received_at"],
    )
    # Композитный для дедупа повторных доставок webhook (channel + external_id)
    op.create_index(
        "ix_inbound_messages_channel_external",
        "inbound_messages",
        ["channel_id", "external_id"],
    )

    # ---- forms ----
    op.create_table(
        "forms",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(length=255), nullable=False),
        # Уникальный URL-slug для публичной страницы /forms/{slug}
        sa.Column("public_slug", sa.String(length=64), nullable=False),
        sa.Column(
            "fields",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'[]'::json"),
        ),
        sa.Column(
            "channel_id",
            sa.Integer(),
            sa.ForeignKey("channels.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "thank_you_text",
            sa.Text(),
            nullable=True,
            server_default="Спасибо! Мы свяжемся с вами.",
        ),
        sa.Column(
            "is_active",
            sa.Boolean(),
            nullable=False,
            server_default=sa.true(),
        ),
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
        sa.UniqueConstraint("public_slug", name="uq_forms_public_slug"),
    )
    op.create_index("ix_forms_is_active", "forms", ["is_active"])
    op.create_index("ix_forms_channel_id", "forms", ["channel_id"])


def downgrade() -> None:
    op.drop_index("ix_forms_channel_id", table_name="forms")
    op.drop_index("ix_forms_is_active", table_name="forms")
    op.drop_table("forms")

    op.drop_index("ix_inbound_messages_channel_external", table_name="inbound_messages")
    op.drop_index("ix_inbound_messages_received_at", table_name="inbound_messages")
    op.drop_index("ix_inbound_messages_target_lead_id", table_name="inbound_messages")
    op.drop_index("ix_inbound_messages_channel_id", table_name="inbound_messages")
    op.drop_table("inbound_messages")

    op.drop_index("ix_channels_secret_token", table_name="channels")
    op.drop_index("ix_channels_is_active", table_name="channels")
    op.drop_index("ix_channels_kind", table_name="channels")
    op.drop_table("channels")
