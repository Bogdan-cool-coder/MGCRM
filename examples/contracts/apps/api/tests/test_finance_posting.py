"""Ф0 ЧАНК 2 — posting engine: эталонные Дт/Кт по каждому шаблону (pure, без DB).

Все билдеры чистые: курс отдаётся stub-резолвером (dict), БД не нужна.
Проверяем: точные знаковые суммы строк, баланс Σ amount_func=0, теги, кросс-валюту,
сторно-зеркало, иммутабельность, валидаторы тегов.
"""

from __future__ import annotations

from datetime import date
from decimal import Decimal

import pytest

from app.services.finance.posting import (
    CashflowWithoutMoney,
    ImmutablePosted,
    LineDraft,
    ManualLineInput,
    MissingCounterparty,
    UnbalancedEntry,
    assert_entry_mutable,
    assert_manual_journal_mutable,
    assert_operation_mutable,
    build_cash_in,
    build_cash_out,
    build_manual_journal,
    build_opening,
    build_reversal,
    build_transfer,
    validate_line_tags,
)

D = Decimal
ON = date(2026, 5, 31)


def _same_ccy_resolver():
    """Резолвер, который не должен вызываться при одной валюте (бросает, если вызвали)."""

    def r(frm: str, to: str, on: date) -> Decimal:
        raise AssertionError(f"resolver не должен вызываться: {frm}->{to}")

    return r


def _fixed_resolver(table: dict[tuple[str, str], Decimal]):
    def r(frm: str, to: str, on: date) -> Decimal:
        return table[(frm, to)]

    return r


def _by_code(draft):
    return {ln.account_code: ln for ln in draft.lines}


# ───────────────────────────── cash_in ─────────────────────────────


def test_cash_in_single_currency_dt_kt():
    draft = build_cash_in(
        on_date=ON,
        func_currency="KZT",
        amount=D("120000.00"),
        currency="KZT",
        money_account_id=5,
        money_account_code="1020",
        counter_account_code="4010",
        cashflow_category_id=42,
        rate_resolver=_same_ccy_resolver(),
    )
    assert draft.kind == "cash_in"
    assert draft.imbalance() == D("0")
    lines = _by_code(draft)
    # Дт денежного 1020 +120000, статья ДДС на денежной строке
    assert lines["1020"].amount == D("120000.00")
    assert lines["1020"].amount_func == D("120000.00")
    assert lines["1020"].money_account_id == 5
    assert lines["1020"].cashflow_category_id == 42
    # Кт дохода 4010 -120000, без статьи (не денежная)
    assert lines["4010"].amount == D("-120000.00")
    assert lines["4010"].cashflow_category_id is None


def test_cash_in_with_ar_requires_counterparty_ok():
    draft = build_cash_in(
        on_date=ON,
        func_currency="KZT",
        amount=D("1000"),
        currency="KZT",
        money_account_id=1,
        money_account_code="1020",
        counter_account_code="1210",  # AR
        cashflow_category_id=1,
        counterparty_company_id=99,
        counter_requires_counterparty=True,
        rate_resolver=_same_ccy_resolver(),
    )
    assert _by_code(draft)["1210"].counterparty_company_id == 99


def test_cash_in_ar_without_counterparty_raises():
    with pytest.raises(MissingCounterparty):
        build_cash_in(
            on_date=ON,
            func_currency="KZT",
            amount=D("1000"),
            currency="KZT",
            money_account_id=1,
            money_account_code="1020",
            counter_account_code="1210",
            cashflow_category_id=1,
            counter_requires_counterparty=True,
            rate_resolver=_same_ccy_resolver(),
        )


def test_cash_in_cross_currency_func_conversion():
    # счёт в USD, функц.валюта KZT, курс 1 USD = 470 KZT
    draft = build_cash_in(
        on_date=ON,
        func_currency="KZT",
        amount=D("100.00"),
        currency="USD",
        money_account_id=2,
        money_account_code="1020",
        counter_account_code="4010",
        cashflow_category_id=7,
        rate_resolver=_fixed_resolver({("USD", "KZT"): D("470")}),
    )
    lines = _by_code(draft)
    assert lines["1020"].amount == D("100.00")
    assert lines["1020"].amount_func == D("47000.00")
    assert lines["4010"].amount_func == D("-47000.00")
    assert draft.imbalance() == D("0")


# ───────────────────────────── cash_out ─────────────────────────────


