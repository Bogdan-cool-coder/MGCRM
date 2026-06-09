"""Posting engine — ядро double-entry GL (Ф0).

ИНВАРИАНТЫ (агент-файл):
  №1  любое движение = fin_journal_entry + ≥2 fin_ledger_line (Дт>0 / Кт<0).
  №2  Σ amount_func == 0 в ФУНКЦ.валюте юрлица — первая линия защиты ЗДЕСЬ,
      вторая — DB-триггер fin_assert_entry_balanced (DEFERRABLE, на COMMIT).
  №3  operation-centric: пользователь НЕ выбирает Дт/Кт (кроме manual_journal);
      проводки пишет ТОЛЬКО этот движок по шаблонам.
  №4  иммутабельность: posted entry/operation нельзя edit/delete — только reverse.
  №5  period-lock: в закрытый период не постим / не сторнируем.
  №6  курс — строгий (fx.get_rate_strict); НИКОГДА services/currency.get_rate.
  №7  деньги — Decimal/ROUND_HALF_UP.
  №8  transfer.cashflow_category=NULL ⇒ вне ДДС; reversed не считается.

АРХИТЕКТУРА (тестируемость):
  • Чистое ядро — `EntryDraft`/`LineDraft` dataclasses + `build_*`-функции,
    которые НЕ ходят в БД: получают уже разрешённые курсы (rate-resolver-замыкание).
    Их покрывают pure-тесты на эталонных Дт/Кт (без DB fixture, через stubs).
  • Async-оркестраторы (`post_operation`, `reverse_entry`, `post_manual_journal`)
    делают: проверка period-lock → resolve курсов (strict) → build_* → persist →
    проставление entry/operation статусов. Они НЕ покрываются pure-тестами (нужна БД),
    но вся арифметика/Дт-Кт/баланс — в чистом ядре под тестом.

POSTING TEMPLATES (Дт/Кт по плану счетов J §3, обоснование у каждого билдера):
  cash_in        Дт money(to)            / Кт <income|AR|advance> (+НДС output 2310)
  cash_out       Дт <expense|AP|...> (+НДС input 1910) / Кт money(from)
  transfer  одно Дт money(to) / Кт money(from)  (cashflow_category=NULL → вне ДДС)
  transfer кросс +FX-строка 4910(доход)/5910(расход) для баланса amount_func
  opening        Дт money(to)            / Кт 3900 «Начальные остатки»
  manual_journal прямые Дт/Кт строки бухгалтера (Σ=0)
  reverse_entry  зеркальные строки исходной, reverses_entry_id, исходная→reversed
"""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import date
from decimal import ROUND_HALF_UP, Decimal

# ───────────────────────────── исключения движка ─────────────────────────────


class PostingError(Exception):
    """Базовая ошибка постинга (вызывающий слой → 422)."""


class UnbalancedEntry(PostingError):
    """Σ amount_func ≠ 0 — проводка не сбалансирована (первая линия защиты)."""

    def __init__(self, imbalance: Decimal) -> None:
        self.imbalance = imbalance
        super().__init__(f"Проводка не сбалансирована: Σ amount_func = {imbalance} (должно быть 0)")


class MissingCounterparty(PostingError):
    """Строка на AR/AP-счёте (requires_counterparty) без counterparty_company_id."""

    def __init__(self, account_code: str) -> None:
        super().__init__(
            f"Счёт {account_code} требует контрагента, но counterparty_company_id не задан"
        )


class CashflowWithoutMoney(PostingError):
    """cashflow_category_id задан, но строка не денежная (money_account_id пуст)."""

    def __init__(self) -> None:
        super().__init__("Статья ДДС допустима только на денежной строке (money_account_id обязателен)")


class PeriodLocked(PostingError):
    """Постинг/сторно в закрытый период (period-lock, инвариант №5)."""

    def __init__(self, year: int, month: int) -> None:
        self.year = year
        self.month = month
        super().__init__(f"Период {year}-{month:02d} закрыт — операция запрещена")


class ImmutablePosted(PostingError):
    """Попытка edit/delete проведённого (posted/reversed) — запрещено, только сторно."""

    def __init__(self, what: str) -> None:
        super().__init__(f"{what} проведён(а) и иммутабелен — изменение запрещено, используйте сторно")


# ───────────────────────────── чистые драфты ─────────────────────────────


def q2(value: Decimal | str | int) -> Decimal:
    """Округление денежной величины до копеек, ROUND_HALF_UP (инвариант №7)."""
    return Decimal(value).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


