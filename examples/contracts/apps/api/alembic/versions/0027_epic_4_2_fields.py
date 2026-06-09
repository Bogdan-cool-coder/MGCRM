"""Эпик 4.2: чистка lead-конверсии + минимальные amoCRM/Pipedrive/HubSpot
расширения для Lead/Deal.

Добавляется:
1. leads.converted_deal_id (INT NULL → deals.id ON DELETE SET NULL) — прямая
   связь Lead → Deal после конверсии. Сейчас траблшут идёт через
   converted_to_counterparty_id + JOIN deals.counterparty_id (медленно, теряем
   связь при пересоздании КА). Индекс — для аналитики «сколько лидов
   сконвертилось в deal X».
2. leads.score (INT NULL, CHECK 0..100) — HubSpot lead scoring. Заполняется
   вручную пока (Эпик 4.2 не покрывает scoring engine). Индекс — для фильтра
   «hot leads с score >= 80» на /admin/leads.
3. deals.lost_reason (TEXT NULL) — amoCRM-стандарт: при переходе в этап «Проигрыш»
   просим менеджера записать причину. Свободный текст (не enum) для совместимости
   с импортом из AmoCRM. Без индекса — фильтр редкий, full-text позже.
4. deals.expected_close_date (DATE NULL) — Pipedrive-стандарт: целевая дата
   закрытия. Используется в forecast (Эпик 6 уже частично) и в `idle_in_stage_days`
   для приоритизации. Индекс — для forecast-запросов «сделки с close_date
   ∈ [today, today+30]».

DDL-only (без seed-данных) — advisory-lock не нужен. ADD COLUMN с NULL — безопасен
на больших таблицах в PG 11+ (без table rewrite).

Revision ID: 0027_epic_4_2_fields
Revises: 0026_card2_extensions
Create Date: 2026-05-31
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0027_epic_4_2_fields"
down_revision: Union[str, None] = "0026_card2_extensions"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ---- 1. leads.converted_deal_id ----
    op.add_column(
        "leads",
        sa.Column(
            "converted_deal_id",
            sa.Integer(),
            sa.ForeignKey("deals.id", ondelete="SET NULL"),
            nullable=True,
        ),
    )
    op.create_index(
        "ix_leads_converted_deal_id", "leads", ["converted_deal_id"],
    )

    # ---- 2. leads.score (HubSpot scoring, 0..100) ----
    op.add_column(
        "leads",
        sa.Column(
            "score",
            sa.Integer(),
            nullable=True,
        ),
    )
    # CHECK: 0..100. Имя ограничения зафиксировано для downgrade'а.
    op.create_check_constraint(
        "ck_leads_score_range",
        "leads",
        "score IS NULL OR (score >= 0 AND score <= 100)",
    )
    op.create_index("ix_leads_score", "leads", ["score"])

    # ---- 3. deals.lost_reason (amoCRM) ----
    op.add_column(
        "deals",
        sa.Column("lost_reason", sa.Text(), nullable=True),
    )

    # ---- 4. deals.expected_close_date (Pipedrive) ----
    op.add_column(
        "deals",
        sa.Column("expected_close_date", sa.Date(), nullable=True),
    )
    op.create_index(
        "ix_deals_expected_close_date", "deals", ["expected_close_date"],
    )


def downgrade() -> None:
    op.drop_index("ix_deals_expected_close_date", table_name="deals")
    op.drop_column("deals", "expected_close_date")

    op.drop_column("deals", "lost_reason")

    op.drop_index("ix_leads_score", table_name="leads")
    op.drop_constraint("ck_leads_score_range", "leads", type_="check")
    op.drop_column("leads", "score")

    op.drop_index("ix_leads_converted_deal_id", table_name="leads")
    op.drop_column("leads", "converted_deal_id")