def test_cash_out_dt_expense_kt_money():
    draft = build_cash_out(
        on_date=ON,
        func_currency="KZT",
        amount=D("50000"),
        currency="KZT",
        money_account_id=3,
        money_account_code="1020",
        counter_account_code="5210",  # хостинг
        cashflow_category_id=11,
        rate_resolver=_same_ccy_resolver(),
    )
    assert draft.kind == "cash_out"
    lines = _by_code(draft)
    # Дт расхода +, Кт денежного - (статья ДДС на денежной строке)
    assert lines["5210"].amount == D("50000.00")
    assert lines["5210"].cashflow_category_id is None
    assert lines["1020"].amount == D("-50000.00")
    assert lines["1020"].cashflow_category_id == 11
    assert lines["1020"].money_account_id == 3
    assert draft.imbalance() == D("0")


def test_cash_out_to_ap_requires_counterparty():
    with pytest.raises(MissingCounterparty):
        build_cash_out(
            on_date=ON,
            func_currency="KZT",
            amount=D("100"),
            currency="KZT",
            money_account_id=3,
            money_account_code="1020",
            counter_account_code="2110",  # AP
            cashflow_category_id=None,
            counter_requires_counterparty=True,
            rate_resolver=_same_ccy_resolver(),
        )


# ───────────────────────────── transfer ─────────────────────────────


def test_transfer_same_currency_no_cashflow_category():
    draft = build_transfer(
        on_date=ON,
        func_currency="KZT",
        amount_from=D("30000"),
        currency_from="KZT",
        account_from_id=1,
        account_from_code="1020",
        amount_to=D("30000"),
        currency_to="KZT",
        account_to_id=2,
        account_to_code="1010",
        rate_resolver=_same_ccy_resolver(),
    )
    assert draft.kind == "transfer"
    assert len(draft.lines) == 2  # без FX-строки
    for ln in draft.lines:
        # инвариант №8: переводы вне ДДС
        assert ln.cashflow_category_id is None
        assert ln.money_account_id is not None
    assert draft.imbalance() == D("0")


def test_transfer_cross_currency_adds_fx_gain():
    # отдали 100 USD (=47000 KZT), получили 48000 KZT → курсовой ДОХОД 1000 (Кт 4910)
    draft = build_transfer(
        on_date=ON,
        func_currency="KZT",
        amount_from=D("100"),
        currency_from="USD",
        account_from_id=1,
        account_from_code="1020",
        amount_to=D("48000"),
        currency_to="KZT",
        account_to_id=2,
        account_to_code="1010",
        rate_resolver=_fixed_resolver({("USD", "KZT"): D("470")}),
    )
    assert len(draft.lines) == 3
    lines = _by_code(draft)
    # Кт источника -47000 func, Дт получателя +48000 func → дисбаланс +1000 → FX -1000 (Кт 4910)
    assert lines["1020"].amount_func == D("-47000.00")
    assert lines["1010"].amount_func == D("48000.00")
    assert "4910" in lines  # курсовой доход
    assert lines["4910"].amount_func == D("-1000.00")
    assert draft.imbalance() == D("0")


def test_transfer_cross_currency_adds_fx_loss():
    # отдали 100 USD (=47000 KZT), получили только 46000 KZT → курсовой РАСХОД (Дт 5910)
    draft = build_transfer(
        on_date=ON,
        func_currency="KZT",
        amount_from=D("100"),
        currency_from="USD",
        account_from_id=1,
        account_from_code="1020",
        amount_to=D("46000"),
        currency_to="KZT",
        account_to_id=2,
        account_to_code="1010",
        rate_resolver=_fixed_resolver({("USD", "KZT"): D("470")}),
    )
    lines = _by_code(draft)
    assert "5910" in lines
    assert lines["5910"].amount_func == D("1000.00")
    assert draft.imbalance() == D("0")


# ───────────────────────────── opening ─────────────────────────────


def test_opening_dt_money_kt_3900():
    draft = build_opening(
        on_date=ON,
        func_currency="KZT",
        amount=D("500000"),
        currency="KZT",
        money_account_id=1,
        money_account_code="1020",
        rate_resolver=_same_ccy_resolver(),
    )
    assert draft.kind == "opening"
    lines = _by_code(draft)
    assert lines["1020"].amount == D("500000.00")
    assert lines["1020"].cashflow_category_id is None  # opening вне ДДС
    assert lines["3900"].amount == D("-500000.00")
    assert draft.imbalance() == D("0")


# ───────────────────────────── manual_journal ─────────────────────────────