@dataclass
class LineDraft:
    """Строка проводки (до persist). amount/amount_func ЗНАКОВЫЕ: Дт>0 / Кт<0.

    `account_code` — для обоснования/ошибок в тестах; persist резолвит в account_gl_id.
    """

    account_code: str
    amount: Decimal  # знаковая, в валюте строки
    currency: str
    amount_func: Decimal  # знаковая, в функц.валюте юрлица (Σ по entry = 0)
    money_account_id: int | None = None
    cashflow_category_id: int | None = None
    counterparty_company_id: int | None = None
    employee_user_id: int | None = None
    vat_rate_id: int | None = None
    deal_id: int | None = None
    contract_id: int | None = None
    subscription_id: int | None = None
    fx_rate: Decimal | None = None
    fx_rate_date: date | None = None
    comment: str | None = None


@dataclass
class EntryDraft:
    """Проводка (до persist): заголовок + сбалансированный набор строк."""

    kind: str  # cash_in|cash_out|transfer|opening|adjustment|reversal|revenue_accrual|expense_accrual
    source: str  # operation|manual|...
    date: date
    func_currency: str
    lines: list[LineDraft] = field(default_factory=list)
    source_ref_id: int | None = None
    reverses_entry_id: int | None = None
    memo: str | None = None

    def imbalance(self) -> Decimal:
        return q2(sum((line.amount_func for line in self.lines), Decimal("0")))


# Резолвер курса: (from_ccy, to_ccy, on_date) -> Decimal. В async-оркестраторе это
# замыкание над fx.get_rate_strict + session; в pure-тестах — словарь-stub.
RateResolver = Callable[[str, str, date], Decimal]


def _func_amount(
    amount: Decimal, ccy: str, func_ccy: str, on_date: date, rate_resolver: RateResolver
) -> tuple[Decimal, Decimal]:
    """(amount_func, fx_rate) — знаковая сумма в функц.валюте через строгий курс."""
    if ccy == func_ccy:
        return q2(amount), Decimal("1")
    rate = rate_resolver(ccy, func_ccy, on_date)
    return q2(amount * rate), rate


def _assert_balanced(draft: EntryDraft) -> None:
    imb = draft.imbalance()
    if imb != Decimal("0"):
        raise UnbalancedEntry(imb)


def validate_line_tags(
    *,
    account_code: str,
    requires_counterparty: bool,
    counterparty_company_id: int | None,
    cashflow_category_id: int | None,
    money_account_id: int | None,
) -> None:
    """Чек-лист тегов строки (J §6.3): AR/AP→контрагент; статья ДДС→денежная строка."""
    if requires_counterparty and counterparty_company_id is None:
        raise MissingCounterparty(account_code)
    if cashflow_category_id is not None and money_account_id is None:
        raise CashflowWithoutMoney()


# ───────────────────────────── posting templates (pure) ─────────────────────────────


def build_cash_in(
    *,
    on_date: date,
    func_currency: str,
    amount: Decimal,
    currency: str,
    money_account_id: int,
    money_account_code: str,
    counter_account_code: str,
    cashflow_category_id: int | None,
    counterparty_company_id: int | None = None,
    counter_requires_counterparty: bool = False,
    vat_rate_id: int | None = None,
    rate_resolver: RateResolver,
    source: str = "operation",
    source_ref_id: int | None = None,
    deal_id: int | None = None,
    contract_id: int | None = None,
    subscription_id: int | None = None,
    memo: str | None = None,
) -> EntryDraft:
    """Приход денег.

      Дт money(to)            +amount   (денежная строка, статья ДДС → приток)
      Кт <income|AR|advance>  -amount

    НДС (J §8.5): в Ф0 output-НДС в проводку НЕ выделяется (только справочно на
    операции; output→2310 механика → Ф5). cashflow_category на ДЕНЕЖНОЙ строке.
    """
    amt = q2(amount)
    dt_func, dt_rate = _func_amount(amt, currency, func_currency, on_date, rate_resolver)
    kt_func, kt_rate = _func_amount(-amt, currency, func_currency, on_date, rate_resolver)

    dt = LineDraft(
        account_code=money_account_code,
        amount=amt,
        currency=currency,
        amount_func=dt_func,
        money_account_id=money_account_id,
        cashflow_category_id=cashflow_category_id,
        fx_rate=dt_rate,
        fx_rate_date=on_date,
    )
    kt = LineDraft(
        account_code=counter_account_code,
        amount=-amt,
        currency=currency,
        amount_func=kt_func,
        counterparty_company_id=counterparty_company_id,
        vat_rate_id=vat_rate_id,
        deal_id=deal_id,
        contract_id=contract_id,
        subscription_id=subscription_id,
        fx_rate=kt_rate,
        fx_rate_date=on_date,
    )
    validate_line_tags(
        account_code=counter_account_code,
        requires_counterparty=counter_requires_counterparty,
        counterparty_company_id=counterparty_company_id,
        cashflow_category_id=None,
        money_account_id=None,
    )
    draft = EntryDraft(
        kind="cash_in",
        source=source,
        date=on_date,
        func_currency=func_currency,
        lines=[dt, kt],
        source_ref_id=source_ref_id,
        memo=memo,
    )
    _assert_balanced(draft)
    return draft


