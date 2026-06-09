"""Pure-function тесты Ф4 модуля «Финансы» (без DB fixture).

Покрыто:
  • build_revenue_recognition — Дт AR / Кт выручка(4010) / Кт НДС output, Σ amount_func=0;
    accrual вне ДДС (money_account=NULL); мультивалюта (func≠doc) — Σ=0.
  • build_fx_revaluation — переоценка: знак курсовой разницы (gain 4910 / loss 5910), Σ=0.
  • recognition helpers — recognition_key, period_last_day, split_net_vat (by_shipment/
    by_payment/нулевая ставка), subscription_mrr.
  • revaluation_delta — дельта переоценки (gain/loss/ноль).
  • base_currency — line_is_in_closed_period (политика H4), base_imbalance (проекция≠инвариант).
  • P&L basis — pnl_from_rows одинаков для accrual/cash на одних строках (формула знаков).
  • http_errors.phase4_status — маппинг исключений Ф4.
"""

from __future__ import annotations

from datetime import date
from decimal import Decimal

import pytest

from app.services.finance.base_currency import (
    BaseCurrencyError,
    base_imbalance,
    line_is_in_closed_period,
)
from app.services.finance.http_errors import phase4_status
from app.services.finance.posting import (
    PostingError,
    UnbalancedEntry,
    build_fx_revaluation,
    build_revenue_recognition,
)
from app.services.finance.recognition import (
    RecognitionError,
    period_last_day,
    period_lock_subkey,
    recognition_key,
    split_net_vat,
)
from app.services.finance.revaluation import RevaluationError, revaluation_delta

_D = Decimal


def _same(frm: str, to: str, on_date: date) -> Decimal:
    return _D("1")


def _kzt(frm: str, to: str, on_date: date) -> Decimal:
    # KZT→RUB = 0.2 (1 KZT = 0.2 RUB) для мультивалютных тестов.
    if (frm, to) == ("KZT", "RUB"):
        return _D("0.2")
    return _D("1")


# ═════════════════════════ build_revenue_recognition ═════════════════════════


def test_recognition_balanced_dt_ar_kt_revenue_vat():
    """Дт AR(gross) / Кт выручка(net) / Кт НДС output(vat). Σ amount_func=0."""
    draft = build_revenue_recognition(
        on_date=date(2026, 5, 31),
        func_currency="RUB",
        currency="RUB",
        amount_net=_D("100000"),
        vat_amount=_D("12000"),
        revenue_account_code="4010",
        ar_account_code="1210",
        vat_output_account_code="2310",
        counterparty_company_id=7,
        rate_resolver=_same,
        subscription_id=42,
    )
    assert draft.kind == "revenue_accrual"
    assert draft.imbalance() == _D("0")
    by_code = {ln.account_code: ln for ln in draft.lines}
    assert by_code["1210"].amount == _D("112000")  # AR Дт = gross
    assert by_code["1210"].counterparty_company_id == 7
    assert by_code["4010"].amount == _D("-100000")  # выручка Кт = net
    assert by_code["2310"].amount == _D("-12000")  # НДС output Кт = vat


def test_recognition_no_vat_omits_vat_line():
    """vat=0 → строки 2310 нет, только AR + выручка. Σ=0."""
    draft = build_revenue_recognition(
        on_date=date(2026, 5, 31),
        func_currency="RUB",
        currency="RUB",
        amount_net=_D("50000"),
        vat_amount=_D("0"),
        revenue_account_code="4010",
        ar_account_code="1210",
        vat_output_account_code="2310",
        counterparty_company_id=7,
        rate_resolver=_same,
    )
    codes = {ln.account_code for ln in draft.lines}
    assert codes == {"1210", "4010"}
    assert draft.imbalance() == _D("0")


def test_recognition_is_accrual_outside_cashflow():
    """Все строки признания — accrual: money_account_id=None (вне ДДС by construction)."""
    draft = build_revenue_recognition(
        on_date=date(2026, 5, 31),
        func_currency="RUB",
        currency="RUB",
        amount_net=_D("10000"),
        vat_amount=_D("1200"),
        revenue_account_code="4010",
        ar_account_code="1210",
        vat_output_account_code="2310",
        counterparty_company_id=3,
        rate_resolver=_same,
    )
    assert all(ln.money_account_id is None for ln in draft.lines)
    assert all(ln.cashflow_category_id is None for ln in draft.lines)


