"""Ф0 ЧАНК 2 — pure-ядро сервисов: fx-конвертация, баланс, trial balance, ДДС-инвариант,
нумерация, период-лок, fin_can. Без DB fixture: чистые функции + duck-typed stubs.
"""

from __future__ import annotations

from datetime import date
from decimal import Decimal

from app.services.finance.access import (
    PermissionRow,
    accessible_entities_from_rows,
    can_from_rows,
    role_default,
)
from app.services.finance.balance import sum_signed, trial_balance_ok
from app.services.finance.cashflow import classify_flow
from app.services.finance.fx import FxRateMissing, convert_strict
from app.services.finance.numbering import format_number
from app.services.finance.period_lock import is_period_locked

D = Decimal


# ───────────────────────────── fx (строгий курс) ─────────────────────────────


def test_convert_strict_rounds_half_up():
    # 100.005 * 1 → 100.01 (HALF_UP), знак сохраняется
    assert convert_strict(D("100.005"), D("1")) == D("100.01")
    assert convert_strict(D("-100.005"), D("1")) == D("-100.01")


def test_convert_strict_cross_rate():
    assert convert_strict(D("100.00"), D("470")) == D("47000.00")
    assert convert_strict(D("-100.00"), D("470")) == D("-47000.00")


def test_fx_rate_missing_carries_pair_and_date():
    # get_rate_strict бросает FxRateMissing — проверяем носимые поля (без БД)
    exc = FxRateMissing("USD", "KZT", date(2026, 5, 31))
    assert exc.from_ccy == "USD"
    assert exc.to_ccy == "KZT"
    assert exc.on_date == date(2026, 5, 31)
    assert "USD" in str(exc) and "KZT" in str(exc)


# ───────────────────────────── баланс / trial balance ─────────────────────────────


def test_sum_signed_multivalue():
    assert sum_signed([D("100"), D("-40"), D("-60")]) == D("0.00")
    assert sum_signed([D("120000.00"), D("-50000.00")]) == D("70000.00")


def test_trial_balance_ok_when_zero():
    assert trial_balance_ok([D("120000"), D("-100000"), D("-20000")]) is True


def test_trial_balance_fails_when_nonzero():
    assert trial_balance_ok([D("120000"), D("-100000")]) is False


def test_money_balance_derivation_pure():
    # остаток = initial + Σ знаковых строк (мультивалюта в func представлена amount_func)
    initial = D("500000")
    lines_amount = [D("120000"), D("-50000"), D("30000")]
    assert sum_signed([initial, *lines_amount]) == D("600000.00")


# ───────────────────────────── ДДС-инвариант ─────────────────────────────


def test_classify_flow_signs():
    assert classify_flow(D("100")) == "inflow"
    assert classify_flow(D("-100")) == "outflow"
    assert classify_flow(D("0")) == "neutral"


def test_cashflow_invariant_transfer_excluded_by_construction():
    """Документируем инвариант №8 на уровне предиката фильтра: строка попадает в ДДС
    ТОЛЬКО если money_account_id задан И cashflow_category_id задан. Перевод
    (cat=None) и accrual (money=None) исключены."""

    def in_cashflow(money_account_id, cashflow_category_id, status) -> bool:
        return (
            money_account_id is not None
            and cashflow_category_id is not None
            and status == "posted"
        )

    # перевод: денежная, но cat=None → исключён
    assert in_cashflow(1, None, "posted") is False
    # accrual: cat есть, но не денежная → исключён
    assert in_cashflow(None, 5, "posted") is False
    # reversed-оригинал → исключён по статусу
    assert in_cashflow(1, 5, "reversed") is False
    # нормальная денежная операция с статьёй → включена
    assert in_cashflow(1, 5, "posted") is True


# ───────────────────────────── нумерация ─────────────────────────────


def test_format_number_pads():
    assert format_number("ОП", 2026, 7) == "ОП-2026-00007"
    assert format_number("Ж", 2026, 123) == "Ж-2026-00123"


def test_format_number_large_value_no_truncation():
    assert format_number("СЧ", 2026, 100000) == "СЧ-2026-100000"


# ───────────────────────────── период-лок ─────────────────────────────


def test_period_locked_predicate():
    locked = {(2026, 5), (2026, 4)}
    assert is_period_locked(date(2026, 5, 31), locked) is True
    assert is_period_locked(date(2026, 5, 1), locked) is True
    assert is_period_locked(date(2026, 6, 1), locked) is False


# ───────────────────────────── fin_can (матрица прав) ─────────────────────────────


def test_role_default_cfo_superset_of_accountant():
    # cfo имеет всё accountant + close_period + manage_settings + view_management
    for cap in ("create_operation", "post_operation", "create_manual_journal", "view_journal"):
        assert role_default("accountant", cap) is True
        assert role_default("cfo", cap) is True
    # close_period / manage_settings / view_management — только cfo
    assert role_default("accountant", "close_period") is False
    assert role_default("cfo", "close_period") is True
    assert role_default("accountant", "manage_settings") is False
    assert role_default("cfo", "manage_settings") is True
    assert role_default("accountant", "view_management") is False
    assert role_default("cfo", "view_management") is True