def build_cash_out(
    *,
    on_date: date,
    func_currency: str,
    amount: Decimal,
    currency: str,
    money_account_id: int,
    money_account_code: str,
    counter_account_code: str,
    cashflow_category_id: int | None,
    counterparty_company_id: int | None = None,
    counter_requires_counterparty: bool = False,
    employee_user_id: int | None = None,
    vat_rate_id: int | None = None,
    rate_resolver: RateResolver,
    source: str = "operation",
    source_ref_id: int | None = None,
    deal_id: int | None = None,
    contract_id: int | None = None,
    subscription_id: int | None = None,
    memo: str | None = None,
) -> EntryDraft:
    """Расход денег.

      Дт <expense|AP|...>   +amount
      Кт money(from)        -amount   (денежная строка, статья ДДС → отток)

    НДС: input→1910 механика → Ф5; в Ф0 в проводку не выделяется. Статья ДДС — на
    ДЕНЕЖНОЙ (кредитовой) строке, т.к. ДДС читает строки с money_account_id.
    """
    amt = q2(amount)
    dt_func, dt_rate = _func_amount(amt, currency, func_currency, on_date, rate_resolver)
    kt_func, kt_rate = _func_amount(-amt, currency, func_currency, on_date, rate_resolver)

    dt = LineDraft(
        account_code=counter_account_code,
        amount=amt,
        currency=currency,
        amount_func=dt_func,
        counterparty_company_id=counterparty_company_id,
        employee_user_id=employee_user_id,
        vat_rate_id=vat_rate_id,
        deal_id=deal_id,
        contract_id=contract_id,
        subscription_id=subscription_id,
        fx_rate=dt_rate,
        fx_rate_date=on_date,
    )
    kt = LineDraft(
        account_code=money_account_code,
        amount=-amt,
        currency=currency,
        amount_func=kt_func,
        money_account_id=money_account_id,
        cashflow_category_id=cashflow_category_id,
        fx_rate=kt_rate,
        fx_rate_date=on_date,
    )
    validate_line_tags(
        account_code=counter_account_code,
        requires_counterparty=counter_requires_counterparty,
        counterparty_company_id=counterparty_company_id,
        cashflow_category_id=None,
        money_account_id=None,
    )
    draft = EntryDraft(
        kind="cash_out",
        source=source,
        date=on_date,
        func_currency=func_currency,
        lines=[dt, kt],
        source_ref_id=source_ref_id,
        memo=memo,
    )
    _assert_balanced(draft)
    return draft


def build_transfer(
    *,
    on_date: date,
    func_currency: str,
    amount_from: Decimal,
    currency_from: str,
    account_from_id: int,
    account_from_code: str,
    amount_to: Decimal,
    currency_to: str,
    account_to_id: int,
    account_to_code: str,
    rate_resolver: RateResolver,
    fx_gain_code: str = "4910",
    fx_loss_code: str = "5910",
    source: str = "operation",
    source_ref_id: int | None = None,
    memo: str | None = None,
) -> EntryDraft:
    """Перевод между денежными счетами.

      Дт money(to)    +amount_to     cashflow_category=NULL → вне ДДС (инвариант №8)
      Кт money(from)  -amount_from   cashflow_category=NULL

    Одновалютный: amount_to == amount_from, Σ amount_func = 0 сразу.
    Кросс-валютный: суммы в разных валютах → их amount_func могут не сойтись из-за
    курсов. Балансирующая FX-строка на 4910 (доход, если перевели «выгоднее») или
    5910 (расход) добивает Σ amount_func до 0. FX-строка НЕ денежная и БЕЗ статьи —
    вне ДДС и корректно попадает в P&L как курсовая разница.
    """
    amt_from = q2(amount_from)
    amt_to = q2(amount_to)
    kt_func, kt_rate = _func_amount(-amt_from, currency_from, func_currency, on_date, rate_resolver)
    dt_func, dt_rate = _func_amount(amt_to, currency_to, func_currency, on_date, rate_resolver)

    dt = LineDraft(
        account_code=account_to_code,
        amount=amt_to,
        currency=currency_to,
        amount_func=dt_func,
        money_account_id=account_to_id,
        cashflow_category_id=None,  # перевод вне ДДС by construction
        fx_rate=dt_rate,
        fx_rate_date=on_date,
    )
    kt = LineDraft(
        account_code=account_from_code,
        amount=-amt_from,
        currency=currency_from,
        amount_func=kt_func,
        money_account_id=account_from_id,
        cashflow_category_id=None,
        fx_rate=kt_rate,
        fx_rate_date=on_date,
    )
    lines = [dt, kt]

    # FX-балансировка: разница amount_func двух денежных строк → курсовая разница.
    imbalance = q2(dt_func + kt_func)
    if imbalance != Decimal("0"):
        # Σ строк = 0 ⇒ FX-строка = -imbalance.
        #   imbalance>0 (получили больше func, чем отдали) → курсовой ДОХОД (Кт 4910, отрицательная).
        #   imbalance<0 (получили меньше) → курсовой РАСХОД (Дт 5910, положительная).
        fx_amount = -imbalance
        fx_code = fx_gain_code if imbalance > 0 else fx_loss_code
        lines.append(
            LineDraft(
                account_code=fx_code,
                amount=fx_amount,
                currency=func_currency,
                amount_func=fx_amount,
                comment="Курсовая разница при переводе между счетами разных валют",
            )
        )

    draft = EntryDraft(
        kind="transfer",
        source=source,
        date=on_date,
        func_currency=func_currency,
        lines=lines,
        source_ref_id=source_ref_id,
        memo=memo,
    )
    _assert_balanced(draft)
    return draft


