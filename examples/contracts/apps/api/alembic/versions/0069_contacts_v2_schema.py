"""CONTACTS 2.0 Ф0 — DDL: расширение Company/Contact + новые таблицы + FK + seed.

Слияние разделов Контакты/Компании/Контрагенты в единый «Контакты».
Эта миграция — только схема (DDL) + seed справочников:
- расширяет crm_companies реквизитами стороны договора + классификацией;
- расширяет crm_contacts (source/tags/status);
- создаёт crm_company_types, crm_contact_positions, crm_contact_company_links,
  crm_folders, crm_files;
- добавляет company_id FK на deals/contracts/client_subscriptions/leads
  (рядом с counterparty_id — НЕ заменяет, см. CONTACTS-2.0 Master Plan);
- сидит справочник crm_company_types (insert-missing, advisory-lock).

Data-migration (Counterparty→Company, LegacyContact→Contact, заполнение
company_id) — в отдельной миграции 0070.

🔴 БЕЗОПАСНОСТЬ: counterparties и counterparty_id колонки НЕ трогаем.

Advisory-lock seed-key 70_010 (CONTACTS 2.0 Ф0 schema).

Revision ID: 0069_contacts_v2_schema  (22 chars ≤32 ✓)
Revises: 0068_activity_target_nullable
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0069_contacts_v2_schema"
down_revision: Union[str, None] = "0068_activity_target_nullable"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# CONTACTS 2.0 Ф0 schema seed-key.
_SEED_LOCK_CONTACTS_V2 = 70_010

# Сид типов компаний (insert-missing по name).
_COMPANY_TYPES = [
    ("Строительная компания", 10),
    ("Агентство недвижимости", 20),
    ("Подрядчик", 30),
    ("Партнёр", 40),
]
# Минимальный сид должностей.
_CONTACT_POSITIONS = [
    ("Директор", 10),
    ("Менеджер", 20),
    ("Бухгалтер", 30),
]


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_CONTACTS_V2},
    )

    # ============ Справочники ============
    op.create_table(
        "crm_company_types",
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column("name", sa.String(128), nullable=False),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default="true"),
        sa.Column(
            "created_at", sa.DateTime(timezone=True),
            server_default=sa.text("now()"), nullable=False,
        ),
        sa.PrimaryKeyConstraint("id"),
        sa.UniqueConstraint("name", name="uq_crm_company_type_name"),
    )
    op.create_table(
        "crm_contact_positions",
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column("name", sa.String(128), nullable=False),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default="true"),
        sa.Column(
            "created_at", sa.DateTime(timezone=True),
            server_default=sa.text("now()"), nullable=False,
        ),
        sa.PrimaryKeyConstraint("id"),
        sa.UniqueConstraint("name", name="uq_crm_contact_position_name"),
    )

    # ============ crm_companies — реквизиты + классификация ============
    company_cols = [
        sa.Column("name", sa.String(255), nullable=True),
        sa.Column("full_legal_form", sa.String(255), nullable=True),
        sa.Column("legal_form", sa.String(64), nullable=True),
        sa.Column("gender_ending_oe", sa.String(16), nullable=True),
        sa.Column("country_code", sa.String(2), nullable=True),
        sa.Column("director_position", sa.String(128), nullable=True),
        sa.Column("director_genitive", sa.String(255), nullable=True),
        sa.Column("director_short", sa.String(128), nullable=True),
        sa.Column("acts_basis", sa.String(64), nullable=True),
        sa.Column("tax_id_label", sa.String(16), nullable=True),
        sa.Column("address", sa.Text(), nullable=True),
        sa.Column("bank", sa.String(255), nullable=True),
        sa.Column("bank_code_label", sa.String(32), nullable=True),
        sa.Column("bank_code", sa.String(64), nullable=True),
        sa.Column("account", sa.String(64), nullable=True),
        sa.Column("turnover_rub", sa.Numeric(18, 2), nullable=True),
        sa.Column("source", sa.String(32), nullable=True),
        sa.Column("holding_role", sa.String(16), nullable=True),
        sa.Column(
            "responsible_user_id", sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
        ),
        sa.Column(
            "company_type_id", sa.Integer(),
            sa.ForeignKey("crm_company_types.id", ondelete="SET NULL"), nullable=True,
        ),
        sa.Column(
            "tags", postgresql.ARRAY(sa.Text()),
            nullable=False, server_default="{}",
        ),
    ]
    for col in company_cols:
        op.add_column("crm_companies", col)
    op.create_index("ix_crm_companies_name", "crm_companies", ["name"])
    op.create_index(
        "ix_crm_companies_responsible_user", "crm_companies", ["responsible_user_id"],
    )

    # ============ crm_contacts — source/tags/status ============
    op.add_column("crm_contacts", sa.Column("source", sa.String(32), nullable=True))
    op.add_column(
        "crm_contacts",
        sa.Column(
            "tags", postgresql.ARRAY(sa.Text()),
            nullable=False, server_default="{}",
        ),
    )
    op.add_column(
        "crm_contacts",
        sa.Column("status", sa.String(32), nullable=True, server_default="active"),
    )

    # ============ crm_contact_company_links (M2M) ============
    op.create_table(
        "crm_contact_company_links",
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column(
            "contact_id", sa.Integer(),
            sa.ForeignKey("crm_contacts.id", ondelete="CASCADE"), nullable=False,
        ),
        sa.Column(
            "company_id", sa.Integer(),
            sa.ForeignKey("crm_companies.id", ondelete="CASCADE"), nullable=False,
        ),
        sa.Column("position", sa.String(128), nullable=True),
        sa.Column(
            "position_id", sa.Integer(),
            sa.ForeignKey("crm_contact_positions.id", ondelete="SET NULL"), nullable=True,
        ),
        sa.Column(
            "employment_status", sa.String(16),
            nullable=False, server_default="works",
        ),
        sa.Column("is_primary", sa.Boolean(), nullable=False, server_default="false"),
        sa.Column(
            "created_at", sa.DateTime(timezone=True),
            server_default=sa.text("now()"), nullable=False,
        ),
        sa.Column(
            "updated_at", sa.DateTime(timezone=True),
            server_default=sa.text("now()"), nullable=False,
        ),
        sa.PrimaryKeyConstraint("id"),
        sa.UniqueConstraint("contact_id", "company_id", name="uq_contact_company_link"),
    )
    op.create_index(
        "ix_ccl_contact", "crm_contact_company_links", ["contact_id"],
    )
    op.create_index(
        "ix_ccl_company", "crm_contact_company_links", ["company_id"],
    )

    # ============ crm_folders / crm_files ============
    op.create_table(
        "crm_folders",
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column("owner_entity_type", sa.String(16), nullable=False),
        sa.Column("owner_entity_id", sa.Integer(), nullable=False),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("is_system", sa.Boolean(), nullable=False, server_default="false"),
        sa.Column(
            "created_at", sa.DateTime(timezone=True),
            server_default=sa.text("now()"), nullable=False,
        ),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index(
        "ix_crm_folders_owner", "crm_folders",
        ["owner_entity_type", "owner_entity_id"],
    )
    op.create_table(
        "crm_files",
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column(
            "folder_id", sa.Integer(),
            sa.ForeignKey("crm_folders.id", ondelete="CASCADE"), nullable=False,
        ),
        sa.Column("owner_entity_type", sa.String(16), nullable=False),
        sa.Column("owner_entity_id", sa.Integer(), nullable=False),
        sa.Column("file_path", sa.String(512), nullable=False),
        sa.Column("original_name", sa.String(255), nullable=False),
        sa.Column("file_size", sa.BigInteger(), nullable=False),
        sa.Column("mime_type", sa.String(255), nullable=True),
        sa.Column(
            "uploaded_by_user_id", sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True,
        ),
        sa.Column(
            "created_at", sa.DateTime(timezone=True),
            server_default=sa.text("now()"), nullable=False,
        ),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index("ix_crm_files_folder", "crm_files", ["folder_id"])
    op.create_index(
        "ix_crm_files_owner", "crm_files",
        ["owner_entity_type", "owner_entity_id"],
    )

    # ============ company_id FK на бизнес-сущностях (рядом с counterparty_id) ============
    op.add_column(
        "deals",
        sa.Column(
            "company_id", sa.Integer(),
            sa.ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True,
        ),
    )
    op.create_index("ix_deals_company_id", "deals", ["company_id"])

    op.add_column(
        "contracts",
        sa.Column(
            "company_id", sa.Integer(),
            sa.ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True,
        ),
    )
    op.create_index("ix_contracts_company_id", "contracts", ["company_id"])

    op.add_column(
        "client_subscriptions",
        sa.Column(
            "company_id", sa.Integer(),
            sa.ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True,
        ),
    )
    op.create_index(
        "ix_client_subscriptions_company_id", "client_subscriptions", ["company_id"],
    )

    op.add_column(
        "leads",
        sa.Column(
            "converted_to_company_id", sa.Integer(),
            sa.ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True,
        ),
    )

    # ============ Seed справочников (insert-missing) ============
    for name, sort_order in _COMPANY_TYPES:
        conn.execute(
            sa.text(
                "INSERT INTO crm_company_types (name, sort_order, is_active) "
                "VALUES (:name, :so, true) ON CONFLICT (name) DO NOTHING"
            ),
            {"name": name, "so": sort_order},
        )
    for name, sort_order in _CONTACT_POSITIONS:
        conn.execute(
            sa.text(
                "INSERT INTO crm_contact_positions (name, sort_order, is_active) "
                "VALUES (:name, :so, true) ON CONFLICT (name) DO NOTHING"
            ),
            {"name": name, "so": sort_order},
        )


def downgrade() -> None:
    # company_id FK на бизнес-сущностях.
    op.drop_column("leads", "converted_to_company_id")
    op.drop_index("ix_client_subscriptions_company_id", "client_subscriptions")
    op.drop_column("client_subscriptions", "company_id")
    op.drop_index("ix_contracts_company_id", "contracts")
    op.drop_column("contracts", "company_id")
    op.drop_index("ix_deals_company_id", "deals")
    op.drop_column("deals", "company_id")

    # Файлы.
    op.drop_index("ix_crm_files_owner", "crm_files")
    op.drop_index("ix_crm_files_folder", "crm_files")
    op.drop_table("crm_files")
    op.drop_index("ix_crm_folders_owner", "crm_folders")
    op.drop_table("crm_folders")

    # M2M.
    op.drop_index("ix_ccl_company", "crm_contact_company_links")
    op.drop_index("ix_ccl_contact", "crm_contact_company_links")
    op.drop_table("crm_contact_company_links")

    # crm_contacts расширения.
    op.drop_column("crm_contacts", "status")
    op.drop_column("crm_contacts", "tags")
    op.drop_column("crm_contacts", "source")

    # crm_companies расширения.
    op.drop_index("ix_crm_companies_responsible_user", "crm_companies")
    op.drop_index("ix_crm_companies_name", "crm_companies")
    for col in (
        "tags", "company_type_id", "responsible_user_id", "holding_role",
        "source", "turnover_rub", "account", "bank_code", "bank_code_label",
        "bank", "address", "tax_id_label", "acts_basis", "director_short",
        "director_genitive", "director_position", "country_code",
        "gender_ending_oe", "legal_form", "full_legal_form", "name",
    ):
        op.drop_column("crm_companies", col)

    # Справочники.
    op.drop_table("crm_contact_positions")
    op.drop_table("crm_company_types")