def test_manual_journal_balanced_dt_kt():
    draft = build_manual_journal(
        on_date=ON,
        func_currency="KZT",
        lines=[
            ManualLineInput(account_code="5990", side="dt", amount=D("1000"), currency="KZT"),
            ManualLineInput(account_code="2900", side="kt", amount=D("1000"), currency="KZT"),
        ],
        rate_resolver=_same_ccy_resolver(),
    )
    assert draft.kind == "adjustment"
    lines = _by_code(draft)
    assert lines["5990"].amount == D("1000.00")  # dt → +
    assert lines["2900"].amount == D("-1000.00")  # kt → -
    assert draft.imbalance() == D("0")


def test_manual_journal_unbalanced_raises():
    with pytest.raises(UnbalancedEntry):
        build_manual_journal(
            on_date=ON,
            func_currency="KZT",
            lines=[
                ManualLineInput(account_code="5990", side="dt", amount=D("1000"), currency="KZT"),
                ManualLineInput(account_code="2900", side="kt", amount=D("900"), currency="KZT"),
            ],
            rate_resolver=_same_ccy_resolver(),
        )


def test_manual_journal_cashflow_without_money_raises():
    with pytest.raises(CashflowWithoutMoney):
        build_manual_journal(
            on_date=ON,
            func_currency="KZT",
            lines=[
                ManualLineInput(
                    account_code="5990",
                    side="dt",
                    amount=D("1000"),
                    currency="KZT",
                    cashflow_category_id=5,  # статья без money_account
                ),
                ManualLineInput(account_code="2900", side="kt", amount=D("1000"), currency="KZT"),
            ],
            rate_resolver=_same_ccy_resolver(),
        )


def test_manual_journal_cross_currency_unbalanced_in_func_raises():
    # S-1: строки в РАЗНЫХ валютах. Номиналы «бьются» (1000 USD dt vs 470000 KZT kt),
    # но в функц.валюте KZT по курсу 1 USD=470: dt=+470000, kt=-460000 → Σ func=+10000 ≠ 0.
    # Σ func ≠ 0 ⇒ UnbalancedEntry (баланс держится в ФУНКЦ.валюте, не в номиналах).
    with pytest.raises(UnbalancedEntry):
        build_manual_journal(
            on_date=ON,
            func_currency="KZT",
            lines=[
                ManualLineInput(account_code="1020", side="dt", amount=D("1000"), currency="USD"),
                ManualLineInput(account_code="4090", side="kt", amount=D("460000"), currency="KZT"),
            ],
            rate_resolver=_fixed_resolver({("USD", "KZT"): D("470")}),
        )


def test_manual_journal_cross_currency_balanced_in_func_ok():
    # Зеркало S-1: те же валюты, но номиналы подобраны так, что Σ func=0
    # (1000 USD * 470 = 470000 KZT). Кросс-валютный manual_journal валиден, если
    # баланс сходится именно в функц.валюте.
    draft = build_manual_journal(
        on_date=ON,
        func_currency="KZT",
        lines=[
            ManualLineInput(account_code="1020", side="dt", amount=D("1000"), currency="USD"),
            ManualLineInput(account_code="4090", side="kt", amount=D("470000"), currency="KZT"),
        ],
        rate_resolver=_fixed_resolver({("USD", "KZT"): D("470")}),
    )
    lines = _by_code(draft)
    assert lines["1020"].amount == D("1000.00")
    assert lines["1020"].amount_func == D("470000.00")
    assert lines["4090"].amount == D("-470000.00")
    assert lines["4090"].amount_func == D("-470000.00")
    assert draft.imbalance() == D("0")


def test_manual_journal_ar_without_counterparty_raises():
    with pytest.raises(MissingCounterparty):
        build_manual_journal(
            on_date=ON,
            func_currency="KZT",
            lines=[
                ManualLineInput(
                    account_code="1210",
                    side="dt",
                    amount=D("1000"),
                    currency="KZT",
                    requires_counterparty=True,
                ),
                ManualLineInput(account_code="4090", side="kt", amount=D("1000"), currency="KZT"),
            ],
            rate_resolver=_same_ccy_resolver(),
        )


# ───────────────────────────── reversal ─────────────────────────────


def test_reversal_mirrors_lines():
    original = [
        LineDraft(account_code="1020", amount=D("120000"), currency="KZT",
                  amount_func=D("120000"), money_account_id=5, cashflow_category_id=42),
        LineDraft(account_code="4010", amount=D("-120000"), currency="KZT",
                  amount_func=D("-120000")),
    ]
    rev = build_reversal(
        on_date=date(2026, 6, 3),
        func_currency="KZT",
        original_lines=original,
        reverses_entry_id=77,
        source="operation",
        source_ref_id=501,
    )
    assert rev.kind == "reversal"
    assert rev.reverses_entry_id == 77
    lines = _by_code(rev)
    # знаки зеркальные
    assert lines["1020"].amount == D("-120000.00")
    assert lines["1020"].amount_func == D("-120000.00")
    assert lines["1020"].cashflow_category_id == 42  # статья зеркалится (откат притока)
    assert lines["4010"].amount == D("120000.00")
    assert rev.imbalance() == D("0")


