"""Wave 2a: add 'needs_rework' value to contract_status enum

«На доработке» — новый подстатус группы «На согласовании» (согласователь вернул
договор автору на доработку). SAFE-дизайн: новой колонки НЕ добавляем, существующие
строки НЕ ремапим — добавляем только одно значение в native PG enum. Группировка
статусов в первичные группы (Архив/Черновик/На согласовании/Согласован) — чистая
логика в app/services/contract_status.py, в БД её нет.

Идемпотентно: ADD VALUE IF NOT EXISTS. Значение НЕ используется в этой же
миграции (только декларируется), поэтому ADD VALUE в транзакции PG16 безопасен
(тот же паттерн что и 0010, где добавлялось 'signed').

Revision ID: 0100_status_rework
Revises: 0099_fin_phase4_accrual
Create Date: 2026-06-04
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0100_status_rework"
down_revision: str | None = "0099_fin_phase4_accrual"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ этой миграции (конвенция: один ключ на миграцию).
#: Диапазон 92_xxx свободен (91_xxx занят finance-фазами).
_LOCK_STATUS_REWORK = 92_001


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_STATUS_REWORK}
    )
    # Идемпотентно. needs_rework вставляется ПОСЛЕ in_review (логический порядок
    # «на согласовании» → «на доработке»), до approved. На повторном прогоне или
    # на уже мигрированной проде — no-op за счёт IF NOT EXISTS.
    op.execute(
        "ALTER TYPE contract_status "
        "ADD VALUE IF NOT EXISTS 'needs_rework' AFTER 'in_review'"
    )


def downgrade() -> None:
    # PostgreSQL не поддерживает DROP VALUE у enum — значение остаётся.
    # Откат безопасен: если ни одна строка не в статусе needs_rework, лишнее
    # значение enum ни на что не влияет. Сознательный no-op.
    pass
