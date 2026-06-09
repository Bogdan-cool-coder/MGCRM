"""P0 security (Unit 3a) — fail-CLOSED per-role visibility matrix.

Прод-БД до этой миграции имела ТОЛЬКО NULL-role строки `visibility_settings`
со scope='all' (старый fail-open default «все видят всё до настройки»). Это
открывало чужие сделки/контракты/PII любой роли.

Эта миграция:
  1. Сидит ЯВНЫЕ per-role строки (entity_type × role → scope), insert-missing:
       admin/director/lawyer → all
       manager/accountant/cfo → personal (own)
     для каждого entity_type (lead/deal/contract/subscription/counterparty/
     company/activity).
  2. УДАЛЯЕТ старые fail-open NULL-role строки со scope='all', чтобы они больше
     не служили fallback'ом «всё видно всем». NULL-role строки с другим scope
     (если админ их явно настроил) НЕ трогаем.

Идемпотентно (insert-missing + conditional delete). Advisory-lock 96_010
(диапазон 96_0xx свободен), сериализует concurrent api-стартапы (scale=2/4).

downgrade(): возвращает старый fail-open NULL-role 'all' default и удаляет
сидированные per-role строки — восстанавливает доминирующее поведение до фикса.

Revision ID: 0105_vis_role_scopes
Revises: 0104_call_training
Create Date: 2026-06-05
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0105_vis_role_scopes"
down_revision: str | None = "0104_call_training"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Advisory-lock ключ (диапазон 96_0xx свободен).
_LOCK_VIS_ROLE_SCOPES = 96_010

# Синхронизировано с app.services.access_control.ALLOWED_ENTITY_TYPES и
# app.services.visibility._DEFAULT_ROLE_SCOPES. Захардкожено в миграции намеренно
# (миграции не должны импортировать движущийся код приложения).
_ENTITY_TYPES = (
    "lead", "deal", "contract", "subscription",
    "counterparty", "company", "activity",
)
_ROLE_SCOPES = {
    "admin": "all",
    "director": "all",
    "lawyer": "all",
    "manager": "personal",
    "accountant": "personal",
    "cfo": "personal",
}


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_VIS_ROLE_SCOPES}
    )

    # 0) Расширить CHECK по applies_to_role: добавить accountant/cfo (роли модуля
    # «Финансы»), иначе seed их строк упадёт на ck_visibility_settings_role.
    conn.execute(sa.text(
        "ALTER TABLE visibility_settings "
        "DROP CONSTRAINT IF EXISTS ck_visibility_settings_role"
    ))
    conn.execute(sa.text(
        "ALTER TABLE visibility_settings ADD CONSTRAINT ck_visibility_settings_role "
        "CHECK (applies_to_role IS NULL OR applies_to_role IN "
        "('manager','lawyer','director','admin','accountant','cfo'))"
    ))

    # 1) Insert-missing per-role строки. Явные ::varchar касты — asyncpg иначе
    # выводит несовместимые типы для повторно используемых bind-параметров.
    insert_sql = sa.text(
        """
        INSERT INTO visibility_settings (entity_type, scope, applies_to_role)
        SELECT CAST(:et AS varchar), CAST(:scope AS varchar), CAST(:role AS varchar)
        WHERE NOT EXISTS (
            SELECT 1 FROM visibility_settings
            WHERE entity_type = CAST(:et AS varchar)
              AND applies_to_role = CAST(:role AS varchar)
        )
        """
    )
    for et in _ENTITY_TYPES:
        for role, scope in _ROLE_SCOPES.items():
            conn.execute(insert_sql, {"et": et, "scope": scope, "role": role})

    # 2) Удалить старые fail-open NULL-role строки со scope='all'.
    conn.execute(
        sa.text(
            """
            DELETE FROM visibility_settings
            WHERE applies_to_role IS NULL AND scope = 'all'
            """
        )
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_VIS_ROLE_SCOPES}
    )

    # 1) Удалить сидированные per-role строки (только дефолтные роли).
    conn.execute(
        sa.text(
            """
            DELETE FROM visibility_settings
            WHERE applies_to_role = ANY(:roles)
            """
        ),
        {"roles": list(_ROLE_SCOPES.keys())},
    )

    # 2) Восстановить старый NULL-role 'all' default (insert-missing).
    insert_sql = sa.text(
        """
        INSERT INTO visibility_settings (entity_type, scope, applies_to_role)
        SELECT CAST(:et AS varchar), 'all', NULL
        WHERE NOT EXISTS (
            SELECT 1 FROM visibility_settings
            WHERE entity_type = CAST(:et AS varchar) AND applies_to_role IS NULL
        )
        """
    )
    for et in _ENTITY_TYPES:
        conn.execute(insert_sql, {"et": et})

    # 3) Вернуть узкий CHECK по ролям (accountant/cfo строки уже удалены в шаге 1).
    conn.execute(sa.text(
        "ALTER TABLE visibility_settings "
        "DROP CONSTRAINT IF EXISTS ck_visibility_settings_role"
    ))
    conn.execute(sa.text(
        "ALTER TABLE visibility_settings ADD CONSTRAINT ck_visibility_settings_role "
        "CHECK (applies_to_role IS NULL OR applies_to_role IN "
        "('manager','lawyer','director','admin'))"
    ))
