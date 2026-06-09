"""Renewal pipeline + bulk_tasks (Эпик 6 MVP).

Эпик 6 — три фичи: renewal-воронка продлений, асинхронная bulk-генерация
документов и аналитика по воронкам. Эта миграция отвечает только за вторую
часть схемы (bulk_tasks): renewal-воронка живёт в существующих pipelines /
pipeline_stages и создаётся сидером `seed_renewal_pipeline` (advisory-lock
728_274_008) на старте lifespan'а — отдельной таблицы под неё не нужно.

Таблица `bulk_tasks`:
- kind:               'document_generation' (расширяется enum-like строкой)
- status:             'pending' | 'running' | 'success' | 'failed' | 'cancelled'
- template_code:      код шаблона документа (master_skeleton или будущие категории)
- target_type:        'counterparty' | 'subscription' (расширяется)
- target_ids:         JSON-массив id выбранных сущностей
- total/success/failed_count: прогресс (обновляется фоновой задачей)
- result_zip_path:    путь до результирующего .zip в /data/storage/bulk_tasks/
- error_text:         финальная ошибка, если status='failed'
- created_by_user_id: автор задачи (FK users, SET NULL при удалении)
- created_at:         когда задачу создали (для сортировки и хранения)
- started_at:         когда фоновая задача реально начала генерацию
- finished_at:        когда задача завершилась (success/failed/cancelled)

Индексы — на горячих WHERE/ORDER BY: created_by_user_id (фильтр «мои задачи»),
status (фильтр по статусу + cleanup в будущем), created_at (сортировка списка).
Миграция schema-only — seed-данных нет, advisory-lock не нужен.

Revision ID: 0024_renewal_bulk
Revises: 0023_inbox_channels
Create Date: 2026-05-30
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa

revision: str = "0024_renewal_bulk"
down_revision: Union[str, None] = "0023_inbox_channels"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "bulk_tasks",
        sa.Column("id", sa.Integer(), primary_key=True),
        # 'document_generation' (расширяемо строкой — bulk_export / bulk_email и т.п.)
        sa.Column("kind", sa.String(length=32), nullable=False),
        # 'pending' | 'running' | 'success' | 'failed' | 'cancelled'
        sa.Column(
            "status",
            sa.String(length=16),
            nullable=False,
            server_default="pending",
        ),
        # Для kind='document_generation' — код шаблона (master_skeleton и т.п.)
        sa.Column("template_code", sa.String(length=64), nullable=True),
        # 'counterparty' | 'subscription' (расширяется enum-like строкой)
        sa.Column("target_type", sa.String(length=32), nullable=False),
        # Массив id — без отдельной таблицы (объёмы небольшие, до сотен)
        sa.Column(
            "target_ids",
            sa.JSON(),
            nullable=False,
            server_default=sa.text("'[]'::json"),
        ),
        sa.Column(
            "total_count", sa.Integer(), nullable=False, server_default="0",
        ),
        sa.Column(
            "success_count", sa.Integer(), nullable=False, server_default="0",
        ),
        sa.Column(
            "failed_count", sa.Integer(), nullable=False, server_default="0",
        ),
        # Путь до результирующего .zip (формируется в bulk_generator)
        sa.Column("result_zip_path", sa.String(length=512), nullable=True),
        # Финальная ошибка, если status='failed'
        sa.Column("error_text", sa.Text(), nullable=True),
        sa.Column(
            "created_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            nullable=False,
            server_default=sa.func.now(),
        ),
        sa.Column(
            "started_at", sa.DateTime(timezone=True), nullable=True,
        ),
        sa.Column(
            "finished_at", sa.DateTime(timezone=True), nullable=True,
        ),
    )
    op.create_index(
        "ix_bulk_tasks_created_by_user_id",
        "bulk_tasks",
        ["created_by_user_id"],
    )
    op.create_index("ix_bulk_tasks_status", "bulk_tasks", ["status"])
    op.create_index("ix_bulk_tasks_created_at", "bulk_tasks", ["created_at"])


def downgrade() -> None:
    op.drop_index("ix_bulk_tasks_created_at", table_name="bulk_tasks")
    op.drop_index("ix_bulk_tasks_status", table_name="bulk_tasks")
    op.drop_index(
        "ix_bulk_tasks_created_by_user_id", table_name="bulk_tasks",
    )
    op.drop_table("bulk_tasks")
