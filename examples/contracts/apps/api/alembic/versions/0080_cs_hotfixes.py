"""CS-hotfixes — company-first подписки, renewal-маркер, регион РФ.

Зона Customer Success (реестр подписок). Содержит:

1. client_subscriptions.counterparty_id → NULLABLE (DROP NOT NULL).
   Company стала источником истины; новая Company без legacy-зеркала
   Counterparty теперь может завести подписку только по company_id.

2. client_subscriptions.last_renewal_generated_at (TIMESTAMPTZ, nullable) —
   маркер генерации renewal-сделки на самой подписке. Дедуп renewal-cron
   опирается на него (а не только на наличие Deal), чтобы удаление сделки
   не вызывало повторную генерацию в окне дедупа.

3. Сид региона 'ru' (Россия / РФ) — раньше RU-клиенты падали в region=NULL
   и смешивались с KZ-подписками.

Идемпотентность: ADD COLUMN IF NOT EXISTS, DROP NOT NULL (повторно безопасно),
INSERT ... WHERE NOT EXISTS для региона. Всё под advisory-lock seed-key 74_005.

Revision ID: 0080_cs_hotfixes   (16 chars ≤32 ✓)
Revises: 0079_inbound_target_deal
Create Date: 2026-06-03
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0080_cs_hotfixes"
down_revision: Union[str, None] = "0079_inbound_target_deal"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_CS_HOTFIX = 74_005


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _SEED_LOCK_CS_HOTFIX})

    # 1. counterparty_id → NULLABLE
    conn.execute(
        sa.text(
            "ALTER TABLE client_subscriptions "
            "ALTER COLUMN counterparty_id DROP NOT NULL"
        )
    )

    # 2. last_renewal_generated_at
    conn.execute(
        sa.text(
            "ALTER TABLE client_subscriptions "
            "ADD COLUMN IF NOT EXISTS last_renewal_generated_at TIMESTAMPTZ"
        )
    )

    # 3. Сид региона РФ (idempotent — INSERT только если code='ru' ещё нет).
    #    sort_order=4 — после ca/gcc/caucasus (см. REGIONS в customer_success.py).
    conn.execute(
        sa.text(
            "INSERT INTO regions (code, name, sort_order) "
            "SELECT 'ru', 'Россия / РФ', 4 "
            "WHERE NOT EXISTS (SELECT 1 FROM regions WHERE code = 'ru')"
        )
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _SEED_LOCK_CS_HOTFIX})

    conn.execute(
        sa.text(
            "ALTER TABLE client_subscriptions "
            "DROP COLUMN IF EXISTS last_renewal_generated_at"
        )
    )
    # Регион 'ru' и NOT NULL обратно НЕ ставим: к моменту даунгрейда могут
    # существовать подписки с counterparty_id IS NULL и/или привязанные к
    # региону 'ru'. Возврат NOT NULL/DELETE region упал бы на проде. Оставляем
    # как есть (forward-only для этих двух изменений — сознательно).
