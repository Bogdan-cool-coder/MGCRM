"""Tech Sprint Фаза 0 (задача 4): per-webhook retry/timeout settings.

До этого MAX_ATTEMPTS=6, RETRY_SCHEDULE_SECONDS, HTTP_TIMEOUT_SECONDS были
хардкодом в webhook_dispatcher.py — одинаковы для всех webhook'ов. Это OK для
MVP, но не работает когда один подписчик — медленный SaaS с высоким timeout'ом
(нужно 30s), а другой — быстрый internal endpoint, и retry-каскад 1m/5m/30m
слишком долгий для срочного flow.

Добавляем три поля на `webhooks`:
- max_attempts INT DEFAULT 5         — кастомный max retry (раньше глобально 6)
- backoff_seconds INT DEFAULT 60     — стартовый backoff в секундах (раньше 60s)
- timeout_seconds INT DEFAULT 10     — HTTP timeout одной попытки (раньше 10s)

Существующие webhook'и получат server_default → 5/60/10 (5 — небольшое снижение
с 6, но это вписывается в принцип «совместимости в большую сторону» — никто
не настраивал явно, и большинство не дотягивали до 6-й попытки).

DDL-only. Advisory-lock env.py уже стоит.

Revision ID: 0033_webhook_settings   (≤32 chars ✓)
Revises: 0032_quiz_randomize_q
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0033_webhook_settings"
down_revision: Union[str, None] = "0032_quiz_randomize_q"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column(
        "webhooks",
        sa.Column(
            "max_attempts",
            sa.Integer(),
            nullable=False,
            server_default=sa.text("5"),
        ),
    )
    op.add_column(
        "webhooks",
        sa.Column(
            "backoff_seconds",
            sa.Integer(),
            nullable=False,
            server_default=sa.text("60"),
        ),
    )
    op.add_column(
        "webhooks",
        sa.Column(
            "timeout_seconds",
            sa.Integer(),
            nullable=False,
            server_default=sa.text("10"),
        ),
    )


def downgrade() -> None:
    op.drop_column("webhooks", "timeout_seconds")
    op.drop_column("webhooks", "backoff_seconds")
    op.drop_column("webhooks", "max_attempts")
