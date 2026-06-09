"""client categories, groups, fx rates + counterparty/contract category cols

Фаза 2: категории клиентов (L/M/S1/S2), холдинги (группы юрлиц), кеш курсов ЦБ РФ,
₽-снимок договора. Аддитивно — safe при rolling-деплое.

Revision ID: 0012_client_categories
Revises: 0011_products_pricing
Create Date: 2026-05-29
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0012_client_categories"
down_revision: Union[str, None] = "0011_products_pricing"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "client_groups",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(length=255), nullable=False),
        sa.Column("category_code", sa.String(length=8), nullable=True),
        sa.Column("turnover_rub", sa.Numeric(precision=18, scale=2), nullable=True),
        sa.Column("category_recalc_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )

    op.create_table(
        "client_categories",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("code", sa.String(length=8), nullable=False),
        sa.Column("name", sa.String(length=128), nullable=False),
        sa.Column("group", sa.String(length=16), nullable=True),
        sa.Column("min_amount", sa.Numeric(precision=18, scale=2), nullable=False, server_default="0"),
        sa.Column("max_amount", sa.Numeric(precision=18, scale=2), nullable=True),
        sa.Column("options", sa.JSON(), nullable=False, server_default="[]"),
        sa.Column("color", sa.String(length=16), nullable=True),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()),
    )
    op.create_index("ix_client_categories_code", "client_categories", ["code"], unique=True)

    op.create_table(
        "fx_rates",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("rate_date", sa.Date(), nullable=False),
        sa.Column("currency", sa.String(length=8), nullable=False),
        sa.Column("rate_to_rub", sa.Numeric(precision=18, scale=6), nullable=False),
        sa.UniqueConstraint("rate_date", "currency", name="uq_fx_date_currency"),
    )
    op.create_index("ix_fx_rates_rate_date", "fx_rates", ["rate_date"])

    op.add_column(
        "counterparties",
        sa.Column("group_id", sa.Integer(), sa.ForeignKey("client_groups.id", ondelete="SET NULL"), nullable=True),
    )
    op.create_index("ix_counterparties_group_id", "counterparties", ["group_id"])
    op.add_column("counterparties", sa.Column("category_code", sa.String(length=8), nullable=True))
    op.create_index("ix_counterparties_category_code", "counterparties", ["category_code"])
    op.add_column("counterparties", sa.Column("turnover_rub", sa.Numeric(precision=18, scale=2), nullable=True))
    op.add_column("counterparties", sa.Column("category_recalc_at", sa.DateTime(timezone=True), nullable=True))

    op.add_column("contracts", sa.Column("total_rub", sa.Numeric(precision=18, scale=2), nullable=True))
    op.add_column("contracts", sa.Column("fx_rate", sa.Numeric(precision=18, scale=6), nullable=True))
    op.add_column("contracts", sa.Column("fx_rate_date", sa.Date(), nullable=True))


def downgrade() -> None:
    op.drop_column("contracts", "fx_rate_date")
    op.drop_column("contracts", "fx_rate")
    op.drop_column("contracts", "total_rub")
    op.drop_index("ix_counterparties_category_code", table_name="counterparties")
    op.drop_index("ix_counterparties_group_id", table_name="counterparties")
    op.drop_column("counterparties", "category_recalc_at")
    op.drop_column("counterparties", "turnover_rub")
    op.drop_column("counterparties", "category_code")
    op.drop_column("counterparties", "group_id")
    op.drop_index("ix_fx_rates_rate_date", table_name="fx_rates")
    op.drop_table("fx_rates")
    op.drop_index("ix_client_categories_code", table_name="client_categories")
    op.drop_table("client_categories")
    op.drop_table("client_groups")
