"""Эпик 21 — UX Upgrade: Profile 2.0 + Dark theme поля на users.

Добавляет четыре поля на таблицу users для UX-улучшений:

- theme_preference VARCHAR(16) DEFAULT 'system'
    Тема UI: 'system' (по умолчанию, follow ОС), 'light', 'dark'.
    CHECK на БД-уровне для защиты от опечаток. NOT NULL c дефолтом —
    при миграции существующие users получают 'system' автоматически.

- signature_url TEXT NULL
    Путь / URL к подписи менеджера (PNG/JPG). Поднимается через
    POST /api/users/me/signature, удаляется через DELETE.
    Используется в шаблонах договоров (block signature_image).
    NULL = подпись не загружена.

- locale VARCHAR(8) DEFAULT 'ru'
    Язык интерфейса. На этапе MVP — 'ru' (default) / 'en' (stub).
    Реальный i18n-движок появится в отдельном эпике; поле уже
    добавляем чтобы фронт мог хранить выбор и API возвращало его.

- job_title VARCHAR(128) NULL
    Должность сотрудника (текстовое поле). Отображается в карточке
    профиля и в подписи документов рядом с full_name.

Все ADD COLUMN с NULLABLE / DEFAULT — без table-rewrite, безопасно на
больших таблицах PG 11+. Defensive advisory-lock seed-key 57_953
(0xE261 — Epic 21 UX profile) для последовательности при concurrent
rolling-restart на scale=2 api-репликах.

Revision ID: 0041_user_ux_profile  (19 chars ≤32 ✓)
Revises: 0040_dup_scan_jobs
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0041_user_ux_profile"
down_revision: Union[str, None] = "0040_dup_scan_jobs"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE261 = 57_953 — Epic 21 UX profile seed-key.
_SEED_LOCK_EPIC_21_PROFILE = 57_953


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_21_PROFILE},
    )

    # ============ users.theme_preference ============
    # Default 'system' (follow ОС). NOT NULL — existing rows получают default
    # без table-rewrite на PG11+ (хранится в pg_attrdef).
    op.execute(
        "ALTER TABLE users "
        "ADD COLUMN IF NOT EXISTS theme_preference VARCHAR(16) "
        "NOT NULL DEFAULT 'system'"
    )
    # CHECK constraint — защита от опечаток. IF NOT EXISTS неподдержан
    # для ADD CONSTRAINT, поэтому проверяем существование через DO block.
    op.execute(
        """
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint
                WHERE conname = 'ck_users_theme_preference'
            ) THEN
                ALTER TABLE users
                ADD CONSTRAINT ck_users_theme_preference
                CHECK (theme_preference IN ('system','light','dark'));
            END IF;
        END$$;
        """
    )

    # ============ users.signature_url ============
    op.execute(
        "ALTER TABLE users "
        "ADD COLUMN IF NOT EXISTS signature_url TEXT"
    )

    # ============ users.locale ============
    op.execute(
        "ALTER TABLE users "
        "ADD COLUMN IF NOT EXISTS locale VARCHAR(8) "
        "NOT NULL DEFAULT 'ru'"
    )
    op.execute(
        """
        DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1 FROM pg_constraint
                WHERE conname = 'ck_users_locale'
            ) THEN
                ALTER TABLE users
                ADD CONSTRAINT ck_users_locale
                CHECK (locale IN ('ru','en'));
            END IF;
        END$$;
        """
    )

    # ============ users.job_title ============
    op.execute(
        "ALTER TABLE users "
        "ADD COLUMN IF NOT EXISTS job_title VARCHAR(128)"
    )


def downgrade() -> None:
    op.execute("ALTER TABLE users DROP CONSTRAINT IF EXISTS ck_users_locale")
    op.execute(
        "ALTER TABLE users DROP CONSTRAINT IF EXISTS ck_users_theme_preference"
    )
    op.execute("ALTER TABLE users DROP COLUMN IF EXISTS job_title")
    op.execute("ALTER TABLE users DROP COLUMN IF EXISTS locale")
    op.execute("ALTER TABLE users DROP COLUMN IF EXISTS signature_url")
    op.execute("ALTER TABLE users DROP COLUMN IF EXISTS theme_preference")
