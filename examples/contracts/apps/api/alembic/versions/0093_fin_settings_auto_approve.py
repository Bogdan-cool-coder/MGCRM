"""Финансы Ф2: флаг fin_settings.auto_approve_without_scenario (байпас согласования fix).

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist).

Контекст (CRITICAL #1 — байпас согласования): раньше submit заявки/реестра БЕЗ
подходящего сценария авто-апрувился (расход проводился без единого голоса). Теперь
по умолчанию это запрещено (submit → 422), а явный opt-out владельца включается этим
флагом. server_default='false' = безопасно: существующие БД получают строгий режим.

Идемпотентно: ADD COLUMN IF NOT EXISTS под advisory-lock 91_009 (повторный прогон —
no-op). downgrade удаляет колонку (DROP COLUMN IF EXISTS).

Revision ID: 0093_fin_settings_auto_approve
Revises: 0092_fin_phase2_perms
Create Date: 2026-06-03
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op

revision: str = "0093_fin_settings_auto_approve"
down_revision: str | None = "0092_fin_phase2_perms"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Уникальный advisory-lock ключ. Занятые fin-ключи: 91_001..91_008. Свободен 91_009.
_LOCK_FIN_SETTINGS_AUTO_APPROVE = 91_009


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _LOCK_FIN_SETTINGS_AUTO_APPROVE},
    )
    conn.execute(
        sa.text(
            "ALTER TABLE fin_settings "
            "ADD COLUMN IF NOT EXISTS auto_approve_without_scenario "
            "BOOLEAN NOT NULL DEFAULT false"
        )
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _LOCK_FIN_SETTINGS_AUTO_APPROVE},
    )
    conn.execute(
        sa.text(
            "ALTER TABLE fin_settings DROP COLUMN IF EXISTS auto_approve_without_scenario"
        )
    )
