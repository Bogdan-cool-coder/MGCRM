"""P2 database integrity (audit A3) — explicit FK ondelete + missing indexes
+ 'needs_rework' value in approval_decision enum.

Три блока:

1. FK ondelete: 27 FK были созданы без явного ON DELETE (=> PG NO ACTION).
   Делаем семантику явной — drop + recreate с правильным правилом:
     * CASCADE      — дочерние строки без родителя бессмысленны (line items,
                      sub-modules, approvals/remarks/revisions договора). Эти
                      уже были CASCADE в моделях — здесь НЕ трогаем.
     * SET NULL     — необязательная ссылка, строка переживает удаление
                      родителя (audit/author/owner-юзеры на nullable-колонках).
                      Удаление пользователя НЕ должно убивать его сделки/договоры.
     * RESTRICT     — справочник/структура, удаление родителя должно
                      блокироваться (product_id у line items, platform_id
                      подписки, pipeline/stage сделки — сделке нужен этап).
   NOT NULL user-FK (contracts.author_user_id, approvals.user_id,
   contract_remarks.author_user_id) физически не могут SET NULL → RESTRICT:
   юзера-автора нельзя удалить, только деактивировать.

2. Индексы: горячие WHERE/JOIN-колонки без индекса (CREATE INDEX IF NOT EXISTS).

3. approval_decision enum: добавляем значение 'needs_rework', чтобы возврат
   на доработку отличался от жёсткого rejected в аналитике. Значение в этой
   же миграции НЕ используется (только декларируется) → ADD VALUE в транзакции
   PG безопасен (тот же паттерн, что и 0100 для contract_status).

Идемпотентно: drop constraint IF EXISTS перед add; index IF NOT EXISTS;
ADD VALUE IF NOT EXISTS. Advisory-lock 97_010 (диапазон 97_0xx свободен).

Revision ID: 0106_fk_ondelete
Revises: 0105_vis_role_scopes
Create Date: 2026-06-05
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0106_fk_ondelete"
down_revision: str | None = "0105_vis_role_scopes"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Advisory-lock ключ (диапазон 97_0xx свободен; 92_xxx/96_xxx заняты ранее).
_LOCK_FK_ONDELETE = 97_010

# (table, constraint_name, column, ref_table, ref_col, ondelete)
# Только FK, у которых сейчас неявный NO ACTION и которым нужна явная семантика.
# CASCADE-FK (contract_id у дочерних, deal_id и т.п.) уже корректны — не трогаем.
_FK_MATRIX: tuple[tuple[str, str, str, str, str, str], ...] = (
    # --- SET NULL: необязательные user/audit ссылки на nullable-колонках ---
    ("audit_log", "audit_log_user_id_fkey", "user_id", "users", "id", "SET NULL"),
    ("client_notes", "client_notes_author_user_id_fkey", "author_user_id", "users", "id", "SET NULL"),
    ("client_tasks", "client_tasks_assignee_user_id_fkey", "assignee_user_id", "users", "id", "SET NULL"),
    ("client_tasks", "client_tasks_created_by_user_id_fkey", "created_by_user_id", "users", "id", "SET NULL"),
    ("contract_attachments", "contract_attachments_uploaded_by_user_id_fkey", "uploaded_by_user_id", "users", "id", "SET NULL"),
    ("contract_remarks", "contract_remarks_resolved_by_user_id_fkey", "resolved_by_user_id", "users", "id", "SET NULL"),
    ("contract_revisions", "contract_revisions_created_by_user_id_fkey", "created_by_user_id", "users", "id", "SET NULL"),
    ("deals", "deals_owner_user_id_fkey", "owner_user_id", "users", "id", "SET NULL"),
    ("deal_stage_history", "deal_stage_history_user_id_fkey", "user_id", "users", "id", "SET NULL"),
    ("client_subscriptions", "client_subscriptions_imp_pm_user_id_fkey", "imp_pm_user_id", "users", "id", "SET NULL"),
    ("client_subscriptions", "client_subscriptions_sup_pm_user_id_fkey", "sup_pm_user_id", "users", "id", "SET NULL"),
    ("client_subscriptions", "client_subscriptions_am_user_id_fkey", "am_user_id", "users", "id", "SET NULL"),
    # nullable optional plan refs on line items
    ("contract_items", "contract_items_plan_id_fkey", "plan_id", "product_plans", "id", "SET NULL"),
    ("deal_products", "deal_products_plan_id_fkey", "plan_id", "product_plans", "id", "SET NULL"),

    # --- RESTRICT: справочник/структура, удаление должно блокироваться ---
    # NOT NULL user-FK — SET NULL невозможен, RESTRICT защищает историю.
    ("contracts", "contracts_author_user_id_fkey", "author_user_id", "users", "id", "RESTRICT"),
    ("approvals", "approvals_user_id_fkey", "user_id", "users", "id", "RESTRICT"),
    ("contract_remarks", "contract_remarks_author_user_id_fkey", "author_user_id", "users", "id", "RESTRICT"),
    # catalog refs — нельзя удалить продукт/платформу, пока на них ссылаются
    ("contract_items", "contract_items_product_id_fkey", "product_id", "products", "id", "RESTRICT"),
    ("deal_products", "deal_products_product_id_fkey", "product_id", "products", "id", "RESTRICT"),
    ("client_subscriptions", "client_subscriptions_platform_id_fkey", "platform_id", "platforms", "id", "RESTRICT"),
    # сделке нужен этап/воронка — удаление этапа/воронки с активными сделками блокируем
    ("deals", "deals_pipeline_id_fkey", "pipeline_id", "pipelines", "id", "RESTRICT"),
    ("deals", "deals_stage_id_fkey", "stage_id", "pipeline_stages", "id", "RESTRICT"),
    ("deal_stage_history", "deal_stage_history_from_stage_id_fkey", "from_stage_id", "pipeline_stages", "id", "RESTRICT"),
    ("deal_stage_history", "deal_stage_history_to_stage_id_fkey", "to_stage_id", "pipeline_stages", "id", "RESTRICT"),

    # --- RESTRICT: contract → counterparty (сторона подписанного договора не
    #     должна молча исчезать; на удаление контрагента — блок). ---
    ("contracts", "contracts_counterparty_id_fkey", "counterparty_id", "counterparties", "id", "RESTRICT"),
)

# Откатные правила: для большинства возвращаем неявный NO ACTION (как было).
_DOWN_ONDELETE = "NO ACTION"

# (table, index_name, columns-SQL) — горячие колонки без индекса.
_INDEXES: tuple[tuple[str, str, str], ...] = (
    ("contracts", "ix_contracts_author_user_id", "author_user_id"),
    ("contracts", "ix_contracts_counterparty_id", "counterparty_id"),
    ("activities", "ix_activities_created_at", "created_at"),
    ("activities", "ix_activities_created_by_id", "created_by_id"),
    ("activities", "ix_activities_completed_by_id", "completed_by_id"),
    ("contract_items", "ix_contract_items_product_id", "product_id"),
    ("deal_products", "ix_deal_products_product_id", "product_id"),
    ("deal_stage_history", "ix_deal_stage_history_user_id", "user_id"),
)


def _drop_add_fk(
    table: str, name: str, col: str, ref_table: str, ref_col: str, ondelete: str
) -> None:
    op.execute(f'ALTER TABLE "{table}" DROP CONSTRAINT IF EXISTS "{name}"')
    op.execute(
        f'ALTER TABLE "{table}" ADD CONSTRAINT "{name}" '
        f'FOREIGN KEY ("{col}") REFERENCES "{ref_table}" ("{ref_col}") '
        f"ON DELETE {ondelete}"
    )


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FK_ONDELETE})

    # 1) FK ondelete: drop + recreate с явным правилом (идемпотентно).
    for table, name, col, ref_table, ref_col, ondelete in _FK_MATRIX:
        _drop_add_fk(table, name, col, ref_table, ref_col, ondelete)

    # 2) Индексы на горячих колонках.
    for table, name, cols in _INDEXES:
        op.execute(f'CREATE INDEX IF NOT EXISTS "{name}" ON "{table}" ({cols})')

    # 3) approval_decision: добавить 'needs_rework' (декларация, не используется
    #    в этой миграции → ADD VALUE в транзакции безопасен).
    op.execute(
        "ALTER TYPE approval_decision "
        "ADD VALUE IF NOT EXISTS 'needs_rework' AFTER 'rejected'"
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FK_ONDELETE})

    # 1) Вернуть FK к неявному NO ACTION (исходное поведение).
    for table, name, col, ref_table, ref_col, _ondelete in _FK_MATRIX:
        _drop_add_fk(table, name, col, ref_table, ref_col, _DOWN_ONDELETE)

    # 2) Удалить добавленные индексы.
    for table, name, _cols in _INDEXES:
        op.execute(f'DROP INDEX IF EXISTS "{name}"')

    # 3) PostgreSQL не поддерживает DROP VALUE у enum — 'needs_rework' остаётся.
    #    Безопасный no-op: лишнее значение enum ни на что не влияет, если ни одна
    #    строка его не использует (downgrade кода вернёт запись 'rejected').