def test_reversal_sum_with_original_is_zero():
    original = [
        LineDraft(account_code="1020", amount=D("500"), currency="KZT", amount_func=D("500")),
        LineDraft(account_code="3900", amount=D("-500"), currency="KZT", amount_func=D("-500")),
    ]
    rev = build_reversal(
        on_date=ON, func_currency="KZT", original_lines=original,
        reverses_entry_id=1, source="operation",
    )
    # orig + rev по каждому счёту = 0
    rev_by = _by_code(rev)
    for ol in original:
        assert ol.amount_func + rev_by[ol.account_code].amount_func == D("0")


def test_reversal_of_cross_currency_transfer_mirrors_all_three_lines():
    # S-2: сторно кросс-валютного перевода (3 строки: money_to, money_from, FX-контрсчёт).
    # Берём исходный transfer-драфт (с FX-строкой 4910) и сторнируем его строки.
    transfer = build_transfer(
        on_date=ON,
        func_currency="KZT",
        amount_from=D("100"),
        currency_from="USD",
        account_from_id=1,
        account_from_code="1020",
        amount_to=D("48000"),
        currency_to="KZT",
        account_to_id=2,
        account_to_code="1010",
        rate_resolver=_fixed_resolver({("USD", "KZT"): D("470")}),
    )
    assert len(transfer.lines) == 3  # money_to + money_from + FX-доход 4910

    rev = build_reversal(
        on_date=date(2026, 6, 3),
        func_currency="KZT",
        original_lines=transfer.lines,
        reverses_entry_id=900,
        source="operation",
        source_ref_id=42,
    )
    # все 3 строки зеркалятся (включая FX-контрсчёт)
    assert len(rev.lines) == 3
    assert rev.reverses_entry_id == 900
    o = _by_code(transfer)
    r = _by_code(rev)
    for code in ("1020", "1010", "4910"):
        assert code in r, f"строка {code} должна быть зеркалирована в сторно"
        # знаковые суммы зеркальны (orig + rev = 0) в обеих величинах
        assert o[code].amount + r[code].amount == D("0")
        assert o[code].amount_func + r[code].amount_func == D("0")
    # сторно само по себе сбалансировано и Σ(orig+rev) по всей проводке = 0
    assert rev.imbalance() == D("0")
    assert transfer.imbalance() + rev.imbalance() == D("0")
    # money_account_id и валюта сохраняются на зеркальных денежных строках
    assert r["1020"].money_account_id == 1
    assert r["1020"].currency == "USD"
    assert r["1010"].money_account_id == 2
    # FX-контрсчёт остаётся не-денежным и без статьи ДДС
    assert r["4910"].money_account_id is None
    assert r["4910"].cashflow_category_id is None


# ───────────────────────────── иммутабельность ─────────────────────────────


@pytest.mark.parametrize("status", ["posted", "reversed"])
def test_entry_immutable_when_posted(status):
    with pytest.raises(ImmutablePosted):
        assert_entry_mutable(status)


@pytest.mark.parametrize("status", ["draft"])
def test_entry_mutable_when_draft(status):
    assert_entry_mutable(status)  # не бросает


@pytest.mark.parametrize("status", ["posted", "reversed"])
def test_operation_immutable_when_posted(status):
    with pytest.raises(ImmutablePosted):
        assert_operation_mutable(status)


@pytest.mark.parametrize("status", ["planned", "to_pay", "on_hold"])
def test_operation_mutable_before_posting(status):
    assert_operation_mutable(status)


@pytest.mark.parametrize("status", ["posted", "reversed"])
def test_manual_journal_immutable_when_posted(status):
    with pytest.raises(ImmutablePosted):
        assert_manual_journal_mutable(status)


def test_manual_journal_mutable_when_draft():
    assert_manual_journal_mutable("draft")  # не бросает


# ───────────────────────────── валидатор тегов ─────────────────────────────


def test_validate_line_tags_ok():
    validate_line_tags(
        account_code="1020",
        requires_counterparty=False,
        counterparty_company_id=None,
        cashflow_category_id=5,
        money_account_id=1,  # статья + денежная → ок
    )


def test_validate_line_tags_cashflow_requires_money():
    with pytest.raises(CashflowWithoutMoney):
        validate_line_tags(
            account_code="5990",
            requires_counterparty=False,
            counterparty_company_id=None,
            cashflow_category_id=5,
            money_account_id=None,
        )
