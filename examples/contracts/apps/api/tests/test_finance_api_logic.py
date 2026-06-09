"""Ф0 ЧАНК 3 — pure-логика API-слоя: маппинг исключений→HTTP, схема-валидаторы,
require_fin (через fin_can на duck-typed строках прав). Без DB fixture.
"""

from __future__ import annotations

from datetime import date
from decimal import Decimal

import pytest
from pydantic import ValidationError

from app.schemas_finance import (
    AllocationsPut,
    CashflowRowOut,
    ManualJournalCreate,
    ManualJournalPatch,
    OperationCreate,
    PeriodLockIn,
    ReverseIn,
)
from app.services.finance.access import PermissionRow, can_from_rows
from app.services.finance.fx import FxRateMissing
from app.services.finance.http_errors import posting_status
from app.services.finance.posting import (
    CashflowWithoutMoney,
    ImmutablePosted,
    MissingCounterparty,
    PeriodLocked,
    PostingError,
    UnbalancedEntry,
)

D = Decimal


# ───────────────────────────── маппинг исключений → HTTP ─────────────────────────────


def test_unbalanced_maps_422():
    code, _ = posting_status(UnbalancedEntry(D("0.01")))
    assert code == 422


def test_fx_missing_maps_422_with_pair_in_message():
    code, detail = posting_status(FxRateMissing("KZT", "RUB", date(2026, 5, 31)))
    assert code == 422
    assert "KZT" in detail and "RUB" in detail


def test_period_locked_maps_409():
    code, _ = posting_status(PeriodLocked(2026, 5))
    assert code == 409


def test_immutable_posted_maps_409():
    code, _ = posting_status(ImmutablePosted("Операция"))
    assert code == 409


def test_missing_counterparty_maps_422():
    code, _ = posting_status(MissingCounterparty("1210"))
    assert code == 422


def test_cashflow_without_money_maps_422():
    code, _ = posting_status(CashflowWithoutMoney())
    assert code == 422


def test_base_posting_error_maps_422():
    code, _ = posting_status(PostingError("неизвестный шаблон"))
    assert code == 422


def test_subclass_checked_before_base():
    # ImmutablePosted — подкласс PostingError, но должен дать 409, не 422
    code, _ = posting_status(ImmutablePosted("Проводка"))
    assert code == 409


def test_unknown_exception_reraised():
    with pytest.raises(ValueError):
        posting_status(ValueError("чужое исключение"))


# ───────────────────────────── phase2_status (Ф2) ─────────────────────────────


def test_phase2_status_conflicts_409():
    from app.services.finance.fin_approval import AlreadyDecided
    from app.services.finance.http_errors import phase2_status
    from app.services.finance.registry import RegistryFrozen
    from app.services.finance.requests import RequestImmutable

    assert phase2_status(RequestImmutable("x"))[0] == 409
    assert phase2_status(RegistryFrozen("x"))[0] == 409
    assert phase2_status(AlreadyDecided("x"))[0] == 409


def test_phase2_status_invalid_422_and_403():
    from app.services.finance.fin_approval import (
        NoScenarioMatched,
        NotAnApprover,
    )
    from app.services.finance.http_errors import phase2_status
    from app.services.finance.registry import RegistryMemberInvalid
    from app.services.finance.requests import RequestError

    assert phase2_status(NoScenarioMatched("x"))[0] == 422
    assert phase2_status(NotAnApprover("x"))[0] == 403
    assert phase2_status(RegistryMemberInvalid("x"))[0] == 422
    assert phase2_status(RequestError("x"))[0] == 422


def test_phase2_status_unknown_reraised():
    from app.services.finance.http_errors import phase2_status

    with pytest.raises(ValueError):
        phase2_status(ValueError("чужое"))


# ───────────────────────────── phase5_status (Ф5) ─────────────────────────────


def test_phase5_status_conflict_and_invalid():
    from app.services.finance.http_errors import phase5_status
    from app.services.finance.invoicing import (
        DocumentImmutable,
        DocumentNotIssued,
        OverPayment,
    )

    # Конфликт состояния (иммутабельность/нельзя отменить оплаченное) → 409.
    assert phase5_status(DocumentImmutable("x"))[0] == 409
    # Невалидный ввод (оплата сверх остатка / документ не выставлен) → 422.
    assert phase5_status(OverPayment("x"))[0] == 422
    assert phase5_status(DocumentNotIssued("x"))[0] == 422


