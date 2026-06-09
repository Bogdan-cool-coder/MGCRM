"""Эпик 19 — Automation 2: branch-conditions evaluate_condition + validate_steps
для шагов if_else в Sequence (pure-function).

Без БД-фикстуры: проверяем pure-функции evaluate_condition,
validate_condition, _resolve_field_path, _try_numeric, validate_steps
для if_else step, build_entity_context.
"""
from __future__ import annotations

import pytest

from app.services.sequence_executor import (
    BRANCH_OPERATORS,
    MAX_BRANCH_DEPTH,
    SEQUENCE_STEP_KINDS,
    _resolve_field_path,
    _try_numeric,
    build_entity_context,
    evaluate_condition,
    validate_condition,
    validate_steps,
)


# ============ evaluate_condition operators ============


def test_evaluate_eq_numeric_match():
    """== оператор: 1000000 == 1000000 → True."""
    ctx = {"deal": {"amount": 1000000}}
    cond = {"field": "deal.amount", "operator": "==", "value": 1000000}
    assert evaluate_condition(cond, ctx) is True


def test_evaluate_eq_numeric_mismatch():
    """== оператор: 999 != 1000 → False."""
    ctx = {"deal": {"amount": 999}}
    cond = {"field": "deal.amount", "operator": "==", "value": 1000}
    assert evaluate_condition(cond, ctx) is False


def test_evaluate_eq_str_vs_int_coercion():
    """JSON-конфиг может иметь value="100" (str); поле — 100 (int); должно совпасть."""
    ctx = {"deal": {"amount": 100}}
    cond = {"field": "deal.amount", "operator": "==", "value": "100"}
    assert evaluate_condition(cond, ctx) is True


def test_evaluate_ne():
    """!= оператор."""
    ctx = {"deal": {"status": "won"}}
    cond = {"field": "deal.status", "operator": "!=", "value": "lost"}
    assert evaluate_condition(cond, ctx) is True
    cond["value"] = "won"
    assert evaluate_condition(cond, ctx) is False


def test_evaluate_gt():
    """> оператор."""
    ctx = {"deal": {"amount": 1500000}}
    cond = {"field": "deal.amount", "operator": ">", "value": 1000000}
    assert evaluate_condition(cond, ctx) is True
    cond["value"] = 2000000
    assert evaluate_condition(cond, ctx) is False


def test_evaluate_gte():
    """>= оператор: ровно == даёт True."""
    ctx = {"deal": {"amount": 1000000}}
    cond = {"field": "deal.amount", "operator": ">=", "value": 1000000}
    assert evaluate_condition(cond, ctx) is True


def test_evaluate_lt():
    """< оператор."""
    ctx = {"deal": {"amount": 50}}
    cond = {"field": "deal.amount", "operator": "<", "value": 100}
    assert evaluate_condition(cond, ctx) is True
    cond["value"] = 30
    assert evaluate_condition(cond, ctx) is False


def test_evaluate_lte():
    """<= оператор: ровно == даёт True."""
    ctx = {"deal": {"amount": 100}}
    cond = {"field": "deal.amount", "operator": "<=", "value": 100}
    assert evaluate_condition(cond, ctx) is True


def test_evaluate_in_list():
    """in оператор: value (list) содержит field_value."""
    ctx = {"deal": {"status": "won"}}
    cond = {"field": "deal.status", "operator": "in", "value": ["won", "lost"]}
    assert evaluate_condition(cond, ctx) is True
    cond["value"] = ["new", "pending"]
    assert evaluate_condition(cond, ctx) is False


def test_evaluate_in_value_must_be_list():
    """in: если value не list → False (нельзя сравнивать)."""
    ctx = {"deal": {"status": "won"}}
    cond = {"field": "deal.status", "operator": "in", "value": "won"}
    assert evaluate_condition(cond, ctx) is False


def test_evaluate_not_in():
    """not_in: True если value НЕ содержит field_value."""
    ctx = {"deal": {"status": "draft"}}
    cond = {
        "field": "deal.status", "operator": "not_in",
        "value": ["won", "lost"],
    }
    assert evaluate_condition(cond, ctx) is True


