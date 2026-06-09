"""Финансы Ф0 (миграция A): роли accountant + cfo в enum user_role.

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist). Отдельная миграция — ALTER TYPE ADD VALUE
нельзя использовать в той же транзакции, где значение применяется (PG12+ ограничение),
поэтому значения добавляются ОТДЕЛЬНО, до сид-миграции прав (0085), которая их использует.

DB-тип enum называется `user_role` (см. 0001_initial). PG16: `ADD VALUE IF NOT EXISTS`
идемпотентен. Выполняем в autocommit-блоке (ALTER TYPE ... ADD VALUE не может идти внутри
обычного транзакционного блока в одном вызове с другими DDL на части версий PG — autocommit
снимает это ограничение и делает повторный прогон scale=2 безопасным).

downgrade: PostgreSQL НЕ поддерживает удаление значения из enum штатно → no-op
(значения accountant/cfo остаются; это безопасно, существующие строки не затрагиваются).

Revision ID: 0084_fin_roles  (13 chars ≤32 ✓)
Revises: 0083_cleanup_phantoms
Create Date: 2026-06-03
"""
from __future__ import annotations

from typing import Sequence, Union

from alembic import op

revision: str = "0084_fin_roles"
down_revision: Union[str, None] = "0083_cleanup_phantoms"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ADD VALUE IF NOT EXISTS — идемпотентно (PG12+). autocommit_block снимает
    # ограничение «нельзя в транзакционном блоке» на разных версиях PG.
    with op.get_context().autocommit_block():
        op.execute("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'accountant'")
        op.execute("ALTER TYPE user_role ADD VALUE IF NOT EXISTS 'cfo'")


def downgrade() -> None:
    # PostgreSQL не умеет удалять значение из enum без пересоздания типа и
    # перезаписи всех ссылающихся колонок — небезопасно и не нужно. No-op.
    pass