@dataclass
class DocAmountLine:
    """Позиция документа (инвойс/вендор-счёт) для accrual-проводки.

    Используется чистыми билдерами build_invoice_issue/build_vendor_bill_confirm.
    Суммы — положительные нетто/НДС; знак Дт/Кт ставит билдер по типу документа.
    """

    account_code: str  # GL-счёт выручки (инвойс) или расхода (вендор-счёт)
    amount_net: Decimal  # >0, нетто позиции (в валюте документа)
    vat_amount: Decimal  # >=0, НДС позиции
    vat_rate_id: int | None = None
    cashflow_category_id: int | None = None  # тег статьи (для будущей разбивки при оплате)


def build_invoice_issue(
    *,
    on_date: date,
    func_currency: str,
    currency: str,
    lines: list[DocAmountLine],
    ar_account_code: str,
    vat_output_account_code: str,
    counterparty_company_id: int,
    rate_resolver: RateResolver,
    source: str = "invoice",
    source_ref_id: int | None = None,
    deal_id: int | None = None,
    contract_id: int | None = None,
    subscription_id: int | None = None,
    memo: str | None = None,
) -> EntryDraft:
    """Выставление счёта клиенту (accrual-by-invoice, J §8.5 / G §1.5 «Событие 1»).

      Дт AR(1210)                 +gross      counterparty (дебиторка)
      Кт выручка(4010/4020/4030)  -net        по каждой позиции (свой счёт)
      Кт НДС output(2310)         -vat_total  (если НДС есть; output→2310)
      Σ = 0  ✓

    AR-строка несёт counterparty (requires_counterparty). Выручка/НДС — accrual,
    БЕЗ money_account ⇒ вне ДДС by construction (P&L двигается, ДДС — нет). vat-строка
    агрегирует НДС всех позиций на 2310. kind=revenue_accrual.
    """
    if not lines:
        raise PostingError("Инвойс без позиций — проводка не создаётся")

    net_total = q2(sum((q2(li.amount_net) for li in lines), Decimal("0")))
    vat_total = q2(sum((q2(li.vat_amount) for li in lines), Decimal("0")))
    gross_total = q2(net_total + vat_total)
    if gross_total <= 0:
        raise PostingError("Сумма инвойса должна быть положительной")

    # Дт AR на gross (дебиторка) — единственная строка с контрагентом.
    ar_func, ar_rate = _func_amount(gross_total, currency, func_currency, on_date, rate_resolver)
    drafts: list[LineDraft] = [
        LineDraft(
            account_code=ar_account_code,
            amount=gross_total,
            currency=currency,
            amount_func=ar_func,
            counterparty_company_id=counterparty_company_id,
            deal_id=deal_id,
            contract_id=contract_id,
            subscription_id=subscription_id,
            fx_rate=ar_rate,
            fx_rate_date=on_date,
        )
    ]
    # Кт выручка по каждой позиции (net, свой счёт выручки).
    for li in lines:
        net = q2(li.amount_net)
        if net <= 0:
            raise PostingError("Нетто позиции инвойса должно быть > 0")
        net_func, net_rate = _func_amount(-net, currency, func_currency, on_date, rate_resolver)
        drafts.append(
            LineDraft(
                account_code=li.account_code,
                amount=-net,
                currency=currency,
                amount_func=net_func,
                vat_rate_id=li.vat_rate_id,
                deal_id=deal_id,
                contract_id=contract_id,
                subscription_id=subscription_id,
                fx_rate=net_rate,
                fx_rate_date=on_date,
            )
        )
    # Кт НДС output на 2310 (агрегат всех позиций), если НДС > 0.
    if vat_total > 0:
        vat_func, vat_rate = _func_amount(-vat_total, currency, func_currency, on_date, rate_resolver)
        drafts.append(
            LineDraft(
                account_code=vat_output_account_code,
                amount=-vat_total,
                currency=currency,
                amount_func=vat_func,
                fx_rate=vat_rate,
                fx_rate_date=on_date,
                comment="НДС с продаж (output)",
            )
        )

    draft = EntryDraft(
        kind="revenue_accrual",
        source=source,
        date=on_date,
        func_currency=func_currency,
        lines=drafts,
        source_ref_id=source_ref_id,
        memo=memo,
    )
    _assert_balanced(draft)
    return draft