def test_role_default_director_read_only():
    # director: только просмотр отчётов/управления/операций
    assert role_default("director", "view_reports") is True
    assert role_default("director", "view_management") is True
    assert role_default("director", "view_operations") is True
    # без ввода/постинга/настроек/журналов
    assert role_default("director", "create_operation") is False
    assert role_default("director", "post_operation") is False
    assert role_default("director", "create_manual_journal") is False
    assert role_default("director", "view_journal") is False
    assert role_default("director", "manage_settings") is False


def test_role_default_manager_only_view_operations():
    assert role_default("manager", "view_operations") is True
    assert role_default("manager", "create_operation") is False
    assert role_default("manager", "post_operation") is False
    assert role_default("manager", "view_reports") is False


def test_role_default_admin_all():
    for cap in ("close_period", "manage_settings", "post_manual_journal", "view_management"):
        assert role_default("admin", cap) is True


def test_can_from_rows_falls_back_to_role_default():
    # нет override-строк → дефолт роли
    assert can_from_rows(
        role="cfo", user_id=1, capability="close_period",
        legal_entity_id=None, rows=[],
    ) is True
    assert can_from_rows(
        role="director", user_id=2, capability="post_operation",
        legal_entity_id=None, rows=[],
    ) is False


def test_can_from_rows_user_override_beats_role():
    # роль manager не имеет create_operation, но user-override разрешает
    rows = [
        PermissionRow(role=None, user_id=7, legal_entity_id=None,
                      capability="create_operation", allowed=True),
    ]
    assert can_from_rows(
        role="manager", user_id=7, capability="create_operation",
        legal_entity_id=None, rows=rows,
    ) is True


def test_can_from_rows_role_override_deny():
    # role-override запрещает то, что дефолт разрешал
    rows = [
        PermissionRow(role="accountant", user_id=None, legal_entity_id=None,
                      capability="post_operation", allowed=False),
    ]
    assert can_from_rows(
        role="accountant", user_id=3, capability="post_operation",
        legal_entity_id=None, rows=rows,
    ) is False


def test_can_from_rows_entity_specific_beats_all_scope():
    # для юрлица 10 — запрет; для всех (NULL) — разрешено. Точное юрлицо выигрывает.
    rows = [
        PermissionRow(role="accountant", user_id=None, legal_entity_id=None,
                      capability="manage_accounts", allowed=True),
        PermissionRow(role="accountant", user_id=None, legal_entity_id=10,
                      capability="manage_accounts", allowed=False),
    ]
    assert can_from_rows(
        role="accountant", user_id=1, capability="manage_accounts",
        legal_entity_id=10, rows=rows,
    ) is False
    # другое юрлицо (5) → entity-specific(10) не применяется → NULL-правило True
    assert can_from_rows(
        role="accountant", user_id=1, capability="manage_accounts",
        legal_entity_id=5, rows=rows,
    ) is True


# ───── accessible_entities_from_rows (read-listing entity-scope, B7) ─────


def test_accessible_entities_null_scope_grant_returns_none():
    # роль с NULL-scope дефолтом view_operations → ограничения нет (None).
    # Это текущее поведение всех фин-ролей — листинги не меняются.
    assert accessible_entities_from_rows(
        role="accountant", user_id=1, capability="view_operations",
        entity_ids=[1, 2, 3], rows=[],
    ) is None


def test_accessible_entities_per_entity_restriction():
    # роль без NULL-scope права (deny на все), но allow на юрлицо 2 → [2].
    rows = [
        PermissionRow(role="accountant", user_id=None, legal_entity_id=None,
                      capability="view_operations", allowed=False),
        PermissionRow(role="accountant", user_id=None, legal_entity_id=2,
                      capability="view_operations", allowed=True),
    ]
    assert accessible_entities_from_rows(
        role="accountant", user_id=1, capability="view_operations",
        entity_ids=[1, 2, 3], rows=rows,
    ) == [2]


def test_accessible_entities_full_deny_returns_empty():
    # NULL-scope deny и нет per-entity allow → пустой список (видит ничего).
    rows = [
        PermissionRow(role="manager", user_id=None, legal_entity_id=None,
                      capability="view_journal", allowed=False),
    ]
    assert accessible_entities_from_rows(
        role="manager", user_id=9, capability="view_journal",
        entity_ids=[1, 2], rows=rows,
    ) == []


def test_accessible_entities_user_override_per_entity():
    # NULL-scope deny на роль, но user-override allow на юрлицо 5 → [5].
    rows = [
        PermissionRow(role="manager", user_id=None, legal_entity_id=None,
                      capability="view_operations", allowed=False),
        PermissionRow(role=None, user_id=42, legal_entity_id=5,
                      capability="view_operations", allowed=True),
    ]
    assert accessible_entities_from_rows(
        role="manager", user_id=42, capability="view_operations",
        entity_ids=[4, 5, 6], rows=rows,
    ) == [5]
