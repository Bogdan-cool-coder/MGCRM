"""Эпик 14 — Departments + Visibility ACL (часть 1): расширение `departments` и users.

Department как сущность уже частично существует в моделях (минимальная версия:
id/name/sort_order, миграция 0010 при seed_pipeline). Эпик 14 поднимает её до
полноценной иерархии с parent_id / head_user_id / soft delete + добавляет на
users поле `manager_id` (прямой руководитель).

Поля на existing `departments`:
- parent_id INT NULL REFERENCES departments(id) ON DELETE SET NULL
  — для дерева отделов (ОП → Sales KZ → Sales Almaty).
- head_user_id INT NULL REFERENCES users(id) ON DELETE SET NULL
  — руководитель отдела (директор/ROP/PM-Lead). Используется в визуализации
  и (опционально) в scope=department_and_children для авто-визибилити подчинённым.
- is_active BOOLEAN DEFAULT TRUE — soft-delete (`DELETE` через PATCH is_active=false,
  не физическое удаление; FK на users.department_id остаётся, не теряем владельцев).
- created_at / updated_at TIMESTAMPTZ — стандарт.

Поля на existing `users` (User.department_id уже был — миграция 0010, добавляем
только manager_id):
- manager_id INT NULL REFERENCES users(id) ON DELETE SET NULL — прямой руководитель.
  Используется в onboarding-эпике для эскалации overdue курсов и в scope=manager
  фильтрах (доп. расширение позже).

Идемпотентно: используем IF NOT EXISTS / DO $$ блоки PostgreSQL и явный
advisory-lock seed-key 728_274_140 (Epic 14, 0xE14 ≈ 3604, но используем
семейство _SEED_LOCK_*) для последовательности при concurrent api-rolling.

Revision ID: 0035_departments  (15 chars ≤32 ✓)
Revises: 0034_saved_filter_order
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0035_departments"
down_revision: Union[str, None] = "0034_saved_filter_order"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# Уникальный для эпика 14 seed-key (для расширения departments — defensive lock,
# хотя env.py уже даёт глобальный advisory-lock на миграцию).
_SEED_LOCK_EPIC_14 = 728_274_140


def upgrade() -> None:
    conn = op.get_bind()
    # Defensive lock: даже если env.py уже взял глобальный lock, отдельный
    # семантический key для этой миграции улучшает диагностику в pg_locks.
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_14},
    )

    # 1) `departments`: добавляем недостающие поля (table уже создана — миграция 0010).
    # Используем raw SQL с IF NOT EXISTS для идемпотентности: повторный запуск
    # на уже мигрированной БД не упадёт.
    op.execute(
        "ALTER TABLE departments "
        "ADD COLUMN IF NOT EXISTS parent_id INTEGER REFERENCES departments(id) "
        "ON DELETE SET NULL"
    )
    op.execute(
        "ALTER TABLE departments "
        "ADD COLUMN IF NOT EXISTS head_user_id INTEGER REFERENCES users(id) "
        "ON DELETE SET NULL"
    )
    op.execute(
        "ALTER TABLE departments "
        "ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE"
    )
    op.execute(
        "ALTER TABLE departments "
        "ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT now()"
    )
    op.execute(
        "ALTER TABLE departments "
        "ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now()"
    )

    # Индексы — для часто запрашиваемых WHERE (parent tree, fast lookup активных).
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_departments_parent_id "
        "ON departments(parent_id)"
    )
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_departments_is_active "
        "ON departments(is_active)"
    )

    # 2) `users`: добавляем `manager_id` (department_id уже был с миграции 0010).
    op.execute(
        "ALTER TABLE users "
        "ADD COLUMN IF NOT EXISTS manager_id INTEGER REFERENCES users(id) "
        "ON DELETE SET NULL"
    )

    # Индексы на users.department_id / users.manager_id — нужны для list-фильтрации
    # «команда отдела» и «мои подчинённые».
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_users_department_id "
        "ON users(department_id)"
    )
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_users_manager_id "
        "ON users(manager_id)"
    )


def downgrade() -> None:
    # Снимаем добавленное (обратимо). users.department_id оставляем — оно было
    # ДО этой миграции (с миграции 0010 seed_pipeline).
    op.execute("DROP INDEX IF EXISTS ix_users_manager_id")
    op.execute("DROP INDEX IF EXISTS ix_users_department_id")
    op.execute("ALTER TABLE users DROP COLUMN IF EXISTS manager_id")

    op.execute("DROP INDEX IF EXISTS ix_departments_is_active")
    op.execute("DROP INDEX IF EXISTS ix_departments_parent_id")
    op.execute("ALTER TABLE departments DROP COLUMN IF EXISTS updated_at")
    op.execute("ALTER TABLE departments DROP COLUMN IF EXISTS created_at")
    op.execute("ALTER TABLE departments DROP COLUMN IF EXISTS is_active")
    op.execute("ALTER TABLE departments DROP COLUMN IF EXISTS head_user_id")
    op.execute("ALTER TABLE departments DROP COLUMN IF EXISTS parent_id")
