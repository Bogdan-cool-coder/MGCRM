"""CONTACTS 2.0 Ф3-B: add UNIQUE(company_id, platform_id, region_id)
on client_subscriptions.

counterparty_id unique остаётся — оба id зеркалируются консистентно.
NULL company_id допускаются (Postgres UNIQUE пропускает NULL).

Advisory-lock seed-key 72_001.

Revision ID: 0072_sub_company_unique  (≤32 ✓)
Revises: 0071_gcal_sync_dir_len
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0072_sub_company_unique"
down_revision: Union[str, None] = "0071_gcal_sync_dir_len"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_LOCK_KEY = 72_001


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_KEY})

    # Проверяем дубли company_id+platform_id+region_id (где company_id NOT NULL),
    # прежде чем добавлять UNIQUE. Если есть — логируем, но не падаем:
    # дубли с company_id NOT NULL означали бы ошибку предыдущей миграции 0070.
    # На практике client_subscriptions имел UNIQUE на counterparty, поэтому дублей
    # быть не должно (каждая пара cp→company зеркалируется 1:1).
    dups = conn.execute(
        sa.text(
            "SELECT company_id, platform_id, region_id, COUNT(*) AS cnt "
            "FROM client_subscriptions "
            "WHERE company_id IS NOT NULL "
            "GROUP BY company_id, platform_id, region_id "
            "HAVING COUNT(*) > 1"
        )
    ).fetchall()
    if dups:
        # Если дубли есть — пропускаем добавление constraint, чтобы не уронить
        # прод. Данные нужно почистить вручную, затем повторно применить.
        import warnings
        warnings.warn(
            f"Skipping uq_sub_company_platform_region: found {len(dups)} duplicate "
            f"(company_id, platform_id, region_id) groups in client_subscriptions. "
            f"Fix data manually then re-run migration."
        )
        return

    # Идемпотентность: не добавляем если constraint уже существует.
    exists = conn.execute(
        sa.text(
            "SELECT 1 FROM pg_constraint WHERE conname = 'uq_sub_company_platform_region'"
        )
    ).scalar()
    if not exists:
        op.create_unique_constraint(
            "uq_sub_company_platform_region",
            "client_subscriptions",
            ["company_id", "platform_id", "region_id"],
        )


def downgrade() -> None:
    conn = op.get_bind()
    exists = conn.execute(
        sa.text(
            "SELECT 1 FROM pg_constraint WHERE conname = 'uq_sub_company_platform_region'"
        )
    ).scalar()
    if exists:
        op.drop_constraint(
            "uq_sub_company_platform_region",
            "client_subscriptions",
            type_="unique",
        )
