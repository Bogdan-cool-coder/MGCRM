"""Эпик 8 (Карточка 2.0): custom_field_defs + extra_fields на 7 сущностях +
entity_audit_logs + dismissed_duplicates + saved_filters.

Что добавляется:
1. На 7 сущностях (leads, crm_contacts, crm_companies, counterparties, deals,
   contracts, client_subscriptions) — колонка extra_fields jsonb DEFAULT '{}'.
   Аддитивно, не ломает существующие записи. По умолчанию пусто.
2. custom_field_defs — определения кастомных полей (scope + code + kind + options).
   Уникальность (entity_scope, code). Soft-delete через is_active.
3. entity_audit_logs — новая generic-таблица аудита (НЕ путать с существующей
   audit_log, которая contract-specific). Hot WHERE — (entity_type, entity_id,
   occurred_at DESC) — композитный индекс.
4. dismissed_duplicates — отметки «это не дубль» от пользователя, чтобы дублескан
   не показывал ту же пару снова. Уникальность по упорядоченной паре
   (entity_type, entity_a_id, entity_b_id), где a < b — поддерживается на уровне
   сервиса при insert'е.
5. saved_filters — сохранённые сегменты пользователя (user_id NULL = глобальный,
   доступен всем; только admin может создавать NULL-сегменты — enforce в роутере).

Миграция аддитивная schema-only — seed нет, advisory-lock не нужен. ADD COLUMN
с DEFAULT '{}'::jsonb безопасен на больших таблицах в PG 11+ (без table rewrite).

Revision ID: 0026_card2_extensions
Revises: 0025_sequences
Create Date: 2026-05-31
"""
from typing import Sequence, Union

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0026_card2_extensions"
down_revision: Union[str, None] = "0025_sequences"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


# Сущности, на которые добавляется extra_fields. Порядок — алфавитный + reverse в
# downgrade (см. ниже).
_EXTRA_FIELDS_TABLES = (
    "leads",
    "crm_contacts",
    "crm_companies",
    "counterparties",
    "deals",
    "contracts",
    "client_subscriptions",
)