def test_phase5_status_delegates_posting_errors():
    from app.services.finance.http_errors import phase5_status
    from app.services.finance.posting import ImmutablePosted, UnbalancedEntry

    # PostingError внутри issue/pay → делегируется posting_status (409/422).
    from decimal import Decimal

    assert phase5_status(ImmutablePosted("счёт"))[0] == 409
    assert phase5_status(UnbalancedEntry(Decimal("5")))[0] == 422


def test_phase5_status_unknown_reraised():
    from app.services.finance.http_errors import phase5_status

    with pytest.raises(ValueError):
        phase5_status(ValueError("чужое"))


# ───────────────────────────── phase3_status (Ф3) ─────────────────────────────


def test_phase3_status_integration_and_posting():
    from app.services.finance.http_errors import phase3_status
    from app.services.finance.pay_integration import (
        MissingOpType,
        NoLegalEntity,
        NoMoneyAccount,
    )
    from app.services.finance.posting import ImmutablePosted, UnbalancedEntry

    # Конфигурационные сбои интеграции → 422.
    assert phase3_status(MissingOpType("x"))[0] == 422
    assert phase3_status(NoLegalEntity("x"))[0] == 422
    assert phase3_status(NoMoneyAccount("x"))[0] == 422
    # PostingError внутри проводки делегируется posting_status (409/422).
    assert phase3_status(ImmutablePosted("op"))[0] == 409
    assert phase3_status(UnbalancedEntry(Decimal("3")))[0] == 422


def test_phase3_status_unknown_reraised():
    from app.services.finance.http_errors import phase3_status

    with pytest.raises(ValueError):
        phase3_status(ValueError("чужое"))


# ───────────────────────────── require_fin: логика через fin_can ─────────────────────────────
# require_fin — тонкая FastAPI-обёртка над can_from_rows; тестируем ядро решения прав.


def _can(role: str, capability: str, *, le: int | None = None) -> bool:
    return can_from_rows(
        role=role, user_id=1, capability=capability, legal_entity_id=le, rows=[]
    )


def test_accountant_can_create_and_post_not_close():
    assert _can("accountant", "create_operation")
    assert _can("accountant", "post_operation")
    assert _can("accountant", "create_manual_journal")
    assert not _can("accountant", "close_period")
    assert not _can("accountant", "manage_settings")


def test_cfo_has_close_period_and_settings():
    assert _can("cfo", "close_period")
    assert _can("cfo", "manage_settings")
    assert _can("cfo", "view_management")
    assert _can("cfo", "post_manual_journal")


def test_director_read_only_no_journal_no_post():
    assert _can("director", "view_reports")
    assert _can("director", "view_management")
    assert not _can("director", "create_operation")
    assert not _can("director", "post_operation")
    assert not _can("director", "view_journal")
    assert not _can("director", "close_period")


def test_manager_only_view_operations():
    assert _can("manager", "view_operations")
    assert not _can("manager", "create_operation")
    assert not _can("manager", "view_reports")


def test_user_override_beats_role_default():
    # director обычно НЕ может create_operation; точечный override включает
    rows = [
        PermissionRow(
            role=None, user_id=7, legal_entity_id=None,
            capability="create_operation", allowed=True,
        )
    ]
    assert can_from_rows(
        role="director", user_id=7, capability="create_operation",
        legal_entity_id=None, rows=rows,
    )


def test_entity_specific_override_beats_for_all():
    # role-deny для всех, но allow для конкретного юрлица 5 → для 5 разрешено
    rows = [
        PermissionRow(
            role="manager", user_id=None, legal_entity_id=None,
            capability="create_operation", allowed=False,
        ),
        PermissionRow(
            role="manager", user_id=None, legal_entity_id=5,
            capability="create_operation", allowed=True,
        ),
    ]
    assert can_from_rows(
        role="manager", user_id=1, capability="create_operation",
        legal_entity_id=5, rows=rows,
    )
    assert not can_from_rows(
        role="manager", user_id=1, capability="create_operation",
        legal_entity_id=9, rows=rows,
    )


# ───────────────────────────── схема Operation ─────────────────────────────


def _op_kwargs(**over):
    base = dict(
        legal_entity_id=1, op_type_id=2, direction="in",
        op_date=date(2026, 6, 1), amount=D("100.00"), currency="KZT",
    )
    base.update(over)
    return base


def test_operation_amount_must_be_positive():
    with pytest.raises(ValidationError):
        OperationCreate(**_op_kwargs(amount=D("0")))
    with pytest.raises(ValidationError):
        OperationCreate(**_op_kwargs(amount=D("-5")))


