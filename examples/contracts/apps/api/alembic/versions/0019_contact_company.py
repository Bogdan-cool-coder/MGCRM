"""crm_contacts, crm_companies (Эпик 1.2): Contact + Company как новые сущности.

Аддитивная миграция: создаёт две новые таблицы рядом с Counterparty (legacy НЕ
модифицируется). Counterparty продолжает использоваться Contract / Subscription /
Deal и т.п. для совместимости. Когда новая Company соответствует существующему
Counterparty — обратная связь хранится в `crm_companies.counterparty_id` (SET NULL
on delete), а не как поле на Counterparty.

Имена таблиц `crm_*` выбраны намеренно, чтобы не конфликтовать с legacy `contacts`
(оно создано миграцией 0013_crm_card и принадлежит CRM-карточке Counterparty).

Revision ID: 0019_contact_company
Revises: 0018_leads
Create Date: 2026-05-30
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0019_contact_company"
down_revision: Union[str, None] = "0018_leads"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ---- crm_companies ----
    op.create_table(
        "crm_companies",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("legal_name", sa.String(length=255), nullable=False),
        sa.Column("short_name", sa.String(length=128), nullable=True),
        sa.Column("tax_id", sa.String(length=32), nullable=True),
        sa.Column("country", sa.String(length=2), nullable=True),
        sa.Column("city", sa.String(length=128), nullable=True),
        sa.Column("website", sa.String(length=255), nullable=True),
        sa.Column("phone", sa.String(length=64), nullable=True),
        sa.Column("email", sa.String(length=255), nullable=True),
        sa.Column("industry", sa.String(length=64), nullable=True),
        sa.Column("notes", sa.Text(), nullable=True),
        sa.Column(
            "group_id",
            sa.Integer(),
            sa.ForeignKey("client_groups.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("category_code", sa.String(length=8), nullable=True),
        sa.Column(
            "counterparty_id",
            sa.Integer(),
            sa.ForeignKey("counterparties.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
    )
    op.create_index("ix_crm_companies_tax_id", "crm_companies", ["tax_id"])
    op.create_index("ix_crm_companies_email", "crm_companies", ["email"])
    op.create_index("ix_crm_companies_country", "crm_companies", ["country"])
    op.create_index("ix_crm_companies_category_code", "crm_companies", ["category_code"])

    # ---- crm_contacts ----
    op.create_table(
        "crm_contacts",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("full_name", sa.String(length=255), nullable=False),
        sa.Column("email", sa.String(length=255), nullable=True),
        sa.Column("phone", sa.String(length=64), nullable=True),
        sa.Column("position", sa.String(length=128), nullable=True),
        sa.Column(
            "company_id",
            sa.Integer(),
            sa.ForeignKey("crm_companies.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "is_primary",
            sa.Boolean(),
            nullable=False,
            server_default=sa.false(),
        ),
        sa.Column(
            "owner_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("tg_username", sa.String(length=64), nullable=True),
        sa.Column("notes", sa.Text(), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
    )
    op.create_index("ix_crm_contacts_email", "crm_contacts", ["email"])
    op.create_index("ix_crm_contacts_phone", "crm_contacts", ["phone"])
    op.create_index("ix_crm_contacts_company_id", "crm_contacts", ["company_id"])
    op.create_index("ix_crm_contacts_owner_id", "crm_contacts", ["owner_id"])


def downgrade() -> None:
    op.drop_index("ix_crm_contacts_owner_id", table_name="crm_contacts")
    op.drop_index("ix_crm_contacts_company_id", table_name="crm_contacts")
    op.drop_index("ix_crm_contacts_phone", table_name="crm_contacts")
    op.drop_index("ix_crm_contacts_email", table_name="crm_contacts")
    op.drop_table("crm_contacts")

    op.drop_index("ix_crm_companies_category_code", table_name="crm_companies")
    op.drop_index("ix_crm_companies_country", table_name="crm_companies")
    op.drop_index("ix_crm_companies_email", table_name="crm_companies")
    op.drop_index("ix_crm_companies_tax_id", table_name="crm_companies")
    op.drop_table("crm_companies")
