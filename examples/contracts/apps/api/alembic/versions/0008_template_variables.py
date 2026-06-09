"""template variables (кастомные переменные шаблона)

Revision ID: 0008_tpl_vars
Revises: 0007_rev_remarks
Create Date: 2026-05-28
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision: str = "0008_tpl_vars"
down_revision: Union[str, None] = "0007_rev_remarks"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    var_type = postgresql.ENUM(
        "text", "textarea", "number", "date", "select", "checkbox",
        name="template_variable_type",
        create_type=False,
    )
    var_type.create(op.get_bind(), checkfirst=True)

    op.create_table(
        "template_variables",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("key", sa.String(64), nullable=False),
        sa.Column("label", sa.String(255), nullable=False),
        sa.Column("help_text", sa.String(512), nullable=True),
        sa.Column("var_type", var_type, nullable=False, server_default="text"),
        sa.Column("options", postgresql.JSON, nullable=False, server_default="[]"),
        sa.Column("default_value", sa.String(512), nullable=True),
        sa.Column("required", sa.Boolean, nullable=False, server_default=sa.false()),
        sa.Column("group", sa.String(128), nullable=True),
        sa.Column("sort_order", sa.Integer, nullable=False, server_default="0"),
        sa.Column("product_codes", postgresql.JSON, nullable=False, server_default="[]"),
        sa.Column("country_codes", postgresql.JSON, nullable=False, server_default="[]"),
        sa.Column("is_active", sa.Boolean, nullable=False, server_default=sa.true()),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.UniqueConstraint("key", name="uq_template_variable_key"),
    )
    op.create_index("ix_template_variables_key", "template_variables", ["key"])


def downgrade() -> None:
    op.drop_index("ix_template_variables_key", table_name="template_variables")
    op.drop_table("template_variables")
    postgresql.ENUM(name="template_variable_type").drop(op.get_bind(), checkfirst=True)
