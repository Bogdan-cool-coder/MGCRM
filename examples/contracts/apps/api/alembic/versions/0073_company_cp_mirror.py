"""CONTACTS 2.0 mirror-инвариант — backfill: Company без Counterparty-зеркала.

Латентный баг: POST /api/companies (новые «Контакты», в т.ч. express-создание)
до фикса НЕ создавал зеркало-Counterparty → у компании counterparty_id=NULL.
Следствие: первая же подписка/подписание (client_subscriptions.counterparty_id
NOT NULL) падала с IntegrityError. Старые 121 компаний имели зеркало из 0070;
компании, заведённые через UI после 0070 и до фикса — нет.

Эта миграция создаёт Counterparty-зеркало для всех crm_companies где
counterparty_id IS NULL и проставляет company.counterparty_id. Идемпотентна:
повторный прогон пропускает уже связанные компании (WHERE counterparty_id IS NULL).

Маппинг полей зеркала идентичен pure-function company_to_counterparty_fields:
- name ← company.name || company.legal_name (NOT NULL зеркала)
- country_code ← company.country_code || company.country || 'KZ' (NOT NULL)
- остальные реквизиты 1:1 из одноимённых колонок company.

Advisory-lock seed-key 73_011 (mirror backfill) — concurrent api replicas (scale=2)
не должны гоняться на INSERT зеркал.

Revision ID: 0073_company_cp_mirror  (21 chars ≤32 ✓)
Revises: 0072_sub_company_unique
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0073_company_cp_mirror"
down_revision: Union[str, None] = "0072_sub_company_unique"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_COMPANY_CP_MIRROR = 73_011

MIRROR_DEFAULT_COUNTRY_CODE = "KZ"


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_COMPANY_CP_MIRROR},
    )

    # Компании без зеркала. Берём все поля, нужные для faithful-mirror.
    rows = conn.execute(
        sa.text(
            "SELECT id, name, legal_name, country, country_code, city, "
            "legal_form, full_legal_form, gender_ending_oe, "
            "director_position, director_genitive, director_short, acts_basis, "
            "tax_id, tax_id_label, address, "
            "bank, bank_code_label, bank_code, account, "
            "phone, email, website, group_id, category_code, "
            "owner_user_id, responsible_user_id, department_id "
            "FROM crm_companies WHERE counterparty_id IS NULL"
        )
    ).mappings().all()

    insert_cp = sa.text(
        "INSERT INTO counterparties "
        "(name, country_code, city, legal_form, full_legal_form, gender_ending_oe, "
        "director_position, director_genitive, director_short, acts_basis, "
        "tax_id, tax_id_label, address, bank, bank_code_label, bank_code, account, "
        "phone, email, website, group_id, category_code, "
        "owner_user_id, responsible_user_id, department_id, extra_fields) "
        "VALUES "
        "(:name, :country_code, :city, :legal_form, :full_legal_form, :gender_ending_oe, "
        ":director_position, :director_genitive, :director_short, :acts_basis, "
        ":tax_id, :tax_id_label, :address, :bank, :bank_code_label, :bank_code, :account, "
        ":phone, :email, :website, :group_id, :category_code, "
        ":owner_user_id, :responsible_user_id, :department_id, '{}'::jsonb) "
        "RETURNING id"
    )

    for c in rows:
        name = (c["name"] or "").strip() or (c["legal_name"] or "").strip() or "—"
        country = (c["country_code"] or "").strip() or (c["country"] or "").strip()
        country_code = (country or MIRROR_DEFAULT_COUNTRY_CODE).upper()[:2]

        new_cp_id = conn.execute(
            insert_cp,
            {
                "name": name,
                "country_code": country_code,
                "city": c["city"],
                "legal_form": c["legal_form"],
                "full_legal_form": c["full_legal_form"],
                "gender_ending_oe": c["gender_ending_oe"],
                "director_position": c["director_position"],
                "director_genitive": c["director_genitive"],
                "director_short": c["director_short"],
                "acts_basis": c["acts_basis"],
                "tax_id": c["tax_id"],
                "tax_id_label": c["tax_id_label"],
                "address": c["address"],
                "bank": c["bank"],
                "bank_code_label": c["bank_code_label"],
                "bank_code": c["bank_code"],
                "account": c["account"],
                "phone": c["phone"],
                "email": c["email"],
                "website": c["website"],
                "group_id": c["group_id"],
                "category_code": c["category_code"],
                "owner_user_id": c["owner_user_id"],
                "responsible_user_id": c["responsible_user_id"],
                "department_id": c["department_id"],
            },
        ).scalar_one()

        conn.execute(
            sa.text(
                "UPDATE crm_companies SET counterparty_id = :cp_id "
                "WHERE id = :company_id AND counterparty_id IS NULL"
            ),
            {"cp_id": new_cp_id, "company_id": c["id"]},
        )


def downgrade() -> None:
    # Backfill необратим безопасно: мы не знаем, какие зеркала созданы именно
    # этой миграцией (vs. 0070 / вручную). Чтобы не удалять чужие Counterparty,
    # downgrade — no-op (зеркала-консистентность не вредит откату схемы).
    pass
