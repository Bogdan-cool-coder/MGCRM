"""Эпик 24 hotfix (июнь 2026): activities.target_type / target_id → nullable.

Эпик 24 (Tasks v2 redesign) разрешает standalone-задачи без привязки к
CRM-сущности (личные задачи). До этого момента (target_type, target_id) в БД
были NOT NULL, что давало 422 на POST /api/activities без target. Делаем оба
столбца nullable. Композитный индекс (target_type, target_id) остаётся —
NULLs в B-tree допустимы, performance не страдает.

Существующие записи остаются как есть (NOT NULL → nullable — безопасное
расширение домена, downgrade требует, чтобы NULL-строки сначала ушли).

Revision ID: 0068_activity_target_nullable
Revises: 0067_merge_m3w2
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op


revision: str = "0068_activity_target_nullable"
down_revision: Union[str, None] = "0067_merge_m3w2"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # Никаких seed'ов — advisory-lock не требуется (структурный ALTER).
    op.alter_column(
        "activities",
        "target_type",
        existing_type=sa.String(length=32),
        nullable=True,
    )
    op.alter_column(
        "activities",
        "target_id",
        existing_type=sa.Integer(),
        nullable=True,
    )


def downgrade() -> None:
    # Перед откатом standalone-задачи должны быть удалены или дозаполнены
    # (NULL → NOT NULL без default нельзя). Откат вручную: DELETE FROM activities
    # WHERE target_type IS NULL OR target_id IS NULL — до alembic downgrade.
    op.alter_column(
        "activities",
        "target_id",
        existing_type=sa.Integer(),
        nullable=False,
    )
    op.alter_column(
        "activities",
        "target_type",
        existing_type=sa.String(length=32),
        nullable=False,
    )
