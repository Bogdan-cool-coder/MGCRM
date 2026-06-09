"""DEALS 2.0 (Ф1c) — inbound_messages.target_deal_id / target_deal_created.

Входящий поток (webhook каналов / public form submit) теперь создаёт
Company + Deal в sales-воронке (этап code='new') вместо Lead. Чтобы линковать
входящее сообщение к созданной сделке — добавляем:

- target_deal_id  (FK deals.id, ON DELETE SET NULL, nullable, indexed)
- target_deal_created (bool, default false)

target_lead_id / target_lead_created НЕ удаляются (deprecate — старые
немигрированные сообщения сохраняют ссылку на Lead).

Идемпотентность: ADD COLUMN IF NOT EXISTS + CREATE INDEX IF NOT EXISTS, под
advisory-lock seed-key 74_004 (DEALS 2.0 inbound→deal).

Revision ID: 0079_inbound_target_deal   (24 chars ≤32 ✓)
Revises: 0078_deals2_pipeline_settings
Create Date: 2026-06-03

Примечание по веткам: 0077/0078/0079 создавались параллельно соседними агентами
(все три ответвлялись от 0076). Чтобы не плодить третью голову, 0079 чейнится на
0078. Финальный merge 0077 + 0079 в одну голову — за координацией (отдельная
merge-миграция, когда параллельные ветки сольются).
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0079_inbound_target_deal"
down_revision: Union[str, None] = "0078_deals2_pipeline_settings"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_INBOUND_DEAL = 74_004


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_INBOUND_DEAL},
    )

    conn.execute(
        sa.text(
            "ALTER TABLE inbound_messages "
            "ADD COLUMN IF NOT EXISTS target_deal_id INTEGER"
        )
    )
    conn.execute(
        sa.text(
            "ALTER TABLE inbound_messages "
            "ADD COLUMN IF NOT EXISTS target_deal_created BOOLEAN "
            "NOT NULL DEFAULT false"
        )
    )
    # FK (idempotent — pg не поддерживает IF NOT EXISTS для ADD CONSTRAINT,
    # поэтому DO-блок с проверкой pg_constraint).
    conn.execute(
        sa.text(
            "DO $$ BEGIN "
            "  IF NOT EXISTS ("
            "    SELECT 1 FROM pg_constraint "
            "    WHERE conname = 'fk_inbound_messages_target_deal_id'"
            "  ) THEN "
            "    ALTER TABLE inbound_messages "
            "      ADD CONSTRAINT fk_inbound_messages_target_deal_id "
            "      FOREIGN KEY (target_deal_id) REFERENCES deals(id) "
            "      ON DELETE SET NULL; "
            "  END IF; "
            "END $$;"
        )
    )
    conn.execute(
        sa.text(
            "CREATE INDEX IF NOT EXISTS ix_inbound_messages_target_deal_id "
            "ON inbound_messages (target_deal_id)"
        )
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("DROP INDEX IF EXISTS ix_inbound_messages_target_deal_id")
    )
    conn.execute(
        sa.text(
            "ALTER TABLE inbound_messages "
            "DROP CONSTRAINT IF EXISTS fk_inbound_messages_target_deal_id"
        )
    )
    conn.execute(
        sa.text(
            "ALTER TABLE inbound_messages "
            "DROP COLUMN IF EXISTS target_deal_created"
        )
    )
    conn.execute(
        sa.text(
            "ALTER TABLE inbound_messages DROP COLUMN IF EXISTS target_deal_id"
        )
    )