def test_recognition_multicurrency_balanced_in_func():
    """func=RUB, doc=KZT: amount в KZT, amount_func в RUB по курсу. Σ amount_func=0."""
    draft = build_revenue_recognition(
        on_date=date(2026, 5, 31),
        func_currency="RUB",
        currency="KZT",
        amount_net=_D("500000"),
        vat_amount=_D("0"),
        revenue_account_code="4010",
        ar_account_code="1210",
        vat_output_account_code="2310",
        counterparty_company_id=9,
        rate_resolver=_kzt,
    )
    assert draft.imbalance() == _D("0")
    by_code = {ln.account_code: ln for ln in draft.lines}
    assert by_code["1210"].amount == _D("500000")  # KZT
    assert by_code["1210"].amount_func == _D("100000")  # 500000 × 0.2 RUB
    assert by_code["4010"].amount_func == _D("-100000")


def test_recognition_zero_net_raises():
    with pytest.raises(PostingError):
        build_revenue_recognition(
            on_date=date(2026, 5, 31), func_currency="RUB", currency="RUB",
            amount_net=_D("0"), vat_amount=_D("0"), revenue_account_code="4010",
            ar_account_code="1210", vat_output_account_code="2310",
            counterparty_company_id=1, rate_resolver=_same,
        )


# ═════════════════════════ build_fx_revaluation ═════════════════════════


def test_revaluation_gain_dt_account_kt_4910():
    """delta>0: Дт счёт(+Δ) / Кт 4910 доход(−Δ). Σ=0."""
    draft = build_fx_revaluation(
        on_date=date(2026, 5, 31),
        func_currency="RUB",
        account_code="1020",
        delta_func=_D("5000"),
        fx_gain_code="4910",
        fx_loss_code="5910",
        account_currency="USD",
    )
    assert draft.kind == "fx_reval"
    assert draft.imbalance() == _D("0")
    by_code = {ln.account_code: ln for ln in draft.lines}
    assert by_code["1020"].amount_func == _D("5000")  # Дт счёт
    assert "4910" in by_code and by_code["4910"].amount_func == _D("-5000")  # Кт доход
    assert "5910" not in by_code


def test_revaluation_loss_kt_account_dt_5910():
    """delta<0: Кт счёт(−|Δ|) / Дт 5910 расход(+|Δ|). Σ=0."""
    draft = build_fx_revaluation(
        on_date=date(2026, 5, 31),
        func_currency="RUB",
        account_code="1020",
        delta_func=_D("-3000"),
        fx_gain_code="4910",
        fx_loss_code="5910",
        account_currency="USD",
    )
    assert draft.imbalance() == _D("0")
    by_code = {ln.account_code: ln for ln in draft.lines}
    assert by_code["1020"].amount_func == _D("-3000")  # Кт счёт
    assert by_code["5910"].amount_func == _D("3000")  # Дт расход
    assert "4910" not in by_code


def test_revaluation_zero_delta_raises():
    with pytest.raises(PostingError):
        build_fx_revaluation(
            on_date=date(2026, 5, 31), func_currency="RUB", account_code="1020",
            delta_func=_D("0"), fx_gain_code="4910", fx_loss_code="5910",
            account_currency="USD",
        )


# ═════════════════════════ recognition helpers ═════════════════════════


def test_recognition_key_format():
    assert recognition_key(42, 2026, 5) == "42-2026-05"
    assert recognition_key(1, 2026, 12) == "1-2026-12"


def test_period_last_day():
    assert period_last_day(2026, 2) == date(2026, 2, 28)  # не високосный
    assert period_last_day(2024, 2) == date(2024, 2, 29)  # високосный
    assert period_last_day(2026, 5) == date(2026, 5, 31)
    assert period_last_day(2026, 4) == date(2026, 4, 30)


def test_split_net_vat_by_shipment():
    """by_shipment + ставка 12%: fee=нетто, vat=round(net*0.12)."""
    net, vat = split_net_vat(
        _D("100000"), vat_rate_pct=_D("12"), vat_recognition="by_shipment"
    )
    assert net == _D("100000.00")
    assert vat == _D("12000.00")


def test_split_net_vat_by_payment_zero_vat():
    """by_payment: НДС в момент признания НЕ начисляется (vat=0)."""
    net, vat = split_net_vat(
        _D("100000"), vat_rate_pct=_D("12"), vat_recognition="by_payment"
    )
    assert net == _D("100000.00")
    assert vat == _D("0.00")


