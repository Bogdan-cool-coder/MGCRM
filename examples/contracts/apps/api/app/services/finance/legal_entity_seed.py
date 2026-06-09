"""Сид юрлица из существующего `LicensorEntity` (J §5.2) — чистый маппинг.

Сама миграция читает `licensor_entities` в runtime и вызывает `licensor_row_to_entity`
для построения dict под insert. Здесь — только чистая функция (тестируема без БД),
без хардкода реквизитов: всё берётся из переданной строки лицензиара.
"""

from __future__ import annotations

from typing import Any

from app.services.finance.seed_data import (
    CURRENCY_BY_COUNTRY,
    VAT_DEFAULT_BY_COUNTRY,
)


def licensor_row_to_entity(row: dict[str, Any]) -> dict[str, Any] | None:
    """`licensor_entities`-строка → dict полей `fin_legal_entity` (insert-payload).

    Возвращает None, если страна неизвестна (нет функциональной валюты) → миграция
    пропускает такую строку с логом. Никакого хардкода реквизитов — всё из `row`.

    Ожидаемые ключи row (как в модели LicensorEntity): country_code, legal_form,
    name, tax_id, tax_id_label, bank, bank_code, account, address. Отсутствующие —
    трактуются как None (снимок реквизитов терпим к недостающим колонкам).
    """
    country = (row.get("country_code") or "").lower()
    func_cur = CURRENCY_BY_COUNTRY.get(country)
    if func_cur is None:
        return None

    vat_enabled = VAT_DEFAULT_BY_COUNTRY.get(country, False)
    legal_form = row.get("legal_form") or ""
    name = row.get("name") or ""
    display_name = f'{legal_form} «{name}»'.strip() if name else (legal_form or "Юрлицо")

    requisites = {
        "bank": row.get("bank"),
        "bank_code": row.get("bank_code"),
        "account": row.get("account"),
        "address": row.get("address"),
        "tax_id_label": row.get("tax_id_label"),
    }

    return {
        "name": display_name,
        "country_code": country,
        "functional_currency": func_cur,
        "vat_enabled": vat_enabled,
        "tax_regime": "vat_general" if vat_enabled else "no_vat",
        "vat_recognition": "by_shipment",
        "tax_id": row.get("tax_id"),
        "licensor_entity_id": row.get("id"),
        "requisites_json": requisites,
    }


def licensor_row_to_money_account(
    row: dict[str, Any], legal_entity_id: int, gl_account_id: int, func_currency: str
) -> dict[str, Any] | None:
    """`licensor_entities`-строка → dict первого банковского `fin_money_account`.

    Возвращает None, если у лицензиара нет банка/счёта (нечего создавать).
    `initial_balance=0` — реальный остаток бухгалтер вводит вручную через UI.
    """
    bank = row.get("bank")
    account = row.get("account")
    if not bank or not account:
        return None
    return {
        "legal_entity_id": legal_entity_id,
        "gl_account_id": gl_account_id,
        "name": f"{bank} ({account})",
        "account_type": "bank",
        "currency": func_currency,
        "initial_balance": "0",
    }
