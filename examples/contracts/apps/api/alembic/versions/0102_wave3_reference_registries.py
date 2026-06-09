"""Wave 3: admin reference registries (countries / cities / sources / product_groups).

Аддитивно, под advisory-lock (scale=2 параллельные старты api). Сидеры — insert-missing
по естественным ключам; backfill product_groups из distinct products.group строк.

Создаёт:
- crm_countries (code unique, RU/EN name, phone_prefix) + seed региона.
- crm_cities (FK→crm_countries.code, unique(country_code,name)) + seed крупных городов.
- crm_sources (code unique) + seed текущих фронтовых CONTACT_SOURCE_OPTIONS.
- product_groups (name unique) + Product.group_id (FK SET NULL) + backfill из
  distinct products.group строк (group-строка остаётся для legacy/display).

Все INSERT/backfill идемпотентны (повторный прогон ничего не дублирует).

Revision ID: 0102_wave3_refs
Revises: 0101_dashboard_cfg
Create Date: 2026-06-04
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0102_wave3_refs"
down_revision: str | None = "0101_dashboard_cfg"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Advisory-lock ключи (диапазон 93_0xx свободен).
_LOCK_WAVE3 = 93_001


# (code, RU-name, EN-name, phone_prefix)
_COUNTRIES = [
    ("kz", "Казахстан", "Kazakhstan", "+7"),
    ("uz", "Узбекистан", "Uzbekistan", "+998"),
    ("ru", "Россия", "Russia", "+7"),
    ("kg", "Кыргызстан", "Kyrgyzstan", "+996"),
    ("tj", "Таджикистан", "Tajikistan", "+992"),
    ("tm", "Туркменистан", "Turkmenistan", "+993"),
    ("az", "Азербайджан", "Azerbaijan", "+994"),
    ("ge", "Грузия", "Georgia", "+995"),
    ("am", "Армения", "Armenia", "+374"),
    ("ae", "ОАЭ", "United Arab Emirates", "+971"),
    ("tr", "Турция", "Turkey", "+90"),
    ("by", "Беларусь", "Belarus", "+375"),
    ("ua", "Украина", "Ukraine", "+380"),
    ("us", "США", "United States", "+1"),
]

_CITIES = [
    ("kz", "Алматы"), ("kz", "Астана"), ("kz", "Шымкент"), ("kz", "Караганда"),
    ("uz", "Ташкент"), ("uz", "Самарканд"),
    ("ru", "Москва"), ("ru", "Санкт-Петербург"),
    ("kg", "Бишкек"), ("az", "Баку"), ("ge", "Тбилиси"), ("am", "Ереван"),
    ("ae", "Дубай"), ("ae", "Абу-Даби"), ("tr", "Стамбул"), ("by", "Минск"),
]

_SOURCES = [
    ("own_contact", "Свой контакт"),
    ("cold_call", "Холодный звонок"),
    ("partner", "Партнёр"),
    ("internet", "Интернет"),
    ("lead", "Лид"),
]


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_WAVE3})
    insp = sa.inspect(conn)
    existing_tables = set(insp.get_table_names())

    # ---- crm_countries ----
    if "crm_countries" not in existing_tables:
        op.create_table(
            "crm_countries",
            sa.Column("id", sa.Integer(), primary_key=True),
            sa.Column("code", sa.String(length=2), nullable=False),
            sa.Column("name", sa.String(length=128), nullable=False),
            sa.Column("name_en", sa.String(length=128), nullable=True),
            sa.Column("phone_prefix", sa.String(length=8), nullable=True),
            sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
            sa.Column("is_active", sa.Boolean(), nullable=False, server_default="true"),
            sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
            sa.UniqueConstraint("code", name="uq_crm_countries_code"),
        )
        op.create_index("ix_crm_countries_code", "crm_countries", ["code"], unique=True)

    # ---- crm_cities ----
    if "crm_cities" not in existing_tables:
        op.create_table(
            "crm_cities",
            sa.Column("id", sa.Integer(), primary_key=True),
            sa.Column("country_code", sa.String(length=2), nullable=False),
            sa.Column("name", sa.String(length=128), nullable=False),
            sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
            sa.Column("is_active", sa.Boolean(), nullable=False, server_default="true"),
            sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
            sa.ForeignKeyConstraint(
                ["country_code"], ["crm_countries.code"],
                name="fk_crm_cities_country", ondelete="CASCADE",
            ),
            sa.UniqueConstraint("country_code", "name", name="uq_city_country_name"),
        )
        op.create_index("ix_crm_cities_country_code", "crm_cities", ["country_code"])

    # ---- crm_sources ----
    if "crm_sources" not in existing_tables:
        op.create_table(
            "crm_sources",
            sa.Column("id", sa.Integer(), primary_key=True),
            sa.Column("code", sa.String(length=32), nullable=False),
            sa.Column("name", sa.String(length=128), nullable=False),
            sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
            sa.Column("is_active", sa.Boolean(), nullable=False, server_default="true"),
            sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
            sa.UniqueConstraint("code", name="uq_crm_sources_code"),
        )
        op.create_index("ix_crm_sources_code", "crm_sources", ["code"], unique=True)

    # ---- product_groups ----
    if "product_groups" not in existing_tables:
        op.create_table(
            "product_groups",
            sa.Column("id", sa.Integer(), primary_key=True),
            sa.Column("name", sa.String(length=128), nullable=False),
            sa.Column("description", sa.Text(), nullable=True),
            sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
            sa.Column("is_active", sa.Boolean(), nullable=False, server_default="true"),
            sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now()),
            sa.UniqueConstraint("name", name="uq_product_groups_name"),
        )

    # ---- products.group_id ----
    prod_cols = {c["name"] for c in insp.get_columns("products")}
    if "group_id" not in prod_cols:
        op.add_column("products", sa.Column("group_id", sa.Integer(), nullable=True))
        op.create_foreign_key(
            "fk_products_group_id", "products", "product_groups",
            ["group_id"], ["id"], ondelete="SET NULL",
        )
        op.create_index("ix_products_group_id", "products", ["group_id"])

    # ---- seeds (insert-missing) ----
    for i, (code, name, name_en, prefix) in enumerate(_COUNTRIES):
        conn.execute(sa.text(
            "INSERT INTO crm_countries (code, name, name_en, phone_prefix, sort_order) "
            "VALUES (:code, :name, :name_en, :prefix, :so) ON CONFLICT (code) DO NOTHING"
        ), {"code": code, "name": name, "name_en": name_en, "prefix": prefix, "so": i})

    for i, (cc, name) in enumerate(_CITIES):
        conn.execute(sa.text(
            "INSERT INTO crm_cities (country_code, name, sort_order) "
            "VALUES (:cc, :name, :so) ON CONFLICT (country_code, name) DO NOTHING"
        ), {"cc": cc, "name": name, "so": i})

    for i, (code, name) in enumerate(_SOURCES):
        conn.execute(sa.text(
            "INSERT INTO crm_sources (code, name, sort_order) "
            "VALUES (:code, :name, :so) ON CONFLICT (code) DO NOTHING"
        ), {"code": code, "name": name, "so": i})

    # ---- backfill product_groups из distinct непустых products.group ----
    conn.execute(sa.text(
        'INSERT INTO product_groups (name) '
        'SELECT DISTINCT TRIM("group") FROM products '
        'WHERE "group" IS NOT NULL AND TRIM("group") <> \'\' '
        'ON CONFLICT (name) DO NOTHING'
    ))
    conn.execute(sa.text(
        'UPDATE products p SET group_id = pg.id '
        'FROM product_groups pg '
        'WHERE p.group_id IS NULL AND p."group" IS NOT NULL '
        'AND TRIM(p."group") = pg.name'
    ))


def downgrade() -> None:
    insp = sa.inspect(op.get_bind())
    prod_cols = {c["name"] for c in insp.get_columns("products")}
    if "group_id" in prod_cols:
        op.drop_index("ix_products_group_id", table_name="products")
        op.drop_constraint("fk_products_group_id", "products", type_="foreignkey")
        op.drop_column("products", "group_id")
    op.drop_table("product_groups")
    op.drop_index("ix_crm_sources_code", table_name="crm_sources")
    op.drop_table("crm_sources")
    op.drop_index("ix_crm_cities_country_code", table_name="crm_cities")
    op.drop_table("crm_cities")
    op.drop_index("ix_crm_countries_code", table_name="crm_countries")
    op.drop_table("crm_countries")
