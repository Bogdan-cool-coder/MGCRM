"""Финансы Ф1: доввод недостающих fin_permission-строк (материализация дефолтов).

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist).

Контекст: миграция 0085 засеяла fin_permission по ROLE_PERMISSIONS на момент её
применения. Позже в seed_data добавлена capability `view_all_operations` (W5 — own-only
листинг для manager) и возможны иные правки дефолтов. На УЖЕ применённых БД эти строки
отсутствуют — `fin_can` отдаёт их через role_default-fallback, но реальных строк в
fin_permission нет (матрица прав UI в Ф1 их не покажет, экспорт/аудит прав неполон).

Эта миграция повторяет insert-missing по ТЕКУЩЕМУ ROLE_PERMISSIONS (single source =
app.services.finance.seed_data) → доводит fin_permission до канонического состояния.
Идемпотентно (insert-missing, НЕ truncate-insert), под advisory-lock → повторный прогон
и scale=2 = no-op. На свежей БД, где 0085 уже всё засеял, — тоже no-op.

downgrade: no-op (не удаляем права — это безопасный доввод дефолтов; снос правил —
ручная операция через API/матрицу прав, не миграцией).

Revision ID: 0090_fin_permission_reseed
Revises: 0089_fin_op_direction_ck
Create Date: 2026-06-03
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op
from app.services.finance.seed_data import ROLE_PERMISSIONS

revision: str = "0090_fin_permission_reseed"
down_revision: str | None = "0089_fin_op_direction_ck"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ этой миграции (конвенция: один ключ на миграцию).
#: НЕ переиспользуем 91_001 от 0085 — ключи в цепочке должны быть уникальны (миграции и
#: так сериализуются alembic'ом в одном xact; общий ключ создаёт ложную коллизию при scale=2).
#: Занятые fin-ключи: 91_001(0085) 91_002(0086) 91_004(0088) 91_005(0089). Свободен 91_006.
_SEED_LOCK_FIN_REFERENCE = 91_006


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _SEED_LOCK_FIN_REFERENCE}
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