def test_evaluate_is_null():
    """is_null: True если поле None."""
    ctx = {"deal": {"closed_at": None}}
    cond = {"field": "deal.closed_at", "operator": "is_null"}
    assert evaluate_condition(cond, ctx) is True
    # Если значение есть → False
    ctx["deal"]["closed_at"] = "2026-05-30"
    assert evaluate_condition(cond, ctx) is False


def test_evaluate_is_not_null():
    """is_not_null: True если поле не None."""
    ctx = {"deal": {"closed_at": "2026-05-30"}}
    cond = {"field": "deal.closed_at", "operator": "is_not_null"}
    assert evaluate_condition(cond, ctx) is True
    ctx["deal"]["closed_at"] = None
    assert evaluate_condition(cond, ctx) is False


# ============ evaluate_condition edge cases ============


def test_evaluate_missing_field_returns_false():
    """Field не существует в context → False (fail-safe)."""
    ctx = {"deal": {}}
    cond = {"field": "deal.does_not_exist", "operator": "==", "value": 1}
    assert evaluate_condition(cond, ctx) is False


def test_evaluate_unknown_operator_returns_false():
    """Неизвестный operator → False (fail-safe)."""
    ctx = {"deal": {"amount": 100}}
    cond = {"field": "deal.amount", "operator": "regex", "value": ".*"}
    assert evaluate_condition(cond, ctx) is False


def test_evaluate_nonnumeric_comparison_returns_false():
    """> на строке → False (нельзя сравнивать)."""
    ctx = {"deal": {"title": "abc"}}
    cond = {"field": "deal.title", "operator": ">", "value": 100}
    assert evaluate_condition(cond, ctx) is False


def test_evaluate_no_field_returns_false():
    """Пустой/отсутствующий field → False."""
    ctx = {"deal": {"x": 1}}
    cond = {"operator": "==", "value": 1}
    assert evaluate_condition(cond, ctx) is False


def test_evaluate_with_subscription_context():
    """evaluate работает с любым target_type (deal/lead/subscription)."""
    ctx = {"subscription": {"fee_actual": 5000}}
    cond = {"field": "subscription.fee_actual", "operator": ">", "value": 1000}
    assert evaluate_condition(cond, ctx) is True


# ============ _resolve_field_path ============


def test_resolve_field_path_simple():
    """'a.b' разрешается через нестинг."""
    ctx = {"a": {"b": "value"}}
    assert _resolve_field_path(ctx, "a.b") == "value"


def test_resolve_field_path_deep():
    """'a.b.c' тоже работает."""
    ctx = {"a": {"b": {"c": 42}}}
    assert _resolve_field_path(ctx, "a.b.c") == 42


def test_resolve_field_path_missing_step_returns_none():
    """Любой отсутствующий шаг → None."""
    ctx = {"a": {"b": "x"}}
    assert _resolve_field_path(ctx, "a.c") is None
    assert _resolve_field_path(ctx, "x.y") is None


def test_resolve_field_path_non_dict_intermediate():
    """Промежуточный шаг не dict → None."""
    ctx = {"a": "not_a_dict"}
    assert _resolve_field_path(ctx, "a.b") is None


def test_resolve_field_path_empty():
    """Пустой path → None."""
    assert _resolve_field_path({"a": 1}, "") is None


# ============ _try_numeric ============


def test_try_numeric_passthrough_int():
    """int как есть."""
    assert _try_numeric(100) == 100


def test_try_numeric_passthrough_float():
    """float как есть."""
    assert _try_numeric(1.5) == 1.5


def test_try_numeric_str_to_int():
    """'100' → 100 (int)."""
    assert _try_numeric("100") == 100


def test_try_numeric_str_to_float():
    """'1.5' → 1.5 (float, потому что есть точка)."""
    assert _try_numeric("1.5") == 1.5


def test_try_numeric_bad_str_passthrough():
    """'abc' → 'abc' (не число)."""
    assert _try_numeric("abc") == "abc"