def build_vendor_bill_confirm(
    *,
    on_date: date,
    func_currency: str,
    currency: str,
    lines: list[DocAmountLine],
    ap_account_code: str,
    vat_input_account_code: str,
    supplier_company_id: int,
    rate_resolver: RateResolver,
    source: str = "vendor_bill",
    source_ref_id: int | None = None,
    memo: str | None = None,
) -> EntryDraft:
    """Подтверждение входящего счёта поставщика (accrual расход + AP, G §9).

      Дт расход(5xxx)        +net        по каждой позиции (свой счёт)
      Дт НДС input(1910)     +vat_total  (если НДС есть; input→1910 к вычету)
      Кт AP(2110)            -gross      supplier (кредиторка)
      Σ = 0  ✓

    AP-строка несёт supplier (requires_counterparty). Расход/НДС — accrual, БЕЗ
    money_account ⇒ вне ДДС (P&L двигается, ДДС — при оплате). kind=expense_accrual.
    """
    if not lines:
        raise PostingError("Вендор-счёт без позиций — проводка не создаётся")

    net_total = q2(sum((q2(li.amount_net) for li in lines), Decimal("0")))
    vat_total = q2(sum((q2(li.vat_amount) for li in lines), Decimal("0")))
    gross_total = q2(net_total + vat_total)
    if gross_total <= 0:
        raise PostingError("Сумма вендор-счёта должна быть положительной")

    drafts: list[LineDraft] = []
    # Дт расход по каждой позиции (net).
    for li in lines:
        net = q2(li.amount_net)
        if net <= 0:
            raise PostingError("Нетто позиции вендор-счёта должно быть > 0")
        net_func, net_rate = _func_amount(net, currency, func_currency, on_date, rate_resolver)
        drafts.append(
            LineDraft(
                account_code=li.account_code,
                amount=net,
                currency=currency,
                amount_func=net_func,
                vat_rate_id=li.vat_rate_id,
                fx_rate=net_rate,
                fx_rate_date=on_date,
            )
        )
    # Дт НДС input на 1910 (агрегат), если НДС > 0.
    if vat_total > 0:
        vat_func, vat_rate = _func_amount(vat_total, currency, func_currency, on_date, rate_resolver)
        drafts.append(
            LineDraft(
                account_code=vat_input_account_code,
                amount=vat_total,
                currency=currency,
                amount_func=vat_func,
                fx_rate=vat_rate,
                fx_rate_date=on_date,
                comment="НДС к зачёту (input)",
            )
        )
    # Кт AP на gross (кредиторка) — строка с контрагентом.
    ap_func, ap_rate = _func_amount(-gross_total, currency, func_currency, on_date, rate_resolver)
    drafts.append(
        LineDraft(
            account_code=ap_account_code,
            amount=-gross_total,
            currency=currency,
            amount_func=ap_func,
            counterparty_company_id=supplier_company_id,
            fx_rate=ap_rate,
            fx_rate_date=on_date,
        )
    )

    draft = EntryDraft(
        kind="expense_accrual",
        source=source,
        date=on_date,
        func_currency=func_currency,
        lines=drafts,
        source_ref_id=source_ref_id,
        memo=memo,
    )
    _assert_balanced(draft)
    return draft


