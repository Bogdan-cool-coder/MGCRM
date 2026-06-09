"""Финансы Ф0 (миграция C): сид юрлица + банк-счёта из licensor_entities (runtime).

Зона: МОДУЛЬ «ФИНАНСЫ» (finance-specialist). Data-миграция: читает существующие
`licensor_entities` в runtime и создаёт по каждой:
  • fin_legal_entity   — insert-missing по licensor_entity_id (kz→KZT, uz→UZS; база группы RUB);
  • fin_money_account  — первый банковский счёт (GL 1020, функц.валюта, initial_balance=0).

БЕЗ хардкода реквизитов — всё из таблицы licensor_entities. Маппинг — чистая функция
app.services.finance.legal_entity_seed (покрыта pure-тестами). Если licensor_entities
пуста (свежая БД до template-seed) — 0 строк, миграция не падает; юрлицо досоздаётся
повторным прогоном/ручным CRUD позже. Идемпотентно (insert-missing) под advisory-lock.

downgrade: удаляет только засеянные строки (с licensor_entity_id IS NOT NULL и их
money_account). Ручные юрлица/счета не трогает.

Revision ID: 0086_fin_legal_seed  (19 chars ≤32 ✓)
Revises: 0085_fin_reference
Create Date: 2026-06-03
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

from app.services.finance.legal_entity_seed import (
    licensor_row_to_entity,
    licensor_row_to_money_account,
)
from app.services.finance.seed_data import DEFAULT_BANK_GL_CODE

revision: str = "0086_fin_legal_seed"
down_revision: Union[str, None] = "0085_fin_reference"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_FIN_LEGAL = 91_002

#: Колонки licensor_entities, читаемые в runtime (только реально существующие в модели).
_LICENSOR_COLS = (
    "id", "country_code", "legal_form", "name", "tax_id", "tax_id_label",
    "bank", "bank_code", "account", "address",
)


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _SEED_LOCK_FIN_LEGAL})

    cols = ", ".join(_LICENSOR_COLS)
    rows = conn.execute(
        sa.text(f"SELECT {cols} FROM licensor_entities ORDER BY country_code")
    ).mappings().all()

    bank_gl_id = conn.execute(
        sa.text("SELECT id FROM fin_account_gl WHERE code = :c"), {"c": DEFAULT_BANK_GL_CODE}
    ).scalar()
    if bank_gl_id is None:
        # План счетов не засеян (не должно случаться: 0085 идёт раньше) — нечего привязывать.
        return

    for row in rows:
        payload = licensor_row_to_entity(dict(row))
        if payload is None:  # неизвестная страна — пропуск
            continue

        # 1. fin_legal_entity — insert-missing по licensor_entity_id.
        existing = conn.execute(
            sa.text("SELECT id FROM fin_legal_entity WHERE licensor_entity_id = :lid"),
            {"lid": payload["licensor_entity_id"]},
        ).scalar()
        if existing is None:
            le_id = conn.execute(
                sa.text(
                    """
                    INSERT INTO fin_legal_entity
                        (name, country_code, functional_currency, vat_enabled,
                         tax_regime, vat_recognition, tax_id, licensor_entity_id,
                         requisites_json, is_active, sort_order)
                    VALUES
                        (:name, :country_code, :functional_currency, :vat_enabled,
                         :tax_regime, :vat_recognition, :tax_id, :licensor_entity_id,
                         CAST(:requisites_json AS jsonb), true, 0)
                    RETURNING id
                    """
                ),
                {
                    "name": payload["name"],
                    "country_code": payload["country_code"],
                    "functional_currency": payload["functional_currency"],
                    "vat_enabled": payload["vat_enabled"],
                    "tax_regime": payload["tax_regime"],
                    "vat_recognition": payload["vat_recognition"],
                    "tax_id": payload["tax_id"],
                    "licensor_entity_id": payload["licensor_entity_id"],
                    "requisites_json": _to_json(payload["requisites_json"]),
                },
            ).scalar_one()
        else:
            le_id = existing

        # 2. первый fin_money_account (банк) — insert-missing по (legal_entity_id, name).
        ma = licensor_row_to_money_account(
            dict(row), le_id, bank_gl_id, payload["functional_currency"]
        )
        if ma is None:  # нет банка/счёта у лицензиара
            continue
        already = conn.execute(
            sa.text(
                "SELECT 1 FROM fin_money_account WHERE legal_entity_id = :le AND name = :name"
            ),
            {"le": le_id, "name": ma["name"]},
        ).scalar()
        if already is None:
            conn.execute(
                sa.text(
                    """
                    INSERT INTO fin_money_account
                        (legal_entity_id, gl_account_id, name, account_type,
                         currency, initial_balance, is_active, sort_order)
                    VALUES
                        (:legal_entity_id, :gl_account_id, :name, :account_type,
                         :currency, CAST(:initial_balance AS numeric), true, 0)
                    """
                ),
                ma,
            )


def _to_json(obj: dict) -> str:
    import json

    return json.dumps(obj, ensure_ascii=False)


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(sa.text("SELECT pg_advisory_xact_lock(:k)"), {"k": _SEED_LOCK_FIN_LEGAL})
    # Удаляем money-accounts засеянных юрлиц, затем сами засеянные юрлица.
    conn.execute(
        sa.text(
            """
            DELETE FROM fin_money_account
            WHERE legal_entity_id IN (
                SELECT id FROM fin_legal_entity WHERE licensor_entity_id IS NOT NULL
            )
            """
        )
    )
    conn.execute(sa.text("DELETE FROM fin_legal_entity WHERE licensor_entity_id IS NOT NULL"))
