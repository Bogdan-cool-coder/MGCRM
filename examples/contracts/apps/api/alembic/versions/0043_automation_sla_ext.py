"""Эпик 19 — Automation 2 (SLA + Dry-run + Branch): extend pipeline_automations.

Добавляем 2 колонки в `pipeline_automations` для поддержки SLA-семантики:

- is_sla BOOLEAN DEFAULT false NOT NULL
    Флаг отделяет SLA-правила от обычных автоматизаций. Используется в UI
    (отдельная вкладка «SLA» в /admin/automations) и в дефолтном сидере
    SLA-rules: фильтр `is_sla=True` гарантирует, что обычная автоматизация
    не считается SLA случайно. На уровне executor'а — никакой различий
    (правила исполняются одинаково), это только классификатор.

- escalation_chain JSONB NULL
    Цепочка эскалаций — список словарей вида:
        [
            {"after_hours": 48, "action_kind": "tg_notify",
             "action_config": {"to": "manager", "text": "..."}},
            {"after_hours": 168, "action_kind": "tg_notify",
             "action_config": {"to": "director", "text": "..."}}
        ]
    После основного срабатывания SLA-автоматизации executor ставит
    отдельные «эскалационные тики» на (started_at + after_hours).
    NULL = эскалация не настроена (только основной triggern).
    Структура хранится в JSONB чтобы можно было фильтровать по ключам
    в будущем (WHERE escalation_chain @> '[{"action_kind":"tg_notify"}]').

Защита от падений / идемпотентность:
- ALTER TABLE ADD COLUMN ... IF NOT EXISTS НЕ нужен на чистой миграции
  (alembic_version блокирует параллельные старты row-lock'ом). Но мы
  делаем простой ALTER, который сам по себе идемпотентен при единственном
  применении — Alembic не даст запуститься дважды.
- DDL-only миграция (никаких seed-данных) — `pg_advisory_xact_lock`
  не нужен. alembic_version row-lock защищает от scale=2 race.

Revision ID: 0043_automation_sla_ext  (24 chars ≤32 ✓)
Revises: 0042_notifications
Create Date: 2026-06-02
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0043_automation_sla_ext"
down_revision: Union[str, None] = "0042_notifications"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # is_sla — флаг отделяет SLA-правила от обычных автоматизаций.
    # NOT NULL DEFAULT false — существующие строки получают false автоматически.
    op.add_column(
        "pipeline_automations",
        sa.Column(
            "is_sla",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("false"),
        ),
    )
    # escalation_chain — список словарей с шагами эскалации (см. docstring).
    # NULL = нет эскалации (поведение по-умолчанию для обычных автоматизаций).
    op.add_column(
        "pipeline_automations",
        sa.Column(
            "escalation_chain",
            JSONB(),
            nullable=True,
        ),
    )
    # Индекс по is_sla для UI-вкладки «SLA» (WHERE is_sla = true).
    # Опционально — таблица маленькая, full scan приемлем; индекс ускоряет на
    # больших инсталляциях где сотни автоматизаций.
    op.create_index(
        "ix_pipeline_automations_is_sla",
        "pipeline_automations",
        ["is_sla"],
    )


def downgrade() -> None:
    op.drop_index(
        "ix_pipeline_automations_is_sla",
        table_name="pipeline_automations",
    )
    op.drop_column("pipeline_automations", "escalation_chain")
    op.drop_column("pipeline_automations", "is_sla")
