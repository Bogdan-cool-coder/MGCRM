"""Эпик 14 — Departments + Visibility ACL (часть 2): owner/department на сущностях
+ таблица `visibility_settings`.

Цель — ввести единый паттерн «у каждой бизнес-сущности есть владелец (User) и
отдел (Department)», чтобы scope-фильтр (services/access_control.py) мог
ограничивать видимость по правилу:
- personal       → owner_user_id = current_user.id
- department     → department_id = current_user.department_id
- department_and_children → department_id IN (subtree(current_user.department_id))
- all (default)  → без фильтра (бэквард-совместимо с существующими endpoints)

ALTER TABLE-добавления (все ADD COLUMN с NULL — без table-rewrite, безопасно
на больших таблицах PG 11+):
- counterparties: + owner_user_id, + department_id
  (`responsible_user_id` уже был с 0029 — это другое поле, ответственный
  менеджер ≠ owner. Owner — для scope-видимости; responsible — UI-«ответственный»)
- crm_companies: + owner_user_id, + department_id
- client_subscriptions: + owner_user_id, + department_id
  (имена ПМ/АМ — sup_pm_user_id / am_user_id / imp_pm_user_id — остаются)
- leads: + department_id (owner_id уже был как owner_id, не owner_user_id)
- deals: + department_id (owner_user_id уже был)

Новая таблица `visibility_settings` — одна строка на (entity_type, applies_to_role)
с дефолтом scope='all'. Админ настраивает матрицу через
/api/admin/visibility-settings.

Seed-default (scope='all' для всех entity × NULL role) — выполняется в
`app.services.visibility.seed_default_visibility_settings` под отдельным
advisory-lock, вызывается в lifespan startup.

Под `pg_advisory_xact_lock(728_274_141)` (defensive — env.py уже даёт глобальный
lock на миграцию).

Revision ID: 0036_owner_dept_fields  (22 chars ≤32 ✓)
Revises: 0035_departments
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0036_owner_dept_fields"
down_revision: Union[str, None] = "0035_departments"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_EPIC_14_OWNER = 728_274_141


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_14_OWNER},
    )

    # ============ Counterparty ============
    op.execute(
        "ALTER TABLE counterparties "
        "ADD COLUMN IF NOT EXISTS owner_user_id INTEGER "
        "REFERENCES users(id) ON DELETE SET NULL"
    )
    op.execute(
        "ALTER TABLE counterparties "
        "ADD COLUMN IF NOT EXISTS department_id INTEGER "
        "REFERENCES departments(id) ON DELETE SET NULL"
    )
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_counterparties_owner_user_id "
        "ON counterparties(owner_user_id)"
    )
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_counterparties_department_id "
        "ON counterparties(department_id)"
    )

    # ============ CrmCompany (crm_companies, Эпик 1.2) ============
    op.execute(
        "ALTER TABLE crm_companies "
        "ADD COLUMN IF NOT EXISTS owner_user_id INTEGER "
        "REFERENCES users(id) ON DELETE SET NULL"
    )
    op.execute(
        "ALTER TABLE crm_companies "
        "ADD COLUMN IF NOT EXISTS department_id INTEGER "
        "REFERENCES departments(id) ON DELETE SET NULL"
    )
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_crm_companies_owner_user_id "
        "ON crm_companies(owner_user_id)"
    )
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_crm_companies_department_id "
        "ON crm_companies(department_id)"
    )

    # ============ ClientSubscription (client_subscriptions, реестр CS) ============
    op.execute(
        "ALTER TABLE client_subscriptions "
        "ADD COLUMN IF NOT EXISTS owner_user_id INTEGER "
        "REFERENCES users(id) ON DELETE SET NULL"
    )
    op.execute(
        "ALTER TABLE client_subscriptions "
        "ADD COLUMN IF NOT EXISTS department_id INTEGER "
        "REFERENCES departments(id) ON DELETE SET NULL"
    )
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_client_subscriptions_owner_user_id "
        "ON client_subscriptions(owner_user_id)"
    )
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_client_subscriptions_department_id "
        "ON client_subscriptions(department_id)"
    )

    # ============ Lead (Эпик 1.0; owner_id уже был) ============
    op.execute(
        "ALTER TABLE leads "
        "ADD COLUMN IF NOT EXISTS department_id INTEGER "
        "REFERENCES departments(id) ON DELETE SET NULL"
    )
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_leads_department_id "
        "ON leads(department_id)"
    )

    # ============ Deal (owner_user_id уже был) ============
    op.execute(
        "ALTER TABLE deals "
        "ADD COLUMN IF NOT EXISTS department_id INTEGER "
        "REFERENCES departments(id) ON DELETE SET NULL"
    )
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_deals_department_id "
        "ON deals(department_id)"
    )

    # ============ visibility_settings ============
    op.create_table(
        "visibility_settings",
        sa.Column("id", sa.Integer(), primary_key=True),
        # "lead" | "deal" | "contract" | "subscription" | "counterparty" | "company"
        sa.Column("entity_type", sa.String(length=32), nullable=False),
        # "personal" | "department" | "department_and_children" | "all"
        sa.Column(
            "scope",
            sa.String(length=32),
            nullable=False,
            server_default=sa.text("'all'"),
        ),
        # NULL = применяется ко всем ролям; иначе конкретная роль
        # ('manager' | 'lawyer' | 'director' | 'admin'). admin всегда видит всё —
        # запись для admin role игнорируется на уровне apply_scope_filter.
        sa.Column("applies_to_role", sa.String(length=16), nullable=True),
        sa.Column(
            "updated_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.CheckConstraint(
            "scope IN ('personal','department','department_and_children','all')",
            name="ck_visibility_settings_scope",
        ),
        sa.CheckConstraint(
            "applies_to_role IS NULL OR "
            "applies_to_role IN ('manager','lawyer','director','admin')",
            name="ck_visibility_settings_role",
        ),
    )

    # UNIQUE на (entity_type, applies_to_role) с учётом NULL — partial-индекс
    # т.к. в Postgres NULL != NULL и UNIQUE не сработает для двух NULL без partial.
    # Получаем: одна строка на (entity, NULL) и одна на (entity, role).
    op.execute(
        "CREATE UNIQUE INDEX uq_visibility_settings_entity_role_notnull "
        "ON visibility_settings(entity_type, applies_to_role) "
        "WHERE applies_to_role IS NOT NULL"
    )
    op.execute(
        "CREATE UNIQUE INDEX uq_visibility_settings_entity_role_null "
        "ON visibility_settings(entity_type) "
        "WHERE applies_to_role IS NULL"
    )
    op.execute(
        "CREATE INDEX ix_visibility_settings_entity_type "
        "ON visibility_settings(entity_type)"
    )


def downgrade() -> None:
    # visibility_settings
    op.execute("DROP INDEX IF EXISTS ix_visibility_settings_entity_type")
    op.execute("DROP INDEX IF EXISTS uq_visibility_settings_entity_role_null")
    op.execute("DROP INDEX IF EXISTS uq_visibility_settings_entity_role_notnull")
    op.drop_table("visibility_settings")

    # Deal
    op.execute("DROP INDEX IF EXISTS ix_deals_department_id")
    op.execute("ALTER TABLE deals DROP COLUMN IF EXISTS department_id")

    # Lead
    op.execute("DROP INDEX IF EXISTS ix_leads_department_id")
    op.execute("ALTER TABLE leads DROP COLUMN IF EXISTS department_id")

    # ClientSubscription
    op.execute("DROP INDEX IF EXISTS ix_client_subscriptions_department_id")
    op.execute("DROP INDEX IF EXISTS ix_client_subscriptions_owner_user_id")
    op.execute("ALTER TABLE client_subscriptions DROP COLUMN IF EXISTS department_id")
    op.execute("ALTER TABLE client_subscriptions DROP COLUMN IF EXISTS owner_user_id")

    # CrmCompany
    op.execute("DROP INDEX IF EXISTS ix_crm_companies_department_id")
    op.execute("DROP INDEX IF EXISTS ix_crm_companies_owner_user_id")
    op.execute("ALTER TABLE crm_companies DROP COLUMN IF EXISTS department_id")
    op.execute("ALTER TABLE crm_companies DROP COLUMN IF EXISTS owner_user_id")

    # Counterparty
    op.execute("DROP INDEX IF EXISTS ix_counterparties_department_id")
    op.execute("DROP INDEX IF EXISTS ix_counterparties_owner_user_id")
    op.execute("ALTER TABLE counterparties DROP COLUMN IF EXISTS department_id")
    op.execute("ALTER TABLE counterparties DROP COLUMN IF EXISTS owner_user_id")
