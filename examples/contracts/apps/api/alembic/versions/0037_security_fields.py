"""Эпик 16 — Security: 2FA TOTP поля на users.

Добавляет четыре поля для TOTP 2FA (RFC 6238) на таблицу users:

- totp_secret_encrypted TEXT NULL
    Fernet-encrypted base32 seed (см. app/services/totp.py::encrypt_secret).
    NULL = у пользователя не настроен TOTP. После disable стирается → NULL.

- totp_enabled BOOLEAN DEFAULT false
    Флаг включённого 2FA. NULL не допускается (DEFAULT false). Включается
    только после verify-setup (когда юзер ввёл код из аутентификатора и
    он сошёлся с свежесгенерированным secret'ом).

- totp_backup_codes_hashed TEXT[]
    Массив bcrypt-хэшей 8-значных backup-кодов. При validate сравниваем
    введённый код с каждым хэшем через bcrypt.checkpw — найдено
    совпадение → удаляем элемент из массива (одноразовость), commit.
    NULL до verify-setup.

- totp_enabled_at TIMESTAMPTZ NULL
    Время первого включения 2FA. Используется в аудите и для будущей
    политики «требуем включить за N дней после создания юзера».

Все ADD COLUMN NULLABLE → без table-rewrite, безопасно на больших таблицах
PG 11+. Defensive advisory-lock seed-key 57696 (0xE160 — Epic 16, security)
для последовательности при concurrent api-rolling.

Revision ID: 0037_security_fields  (20 chars ≤32 ✓)
Revises: 0036_owner_dept_fields
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0037_security_fields"
down_revision: Union[str, None] = "0036_owner_dept_fields"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE160 = 57696 — Epic 16 security seed-key. Defensive lock: env.py уже даёт
# глобальный lock на миграцию, но отдельный семантический key улучшает
# диагностику в pg_locks при concurrent rolling-restart.
_SEED_LOCK_EPIC_16_SECURITY = 57_696


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_16_SECURITY},
    )

    # ============ users.totp_* ============
    op.execute(
        "ALTER TABLE users "
        "ADD COLUMN IF NOT EXISTS totp_secret_encrypted TEXT"
    )
    op.execute(
        "ALTER TABLE users "
        "ADD COLUMN IF NOT EXISTS totp_enabled BOOLEAN NOT NULL DEFAULT FALSE"
    )
    # ARRAY of TEXT для хэшей бэкап-кодов. NULL допустим (до verify-setup).
    op.execute(
        "ALTER TABLE users "
        "ADD COLUMN IF NOT EXISTS totp_backup_codes_hashed TEXT[]"
    )
    op.execute(
        "ALTER TABLE users "
        "ADD COLUMN IF NOT EXISTS totp_enabled_at TIMESTAMPTZ"
    )


def downgrade() -> None:
    op.execute("ALTER TABLE users DROP COLUMN IF EXISTS totp_enabled_at")
    op.execute(
        "ALTER TABLE users DROP COLUMN IF EXISTS totp_backup_codes_hashed"
    )
    op.execute("ALTER TABLE users DROP COLUMN IF EXISTS totp_enabled")
    op.execute("ALTER TABLE users DROP COLUMN IF EXISTS totp_secret_encrypted")