def test_try_numeric_bool_passthrough():
    """bool не сворачиваем в int (избегаем True==1)."""
    assert _try_numeric(True) is True
    assert _try_numeric(False) is False


def test_try_numeric_none_passthrough():
    """None как есть."""
    assert _try_numeric(None) is None


# ============ validate_condition ============


def test_validate_condition_ok():
    """Минимальный валидный condition."""
    ok, err = validate_condition({"field": "deal.amount", "operator": ">", "value": 100})
    assert ok is True
    assert err is None


def test_validate_condition_missing_field():
    """Без field → ошибка."""
    ok, err = validate_condition({"operator": "==", "value": 1})
    assert ok is False
    assert "field" in (err or "")


def test_validate_condition_unknown_operator():
    """Неизвестный operator → ошибка."""
    ok, err = validate_condition({"field": "x", "operator": "regex", "value": "."})
    assert ok is False
    assert "operator" in (err or "")


def test_validate_condition_in_requires_list_value():
    """in с не-list value → ошибка валидации."""
    ok, err = validate_condition({"field": "x", "operator": "in", "value": "abc"})
    assert ok is False


def test_validate_condition_in_with_list_ok():
    """in с list value → OK."""
    ok, _ = validate_condition({"field": "x", "operator": "in", "value": [1, 2]})
    assert ok is True


def test_validate_condition_is_null_no_value():
    """is_null без value → OK (value игнорируется)."""
    ok, _ = validate_condition({"field": "x", "operator": "is_null"})
    assert ok is True


# ============ validate_steps with if_else ============


def test_validate_steps_with_if_else_ok():
    """if_else шаг с валидной condition и обеими ветками — OK."""
    steps = [
        {
            "kind": "if_else",
            "condition": {"field": "deal.amount", "operator": ">=", "value": 1000000},
            "true_steps": [{"kind": "tg_notify", "config": {}, "delay_days": 0}],
            "false_steps": [{"kind": "wait", "config": {}, "delay_days": 1}],
        }
    ]
    ok, err = validate_steps(steps)
    assert ok is True, f"unexpected error: {err}"


def test_validate_steps_if_else_no_condition_fails():
    """if_else без condition → ошибка."""
    steps = [{"kind": "if_else", "true_steps": [], "false_steps": []}]
    ok, err = validate_steps(steps)
    assert ok is False
    assert "condition" in (err or "")


def test_validate_steps_if_else_invalid_condition_fails():
    """if_else с невалидной condition → ошибка."""
    steps = [
        {
            "kind": "if_else",
            "condition": {"operator": "==", "value": 1},  # нет field
            "true_steps": [],
            "false_steps": [],
        }
    ]
    ok, err = validate_steps(steps)
    assert ok is False


def test_validate_steps_if_else_empty_branches_ok():
    """if_else с пустыми true_steps/false_steps → OK (ветка-no-op разрешена)."""
    steps = [
        {
            "kind": "if_else",
            "condition": {"field": "deal.amount", "operator": ">", "value": 0},
            "true_steps": [],
            "false_steps": [],
        }
    ]
    ok, _ = validate_steps(steps)
    assert ok is True


def test_validate_steps_nested_if_else_fails():
    """Вложенный if_else (depth > MAX_BRANCH_DEPTH=1) → ошибка."""
    inner = {
        "kind": "if_else",
        "condition": {"field": "x", "operator": "==", "value": 1},
        "true_steps": [],
        "false_steps": [],
    }
    steps = [
        {
            "kind": "if_else",
            "condition": {"field": "deal.amount", "operator": ">", "value": 0},
            "true_steps": [inner],
            "false_steps": [],
        }
    ]
    ok, err = validate_steps(steps)
    assert ok is False
    assert "вложенность" in (err or "") or "depth" in (err or "").lower()


def test_validate_steps_mixed_with_if_else_ok():
    """Mix: wait → if_else → tg_notify — все валидны."""
    steps = [
        {"kind": "wait", "config": {}, "delay_days": 1},
        {
            "kind": "if_else",
            "condition": {"field": "deal.amount", "operator": ">", "value": 0},
            "true_steps": [{"kind": "tg_notify", "config": {}, "delay_days": 0}],
            "false_steps": [],
        },
        {"kind": "tg_notify", "config": {}, "delay_days": 0},
    ]
    ok, _ = validate_steps(steps)
    assert ok is True


