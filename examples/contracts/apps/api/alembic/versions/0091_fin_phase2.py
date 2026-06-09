"""Финансы Ф2: реестр платежей + согласование под типы + заявки менеджеров.

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist). Additive поверх Ф0+Ф1 (head=0090).

Создаёт 4 таблицы (G §4/§6):
  - fin_payment_registry    — батч расходов по одному счёту-источнику;
  - fin_request             — заявка менеджера (ЗП/комиссия/расход/платёж) → операция;
  - fin_approval_scenario   — настраиваемый сценарий согласования под op_type+entity+сумму;
  - fin_approval            — полиморфный голос (operation/registry/request/invoice).
И 2 колонки на fin_operation (заложены спекой J, но в Ф0 не материализованы):
  - registry_id      → fin_payment_registry (позиция реестра);
  - fin_request_id   → fin_request (операция, рождённая заявкой).

Идемпотентно: все CREATE/ALTER через IF NOT EXISTS под advisory-lock 91_007 (повторный
прогон и scale=2 = no-op). Сидов нет — сценарии согласования настраиваются через UI/API.

downgrade: дроп колонок + таблиц (обратный порядок зависимостей). Закрытые периоды
не затрагивает (Ф2-сущности — слой над GL, проводки не трогаются).

Revision ID: 0091_fin_phase2
Revises: 0090_fin_permission_reseed
Create Date: 2026-06-03
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op

revision: str = "0091_fin_phase2"
down_revision: str | None = "0090_fin_permission_reseed"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ. Занятые fin-ключи: 91_001(0085) 91_002(0086)
#: 91_004(0088) 91_005(0089) 91_006(0090). Свободен 91_007.
_LOCK_FIN_PHASE2 = 91_007


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FIN_PHASE2})

    # ── fin_payment_registry ──
    conn.execute(sa.text(
        """
        CREATE TABLE IF NOT EXISTS fin_payment_registry (
            id                  SERIAL PRIMARY KEY,
            number              VARCHAR(32),
            legal_entity_id     INTEGER NOT NULL REFERENCES fin_legal_entity(id) ON DELETE RESTRICT,
            source_account_id   INTEGER NOT NULL REFERENCES fin_money_account(id) ON DELETE RESTRICT,
            registry_date       DATE NOT NULL,
            title               VARCHAR(255),
            approval_status     VARCHAR(12) NOT NULL DEFAULT 'draft',
            payment_status      VARCHAR(10) NOT NULL DEFAULT 'new',
            comment             TEXT,
            created_by_user_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            submitted_at        TIMESTAMPTZ,
            approved_at         TIMESTAMPTZ,
            posted_at           TIMESTAMPTZ,
            created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
            updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
            CONSTRAINT ck_fin_registry_approval_status
                CHECK (approval_status IN ('draft','on_review','approved','rejected')),
            CONSTRAINT ck_fin_registry_payment_status
                CHECK (payment_status IN ('new','partial','paid'))
        )
        """
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_payment_registry_legal_entity_id "
        "ON fin_payment_registry (legal_entity_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_payment_registry_source_account_id "
        "ON fin_payment_registry (source_account_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_payment_registry_registry_date "
        "ON fin_payment_registry (registry_date)"
    ))

    # ── fin_request ──
    conn.execute(sa.text(
        """
        CREATE TABLE IF NOT EXISTS fin_request (
            id                       SERIAL PRIMARY KEY,
            number                   VARCHAR(32),
            request_type             VARCHAR(24) NOT NULL,
            legal_entity_id          INTEGER NOT NULL REFERENCES fin_legal_entity(id) ON DELETE RESTRICT,
            requester_user_id        INTEGER REFERENCES users(id) ON DELETE SET NULL,
            amount                   NUMERIC(18,2) NOT NULL,
            currency                 VARCHAR(8) NOT NULL,
            op_type_id               INTEGER REFERENCES fin_op_type(id) ON DELETE SET NULL,
            counterparty_company_id  INTEGER REFERENCES crm_companies(id) ON DELETE SET NULL,
            payee_user_id            INTEGER REFERENCES users(id) ON DELETE SET NULL,
            cashflow_category_id     INTEGER REFERENCES fin_cashflow_category(id) ON DELETE SET NULL,
            period_year              INTEGER,
            period_month             INTEGER,
            desired_date             DATE,
            description              TEXT,
            status                   VARCHAR(12) NOT NULL DEFAULT 'draft',
            resulting_operation_id   INTEGER REFERENCES fin_operation(id) ON DELETE SET NULL,
            rejected_reason          VARCHAR(512),
            submitted_at             TIMESTAMPTZ,
            decided_at               TIMESTAMPTZ,
            created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
            updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
            CONSTRAINT ck_fin_request_amount_pos CHECK (amount > 0),
            CONSTRAINT ck_fin_request_type
                CHECK (request_type IN ('salary','commission','expense_reimbursement','payment')),
            CONSTRAINT ck_fin_request_status
                CHECK (status IN ('draft','submitted','approved','rejected','paid','cancelled')),
            CONSTRAINT ck_fin_request_period_month
                CHECK (period_month IS NULL OR (period_month >= 1 AND period_month <= 12))
        )
        """
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_request_legal_entity_id "
        "ON fin_request (legal_entity_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_request_requester_user_id "
        "ON fin_request (requester_user_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_request_status ON fin_request (status)"
    ))

    # ── fin_approval_scenario ──
    conn.execute(sa.text(
        """
        CREATE TABLE IF NOT EXISTS fin_approval_scenario (
            id                  SERIAL PRIMARY KEY,
            name                VARCHAR(128) NOT NULL,
            applies_to          VARCHAR(12) NOT NULL DEFAULT 'operation',
            op_type_id          INTEGER REFERENCES fin_op_type(id) ON DELETE CASCADE,
            legal_entity_id     INTEGER REFERENCES fin_legal_entity(id) ON DELETE CASCADE,
            min_amount          NUMERIC(18,2),
            max_amount          NUMERIC(18,2),
            stages              JSONB NOT NULL DEFAULT '[]',
            priority            INTEGER NOT NULL DEFAULT 0,
            is_active           BOOLEAN NOT NULL DEFAULT true,
            created_by_user_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
            updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
            CONSTRAINT ck_fin_scenario_applies_to
                CHECK (applies_to IN ('operation','registry','request','invoice'))
        )
        """
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_approval_scenario_op_type_id "
        "ON fin_approval_scenario (op_type_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_approval_scenario_legal_entity_id "
        "ON fin_approval_scenario (legal_entity_id)"
    ))

    # ── fin_approval (полиморфный голос) ──
    conn.execute(sa.text(
        """
        CREATE TABLE IF NOT EXISTS fin_approval (
            id                SERIAL PRIMARY KEY,
            approvable_kind   VARCHAR(12) NOT NULL,
            approvable_id     INTEGER NOT NULL,
            scenario_id       INTEGER REFERENCES fin_approval_scenario(id) ON DELETE SET NULL,
            user_id           INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            stage_order       INTEGER NOT NULL DEFAULT 0,
            decision          VARCHAR(10) NOT NULL DEFAULT 'pending',
            comment           TEXT,
            decided_at        TIMESTAMPTZ,
            created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
            CONSTRAINT ck_fin_approval_kind
                CHECK (approvable_kind IN ('operation','registry','request','invoice')),
            CONSTRAINT ck_fin_approval_decision
                CHECK (decision IN ('pending','approved','rejected')),
            CONSTRAINT uq_fin_approval_vote
                UNIQUE (approvable_kind, approvable_id, stage_order, user_id)
        )
        """
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_approval_approvable_kind "
        "ON fin_approval (approvable_kind)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_approval_approvable_id "
        "ON fin_approval (approvable_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_approval_user_id ON fin_approval (user_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_approval_target "
        "ON fin_approval (approvable_kind, approvable_id)"
    ))

    # ── fin_operation: registry_id + fin_request_id (заложены J, в Ф0 не материализованы) ──
    conn.execute(sa.text(
        "ALTER TABLE fin_operation ADD COLUMN IF NOT EXISTS registry_id INTEGER "
        "REFERENCES fin_payment_registry(id) ON DELETE SET NULL"
    ))
    conn.execute(sa.text(
        "ALTER TABLE fin_operation ADD COLUMN IF NOT EXISTS fin_request_id INTEGER "
        "REFERENCES fin_request(id) ON DELETE SET NULL"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_operation_registry_id "
        "ON fin_operation (registry_id)"
    ))
    conn.execute(sa.text(
        "CREATE INDEX IF NOT EXISTS ix_fin_operation_fin_request_id "
        "ON fin_operation (fin_request_id)"
    ))


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FIN_PHASE2})

    conn.execute(sa.text("DROP INDEX IF EXISTS ix_fin_operation_fin_request_id"))
    conn.execute(sa.text("DROP INDEX IF EXISTS ix_fin_operation_registry_id"))
    conn.execute(sa.text("ALTER TABLE fin_operation DROP COLUMN IF EXISTS fin_request_id"))
    conn.execute(sa.text("ALTER TABLE fin_operation DROP COLUMN IF EXISTS registry_id"))

    conn.execute(sa.text("DROP TABLE IF EXISTS fin_approval"))
    conn.execute(sa.text("DROP TABLE IF EXISTS fin_approval_scenario"))
    conn.execute(sa.text("DROP TABLE IF EXISTS fin_request"))
    conn.execute(sa.text("DROP TABLE IF EXISTS fin_payment_registry"))
