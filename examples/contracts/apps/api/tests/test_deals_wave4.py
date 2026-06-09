"""Wave 4 — pure-function unit tests (без БД)."""
from __future__ import annotations

from decimal import Decimal

import pytest

from app.services.deals_wave4 import (
    DEAL_CARD_FIELDS_KEY,
    STAGE_REQUIRED_FIELDS_KEY,
    default_deal_card_config,
    deal_total,
    line_amount,
    missing_required_fields,
    normalize_deal_card_config,
    required_fields_for_stage,
    validate_deal_card_config,
)


# ---------- line_amount / deal_total ----------

def test_line_amount_basic():
    assert line_amount(2, 100) == Decimal("200.00")
    assert line_amount(Decimal("1.5"), Decimal("10")) == Decimal("15.00")
    assert line_amount(3, "99.99") == Decimal("299.97")


def test_deal_total_same_currency():
    lines = [(Decimal("200.00"), "USD"), (Decimal("50.00"), "USD")]
    assert deal_total(lines, "USD") == Decimal("250.00")


def test_deal_total_skips_other_currency():
    lines = [(Decimal("200.00"), "USD"), (Decimal("5000.00"), "KZT")]
    assert deal_total(lines, "USD") == Decimal("200.00")


def test_deal_total_currency_none_takes_first():
    lines = [(Decimal("100.00"), "KZT"), (Decimal("50.00"), "KZT")]
    assert deal_total(lines, None) == Decimal("150.00")


def test_deal_total_empty():
    assert deal_total([], "USD") == Decimal("0.00")


# ---------- deal-card-config ----------

def test_default_config_all_standard_visible_not_required():
    cfg = default_deal_card_config()
    fields = cfg[DEAL_CARD_FIELDS_KEY]
    assert any(f["field"] == "amount" for f in fields)
    assert all(f["visible"] for f in fields)
    assert all(not f["required"] for f in fields)
    assert cfg[STAGE_REQUIRED_FIELDS_KEY] == {}


def test_normalize_empty_returns_default():
    assert normalize_deal_card_config(None) == default_deal_card_config()
    assert normalize_deal_card_config({}) == default_deal_card_config()


def test_normalize_dedup_and_drops_invalid():
    raw = {
        DEAL_CARD_FIELDS_KEY: [
            {"field": "amount", "required": True},
            {"field": "amount", "required": False},  # dup → ignored
            {"no_field": "x"},                         # invalid → dropped
            {"field": "currency", "visible": False, "order": 5},
        ],
    }
    norm = normalize_deal_card_config(raw)
    fields = norm[DEAL_CARD_FIELDS_KEY]
    assert [f["field"] for f in fields] == ["amount", "currency"]
    assert fields[0]["required"] is True
    assert fields[1]["visible"] is False


def test_normalize_stage_required():
    raw = {
        DEAL_CARD_FIELDS_KEY: [{"field": "amount"}],
        STAGE_REQUIRED_FIELDS_KEY: {"7": ["amount", "currency"], "9": []},
    }
    norm = normalize_deal_card_config(raw)
    assert norm[STAGE_REQUIRED_FIELDS_KEY] == {"7": ["amount", "currency"]}


def test_validate_config_errors():
    assert validate_deal_card_config({DEAL_CARD_FIELDS_KEY: "nope"})
    assert validate_deal_card_config({DEAL_CARD_FIELDS_KEY: [{"x": 1}]})
    assert validate_deal_card_config({STAGE_REQUIRED_FIELDS_KEY: []})
    assert validate_deal_card_config({DEAL_CARD_FIELDS_KEY: [{"field": "amount"}]}) == []


# ---------- required-field validation ----------

def test_required_fields_pipeline_level():
    cfg = {DEAL_CARD_FIELDS_KEY: [
        {"field": "amount", "required": True},
        {"field": "currency", "required": False},
    ]}
    assert required_fields_for_stage(cfg, 7) == ["amount"]


def test_required_fields_stage_override_wins():
    cfg = {
        DEAL_CARD_FIELDS_KEY: [{"field": "amount", "required": True}],
        STAGE_REQUIRED_FIELDS_KEY: {"7": ["currency", "expected_sign_date"]},
    }
    assert required_fields_for_stage(cfg, 7) == ["currency", "expected_sign_date"]
    # этап без override → pipeline-level
    assert required_fields_for_stage(cfg, 9) == ["amount"]


def test_missing_required_fields_detects_empties():
    required = ["amount", "currency", "tags", "contacts"]
    values = {"amount": None, "currency": "USD", "tags": [], "contacts": [1]}
    assert missing_required_fields(required, values) == ["amount", "tags"]


def test_missing_required_fields_whitespace_string():
    assert missing_required_fields(["product"], {"product": "  "}) == ["product"]
    assert missing_required_fields(["product"], {"product": "X"}) == []


def test_missing_required_fields_zero_amount_is_present():
    # 0 — валидное значение (не пусто)
    assert missing_required_fields(["amount"], {"amount": Decimal("0")}) == []
