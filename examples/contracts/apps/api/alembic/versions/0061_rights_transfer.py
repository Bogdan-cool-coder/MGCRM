"""Epic 14.2 — Company Management: rights transfer audit logs.

Две таблицы для аудита передачи прав/задач/контрагентов от одного юзера
к другому. Используется при увольнении (automatic transfer) или вручную
admin'ом.

1) rights_transfer_logs — заголовок операции передачи.

   from_user_id / to_user_id — стороны передачи. ON DELETE SET NULL чтобы
   при удалении юзера лог не дропался (его подняли для аудита).

   initiated_by_user_id — admin, который запустил операцию.

   categories JSONB — массив строк: что передавалось. Whitelist:
     "contacts" — Counterparty.responsible_user_id и owner_user_id
     "deals" — Deal.owner_user_id
     "tasks_assigner" — Activity.assigner_user_id (кто поставил задачу)
     "tasks_executor" — Activity.responsible_user_id (кто выполняет)
     "approvals" — Approval.approver_user_id
     "settings" — кастомные правила (visibility settings, automations и т.п.)

   reason TEXT — текстовое объяснение (для audit + compliance).

   executed_at TIMESTAMPTZ DEFAULT now() — когда применено.

   reversible_until TIMESTAMPTZ — 7 дней от executed_at. После — undo
   запрещён (revert endpoint вернёт 400).

   is_reverted BOOLEAN DEFAULT false — флажок «откатили».
   reverted_at / reverted_by_user_id — кто и когда откатил.

2) rights_transfer_items — детализация: какой именно entity передан.

   transfer_log_id INT FK CASCADE — родитель.

   entity_type VARCHAR(32) — 'counterparty' | 'deal' | 'lead' | 'activity'
     | 'approval' | 'setting'.

   entity_id INT — id переданного объекта.

   old_owner_user_id / new_owner_user_id — для отката (revert UPDATE entity
     SET <field> = old_owner WHERE id = entity_id).

   field_name VARCHAR(64) — какое именно поле меняли:
     'responsible_user_id', 'owner_user_id', 'author_user_id',
     'assigner_user_id', 'approver_user_id', ...

   reverted_at TIMESTAMPTZ NULL — отметка что конкретно этот item откачен
   (на случай частичного отката в будущем; пока всё-или-ничего).

Индексы:
- idx_rt_from_user — частый фильтр «что передал юзер X».
- idx_rt_executed — DESC по executed_at: лог в админке.
- idx_rti_log — child by parent.
- idx_rti_entity — поиск «какие операции касались object'а X».

Defensive advisory-lock seed-key 57_994 (0xE28A — Epic 14.2 RightsTransfer).
DDL-only.

Revision ID: 0061_rights_transfer  (20 chars ≤32 ✓)
Revises: 0060_user_dismissal
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0061_rights_transfer"
down_revision: Union[str, None] = "0060_user_dismissal"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE28A = 57_994 — Epic 14.2 RightsTransfer seed-key.
_SEED_LOCK_EPIC_14_2_RT = 57_994


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_14_2_RT},
    )

    # ============ rights_transfer_logs ============
    op.create_table(
        "rights_transfer_logs",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "from_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "to_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "initiated_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "categories",
            JSONB(),
            nullable=False,
            server_default=sa.text("'[]'::jsonb"),
        ),
        sa.Column("reason", sa.Text(), nullable=True),
        sa.Column(
            "executed_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "reversible_until",
            sa.DateTime(timezone=True),
            nullable=True,
        ),
        sa.Column(
            "is_reverted",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("false"),
        ),
        sa.Column(
            "reverted_at",
            sa.DateTime(timezone=True),
            nullable=True,
        ),
        sa.Column(
            "reverted_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
    )
    op.create_index(
        "idx_rt_from_user",
        "rights_transfer_logs",
        ["from_user_id"],
    )
    op.create_index(
        "idx_rt_executed",
        "rights_transfer_logs",
        [sa.text("executed_at DESC")],
    )

    # ============ rights_transfer_items ============
    op.create_table(
        "rights_transfer_items",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "transfer_log_id",
            sa.Integer(),
            sa.ForeignKey("rights_transfer_logs.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("entity_type", sa.String(length=32), nullable=False),
        sa.Column("entity_id", sa.Integer(), nullable=False),
        sa.Column(
            "old_owner_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "new_owner_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("field_name", sa.String(length=64), nullable=False),
        sa.Column(
            "reverted_at",
            sa.DateTime(timezone=True),
            nullable=True,
        ),
    )
    op.create_index(
        "idx_rti_log",
        "rights_transfer_items",
        ["transfer_log_id"],
    )
    op.create_index(
        "idx_rti_entity",
        "rights_transfer_items",
        ["entity_type", "entity_id"],
    )


def downgrade() -> None:
    op.drop_index("idx_rti_entity", table_name="rights_transfer_items")
    op.drop_index("idx_rti_log", table_name="rights_transfer_items")
    op.drop_table("rights_transfer_items")
    op.drop_index("idx_rt_executed", table_name="rights_transfer_logs")
    op.drop_index("idx_rt_from_user", table_name="rights_transfer_logs")
    op.drop_table("rights_transfer_logs")
