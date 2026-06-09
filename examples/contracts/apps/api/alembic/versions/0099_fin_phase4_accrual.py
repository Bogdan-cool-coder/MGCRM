"""Финансы Ф4: accrual (revenue recognition MRR) + переоценка + смена базовой валюты.

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist).

Контекст (G §2 реш.1/5, H4/H6/H10):
  • fin_revenue_schedule — план признания выручки помесячно (recognition_key UNIQUE,
    идемпотентность под scale=2). Признание постит accrual-проводку Дт AR / Кт выручка
    (4010 MRR) / Кт НДС output → status='recognized'.
  • fin_base_recompute_job — задание пересчёта amount_in_base при смене базовой валюты.
    Политика H4: пересчитываются ТОЛЬКО открытые периоды (закрытые сохраняют историческую
    базу) — служба в base_currency.py.
  • Доввод fin_permission для новых capability recognize_revenue/run_revaluation/
    change_base_currency (insert-missing по ТЕКУЩЕМУ ROLE_PERMISSIONS).

FX-переоценка и accrual-проводки используют существующие счета (4910/5910 курсовые,
1210 AR, 4010 MRR, 2310 НДС) — новых счетов НЕ требуется.

downgrade: дроп двух таблиц; права — no-op (безопасный доввод, снос ручной).

Revision ID: 0099_fin_phase4_accrual
Revises: 0098_fin_phase3_integration
Create Date: 2026-06-03
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op
from app.services.finance.seed_data import ROLE_PERMISSIONS

revision: str = "0099_fin_phase4_accrual"
down_revision: str | None = "0098_fin_phase3_integration"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ. Занятые fin-ключи 91_001..91_014; свободен 91_015.
_LOCK_FIN_PHASE4 = 91_015


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FIN_PHASE4})

    # ── fin_revenue_schedule ──
    conn.execute(
        sa.text(
            """
            CREATE TABLE IF NOT EXISTS fin_revenue_schedule (
                id SERIAL PRIMARY KEY,
                subscription_id INTEGER NOT NULL
                    REFERENCES client_subscriptions(id) ON DELETE CASCADE,
                legal_entity_id INTEGER NOT NULL
                    REFERENCES fin_legal_entity(id) ON DELETE RESTRICT,
                counterparty_company_id INTEGER
                    REFERENCES crm_companies(id) ON DELETE SET NULL,
                period_year INTEGER NOT NULL,
                period_month INTEGER NOT NULL,
                amount_net NUMERIC(18,2) NOT NULL,
                vat_amount NUMERIC(18,2) NOT NULL DEFAULT 0,
                currency VARCHAR(8) NOT NULL,
                revenue_account_code VARCHAR(8) NOT NULL DEFAULT '4010',
                vat_rate_id INTEGER REFERENCES fin_vat_rate(id) ON DELETE SET NULL,
                cashflow_category_id INTEGER
                    REFERENCES fin_cashflow_category(id) ON DELETE SET NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'scheduled',
                recognition_key VARCHAR(64) NOT NULL,
                recognized_journal_entry_id INTEGER
                    REFERENCES fin_journal_entry(id) ON DELETE SET NULL,
                recognized_at TIMESTAMPTZ,
                created_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT uq_fin_revenue_schedule_key UNIQUE (recognition_key),
                CONSTRAINT ck_fin_revenue_schedule_month
                    CHECK (period_month BETWEEN 1 AND 12),
                CONSTRAINT ck_fin_revenue_schedule_status
                    CHECK (status IN ('scheduled','recognized','skipped','reversed'))
            )
            """
        )
    )
    conn.execute(
        sa.text(
            "CREATE INDEX IF NOT EXISTS ix_fin_revenue_schedule_subscription_id "
            "ON fin_revenue_schedule (subscription_id)"
        )
    )
    conn.execute(
        sa.text(
            "CREATE INDEX IF NOT EXISTS ix_fin_revenue_schedule_legal_entity_id "
            "ON fin_revenue_schedule (legal_entity_id)"
        )
    )
    conn.execute(
        sa.text(
            "CREATE INDEX IF NOT EXISTS ix_fin_revenue_schedule_counterparty_company_id "
            "ON fin_revenue_schedule (counterparty_company_id)"
        )
    )
    conn.execute(
        sa.text(
            "CREATE INDEX IF NOT EXISTS ix_fin_revenue_schedule_status "
            "ON fin_revenue_schedule (status)"
        )
    )
    conn.execute(
        sa.text(
            "CREATE INDEX IF NOT EXISTS ix_fin_revenue_schedule_sub_period "
            "ON fin_revenue_schedule (subscription_id, period_year, period_month)"
        )
    )

    # ── fin_base_recompute_job ──
    conn.execute(
        sa.text(
            """
            CREATE TABLE IF NOT EXISTS fin_base_recompute_job (
                id SERIAL PRIMARY KEY,
                target_currency VARCHAR(8) NOT NULL,
                previous_currency VARCHAR(8),
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                total_lines INTEGER NOT NULL DEFAULT 0,
                processed_lines INTEGER NOT NULL DEFAULT 0,
                skipped_closed INTEGER NOT NULL DEFAULT 0,
                missing_rate_lines INTEGER NOT NULL DEFAULT 0,
                error TEXT,
                started_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                finished_at TIMESTAMPTZ,
                CONSTRAINT ck_fin_base_recompute_job_status
                    CHECK (status IN ('pending','running','done','partial','failed'))
            )
            """
        )
    )
    conn.execute(
        sa.text(
            "CREATE INDEX IF NOT EXISTS ix_fin_base_recompute_job_status "
            "ON fin_base_recompute_job (status)"
        )
    )

    # ── доввод прав Ф4 (insert-missing по текущему ROLE_PERMISSIONS) ──
    for role, caps in ROLE_PERMISSIONS.items():
        for capability, allowed in caps.items():
            conn.execute(
                sa.text(
                    """
                    INSERT INTO fin_permission
                        (role, user_id, legal_entity_id, capability, allowed)
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
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FIN_PHASE4})
    conn.execute(sa.text("DROP TABLE IF EXISTS fin_base_recompute_job"))
    conn.execute(sa.text("DROP TABLE IF EXISTS fin_revenue_schedule"))
    # Права — безопасный доввод дефолтов; откат не сносит (иначе сломаем доступ).
