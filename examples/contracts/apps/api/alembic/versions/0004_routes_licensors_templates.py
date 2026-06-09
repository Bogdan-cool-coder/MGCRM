"""routes multi-select + licensor entities + templates

Revision ID: 0004_routes_lic_tpl
Revises: 0003_tg_link
Create Date: 2026-05-26
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0004_routes_lic_tpl"
down_revision: Union[str, None] = "0003_tg_link"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ApprovalRoute: добавляем product_codes и country_codes как массивы
    op.add_column("approval_routes", sa.Column("product_codes", sa.JSON, nullable=False, server_default="[]"))
    op.add_column("approval_routes", sa.Column("country_codes", sa.JSON, nullable=False, server_default="[]"))
    # Перенос данных: старые product_code → массив из одного элемента
    op.execute("""
        UPDATE approval_routes
        SET product_codes = jsonb_build_array(product_code)::json,
            country_codes = CASE
                WHEN country_code IS NULL THEN '[]'::json
                ELSE jsonb_build_array(country_code)::json
            END
    """)
    op.drop_index("ix_route_product_country", table_name="approval_routes")

    # LicensorEntity
    op.create_table(
        "licensor_entities",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("country_code", sa.String(2), nullable=False, unique=True),
        sa.Column("is_default", sa.Boolean, nullable=False, server_default=sa.true()),
        sa.Column("legal_form", sa.String(64), nullable=False),
        sa.Column("full_legal_form", sa.String(255), nullable=False),
        sa.Column("gender_ending_oe", sa.String(16), nullable=False, server_default="ое"),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("director_position", sa.String(128), nullable=False),
        sa.Column("director_short", sa.String(128), nullable=False),
        sa.Column("director_genitive", sa.String(255), nullable=False),
        sa.Column("acts_basis", sa.String(64), nullable=False, server_default="Устава"),
        sa.Column("tax_id_label", sa.String(16), nullable=False),
        sa.Column("tax_id", sa.String(64), nullable=False),
        sa.Column("address", sa.Text, nullable=False),
        sa.Column("bank", sa.String(255), nullable=False),
        sa.Column("bank_code_label", sa.String(32), nullable=False),
        sa.Column("bank_code", sa.String(64), nullable=False),
        sa.Column("account", sa.String(64), nullable=False),
        sa.Column("phone", sa.String(64), nullable=True),
        sa.Column("email", sa.String(255), nullable=True),
        sa.Column("website", sa.String(255), nullable=True),
        sa.Column("training_login", sa.String(255), nullable=True),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_licensor_entities_country_code", "licensor_entities", ["country_code"])

    # Templates
    op.create_table(
        "templates",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("code", sa.String(64), nullable=False, unique=True),
        sa.Column("kind", sa.String(16), nullable=False),
        sa.Column("title", sa.String(255), nullable=False),
        sa.Column("content", sa.Text, nullable=False),
        sa.Column("version", sa.Integer, nullable=False, server_default="1"),
        sa.Column("updated_by_user_id", sa.Integer, sa.ForeignKey("users.id"), nullable=True),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_templates_code", "templates", ["code"])


def downgrade() -> None:
    op.drop_index("ix_templates_code", table_name="templates")
    op.drop_table("templates")
    op.drop_index("ix_licensor_entities_country_code", table_name="licensor_entities")
    op.drop_table("licensor_entities")
    op.drop_column("approval_routes", "country_codes")
    op.drop_column("approval_routes", "product_codes")
    op.create_index("ix_route_product_country", "approval_routes", ["product_code", "country_code"])