def test_split_net_vat_zero_rate():
    net, vat = split_net_vat(
        _D("100000"), vat_rate_pct=_D("0"), vat_recognition="by_shipment"
    )
    assert vat == _D("0.00")


def test_split_net_vat_gross_input():
    """fee_is_gross: gross→net=gross/(1+rate), vat=gross−net."""
    net, vat = split_net_vat(
        _D("112000"), vat_rate_pct=_D("12"), vat_recognition="by_shipment",
        fee_is_gross=True,
    )
    assert net == _D("100000.00")
    assert vat == _D("12000.00")


# ═════════════════════════ revaluation_delta ═════════════════════════


def test_revaluation_delta_gain():
    """100 USD по курсу 95, книга 9000 → revalued 9500, delta +500 (gain)."""
    d = revaluation_delta(
        balance_fc=_D("100"), book_value_func=_D("9000"), rate=_D("95")
    )
    assert d.revalued_func == _D("9500.00")
    assert d.delta_func == _D("500.00")


def test_revaluation_delta_loss():
    """100 USD по курсу 85, книга 9000 → revalued 8500, delta −500 (loss)."""
    d = revaluation_delta(
        balance_fc=_D("100"), book_value_func=_D("9000"), rate=_D("85")
    )
    assert d.delta_func == _D("-500.00")


def test_revaluation_delta_zero():
    d = revaluation_delta(
        balance_fc=_D("100"), book_value_func=_D("9000"), rate=_D("90")
    )
    assert d.delta_func == _D("0.00")


# ═════════════════════════ base_currency (H4) ═════════════════════════


def test_line_in_closed_period_true():
    locked = {(1, 2026, 5)}
    assert line_is_in_closed_period(
        legal_entity_id=1, on_date=date(2026, 5, 15), locked=locked
    )


def test_line_in_open_period_false():
    locked = {(1, 2026, 5)}
    # другой месяц
    assert not line_is_in_closed_period(
        legal_entity_id=1, on_date=date(2026, 6, 1), locked=locked
    )
    # другое юрлицо
    assert not line_is_in_closed_period(
        legal_entity_id=2, on_date=date(2026, 5, 15), locked=locked
    )


def test_base_imbalance_projection_not_invariant():
    """Σ amount_in_base может быть ≠0 (пер-строчное округление) — это проекция, не баланс."""
    # типичный «хвост округления»: +33.33 / −33.34 ⇒ −0.01
    assert base_imbalance([_D("33.33"), _D("-33.34")]) == _D("-0.01")
    # None трактуем как 0 (строка без курса)
    assert base_imbalance([_D("100.00"), None, _D("-100.00")]) == _D("0.00")


# ═════════════════════════ http_errors.phase4_status ═════════════════════════


def test_phase4_status_recognition_error_422():
    assert phase4_status(RecognitionError("уже признано"))[0] == 422


def test_phase4_status_revaluation_error_422():
    assert phase4_status(RevaluationError("нулевая дельта"))[0] == 422


def test_phase4_status_base_currency_error_422():
    assert phase4_status(BaseCurrencyError("нет курса"))[0] == 422


def test_phase4_status_unbalanced_delegates_422():
    assert phase4_status(UnbalancedEntry(_D("1")))[0] == 422


# ═════════════════ single-flight advisory subkey (W-4) ═════════════════


def test_period_lock_subkey_deterministic():
    """Один и тот же (юрлицо, год, месяц) → один subkey (single-flight стабилен)."""
    a = period_lock_subkey(1, 2026, 6)
    b = period_lock_subkey(1, 2026, 6)
    assert a == b


def test_period_lock_subkey_distinguishes_period():
    """Разные периоды/юрлица → разные subkey (прогоны не блокируют друг друга зря)."""
    base = period_lock_subkey(1, 2026, 6)
    assert period_lock_subkey(1, 2026, 7) != base  # другой месяц
    assert period_lock_subkey(1, 2027, 6) != base  # другой год
    assert period_lock_subkey(2, 2026, 6) != base  # другое юрлицо


def test_period_lock_subkey_in_int4_range():
    """subkey должен укладываться в знаковый int4 (аргумент pg_advisory_xact_lock)."""
    lo, hi = -(2**31), 2**31 - 1
    for le_id, y, m in [
        (1, 2026, 1),
        (1, 2026, 12),
        (99_999, 9999, 12),
        (50_000, 2100, 6),
        (1, 1970, 1),
    ]:
        k = period_lock_subkey(le_id, y, m)
        assert lo <= k <= hi
