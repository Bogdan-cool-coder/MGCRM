"""template categories + bindings + approval route by category (Эпик 3).

Документооборот 2.0: добавляем категории шаблонов и привязки + опциональную
привязку маршрутов согласования к категории документа.

Расширения `templates`:
- `category` String(32) nullable, index — 'sublicense_main' / 'addendum' /
  'notice' / 'act' / 'cancellation'. Существующие записи получают NULL,
  кроме шаблона `master_skeleton` — он мапится на 'sublicense_main' (это
  основной сублицензионный шаблон, по которому генерятся все договоры).
- `product_codes`, `country_codes`, `client_category_codes`, `department_ids`
  JSON not null default '[]' — привязки: при фильтрации на /contracts/new
  пустой список = «подходит ко всему» (wildcard).

Расширение `approval_routes`:
- `template_category` String(32) nullable — если задан, маршрут применяется
  только к шаблонам этой категории; null = общий маршрут (legacy fallback).

Миграция аддитивная (schema-only + UPDATE одной существующей записи по code),
advisory-lock НЕ нужен: новые колонки идемпотентны на уровне alembic, а UPDATE
по уникальному code тоже идемпотентен (повторный запуск перезатрёт тем же
значением). См. backend-specialist.md: «advisory-lock seed-key — только если
миграция сидит данные или создаёт уникальные значения по умолчанию».

Revision ID: 0021_tpl_categories
Revises: 0020_activities
Create Date: 2026-05-30
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0021_tpl_categories"
down_revision: Union[str, None] = "0020_activities"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ---- templates: category + привязки ----
    op.add_column(
        "templates",
        sa.Column("category", sa.String(length=32), nullable=True),
    )
    op.create_index("ix_templates_category", "templates", ["category"])

    op.add_column(
        "templates",
        sa.Column(
            "product_codes",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'[]'::json"),
        ),
    )
    op.add_column(
        "templates",
        sa.Column(
            "country_codes",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'[]'::json"),
        ),
    )
    op.add_column(
        "templates",
        sa.Column(
            "client_category_codes",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'[]'::json"),
        ),
    )
    op.add_column(
        "templates",
        sa.Column(
            "department_ids",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'[]'::json"),
        ),
    )

    # Базовый шаблон master_skeleton — это основной сублицензионный договор.
    # Существующие YAML-шаблоны (product_*, country_*) категории не имеют —
    # они служебные данные, а не самостоятельные категории документов.
    op.execute(
        "UPDATE templates SET category = 'sublicense_main' "
        "WHERE code = 'master_skeleton' AND category IS NULL"
    )

    # ---- approval_routes: template_category ----
    op.add_column(
        "approval_routes",
        sa.Column("template_category", sa.String(length=32), nullable=True),
    )


def downgrade() -> None:
    op.drop_column("approval_routes", "template_category")

    op.drop_column("templates", "department_ids")
    op.drop_column("templates", "client_category_codes")
    op.drop_column("templates", "country_codes")
    op.drop_column("templates", "product_codes")
    op.drop_index("ix_templates_category", table_name="templates")
    op.drop_column("templates", "category")
