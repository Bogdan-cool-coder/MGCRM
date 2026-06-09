"""products, plans, prices, contract_items, settings + contract pricing cols

Фаза 1: раздел продуктов/прайса. Позиции договора с ценой-снимком, скидка на итог,
key/value настройки (лимит скидки). Всё аддитивно — safe при rolling-деплое (2 реплики).

Revision ID: 0011_products_pricing
Revises: 0010_signed_attach
Create Date: 2026-05-29
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0011_products_pricing"
down_revision: Union[str, None] = "0010_signed_attach"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "products",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("code", sa.String(length=64), nullable=False),
        sa.Column("name", sa.String(length=255), nullable=False),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("group", sa.String(length=128), nullable=True),
        sa.Column("pricing_type", sa.String(length=16), nullable=False, server_default="fixed"),
        sa.Column("maps_to_product_code", sa.String(length=32), nullable=True),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_products_code", "products", ["code"], unique=True)

    op.create_table(
        "product_plans",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("product_id", sa.Integer(), sa.ForeignKey("products.id", ondelete="CASCADE"), nullable=False),
        sa.Column("name", sa.String(length=255), nullable=False),
        sa.Column("unit", sa.String(length=32), nullable=False, server_default="year"),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
    )
    op.create_index("ix_product_plans_product_id", "product_plans", ["product_id"])

    op.create_table(
        "product_prices",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("product_id", sa.Integer(), sa.ForeignKey("products.id", ondelete="CASCADE"), nullable=False),
        sa.Column("plan_id", sa.Integer(), sa.ForeignKey("product_plans.id", ondelete="CASCADE"), nullable=True),
        sa.Column("currency", sa.String(length=8), nullable=False),
        sa.Column("amount", sa.Numeric(precision=18, scale=2), nullable=False),
        sa.UniqueConstraint("product_id", "plan_id", "currency", name="uq_product_price"),
    )
    op.create_index("ix_product_prices_product_id", "product_prices", ["product_id"])

    op.create_table(
        "contract_items",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("contract_id", sa.Integer(), sa.ForeignKey("contracts.id", ondelete="CASCADE"), nullable=False),
        sa.Column("product_id", sa.Integer(), sa.ForeignKey("products.id"), nullable=False),
        sa.Column("plan_id", sa.Integer(), sa.ForeignKey("product_plans.id"), nullable=True),
        sa.Column("name_snapshot", sa.String(length=255), nullable=False),
        sa.Column("currency", sa.String(length=8), nullable=False),
        sa.Column("qty", sa.Numeric(precision=18, scale=2), nullable=False, server_default="1"),
        sa.Column("unit_price", sa.Numeric(precision=18, scale=2), nullable=False),
        sa.Column("line_total", sa.Numeric(precision=18, scale=2), nullable=False),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
    )
    op.create_index("ix_contract_items_contract_id", "contract_items", ["contract_id"])

    op.create_table(
        "settings",
        sa.Column("key", sa.String(length=64), primary_key=True),
        sa.Column("value", sa.String(length=512), nullable=True),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )

    op.add_column("contracts", sa.Column("currency", sa.String(length=8), nullable=True))
    op.add_column("contracts", sa.Column("subtotal", sa.Numeric(precision=18, scale=2), nullable=False, server_default="0"))
    op.add_column("contracts", sa.Column("discount_pct", sa.Numeric(precision=5, scale=2), nullable=False, server_default="0"))
    op.add_column("contracts", sa.Column("discount_amount", sa.Numeric(precision=18, scale=2), nullable=False, server_default="0"))
    op.add_column("contracts", sa.Column("total", sa.Numeric(precision=18, scale=2), nullable=False, server_default="0"))


def downgrade() -> None:
    op.drop_column("contracts", "total")
    op.drop_column("contracts", "discount_amount")
    op.drop_column("contracts", "discount_pct")
    op.drop_column("contracts", "subtotal")
    op.drop_column("contracts", "currency")
    op.drop_table("settings")
    op.drop_index("ix_contract_items_contract_id", table_name="contract_items")
    op.drop_table("contract_items")
    op.drop_index("ix_product_prices_product_id", table_name="product_prices")
    op.drop_table("product_prices")
    op.drop_index("ix_product_plans_product_id", table_name="product_plans")
    op.drop_table("product_plans")
    op.drop_index("ix_products_code", table_name="products")
    op.drop_table("products")
