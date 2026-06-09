"""Финансы Ф5: инвойсы + акты + вендор-счета (полный AR/AP cycle + НДС-книги).

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist). Additive поверх Ф0-Ф2 (head=0093).
Ф3 (write-through ContractPayment) и Ф4 (accrual/смена базы) ПРОПУЩЕНЫ по решению
владельца — Ф5 стоит самостоятельно: инвойс при issue постит выручку+AR+НДС output
(accrual-by-invoice), вендор-счёт при confirm постит расход+НДС input+AP.

Создаёт 6 таблиц (G §9, решение 9):
  - fin_invoice + fin_invoice_line          — счёт клиенту → AR(1210)/выручка(4xxx)/НДС output(2310);
  - fin_act + fin_act_line                  — акт выполнения (документ, БЕЗ проводки — не задваивает выручку);
  - fin_vendor_bill + fin_vendor_bill_line  — входящий счёт → расход(5xxx)/НДС input(1910)/AP(2110).

Идемпотентно: CREATE TABLE/INDEX IF NOT EXISTS под advisory-lock 91_010 (повторный
прогон и scale=2 = no-op). Сидов нет (документы создаются через API). Капы Ф5
(manage_invoices/manage_vendor_bills/view_vat) сидятся отдельной миграцией 0095.

downgrade: дроп таблиц в обратном порядке зависимостей. Закрытые периоды не
затрагивает (проводки инвойсов/счетов не трогаются — это слой над GL).

Revision ID: 0094_fin_phase5
Revises: 0093_fin_settings_auto_approve
Create Date: 2026-06-03
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op

revision: str = "0094_fin_phase5"
down_revision: str | None = "0093_fin_settings_auto_approve"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ. Занятые fin-ключи: 91_001(0085) 91_002(0086)
#: 91_004(0088) 91_005(0089) 91_006(0090) 91_007(0091) 91_008(0092) 91_009(0093).
#: Свободен 91_010.
_LOCK_FIN_PHASE5 = 91_010


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FIN_PHASE5})

    # ── fin_invoice ──
    conn.execute(sa.text(
        """
        CREATE TABLE IF NOT EXISTS fin_invoice (
            id                       SERIAL PRIMARY KEY,
            number                   VARCHAR(32),
            legal_entity_id          INTEGER NOT NULL REFERENCES fin_legal_entity(id) ON DELETE RESTRICT,
            counterparty_company_id  INTEGER NOT NULL REFERENCES crm_companies(id) ON DELETE RESTRICT,
            contact_id               INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
            deal_id                  INTEGER REFERENCES deals(id) ON DELETE SET NULL,
            contract_id              INTEGER REFERENCES contracts(id) ON DELETE SET NULL,
            subscription_id          INTEGER REFERENCES client_subscriptions(id) ON DELETE SET NULL,
            issue_date               DATE NOT NULL,
            due_date                 DATE,
            currency                 VARCHAR(8) NOT NULL,
            amount_net               NUMERIC(18,2) NOT NULL DEFAULT 0,
            vat_amount               NUMERIC(18,2) NOT NULL DEFAULT 0,
            amount_gross             NUMERIC(18,2) NOT NULL DEFAULT 0,
            paid_amount              NUMERIC(18,2) NOT NULL DEFAULT 0,
            status                   VARCHAR(16) NOT NULL DEFAULT 'draft',
            revenue_account_code     VARCHAR(8) NOT NULL DEFAULT '4030',
            purpose                  TEXT,
            amount_in_words          VARCHAR(512),
            document_file_id         INTEGER,
            journal_entry_id         INTEGER REFERENCES fin_journal_entry(id) ON DELETE SET NULL,
            created_by_user_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
            issued_at                TIMESTAMPTZ,
            created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
            updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
            CONSTRAINT ck_fin_invoice_status
                CHECK (status IN ('draft','issued','partially_paid','paid','cancelled'))
        )
        """
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_invoice_legal_entity_id "
        "ON fin_invoice (legal_entity_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_invoice_counterparty_company_id "
        "ON fin_invoice (counterparty_company_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_invoice_deal_id ON fin_invoice (deal_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_invoice_contract_id ON fin_invoice (contract_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_invoice_subscription_id ON fin_invoice (subscription_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_invoice_issue_date ON fin_invoice (issue_date)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_invoice_due_date ON fin_invoice (due_date)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_invoice_status ON fin_invoice (status)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_invoice_le_status "
        "ON fin_invoice (legal_entity_id, status)"
    ))

    # ── fin_invoice_line ──
    conn.execute(sa.text(
        """
        CREATE TABLE IF NOT EXISTS fin_invoice_line (
            id                    SERIAL PRIMARY KEY,
            invoice_id            INTEGER NOT NULL REFERENCES fin_invoice(id) ON DELETE CASCADE,
            name                  VARCHAR(512) NOT NULL,
            qty                   NUMERIC(18,4) NOT NULL DEFAULT 1,
            unit_price            NUMERIC(18,2) NOT NULL,
            amount_net            NUMERIC(18,2) NOT NULL,
            vat_rate_id           INTEGER REFERENCES fin_vat_rate(id) ON DELETE SET NULL,
            vat_amount            NUMERIC(18,2) NOT NULL DEFAULT 0,
            amount_gross          NUMERIC(18,2) NOT NULL,
            revenue_account_code  VARCHAR(8),
            cashflow_category_id  INTEGER REFERENCES fin_cashflow_category(id) ON DELETE SET NULL,
            sort_order            INTEGER NOT NULL DEFAULT 0
        )
        """
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_invoice_line_invoice_id "
        "ON fin_invoice_line (invoice_id)"
    ))

    # ── fin_act ──
    conn.execute(sa.text(
        """
        CREATE TABLE IF NOT EXISTS fin_act (
            id                       SERIAL PRIMARY KEY,
            number                   VARCHAR(32),
            legal_entity_id          INTEGER NOT NULL REFERENCES fin_legal_entity(id) ON DELETE RESTRICT,
            counterparty_company_id  INTEGER NOT NULL REFERENCES crm_companies(id) ON DELETE RESTRICT,
            invoice_id               INTEGER REFERENCES fin_invoice(id) ON DELETE SET NULL,
            contract_id              INTEGER REFERENCES contracts(id) ON DELETE SET NULL,
            subscription_id          INTEGER REFERENCES client_subscriptions(id) ON DELETE SET NULL,
            act_date                 DATE NOT NULL,
            period_year              INTEGER,
            period_month             INTEGER,
            currency                 VARCHAR(8) NOT NULL,
            amount_net               NUMERIC(18,2) NOT NULL DEFAULT 0,
            vat_amount               NUMERIC(18,2) NOT NULL DEFAULT 0,
            amount_gross             NUMERIC(18,2) NOT NULL DEFAULT 0,
            status                   VARCHAR(12) NOT NULL DEFAULT 'draft',
            purpose                  TEXT,
            amount_in_words          VARCHAR(512),
            document_file_id         INTEGER,
            signed_at                TIMESTAMPTZ,
            created_by_user_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
            updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
            CONSTRAINT ck_fin_act_status
                CHECK (status IN ('draft','issued','signed','cancelled')),
            CONSTRAINT ck_fin_act_period_month
                CHECK (period_month IS NULL OR (period_month >= 1 AND period_month <= 12))
        )
        """
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_act_legal_entity_id ON fin_act (legal_entity_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_act_counterparty_company_id "
        "ON fin_act (counterparty_company_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_act_invoice_id ON fin_act (invoice_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_act_act_date ON fin_act (act_date)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_act_status ON fin_act (status)"
    ))

    # ── fin_act_line ──
    conn.execute(sa.text(
        """
        CREATE TABLE IF NOT EXISTS fin_act_line (
            id            SERIAL PRIMARY KEY,
            act_id        INTEGER NOT NULL REFERENCES fin_act(id) ON DELETE CASCADE,
            name          VARCHAR(512) NOT NULL,
            qty           NUMERIC(18,4) NOT NULL DEFAULT 1,
            unit_price    NUMERIC(18,2) NOT NULL,
            amount_net    NUMERIC(18,2) NOT NULL,
            vat_rate_id   INTEGER REFERENCES fin_vat_rate(id) ON DELETE SET NULL,
            vat_amount    NUMERIC(18,2) NOT NULL DEFAULT 0,
            amount_gross  NUMERIC(18,2) NOT NULL,
            sort_order    INTEGER NOT NULL DEFAULT 0
        )
        """
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_act_line_act_id ON fin_act_line (act_id)"
    ))

    # ── fin_vendor_bill ──
    conn.execute(sa.text(
        """
        CREATE TABLE IF NOT EXISTS fin_vendor_bill (
            id                    SERIAL PRIMARY KEY,
            number                VARCHAR(32),
            bill_no               VARCHAR(64),
            legal_entity_id       INTEGER NOT NULL REFERENCES fin_legal_entity(id) ON DELETE RESTRICT,
            supplier_company_id   INTEGER NOT NULL REFERENCES crm_companies(id) ON DELETE RESTRICT,
            bill_date             DATE NOT NULL,
            due_date              DATE,
            currency              VARCHAR(8) NOT NULL,
            amount_net            NUMERIC(18,2) NOT NULL DEFAULT 0,
            vat_amount            NUMERIC(18,2) NOT NULL DEFAULT 0,
            amount_gross          NUMERIC(18,2) NOT NULL DEFAULT 0,
            paid_amount           NUMERIC(18,2) NOT NULL DEFAULT 0,
            status                VARCHAR(16) NOT NULL DEFAULT 'draft',
            expense_account_code  VARCHAR(8) NOT NULL DEFAULT '5990',
            cashflow_category_id  INTEGER REFERENCES fin_cashflow_category(id) ON DELETE SET NULL,
            purpose               TEXT,
            document_file_id      INTEGER,
            journal_entry_id      INTEGER REFERENCES fin_journal_entry(id) ON DELETE SET NULL,
            created_by_user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
            confirmed_at          TIMESTAMPTZ,
            created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
            updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
            CONSTRAINT ck_fin_vendor_bill_status
                CHECK (status IN ('draft','confirmed','partially_paid','paid','cancelled'))
        )
        """
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_vendor_bill_legal_entity_id "
        "ON fin_vendor_bill (legal_entity_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_vendor_bill_supplier_company_id "
        "ON fin_vendor_bill (supplier_company_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_vendor_bill_bill_date ON fin_vendor_bill (bill_date)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_vendor_bill_due_date ON fin_vendor_bill (due_date)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_vendor_bill_status ON fin_vendor_bill (status)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_vendor_bill_le_status "
        "ON fin_vendor_bill (legal_entity_id, status)"
    ))

    # ── fin_vendor_bill_line ──
    conn.execute(sa.text(
        """
        CREATE TABLE IF NOT EXISTS fin_vendor_bill_line (
            id                    SERIAL PRIMARY KEY,
            bill_id               INTEGER NOT NULL REFERENCES fin_vendor_bill(id) ON DELETE CASCADE,
            name                  VARCHAR(512) NOT NULL,
            qty                   NUMERIC(18,4) NOT NULL DEFAULT 1,
            unit_price            NUMERIC(18,2) NOT NULL,
            amount_net            NUMERIC(18,2) NOT NULL,
            vat_rate_id           INTEGER REFERENCES fin_vat_rate(id) ON DELETE SET NULL,
            vat_amount            NUMERIC(18,2) NOT NULL DEFAULT 0,
            amount_gross          NUMERIC(18,2) NOT NULL,
            expense_account_code  VARCHAR(8),
            cashflow_category_id  INTEGER REFERENCES fin_cashflow_category(id) ON DELETE SET NULL,
            sort_order            INTEGER NOT NULL DEFAULT 0
        )
        """
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_vendor_bill_line_bill_id "
        "ON fin_vendor_bill_line (bill_id)"
    ))


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FIN_PHASE5})
    # Обратный порядок зависимостей (линии → шапки; акт ссылается на инвойс).
    conn.execute(sa.text("DROP TABLE IF EXISTS fin_vendor_bill_line"))
    conn.execute(sa.text("DROP TABLE IF EXISTS fin_vendor_bill"))
    conn.execute(sa.text("DROP TABLE IF EXISTS fin_act_line"))
    conn.execute(sa.text("DROP TABLE IF EXISTS fin_act"))
    conn.execute(sa.text("DROP TABLE IF EXISTS fin_invoice_line"))
    conn.execute(sa.text("DROP TABLE IF EXISTS fin_invoice"))
