"""Ф0 ЧАНК 1 — чистый маппинг licensor_entities → fin_legal_entity / money_account.

Без БД: проверяет country→currency, vat-default, снимок реквизитов, идемпотентность
формы (повторный вызов на той же строке даёт идентичный payload), пропуск неизвестной
страны, отсутствие хардкода (имя/БИН/банк берутся из строки).
"""

from __future__ import annotations

from app.services.finance.legal_entity_seed import (
    licensor_row_to_entity,
    licensor_row_to_money_account,
)

_KZ_ROW = {
    "id": 7,
    "country_code": "kz",
    "legal_form": "ТОО",
    "name": "Пропхесервис",
    "tax_id": "123456789012",
    "tax_id_label": "БИН",
    "bank": "АО «Каспи Банк»",
    "bank_code": "CASPKZKA",
    "account": "KZ123456789",
    "address": "г. Алматы",
}


def test_kz_maps_to_kzt_vat_enabled():
    out = licensor_row_to_entity(_KZ_ROW)
    assert out is not None
    assert out["functional_currency"] == "KZT"
    assert out["vat_enabled"] is True
    assert out["tax_regime"] == "vat_general"
    assert out["vat_recognition"] == "by_shipment"
    assert out["licensor_entity_id"] == 7


def test_name_built_from_row_not_hardcoded():
    out = licensor_row_to_entity(_KZ_ROW)
    assert out is not None
    assert out["name"] == 'ТОО «Пропхесервис»'
    assert out["tax_id"] == "123456789012"


def test_requisites_snapshot_from_row():
    out = licensor_row_to_entity(_KZ_ROW)
    assert out is not None
    req = out["requisites_json"]
    assert req["bank"] == "АО «Каспи Банк»"
    assert req["account"] == "KZ123456789"
    assert req["address"] == "г. Алматы"
    assert req["tax_id_label"] == "БИН"


def test_unknown_country_skipped():
    row = dict(_KZ_ROW, country_code="de")
    assert licensor_row_to_entity(row) is None


def test_uz_maps_to_uzs():
    row = dict(_KZ_ROW, country_code="uz", id=9)
    out = licensor_row_to_entity(row)
    assert out is not None
    assert out["functional_currency"] == "UZS"


def test_mapping_is_deterministic():
    a = licensor_row_to_entity(_KZ_ROW)
    b = licensor_row_to_entity(_KZ_ROW)
    assert a == b  # форма идемпотентна → insert-missing повторно даст тот же payload


def test_missing_optional_columns_tolerated():
    row = {"id": 1, "country_code": "kz", "legal_form": "ТОО", "name": "X"}
    out = licensor_row_to_entity(row)
    assert out is not None
    assert out["requisites_json"]["bank"] is None
    assert out["tax_id"] is None


def test_money_account_from_row():
    ma = licensor_row_to_money_account(_KZ_ROW, legal_entity_id=3, gl_account_id=2, func_currency="KZT")
    assert ma is not None
    assert ma["legal_entity_id"] == 3
    assert ma["gl_account_id"] == 2
    assert ma["currency"] == "KZT"
    assert ma["account_type"] == "bank"
    assert ma["initial_balance"] == "0"
    assert "Каспи" in ma["name"] and "KZ123456789" in ma["name"]


def test_money_account_none_without_bank():
    row = dict(_KZ_ROW, bank=None)
    assert licensor_row_to_money_account(row, 3, 2, "KZT") is None
    row2 = dict(_KZ_ROW, account=None)
    assert licensor_row_to_money_account(row2, 3, 2, "KZT") is None
