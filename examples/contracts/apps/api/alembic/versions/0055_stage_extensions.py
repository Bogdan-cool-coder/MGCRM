"""Эпик 23 — Конструктор воронок AmoCRM-style: расширение pipeline_stages.

Добавляем 3 колонки в `pipeline_stages` для визуального конструктора воронок:

- description TEXT NULL
    Описание этапа для UI карточки. Юристы / РОПы пишут «Что должно произойти
    на этом этапе» — рендерится в правой панели визуального конструктора.

- sla_hours INT NULL
    SLA в часах для авто-мониторинга через Эпик 19 (Automation 2.0). Если
    значение задано — сделка/лид зависает на этом этапе > sla_hours часов →
    создаётся sla_breach нотификация (см. automation_executor:
    _create_sla_breach_notification). NULL = SLA не настроен.
    Используется в idle_in_stage_scanner: если у этапа есть sla_hours,
    SLA-автоматизация на нём может работать с trigger_config
    `{"idle_in_stage_hours": sla_hours}`.

- default_task_category_id INT NULL
    Категория задач (TaskCategory), которые автоматически создаются при
    входе сделки/лида в этот этап. NULL = не создавать задачи автоматически.
    FK ДОБАВЛЯЕТСЯ В МИГРАЦИИ ЭПИКА 24 (когда появится таблица
    `task_categories`). Сейчас просто nullable Integer без FK constraint
    — это валидно и не блокирует Эпик 23.

Defensive advisory-lock seed-key 57_991 (0xE287 — Epic 23 Pipeline Builder).
DDL-only, без seed-данных.

Revision ID: 0055_stage_extensions  (22 chars ≤32 ✓)
Revises: 0054_oauth_clients
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0055_stage_extensions"
down_revision: Union[str, None] = "0054_oauth_clients"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE287 = 57_991 — Epic 23 Pipeline Builder seed-key.
_SEED_LOCK_EPIC_23 = 57_991


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_23},
    )

    # description — описание этапа для UI визуального конструктора.
    op.add_column(
        "pipeline_stages",
        sa.Column("description", sa.Text(), nullable=True),
    )
    # sla_hours — SLA в часах. NULL = не настроен.
    op.add_column(
        "pipeline_stages",
        sa.Column("sla_hours", sa.Integer(), nullable=True),
    )
    # default_task_category_id — категория автоматически создаваемых задач при
    # входе в этап. FK на task_categories(id) будет добавлен в Эпике 24.
    # Сейчас — просто nullable Integer (FK не требуется на уровне БД, исп. в коде).
    op.add_column(
        "pipeline_stages",
        sa.Column("default_task_category_id", sa.Integer(), nullable=True),
    )


def downgrade() -> None:
    op.drop_column("pipeline_stages", "default_task_category_id")
    op.drop_column("pipeline_stages", "sla_hours")
    op.drop_column("pipeline_stages", "description")
