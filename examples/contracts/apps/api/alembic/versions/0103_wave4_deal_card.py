"""Wave 4 (deal-card rework): deal_products + deal_contacts + expected dates.

Аддитивно, под advisory-lock (scale=2 параллельные старты api). Все DDL —
с проверкой существования через inspector (повторный прогон не падает).

Создаёт:
- deals.expected_sign_date / expected_payment_date (Date, nullable).
- deal_products (line items продукта сделки + амаунт-снимок).
- deal_contacts (M2M сделка↔контакт, unique(deal_id, contact_id)).

Данные не сидим. deal_card_fields / stage_required_fields живут в
Pipeline.settings JSONB (без миграции схемы).

Revision ID: 0103_wave4_deal_card
Revises: 0102_wave3_refs
Create Date: 2026-06-04
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0103_wave4_deal_card"
down_revision: str | None = "0102_wave3_refs"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Advisory-lock ключ (диапазон 94_0xx свободен).
_LOCK_WAVE4 = 94_001


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_WAVE4})
    insp = sa.inspect(conn)

    # --- 1. deals: expected_sign_date / expected_payment_date ---
    deal_cols = {c["name"] for c in insp.get_columns("deals")}
    if "expected_sign_date" not in deal_cols:
        op.add_column("deals", sa.Column("expected_sign_date", sa.Date(), nullable=True))
    if "expected_payment_date" not in deal_cols:
        op.add_column("deals", sa.Column("expected_payment_date", sa.Date(), nullable=True))

    tables = set(insp.get_table_names())

    # --- 2. deal_products ---
    if "deal_products" not in tables:
        op.create_table(
            "deal_products",
            sa.Column("id", sa.Integer(), primary_key=True),
            sa.Column(
                "deal_id", sa.Integer(),
                sa.ForeignKey("deals.id", ondelete="CASCADE"), nullable=False,
            ),
            sa.Column(
                "product_id", sa.Integer(),
                sa.ForeignKey("products.id"), nullable=False,
            ),
            sa.Column(
                "plan_id", sa.Integer(),
                sa.ForeignKey("product_plans.id"), nullable=True,
            ),
            sa.Column("quantity", sa.Numeric(18, 2), nullable=False, server_default="1"),
            sa.Column("unit_price", sa.Numeric(18, 2), nullable=False),
            sa.Column("currency", sa.String(8), nullable=False),
            sa.Column("amount", sa.Numeric(18, 2), nullable=False),
            sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
            sa.Column(
                "created_at", sa.DateTime(timezone=True),
                server_default=sa.func.now(), nullable=False,
            ),
        )
        op.create_index("ix_deal_products_deal_id", "deal_products", ["deal_id"])

    # --- 3. deal_contacts ---
    if "deal_contacts" not in tables:
        op.create_table(
            "deal_contacts",
            sa.Column("id", sa.Integer(), primary_key=True),
            sa.Column(
                "deal_id", sa.Integer(),
                sa.ForeignKey("deals.id", ondelete="CASCADE"), nullable=False,
            ),
            sa.Column(
                "contact_id", sa.Integer(),
                sa.ForeignKey("crm_contacts.id", ondelete="CASCADE"), nullable=False,
            ),
            sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
            sa.Column(
                "created_at", sa.DateTime(timezone=True),
                server_default=sa.func.now(), nullable=False,
            ),
            sa.UniqueConstraint("deal_id", "contact_id", name="uq_deal_contact"),
        )
        op.create_index("ix_deal_contacts_deal_id", "deal_contacts", ["deal_id"])
        op.create_index("ix_deal_contacts_contact_id", "deal_contacts", ["contact_id"])


def downgrade() -> None:
    conn = op.get_bind()
    insp = sa.inspect(conn)
    tables = set(insp.get_table_names())
    if "deal_contacts" in tables:
        op.drop_table("deal_contacts")
    if "deal_products" in tables:
        op.drop_table("deal_products")
    deal_cols = {c["name"] for c in insp.get_columns("deals")}
    if "expected_payment_date" in deal_cols:
        op.drop_column("deals", "expected_payment_date")
    if "expected_sign_date" in deal_cols:
        op.drop_column("deals", "expected_sign_date")
