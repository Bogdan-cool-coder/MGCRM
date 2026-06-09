"""Pure-тесты отчётов ядра GL (Ф1) — services/finance/reports.py.

Без DB fixture: проверяем чистые ядра агрегации (pnl_from_rows, trial_balance_rows,
arap_from_rows) на duck-typed строках. Инварианты: доход−расход, transfer/reversed
исключены by construction (в ядро попадают только агрегаты income/expense-строк),
Σ Дт = Σ Кт в trial balance, знаки AR/AP-сальдо. Деньги — Decimal.
"""
from __future__ import annotations

from decimal import Decimal

import pytest
from pydantic import ValidationError

from app.routers.finance import _normalize_q
from app.schemas_finance import PermissionCreate, PermissionPatch
from app.services.finance import reports as R


def _agg(aid, code, name, atype, s):
    return R._AggRow(aid, code, name, atype, Decimal(s))


def _tb(aid, code, name, atype, d, c):
    return R._TbAggRow(aid, code, name, atype, Decimal(d), Decimal(c))


def _arap(cp, aid, code, name, s):
    return R._ArApAggRow(cp, aid, code, name, Decimal(s))


# ───────────────────────────── P&L-lite ─────────────────────────────


def test_pnl_income_minus_expense():
    """Прибыль = доход − расход; доход (Кт<0) и расход (Дт>0) → положительны в отчёте."""
    rows = [
        _agg(1, "4010", "MRR", "income", "-100000.00"),  # Кт −100k → доход 100k
        _agg(2, "4030", "Услуги", "income", "-20000.00"),  # доход 20k
        _agg(3, "5110", "ФОТ", "expense", "70000.00"),  # Дт 70k → расход 70k
        _agg(4, "5210", "Хостинг", "expense", "5000.00"),  # расход 5k
    ]
    rep = R.pnl_from_rows(rows)
    assert rep.total_income == Decimal("120000.00")
    assert rep.total_expense == Decimal("75000.00")
    assert rep.profit == Decimal("45000.00")
    assert [ln.amount for ln in rep.income_lines] == [
        Decimal("100000.00"), Decimal("20000.00")
    ]
    assert all(ln.amount > 0 for ln in rep.expense_lines)


def test_pnl_empty_is_zero():
    rep = R.pnl_from_rows([])
    assert rep.total_income == Decimal("0")
    assert rep.total_expense == Decimal("0")
    assert rep.profit == Decimal("0")


def test_pnl_loss_negative_profit():
    rows = [
        _agg(1, "4010", "MRR", "income", "-10000.00"),
        _agg(2, "5110", "ФОТ", "expense", "30000.00"),
    ]
    rep = R.pnl_from_rows(rows)
    assert rep.profit == Decimal("-20000.00")


def test_pnl_ignores_non_income_expense():
    """В ядро могут прийти только income/expense (фильтр SQL); чужие типы игнорятся."""
    rows = [
        _agg(1, "4010", "MRR", "income", "-1000.00"),
        _agg(9, "1020", "Банк", "asset", "5000.00"),  # не должно учитываться
    ]
    rep = R.pnl_from_rows(rows)
    assert rep.total_income == Decimal("1000.00")
    assert rep.expense_lines == []
    assert rep.profit == Decimal("1000.00")


def test_pnl_transfer_reversed_excluded_by_construction():
    """Перевод (нет income/expense-строки) и reversed (не posted) не дают строк в
    SQL-агрегате → в ядро попадает только реальный доход. Моделируем: единственный
    доход, переводных/сторно-строк по income/expense просто НЕТ."""
    rows = [_agg(1, "4030", "Услуги", "income", "-500.00")]
    rep = R.pnl_from_rows(rows)
    assert rep.total_income == Decimal("500.00")
    assert rep.profit == Decimal("500.00")


# ───────────────────────────── Trial balance ─────────────────────────────


def test_trial_balance_debit_equals_credit():
    """Σ Дт = Σ Кт ⇒ is_balanced. credit_sum приходит отрицательной → |..| в отчёте."""
    rows = [
        _tb(1, "1020", "Банк", "asset", "100000.00", "0.00"),  # Дт 100k
        _tb(2, "4010", "MRR", "income", "0.00", "-100000.00"),  # Кт 100k
    ]
    rep = R.trial_balance_rows(rows)
    assert rep.total_debit == Decimal("100000.00")
    assert rep.total_credit == Decimal("100000.00")
    assert rep.is_balanced is True
    assert rep.rows[0].balance == Decimal("100000.00")  # 1020 Дт-сальдо
    assert rep.rows[1].balance == Decimal("-100000.00")  # 4010 Кт-сальдо


def test_trial_balance_mixed_account_both_sides():
    """Счёт с оборотами и Дт и Кт: debit и credit одновременно, balance = разница."""
    rows = [_tb(1, "1020", "Банк", "asset", "30000.00", "-12000.00")]
    rep = R.trial_balance_rows(rows)
    r = rep.rows[0]
    assert r.debit == Decimal("30000.00")
    assert r.credit == Decimal("12000.00")
    assert r.balance == Decimal("18000.00")


def test_trial_balance_skips_zero_turnover():
    rows = [
        _tb(1, "1020", "Банк", "asset", "0.00", "0.00"),  # пропускается
        _tb(2, "5110", "ФОТ", "expense", "5000.00", "0.00"),
    ]
    rep = R.trial_balance_rows(rows)
    assert len(rep.rows) == 1
    assert rep.rows[0].account_code == "5110"


