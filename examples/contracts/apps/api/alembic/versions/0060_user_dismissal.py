"""Epic 14.2 — Company Management: dismissal fields на users.

Добавляем 5 колонок в `users` для увольнения / отпуска / замещения:

- dismissed_at TIMESTAMPTZ NULL
    Момент увольнения. NULL = активный сотрудник. Заполняется в endpoint'е
    /api/admin/users/{user_id}/dismiss; обнуляется через /restore.

- dismissed_by_user_id INT FK users.id ON DELETE SET NULL
    Кто провёл процедуру увольнения (admin). При удалении этого юзера
    обнуляется (история не теряется, но автор стирается).

- dismissed_reason TEXT NULL
    Свободная текстовая причина увольнения (для аудита и compliance).

- substitute_user_id INT FK users.id ON DELETE SET NULL
    Заместитель — кому передаются права/задачи/контрагенты при увольнении.
    Также используется для отпусков (см. user_vacations.substitute_user_id
    в миграции 0062).

- employment_status VARCHAR(16) DEFAULT 'active'
    Расширенный статус: 'active' | 'dismissed' | 'on_vacation'.
    Дополняет is_active (которое остаётся как боевой флажок для логина).
    'on_vacation' проставляется services/work_schedule.py при старте отпуска.

Индексы:
- idx_users_status — partial WHERE employment_status != 'active': нужно
  для админ-фильтра «уволенные» / «в отпуске» без full-scan.
- idx_users_dismissed — partial WHERE dismissed_at IS NOT NULL ORDER BY
  dismissed_at DESC: список последних увольнений в карточке HR.

Defensive advisory-lock seed-key 57_993 (0xE289 — Epic 14.2 Dismissal).
DDL-only.

Revision ID: 0060_user_dismissal  (19 chars ≤32 ✓)
Revises: 0059_xxx (Эпик 24 backend → uses 0057..0059)
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0060_user_dismissal"
# Координация с Эпиком 24: миграции 0057..0059 он добавляет до нас.
# Если Эпик 24 ещё не залит — менеджер должен подтянуть branch и
# поправить down_revision вручную. В CI/тесте сейчас базируемся на
# последней актуальной — 0056_category_options (до Эпика 24).
# После merge Эпика 24 нужно: alembic merge -m "merge_epic_24_14_2"
# или вручную переписать down_revision = "0059_xxx".
down_revision: Union[str, None] = "0056_category_options"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE289 = 57_993 — Epic 14.2 Dismissal seed-key.
_SEED_LOCK_EPIC_14_2_DISMISSAL = 57_993


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_14_2_DISMISSAL},
    )

    op.add_column(
        "users",
        sa.Column("dismissed_at", sa.DateTime(timezone=True), nullable=True),
    )
    op.add_column(
        "users",
        sa.Column(
            "dismissed_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
    )
    op.add_column(
        "users",
        sa.Column("dismissed_reason", sa.Text(), nullable=True),
    )
    op.add_column(
        "users",
        sa.Column(
            "substitute_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
    )
    op.add_column(
        "users",
        sa.Column(
            "employment_status",
            sa.String(length=16),
            nullable=False,
            server_default=sa.text("'active'"),
        ),
    )

    # Partial index по non-active — типично админский фильтр (мало строк).
    op.create_index(
        "idx_users_status",
        "users",
        ["employment_status"],
        postgresql_where=sa.text("employment_status != 'active'"),
    )
    # Партиальный по dismissed_at DESC: список «недавно уволенных» в админке.
    op.create_index(
        "idx_users_dismissed",
        "users",
        [sa.text("dismissed_at DESC")],
        postgresql_where=sa.text("dismissed_at IS NOT NULL"),
    )


def downgrade() -> None:
    op.drop_index("idx_users_dismissed", table_name="users")
    op.drop_index("idx_users_status", table_name="users")
    op.drop_column("users", "employment_status")
    op.drop_column("users", "substitute_user_id")
    op.drop_column("users", "dismissed_reason")
    op.drop_column("users", "dismissed_by_user_id")
    op.drop_column("users", "dismissed_at")
