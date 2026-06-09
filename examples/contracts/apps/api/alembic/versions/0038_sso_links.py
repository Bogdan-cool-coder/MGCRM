"""Эпик 16 — Security: SSO links + APIToken rate limit.

Две связанные изменения:

1) Новая таблица `user_sso_links`:
   - Хранит привязку User'а к внешнему OAuth провайдеру (Google / Yandex).
   - Один user может иметь по одной привязке на провайдера (UNIQUE
     user_id, provider) — переключаться между Google-аккаунтами не даём.
   - Один (provider, provider_user_id) → один user (UNIQUE) — никто не
     может подменить SSO-связь чужого аккаунта.
   - При callback'е SSO ищем по (provider, provider_user_id); если нет —
     lookup по email от провайдера; если нет — auto-create user без
     password_hash (login через SSO only).

   provider VARCHAR(16): "google" | "yandex" (CHECK на БД-уровне для
   защиты от опечаток).

   provider_user_id VARCHAR(128): то, что Google зовёт `sub` (стабильный
   ID, не меняется при смене email), Yandex — `id` числовой.

   provider_email VARCHAR(256): email от провайдера (для UX и для повторной
   привязки если sub изменился). Может отличаться от User.email.

2) ALTER TABLE api_tokens ADD COLUMN rate_limit_per_hour INT DEFAULT 1000:
   - Per-token лимит для Bearer-rate-limit (token-bucket в Redis).
   - default 1000 — соответствует разумному внешнему интеграционному
     потоку. Админ может бамп'нуть до 100000 для batch-job'ов.

Defensive advisory-lock seed-key 57697 (0xE161 — Epic 16 SSO).

Revision ID: 0038_sso_links  (13 chars ≤32 ✓)
Revises: 0037_security_fields
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0038_sso_links"
down_revision: Union[str, None] = "0037_security_fields"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_EPIC_16_SSO = 57_697


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_16_SSO},
    )

    # ============ user_sso_links ============
    op.create_table(
        "user_sso_links",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        # "google" | "yandex" — CHECK на БД-уровне.
        sa.Column("provider", sa.String(length=16), nullable=False),
        # Google: sub (URL-safe строка ~21 chars). Yandex: id (числовой,
        # до 16 цифр обычно). 128 — щедрый лимит на будущее (Apple ID и т.п.).
        sa.Column("provider_user_id", sa.String(length=128), nullable=False),
        # Email от провайдера (для UX «Войти как X из Google»). Может
        # отличаться от User.email при ребрендинге почты.
        sa.Column("provider_email", sa.String(length=256), nullable=True),
        sa.Column(
            "linked_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.CheckConstraint(
            "provider IN ('google','yandex')",
            name="ck_user_sso_links_provider",
        ),
        # Один внешний аккаунт = один user (защита от race подмены).
        sa.UniqueConstraint(
            "provider", "provider_user_id", name="uq_user_sso_provider_uid",
        ),
        # Один user = одна привязка на провайдер (переключать между
        # аккаунтами Google нельзя без unlink).
        sa.UniqueConstraint(
            "user_id", "provider", name="uq_user_sso_user_provider",
        ),
    )
    op.create_index("idx_sso_user", "user_sso_links", ["user_id"])

    # ============ api_tokens.rate_limit_per_hour ============
    # ADD COLUMN с DEFAULT 1000 + NOT NULL — на больших таблицах PG12+
    # это no-op для existing rows (default не материализуется в строки,
    # хранится в pg_attrdef). Для PG < 11 был бы table-rewrite, но мы
    # на PG16 — безопасно.
    op.execute(
        "ALTER TABLE api_tokens "
        "ADD COLUMN IF NOT EXISTS rate_limit_per_hour INTEGER "
        "NOT NULL DEFAULT 1000"
    )


def downgrade() -> None:
    op.execute(
        "ALTER TABLE api_tokens DROP COLUMN IF EXISTS rate_limit_per_hour"
    )
    op.drop_index("idx_sso_user", table_name="user_sso_links")
    op.drop_table("user_sso_links")
