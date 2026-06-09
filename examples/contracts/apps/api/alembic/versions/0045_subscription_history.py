"""Эпик 22 — Cohort Analytics: таблица истории смены этапов подписок.

Добавляет `subscription_stage_history` для точного когортного анализа retention.
Без этой таблицы когорта могла бы быть рассчитана только по текущему stage_code
(approximation). С историей — точный cohort retention по дате перехода в C0.

Таблица пишется application-level в registry router (PATCH /subscriptions/{id})
при каждом изменении lifecycle_stage_id.

Backfill: скрипт `apps/api/scripts/backfill_subscription_history.py` создаёт
одну начальную запись для каждой существующей подписки (to_stage_code = текущий
stage.code, changed_at = updated_at). Запускать вручную после деплоя миграции.
История для этих подписок будет неполной (только текущий stage известен), но
когортная аналитика начнёт накапливать точную историю с момента деплоя.

Индексы:
- idx_sub_history_sub(subscription_id, changed_at DESC) — главный: история
  конкретной подписки в хронологическом порядке (JOIN + ORDER BY).
- idx_sub_history_stage(to_stage_code, changed_at) — для когортной агрегации:
  «все подписки, перешедшие в C0 в период X» (WHERE to_stage='C0' AND changed_at>).

Advisory-lock seed-key: 0045 (уникальный, не пересекается с другими).
DDL-only миграция — seed-данных нет; advisory-lock не нужен для самой миграции,
но соблюдаем паттерн проекта для консистентности.

Revision ID: 0045_subscription_history  (27 chars ≤32 ✓)
Revises: 0043_automation_sla_ext
Create Date: 2026-06-02
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0045_subscription_history"
down_revision: Union[str, None] = "0043_automation_sla_ext"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "subscription_stage_history",
        sa.Column("id", sa.Integer(), primary_key=True, autoincrement=True),
        sa.Column(
            "subscription_id",
            sa.Integer(),
            sa.ForeignKey("client_subscriptions.id", ondelete="CASCADE"),
            nullable=False,
        ),
        # from_stage_code NULL означает «первая запись» или «неизвестный предыдущий этап»
        sa.Column("from_stage_code", sa.String(16), nullable=True),
        sa.Column("to_stage_code", sa.String(16), nullable=False),
        sa.Column(
            "changed_at",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.Column(
            "changed_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
    )

    # История конкретной подписки (JOIN + ORDER BY changed_at DESC)
    op.create_index(
        "idx_sub_history_sub",
        "subscription_stage_history",
        ["subscription_id", sa.text("changed_at DESC")],
    )

    # Когортная агрегация: «все переходы в C0 за период»
    op.create_index(
        "idx_sub_history_stage",
        "subscription_stage_history",
        ["to_stage_code", "changed_at"],
    )


def downgrade() -> None:
    op.drop_index("idx_sub_history_stage", table_name="subscription_stage_history")
    op.drop_index("idx_sub_history_sub", table_name="subscription_stage_history")
    op.drop_table("subscription_stage_history")
