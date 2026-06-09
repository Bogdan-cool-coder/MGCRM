"""Финансы Ф0 (миграция B): справочники + сиды.

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist). Создаёт справочные таблицы и засевает
канонические данные из J_phase0_LOCKED (single source = app.services.finance.seed_data):
  • fin_settings           — singleton base_currency=RUB
  • fin_legal_entity       — юрлица (сид данными — миграция 0086, runtime из licensor_entities)
  • fin_vat_rate           — ставки НДС (KZ ҚҚС 12% / UZ 12% / «Без НДС»)
  • fin_account_gl         — план счетов, 39 счетов (классы 1xxx–5xxx)
  • fin_money_account      — денежные счета (сид — 0086)
  • fin_cat_set            — «SaaS-набор операций»
  • fin_cashflow_category  — дерево статей ДДС, 39 узлов (3 уровня, parent_id)
  • fin_op_type            — типы операций (posting_template)
  • fin_number_sequence    — нумератор финдокументов
  • fin_permission         — дефолты прав ролей × capability (вкл. accountant/cfo + журналы)
  • fin_period_lock        — закрытие периода

Все сиды — insert-missing (НЕ truncate-insert), под одним advisory-lock → повторный
прогон scale=2 = no-op. Деньги Numeric(18,2), курсы Numeric(20,8), проценты Numeric(5,2).

downgrade: DROP TABLE в обратном порядке FK (ядро GL дропается в 0086, здесь — только
справочники этой миграции).

Revision ID: 0085_fin_reference  (18 chars ≤32 ✓)
Revises: 0084_fin_roles
Create Date: 2026-06-03
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

from app.services.finance.seed_data import (
    ACCOUNTS_GL,
    BASE_CURRENCY,
    CASHFLOW_TREE,
    CAT_SET_NAME,
    OP_TYPES,
    ROLE_PERMISSIONS,
    VAT_RATES,
)

revision: str = "0085_fin_reference"
down_revision: Union[str, None] = "0084_fin_roles"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_FIN_REFERENCE = 91_001


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _SEED_LOCK_FIN_REFERENCE})

    _create_tables()
    _seed_settings(conn)
    _seed_vat_rates(conn)
    _seed_accounts(conn)
    _seed_cashflow(conn)
    _seed_op_types(conn)
    _seed_permissions(conn)


# ───────────────────────────── DDL ─────────────────────────────


def _create_tables() -> None:
    op.create_table(
        "fin_settings",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("base_currency", sa.String(8), nullable=False, server_default="RUB"),
        sa.Column("base_currency_changed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )

    op.create_table(
        "fin_legal_entity",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("country_code", sa.String(2), nullable=False),
        sa.Column("functional_currency", sa.String(8), nullable=False),
        sa.Column("vat_enabled", sa.Boolean, nullable=False, server_default="false"),
        sa.Column("tax_regime", sa.String(20), nullable=False, server_default="no_vat"),
        sa.Column("vat_recognition", sa.String(12), nullable=False, server_default="by_shipment"),
        sa.Column("tax_id", sa.String(32), nullable=True),
        sa.Column(
            "licensor_entity_id",
            sa.Integer,
            sa.ForeignKey("licensor_entities.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("requisites_json", sa.dialects.postgresql.JSONB, nullable=True),
        sa.Column("is_active", sa.Boolean, nullable=False, server_default="true"),
        sa.Column("sort_order", sa.Integer, nullable=False, server_default="0"),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_fin_legal_entity_licensor", "fin_legal_entity", ["licensor_entity_id"])

    op.create_table(
        "fin_vat_rate",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column(
            "legal_entity_id",
            sa.Integer,
            sa.ForeignKey("fin_legal_entity.id", ondelete="CASCADE"),
            nullable=True,
        ),
        sa.Column("country_code", sa.String(2), nullable=True),
        sa.Column("name", sa.String(64), nullable=False),
        sa.Column("rate_pct", sa.Numeric(5, 2), nullable=False),
        sa.Column("kind", sa.String(12), nullable=False),
        sa.Column("effective_from", sa.Date, nullable=True),
        sa.Column("effective_to", sa.Date, nullable=True),
        sa.Column("is_active", sa.Boolean, nullable=False, server_default="true"),
        sa.UniqueConstraint("name", "country_code", name="uq_fin_vat_rate_name_country"),
    )
    op.create_index("ix_fin_vat_rate_le", "fin_vat_rate", ["legal_entity_id"])

    op.create_table(
        "fin_account_gl",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("code", sa.String(8), nullable=False, unique=True),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("type", sa.String(12), nullable=False),
        sa.Column("subtype", sa.String(24), nullable=True),
        sa.Column("normal_side", sa.String(4), nullable=False),
        sa.Column("is_money", sa.Boolean, nullable=False, server_default="false"),
        sa.Column("requires_counterparty", sa.Boolean, nullable=False, server_default="false"),
        sa.Column("is_active", sa.Boolean, nullable=False, server_default="true"),
        sa.Column("sort_order", sa.Integer, nullable=False, server_default="0"),
        sa.CheckConstraint(
            "type IN ('asset','liability','equity','income','expense')",
            name="ck_fin_account_gl_type",
        ),
        sa.CheckConstraint("normal_side IN ('dt','kt','both')", name="ck_fin_account_gl_side"),
    )
    op.create_index("ix_fin_account_gl_code", "fin_account_gl", ["code"])

    op.create_table(
        "fin_cat_set",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("name", sa.String(128), nullable=False, unique=True),
        sa.Column("is_active", sa.Boolean, nullable=False, server_default="true"),
    )

    op.create_table(
        "fin_cashflow_category",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column(
            "cat_set_id",
            sa.Integer,
            sa.ForeignKey("fin_cat_set.id", ondelete="RESTRICT"),
            nullable=False,
        ),
        sa.Column(
            "parent_id",
            sa.Integer,
            sa.ForeignKey("fin_cashflow_category.id", ondelete="RESTRICT"),
            nullable=True,
        ),
        sa.Column("code", sa.String(24), nullable=False),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("level", sa.Integer, nullable=False),
        sa.Column("activity", sa.String(12), nullable=False),
        sa.Column("direction", sa.String(8), nullable=False),
        sa.Column("sort_order", sa.Integer, nullable=False, server_default="0"),
        sa.Column("is_active", sa.Boolean, nullable=False, server_default="true"),
        sa.UniqueConstraint("cat_set_id", "code", name="uq_fin_cashflow_cat_set_code"),
    )
    op.create_index("ix_fin_cashflow_cat_set", "fin_cashflow_category", ["cat_set_id"])
    op.create_index("ix_fin_cashflow_parent", "fin_cashflow_category", ["parent_id"])
    op.create_index("ix_fin_cashflow_code", "fin_cashflow_category", ["code"])

    op.create_table(
        "fin_money_account",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column(
            "legal_entity_id",
            sa.Integer,
            sa.ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"),
            nullable=False,
        ),
        sa.Column(
            "gl_account_id",
            sa.Integer,
            sa.ForeignKey("fin_account_gl.id", ondelete="RESTRICT"),
            nullable=False,
        ),
        sa.Column("name", sa.String(255), nullable=False),
        sa.Column("account_type", sa.String(16), nullable=False),
        sa.Column("currency", sa.String(8), nullable=False),
        sa.Column("initial_balance", sa.Numeric(18, 2), nullable=False, server_default="0"),
        sa.Column("is_active", sa.Boolean, nullable=False, server_default="true"),
        sa.Column("sort_order", sa.Integer, nullable=False, server_default="0"),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_fin_money_account_le", "fin_money_account", ["legal_entity_id"])

    op.create_table(
        "fin_op_type",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("code", sa.String(32), nullable=False, unique=True),
        sa.Column("name", sa.String(128), nullable=False),
        sa.Column("direction", sa.String(10), nullable=False),
        sa.Column("posting_template", sa.String(24), nullable=False),
        sa.Column(
            "default_cat_id",
            sa.Integer,
            sa.ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "default_gl_account_id",
            sa.Integer,
            sa.ForeignKey("fin_account_gl.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("counts_in_pnl", sa.Boolean, nullable=False, server_default="true"),
        sa.Column("counts_in_cashflow", sa.Boolean, nullable=False, server_default="true"),
        sa.Column("is_internal_transfer", sa.Boolean, nullable=False, server_default="false"),
        sa.Column("is_archived", sa.Boolean, nullable=False, server_default="false"),
        sa.Column("sort_order", sa.Integer, nullable=False, server_default="0"),
    )
    op.create_index("ix_fin_op_type_code", "fin_op_type", ["code"])

    op.create_table(
        "fin_number_sequence",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("doc_type", sa.String(16), nullable=False),
        sa.Column(
            "legal_entity_id",
            sa.Integer,
            sa.ForeignKey("fin_legal_entity.id", ondelete="CASCADE"),
            nullable=True,
        ),
        sa.Column("year", sa.Integer, nullable=False),
        sa.Column("prefix", sa.String(16), nullable=True),
        sa.Column("next_value", sa.Integer, nullable=False, server_default="1"),
        sa.UniqueConstraint("doc_type", "legal_entity_id", "year", name="uq_fin_number_seq"),
    )

    op.create_table(
        "fin_permission",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("role", sa.String(16), nullable=True),
        sa.Column("user_id", sa.Integer, sa.ForeignKey("users.id", ondelete="CASCADE"), nullable=True),
        sa.Column(
            "legal_entity_id",
            sa.Integer,
            sa.ForeignKey("fin_legal_entity.id", ondelete="CASCADE"),
            nullable=True,
        ),
        sa.Column("capability", sa.String(32), nullable=False),
        sa.Column("allowed", sa.Boolean, nullable=False, server_default="true"),
        sa.UniqueConstraint("role", "user_id", "legal_entity_id", "capability", name="uq_fin_permission"),
    )
    op.create_index("ix_fin_permission_role", "fin_permission", ["role"])
    op.create_index("ix_fin_permission_user", "fin_permission", ["user_id"])
    op.create_index("ix_fin_permission_le", "fin_permission", ["legal_entity_id"])

    op.create_table(
        "fin_period_lock",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column(
            "legal_entity_id",
            sa.Integer,
            sa.ForeignKey("fin_legal_entity.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("year", sa.Integer, nullable=False),
        sa.Column("month", sa.Integer, nullable=False),
        sa.Column("locked_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("locked_by_user_id", sa.Integer, sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.UniqueConstraint("legal_entity_id", "year", "month", name="uq_fin_period_lock"),
        sa.CheckConstraint("month BETWEEN 1 AND 12", name="ck_fin_period_lock_month"),
    )
    op.create_index("ix_fin_period_lock_le", "fin_period_lock", ["legal_entity_id"])


# ───────────────────────────── сиды (insert-missing) ─────────────────────────────


def _seed_settings(conn) -> None:
    conn.execute(
        sa.text(
            "INSERT INTO fin_settings (base_currency) "
            "SELECT :cur WHERE NOT EXISTS (SELECT 1 FROM fin_settings)"
        ),
        {"cur": BASE_CURRENCY},
    )


def _seed_vat_rates(conn) -> None:
    for name, rate_pct, kind, country in VAT_RATES:
        conn.execute(
            sa.text(
                """
                INSERT INTO fin_vat_rate (name, rate_pct, kind, country_code, is_active)
                SELECT CAST(:name AS varchar), CAST(:rate AS numeric),
                       CAST(:kind AS varchar), CAST(:country AS varchar), true
                WHERE NOT EXISTS (
                    SELECT 1 FROM fin_vat_rate
                    WHERE name = CAST(:name AS varchar)
                      AND country_code IS NOT DISTINCT FROM CAST(:country AS varchar)
                )
                """
            ),
            {"name": name, "rate": rate_pct, "kind": kind, "country": country},
        )


def _seed_accounts(conn) -> None:
    for idx, (code, name, atype, subtype, side, is_money, req_cp) in enumerate(ACCOUNTS_GL):
        conn.execute(
            sa.text(
                """
                INSERT INTO fin_account_gl
                    (code, name, type, subtype, normal_side, is_money,
                     requires_counterparty, is_active, sort_order)
                SELECT CAST(:code AS varchar), :name, :type, :subtype, :side, :is_money,
                       :req_cp, true, :sort
                WHERE NOT EXISTS (SELECT 1 FROM fin_account_gl WHERE code = CAST(:code AS varchar))
                """
            ),
            {
                "code": code, "name": name, "type": atype, "subtype": subtype,
                "side": side, "is_money": is_money, "req_cp": req_cp, "sort": idx,
            },
        )


def _seed_cashflow(conn) -> None:
    # 1. cat_set (singleton по имени).
    conn.execute(
        sa.text(
            "INSERT INTO fin_cat_set (name, is_active) "
            "SELECT CAST(:name AS varchar), true "
            "WHERE NOT EXISTS (SELECT 1 FROM fin_cat_set WHERE name = CAST(:name AS varchar))"
        ),
        {"name": CAT_SET_NAME},
    )
    cat_set_id = conn.execute(
        sa.text("SELECT id FROM fin_cat_set WHERE name = :name"), {"name": CAT_SET_NAME}
    ).scalar_one()

    # 2. узлы дерева в порядке сида (родители раньше детей — гарантируется списком).
    for idx, (code, name, level, activity, direction, parent_code) in enumerate(CASHFLOW_TREE):
        parent_id = None
        if parent_code is not None:
            parent_id = conn.execute(
                sa.text(
                    "SELECT id FROM fin_cashflow_category WHERE cat_set_id = :cs AND code = :pc"
                ),
                {"cs": cat_set_id, "pc": parent_code},
            ).scalar_one()
        conn.execute(
            sa.text(
                """
                INSERT INTO fin_cashflow_category
                    (cat_set_id, parent_id, code, name, level, activity, direction, sort_order, is_active)
                SELECT :cs, :parent, CAST(:code AS varchar), :name, :level,
                       :activity, :direction, :sort, true
                WHERE NOT EXISTS (
                    SELECT 1 FROM fin_cashflow_category
                    WHERE cat_set_id = :cs AND code = CAST(:code AS varchar)
                )
                """
            ),
            {
                "cs": cat_set_id, "parent": parent_id, "code": code, "name": name,
                "level": level, "activity": activity, "direction": direction, "sort": idx,
            },
        )


def _seed_op_types(conn) -> None:
    cat_set_id = conn.execute(
        sa.text("SELECT id FROM fin_cat_set WHERE name = :name"), {"name": CAT_SET_NAME}
    ).scalar_one()
    for idx, row in enumerate(OP_TYPES):
        (code, name, direction, template, cat_code, gl_code,
         in_pnl, in_cf, is_transfer) = row
        cat_id = None
        if cat_code is not None:
            cat_id = conn.execute(
                sa.text(
                    "SELECT id FROM fin_cashflow_category WHERE cat_set_id = :cs AND code = :c"
                ),
                {"cs": cat_set_id, "c": cat_code},
            ).scalar()
        gl_id = None
        if gl_code is not None:
            gl_id = conn.execute(
                sa.text("SELECT id FROM fin_account_gl WHERE code = :c"), {"c": gl_code}
            ).scalar()
        conn.execute(
            sa.text(
                """
                INSERT INTO fin_op_type
                    (code, name, direction, posting_template, default_cat_id,
                     default_gl_account_id, counts_in_pnl, counts_in_cashflow,
                     is_internal_transfer, is_archived, sort_order)
                SELECT CAST(:code AS varchar), :name, :direction, :template, :cat_id, :gl_id,
                       :in_pnl, :in_cf, :transfer, false, :sort
                WHERE NOT EXISTS (SELECT 1 FROM fin_op_type WHERE code = CAST(:code AS varchar))
                """
            ),
            {
                "code": code, "name": name, "direction": direction, "template": template,
                "cat_id": cat_id, "gl_id": gl_id, "in_pnl": in_pnl, "in_cf": in_cf,
                "transfer": is_transfer, "sort": idx,
            },
        )


def _seed_permissions(conn) -> None:
    # Дефолты прав по ролям (legal_entity_id=NULL = на все юрлица; user_id=NULL = role-default).
    for role, caps in ROLE_PERMISSIONS.items():
        for capability, allowed in caps.items():
            conn.execute(
                sa.text(
                    """
                    INSERT INTO fin_permission (role, user_id, legal_entity_id, capability, allowed)
                    SELECT CAST(:role AS varchar), NULL, NULL,
                           CAST(:cap AS varchar), :allowed
                    WHERE NOT EXISTS (
                        SELECT 1 FROM fin_permission
                        WHERE role = CAST(:role AS varchar) AND user_id IS NULL
                          AND legal_entity_id IS NULL
                          AND capability = CAST(:cap AS varchar)
                    )
                    """
                ),
                {"role": role, "cap": capability, "allowed": allowed},
            )


def downgrade() -> None:
    # Обратный порядок FK. Ядро GL (journal/ledger/operation/...) дропается в 0086.
    op.drop_table("fin_period_lock")
    op.drop_table("fin_permission")
    op.drop_table("fin_number_sequence")
    op.drop_table("fin_op_type")
    op.drop_table("fin_money_account")
    op.drop_table("fin_cashflow_category")
    op.drop_table("fin_cat_set")
    op.drop_table("fin_account_gl")
    op.drop_table("fin_vat_rate")
    op.drop_table("fin_legal_entity")
    op.drop_table("fin_settings")
