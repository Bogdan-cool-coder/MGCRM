"""Финансы Ф2: доввод fin_permission-строк для новых capability (реестр/согласование/заявки).

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist).

Контекст: миграции 0085/0090 засеяли fin_permission по ROLE_PERMISSIONS на момент их
применения. Ф2 добавила capability create_request/fulfill_request/manage_registry/
approve/manage_approval_scenarios + дефолты ролей. На уже-применённых БД этих строк нет —
`fin_can` отдаёт их через role_default-fallback, но реальных строк в матрице нет (UI прав
их не покажет). Эта миграция повторяет insert-missing по ТЕКУЩЕМУ ROLE_PERMISSIONS (single
source = seed_data) → доводит fin_permission до канона. Идемпотентно, под advisory-lock.

downgrade: no-op (безопасный доввод дефолтов; снос прав — ручная операция через API).

Revision ID: 0092_fin_phase2_perms
Revises: 0091_fin_phase2
Create Date: 2026-06-03
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op
from app.services.finance.seed_data import ROLE_PERMISSIONS

revision: str = "0092_fin_phase2_perms"
down_revision: str | None = "0091_fin_phase2"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ. Занятые: 91_001..91_007. Свободен 91_008.
_LOCK_FIN_PHASE2_PERMS = 91_008


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_FIN_PHASE2_PERMS}
    )

    for role, caps in ROLE_PERMISSIONS.items():
        for capability, allowed in caps.items():
            conn.execute(
                sa.text(
                    """
                    INSERT INTO fin_permission
                        (role, user_id, legal_entity_id, capability, allowed)
                    SELECT CAST(:role AS varchar), NULL, NULL,
                           CAST(:cap AS varchar), :allowed
                    WHERE NOT EXISTS (
                        SELECT 1 FROM fin_permission
                        WHERE role = CAST(:role AS varchar) AND user_id IS NULL
                          AND legal_entity_id IS NULL
                          AND capability = CAST(:cap AS varchar)
                    )
                    """
                ),
                {"role": role, "cap": capability, "allowed": allowed},
            )


def downgrade() -> None:
    # Безопасный доввод дефолтов — откат не сносит права (иначе сломаем доступ).
    pass
