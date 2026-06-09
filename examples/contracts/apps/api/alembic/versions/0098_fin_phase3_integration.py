"""Финансы Ф3: op-types факта оплаты (write-through) + идемпотентность плановых подписок.

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist).

Контекст: Ф3 вводит интеграцию «факт оплаты = fin_operation» (G §Ф3, решение 7):
  • income по сделке/договору (mark-paid, ContractPayment write-through) — op_type
    `income_deal` (cash_in → 4020 выручка лицензий);
  • ФОТ/комиссии (MotivationalCard.paid) — op_types `payroll_salary` (cash_out → 5110),
    `payroll_commission` (cash_out → 5120);
  • плановые поступления подписок — op_type `subscription_planned` (cash_in → 4010).
Доввод insert-missing по ТЕКУЩЕМУ seed_data.OP_TYPES (single source). Идемпотентно.

Плановые операции подписки (status='planned', source='subscription') дают N записей на
одну подписку (помесячно), поэтому глобальный uq_fin_operation_source(source,
source_ref_id) для них непригоден. Добавляем ЧАСТИЧНЫЙ unique-индекс
(subscription_id, op_date) WHERE source='subscription' AND status='planned' — гарантирует
«один план на подписку на дату периода» (идемпотентность cron под scale=2), не мешая
множественным фактическим операциям и сторно.

downgrade: дроп частичного индекса; op-types — no-op (снос справочника ручной).

Revision ID: 0098_fin_phase3_integration
Revises: 0097_fin_doc_signature
Create Date: 2026-06-03
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op
from app.services.finance.seed_data import CAT_SET_NAME, OP_TYPES

revision: str = "0098_fin_phase3_integration"
down_revision: str | None = "0097_fin_doc_signature"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ. Занятые fin-ключи 91_001..91_013; свободен 91_014.
_LOCK_FIN_PHASE3 = 91_014

#: Коды op-types, доввод которых выполняет эта миграция (Ф3).
_F3_OP_TYPE_CODES = (
    "income_deal",
    "payroll_salary",
    "payroll_commission",
    "subscription_planned",
)

_IDX_SUB_PLANNED = "uq_fin_operation_sub_planned"


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FIN_PHASE3})

    cat_set_id = conn.execute(
        sa.text("SELECT id FROM fin_cat_set WHERE name = :name"), {"name": CAT_SET_NAME}
    ).scalar()

    f3 = {code: row for row in OP_TYPES if (code := row[0]) in _F3_OP_TYPE_CODES}
    for idx, code in enumerate(_F3_OP_TYPE_CODES):
        row = f3.get(code)
        if row is None:
            continue
        (_code, name, direction, template, cat_code, gl_code,
         in_pnl, in_cf, is_transfer) = row
        cat_id = None
        if cat_code is not None and cat_set_id is not None:
            cat_id = conn.execute(
                sa.text(
                    "SELECT id FROM fin_cashflow_category "
                    "WHERE cat_set_id = :cs AND code = :c"
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
                WHERE NOT EXISTS (
                    SELECT 1 FROM fin_op_type WHERE code = CAST(:code AS varchar)
                )
                """
            ),
            {
                "code": code, "name": name, "direction": direction, "template": template,
                "cat_id": cat_id, "gl_id": gl_id, "in_pnl": in_pnl, "in_cf": in_cf,
                "transfer": is_transfer, "sort": 100 + idx,
            },
        )

    # Частичный unique для идемпотентности плановых поступлений подписок.
    conn.execute(
        sa.text(
            f"""
            CREATE UNIQUE INDEX IF NOT EXISTS {_IDX_SUB_PLANNED}
            ON fin_operation (subscription_id, op_date)
            WHERE source = 'subscription' AND status = 'planned'
            """
        )
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FIN_PHASE3})
    conn.execute(sa.text(f"DROP INDEX IF EXISTS {_IDX_SUB_PLANNED}"))
