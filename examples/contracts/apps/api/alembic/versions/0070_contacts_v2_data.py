"""CONTACTS 2.0 Ф0 — data-migration: Counterparty→Company, Legacy→Contact, FK fill.

Идемпотентная. Повторный прогон не плодит дубли и не падает.

Шаги:
1. Для каждого counterparty — найти/создать crm_companies (по Company.counterparty_id),
   дозаполнить недостающие реквизиты. Строит маппинг counterparty_id→company_id.
2. Заполнить deals.company_id / contracts.company_id /
   client_subscriptions.company_id / leads.converted_to_company_id по маппингу
   (только где company_id ещё NULL).
3. LegacyContact (table `contacts`) → crm_contacts (дедуп по email/phone/name) +
   crm_contact_company_links (UNIQUE contact_id+company_id).
4. Существующие crm_contacts со старым company_id → crm_contact_company_links.

🔴 counterparties / counterparty_id колонки НЕ трогаем.

Advisory-lock seed-key 70_011 (CONTACTS 2.0 Ф0 data).

Revision ID: 0070_contacts_v2_data  (20 chars ≤32 ✓)
Revises: 0069_contacts_v2_schema
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Any, Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0070_contacts_v2_data"
down_revision: Union[str, None] = "0069_contacts_v2_schema"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_CONTACTS_V2_DATA = 70_011

# Поля Counterparty → одноимённые колонки crm_companies (1:1).
_DIRECT_FIELDS = (
    "tax_id", "tax_id_label", "phone", "email", "website", "notes",
    "group_id", "category_code", "owner_user_id", "responsible_user_id",
    "department_id", "turnover_rub", "full_legal_form", "legal_form",
    "gender_ending_oe", "director_position", "director_genitive",
    "director_short", "acts_basis", "address", "bank", "bank_code_label",
    "bank_code", "account", "country_code", "city",
)


def _resolve_legal_name(cp: dict[str, Any]) -> str:
    full = (cp.get("full_legal_form") or "").strip()
    if full:
        return full
    name = (cp.get("name") or "").strip()
    legal_form = (cp.get("legal_form") or "").strip()
    if legal_form and name:
        return f"{legal_form} {name}".strip()
    return name or "—"


def _company_fields_from_cp(cp: dict[str, Any]) -> dict[str, Any]:
    out: dict[str, Any] = {}
    for f in _DIRECT_FIELDS:
        out[f] = cp.get(f)
    out["name"] = cp.get("name")
    out["legal_name"] = _resolve_legal_name(cp)
    # country (источник истины) ← country_code.
    out["country"] = cp.get("country_code")
    out["extra_fields"] = cp.get("extra_fields") or {}
    return out


def _dedup_key(name: str | None, email: str | None, phone: str | None) -> str:
    e = (email or "").strip().lower()
    if e:
        return f"email:{e}"
    digits = "".join(c for c in (phone or "") if c.isdigit())
    if digits:
        return f"phone:{digits}"
    n = (name or "").strip().lower()
    if n:
        return f"name:{n}"
    return ""


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_CONTACTS_V2_DATA},
    )

    # ---- 1. Counterparty → Company + маппинг ----
    cp_rows = conn.execute(
        sa.text(
            "SELECT id, name, full_legal_form, legal_form, gender_ending_oe, "
            "country_code, city, director_position, director_genitive, "
            "director_short, acts_basis, tax_id_label, tax_id, address, bank, "
            "bank_code_label, bank_code, account, phone, email, website, notes, "
            "group_id, category_code, turnover_rub, responsible_user_id, "
            "owner_user_id, department_id, extra_fields "
            "FROM counterparties"
        )
    ).mappings().all()

    # Существующие Company по counterparty_id (для переиспользования).
    existing = conn.execute(
        sa.text(
            "SELECT id, counterparty_id FROM crm_companies "
            "WHERE counterparty_id IS NOT NULL"
        )
    ).mappings().all()
    cp_to_company: dict[int, int] = {
        r["counterparty_id"]: r["id"] for r in existing
    }

    insert_company = sa.text(
        "INSERT INTO crm_companies "
        "(legal_name, short_name, name, tax_id, tax_id_label, country, city, "
        "website, phone, email, notes, group_id, category_code, counterparty_id, "
        "full_legal_form, legal_form, gender_ending_oe, country_code, "
        "director_position, director_genitive, director_short, acts_basis, "
        "address, bank, bank_code_label, bank_code, account, turnover_rub, "
        "responsible_user_id, owner_user_id, department_id, extra_fields) "
        "VALUES "
        "(:legal_name, NULL, :name, :tax_id, :tax_id_label, :country, :city, "
        ":website, :phone, :email, :notes, :group_id, :category_code, :cp_id, "
        ":full_legal_form, :legal_form, :gender_ending_oe, :country_code, "
        ":director_position, :director_genitive, :director_short, :acts_basis, "
        ":address, :bank, :bank_code_label, :bank_code, :account, :turnover_rub, "
        ":responsible_user_id, :owner_user_id, :department_id, "
        "CAST(:extra_fields AS jsonb)) "
        "RETURNING id"
    )

    for cp in cp_rows:
        cp_dict = dict(cp)
        fields = _company_fields_from_cp(cp_dict)
        cp_id = cp_dict["id"]
        company_id = cp_to_company.get(cp_id)

        if company_id is None:
            params = {
                "legal_name": fields["legal_name"],
                "name": fields["name"],
                "tax_id": fields["tax_id"],
                "tax_id_label": fields["tax_id_label"],
                "country": fields["country"],
                "city": fields["city"],
                "website": fields["website"],
                "phone": fields["phone"],
                "email": fields["email"],
                "notes": fields["notes"],
                "group_id": fields["group_id"],
                "category_code": fields["category_code"],
                "cp_id": cp_id,
                "full_legal_form": fields["full_legal_form"],
                "legal_form": fields["legal_form"],
                "gender_ending_oe": fields["gender_ending_oe"],
                "country_code": fields["country_code"],
                "director_position": fields["director_position"],
                "director_genitive": fields["director_genitive"],
                "director_short": fields["director_short"],
                "acts_basis": fields["acts_basis"],
                "address": fields["address"],
                "bank": fields["bank"],
                "bank_code_label": fields["bank_code_label"],
                "bank_code": fields["bank_code"],
                "account": fields["account"],
                "turnover_rub": fields["turnover_rub"],
                "responsible_user_id": fields["responsible_user_id"],
                "owner_user_id": fields["owner_user_id"],
                "department_id": fields["department_id"],
                "extra_fields": _json(fields["extra_fields"]),
            }
            new_id = conn.execute(insert_company, params).scalar_one()
            cp_to_company[cp_id] = new_id
        else:
            # Переиспользуем: дозаполняем только пустые реквизиты (COALESCE).
            conn.execute(
                sa.text(
                    "UPDATE crm_companies SET "
                    "name = COALESCE(name, :name), "
                    "full_legal_form = COALESCE(full_legal_form, :full_legal_form), "
                    "legal_form = COALESCE(legal_form, :legal_form), "
                    "gender_ending_oe = COALESCE(gender_ending_oe, :gender_ending_oe), "
                    "country_code = COALESCE(country_code, :country_code), "
                    "country = COALESCE(country, :country), "
                    "director_position = COALESCE(director_position, :director_position), "
                    "director_genitive = COALESCE(director_genitive, :director_genitive), "
                    "director_short = COALESCE(director_short, :director_short), "
                    "acts_basis = COALESCE(acts_basis, :acts_basis), "
                    "tax_id_label = COALESCE(tax_id_label, :tax_id_label), "
                    "tax_id = COALESCE(tax_id, :tax_id), "
                    "address = COALESCE(address, :address), "
                    "bank = COALESCE(bank, :bank), "
                    "bank_code_label = COALESCE(bank_code_label, :bank_code_label), "
                    "bank_code = COALESCE(bank_code, :bank_code), "
                    "account = COALESCE(account, :account), "
                    "turnover_rub = COALESCE(turnover_rub, :turnover_rub), "
                    "responsible_user_id = COALESCE(responsible_user_id, :responsible_user_id) "
                    "WHERE id = :company_id"
                ),
                {
                    "name": fields["name"],
                    "full_legal_form": fields["full_legal_form"],
                    "legal_form": fields["legal_form"],
                    "gender_ending_oe": fields["gender_ending_oe"],
                    "country_code": fields["country_code"],
                    "country": fields["country"],
                    "director_position": fields["director_position"],
                    "director_genitive": fields["director_genitive"],
                    "director_short": fields["director_short"],
                    "acts_basis": fields["acts_basis"],
                    "tax_id_label": fields["tax_id_label"],
                    "tax_id": fields["tax_id"],
                    "address": fields["address"],
                    "bank": fields["bank"],
                    "bank_code_label": fields["bank_code_label"],
                    "bank_code": fields["bank_code"],
                    "account": fields["account"],
                    "turnover_rub": fields["turnover_rub"],
                    "responsible_user_id": fields["responsible_user_id"],
                    "company_id": company_id,
                },
            )

    # ---- 2. Заполнить company_id на бизнес-сущностях по маппингу ----
    # Делаем set-based UPDATE через JOIN на crm_companies.counterparty_id —
    # идемпотентно (WHERE company_id IS NULL).
    conn.execute(
        sa.text(
            "UPDATE deals d SET company_id = c.id FROM crm_companies c "
            "WHERE d.counterparty_id = c.counterparty_id "
            "AND d.counterparty_id IS NOT NULL AND d.company_id IS NULL"
        )
    )
    conn.execute(
        sa.text(
            "UPDATE contracts ct SET company_id = c.id FROM crm_companies c "
            "WHERE ct.counterparty_id = c.counterparty_id "
            "AND ct.counterparty_id IS NOT NULL AND ct.company_id IS NULL"
        )
    )
    conn.execute(
        sa.text(
            "UPDATE client_subscriptions s SET company_id = c.id FROM crm_companies c "
            "WHERE s.counterparty_id = c.counterparty_id "
            "AND s.counterparty_id IS NOT NULL AND s.company_id IS NULL"
        )
    )
    conn.execute(
        sa.text(
            "UPDATE leads l SET converted_to_company_id = c.id FROM crm_companies c "
            "WHERE l.converted_to_counterparty_id = c.counterparty_id "
            "AND l.converted_to_counterparty_id IS NOT NULL "
            "AND l.converted_to_company_id IS NULL"
        )
    )

    # ---- 3. LegacyContact → crm_contacts + links ----
    # Кеш существующих crm_contacts по dedup-ключу (для переиспользования).
    contact_rows = conn.execute(
        sa.text("SELECT id, full_name, email, phone FROM crm_contacts")
    ).mappings().all()
    by_key: dict[str, int] = {}
    for r in contact_rows:
        k = _dedup_key(r["full_name"], r["email"], r["phone"])
        if k and k not in by_key:
            by_key[k] = r["id"]

    insert_contact = sa.text(
        "INSERT INTO crm_contacts "
        "(full_name, email, phone, position, owner_id, tg_username, notes, status, "
        "tags, extra_fields) "
        "VALUES (:full_name, :email, :phone, :position, :owner_id, :tg_username, "
        ":notes, 'active', '{}', '{}'::jsonb) RETURNING id"
    )
    upsert_link = sa.text(
        "INSERT INTO crm_contact_company_links "
        "(contact_id, company_id, position, employment_status, is_primary) "
        "VALUES (:contact_id, :company_id, :position, 'works', :is_primary) "
        "ON CONFLICT (contact_id, company_id) DO NOTHING"
    )

    legacy_rows = conn.execute(
        sa.text(
            "SELECT lc.id, lc.counterparty_id, lc.name, lc.position, lc.phone, "
            "lc.email, lc.messenger, lc.is_primary, lc.note, "
            "cp.owner_user_id AS cp_owner "
            "FROM contacts lc JOIN counterparties cp ON cp.id = lc.counterparty_id"
        )
    ).mappings().all()

    for lc in legacy_rows:
        company_id = cp_to_company.get(lc["counterparty_id"])
        if company_id is None:
            continue
        key = _dedup_key(lc["name"], lc["email"], lc["phone"])
        contact_id = by_key.get(key) if key else None
        if contact_id is None:
            contact_id = conn.execute(
                insert_contact,
                {
                    "full_name": (lc["name"] or "").strip() or "—",
                    "email": lc["email"],
                    "phone": lc["phone"],
                    "position": lc["position"],
                    "owner_id": lc["cp_owner"],
                    "tg_username": lc["messenger"],
                    "notes": lc["note"],
                },
            ).scalar_one()
            if key:
                by_key[key] = contact_id
        conn.execute(
            upsert_link,
            {
                "contact_id": contact_id,
                "company_id": company_id,
                "position": lc["position"],
                "is_primary": bool(lc["is_primary"]),
            },
        )

    # ---- 4. Существующие crm_contacts.company_id → links ----
    conn.execute(
        sa.text(
            "INSERT INTO crm_contact_company_links "
            "(contact_id, company_id, position, employment_status, is_primary) "
            "SELECT id, company_id, position, 'works', COALESCE(is_primary, false) "
            "FROM crm_contacts WHERE company_id IS NOT NULL "
            "ON CONFLICT (contact_id, company_id) DO NOTHING"
        )
    )


def _json(value: Any) -> str:
    import json
    return json.dumps(value or {})


def downgrade() -> None:
    # Data-migration необратима безопасно: company_id-колонки и созданные
    # Company/Contact/links удалит downgrade схемы (0069). Здесь чистим только
    # заполненные FK, чтобы повторный upgrade был чистым (best-effort).
    conn = op.get_bind()
    conn.execute(sa.text("UPDATE deals SET company_id = NULL"))
    conn.execute(sa.text("UPDATE contracts SET company_id = NULL"))
    conn.execute(sa.text("UPDATE client_subscriptions SET company_id = NULL"))
    conn.execute(sa.text("UPDATE leads SET converted_to_company_id = NULL"))
