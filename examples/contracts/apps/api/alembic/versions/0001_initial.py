"""initial schema

Revision ID: 0001_initial
Revises:
Create Date: 2026-05-26
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision: str = "0001_initial"
down_revision: Union[str, None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    user_role = sa.Enum("admin", "director", "lawyer", "manager", name="user_role")
    contract_status = sa.Enum(
        "draft", "submitted", "in_review", "approved", "rejected", "uploaded", "archived",
        name="contract_status",
    )
    approval_decision = sa.Enum("pending", "approved", "rejected", name="approval_decision")

    op.create_table(
        "users",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("email", sa.String(255), nullable=False, unique=True),
        sa.Column("password_hash", sa.String(255), nullable=False),
        sa.Column("full_name", sa.String(255), nullable=False),
        sa.Column("role", user_role, nullable=False),
        sa.Column("telegram_user_id", sa.BigInteger, nullable=True, unique=True),
        sa.Column("is_active", sa.Boolean, nullable=False, server_default=sa.true()),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_users_email", "users", ["email"])

    op.create_table(
        "counterparties",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("full_legal_form", sa.String(255), nullable=True),
        sa.Column("legal_form", sa.String(64), nullable=True),
        sa.Column("gender_ending_oe", sa.String(16), nullable=True, server_default="ое"),
        sa.Column("country_code", sa.String(2), nullable=False),
        sa.Column("director_position", sa.String(128), nullable=True),
        sa.Column("director_genitive", sa.String(255), nullable=True),
        sa.Column("director_short", sa.String(128), nullable=True),
        sa.Column("acts_basis", sa.String(64), nullable=True, server_default="Устава"),
        sa.Column("tax_id_label", sa.String(16), nullable=True),
        sa.Column("tax_id", sa.String(64), nullable=True),
        sa.Column("address", sa.Text, nullable=True),
        sa.Column("bank", sa.String(255), nullable=True),
        sa.Column("bank_code_label", sa.String(32), nullable=True),
        sa.Column("bank_code", sa.String(64), nullable=True),
        sa.Column("account", sa.String(64), nullable=True),
        sa.Column("notes", sa.Text, nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_counterparties_name", "counterparties", ["name"])
    op.create_index("ix_counterparty_country", "counterparties", ["country_code"])

    op.create_table(
        "contract_number_sequences",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("city_code", sa.String(8), nullable=False),
        sa.Column("country_code", sa.String(2), nullable=False),
        sa.Column("start_number", sa.Integer, nullable=False, server_default="220"),
        sa.Column("current_number", sa.Integer, nullable=False, server_default="220"),
        sa.UniqueConstraint("city_code", "country_code", name="uq_seq_city_country"),
    )

    op.create_table(
        "contracts",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("number", sa.String(64), nullable=True),
        sa.Column("title", sa.String(512), nullable=True),
        sa.Column("product_code", sa.String(32), nullable=False),
        sa.Column("country_code", sa.String(2), nullable=False),
        sa.Column("city", sa.String(128), nullable=True),
        sa.Column("city_code", sa.String(8), nullable=True),
        sa.Column("counterparty_id", sa.Integer, sa.ForeignKey("counterparties.id"), nullable=True),
        sa.Column("author_user_id", sa.Integer, sa.ForeignKey("users.id"), nullable=False),
        sa.Column("status", contract_status, nullable=False, server_default="draft"),
        sa.Column("context", postgresql.JSONB, nullable=False, server_default="{}"),
        sa.Column("template_version", sa.String(64), nullable=True),
        sa.Column("docx_path", sa.String(512), nullable=True),
        sa.Column("pdf_path", sa.String(512), nullable=True),
        sa.Column("drive_folder_url", sa.String(1024), nullable=True),
        sa.Column("drive_docx_url", sa.String(1024), nullable=True),
        sa.Column("drive_pdf_url", sa.String(1024), nullable=True),
        sa.Column("telegram_message_id", sa.BigInteger, nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_contracts_number", "contracts", ["number"])
    op.create_index("ix_contract_status", "contracts", ["status"])
    op.create_index("ix_contract_product_country", "contracts", ["product_code", "country_code"])

    op.create_table(
        "approval_routes",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("product_code", sa.String(32), nullable=False),
        sa.Column("country_code", sa.String(2), nullable=True),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("approver_user_ids", sa.JSON, nullable=False, server_default="[]"),
        sa.Column("min_required", sa.Integer, nullable=False, server_default="1"),
        sa.Column("is_active", sa.Boolean, nullable=False, server_default=sa.true()),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_route_product_country", "approval_routes", ["product_code", "country_code"])

    op.create_table(
        "approvals",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("contract_id", sa.Integer, sa.ForeignKey("contracts.id", ondelete="CASCADE"), nullable=False),
        sa.Column("user_id", sa.Integer, sa.ForeignKey("users.id"), nullable=False),
        sa.Column("decision", approval_decision, nullable=False, server_default="pending"),
        sa.Column("comment", sa.Text, nullable=True),
        sa.Column("decided_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.UniqueConstraint("contract_id", "user_id", name="uq_approval_contract_user"),
    )
    op.create_index("ix_approvals_contract_id", "approvals", ["contract_id"])
    op.create_index("ix_approvals_user_id", "approvals", ["user_id"])

    op.create_table(
        "audit_log",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("user_id", sa.Integer, sa.ForeignKey("users.id"), nullable=True),
        sa.Column("contract_id", sa.Integer, sa.ForeignKey("contracts.id"), nullable=True),
        sa.Column("action", sa.String(64), nullable=False),
        sa.Column("payload", postgresql.JSONB, nullable=True),
        sa.Column("ip", sa.String(64), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_audit_log_contract_id", "audit_log", ["contract_id"])


def downgrade() -> None:
    op.drop_table("audit_log")
    op.drop_table("approvals")
    op.drop_table("approval_routes")
    op.drop_table("contracts")
    op.drop_table("contract_number_sequences")
    op.drop_table("counterparties")
    op.drop_table("users")
    op.execute("DROP TYPE IF EXISTS approval_decision")
    op.execute("DROP TYPE IF EXISTS contract_status")
    op.execute("DROP TYPE IF EXISTS user_role")