def build_revenue_recognition(
    *,
    on_date: date,
    func_currency: str,
    currency: str,
    amount_net: Decimal,
    vat_amount: Decimal,
    revenue_account_code: str,
    ar_account_code: str,
    vat_output_account_code: str,
    counterparty_company_id: int,
    rate_resolver: RateResolver,
    subscription_id: int | None = None,
    cashflow_category_id: int | None = None,
    vat_rate_id: int | None = None,
    source: str = "subscription_recognition",
    source_ref_id: int | None = None,
    memo: str | None = None,
) -> EntryDraft:
    """Признание выручки помесячно (revenue recognition, MRR, G §2 реш.5 «Событие 1»).

      Дт AR(1210)            +gross   counterparty (дебиторка по подписке)
      Кт выручка(4010 MRR)   -net     признанная выручка месяца
      Кт НДС output(2310)    -vat     (если НДС есть; output→2310)
      Σ = 0  ✓

    Выручка признаётся ПО НАЧИСЛЕНИЮ, независимо от оплаты — money_account=NULL на ВСЕХ
    строках ⇒ проводка ВНЕ ДДС by construction (P&L двигается, ДДС — нет). Оплата гасит
    AR отдельной cash-проводкой; двойного учёта нет (ДДС=cash-строки, P&L=accrual-строки —
    физически разные). kind=revenue_accrual (как у инвойса — единая accrual-ось P&L).

    Отличие от build_invoice_issue: одна позиция выручки (MRR-счёт), без списка
    позиций — подписка признаёт ровно одну сумму за месяц. cashflow_category_id тег
    несётся на AR-строке справочно (не делает её денежной — money_account=NULL).
    """
    net = q2(amount_net)
    vat = q2(vat_amount)
    if net <= 0:
        raise PostingError("Сумма признания выручки должна быть положительной")
    if vat < 0:
        raise PostingError("НДС признания не может быть отрицательным")
    gross = q2(net + vat)

    ar_func, ar_rate = _func_amount(gross, currency, func_currency, on_date, rate_resolver)
    rev_func, rev_rate = _func_amount(-net, currency, func_currency, on_date, rate_resolver)
    drafts: list[LineDraft] = [
        LineDraft(
            account_code=ar_account_code,
            amount=gross,
            currency=currency,
            amount_func=ar_func,
            counterparty_company_id=counterparty_company_id,
            subscription_id=subscription_id,
            cashflow_category_id=None,  # accrual: НЕ денежная строка (money_account=NULL)
            fx_rate=ar_rate,
            fx_rate_date=on_date,
        ),
        LineDraft(
            account_code=revenue_account_code,
            amount=-net,
            currency=currency,
            amount_func=rev_func,
            subscription_id=subscription_id,
            vat_rate_id=vat_rate_id,
            fx_rate=rev_rate,
            fx_rate_date=on_date,
            comment="Признание выручки (MRR)",
        ),
    ]
    if vat > 0:
        vat_func, vat_r = _func_amount(-vat, currency, func_currency, on_date, rate_resolver)
        drafts.append(
            LineDraft(
                account_code=vat_output_account_code,
                amount=-vat,
                currency=currency,
                amount_func=vat_func,
                fx_rate=vat_r,
                fx_rate_date=on_date,
                comment="НДС с продаж (output, признание)",
            )
        )
    # AR требует контрагента — валидируем (subscription_id есть, но counterparty обязателен).
    validate_line_tags(
        account_code=ar_account_code,
        requires_counterparty=True,
        counterparty_company_id=counterparty_company_id,
        cashflow_category_id=None,
        money_account_id=None,
    )
    draft = EntryDraft(
        kind="revenue_accrual",
        source=source,
        date=on_date,
        func_currency=func_currency,
        lines=drafts,
        source_ref_id=source_ref_id,
        memo=memo,
    )
    _assert_balanced(draft)
    return draft