def test_trial_balance_unbalanced_detected():
    """Если Σ Дт ≠ Σ Кт — is_balanced False (детектор багов поверх БД-триггера)."""
    rows = [
        _tb(1, "1020", "Банк", "asset", "100.00", "0.00"),
        _tb(2, "4010", "MRR", "income", "0.00", "-90.00"),  # дисбаланс 10
    ]
    rep = R.trial_balance_rows(rows)
    assert rep.is_balanced is False
    assert rep.total_debit - rep.total_credit == Decimal("10.00")


# ───────────────────────────── AR/AP-сальдо ─────────────────────────────


def test_arap_signs_preserved():
    """AR положителен (нам должны, Дт>0), AP отрицателен (мы должны, Кт<0)."""
    ar = R.arap_from_rows([
        _arap(10, 1, "1210", "AR", "50000.00"),
        _arap(11, 1, "1210", "AR", "12000.00"),
    ])
    assert [r.balance for r in ar] == [Decimal("50000.00"), Decimal("12000.00")]
    ap = R.arap_from_rows([_arap(20, 2, "2110", "AP", "-30000.00")])
    assert ap[0].balance == Decimal("-30000.00")


def test_arap_drops_zero_balances():
    """Полностью погашенный контрагент (сальдо 0) не показывается."""
    rows = R.arap_from_rows([
        _arap(10, 1, "1210", "AR", "0.00"),
        _arap(11, 1, "1210", "AR", "100.00"),
    ])
    assert len(rows) == 1
    assert rows[0].counterparty_company_id == 11


def test_arap_null_counterparty_kept():
    """Строка без контрагента (NULL) сохраняется — это «прочая» дебиторка без привязки."""
    rows = R.arap_from_rows([_arap(None, 1, "1290", "Прочая AR", "777.00")])
    assert rows[0].counterparty_company_id is None
    assert rows[0].balance == Decimal("777.00")


# ───────────────────────────── q-поиск операций (предикат фильтра) ─────────────────────────────


def test_normalize_q_applies_filter_for_real_term():
    assert _normalize_q("ФОТ") == "ФОТ"
    assert _normalize_q("  оплата  ") == "оплата"  # trim


def test_normalize_q_none_for_empty_or_blank():
    assert _normalize_q(None) is None
    assert _normalize_q("") is None
    assert _normalize_q("   ") is None  # только пробелы → фильтр НЕ применяется


# ───────────────────────────── Права-CRUD (валидаторы) ─────────────────────────────


def test_permission_create_role_only_ok():
    p = PermissionCreate(role="accountant", capability="view_reports")
    assert p.role == "accountant"
    assert p.user_id is None


def test_permission_create_user_only_ok():
    p = PermissionCreate(user_id=42, capability="post_operation", allowed=False)
    assert p.user_id == 42
    assert p.allowed is False


def test_permission_create_rejects_both_role_and_user():
    with pytest.raises(ValidationError):
        PermissionCreate(role="cfo", user_id=42, capability="view_reports")


def test_permission_create_rejects_neither_role_nor_user():
    with pytest.raises(ValidationError):
        PermissionCreate(capability="view_reports")


def test_permission_patch_allowed_required():
    assert PermissionPatch(allowed=True).allowed is True
    with pytest.raises(ValidationError):
        PermissionPatch()


# ───────────────────── B7 CRIT-1: сторно нетит в 0 (status-фильтр) ─────────────────────


def test_b7_projection_status_filter_includes_reversed():
    """Все проекции должны фильтровать status IN ('posted','reversed'), НЕ только posted.

    Это инвариант фикса B7 CRIT-1: оригинал сторно помечен 'reversed', но его строки
    реальны и обязаны участвовать в сумме — иначе в проекции остаётся лишь зеркало
    сторно (−original вместо 0). 'draft' исключён.
    """
    from app.services.finance import balance as B
    from app.services.finance import cashflow as C
    from app.services.finance import reports as Rep
    from app.services.finance import revaluation as Rev
    from app.services.finance import vat_books as V

    expected = ("posted", "reversed")
    assert Rep._LIVE_STATUSES == expected
    assert C._LIVE_STATUSES == expected
    assert B._LIVE_STATUSES == expected
    assert V._LIVE_STATUSES == expected
    assert Rev._LIVE_STATUSES == expected
    # idempotency-guard переоценки намеренно остаётся на чистом 'posted'
    assert Rev._POSTED == "posted"


def test_b7_pnl_reversed_pair_nets_to_zero():
    """Оригинал income-строки (Кт −1000) + её сторно (Дт +1000) в P&L-ядре → доход 0.

    Когда ОБЕ половины (posted-оригинал-через-reversed + posted-reversal) попадают в
    агрегат по счёту, их Σ amount_func = 0 → доход счёта = 0. До фикса оригинал
    исключался и оставалось только зеркало (+1000 в Дт → доход −1000).
    """
    # агрегат по income-счёту: −1000 (оригинал, Кт) + (+1000) (сторно, Дт) = 0
    rep = R.pnl_from_rows([_agg(24, "4090", "Прочие доходы", "income", "0.00")])
    assert rep.total_income == Decimal("0.00")
    assert rep.profit == Decimal("0.00")


def test_b7_trial_balance_reversed_pair_zero_balance():
    """Счёт со сторнированной парой: дебет=кредит=1000, сальдо 0 (нетит)."""
    rows = [
        _tb(1, "1010", "Касса", "asset", "1000.00", "-1000.00"),  # приход + сторно
    ]
    rep = R.trial_balance_rows(rows)
    assert rep.is_balanced
    assert rep.rows[0].balance == Decimal("0.00")
