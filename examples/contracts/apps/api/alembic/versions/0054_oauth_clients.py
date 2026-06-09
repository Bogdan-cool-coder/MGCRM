"""Эпик 15 — OAuth 2.0 Provider (Authorization Code + PKCE).

Три таблицы для полноценного OAuth 2.0 сервера, где MACRO CRM выступает в
роли Identity Provider'а для third-party приложений (Slack-боты, мобилки,
интеграции).

1) `oauth_clients` — зарегистрированные third-party приложения.

   client_id VARCHAR(64) UNIQUE — публичный идентификатор приложения
   (URL-safe, генерится сервером). Передаётся в /authorize?client_id=...

   client_secret_hash VARCHAR(128) — bcrypt-хэш client_secret'а.
   Plaintext возвращается ОДИН раз при создании приложения (как наш
   APIToken). При /token обмене сервер берёт client_id + client_secret
   из basic-auth (для confidential clients) или из form-body.

   name VARCHAR(128) — человеческое имя приложения для UI согласия
   ("Slack — MACRO бот", "iOS приложение продаж").

   redirect_uris TEXT[] — whitelist допустимых redirect_uri (защита от
   open-redirect). /authorize проверяет что переданный redirect_uri ∈ этого
   списка (точное совпадение, без wildcards в MVP).

   scopes TEXT[] — какие scope'ы это приложение МОЖЕТ запрашивать. Если в
   /authorize?scope=write:deals, а в client.scopes только ["read:leads"] —
   400. На /authorize запрашиваемые scopes должны быть subset из этого.

   created_by_user_id — admin, который зарегистрировал приложение.

   is_active — отключение клиента без удаления (для аудита).

2) `oauth_authorizations` — короткоживущие authorization code'ы между
   /authorize → /token обменом (по спеке OAuth 2.0 RFC 6749 §4.1).

   client_id INT FK oauth_clients.id ON DELETE CASCADE — приложение,
   которому этот код принадлежит.

   user_id INT FK users.id ON DELETE CASCADE — пользователь, который дал
   согласие в UI.

   code_hash VARCHAR(128) — bcrypt-хэш одноразового кода. Plain возвращаем
   в redirect_uri?code=... Запись one-time: после успешного обмена ставим
   used=true (next attempt → 400 invalid_grant). Жизнь кода — 10 минут
   (expires_at).

   code_challenge VARCHAR(128) — PKCE S256 challenge (RFC 7636). Хранится
   как base64url(SHA256(code_verifier)). На /token клиент передаёт
   code_verifier (plain), сервер считает S256 и сравнивает с этим хэшем.
   NULL только для не-PKCE клиентов (legacy, не рекомендуем).

   redirect_uri TEXT — тот же URL что был в /authorize. Защита от
   подмены на /token (RFC требует совпадения).

   scopes TEXT[] — какие scope'ы юзер одобрил (может быть subset
   запрошенных в /authorize).

   expires_at TIMESTAMPTZ — 10 минут от created_at. После — код невалиден.

   used BOOLEAN — флажок «обменян на access_token». Защита от replay.

3) `oauth_tokens` — access + refresh пары после успешного /token обмена.

   client_id / user_id — FK на oauth_clients / users (CASCADE).

   access_token_hash VARCHAR(128) UNIQUE — SHA256 hex plaintext'а.
   Подходит к нашему dep'у `verify_access_token`: ищем по хэшу. Plain
   возвращается клиенту в /token response (один раз).

   refresh_token_hash VARCHAR(128) UNIQUE — аналогично, для долгоживущего
   refresh'а. Используется в /token grant_type=refresh_token.

   scopes TEXT[] — финальный набор scope'ов (наследуется из authorization).

   expires_at TIMESTAMPTZ — момент истечения access_token'а. Default
   60 минут (можем повышать через config).

   revoked BOOLEAN — флажок для /revoke endpoint'а. После revoke
   verify_access_token возвращает None.

Индексы:
- idx_oauth_tokens_access (access_token_hash) WHERE NOT revoked —
  главный hot path: Bearer-проверка на каждом запросе. Partial WHERE
  NOT revoked экономит место (отозванные не индексируются).

Defensive advisory-lock seed-key 57_990 (0xE286 — Epic 15 OAuth).
DDL-only, без seed-данных — клиенты заводятся через admin UI.

Revision ID: 0054_oauth_clients  (18 chars ≤32 ✓)
Revises: 0053_calldown_integration
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import ARRAY

revision: str = "0054_oauth_clients"
down_revision: Union[str, None] = "0053_calldown_integration"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE286 = 57_990 — Epic 15 OAuth seed-key.
_SEED_LOCK_EPIC_15_OAUTH = 57_990


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_15_OAUTH},
    )

    # ============ oauth_clients ============
    op.create_table(
        "oauth_clients",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("client_id", sa.String(length=64), nullable=False),
        sa.Column("client_secret_hash", sa.String(length=128), nullable=False),
        sa.Column("name", sa.String(length=128), nullable=False),
        # Postgres ARRAY: точечный whitelist redirect_uri'ев (без wildcards).
        sa.Column("redirect_uris", ARRAY(sa.Text()), nullable=False),
        sa.Column("scopes", ARRAY(sa.Text()), nullable=False),
        sa.Column(
            "created_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
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
        sa.UniqueConstraint("client_id", name="uq_oauth_clients_client_id"),
    )

    # ============ oauth_authorizations ============
    op.create_table(
        "oauth_authorizations",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "client_id",
            sa.Integer(),
            sa.ForeignKey("oauth_clients.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("code_hash", sa.String(length=128), nullable=True),
        # PKCE S256 challenge (base64url(SHA256(code_verifier)))
        sa.Column("code_challenge", sa.String(length=128), nullable=True),
        sa.Column("redirect_uri", sa.Text(), nullable=False),
        sa.Column("scopes", ARRAY(sa.Text()), nullable=True),
        sa.Column(
            "expires_at",
            sa.DateTime(timezone=True),
            nullable=False,
        ),
        sa.Column(
            "used",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("false"),
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
    )

    # ============ oauth_tokens ============
    op.create_table(
        "oauth_tokens",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "client_id",
            sa.Integer(),
            sa.ForeignKey("oauth_clients.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("access_token_hash", sa.String(length=128), nullable=True),
        sa.Column("refresh_token_hash", sa.String(length=128), nullable=True),
        sa.Column("scopes", ARRAY(sa.Text()), nullable=True),
        sa.Column(
            "expires_at",
            sa.DateTime(timezone=True),
            nullable=False,
        ),
        sa.Column(
            "revoked",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("false"),
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.UniqueConstraint(
            "access_token_hash", name="uq_oauth_tokens_access_hash",
        ),
        sa.UniqueConstraint(
            "refresh_token_hash", name="uq_oauth_tokens_refresh_hash",
        ),
    )

    # Hot path Bearer-проверки: partial index по неотозванным.
    op.create_index(
        "idx_oauth_tokens_access",
        "oauth_tokens",
        ["access_token_hash"],
        postgresql_where=sa.text("NOT revoked"),
    )


def downgrade() -> None:
    op.drop_index("idx_oauth_tokens_access", table_name="oauth_tokens")
    op.drop_table("oauth_tokens")
    op.drop_table("oauth_authorizations")
    op.drop_table("oauth_clients")
