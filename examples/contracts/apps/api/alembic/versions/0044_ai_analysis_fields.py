"""Эпик 18 — AI Features: contract analysis cache + AI request log.

Расширяем `contracts` двумя полями для кэша AI-анализа и заводим
служебную таблицу `ai_request_log` для observability (token usage,
latency, error rate per feature/user).

`contracts.ai_analysis_json` JSONB NULL
    Кэш последнего AI-анализа. Структура:
        {
          "issues": [{"severity": "warning", "quote": "...", "explanation": "...", "suggestion": "..."}],
          "standard_sections": [{"section": "Предмет договора", "status": "ok"}],
          "recommendations": ["..."],
          "model": "claude-sonnet-4-5",
          "ai_tokens_used": 1234
        }
    NULL = ни разу не анализировался. force_refresh обновляет.

`contracts.ai_analyzed_at` TIMESTAMPTZ NULL
    Момент последнего анализа. UI показывает «Последний анализ N мин назад».
    NULL = вместе с ai_analysis_json NULL.

`ai_request_log` — append-only observability таблица:
- id SERIAL PK
- user_id INT NULL FK users.id ON DELETE SET NULL
    Кто инициировал. NULL = system-call (cron, в будущем).
- entity_type VARCHAR(32) NULL
    'contract' | 'deal' | 'lead' | 'counterparty' — какая сущность.
    NULL = standalone-call (например chat).
- entity_id INT NULL
    id сущности (если entity_type задан). NULL — same as above.
- feature VARCHAR(32) NOT NULL
    'contract_analyze' | 'deal_prefill' | 'lead_prefill' | …
    Для group-by аналитики и rate-limit'а.
- prompt_tokens / completion_tokens / total_tokens INT NULL
    Anthropic API возвращает usage. NULL при ошибке до получения ответа.
- model VARCHAR(64) NULL
    Имя модели Claude. NULL при ошибке до отправки.
- duration_ms INT NULL
    Длительность вызова Anthropic API (включая retries).
- status VARCHAR(16) NOT NULL DEFAULT 'success'
    'success' | 'error' | 'not_configured' | 'rate_limited'.
- error_message TEXT NULL
    При status != 'success' — короткое описание (без stack trace).
- created_at TIMESTAMPTZ DEFAULT now()

Индекс idx_ai_log_user_created(user_id, created_at DESC) — для dashboard
«мои AI-запросы за неделю» и rate-limit countup'а.

Defensive advisory-lock seed-key 57_955 (0xE263 — Epic 18 AI features).
DDL-only, без seed-данных — advisory-lock здесь больше для консистентности
с остальными миграциями (alembic_version row-lock уже защищает от race).

Revision ID: 0044_ai_analysis_fields  (23 chars ≤32 ✓)
Revises: 0043_automation_sla_ext
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0044_ai_analysis_fields"
down_revision: Union[str, None] = "0043_automation_sla_ext"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE263 = 57_955 — Epic 18 AI features seed-key.
_SEED_LOCK_EPIC_18_AI = 57_955


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_18_AI},
    )

    # 1) Поля для кэша анализа на contracts.
    op.add_column(
        "contracts",
        sa.Column("ai_analysis_json", JSONB(), nullable=True),
    )
    op.add_column(
        "contracts",
        sa.Column(
            "ai_analyzed_at",
            sa.DateTime(timezone=True),
            nullable=True,
        ),
    )

    # 2) ai_request_log — observability AI вызовов.
    op.create_table(
        "ai_request_log",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        # Тип сущности (для group-by аналитики). Whitelist в app-коде.
        sa.Column("entity_type", sa.String(length=32), nullable=True),
        sa.Column("entity_id", sa.Integer(), nullable=True),
        # Имя AI-фичи. Whitelist в app-коде (anthropic_client.AIFeature).
        sa.Column("feature", sa.String(length=32), nullable=False),
        sa.Column("prompt_tokens", sa.Integer(), nullable=True),
        sa.Column("completion_tokens", sa.Integer(), nullable=True),
        sa.Column("total_tokens", sa.Integer(), nullable=True),
        sa.Column("model", sa.String(length=64), nullable=True),
        sa.Column("duration_ms", sa.Integer(), nullable=True),
        sa.Column(
            "status",
            sa.String(length=16),
            nullable=False,
            server_default=sa.text("'success'"),
        ),
        sa.Column("error_message", sa.Text(), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
    )

    # Основной индекс для UI/аналитики «AI-запросы юзера X за период».
    op.create_index(
        "idx_ai_log_user_created",
        "ai_request_log",
        ["user_id", sa.text("created_at DESC")],
    )


def downgrade() -> None:
    op.drop_index("idx_ai_log_user_created", table_name="ai_request_log")
    op.drop_table("ai_request_log")
    op.drop_column("contracts", "ai_analyzed_at")
    op.drop_column("contracts", "ai_analysis_json")
