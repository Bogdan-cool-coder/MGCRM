"""AI Assistant actions — pure-function тесты (без сети/БД).

Покрытие:
- TOOL_SCHEMAS: структура tool-схем (name/input_schema/required).
- validate_and_normalize_args: нормализация + валидация для 3 действий.
- missing_required: state-machine propose (когда хватает полей).
- build_action_summary / build_proposed_action: shape proposed_action.
"""
from __future__ import annotations

import pytest

from app.services.ai_assistant import (
    ACTION_REQUIRED_FIELDS,
    ACTION_TYPES,
    CONTRACT_PRODUCT_CODES,
    TASK_KINDS,
    TOOL_SCHEMAS,
    AssistantArgError,
    build_action_summary,
    build_proposed_action,
    missing_required,
    validate_and_normalize_args,
)


# ============ Tool schemas ============

def test_tool_schemas_cover_three_actions():
    names = {t["name"] for t in TOOL_SCHEMAS}
    assert names == set(ACTION_TYPES)
    assert len(TOOL_SCHEMAS) == 3


def test_tool_schemas_have_input_schema_and_required():
    for tool in TOOL_SCHEMAS:
        assert "name" in tool
        assert "description" in tool
        schema = tool["input_schema"]
        assert schema["type"] == "object"
        assert "properties" in schema
        assert "required" in schema


def test_tool_create_contract_requires_product_country_city():
    contract = next(t for t in TOOL_SCHEMAS if t["name"] == "create_contract")
    req = set(contract["input_schema"]["required"])
    assert req == {"product_code", "country_code", "city"}


# ============ Normalize: task ============

def test_normalize_task_defaults_kind_to_task():
    out = validate_and_normalize_args("create_task", {"title": "Позвонить"})
    assert out["title"] == "Позвонить"
    assert out["kind"] == "task"
    assert out["responsible_id"] is None
    assert out["target_type"] is None


def test_normalize_task_strips_whitespace():
    out = validate_and_normalize_args("create_task", {"title": "  X  "})
    assert out["title"] == "X"


def test_normalize_task_invalid_kind_raises():
    with pytest.raises(AssistantArgError):
        validate_and_normalize_args("create_task", {"title": "X", "kind": "email"})


def test_normalize_task_target_pair_required_together():
    with pytest.raises(AssistantArgError):
        validate_and_normalize_args(
            "create_task", {"title": "X", "target_type": "deal"}
        )
    with pytest.raises(AssistantArgError):
        validate_and_normalize_args("create_task", {"title": "X", "target_id": 5})


def test_normalize_task_valid_target_pair():
    out = validate_and_normalize_args(
        "create_task", {"title": "X", "target_type": "deal", "target_id": "7"}
    )
    assert out["target_type"] == "deal"
    assert out["target_id"] == 7


def test_normalize_task_invalid_target_type_raises():
    with pytest.raises(AssistantArgError):
        validate_and_normalize_args(
            "create_task", {"title": "X", "target_type": "spaceship", "target_id": 1}
        )


# ============ Normalize: deal ============

def test_normalize_deal_amount_and_currency():
    out = validate_and_normalize_args(
        "create_deal",
        {"title": "Сделка", "company_name": "ПТС", "amount": "1500.5", "currency": "usd"},
    )
    assert out["amount"] == 1500.5
    assert out["currency"] == "USD"


def test_normalize_deal_bad_amount_raises():
    with pytest.raises(AssistantArgError):
        validate_and_normalize_args(
            "create_deal", {"title": "X", "company_name": "Y", "amount": "много"}
        )


def test_normalize_deal_company_id_int_coerce():
    out = validate_and_normalize_args(
        "create_deal", {"title": "X", "company_id": "42"}
    )
    assert out["company_id"] == 42


# ============ Normalize: contract ============

def test_normalize_contract_lowercases_product_uppercases_country():
    out = validate_and_normalize_args(
        "create_contract",
        {"product_code": "MacroCRM", "country_code": "kz", "city": "Алматы",
         "company_name": "ПТС"},
    )
    assert out["product_code"] == "macrocrm"
    assert out["country_code"] == "KZ"


def test_normalize_contract_invalid_product_raises():
    with pytest.raises(AssistantArgError):
        validate_and_normalize_args(
            "create_contract",
            {"product_code": "sap", "country_code": "KZ", "city": "X"},
        )


def test_normalize_contract_invalid_country_raises():
    with pytest.raises(AssistantArgError):
        validate_and_normalize_args(
            "create_contract",
            {"product_code": "macrocrm", "country_code": "KAZ", "city": "X"},
        )


def test_unknown_action_raises():
    with pytest.raises(AssistantArgError):
        validate_and_normalize_args("create_invoice", {})


# ============ missing_required state-machine ============

def test_missing_required_task_only_title():
    assert missing_required("create_task", {"title": "X", "kind": "task"}) == []
    assert "title" in missing_required("create_task", {"title": None, "kind": "task"})


def test_missing_required_deal_needs_company():
    args = validate_and_normalize_args("create_deal", {"title": "X"})
    assert missing_required("create_deal", args) == ["company"]
    args2 = validate_and_normalize_args(
        "create_deal", {"title": "X", "company_name": "ПТС"}
    )
    assert missing_required("create_deal", args2) == []
    args3 = validate_and_normalize_args(
        "create_deal", {"title": "X", "company_id": 5}
    )
    assert missing_required("create_deal", args3) == []


def test_missing_required_contract_full_set():
    incomplete = validate_and_normalize_args(
        "create_contract", {"product_code": "macrocrm"}
    )
    miss = missing_required("create_contract", incomplete)
    assert "country_code" in miss
    assert "city" in miss
    assert "company" in miss

    complete = validate_and_normalize_args(
        "create_contract",
        {"product_code": "macrocrm", "country_code": "KZ", "city": "Алматы",
         "company_id": 3},
    )
    assert missing_required("create_contract", complete) == []


def test_action_required_fields_cover_all_actions():
    assert set(ACTION_REQUIRED_FIELDS.keys()) == set(ACTION_TYPES)


# ============ summary / proposed_action ============

def test_build_summary_deal_contains_company_and_amount():
    args = validate_and_normalize_args(
        "create_deal",
        {"title": "Поставка", "company_name": "ПТС", "amount": 100, "currency": "USD"},
    )
    s = build_action_summary("create_deal", args)
    assert "Поставка" in s
    assert "ПТС" in s
    assert "100" in s


def test_build_summary_task_kind_label():
    args = validate_and_normalize_args(
        "create_task", {"title": "Перезвонить", "kind": "call"}
    )
    s = build_action_summary("create_task", args)
    assert "Звонок" in s
    assert "Перезвонить" in s


def test_build_proposed_action_shape():
    args = validate_and_normalize_args(
        "create_task", {"title": "X", "kind": "task"}
    )
    pa = build_proposed_action("create_task", args)
    assert pa["type"] == "create_task"
    assert pa["args"] == args
    assert isinstance(pa["summary"], str) and pa["summary"]
    assert pa["title"] == "Создать задачу"


def test_constants_consistency():
    assert "task" in TASK_KINDS
    assert "macrocrm" in CONTRACT_PRODUCT_CODES
