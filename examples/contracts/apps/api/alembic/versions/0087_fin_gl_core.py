"""Финансы Ф0 (миграция D): ядро GL + DEFERRABLE-триггер баланса проводки.

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist). Создаёт транзакционные таблицы:
  • fin_journal_entry       — проводка (единица истины)
  • fin_ledger_line         — строка проводки (Дт>0 / Кт<0, три валюты)
  • fin_operation           — UX-обёртка (приход/расход/перевод)
  • fin_allocation          — split по статьям
  • fin_manual_journal      — ручная adjustment-проводка (header)
  • fin_manual_journal_line — строки ввода Дт/Кт

КРИТИЧНЫЙ ИНВАРИАНТ H1 — DB-уровень: для каждой posted/любой fin_journal_entry
Σ ledger_line.amount_func == 0 (в функциональной валюте юрлица). Реализован как
CONSTRAINT TRIGGER DEFERRABLE INITIALLY DEFERRED на fin_ledger_line (INSERT/UPDATE/
DELETE) — проверка откладывается до COMMIT, поэтому многострочную проводку можно
вставлять построчно в одной транзакции, а дисбаланс ловится при коммите (а не на
первой строке). Это ВТОРАЯ линия защиты; первая — posting.py (следующий чанк).

Почему amount_func (а не amount/amount_in_base): баланс железобетонен только в
функциональной валюте юрлица (J §2/H3). amount_in_base — проекция (округления),
построчно Σ=0 НЕ требуется. Строки разных валют сводятся в amount_func через
get_rate_strict (posting engine) — балансирующая FX-строка добавляется там.

downgrade: дроп триггера+функции и таблиц в обратном порядке FK.

Revision ID: 0087_fin_gl_core  (16 chars ≤32 ✓)
Revises: 0086_fin_legal_seed
Create Date: 2026-06-03
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0087_fin_gl_core"
down_revision: Union[str, None] = "0086_fin_legal_seed"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    _create_journal_entry()
    _create_ledger_line()
    _create_balance_trigger()
    _create_operation()
    _create_allocation()
    _create_manual_journal()


def _create_journal_entry() -> None:
    op.create_table(
        "fin_journal_entry",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column(
            "legal_entity_id",
            sa.Integer,
            sa.ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"),
            nullable=False,
        ),
        sa.Column("date", sa.Date, nullable=False),
        sa.Column("kind", sa.String(20), nullable=False),
        sa.Column("status", sa.String(10), nullable=False, server_default="draft"),
        sa.Column("source", sa.String(20), nullable=False),
        sa.Column("source_ref_id", sa.Integer, nullable=True),
        sa.Column(
            "reverses_entry_id",
            sa.Integer,
            sa.ForeignKey("fin_journal_entry.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("func_currency", sa.String(8), nullable=False),
        sa.Column("base_currency", sa.String(8), nullable=True),
        sa.Column("posted_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("posted_by_user_id", sa.Integer, sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("memo", sa.Text, nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index(
        "ix_fin_journal_entry_le_date_status",
        "fin_journal_entry", ["legal_entity_id", "date", "status"],
    )
    op.create_index("ix_fin_journal_entry_source", "fin_journal_entry", ["source", "source_ref_id"])
    op.create_index("ix_fin_journal_entry_kind", "fin_journal_entry", ["kind"])


def _create_ledger_line() -> None:
    op.create_table(
        "fin_ledger_line",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column(
            "journal_entry_id",
            sa.Integer,
            sa.ForeignKey("fin_journal_entry.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "legal_entity_id",
            sa.Integer,
            sa.ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"),
            nullable=False,
        ),
        sa.Column(
            "account_gl_id",
            sa.Integer,
            sa.ForeignKey("fin_account_gl.id", ondelete="RESTRICT"),
            nullable=False,
        ),
        sa.Column("amount", sa.Numeric(18, 2), nullable=False),
        sa.Column("currency", sa.String(8), nullable=False),
        sa.Column("amount_func", sa.Numeric(18, 2), nullable=False),
        sa.Column("amount_in_base", sa.Numeric(18, 2), nullable=True),
        sa.Column("base_currency", sa.String(8), nullable=True),
        sa.Column("fx_rate", sa.Numeric(20, 8), nullable=True),
        sa.Column("fx_rate_date", sa.Date, nullable=True),
        sa.Column("fx_missing", sa.Boolean, nullable=False, server_default="false"),
        sa.Column("money_account_id", sa.Integer, sa.ForeignKey("fin_money_account.id", ondelete="SET NULL"), nullable=True),
        sa.Column("cashflow_category_id", sa.Integer, sa.ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True),
        sa.Column("counterparty_company_id", sa.Integer, sa.ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True),
        sa.Column("employee_user_id", sa.Integer, sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("vat_rate_id", sa.Integer, sa.ForeignKey("fin_vat_rate.id", ondelete="SET NULL"), nullable=True),
        sa.Column("deal_id", sa.Integer, sa.ForeignKey("deals.id", ondelete="SET NULL"), nullable=True),
        sa.Column("contract_id", sa.Integer, sa.ForeignKey("contracts.id", ondelete="SET NULL"), nullable=True),
        sa.Column("subscription_id", sa.Integer, sa.ForeignKey("client_subscriptions.id", ondelete="SET NULL"), nullable=True),
        sa.Column("comment", sa.Text, nullable=True),
        sa.CheckConstraint("amount <> 0", name="ck_fin_ledger_line_amount_nonzero"),
    )
    op.create_index("ix_fin_ledger_line_entry", "fin_ledger_line", ["journal_entry_id"])
    op.create_index("ix_fin_ledger_line_le", "fin_ledger_line", ["legal_entity_id"])
    op.create_index("ix_fin_ledger_line_acc", "fin_ledger_line", ["account_gl_id"])
    op.create_index("ix_fin_ledger_line_acc_le", "fin_ledger_line", ["account_gl_id", "legal_entity_id"])
    op.create_index("ix_fin_ledger_line_money", "fin_ledger_line", ["money_account_id"])
    op.create_index("ix_fin_ledger_line_cat", "fin_ledger_line", ["cashflow_category_id"])
    op.create_index("ix_fin_ledger_line_cp_acc", "fin_ledger_line", ["counterparty_company_id", "account_gl_id"])
    op.create_index("ix_fin_ledger_line_deal", "fin_ledger_line", ["deal_id"])
    op.create_index("ix_fin_ledger_line_contract", "fin_ledger_line", ["contract_id"])
    op.create_index("ix_fin_ledger_line_subscription", "fin_ledger_line", ["subscription_id"])


def _create_balance_trigger() -> None:
    """DEFERRABLE constraint-триггер: Σ amount_func по journal_entry == 0 на COMMIT (H1)."""
    op.execute(
        r"""
        CREATE OR REPLACE FUNCTION fin_assert_entry_balanced() RETURNS trigger AS $$
        DECLARE
            v_entry_id  bigint;
            v_imbalance numeric(18,2);
            v_cnt       int;
        BEGIN
            -- entry-id зависит от операции: на DELETE берём OLD, иначе NEW.
            IF (TG_OP = 'DELETE') THEN
                v_entry_id := OLD.journal_entry_id;
            ELSE
                v_entry_id := NEW.journal_entry_id;
            END IF;

            -- Проводка могла быть удалена целиком (CASCADE) — тогда строк нет, баланс не нужен.
            SELECT COUNT(*), COALESCE(SUM(amount_func), 0)
              INTO v_cnt, v_imbalance
              FROM fin_ledger_line
             WHERE journal_entry_id = v_entry_id;

            IF v_cnt = 0 THEN
                RETURN NULL;  -- нет строк (проводка удалена / пустой черновик) — пропуск
            END IF;

            IF v_imbalance <> 0 THEN
                RAISE EXCEPTION
                    'fin_ledger imbalance: journal_entry_id=% Σamount_func=% (must be 0)',
                    v_entry_id, v_imbalance
                    USING ERRCODE = 'check_violation';
            END IF;

            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;
        """
    )
    op.execute(
        """
        CREATE CONSTRAINT TRIGGER fin_ledger_balance_check
        AFTER INSERT OR UPDATE OR DELETE ON fin_ledger_line
        DEFERRABLE INITIALLY DEFERRED
        FOR EACH ROW
        EXECUTE FUNCTION fin_assert_entry_balanced();
        """
    )


def _create_operation() -> None:
    op.create_table(
        "fin_operation",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("number", sa.String(32), nullable=True),
        sa.Column("legal_entity_id", sa.Integer, sa.ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("op_type_id", sa.Integer, sa.ForeignKey("fin_op_type.id", ondelete="SET NULL"), nullable=True),
        sa.Column("direction", sa.String(10), nullable=False),
        sa.Column("status", sa.String(16), nullable=False, server_default="planned"),
        sa.Column("op_date", sa.Date, nullable=False),
        sa.Column("due_date", sa.Date, nullable=True),
        sa.Column("amount", sa.Numeric(18, 2), nullable=False),
        sa.Column("currency", sa.String(8), nullable=False),
        sa.Column("to_amount", sa.Numeric(18, 2), nullable=True),
        sa.Column("account_from_id", sa.Integer, sa.ForeignKey("fin_money_account.id", ondelete="SET NULL"), nullable=True),
        sa.Column("account_to_id", sa.Integer, sa.ForeignKey("fin_money_account.id", ondelete="SET NULL"), nullable=True),
        sa.Column("cashflow_category_id", sa.Integer, sa.ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True),
        sa.Column("counterparty_company_id", sa.Integer, sa.ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True),
        sa.Column("vat_rate_id", sa.Integer, sa.ForeignKey("fin_vat_rate.id", ondelete="SET NULL"), nullable=True),
        sa.Column("vat_amount", sa.Numeric(18, 2), nullable=True),
        sa.Column("amount_net", sa.Numeric(18, 2), nullable=True),
        sa.Column("purpose", sa.Text, nullable=True),
        sa.Column("journal_entry_id", sa.Integer, sa.ForeignKey("fin_journal_entry.id", ondelete="SET NULL"), nullable=True),
        sa.Column("source", sa.String(20), nullable=True),
        sa.Column("source_ref_id", sa.Integer, nullable=True),
        sa.Column("deal_id", sa.Integer, sa.ForeignKey("deals.id", ondelete="SET NULL"), nullable=True),
        sa.Column("contract_id", sa.Integer, sa.ForeignKey("contracts.id", ondelete="SET NULL"), nullable=True),
        sa.Column("subscription_id", sa.Integer, sa.ForeignKey("client_subscriptions.id", ondelete="SET NULL"), nullable=True),
        sa.Column("is_for_management", sa.Boolean, nullable=False, server_default="false"),
        sa.Column("rejected_reason", sa.String(512), nullable=True),
        sa.Column("created_by_user_id", sa.Integer, sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("posted_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.UniqueConstraint("source", "source_ref_id", name="uq_fin_operation_source"),
        sa.CheckConstraint("amount > 0", name="ck_fin_operation_amount_pos"),
    )
    op.create_index("ix_fin_operation_le", "fin_operation", ["legal_entity_id"])
    op.create_index("ix_fin_operation_status", "fin_operation", ["status"])
    op.create_index("ix_fin_operation_date", "fin_operation", ["op_date"])
    op.create_index("ix_fin_operation_cp", "fin_operation", ["counterparty_company_id"])
    op.create_index("ix_fin_operation_deal", "fin_operation", ["deal_id"])
    op.create_index("ix_fin_operation_contract", "fin_operation", ["contract_id"])


def _create_allocation() -> None:
    op.create_table(
        "fin_allocation",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("operation_id", sa.Integer, sa.ForeignKey("fin_operation.id", ondelete="CASCADE"), nullable=False),
        sa.Column("cashflow_category_id", sa.Integer, sa.ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True),
        sa.Column("amount", sa.Numeric(18, 2), nullable=False),
        sa.Column("comment", sa.String(255), nullable=True),
    )
    op.create_index("ix_fin_allocation_op", "fin_allocation", ["operation_id"])


def _create_manual_journal() -> None:
    op.create_table(
        "fin_manual_journal",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("number", sa.String(32), nullable=True),
        sa.Column("legal_entity_id", sa.Integer, sa.ForeignKey("fin_legal_entity.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("date", sa.Date, nullable=False),
        sa.Column("status", sa.String(10), nullable=False, server_default="draft"),
        sa.Column("memo", sa.String(512), nullable=False),
        sa.Column("journal_entry_id", sa.Integer, sa.ForeignKey("fin_journal_entry.id", ondelete="SET NULL"), nullable=True),
        sa.Column("created_by_user_id", sa.Integer, sa.ForeignKey("users.id", ondelete="SET NULL"), nullable=True),
        sa.Column("posted_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_fin_manual_journal_le", "fin_manual_journal", ["legal_entity_id"])
    op.create_index("ix_fin_manual_journal_date", "fin_manual_journal", ["date"])

    op.create_table(
        "fin_manual_journal_line",
        sa.Column("id", sa.Integer, primary_key=True),
        sa.Column("manual_journal_id", sa.Integer, sa.ForeignKey("fin_manual_journal.id", ondelete="CASCADE"), nullable=False),
        sa.Column("account_gl_id", sa.Integer, sa.ForeignKey("fin_account_gl.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("side", sa.String(2), nullable=False),
        sa.Column("amount", sa.Numeric(18, 2), nullable=False),
        sa.Column("currency", sa.String(8), nullable=False),
        sa.Column("counterparty_company_id", sa.Integer, sa.ForeignKey("crm_companies.id", ondelete="SET NULL"), nullable=True),
        sa.Column("money_account_id", sa.Integer, sa.ForeignKey("fin_money_account.id", ondelete="SET NULL"), nullable=True),
        sa.Column("cashflow_category_id", sa.Integer, sa.ForeignKey("fin_cashflow_category.id", ondelete="SET NULL"), nullable=True),
        sa.Column("comment", sa.String(255), nullable=True),
        sa.CheckConstraint("side IN ('dt','kt')", name="ck_fin_mj_line_side"),
        sa.CheckConstraint("amount > 0", name="ck_fin_mj_line_amount_pos"),
    )
    op.create_index("ix_fin_mj_line_journal", "fin_manual_journal_line", ["manual_journal_id"])


def downgrade() -> None:
    op.execute("DROP TRIGGER IF EXISTS fin_ledger_balance_check ON fin_ledger_line")
    op.execute("DROP FUNCTION IF EXISTS fin_assert_entry_balanced()")
    op.drop_table("fin_manual_journal_line")
    op.drop_table("fin_manual_journal")
    op.drop_table("fin_allocation")
    op.drop_table("fin_operation")
    op.drop_table("fin_ledger_line")
    op.drop_table("fin_journal_entry")