# ============ build_entity_context ============


class _StubDeal:
    """Минимальный stub."""

    def __init__(self):
        self.id = 42
        self.title = "Test deal"
        self.amount = 1000000
        self.currency = "USD"
        self.status = "open"
        self.stage_id = 5
        self.pipeline_id = 1
        self.owner_user_id = 10
        self.counterparty_id = 7
        self.contract_id = None
        self.product_code = "saas"
        self.won = False
        self.lost = False


class _StubSubscription:
    def __init__(self):
        self.id = 99
        self.fee_actual = 5000
        self.fee_contract = 5500
        self.currency = "USD"
        self.health_tier = "A1"
        self.manual_tier_override = None
        self.lifecycle_stage_id = 3
        self.sup_pm_user_id = 11
        self.am_user_id = 12
        self.imp_pm_user_id = None
        self.platform_code = "macro"


def test_build_entity_context_deal():
    """build_entity_context для deal: {target_type: {field: value, ...}}."""
    ctx = build_entity_context("deal", _StubDeal())
    assert "deal" in ctx
    assert ctx["deal"]["id"] == 42
    assert ctx["deal"]["amount"] == 1000000
    assert ctx["deal"]["status"] == "open"


def test_build_entity_context_subscription():
    """build_entity_context для subscription."""
    ctx = build_entity_context("subscription", _StubSubscription())
    assert "subscription" in ctx
    assert ctx["subscription"]["fee_actual"] == 5000
    assert ctx["subscription"]["health_tier"] == "A1"


def test_build_entity_context_unknown_target_type():
    """Unknown target_type → {target_type: {}}."""
    ctx = build_entity_context("unknown", _StubDeal())
    assert ctx == {"unknown": {}}


def test_build_entity_context_decimal_converted_to_float():
    """Decimal значения конвертируются в float для JSON-friendly сравнения."""
    from decimal import Decimal

    class _Deal:
        def __init__(self):
            self.id = 1
            self.title = "t"
            self.amount = Decimal("1500.50")
            self.currency = "USD"
            self.status = "won"
            self.stage_id = 1
            self.pipeline_id = 1
            self.owner_user_id = 1
            self.counterparty_id = 1
            self.contract_id = 1
            self.product_code = "x"
            self.won = True
            self.lost = False

    ctx = build_entity_context("deal", _Deal())
    assert isinstance(ctx["deal"]["amount"], float)
    assert ctx["deal"]["amount"] == 1500.50


def test_build_entity_context_evaluate_combo():
    """Интеграция: build + evaluate работают вместе."""
    ctx = build_entity_context("deal", _StubDeal())
    cond = {"field": "deal.amount", "operator": ">=", "value": 1000000}
    assert evaluate_condition(cond, ctx) is True


# ============ Constants ============


def test_branch_operators_constant():
    """BRANCH_OPERATORS экспонирован как tuple для UI dropdown'а."""
    assert isinstance(BRANCH_OPERATORS, tuple)
    # Все основные операторы
    for op in ("==", "!=", ">", ">=", "<", "<=", "in", "not_in",
               "is_null", "is_not_null"):
        assert op in BRANCH_OPERATORS


def test_max_branch_depth_constant():
    """MAX_BRANCH_DEPTH=1 для MVP."""
    assert MAX_BRANCH_DEPTH == 1


def test_if_else_in_sequence_step_kinds():
    """if_else добавлен в SEQUENCE_STEP_KINDS."""
    assert "if_else" in SEQUENCE_STEP_KINDS
    # Старые kinds на месте
    assert "wait" in SEQUENCE_STEP_KINDS
    assert "tg_notify" in SEQUENCE_STEP_KINDS
    assert "email" in SEQUENCE_STEP_KINDS
    assert "create_task" in SEQUENCE_STEP_KINDS
