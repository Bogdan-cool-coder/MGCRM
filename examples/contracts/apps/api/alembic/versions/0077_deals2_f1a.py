"""DEALS 2.0 (Ф1a) — Deal.tags, Deal.product, Deal.lost_reason_id.

Deal.tags    — ARRAY(Text) default {} (теги для фильтрации/group-by на доске).
Deal.product — Text nullable («что смотрят» — свободная строка, а не FK на
               products; причина: Product.code — внутренний слаг CRM, тогда
               как Deal.product — внешнее название продукта/направления, которое
               менеджер вводит вручную. FK добавим только если появится явный
               продуктовый каталог в продажах; пока FK избыточен).
Deal.lost_reason_id — FK на lost_reasons.id (SET NULL). Дополняет свободный
               текст Deal.lost_reason: при выборе из реестра LostReason пишем
               и id, и текст (для совместимости с AmoCRM-импортом где только текст).

Advisory-lock seed-key 77_001. Revision ≤32 chars ✓.
Идемпотентно: ADD COLUMN IF NOT EXISTS.

Revision ID: 0077_deals2_f1a  (15 chars ≤32 ✓)
Revises: 0076_deals2_leads
Create Date: 2026-06-03
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0077_deals2_f1a"
down_revision: Union[str, None] = "0076_deals2_leads"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_LOCK_KEY = 77_001


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_KEY})

    # Deal.tags — ARRAY(Text) default {}
    conn.execute(sa.text(
        "ALTER TABLE deals ADD COLUMN IF NOT EXISTS tags TEXT[] NOT NULL DEFAULT '{}'"
    ))

    # Deal.product — свободная строка (nullable)
    conn.execute(sa.text(
        "ALTER TABLE deals ADD COLUMN IF NOT EXISTS product TEXT"
    ))

    # Deal.lost_reason_id — FK → lost_reasons.id (SET NULL, nullable)
    conn.execute(sa.text(
        "ALTER TABLE deals ADD COLUMN IF NOT EXISTS lost_reason_id INTEGER"
    ))
    # FK constraint — ADD IF NOT EXISTS через проверку information_schema
    conn.execute(sa.text("""
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM information_schema.table_constraints
                WHERE table_name = 'deals'
                  AND constraint_name = 'fk_deals_lost_reason_id'
            ) THEN
                ALTER TABLE deals
                    ADD CONSTRAINT fk_deals_lost_reason_id
                    FOREIGN KEY (lost_reason_id)
                    REFERENCES lost_reasons(id)
                    ON DELETE SET NULL;
            END IF;
        END $$;
    """))

    # GIN-индекс на deals.tags для быстрого тэг-фильтра
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_deals_tags ON deals USING GIN (tags)"
    ))

    # Индекс на deals.product для фильтра по продукту
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_deals_product ON deals (product) WHERE product IS NOT NULL"
    ))


def downgrade() -> None:
    conn = op.get_bind()

    conn.execute(sa.text(
        "DROP INDEX IF EXISTS ix_deals_product"
    ))
    conn.execute(sa.text(
        "DROP INDEX IF EXISTS ix_deals_tags"
    ))
    conn.execute(sa.text(
        "ALTER TABLE deals DROP CONSTRAINT IF EXISTS fk_deals_lost_reason_id"
    ))
    conn.execute(sa.text(
        "ALTER TABLE deals DROP COLUMN IF EXISTS lost_reason_id"
    ))
    conn.execute(sa.text(
        "ALTER TABLE deals DROP COLUMN IF EXISTS product"
    ))
    conn.execute(sa.text(
        "ALTER TABLE deals DROP COLUMN IF EXISTS tags"
    ))
