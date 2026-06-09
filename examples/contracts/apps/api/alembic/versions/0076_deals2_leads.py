"""DEALS 2.0 (Ф0) — миграция Lead → Company + Deal + seed причин отказа.

Идемпотентная data-миграция. Для каждого Lead, у которого ещё нет связанного
Deal (lead.converted_deal_id IS NULL), создаёт:

1. Company (если lead.converted_to_company_id пуст) из полей лида + Counterparty-
   зеркало (тот же faithful-mirror, что в Contacts 2.0 / 0073).
2. Deal в воронке «Продажи» на этапе по маппингу lead.stage/status → sales-code
   (lead_to_deal_fields). owner_user_id ← lead.owner_id, title ← lead.name.
3. Проставляет lead.converted_to_company_id / converted_deal_id /
   converted_to_counterparty_id / status='converted' / converted_at.

🟢 Lead-таблица НЕ дропается (deprecate). Идемпотентно: повторный прогон
пропускает лиды с уже заполненным converted_deal_id.

Также сидит реестр причин отказа (DEFAULT_LOST_REASONS) — insert-missing.

Advisory-lock seed-key 74_003 (DEALS 2.0 leads migration).

Revision ID: 0076_deals2_leads  (17 chars ≤32 ✓)
Revises: 0075_deals2_pipeline
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

from app.services.contacts_v2 import company_to_counterparty_fields
from app.services.deals_v2 import (
    DEFAULT_LOST_REASONS,
    lead_to_company_fields,
    lead_to_deal_fields,
)

revision: str = "0076_deals2_leads"
down_revision: Union[str, None] = "0075_deals2_pipeline"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_DEALS2_LEADS = 74_003


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_DEALS2_LEADS},
    )

    _seed_lost_reasons(conn)
    _migrate_leads(conn)


def _seed_lost_reasons(conn) -> None:
    existing = set(
        conn.execute(sa.text("SELECT name FROM lost_reasons")).scalars().all()
    )
    for name, order in DEFAULT_LOST_REASONS:
        if name in existing:
            continue
        conn.execute(
            sa.text(
                "INSERT INTO lost_reasons (name, sort_order, is_active) "
                "VALUES (:name, :order, true)"
            ),
            {"name": name, "order": order},
        )


def _migrate_leads(conn) -> None:
    # Воронка «Продажи» + карта code → stage_id.
    pipe_id = conn.execute(
        sa.text(
            "SELECT id FROM pipelines WHERE kind = 'sales' "
            "ORDER BY (name = 'Продажи') DESC, id LIMIT 1"
        )
    ).scalar()
    if pipe_id is None:
        return  # нет sales-воронки — нечего привязывать (не должно случаться)

    stage_by_code: dict[str, int] = {
        r["code"]: r["id"]
        for r in conn.execute(
            sa.text(
                "SELECT id, code FROM pipeline_stages "
                "WHERE pipeline_id = :pid AND code IS NOT NULL"
            ),
            {"pid": pipe_id},
        ).mappings().all()
    }
    if not stage_by_code:
        return

    # Лиды без созданного Deal.
    leads = conn.execute(
        sa.text(
            "SELECT l.id, l.name, l.contact_email, l.contact_phone, l.source, "
            "       l.tags, l.owner_id, l.department_id, l.status, "
            "       l.converted_to_company_id, l.converted_to_counterparty_id, "
            "       s.code AS stage_code "
            "FROM leads l "
            "LEFT JOIN pipeline_stages s ON s.id = l.stage_id "
            "WHERE l.converted_deal_id IS NULL"
        )
    ).mappings().all()

    insert_company = sa.text(
        "INSERT INTO crm_companies (legal_name, name, email, phone, source, "
        " country, tax_id, tags, owner_user_id, department_id, extra_fields) "
        "VALUES (:legal_name, :name, :email, :phone, :source, "
        " NULL, NULL, CAST(:tags AS text[]), :owner_id, :department_id, '{}'::jsonb) "
        "RETURNING id"
    )

    insert_cp = sa.text(
        "INSERT INTO counterparties (name, country_code, phone, email, "
        " owner_user_id, department_id, extra_fields) "
        "VALUES (:name, :country_code, :phone, :email, "
        " :owner_user_id, :department_id, '{}'::jsonb) RETURNING id"
    )

    insert_deal = sa.text(
        "INSERT INTO deals (pipeline_id, stage_id, company_id, counterparty_id, "
        " title, owner_user_id, department_id, stage_changed_at, extra_fields) "
        "VALUES (:pipeline_id, :stage_id, :company_id, :counterparty_id, "
        " :title, :owner_user_id, :department_id, now(), '{}'::jsonb) RETURNING id"
    )

    for lead in leads:
        lead_d = dict(lead)
        # stage_code для маппинга — из join'а или None.
        lead_d["stage_code"] = lead["stage_code"]

        deal_fields = lead_to_deal_fields(lead_d)
        sales_code = deal_fields["sales_stage_code"]
        stage_id = stage_by_code.get(sales_code) or stage_by_code.get("new")
        if stage_id is None:
            continue

        # ---- Company + Counterparty-зеркало ----
        company_id = lead["converted_to_company_id"]
        counterparty_id = lead["converted_to_counterparty_id"]

        if company_id is None:
            cfields = lead_to_company_fields(lead_d)
            company_id = conn.execute(
                insert_company,
                {
                    "legal_name": cfields["legal_name"],
                    "name": cfields["name"],
                    "email": cfields["email"],
                    "phone": cfields["phone"],
                    "source": cfields["source"],
                    "tags": cfields["tags"],
                    "owner_id": lead["owner_id"],
                    "department_id": lead["department_id"],
                },
            ).scalar()

            # Counterparty-зеркало (faithful mirror, как в 0073).
            mirror = company_to_counterparty_fields(
                {
                    "name": cfields["name"],
                    "legal_name": cfields["legal_name"],
                    "email": cfields["email"],
                    "phone": cfields["phone"],
                    "owner_user_id": lead["owner_id"],
                    "department_id": lead["department_id"],
                }
            )
            counterparty_id = conn.execute(
                insert_cp,
                {
                    "name": mirror["name"],
                    "country_code": mirror["country_code"],
                    "phone": mirror.get("phone"),
                    "email": mirror.get("email"),
                    "owner_user_id": mirror.get("owner_user_id"),
                    "department_id": mirror.get("department_id"),
                },
            ).scalar()
            conn.execute(
                sa.text(
                    "UPDATE crm_companies SET counterparty_id = :cp WHERE id = :cid"
                ),
                {"cp": counterparty_id, "cid": company_id},
            )
        elif counterparty_id is None:
            # Company уже есть — берём её counterparty-зеркало.
            counterparty_id = conn.execute(
                sa.text("SELECT counterparty_id FROM crm_companies WHERE id = :cid"),
                {"cid": company_id},
            ).scalar()

        # ---- Deal ----
        deal_id = conn.execute(
            insert_deal,
            {
                "pipeline_id": pipe_id,
                "stage_id": stage_id,
                "company_id": company_id,
                "counterparty_id": counterparty_id,
                "title": deal_fields["title"],
                "owner_user_id": deal_fields["owner_user_id"],
                "department_id": deal_fields["department_id"],
            },
        ).scalar()

        # ---- Обратные связи на Lead ----
        conn.execute(
            sa.text(
                "UPDATE leads SET converted_to_company_id = :cid, "
                " converted_to_counterparty_id = :cp, converted_deal_id = :did, "
                " status = 'converted', converted_at = now() "
                "WHERE id = :lid"
            ),
            {"cid": company_id, "cp": counterparty_id, "did": deal_id, "lid": lead["id"]},
        )


def downgrade() -> None:
    # Data-миграция необратима безопасно: созданные Company/Counterparty/Deal
    # могут быть уже изменены пользователями, удалять их по факту опасно.
    # downgrade — no-op (согласовано с практикой проекта, см. 0073).
    pass
