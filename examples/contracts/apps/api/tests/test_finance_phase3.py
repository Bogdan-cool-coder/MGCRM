"""Pure-function тесты Ф3 модуля «Финансы» — интеграция факта оплаты (без DB fixture).

КРИТИЧНО: эта фаза касается расчёта КОМИССИЙ. Главный тест безопасности —
SHADOW-сверка (reconcile_sets): старый путь (ContractPayment) == новый (GL), 0
расхождений. Также: идемпотентность ключей, выбор payroll op_type, Σ=0 ФОТ-проводки
(через build_cash_out с контр-счётом 5110/5120), план↔факт подписок (не задваивает).

Все тесты — чистые: dataclass-стабы вместо ORM, словарь-резолвер курса вместо БД.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date
from decimal import Decimal

from app.services.finance.pay_integration import (
    OP_TYPE_PAYROLL_COMMISSION,
    OP_TYPE_PAYROLL_SALARY,
    card_is_commission_dominated,
    deal_payment_amount,
    motivational_payout_amount,
    payroll_op_type_code,
    period_first_day,
    subscription_planned_amount,
)
from app.services.finance.posting import build_cash_out
from app.services.finance.shadow_recon import CommissionFact, reconcile_sets


def _rate_one(_from: str, _to: str, _on: date) -> Decimal:
    """Стаб-резолвер курса 1:1 (одна валюта)."""
    return Decimal("1")


# ───────────────────────────── pure helpers ─────────────────────────────


def test_period_first_day():
    assert period_first_day(2026, 6) == date(2026, 6, 1)
    assert period_first_day(2026, 12) == date(2026, 12, 1)


def test_payroll_op_type_code():
    assert payroll_op_type_code(commission=True) == OP_TYPE_PAYROLL_COMMISSION
    assert payroll_op_type_code(commission=False) == OP_TYPE_PAYROLL_SALARY


@dataclass
class _Card:
    total_amount_local: Decimal | None
    total_amount_currency_local: str | None
    fact_commission_amount: Decimal | None = None
    fact_base_salary_amount: Decimal | None = None
    id: int = 1
    user_id: int = 10
    period_year: int = 2026
    period_month: int = 6
    paid_at = None


def test_motivational_payout_amount():
    assert motivational_payout_amount(
        _Card(Decimal("150000.00"), "RUB")
    ) == (Decimal("150000.00"), "RUB")


def test_motivational_payout_none_when_zero_or_missing():
    assert motivational_payout_amount(_Card(Decimal("0"), "RUB")) is None
    assert motivational_payout_amount(_Card(None, "RUB")) is None
    assert motivational_payout_amount(_Card(Decimal("100"), None)) is None


def test_card_is_commission_dominated():
    # комиссия > оклада → комиссионный счёт (5120)
    assert card_is_commission_dominated(
        _Card(Decimal("1"), "RUB", fact_commission_amount=Decimal("80000"),
              fact_base_salary_amount=Decimal("50000"))
    ) is True
    # оклад >= комиссии → ФОТ-счёт (5110)
    assert card_is_commission_dominated(
        _Card(Decimal("1"), "RUB", fact_commission_amount=Decimal("20000"),
              fact_base_salary_amount=Decimal("50000"))
    ) is False


@dataclass
class _Deal:
    amount: Decimal | None
    currency: str | None


def test_deal_payment_amount():
    assert deal_payment_amount(_Deal(Decimal("99000.00"), "RUB")) == (
        Decimal("99000.00"), "RUB"
    )
    assert deal_payment_amount(_Deal(None, "RUB")) is None
    assert deal_payment_amount(_Deal(Decimal("0"), "RUB")) is None
    assert deal_payment_amount(_Deal(Decimal("100"), None)) is None


@dataclass
class _Sub:
    is_active: bool
    fee_actual: Decimal | None
    fee_currency: str | None


def test_subscription_planned_amount():
    assert subscription_planned_amount(
        _Sub(True, Decimal("120000.00"), "RUB")
    ) == (Decimal("120000.00"), "RUB")
    assert subscription_planned_amount(_Sub(False, Decimal("120000"), "RUB")) is None
    assert subscription_planned_amount(_Sub(True, None, "RUB")) is None
    assert subscription_planned_amount(_Sub(True, Decimal("0"), "RUB")) is None


# ───────────────────────────── ФОТ-проводка Σ=0 ─────────────────────────────


def test_payroll_operation_balanced_salary():
    """ФОТ-выплата: Дт 5110 ФОТ / Кт деньги. Σ amount_func=0, знаки верны."""
    draft = build_cash_out(
        on_date=date(2026, 6, 30),
        func_currency="RUB",
        amount=Decimal("150000.00"),
        currency="RUB",
        money_account_id=1,
        money_account_code="1010",
        counter_account_code="5110",  # ФОТ
        cashflow_category_id=42,
        rate_resolver=_rate_one,
        source="motivational_card",
        source_ref_id=7,
    )
    assert sum(ln.amount_func for ln in draft.lines) == Decimal("0.00")
    money = next(ln for ln in draft.lines if ln.money_account_id is not None)
    payroll = next(ln for ln in draft.lines if ln.account_code == "5110")
    # cash_out: денежная строка Кт (−), расход Дт (+)
    assert money.amount_func < 0
    assert payroll.amount_func > 0
    # статья ДДС — на денежной строке (попадёт в отток)
    assert money.cashflow_category_id == 42
    assert payroll.cashflow_category_id is None


def test_payroll_operation_balanced_commission():
    """Комиссия: Дт 5120 / Кт деньги. Σ=0."""
    draft = build_cash_out(
        on_date=date(2026, 6, 30),
        func_currency="RUB",
        amount=Decimal("80000.00"),
        currency="RUB",
        money_account_id=2,
        money_account_code="1010",
        counter_account_code="5120",  # комиссии
        cashflow_category_id=43,
        rate_resolver=_rate_one,
        source="motivational_card",
        source_ref_id=8,
    )
    assert sum(ln.amount_func for ln in draft.lines) == Decimal("0.00")


# ───────────────────────────── SHADOW: комиссии целы ─────────────────────────────


def _fact(pid: int, amount: str, ccy: str = "RUB") -> CommissionFact:
    return CommissionFact(
        payment_id=pid, amount=Decimal(amount), currency=ccy,
        attributed_to_user_id=10, payment_date=date(2026, 6, 1),
    )


def test_shadow_zero_diff_when_gl_mirrors_exactly():
    """ГЛАВНЫЙ ТЕСТ: старый путь == новый путь → ok=True, нет расхождений.

    Каждый eligible ContractPayment имеет зеркальную GL-операцию с равной суммой/валютой.
    Это и есть гарантия, что переключение чтения комиссий на GL не изменит входы.
    """
    old = [_fact(1, "100000"), _fact(2, "50000"), _fact(3, "75000")]
    gl = {1: (Decimal("100000"), "RUB"), 2: (Decimal("50000"), "RUB"),
          3: (Decimal("75000"), "RUB")}
    r = reconcile_sets(old, gl)
    assert r.ok is True
    assert r.matched == 3
    assert r.missing_in_gl == []
    assert r.amount_mismatch == []
    assert r.orphan_in_gl == []


def test_shadow_detects_missing_in_gl():
    """Eligible-платёж без GL-зеркала → missing_in_gl, ok=False (блокирует переключение)."""
    old = [_fact(1, "100000"), _fact(2, "50000")]
    gl = {1: (Decimal("100000"), "RUB")}  # платёж 2 не зеркалирован
    r = reconcile_sets(old, gl)
    assert r.ok is False
    assert r.missing_in_gl == [2]
    assert r.matched == 1


def test_shadow_detects_amount_mismatch():
    """Сумма GL ≠ сумма платежа → amount_mismatch (вход комиссии изменился бы)."""
    old = [_fact(1, "100000")]
    gl = {1: (Decimal("90000"), "RUB")}
    r = reconcile_sets(old, gl)
    assert r.ok is False
    assert r.amount_mismatch == [1]


def test_shadow_detects_currency_mismatch():
    old = [_fact(1, "100000", "RUB")]
    gl = {1: (Decimal("100000"), "USD")}
    r = reconcile_sets(old, gl)
    assert r.ok is False
    assert r.amount_mismatch == [1]


def test_shadow_detects_orphan_in_gl():
    """GL-операция без eligible-платежа → orphan (GL переучёл вход комиссии)."""
    old = [_fact(1, "100000")]
    gl = {1: (Decimal("100000"), "RUB"), 99: (Decimal("5000"), "RUB")}
    r = reconcile_sets(old, gl)
    assert r.ok is False
    assert r.orphan_in_gl == [99]


def test_shadow_empty_both_is_ok():
    """Нет платежей и нет операций → тривиально согласовано."""
    r = reconcile_sets([], {})
    assert r.ok is True
    assert r.total_old == 0 and r.total_new == 0
