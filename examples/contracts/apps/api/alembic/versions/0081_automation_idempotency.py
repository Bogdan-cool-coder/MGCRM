"""Automation idempotency — trigger_event_ts + unique-index дедупа AutomationRun.

Зона PipelineAutomation Engine (Эпик 4). Закрывает CRITICAL-связку C1/C2/C3
код-аудита: на scale=2 cron-сканеры (idle/date) и inline on_enter_stage могли
дублировать побочные действия (TG/webhook/задачи), потому что дедуп опирался
только на временно́е окно `_was_recently_run` (racy) без транзакционной гарантии.

Содержит:

1. automation_runs.trigger_event_ts (TIMESTAMPTZ, nullable) — момент события,
   породившего run. Заполняется по триггеру:
   - on_enter_stage      → deal.stage_changed_at
   - idle_in_stage_days  → округлённое начало окна (now - window, floored to hour)
   - date_field_approaching → target_date - days
   - on_create           → target.created_at
   NULL = ручной/legacy run (без дедупа — manual execute/retry допускают повтор
   сознательно).

2. Частичный UNIQUE-индекс ux_automation_runs_idem
   (automation_id, target_type, target_id, trigger_event_ts) WHERE
   trigger_event_ts IS NOT NULL. Даёт транзакционную идемпотентность:
   INSERT ... ON CONFLICT DO NOTHING. Partial — чтобы NULL-строки (ручные)
   не конфликтовали (в PG NULL в UNIQUE и так distinct, но partial делает
   намерение явным и не раздувает индекс ручными прогонами).

Идемпотентность миграции: ADD COLUMN IF NOT EXISTS, CREATE INDEX IF NOT EXISTS.
Advisory-lock seed-key 74_006 (DDL-only, но держим единый паттерн с соседними
миграциями зоны — параллельный старт scale=2 безопасен и без него за счёт
alembic_version row-lock; lock тут — дешёвая страховка).

Revision ID: 0081_automation_idem   (20 chars ≤32 ✓)
Revises: 0080_cs_hotfixes
Create Date: 2026-06-03
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0081_automation_idem"
down_revision: Union[str, None] = "0080_cs_hotfixes"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_AUTOMATION_IDEM = 74_006


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_AUTOMATION_IDEM},
    )

    # 1. trigger_event_ts — момент события (для дедупа cron/inline).
    conn.execute(
        sa.text(
            "ALTER TABLE automation_runs "
            "ADD COLUMN IF NOT EXISTS trigger_event_ts TIMESTAMPTZ"
        )
    )

    # 2. Частичный UNIQUE-индекс идемпотентности. Только для строк с
    #    trigger_event_ts IS NOT NULL — ручные прогоны (NULL) не дедупим.
    conn.execute(
        sa.text(
            "CREATE UNIQUE INDEX IF NOT EXISTS ux_automation_runs_idem "
            "ON automation_runs "
            "(automation_id, target_type, target_id, trigger_event_ts) "
            "WHERE trigger_event_ts IS NOT NULL"
        )
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_AUTOMATION_IDEM},
    )
    conn.execute(sa.text("DROP INDEX IF EXISTS ux_automation_runs_idem"))
    conn.execute(
        sa.text(
            "ALTER TABLE automation_runs DROP COLUMN IF EXISTS trigger_event_ts"
        )
    )
