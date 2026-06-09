"""Pydantic v2 схемы модуля «Финансы» (Ф0, ЧАНК 3).

Request/response DTO для эндпоинтов /api/finance/*. Все out-схемы —
ConfigDict(from_attributes=True) (читаются из ORM). Деньги — Decimal (никакого
float). Валидаторы повторяют инварианты домена на границе API (суммы > 0, баланс
ручного журнала Σ dt = Σ kt, allocation Σ == сумма), чтобы вернуть 422 ДО движка.

Схемы — тонкие; вся бизнес-логика остаётся в services/finance. Здесь только форма
данных + дешёвые structural-валидаторы (тестируемы без БД).
"""

from __future__ import annotations

import datetime as _dt
from datetime import date, datetime
from decimal import Decimal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

# ───────────────────────────── общие ─────────────────────────────

_POSITIVE = Field(gt=0)


# ───────────────────────────── LegalEntity ─────────────────────────────


class LegalEntityOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    name: str
    country_code: str
    functional_currency: str
    vat_enabled: bool
    tax_regime: str
    vat_recognition: str
    tax_id: str | None = None
    licensor_entity_id: int | None = None
    is_active: bool
    sort_order: int


class LegalEntityCreate(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    country_code: str = Field(min_length=2, max_length=2)
    functional_currency: str = Field(min_length=2, max_length=8)
    vat_enabled: bool = False
    tax_regime: str = "no_vat"
    vat_recognition: str = "by_shipment"
    tax_id: str | None = None
    licensor_entity_id: int | None = None
    sort_order: int = 0


class LegalEntityPatch(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=255)
    functional_currency: str | None = None
    vat_enabled: bool | None = None
    tax_regime: str | None = None
    vat_recognition: str | None = None
    tax_id: str | None = None
    is_active: bool | None = None
    sort_order: int | None = None


# ───────────────────────────── GL-счёт (план счетов) ─────────────────────────────


class GlAccountOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    code: str
    name: str
    type: str
    subtype: str | None = None
    normal_side: str
    is_money: bool
    requires_counterparty: bool
    is_active: bool
    sort_order: int


# ───────────────────────────── MoneyAccount ─────────────────────────────


class MoneyAccountOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    legal_entity_id: int
    gl_account_id: int
    name: str
    account_type: str
    currency: str
    initial_balance: Decimal
    is_active: bool
    sort_order: int


class MoneyAccountCreate(BaseModel):
    legal_entity_id: int
    gl_account_id: int
    name: str = Field(min_length=1, max_length=255)
    account_type: str  # bank|cash|acquiring|ewallet
    currency: str = Field(min_length=2, max_length=8)
    # initial_balance != 0 → оркестратор постит opening-проводку (Дт money / Кт 3900).
    initial_balance: Decimal = Decimal("0")
    sort_order: int = 0


class MoneyAccountPatch(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=255)
    account_type: str | None = None
    is_active: bool | None = None
    sort_order: int | None = None


# ───────────────────────────── OpType ─────────────────────────────


class OpTypeOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    code: str
    name: str
    direction: str
    posting_template: str
    default_cat_id: int | None = None
    default_gl_account_id: int | None = None
    counts_in_pnl: bool
    counts_in_cashflow: bool
    is_internal_transfer: bool
    is_archived: bool
    sort_order: int


class OpTypeCreate(BaseModel):
    code: str = Field(min_length=1, max_length=32)
    name: str = Field(min_length=1, max_length=128)
    direction: str  # in|out|transfer|none
    posting_template: str  # cash_in|cash_out|transfer|opening|manual_journal|reversal
    default_cat_id: int | None = None
    default_gl_account_id: int | None = None
    counts_in_pnl: bool = True
    counts_in_cashflow: bool = True
    is_internal_transfer: bool = False
    sort_order: int = 0


class OpTypePatch(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=128)
    default_cat_id: int | None = None
    default_gl_account_id: int | None = None
    counts_in_pnl: bool | None = None
    counts_in_cashflow: bool | None = None
    is_internal_transfer: bool | None = None
    is_archived: bool | None = None
    sort_order: int | None = None


# ───────────────────────────── CashflowCategory (статьи ДДС) ─────────────────────────────


class CategoryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    cat_set_id: int
    parent_id: int | None = None
    code: str
    name: str
    level: int
    activity: str
    direction: str
    sort_order: int
    is_active: bool


class CategoryCreate(BaseModel):
    cat_set_id: int
    parent_id: int | None = None
    code: str = Field(min_length=1, max_length=24)
    name: str = Field(min_length=1, max_length=255)
    level: int = Field(ge=1, le=3)
    activity: str  # operating|investing|financing
    direction: str  # inflow|outflow|both
    sort_order: int = 0


class CategoryPatch(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=255)
    parent_id: int | None = None
    activity: str | None = None
    direction: str | None = None
    sort_order: int | None = None
    is_active: bool | None = None


class CategoryReorderItem(BaseModel):
    """Один элемент bulk-reorder статей ДДС: id статьи + новый sort_order."""

    id: int
    sort_order: int


class ReorderOut(BaseModel):
    updated: int


class CatSetOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    name: str
    is_active: bool


# ───────────────────────────── VatRate ─────────────────────────────


class VatRateOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    legal_entity_id: int | None = None
    country_code: str | None = None
    name: str
    rate_pct: Decimal
    kind: str
    effective_from: date | None = None
    effective_to: date | None = None
    is_active: bool


# ───────────────────────────── Operation ─────────────────────────────


class OperationCreate(BaseModel):
    """Создание операции (черновик). Знак суммы НЕ задаётся — его ставит движок."""

    legal_entity_id: int
    op_type_id: int  # обязателен: из него движок берёт posting_template
    direction: str  # in|out|transfer
    op_date: date
    due_date: date | None = None
    amount: Decimal = _POSITIVE  # > 0 (знак — в проводке)
    currency: str = Field(min_length=2, max_length=8)
    to_amount: Decimal | None = None  # кросс-валютный transfer (получено)
    account_from_id: int | None = None  # expense/transfer
    account_to_id: int | None = None  # income/transfer
    cashflow_category_id: int | None = None  # статья ДДС (НЕ для transfer)
    counterparty_company_id: int | None = None
    vat_rate_id: int | None = None
    vat_amount: Decimal | None = None
    amount_net: Decimal | None = None
    purpose: str | None = Field(default=None, max_length=512)
    deal_id: int | None = None
    contract_id: int | None = None
    subscription_id: int | None = None
    is_for_management: bool = False

    @field_validator("direction")
    @classmethod
    def _direction_known(cls, v: str) -> str:
        if v not in ("in", "out", "transfer"):
            raise ValueError("direction должен быть in|out|transfer")
        return v

    @field_validator("to_amount")
    @classmethod
    def _to_amount_positive(cls, v: Decimal | None) -> Decimal | None:
        if v is not None and v <= 0:
            raise ValueError("to_amount должен быть > 0")
        return v


class OperationPatch(BaseModel):
    """Правка операции. Для posted разрешены только нефин-поля (контроль — в роутере)."""

    op_type_id: int | None = None
    op_date: date | None = None
    due_date: date | None = None
    amount: Decimal | None = Field(default=None, gt=0)
    currency: str | None = None
    to_amount: Decimal | None = None
    account_from_id: int | None = None
    account_to_id: int | None = None
    cashflow_category_id: int | None = None
    counterparty_company_id: int | None = None
    purpose: str | None = Field(default=None, max_length=512)
    is_for_management: bool | None = None


class OperationOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    number: str | None = None
    legal_entity_id: int
    op_type_id: int | None = None
    direction: str
    status: str
    op_date: date
    due_date: date | None = None
    amount: Decimal
    currency: str
    to_amount: Decimal | None = None
    account_from_id: int | None = None
    account_to_id: int | None = None
    cashflow_category_id: int | None = None
    counterparty_company_id: int | None = None
    vat_rate_id: int | None = None
    vat_amount: Decimal | None = None
    amount_net: Decimal | None = None
    purpose: str | None = None
    journal_entry_id: int | None = None
    deal_id: int | None = None
    contract_id: int | None = None
    subscription_id: int | None = None
    is_for_management: bool
    rejected_reason: str | None = None
    posted_at: datetime | None = None
    created_at: datetime


class OperationListOut(BaseModel):
    """Листинг операций + sum-footer (Σ по направлениям) в валютах-разрезе нет —
    суммируем в func-валюте на клиенте; здесь отдаём count и Σ amount по валюте."""

    items: list[OperationOut]
    total: int
    sum_in: Decimal
    sum_out: Decimal


class ReverseIn(BaseModel):
    """Сторнирование проводки/операции. on_date — дата сторно в открытом периоде."""

    on_date: date | None = None
    memo: str | None = Field(default=None, max_length=512)


# ───────────────────────────── Allocation (split) ─────────────────────────────


class AllocationItem(BaseModel):
    cashflow_category_id: int
    amount: Decimal = _POSITIVE
    comment: str | None = Field(default=None, max_length=255)


class AllocationsPut(BaseModel):
    """Полная замена разнесения операции. Σ amount должна биться с суммой операции
    (проверяется в роутере против operation.amount — здесь только структурно)."""

    items: list[AllocationItem]

    @model_validator(mode="after")
    def _non_empty(self) -> AllocationsPut:
        if not self.items:
            raise ValueError("Список разнесения не может быть пустым")
        return self


class AllocationOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    operation_id: int
    cashflow_category_id: int | None = None
    amount: Decimal
    comment: str | None = None


# ───────────────────────────── ManualJournal (ручная проводка) ─────────────────────────────


class ManualLineIn(BaseModel):
    account_gl_id: int
    side: str  # dt|kt
    amount: Decimal = _POSITIVE  # > 0; знак ставит движок по side
    currency: str = Field(min_length=2, max_length=8)
    counterparty_company_id: int | None = None
    money_account_id: int | None = None
    cashflow_category_id: int | None = None
    comment: str | None = Field(default=None, max_length=255)

    @field_validator("side")
    @classmethod
    def _side_known(cls, v: str) -> str:
        if v not in ("dt", "kt"):
            raise ValueError("side должен быть dt|kt")
        return v


class ManualJournalCreate(BaseModel):
    legal_entity_id: int
    date: date
    memo: str = Field(min_length=1, max_length=512)  # обязательное обоснование
    lines: list[ManualLineIn]

    @model_validator(mode="after")
    def _balanced(self) -> ManualJournalCreate:
        """Σ dt == Σ kt в РАЗРЕЗЕ валют строк (структурная проверка до движка).

        Это НЕ полный инвариант Σ amount_func=0 (он считается движком после
        конвертации по строгому курсу) — но ловит явный дисбаланс одновалютной
        проводки ещё на входе. Для много-валютной проводки совпадение по валютам
        не гарантирует func-баланс; финальную проверку делает posting-движок.
        """
        if len(self.lines) < 2:
            raise ValueError("Проводка требует минимум 2 строки")
        per_ccy: dict[str, Decimal] = {}
        for ln in self.lines:
            sign = Decimal("1") if ln.side == "dt" else Decimal("-1")
            per_ccy[ln.currency] = per_ccy.get(ln.currency, Decimal("0")) + sign * ln.amount
        if len(per_ccy) == 1:
            # одновалютная: Σ dt должна равняться Σ kt
            (only_imbalance,) = per_ccy.values()
            if only_imbalance != Decimal("0"):
                raise ValueError(
                    "Одновалютная проводка не сбалансирована: Σ Дт ≠ Σ Кт"
                )
        return self


class ManualJournalPatch(BaseModel):
    """Правка ЧЕРНОВИКА ручного журнала (J §6.4). Только draft; posted/reversed → 409.

    Если переданы `lines` — полная замена строк с тем же балансом-валидатором, что
    при создании (Σ Дт == Σ Кт для одновалютной проводки; func-баланс — движок при
    постинге). Header-поля (date/memo) необязательны.
    """

    # тип квалифицирован (_dt.date), т.к. имя поля `date` шадоует импорт `date`
    # при отложенной оценке аннотаций (PEP 563 / pydantic rebuild).
    date: _dt.date | None = None
    memo: str | None = Field(default=None, min_length=1, max_length=512)
    lines: list[ManualLineIn] | None = None

    @model_validator(mode="after")
    def _balanced(self) -> ManualJournalPatch:
        if self.lines is None:
            return self
        if len(self.lines) < 2:
            raise ValueError("Проводка требует минимум 2 строки")
        per_ccy: dict[str, Decimal] = {}
        for ln in self.lines:
            sign = Decimal("1") if ln.side == "dt" else Decimal("-1")
            per_ccy[ln.currency] = per_ccy.get(ln.currency, Decimal("0")) + sign * ln.amount
        if len(per_ccy) == 1:
            (only_imbalance,) = per_ccy.values()
            if only_imbalance != Decimal("0"):
                raise ValueError(
                    "Одновалютная проводка не сбалансирована: Σ Дт ≠ Σ Кт"
                )
        return self


class ManualLineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    account_gl_id: int
    side: str
    amount: Decimal
    currency: str
    counterparty_company_id: int | None = None
    money_account_id: int | None = None
    cashflow_category_id: int | None = None
    comment: str | None = None


class ManualJournalOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    number: str | None = None
    legal_entity_id: int
    date: date
    status: str
    memo: str
    journal_entry_id: int | None = None
    posted_at: datetime | None = None
    created_at: datetime
    lines: list[ManualLineOut] = []


# ───────────────────────────── JournalEntry / Line (read) ─────────────────────────────


class LedgerLineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    account_gl_id: int
    amount: Decimal
    currency: str
    amount_func: Decimal
    amount_in_base: Decimal | None = None
    money_account_id: int | None = None
    cashflow_category_id: int | None = None
    counterparty_company_id: int | None = None
    fx_rate: Decimal | None = None
    comment: str | None = None


class JournalEntryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    legal_entity_id: int
    date: date
    kind: str
    status: str
    source: str
    source_ref_id: int | None = None
    reverses_entry_id: int | None = None
    func_currency: str
    memo: str | None = None
    posted_at: datetime | None = None
    lines: list[LedgerLineOut] = []


# ───────────────────────────── Balance / Cashflow / Period ─────────────────────────────


class BalanceRow(BaseModel):
    money_account_id: int
    account_name: str
    currency: str
    amount: Decimal  # в валюте счёта
    amount_func: Decimal  # в функц.валюте юрлица


class BalancesOut(BaseModel):
    legal_entity_id: int
    on_date: date | None = None
    rows: list[BalanceRow]


class CashflowRowOut(BaseModel):
    category_id: int
    category_code: str
    category_name: str
    activity: str  # operating|investing|financing
    direction: str
    net_func: Decimal


class CashflowReportOut(BaseModel):
    legal_entity_id: int
    date_from: date
    date_to: date
    rows: list[CashflowRowOut]
    inflow: Decimal
    outflow: Decimal
    net: Decimal


class PeriodLockOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    legal_entity_id: int
    year: int
    month: int
    locked_at: datetime
    locked_by_user_id: int | None = None


class PeriodLockIn(BaseModel):
    legal_entity_id: int
    year: int = Field(ge=2000, le=2100)
    month: int = Field(ge=1, le=12)


# ───────────────────────────── Permissions (read) ─────────────────────────────


class PermissionOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    role: str | None = None
    user_id: int | None = None
    legal_entity_id: int | None = None
    capability: str
    allowed: bool


# ───────────────────────────── Permissions (CRUD — Ф1) ─────────────────────────────


class PermissionCreate(BaseModel):
    role: str | None = Field(default=None, max_length=16)
    user_id: int | None = None
    legal_entity_id: int | None = None
    capability: str = Field(min_length=1, max_length=32)
    allowed: bool = True

    @model_validator(mode="after")
    def _role_xor_user(self) -> PermissionCreate:
        # Ровно одно из role / user_id (role-дефолт ИЛИ точечное переопределение).
        if (self.role is None) == (self.user_id is None):
            raise ValueError("Укажите ровно одно из: role или user_id")
        return self


class PermissionPatch(BaseModel):
    allowed: bool


# ───────────────────────────── Reports (Ф1) ─────────────────────────────


class PnlLineOut(BaseModel):
    account_id: int
    account_code: str
    account_name: str
    account_type: str
    amount: Decimal


class PnlReportOut(BaseModel):
    legal_entity_id: int
    date_from: date
    date_to: date
    basis: str = "accrual"  # accrual (по начислению) | cash (по деньгам)
    income_lines: list[PnlLineOut]
    expense_lines: list[PnlLineOut]
    total_income: Decimal
    total_expense: Decimal
    profit: Decimal


class TrialBalanceRowOut(BaseModel):
    account_id: int
    account_code: str
    account_name: str
    account_type: str
    debit: Decimal
    credit: Decimal
    balance: Decimal


class TrialBalanceReportOut(BaseModel):
    legal_entity_id: int
    on_date: date | None = None
    rows: list[TrialBalanceRowOut]
    total_debit: Decimal
    total_credit: Decimal
    is_balanced: bool


class ArApRowOut(BaseModel):
    counterparty_company_id: int | None = None
    account_id: int
    account_code: str
    account_name: str
    balance: Decimal


class ArApReportOut(BaseModel):
    legal_entity_id: int
    kind: str  # ar | ap
    on_date: date | None = None
    rows: list[ArApRowOut]
    total: Decimal


class EntriesListOut(BaseModel):
    items: list[JournalEntryOut]
    total: int


# ═════════════════════════ Ф2: согласование / реестр / заявки ═════════════════════════

_REQUEST_TYPES = ("salary", "commission", "expense_reimbursement", "payment")
_APPROVABLE_KINDS = ("operation", "registry", "request", "invoice")
_STAGE_MODES = ("any", "all")


# ───────────────────────────── сценарии согласования ─────────────────────────────


class ScenarioStage(BaseModel):
    """Этап сценария согласования (формат ApprovalRoute.stages)."""

    order: int = Field(ge=0)
    name: str = Field(min_length=1, max_length=128)
    user_ids: list[int] = Field(min_length=1)
    min_required: int = Field(default=1, ge=1)
    mode: str = Field(default="any")

    @field_validator("mode")
    @classmethod
    def _mode_valid(cls, v: str) -> str:
        if v not in _STAGE_MODES:
            raise ValueError(f"mode ∈ {_STAGE_MODES}")
        return v


class ApprovalScenarioOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    name: str
    applies_to: str
    op_type_id: int | None = None
    legal_entity_id: int | None = None
    min_amount: Decimal | None = None
    max_amount: Decimal | None = None
    stages: list[dict] = []
    priority: int
    is_active: bool


class ApprovalScenarioCreate(BaseModel):
    name: str = Field(min_length=1, max_length=128)
    applies_to: str = "operation"
    op_type_id: int | None = None
    legal_entity_id: int | None = None
    min_amount: Decimal | None = None
    max_amount: Decimal | None = None
    stages: list[ScenarioStage] = []
    priority: int = 0
    is_active: bool = True

    @field_validator("applies_to")
    @classmethod
    def _applies_valid(cls, v: str) -> str:
        if v not in _APPROVABLE_KINDS:
            raise ValueError(f"applies_to ∈ {_APPROVABLE_KINDS}")
        return v

    @model_validator(mode="after")
    def _amounts_ordered(self) -> ApprovalScenarioCreate:
        if (
            self.min_amount is not None
            and self.max_amount is not None
            and self.min_amount > self.max_amount
        ):
            raise ValueError("min_amount не может быть больше max_amount")
        return self

    @model_validator(mode="after")
    def _stages_valid(self) -> ApprovalScenarioCreate:
        # WARNING #4: непустой список этапов, корректные user_ids/min_required/order.
        from app.services.finance.fin_approval import validate_stages

        errs = validate_stages([s.model_dump() for s in self.stages])
        if errs:
            raise ValueError("; ".join(errs))
        return self


class ApprovalScenarioPatch(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=128)
    applies_to: str | None = None
    op_type_id: int | None = None
    legal_entity_id: int | None = None
    min_amount: Decimal | None = None
    max_amount: Decimal | None = None
    stages: list[ScenarioStage] | None = None
    priority: int | None = None
    is_active: bool | None = None

    @field_validator("applies_to")
    @classmethod
    def _applies_valid(cls, v: str | None) -> str | None:
        if v is not None and v not in _APPROVABLE_KINDS:
            raise ValueError(f"applies_to ∈ {_APPROVABLE_KINDS}")
        return v

    @model_validator(mode="after")
    def _amounts_and_stages(self) -> ApprovalScenarioPatch:
        if (
            self.min_amount is not None
            and self.max_amount is not None
            and self.min_amount > self.max_amount
        ):
            raise ValueError("min_amount не может быть больше max_amount")
        # WARNING #4: если stages передаётся в PATCH — он тоже должен быть валиден.
        if self.stages is not None:
            from app.services.finance.fin_approval import validate_stages

            errs = validate_stages([s.model_dump() for s in self.stages])
            if errs:
                raise ValueError("; ".join(errs))
        return self


# ───────────────────────────── голоса / сводка ─────────────────────────────


class ApprovalVoteOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    approvable_kind: str
    approvable_id: int
    scenario_id: int | None = None
    user_id: int
    stage_order: int
    decision: str
    comment: str | None = None
    decided_at: datetime | None = None


class ApprovalStageSummary(BaseModel):
    order: int
    name: str
    mode: str
    user_ids: list[int]
    min_required: int
    approved: int
    rejected: int
    pending: int
    is_active: bool


class ApprovalSummaryOut(BaseModel):
    status: str  # pending|approved|rejected
    active_stage: int
    total_stages: int
    stages: list[ApprovalStageSummary]
    scenario_id: int | None = None
    votes: list[ApprovalVoteOut] = []


class ApprovalDecisionIn(BaseModel):
    decision: str
    comment: str | None = Field(default=None, max_length=2000)

    @field_validator("decision")
    @classmethod
    def _decision_valid(cls, v: str) -> str:
        if v not in ("approved", "rejected"):
            raise ValueError("decision ∈ ('approved','rejected')")
        return v


# ───────────────────────────── заявки ─────────────────────────────


class RequestOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    number: str | None = None
    request_type: str
    legal_entity_id: int
    requester_user_id: int | None = None
    amount: Decimal
    currency: str
    op_type_id: int | None = None
    counterparty_company_id: int | None = None
    payee_user_id: int | None = None
    cashflow_category_id: int | None = None
    period_year: int | None = None
    period_month: int | None = None
    desired_date: date | None = None
    description: str | None = None
    status: str
    resulting_operation_id: int | None = None
    rejected_reason: str | None = None
    submitted_at: datetime | None = None
    decided_at: datetime | None = None
    created_at: datetime


class RequestCreate(BaseModel):
    request_type: str
    legal_entity_id: int
    amount: Decimal = _POSITIVE
    currency: str = Field(min_length=2, max_length=8)
    op_type_id: int | None = None
    counterparty_company_id: int | None = None
    payee_user_id: int | None = None
    cashflow_category_id: int | None = None
    period_year: int | None = None
    period_month: int | None = Field(default=None, ge=1, le=12)
    desired_date: date | None = None
    description: str | None = Field(default=None, max_length=4000)

    @field_validator("request_type")
    @classmethod
    def _type_valid(cls, v: str) -> str:
        if v not in _REQUEST_TYPES:
            raise ValueError(f"request_type ∈ {_REQUEST_TYPES}")
        return v


class RequestPatch(BaseModel):
    """Правка ТОЛЬКО draft-заявки (после submit — иммутабельна)."""

    amount: Decimal | None = Field(default=None, gt=0)
    currency: str | None = Field(default=None, min_length=2, max_length=8)
    op_type_id: int | None = None
    counterparty_company_id: int | None = None
    payee_user_id: int | None = None
    cashflow_category_id: int | None = None
    period_year: int | None = None
    period_month: int | None = Field(default=None, ge=1, le=12)
    desired_date: date | None = None
    description: str | None = Field(default=None, max_length=4000)


class RequestFulfillIn(BaseModel):
    """Конвертация approved-заявки в операцию (бухгалтер). Указывает счёт-источник."""

    account_from_id: int
    op_date: date | None = None
    op_type_id: int | None = None  # переопределить тип для постинга (cash_out)
    auto_post: bool = True


# ───────────────────────────── реестр платежей ─────────────────────────────


class RegistryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    number: str | None = None
    legal_entity_id: int
    source_account_id: int
    registry_date: date
    title: str | None = None
    approval_status: str
    payment_status: str
    comment: str | None = None
    created_by_user_id: int | None = None
    submitted_at: datetime | None = None
    approved_at: datetime | None = None
    posted_at: datetime | None = None
    created_at: datetime


class RegistryCreate(BaseModel):
    legal_entity_id: int
    source_account_id: int
    registry_date: date
    title: str | None = Field(default=None, max_length=255)
    comment: str | None = Field(default=None, max_length=4000)


class RegistryPatch(BaseModel):
    """Правка ТОЛЬКО draft-реестра (после submit — состав заморожен, E/F11)."""

    registry_date: date | None = None
    title: str | None = Field(default=None, max_length=255)
    comment: str | None = Field(default=None, max_length=4000)


class RegistryItemsIn(BaseModel):
    """Добавить/убрать операции из реестра (только draft). Σ-валидация — в сервисе."""

    operation_ids: list[int] = Field(min_length=1)


class RegistryDetailOut(RegistryOut):
    items: list[OperationOut] = []


# Ф5 schemas follow.
# ───────────────────────────── Ф5: инвойсы ─────────────────────────────


class InvoiceLineIn(BaseModel):
    """Позиция счёта клиенту (вход). net/vat/gross считает сервис из qty*price+ставка."""

    name: str = Field(min_length=1, max_length=512)
    qty: Decimal = Field(default=Decimal("1"), gt=0)
    unit_price: Decimal = Field(ge=0)
    vat_rate_id: int | None = None
    revenue_account_code: str | None = Field(default=None, max_length=8)
    cashflow_category_id: int | None = None
    sort_order: int = 0


class InvoiceLineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    name: str
    qty: Decimal
    unit_price: Decimal
    amount_net: Decimal
    vat_rate_id: int | None = None
    vat_amount: Decimal
    amount_gross: Decimal
    revenue_account_code: str | None = None
    cashflow_category_id: int | None = None
    sort_order: int


class InvoiceCreate(BaseModel):
    """Создать счёт клиенту (draft). Итоги считаются из позиций сервисом."""

    legal_entity_id: int
    counterparty_company_id: int
    contact_id: int | None = None
    deal_id: int | None = None
    contract_id: int | None = None
    subscription_id: int | None = None
    issue_date: date
    due_date: date | None = None
    currency: str = Field(min_length=2, max_length=8)
    revenue_account_code: str = Field(default="4030", max_length=8)
    purpose: str | None = None
    lines: list[InvoiceLineIn] = Field(min_length=1)


class InvoicePatch(BaseModel):
    """Правка ТОЛЬКО draft-счёта (после issue — иммутабелен). Если lines заданы — замена."""

    counterparty_company_id: int | None = None
    contact_id: int | None = None
    deal_id: int | None = None
    contract_id: int | None = None
    subscription_id: int | None = None
    issue_date: date | None = None
    due_date: date | None = None
    currency: str | None = Field(default=None, min_length=2, max_length=8)
    revenue_account_code: str | None = Field(default=None, max_length=8)
    purpose: str | None = None
    lines: list[InvoiceLineIn] | None = None


class InvoiceOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    number: str | None = None
    legal_entity_id: int
    counterparty_company_id: int
    contact_id: int | None = None
    deal_id: int | None = None
    contract_id: int | None = None
    subscription_id: int | None = None
    issue_date: date
    due_date: date | None = None
    currency: str
    amount_net: Decimal
    vat_amount: Decimal
    amount_gross: Decimal
    paid_amount: Decimal
    status: str
    revenue_account_code: str
    purpose: str | None = None
    amount_in_words: str | None = None
    document_file_id: int | None = None
    journal_entry_id: int | None = None
    signed_at: datetime | None = None
    signed_by_user_id: int | None = None
    issued_at: datetime | None = None
    created_at: datetime


class InvoiceDetailOut(InvoiceOut):
    lines: list[InvoiceLineOut] = []


class DocPaymentIn(BaseModel):
    """Оплата документа (инвойс/вендор-счёт): денежный счёт + сумма + дата + статья ДДС."""

    money_account_id: int
    amount: Decimal = Field(gt=0)
    on_date: date
    cashflow_category_id: int | None = None


# ───────────────────────────── Ф5: акты ─────────────────────────────


class ActLineIn(BaseModel):
    name: str = Field(min_length=1, max_length=512)
    qty: Decimal = Field(default=Decimal("1"), gt=0)
    unit_price: Decimal = Field(ge=0)
    vat_rate_id: int | None = None
    sort_order: int = 0


class ActLineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    name: str
    qty: Decimal
    unit_price: Decimal
    amount_net: Decimal
    vat_rate_id: int | None = None
    vat_amount: Decimal
    amount_gross: Decimal
    sort_order: int


class ActCreate(BaseModel):
    """Акт выполненных работ (draft). Документ выполнения — проводку НЕ постит."""

    legal_entity_id: int
    counterparty_company_id: int
    invoice_id: int | None = None
    contract_id: int | None = None
    subscription_id: int | None = None
    act_date: date
    period_year: int | None = None
    period_month: int | None = Field(default=None, ge=1, le=12)
    currency: str = Field(min_length=2, max_length=8)
    purpose: str | None = None
    lines: list[ActLineIn] = Field(min_length=1)


class ActPatch(BaseModel):
    """Правка ТОЛЬКО draft-акта."""

    counterparty_company_id: int | None = None
    invoice_id: int | None = None
    contract_id: int | None = None
    subscription_id: int | None = None
    act_date: date | None = None
    period_year: int | None = None
    period_month: int | None = Field(default=None, ge=1, le=12)
    currency: str | None = Field(default=None, min_length=2, max_length=8)
    purpose: str | None = None
    lines: list[ActLineIn] | None = None


class ActOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    number: str | None = None
    legal_entity_id: int
    counterparty_company_id: int
    invoice_id: int | None = None
    contract_id: int | None = None
    subscription_id: int | None = None
    act_date: date
    period_year: int | None = None
    period_month: int | None = None
    currency: str
    amount_net: Decimal
    vat_amount: Decimal
    amount_gross: Decimal
    status: str
    purpose: str | None = None
    amount_in_words: str | None = None
    document_file_id: int | None = None
    signed_at: datetime | None = None
    signed_by_user_id: int | None = None
    created_at: datetime


class ActDetailOut(ActOut):
    lines: list[ActLineOut] = []


# ───────────────────────────── Ф5: вендор-счета ─────────────────────────────


class VendorBillLineIn(BaseModel):
    name: str = Field(min_length=1, max_length=512)
    qty: Decimal = Field(default=Decimal("1"), gt=0)
    unit_price: Decimal = Field(ge=0)
    vat_rate_id: int | None = None
    expense_account_code: str | None = Field(default=None, max_length=8)
    cashflow_category_id: int | None = None
    sort_order: int = 0


class VendorBillLineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    name: str
    qty: Decimal
    unit_price: Decimal
    amount_net: Decimal
    vat_rate_id: int | None = None
    vat_amount: Decimal
    amount_gross: Decimal
    expense_account_code: str | None = None
    cashflow_category_id: int | None = None
    sort_order: int


class VendorBillCreate(BaseModel):
    """Входящий счёт от поставщика (draft). При confirm → расход+НДС input+AP."""

    legal_entity_id: int
    supplier_company_id: int
    bill_no: str | None = Field(default=None, max_length=64)
    bill_date: date
    due_date: date | None = None
    currency: str = Field(min_length=2, max_length=8)
    expense_account_code: str = Field(default="5990", max_length=8)
    cashflow_category_id: int | None = None
    purpose: str | None = None
    lines: list[VendorBillLineIn] = Field(min_length=1)


class VendorBillPatch(BaseModel):
    """Правка ТОЛЬКО draft-вендор-счёта."""

    supplier_company_id: int | None = None
    bill_no: str | None = Field(default=None, max_length=64)
    bill_date: date | None = None
    due_date: date | None = None
    currency: str | None = Field(default=None, min_length=2, max_length=8)
    expense_account_code: str | None = Field(default=None, max_length=8)
    cashflow_category_id: int | None = None
    purpose: str | None = None
    lines: list[VendorBillLineIn] | None = None


class VendorBillOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    number: str | None = None
    bill_no: str | None = None
    legal_entity_id: int
    supplier_company_id: int
    bill_date: date
    due_date: date | None = None
    currency: str
    amount_net: Decimal
    vat_amount: Decimal
    amount_gross: Decimal
    paid_amount: Decimal
    status: str
    expense_account_code: str
    cashflow_category_id: int | None = None
    purpose: str | None = None
    document_file_id: int | None = None
    journal_entry_id: int | None = None
    confirmed_at: datetime | None = None
    created_at: datetime


class VendorBillDetailOut(VendorBillOut):
    lines: list[VendorBillLineOut] = []


# ───────────────────────────── Ф5: НДС-книги + aging ─────────────────────────────


class VatBookEntryOut(BaseModel):
    entry_id: int
    entry_date: date
    source: str
    source_ref_id: int | None = None
    counterparty_company_id: int | None = None
    vat_amount: Decimal
    memo: str | None = None


class VatReportOut(BaseModel):
    output_total: Decimal
    input_total: Decimal
    vat_payable: Decimal
    sales_book: list[VatBookEntryOut] = []
    purchase_book: list[VatBookEntryOut] = []


class AgingDocOut(BaseModel):
    doc_id: int
    number: str | None = None
    counterparty_company_id: int | None = None
    currency: str
    due_date: date | None = None
    outstanding: Decimal
    bucket: str


class AgingReportOut(BaseModel):
    docs: list[AgingDocOut] = []
    by_bucket: dict[str, Decimal] = {}
    total: Decimal
    total_amount: Decimal = Decimal("0")


# ───────────────────────────── Ф3: интеграция факта оплаты ─────────────────────────────


class SubscriptionPlanIn(BaseModel):
    """Запрос генерации плановых поступлений подписок за период (платёжный календарь)."""

    year: int = Field(ge=2000, le=2100)
    month: int = Field(ge=1, le=12)
    subscription_id: int | None = None  # None = по всем активным подпискам


class SubscriptionPlanOut(BaseModel):
    """Итог прогона генерации планов: сколько подписок обработано / создано / уже было."""

    period: str
    processed: int
    created: int
    existing: int
    skipped: int  # неактивные / без fee_actual


class ShadowReconOut(BaseModel):
    """Результат SHADOW-сверки комиссий: старый путь (ContractPayment) vs новый (GL)."""

    ok: bool
    total_old: int
    total_new: int
    matched: int
    missing_in_gl: list[int] = []
    amount_mismatch: list[int] = []
    orphan_in_gl: list[int] = []


class MotivationalCardPayOut(BaseModel):
    """Результат проводки выплаты по МК: статус карты + ссылка на расходную операцию."""

    card_id: int
    card_status: str
    operation_id: int | None = None
    created: bool = False


# ───────────────────────────── Ф4: accrual + переоценка + смена базы ─────────────────────────────


class RevenueScheduleOut(BaseModel):
    """Строка плана признания выручки (MRR помесячно)."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    subscription_id: int
    legal_entity_id: int
    counterparty_company_id: int | None = None
    period_year: int
    period_month: int
    amount_net: Decimal
    vat_amount: Decimal
    currency: str
    revenue_account_code: str
    status: str
    recognition_key: str
    recognized_journal_entry_id: int | None = None
    recognized_at: datetime | None = None


class RevenueRecognitionRunIn(BaseModel):
    """Запрос признания выручки за период (генерация плана + проводки accrual)."""

    legal_entity_id: int
    year: int = Field(ge=2000, le=2100)
    month: int = Field(ge=1, le=12)
    subscription_id: int | None = None  # None = по всем активным подпискам
    vat_rate_id: int | None = None  # ставка НДС начисления (by_shipment)


class RevenueRecognitionRunOut(BaseModel):
    """Итог прогона признания: счётчики по подпискам."""

    period: str
    processed: int
    scheduled: int
    recognized: int
    existing: int
    skipped: int


class RevenueReverseIn(BaseModel):
    """Откат признанной выручки (H6) — сторно в текущем открытом периоде."""

    reverse_date: date | None = None  # дата открытого периода; None = сегодня


class RevenueReverseOut(BaseModel):
    """Результат сторно признания: ссылка на сторно-проводку."""

    schedule_id: int
    reversal_entry_id: int
    status: str


class RevaluationRunIn(BaseModel):
    """Запрос переоценки валютных остатков на конец периода."""

    legal_entity_id: int
    year: int = Field(ge=2000, le=2100)
    month: int = Field(ge=1, le=12)


class RevaluationRunOut(BaseModel):
    """Итог переоценки: сколько счетов переоценено / без изменений / в функц.валюте."""

    period: str
    processed: int
    revalued: int
    no_change: int
    skipped_func_ccy: int


class BaseCurrencyChangeIn(BaseModel):
    """Запрос смены базовой валюты группы + пересчёта amount_in_base (открытые периоды)."""

    target_currency: str = Field(min_length=3, max_length=8)


class BaseRecomputeJobOut(BaseModel):
    """Итог задания пересчёта amount_in_base при смене базы."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    target_currency: str
    previous_currency: str | None = None
    status: str
    total_lines: int
    processed_lines: int
    skipped_closed: int  # строки закрытых периодов (НЕ пересчитываются, H4)
    missing_rate_lines: int  # строки без курса на дату (fx_missing)
    error: str | None = None


class FinSettingsOut(BaseModel):
    """Глобальные настройки модуля «Финансы» (singleton): базовая валюта группы."""

    model_config = ConfigDict(from_attributes=True)

    base_currency: str
    base_currency_changed_at: datetime | None = None
    auto_approve_without_scenario: bool


# ───────────────────────────── Платёжный календарь (read-only проекция) ─────────────────────────────


class CalendarEventOut(BaseModel):
    """Событие платёжного календаря (плоская проекция предстоящих/прошедших платежей)."""

    date: date
    direction: str  # in | out
    title: str
    amount: Decimal
    currency: str
    source_type: str  # invoice | vendor_bill | act | payment_registry | request | deal
    source_id: int
    status: str  # planned | paid | overdue
    legal_entity_id: int | None = None
    counterparty_company_id: int | None = None


class CalendarDayTotalOut(BaseModel):
    """Дневной агрегат: суммы поступлений/выплат по валютам + счётчики."""

    date: date
    total_in: dict[str, Decimal]
    total_out: dict[str, Decimal]
    overdue_count: int
    event_count: int


class CalendarOut(BaseModel):
    """Ответ /finance/calendar: плоский список событий + дневные агрегаты."""

    date_from: date
    date_to: date
    direction: str
    events: list[CalendarEventOut]
    day_totals: list[CalendarDayTotalOut]