def build_fx_revaluation(
    *,
    on_date: date,
    func_currency: str,
    account_code: str,
    delta_func: Decimal,
    fx_gain_code: str,
    fx_loss_code: str,
    account_currency: str,
    counterparty_company_id: int | None = None,
    new_rate: Decimal | None = None,
    source: str = "fx_reval",
    source_ref_id: int | None = None,
    memo: str | None = None,
) -> EntryDraft:
    """Переоценка валютного остатка (FX revaluation, G §2 реш.1, H10).

    На конец периода остаток валютного счёта/AR/AP, пересчитанный по курсу на дату,
    расходится с замороженной суммой amount_func. `delta_func` — эта дельта в
    ФУНКЦИОНАЛЬНОЙ валюте (>0 ⇒ актив подорожал/обязательство подешевело → переоценка
    в сторону Дт счёта; <0 ⇒ Кт счёта). Контрсчёт — курсовые разницы:

      delta_func > 0:  Дт счёт +Δ / Кт 4910 доход −Δ        (нереализованная прибыль)
      delta_func < 0:  Кт счёт −|Δ| / Дт 5910 расход +|Δ|   (нереализованный убыток)

    Σ = 0 ✓ (счёт-строка и курсовая-строка зеркальны в func-валюте). Переоценка живёт в
    ФУНКЦ.валюте: amount строки счёта = amount_func (валютная сумма счёта НЕ меняется —
    меняется её оценка в функц.валюте; поэтому currency строки счёта = func_currency,
    Δ только в оценке). kind=fx_reval. money_account НЕ ставим — переоценка вне ДДС
    (это не движение денег, а изменение оценки).

    delta_func==0 → PostingError (нечего переоценивать; вызывающий должен фильтровать).
    """
    delta = q2(delta_func)
    if delta == Decimal("0"):
        raise PostingError("Дельта переоценки нулевая — проводка не создаётся")

    # Строка переоцениваемого счёта несёт знаковую дельту в func-валюте. Сумма в валюте
    # счёта при переоценке НЕ меняется (валюта остатка та же), меняется лишь оценка →
    # amount строки = amount_func = delta (обе в func), currency=func_currency.
    account_line = LineDraft(
        account_code=account_code,
        amount=delta,
        currency=func_currency,
        amount_func=delta,
        counterparty_company_id=counterparty_company_id,
        fx_rate=new_rate,
        fx_rate_date=on_date,
        comment=f"Переоценка остатка ({account_currency})",
    )
    # Контр-строка курсовых разниц: gain (4910) при delta>0, loss (5910) при delta<0.
    if delta > 0:
        fx_line = LineDraft(
            account_code=fx_gain_code,
            amount=-delta,
            currency=func_currency,
            amount_func=-delta,
            fx_rate_date=on_date,
            comment="Курсовая разница (доход)",
        )
    else:
        fx_line = LineDraft(
            account_code=fx_loss_code,
            amount=-delta,  # delta<0 → -delta>0 → Дт расхода
            currency=func_currency,
            amount_func=-delta,
            fx_rate_date=on_date,
            comment="Курсовая разница (расход)",
        )
    draft = EntryDraft(
        kind="fx_reval",
        source=source,
        date=on_date,
        func_currency=func_currency,
        lines=[account_line, fx_line],
        source_ref_id=source_ref_id,
        memo=memo,
    )
    _assert_balanced(draft)
    return draft


def build_opening(
    *,
    on_date: date,
    func_currency: str,
    amount: Decimal,
    currency: str,
    money_account_id: int,
    money_account_code: str,
    opening_offset_code: str = "3900",
    rate_resolver: RateResolver,
    source: str = "operation",
    source_ref_id: int | None = None,
    memo: str | None = None,
) -> EntryDraft:
    """Ввод начального остатка денежного счёта.

      Дт money(to)         +amount   (денежная строка; статья ДДС=NULL — не движение)
      Кт 3900 «Начальные остатки»  -amount

    Opening — НЕ приток ДДС (это ввод сальдо, не операция): cashflow_category=NULL.
    Знак amount задаёт направление (отрицательный остаток допустим → Кт money).
    """
    amt = q2(amount)
    dt_func, dt_rate = _func_amount(amt, currency, func_currency, on_date, rate_resolver)
    kt_func, kt_rate = _func_amount(-amt, currency, func_currency, on_date, rate_resolver)

    dt = LineDraft(
        account_code=money_account_code,
        amount=amt,
        currency=currency,
        amount_func=dt_func,
        money_account_id=money_account_id,
        cashflow_category_id=None,
        fx_rate=dt_rate,
        fx_rate_date=on_date,
    )
    kt = LineDraft(
        account_code=opening_offset_code,
        amount=-amt,
        currency=currency,
        amount_func=kt_func,
        fx_rate=kt_rate,
        fx_rate_date=on_date,
    )
    draft = EntryDraft(
        kind="opening",
        source=source,
        date=on_date,
        func_currency=func_currency,
        lines=[dt, kt],
        source_ref_id=source_ref_id,
        memo=memo,
    )
    _assert_balanced(draft)
    return draft


@dataclass
class ManualLineInput:
    """Вход строки ручного журнала (UX dt/kt + положительная сумма)."""

    account_code: str
    side: str  # dt|kt
    amount: Decimal  # >0
    currency: str
    requires_counterparty: bool = False
    counterparty_company_id: int | None = None
    money_account_id: int | None = None
    cashflow_category_id: int | None = None
    comment: str | None = None