def upgrade() -> None:
    # ---- 1. extra_fields на 7 сущностях ----
    for tbl in _EXTRA_FIELDS_TABLES:
        op.add_column(
            tbl,
            sa.Column(
                "extra_fields",
                JSONB(),
                nullable=False,
                server_default=sa.text("'{}'::jsonb"),
            ),
        )

    # ---- 2. custom_field_defs ----
    op.create_table(
        "custom_field_defs",
        sa.Column("id", sa.Integer(), primary_key=True),
        # 'lead' | 'contact' | 'company' | 'counterparty' | 'deal' | 'contract' | 'subscription'
        sa.Column("entity_scope", sa.String(length=32), nullable=False),
        # snake_case — уникален в паре (scope, code)
        sa.Column("code", sa.String(length=64), nullable=False),
        sa.Column("label_ru", sa.String(length=255), nullable=False),
        # 'text' | 'textarea' | 'number' | 'date' | 'select' | 'multiselect' | 'url' | 'checkbox'
        sa.Column("kind", sa.String(length=16), nullable=False),
        sa.Column(
            "is_required",
            sa.Boolean(),
            nullable=False,
            server_default=sa.false(),
        ),
        # null допустим; для select/multiselect — строка/массив (JSON)
        sa.Column("default_value", JSONB(), nullable=True),
        # Только для select/multiselect — массив вариантов. Иначе []
        sa.Column(
            "options_json",
            JSONB(),
            nullable=False,
            server_default=sa.text("'[]'::jsonb"),
        ),
        sa.Column("sort_order", sa.Integer(), nullable=False, server_default="0"),
        sa.Column(
            "is_active",
            sa.Boolean(),
            nullable=False,
            server_default=sa.true(),
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
        sa.UniqueConstraint("entity_scope", "code", name="uq_cfd_scope_code"),
    )
    op.create_index(
        "ix_custom_field_defs_entity_scope",
        "custom_field_defs",
        ["entity_scope"],
    )
    op.create_index(
        "ix_custom_field_defs_is_active",
        "custom_field_defs",
        ["is_active"],
    )

    # ---- 3. entity_audit_logs ----
    op.create_table(
        "entity_audit_logs",
        sa.Column("id", sa.Integer(), primary_key=True),
        # 'lead' | 'contact' | 'company' | 'counterparty' | 'deal' | 'contract' | 'subscription'
        sa.Column("entity_type", sa.String(length=32), nullable=False),
        sa.Column("entity_id", sa.Integer(), nullable=False),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        # 'create' | 'update' | 'delete' | 'merge' | 'extra_fields_change' | 'bulk_action'
        sa.Column("action", sa.String(length=32), nullable=False),
        # Для action='update': {"fields": {field_name: {"old": ..., "new": ...}}}
        # Для action='merge': {"merged_from": id, "field_choices": {...}}
        # Для action='create'/'delete': snapshot или null
        sa.Column("diff_json", JSONB(), nullable=True),
        sa.Column(
            "occurred_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        # Опциональный correlation id (для bulk операций)
        sa.Column("request_id", sa.String(length=64), nullable=True),
    )
    # Главный индекс — Hot WHERE entity_type=? AND entity_id=? ORDER BY occurred_at DESC
    op.create_index(
        "ix_entity_audit_logs_entity_occurred",
        "entity_audit_logs",
        ["entity_type", "entity_id", sa.text("occurred_at DESC")],
    )
    op.create_index(
        "ix_entity_audit_logs_user_id",
        "entity_audit_logs",
        ["user_id"],
    )

    # ---- 4. dismissed_duplicates ----
    op.create_table(
        "dismissed_duplicates",
        sa.Column("id", sa.Integer(), primary_key=True),
        # 'counterparty' | 'contact' | 'company' | 'lead'
        sa.Column("entity_type", sa.String(length=32), nullable=False),
        # a < b — нормализуется на уровне сервиса при insert
        sa.Column("entity_a_id", sa.Integer(), nullable=False),
        sa.Column("entity_b_id", sa.Integer(), nullable=False),
        sa.Column(
            "dismissed_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "dismissed_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.UniqueConstraint(
            "entity_type", "entity_a_id", "entity_b_id", name="uq_dismissed_pair",
        ),
    )
    op.create_index(
        "ix_dismissed_duplicates_entity",
        "dismissed_duplicates",
        ["entity_type"],
    )

    # ---- 5. saved_filters ----
    op.create_table(
        "saved_filters",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=True,  # NULL = глобальный
        ),
        # 'leads' | 'contacts' | 'companies' | 'counterparties' | 'deals' | 'registry'
        sa.Column("page_key", sa.String(length=64), nullable=False),
        sa.Column("name", sa.String(length=128), nullable=False),
        sa.Column(
            "filter_json",
            JSONB(),
            nullable=False,
            server_default=sa.text("'{}'::jsonb"),
        ),
        sa.Column(
            "is_pinned",
            sa.Boolean(),
            nullable=False,
            server_default=sa.false(),
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
    )
    op.create_index(
        "ix_saved_filters_user_page",
        "saved_filters",
        ["user_id", "page_key", sa.text("is_pinned DESC")],
    )
    op.create_index(
        "ix_saved_filters_page_key",
        "saved_filters",
        ["page_key"],
    )


def downgrade() -> None:
    op.drop_index("ix_saved_filters_page_key", table_name="saved_filters")
    op.drop_index("ix_saved_filters_user_page", table_name="saved_filters")
    op.drop_table("saved_filters")

    op.drop_index("ix_dismissed_duplicates_entity", table_name="dismissed_duplicates")
    op.drop_table("dismissed_duplicates")

    op.drop_index("ix_entity_audit_logs_user_id", table_name="entity_audit_logs")
    op.drop_index("ix_entity_audit_logs_entity_occurred", table_name="entity_audit_logs")
    op.drop_table("entity_audit_logs")

    op.drop_index("ix_custom_field_defs_is_active", table_name="custom_field_defs")
    op.drop_index("ix_custom_field_defs_entity_scope", table_name="custom_field_defs")
    op.drop_table("custom_field_defs")

    for tbl in reversed(_EXTRA_FIELDS_TABLES):
        op.drop_column(tbl, "extra_fields")