def test_operation_direction_validated():
    with pytest.raises(ValidationError):
        OperationCreate(**_op_kwargs(direction="bogus"))
    assert OperationCreate(**_op_kwargs(direction="transfer")).direction == "transfer"


def test_operation_direction_db_check_matches_pydantic():
    """DB-CHECK (миграция 0089) и Pydantic-валидатор должны принимать одно множество.

    Дрейф между БД-инвариантом и схемой опасен (кривой direction обошёл бы проекции
    ДДС/P&L). Тест ловит расхождение как чистый предикат, без БД.
    """
    db_check_allowed = {"in", "out", "transfer"}
    for v in db_check_allowed:
        assert OperationCreate(**_op_kwargs(direction=v)).direction == v
    for bad in ("inflow", "outflow", "income", "expense", "INCOME", "", "none"):
        assert bad not in db_check_allowed
        with pytest.raises(ValidationError):
            OperationCreate(**_op_kwargs(direction=bad))


def test_operation_to_amount_positive_if_present():
    with pytest.raises(ValidationError):
        OperationCreate(**_op_kwargs(direction="transfer", to_amount=D("-1")))
    ok = OperationCreate(**_op_kwargs(direction="transfer", to_amount=D("90.00")))
    assert ok.to_amount == D("90.00")


# ───────────────────────────── схема ManualJournal (баланс) ─────────────────────────────


def _line(side, amount, ccy="KZT", **over):
    return dict(account_gl_id=1, side=side, amount=D(amount), currency=ccy, **over)


def test_manual_journal_single_currency_balanced_ok():
    mj = ManualJournalCreate(
        legal_entity_id=1, date=date(2026, 6, 1), memo="корректировка",
        lines=[_line("dt", "100.00"), _line("kt", "100.00")],
    )
    assert len(mj.lines) == 2


def test_manual_journal_single_currency_unbalanced_rejected():
    with pytest.raises(ValidationError):
        ManualJournalCreate(
            legal_entity_id=1, date=date(2026, 6, 1), memo="bad",
            lines=[_line("dt", "100.00"), _line("kt", "90.00")],
        )


def test_manual_journal_needs_two_lines():
    with pytest.raises(ValidationError):
        ManualJournalCreate(
            legal_entity_id=1, date=date(2026, 6, 1), memo="one",
            lines=[_line("dt", "100.00")],
        )


def test_manual_journal_line_amount_positive():
    with pytest.raises(ValidationError):
        ManualJournalCreate(
            legal_entity_id=1, date=date(2026, 6, 1), memo="neg",
            lines=[_line("dt", "-100.00"), _line("kt", "100.00")],
        )


def test_manual_journal_line_side_validated():
    with pytest.raises(ValidationError):
        ManualJournalCreate(
            legal_entity_id=1, date=date(2026, 6, 1), memo="bad side",
            lines=[_line("DEBIT", "100.00"), _line("kt", "100.00")],
        )


def test_manual_journal_multicurrency_skips_strict_balance():
    # разные валюты строк — структурную Σ dt=Σ kt НЕ проверяем (func-баланс делает движок)
    mj = ManualJournalCreate(
        legal_entity_id=1, date=date(2026, 6, 1), memo="cross",
        lines=[_line("dt", "100.00", "USD"), _line("kt", "47000.00", "KZT")],
    )
    assert len(mj.lines) == 2


# ───────────────────────────── схема Allocation ─────────────────────────────


def test_allocation_items_required_nonempty():
    with pytest.raises(ValidationError):
        AllocationsPut(items=[])


def test_allocation_item_amount_positive():
    with pytest.raises(ValidationError):
        AllocationsPut(items=[{"cashflow_category_id": 1, "amount": D("0")}])


def test_allocation_sum_helper_matches():
    # Σ amount считается в роутере против operation.amount; здесь проверяем,
    # что схема корректно парсит набор и суммы доступны для проверки.
    a = AllocationsPut(
        items=[
            {"cashflow_category_id": 1, "amount": D("60.00")},
            {"cashflow_category_id": 2, "amount": D("40.00")},
        ]
    )
    assert sum((it.amount for it in a.items), D("0")) == D("100.00")


# ───────────────────────────── W5: own-only vs view_all_operations ─────────────────────────────
# manager имеет view_operations, но НЕ view_all_operations → листинг/by-id фильтруется
# по created_by_user_id. accountant/cfo/director/admin имеют view_all_operations → все.