def build_manual_journal(
    *,
    on_date: date,
    func_currency: str,
    lines: list[ManualLineInput],
    rate_resolver: RateResolver,
    source: str = "manual",
    source_ref_id: int | None = None,
    memo: str | None = None,
) -> EntryDraft:
    """Ручная adjustment-проводка (J §6.3): прямой выбор GL-счетов + Дт/Кт.

      sign = +1 (dt) | -1 (kt); amount знаковый; amount_func через strict-курс.
    Валидация: Σ amount_func=0; AR/AP→контрагент; статья ДДС→денежная строка.
    """
    drafts: list[LineDraft] = []
    for li in lines:
        if li.side not in ("dt", "kt"):
            raise PostingError(f"side должен быть dt|kt, получено {li.side!r}")
        if li.amount <= 0:
            raise PostingError("amount строки ручного журнала должен быть > 0 (знак задаёт side)")
        sign = Decimal("1") if li.side == "dt" else Decimal("-1")
        signed = q2(sign * li.amount)
        signed_func, rate = _func_amount(signed, li.currency, func_currency, on_date, rate_resolver)
        validate_line_tags(
            account_code=li.account_code,
            requires_counterparty=li.requires_counterparty,
            counterparty_company_id=li.counterparty_company_id,
            cashflow_category_id=li.cashflow_category_id,
            money_account_id=li.money_account_id,
        )
        drafts.append(
            LineDraft(
                account_code=li.account_code,
                amount=signed,
                currency=li.currency,
                amount_func=signed_func,
                money_account_id=li.money_account_id,
                cashflow_category_id=li.cashflow_category_id,
                counterparty_company_id=li.counterparty_company_id,
                fx_rate=rate,
                fx_rate_date=on_date,
                comment=li.comment,
            )
        )
    draft = EntryDraft(
        kind="adjustment",
        source=source,
        date=on_date,
        func_currency=func_currency,
        lines=drafts,
        source_ref_id=source_ref_id,
        memo=memo,
    )
    _assert_balanced(draft)
    return draft


def build_reversal(
    *,
    on_date: date,
    func_currency: str,
    original_lines: list[LineDraft],
    reverses_entry_id: int,
    source: str,
    source_ref_id: int | None = None,
    memo: str | None = None,
) -> EntryDraft:
    """Сторно: зеркальные строки исходной проводки (amount*-1, amount_func*-1).

    Тот же account/теги, противоположный знак. Σ(orig+rev)=0 ⇒ остаток/отчёты
    сходятся. Дата — В ОТКРЫТОМ периоде (проверяет оркестратор). Исходная entry →
    status='reversed' (делает оркестратор). cashflow_category зеркалится — сторно
    прихода даёт отток той же статьи (ДДС честно «откатывает»).
    """
    mirror = [
        LineDraft(
            account_code=ln.account_code,
            amount=q2(-ln.amount),
            currency=ln.currency,
            amount_func=q2(-ln.amount_func),
            money_account_id=ln.money_account_id,
            cashflow_category_id=ln.cashflow_category_id,
            counterparty_company_id=ln.counterparty_company_id,
            employee_user_id=ln.employee_user_id,
            vat_rate_id=ln.vat_rate_id,
            deal_id=ln.deal_id,
            contract_id=ln.contract_id,
            subscription_id=ln.subscription_id,
            fx_rate=ln.fx_rate,
            fx_rate_date=ln.fx_rate_date,
            comment="Сторно",
        )
        for ln in original_lines
    ]
    draft = EntryDraft(
        kind="reversal",
        source=source,
        date=on_date,
        func_currency=func_currency,
        lines=mirror,
        source_ref_id=source_ref_id,
        reverses_entry_id=reverses_entry_id,
        memo=memo,
    )
    _assert_balanced(draft)
    return draft


# ───────────────────────────── иммутабельность ─────────────────────────────

# Иммутабельные статусы живут ЗДЕСЬ единственным источником истины (DRY) — все три
# assert_*_mutable используют один и тот же набор «проведено → только сторно».
_IMMUTABLE_STATUSES = ("posted", "reversed")
_IMMUTABLE_ENTRY_STATUSES = _IMMUTABLE_STATUSES
_IMMUTABLE_OP_STATUSES = _IMMUTABLE_STATUSES
_IMMUTABLE_MANUAL_JOURNAL_STATUSES = _IMMUTABLE_STATUSES


def assert_entry_mutable(status: str) -> None:
    """Запрет edit/delete проведённой проводки (инвариант №4). Бросает ImmutablePosted."""
    if status in _IMMUTABLE_ENTRY_STATUSES:
        raise ImmutablePosted("Проводка")


def assert_operation_mutable(status: str) -> None:
    """Запрет edit/delete проведённой операции (инвариант №4)."""
    if status in _IMMUTABLE_OP_STATUSES:
        raise ImmutablePosted("Операция")


def assert_manual_journal_mutable(status: str) -> None:
    """Запрет повторного постинга/edit проведённого ручного журнала (инвариант №4)."""
    if status in _IMMUTABLE_MANUAL_JOURNAL_STATUSES:
        raise ImmutablePosted("Ручной журнал")
