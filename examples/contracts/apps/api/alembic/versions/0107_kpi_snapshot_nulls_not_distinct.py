"""C2 — fix RegistryKpiSnapshot idempotency: uq_kpi_snapshot NULLS NOT DISTINCT.

Проблема (audit C2): `uq_kpi_snapshot(snapshot_date, platform_id, region_id)` — обычный
UNIQUE, а в Postgres NULL'ы считаются РАЗЛИЧНЫМИ. Поэтому общий срез реестра
`(date, NULL, NULL)` и per-platform-bucket'ы с region_id=NULL НЕ конфликтуют сами с
собой → `snapshot_registry_kpis(force=True)` (повторный прогон за ту же дату) задваивает
эти строки: `ON CONFLICT (constraint=uq_kpi_snapshot)` не срабатывает на NULL-ключах.

Фикс: пересоздаём constraint с `NULLS NOT DISTINCT` (PG15+, у нас PG16) — теперь
`(date, NULL, NULL)` уникален, ON CONFLICT DO UPDATE отрабатывает, повторный force —
честный upsert, без дублей.

Перед пересозданием дедуплицируем уже накопленные дубли (если были force-прогоны до
фикса): оставляем строку с max(id) на каждый логический ключ, остальные удаляем.

Идемпотентно: DROP CONSTRAINT IF EXISTS перед ADD. Advisory-lock 97_110
(диапазон 97_1xx свободен; 97_010 занят 0106).

Revision ID: 0107_kpi_nulls_nd
Revises: 0106_fk_ondelete
Create Date: 2026-06-05
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0107_kpi_nulls_nd"
down_revision: str | None = "0106_fk_ondelete"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Advisory-lock ключ (диапазон 97_1xx свободен; 97_010 занят 0106).
_LOCK_KPI_NULLS_ND = 97_110


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_KPI_NULLS_ND})

    # 1) Дедуп: оставить max(id) на каждый (snapshot_date, platform_id, region_id),
    #    трактуя NULL как один и тот же ключ (IS NOT DISTINCT FROM). Удаляем «старые»
    #    дубликаты, иначе ADD CONSTRAINT NULLS NOT DISTINCT упадёт на существующих парах.
    conn.execute(
        sa.text(
            """
            DELETE FROM registry_kpi_snapshots a
            USING registry_kpi_snapshots b
            WHERE a.id < b.id
              AND a.snapshot_date = b.snapshot_date
              AND a.platform_id IS NOT DISTINCT FROM b.platform_id
              AND a.region_id   IS NOT DISTINCT FROM b.region_id
            """
        )
    )

    # 2) Пересоздать constraint с NULLS NOT DISTINCT.
    op.drop_constraint("uq_kpi_snapshot", "registry_kpi_snapshots", type_="unique")
    conn.execute(
        sa.text(
            """
            ALTER TABLE registry_kpi_snapshots
            ADD CONSTRAINT uq_kpi_snapshot
            UNIQUE NULLS NOT DISTINCT (snapshot_date, platform_id, region_id)
            """
        )
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_KPI_NULLS_ND})

    op.drop_constraint("uq_kpi_snapshot", "registry_kpi_snapshots", type_="unique")
    op.create_unique_constraint(
        "uq_kpi_snapshot",
        "registry_kpi_snapshots",
        ["snapshot_date", "platform_id", "region_id"],
    )
