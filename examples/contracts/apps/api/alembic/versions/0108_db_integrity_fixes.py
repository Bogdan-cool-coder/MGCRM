"""DB-целостность: FK repoint + nullable fix + NULLS NOT DISTINCT (model↔DB drift).

Три точечных фикса рассинхрона модели и боевой схемы (audit июнь 2026):

1. FinInvoice.contact_id — FK висел на legacy `contacts.id`, а модель/домен уже
   работают с `crm_contacts`. Обнуляем орфанов (contact_id, которого нет в
   crm_contacts — в dev данных ~0), пересаживаем FK на `crm_contacts(id)
   ON DELETE SET NULL`.

2. activities.created_by_id — колонка была NOT NULL, но FK имеет ON DELETE SET NULL.
   Противоречие: при удалении автора Postgres попытается записать NULL в NOT NULL
   → ошибка, удаление пользователя падает. Решаем в сторону безопасной истории:
   снимаем NOT NULL (DROP NOT NULL).

3. uq_fin_permission — обычный UNIQUE по (role, user_id, legal_entity_id,
   capability). Колонки role/user_id/legal_entity_id nullable, а в Postgres NULL'ы
   различны → role-default права (user_id=NULL, legal_entity_id=NULL) не конфликтуют
   сами с собой, дубли проходят. Пересоздаём constraint с NULLS NOT DISTINCT (PG15+,
   у нас PG16). Перед пересозданием дедуплицируем (keep max(id) на логический ключ).

Идемпотентно: DROP CONSTRAINT IF EXISTS / IF EXISTS-guard на FK; DROP NOT NULL —
no-op если уже nullable. Advisory-lock 97_111 (диапазон 97_1xx; 97_110 занят 0107,
97_010 занят 0106).

Revision ID: 0108_db_integrity_fixes
Revises: 0107_kpi_nulls_nd
Create Date: 2026-06-07
"""
from __future__ import annotations

from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op

revision: str = "0108_db_integrity_fixes"
down_revision: str | None = "0107_kpi_nulls_nd"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

#: Advisory-lock ключ (97_1xx; 97_110 занят 0107, 97_010 занят 0106).
_LOCK_DB_INTEGRITY = 97_111

#: Имя FK-constraint'а fin_invoice.contact_id (генерится по конвенции Postgres
#: `<table>_<col>_fkey`; на legacy он указывал на contacts.id).
_FK_INVOICE_CONTACT = "fin_invoice_contact_id_fkey"


