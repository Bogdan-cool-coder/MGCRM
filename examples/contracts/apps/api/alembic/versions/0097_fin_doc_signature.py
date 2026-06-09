"""Финансы Ф5 / Фаза A: подпись финансовых документов (инвойс/акт).

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist).

Добавляет approval-based «подписание» к инвойсу и акту:
  - fin_invoice.signed_by_user_id (FK users, SET NULL), fin_invoice.signed_at
  - fin_act.signed_by_user_id     (FK users, SET NULL)
    (fin_act.signed_at уже существует из 0094 — не дублируем)

Подпись = действие sign проставляет signed_by/signed_at; PDF перегенерируется
с блоком подписи (см. services/finance/doc_render.py). Документ становится
иммутабельным от правки. Полный OnlyOffice e-sign — отложен (под-фаза).

Идемпотентно: ADD COLUMN IF NOT EXISTS под advisory-lock 91_013. Additive, на
проде fin_invoice/fin_act могут быть пусты (Ф5 в работе) — данные не трогаем.
downgrade — DROP COLUMN IF EXISTS (signed_at у акта НЕ дропаем — он из 0094).

Revision ID: 0097_fin_doc_signature
Revises: 0096_fin_je_accrual_uniq
Create Date: 2026-06-03
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op

revision: str = "0097_fin_doc_signature"
down_revision: str | None = "0096_fin_je_accrual_uniq"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ. Занятые fin-ключи 91_001..91_012; свободен 91_013.
_LOCK_FIN_DOC_SIGNATURE = 91_013


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FIN_DOC_SIGNATURE})
    conn.execute(
        sa.text(
            "ALTER TABLE fin_invoice "
            "ADD COLUMN IF NOT EXISTS signed_by_user_id INTEGER "
            "REFERENCES users(id) ON DELETE SET NULL"
        )
    )
    conn.execute(
        sa.text(
            "ALTER TABLE fin_invoice "
            "ADD COLUMN IF NOT EXISTS signed_at TIMESTAMPTZ"
        )
    )
    conn.execute(
        sa.text(
            "ALTER TABLE fin_act "
            "ADD COLUMN IF NOT EXISTS signed_by_user_id INTEGER "
            "REFERENCES users(id) ON DELETE SET NULL"
        )
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FIN_DOC_SIGNATURE})
    conn.execute(sa.text("ALTER TABLE fin_act DROP COLUMN IF EXISTS signed_by_user_id"))
    conn.execute(sa.text("ALTER TABLE fin_invoice DROP COLUMN IF EXISTS signed_at"))
    conn.execute(sa.text("ALTER TABLE fin_invoice DROP COLUMN IF EXISTS signed_by_user_id"))
