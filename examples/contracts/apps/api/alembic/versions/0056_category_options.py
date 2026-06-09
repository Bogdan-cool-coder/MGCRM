"""Эпик 23 — Конструктор воронок: расширение client_categories.

Добавляем 2 колонки + 1 server_default update:

- description TEXT NULL
    Описание категории для UI карточки в конструкторе категорий.
    Юристы / РОПы пишут «Кого относим к этой категории».

- options_meta JSONB DEFAULT '{}'
    Расширенные структурные опции категории (объект, НЕ список — отдельно от
    legacy `options` колонки типа JSON list[str], которая хранит «перки/SLA»
    как массив строк и используется во фронте + backend pydantic-схеме).
    Новое поле даёт расширяемую структуру для будущих фич (например,
    `{"sla_hours": 4, "auto_owner_rule": "round_robin", "discount_pct": 10}`)
    БЕЗ break существующих consumers.

- color: ALTER server_default → '#172747' (был NULL).
    Существующие записи остаются как есть (ALTER DEFAULT не трогает данные).
    Новые INSERT'ы без явного `color` получат brand-primary цвет MACRO Global
    по умолчанию.

NB: ТЗ Эпика 23 упоминало «options JSONB DEFAULT '{}'» — но колонка `options`
уже существует с типом JSON (list[str]) и используется во фронте/Pydantic.
Менять её тип = break существующих API contracts + утечка данных. Поэтому
заводим параллельную JSONB-колонку `options_meta` для новых расширений.
Legacy `options` остаётся для обратной совместимости.

Defensive advisory-lock seed-key 57_992 (0xE288 — Epic 23 Category Options).
DDL-only, без seed-данных.

Revision ID: 0056_category_options  (22 chars ≤32 ✓)
Revises: 0055_stage_extensions
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0056_category_options"
down_revision: Union[str, None] = "0055_stage_extensions"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE288 = 57_992 — Epic 23 Category Options seed-key.
_SEED_LOCK_EPIC_23_CAT = 57_992


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_23_CAT},
    )

    # description — текстовое описание категории.
    op.add_column(
        "client_categories",
        sa.Column("description", sa.Text(), nullable=True),
    )
    # options_meta — JSONB объект для расширенных опций (отдельно от legacy
    # `options` JSON list[str], который хранит «перки/SLA» строками).
    op.add_column(
        "client_categories",
        sa.Column(
            "options_meta",
            JSONB(),
            nullable=False,
            server_default=sa.text("'{}'::jsonb"),
        ),
    )
    # ALTER server_default color → brand-primary MACRO Global.
    # Существующие NULL'ы не трогаем (ALTER DEFAULT не пишет в строки).
    op.alter_column(
        "client_categories",
        "color",
        server_default=sa.text("'#172747'"),
    )


def downgrade() -> None:
    op.alter_column(
        "client_categories",
        "color",
        server_default=None,
    )
    op.drop_column("client_categories", "options_meta")
    op.drop_column("client_categories", "description")