def _drop_invoice_contact_fk(conn) -> None:  # type: ignore[no-untyped-def]
    """Найти и дропнуть фактический FK на fin_invoice.contact_id (любое имя)."""
    rows = conn.execute(
        sa.text(
            """
            SELECT con.conname
              FROM pg_constraint con
              JOIN pg_class rel        ON rel.oid = con.conrelid
              JOIN pg_attribute att    ON att.attrelid = con.conrelid
                                      AND att.attnum = ANY (con.conkey)
             WHERE con.contype = 'f'
               AND rel.relname = 'fin_invoice'
               AND att.attname = 'contact_id'
            """
        )
    ).scalars().all()
    for conname in rows:
        conn.execute(sa.text(f'ALTER TABLE fin_invoice DROP CONSTRAINT "{conname}"'))


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_DB_INTEGRITY})

    # ── 1) FinInvoice.contact_id: repoint contacts.id → crm_contacts.id ──────────
    # Обнулить орфанов (нет соответствующей строки в crm_contacts), иначе ADD
    # CONSTRAINT упадёт по FK-violation.
    conn.execute(
        sa.text(
            """
            UPDATE fin_invoice
               SET contact_id = NULL
             WHERE contact_id IS NOT NULL
               AND contact_id NOT IN (SELECT id FROM crm_contacts)
            """
        )
    )
    # Снять старый FK. Имя авто-сгенерено Postgres'ом по конвенции, но на всякий
    # случай находим фактический FK-constraint на колонке contact_id в pg_catalog
    # и дропаем его (плюс IF EXISTS по конвенционному имени — как страховка).
    _drop_invoice_contact_fk(conn)
    conn.execute(
        sa.text(
            f"ALTER TABLE fin_invoice DROP CONSTRAINT IF EXISTS {_FK_INVOICE_CONTACT}"
        )
    )
    # Навесить новый FK на crm_contacts.
    conn.execute(
        sa.text(
            f"""
            ALTER TABLE fin_invoice
              ADD CONSTRAINT {_FK_INVOICE_CONTACT}
              FOREIGN KEY (contact_id) REFERENCES crm_contacts (id)
              ON DELETE SET NULL
            """
        )
    )

    # ── 2) activities.created_by_id: DROP NOT NULL ──────────────────────────────
    # No-op, если колонка уже nullable.
    conn.execute(
        sa.text("ALTER TABLE activities ALTER COLUMN created_by_id DROP NOT NULL")
    )

    # ── 3) uq_fin_permission: NULLS NOT DISTINCT ────────────────────────────────
    # Дедуп: оставить max(id) на каждый логический ключ (NULL == NULL).
    conn.execute(
        sa.text(
            """
            DELETE FROM fin_permission a
            USING fin_permission b
            WHERE a.id < b.id
              AND a.role            IS NOT DISTINCT FROM b.role
              AND a.user_id         IS NOT DISTINCT FROM b.user_id
              AND a.legal_entity_id IS NOT DISTINCT FROM b.legal_entity_id
              AND a.capability       =                  b.capability
            """
        )
    )
    conn.execute(
        sa.text(
            "ALTER TABLE fin_permission DROP CONSTRAINT IF EXISTS uq_fin_permission"
        )
    )
    conn.execute(
        sa.text(
            """
            ALTER TABLE fin_permission
              ADD CONSTRAINT uq_fin_permission
              UNIQUE NULLS NOT DISTINCT (role, user_id, legal_entity_id, capability)
            """
        )
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _LOCK_DB_INTEGRITY})

    # ── 3') uq_fin_permission: вернуть обычный UNIQUE (NULLS DISTINCT) ───────────
    conn.execute(
        sa.text(
            "ALTER TABLE fin_permission DROP CONSTRAINT IF EXISTS uq_fin_permission"
        )
    )
    conn.execute(
        sa.text(
            """
            ALTER TABLE fin_permission
              ADD CONSTRAINT uq_fin_permission
              UNIQUE (role, user_id, legal_entity_id, capability)
            """
        )
    )

    # ── 2') activities.created_by_id: вернуть NOT NULL ──────────────────────────
    # Перед SET NOT NULL подчищаем возможные NULL (история без автора) — иначе
    # ALTER упадёт. Безопасной строки-«системного» автора у нас нет, поэтому такие
    # сироты-активности удаляются (downgrade — аварийный путь).
    conn.execute(
        sa.text("DELETE FROM activities WHERE created_by_id IS NULL")
    )
    conn.execute(
        sa.text("ALTER TABLE activities ALTER COLUMN created_by_id SET NOT NULL")
    )

    # ── 1') FinInvoice.contact_id: repoint crm_contacts.id → contacts.id ─────────
    conn.execute(
        sa.text(
            """
            UPDATE fin_invoice
               SET contact_id = NULL
             WHERE contact_id IS NOT NULL
               AND contact_id NOT IN (SELECT id FROM contacts)
            """
        )
    )
    _drop_invoice_contact_fk(conn)
    conn.execute(
        sa.text(
            f"ALTER TABLE fin_invoice DROP CONSTRAINT IF EXISTS {_FK_INVOICE_CONTACT}"
        )
    )
    conn.execute(
        sa.text(
            f"""
            ALTER TABLE fin_invoice
              ADD CONSTRAINT {_FK_INVOICE_CONTACT}
              FOREIGN KEY (contact_id) REFERENCES contacts (id)
              ON DELETE SET NULL
            """
        )
    )