def test_manager_lacks_view_all_operations_but_has_view():
    assert _can("manager", "view_operations")
    assert not _can("manager", "view_all_operations")


def test_privileged_roles_have_view_all_operations():
    for role in ("accountant", "cfo", "director", "admin"):
        assert _can(role, "view_all_operations"), role


def test_view_all_operations_user_override_for_manager():
    # точечный user-override может выдать manager'у право видеть все операции
    rows = [
        PermissionRow(
            role=None, user_id=42, legal_entity_id=None,
            capability="view_all_operations", allowed=True,
        )
    ]
    assert can_from_rows(
        role="manager", user_id=42, capability="view_all_operations",
        legal_entity_id=None, rows=rows,
    )
    # другой manager без override — по-прежнему own-only
    assert not can_from_rows(
        role="manager", user_id=99, capability="view_all_operations",
        legal_entity_id=None, rows=rows,
    )


# ───────────────────────────── W7: enum-валидация query-фильтров ─────────────────────────────
# Списки допустимых значений в роутере должны совпадать с CHECK-констрейнтами модели.


def test_listing_enum_constants_cover_model_check():
    from app.routers.finance import _OP_DIRECTIONS, _OP_STATUSES

    assert set(_OP_DIRECTIONS) == {"in", "out", "transfer"}
    # соответствует ck_fin_operation_status в models.FinOperation
    assert set(_OP_STATUSES) == {
        "planned", "to_pay", "on_hold", "posted",
        "reversed", "rejected", "cancelled", "partially_paid",
    }


# ───────────────────────────── W6: PATCH черновика ручного журнала (баланс) ─────────────────────────────


def test_manual_journal_patch_header_only_ok():
    # без lines — баланс не проверяется, правка только header-полей
    p = ManualJournalPatch(memo="новое обоснование")
    assert p.memo == "новое обоснование"
    assert p.lines is None


def test_manual_journal_patch_lines_balanced_ok():
    p = ManualJournalPatch(
        lines=[_line("dt", "100.00"), _line("kt", "100.00")]
    )
    assert p.lines is not None and len(p.lines) == 2


def test_manual_journal_patch_lines_unbalanced_rejected():
    with pytest.raises(ValidationError):
        ManualJournalPatch(lines=[_line("dt", "100.00"), _line("kt", "90.00")])


def test_manual_journal_patch_lines_needs_two():
    with pytest.raises(ValidationError):
        ManualJournalPatch(lines=[_line("dt", "100.00")])


def test_manual_journal_patch_multicurrency_skips_strict_balance():
    p = ManualJournalPatch(
        lines=[_line("dt", "100.00", "USD"), _line("kt", "47000.00", "KZT")]
    )
    assert p.lines is not None and len(p.lines) == 2


# ───────────────────────────── контракт frontend↔backend (выравнивание) ─────────────────────────────


def test_reverse_in_accepts_empty_body():
    """POST /operations|journals|entries/{id}/reverse — фронт шлёт {} (body опционально).

    Все поля ReverseIn необязательны, поэтому пустой JSON-объект валиден."""
    r = ReverseIn()
    assert r.on_date is None and r.memo is None
    # явно как с фронта: api(..., { body: {} })
    r2 = ReverseIn.model_validate({})
    assert r2.on_date is None and r2.memo is None


def test_period_lock_in_used_for_lock_and_unlock_by_period():
    """POST/DELETE /periods/lock используют одну схему по (entity, year, month)."""
    p = PeriodLockIn(legal_entity_id=1, year=2026, month=5)
    assert p.legal_entity_id == 1 and p.year == 2026 and p.month == 5


@pytest.mark.parametrize("month", [0, 13])
def test_period_lock_in_month_bounds(month):
    with pytest.raises(ValidationError):
        PeriodLockIn(legal_entity_id=1, year=2026, month=month)


def test_period_lock_in_year_bounds():
    with pytest.raises(ValidationError):
        PeriodLockIn(legal_entity_id=1, year=1999, month=5)


def test_cashflow_row_out_carries_activity_for_grouping():
    """ДДС-строка несёт activity (operating|investing|financing) — фронт группирует
    по виду деятельности на клиенте поверх плоского rows-контракта."""
    row = CashflowRowOut(
        category_id=1,
        category_code="O-IN-SUB",
        category_name="Поступления по подпискам",
        activity="operating",
        direction="inflow",
        net_func=Decimal("1000.00"),
    )
    assert row.activity == "operating"
