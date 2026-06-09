"""sequences + sequence_runs (Эпик 4.1): многошаговые «cadences».

Эпик 4.1 — расширение PipelineAutomation Engine: добавление действия
`start_sequence`, которое запускает многошаговую последовательность для цели
(deal / lead / subscription). Шаги выполняются по расписанию через cron-сканер.

Таблицы:
- sequences          — шаблоны последовательностей (steps_json: list of
                       {kind, config, delay_days}). Создаются вручную в админке.
- sequence_runs      — конкретные запуски: указывает на target_type+target_id,
                       курсор current_step_index, статус, next_step_at для
                       cron-сканера (jobs/automation_cron.py).

Индексы:
- sequences.(is_active) — фильтр списка.
- sequence_runs.(sequence_id) — история запусков шаблона.
- (target_type, target_id) — все sequences по сущности (для таймлайна).
- (status, next_step_at) — композитный, главный путь cron сканера:
  WHERE status IN ('pending','running') AND next_step_at <= now()
  ORDER BY next_step_at.

Миграция аддитивная, без seed-данных — advisory-lock НЕ нужен. Sequence-шаблоны
создаются вручную (нет дефолтного «sample sequence», который пришлось бы пихать
через seed). Если потребуется — добавим в отдельной миграции с advisory-lock.

Revision ID: 0025_sequences
Revises: 0024_renewal_bulk
Create Date: 2026-05-31
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0025_sequences"
down_revision: Union[str, None] = "0024_renewal_bulk"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ---- sequences ----
    op.create_table(
        "sequences",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("name", sa.String(length=255), nullable=False),
        sa.Column("description", sa.Text(), nullable=True),
        # Шаги: [{kind, config, delay_days}, ...]. Хранится одним JSON, без отдельной
        # дочерней таблицы — порядок и состав часто меняются целиком.
        sa.Column(
            "steps_json",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'[]'::json"),
        ),
        sa.Column(
            "is_active",
            sa.Boolean(),
            nullable=False,
            server_default=sa.true(),
        ),
        sa.Column(
            "created_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
    )
    op.create_index(
        "ix_sequences_is_active",
        "sequences",
        ["is_active"],
    )

    # ---- sequence_runs ----
    op.create_table(
        "sequence_runs",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "sequence_id",
            sa.Integer(),
            sa.ForeignKey("sequences.id", ondelete="CASCADE"),
            nullable=False,
        ),
        # 'deal' | 'lead' | 'subscription'
        sa.Column("target_type", sa.String(length=32), nullable=False),
        sa.Column("target_id", sa.Integer(), nullable=False),
        # Индекс СЛЕДУЮЩЕГО шага к выполнению (0 = ещё не начали)
        sa.Column(
            "current_step_index",
            sa.Integer(),
            nullable=False,
            server_default="0",
        ),
        # 'pending' | 'running' | 'completed' | 'failed' | 'cancelled'
        sa.Column(
            "status",
            sa.String(length=16),
            nullable=False,
            server_default="pending",
        ),
        sa.Column(
            "started_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column("next_step_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("result_json", sa.JSON(), nullable=True),
    )
    op.create_index(
        "ix_sequence_runs_sequence_id",
        "sequence_runs",
        ["sequence_id"],
    )
    op.create_index(
        "ix_sequence_runs_target",
        "sequence_runs",
        ["target_type", "target_id"],
    )
    op.create_index(
        "ix_sequence_runs_status_next",
        "sequence_runs",
        ["status", "next_step_at"],
    )


def downgrade() -> None:
    op.drop_index("ix_sequence_runs_status_next", table_name="sequence_runs")
    op.drop_index("ix_sequence_runs_target", table_name="sequence_runs")
    op.drop_index("ix_sequence_runs_sequence_id", table_name="sequence_runs")
    op.drop_table("sequence_runs")

    op.drop_index("ix_sequences_is_active", table_name="sequences")
    op.drop_table("sequences")
