"""Epic 14.2 — Company Management: рабочие графики, отпуска, производственный календарь.

Три таблицы:

1) work_schedules — расписание per scope (отдел или конкретный юзер).

   scope_type VARCHAR(16) — 'department' | 'user'.
   scope_id INT — department.id или users.id (без FK — динамический lookup
     в сервисе по scope_type; делать polymorphic FK в PG неудобно).

   day_of_week INT — 0=Monday..6=Sunday (ISO 8601).

   is_working BOOLEAN — день рабочий или нет.
   start_time / end_time TIME — рабочие часы (NULL если is_working=false).
   meeting_slot_minutes INT DEFAULT 30 — длительность одного слота для
     /available-slots calculation.

   UNIQUE(scope_type, scope_id, day_of_week) — на один scope+день один ряд.

2) user_vacations — отпуска / больничные / отгулы / командировки.

   start_date / end_date DATE — границы (включительно).
   vacation_type VARCHAR(16) — 'vacation' | 'sick_leave' | 'day_off' |
     'business_trip'.
   substitute_user_id INT FK SET NULL — кто замещает на этот период.
     Берётся в priority перед users.substitute_user_id (см.
     services/work_schedule.get_active_substitute).
   approved_by_user_id / approved_at — заявка / approval flow:
     admin/director — auto-approve в endpoint'е, остальные ждут approve.

   CHECK (end_date >= start_date) — защита от опечаток.

3) production_calendar — производственный календарь по странам.

   country_code VARCHAR(2) — 'RU' | 'KZ' | 'UZ' | 'AE'.
   year INT, date DATE — год дублирует для idx_calendar_country_year
     (быстрый фильтр по году в UI).
   is_holiday BOOLEAN — выходной/праздник.
   is_short_day BOOLEAN — сокращённый рабочий день.
   name VARCHAR(128) — название праздника для UI ('Новый год', 'Наурыз').

   UNIQUE(country_code, date) — один день — одна запись.

   Используется services/production_calendar.add_working_days для расчёта
   «due_at + N рабочих дней» (учитывает выходные + праздники).

Defensive advisory-lock seed-key 57_995 (0xE28B — Epic 14.2 Schedule).
DDL-only; seed-данные production_calendar для 2026 будут заливаться отдельно
через services/production_calendar.seed_default (вызывается через
POST /api/admin/production-calendar/seed admin'ом).

Revision ID: 0062_schedule_calendar  (22 chars ≤32 ✓)
Revises: 0061_rights_transfer
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0062_schedule_calendar"
down_revision: Union[str, None] = "0061_rights_transfer"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE28B = 57_995 — Epic 14.2 Schedule seed-key.
_SEED_LOCK_EPIC_14_2_SCHEDULE = 57_995


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_14_2_SCHEDULE},
    )

    # ============ work_schedules ============
    op.create_table(
        "work_schedules",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("scope_type", sa.String(length=16), nullable=False),
        sa.Column("scope_id", sa.Integer(), nullable=False),
        sa.Column("day_of_week", sa.Integer(), nullable=False),
        sa.Column(
            "is_working",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("true"),
        ),
        sa.Column("start_time", sa.Time(), nullable=True),
        sa.Column("end_time", sa.Time(), nullable=True),
        sa.Column(
            "meeting_slot_minutes",
            sa.Integer(),
            nullable=False,
            server_default=sa.text("30"),
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
        sa.UniqueConstraint(
            "scope_type", "scope_id", "day_of_week",
            name="uq_schedule_scope_day",
        ),
        # CHECK day_of_week in 0..6 — защита от опечаток в API.
        sa.CheckConstraint(
            "day_of_week >= 0 AND day_of_week <= 6",
            name="ck_schedule_day_of_week",
        ),
        sa.CheckConstraint(
            "scope_type IN ('department', 'user')",
            name="ck_schedule_scope_type",
        ),
    )
    op.create_index(
        "idx_schedule_scope",
        "work_schedules",
        ["scope_type", "scope_id"],
    )

    # ============ user_vacations ============
    op.create_table(
        "user_vacations",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("start_date", sa.Date(), nullable=False),
        sa.Column("end_date", sa.Date(), nullable=False),
        sa.Column(
            "vacation_type",
            sa.String(length=16),
            nullable=False,
            server_default=sa.text("'vacation'"),
        ),
        sa.Column(
            "substitute_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("notes", sa.Text(), nullable=True),
        sa.Column(
            "approved_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "approved_at",
            sa.DateTime(timezone=True),
            nullable=True,
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.CheckConstraint(
            "end_date >= start_date",
            name="ck_vacation_date_range",
        ),
        sa.CheckConstraint(
            "vacation_type IN ('vacation', 'sick_leave', 'day_off', 'business_trip')",
            name="ck_vacation_type",
        ),
    )
    op.create_index(
        "idx_vacation_user_dates",
        "user_vacations",
        ["user_id", "start_date", "end_date"],
    )

    # ============ production_calendar ============
    op.create_table(
        "production_calendar",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("country_code", sa.String(length=2), nullable=False),
        sa.Column("year", sa.Integer(), nullable=False),
        sa.Column("date", sa.Date(), nullable=False),
        sa.Column(
            "is_holiday",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("true"),
        ),
        sa.Column(
            "is_short_day",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("false"),
        ),
        sa.Column("name", sa.String(length=128), nullable=True),
        sa.UniqueConstraint(
            "country_code", "date",
            name="uq_calendar_country_date",
        ),
    )
    op.create_index(
        "idx_calendar_country_year",
        "production_calendar",
        ["country_code", "year"],
    )


def downgrade() -> None:
    op.drop_index("idx_calendar_country_year", table_name="production_calendar")
    op.drop_table("production_calendar")
    op.drop_index("idx_vacation_user_dates", table_name="user_vacations")
    op.drop_table("user_vacations")
    op.drop_index("idx_schedule_scope", table_name="work_schedules")
    op.drop_table("work_schedules")
