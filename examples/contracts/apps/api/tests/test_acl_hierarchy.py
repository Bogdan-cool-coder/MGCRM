"""P3 security tail (A1/A2 WARNING+REFACTORING) — pure-function unit tests.

Покрывает извлечённую из async-роутеров чистую логику:
- manager-chain (vacation approve scope) — is_in_manager_chain_pure
- cycle detection при назначении руководителя — manager_assignment_creates_cycle
- _extract_bearer (переиспользован в oauth /userinfo)
- UserPickerOut не содержит чувствительных полей (PII-leak фикс)
"""
from __future__ import annotations

from app.deps import _extract_bearer
from app.routers.departments import manager_assignment_creates_cycle
from app.routers.vacations import is_in_manager_chain_pure
from app.schemas import UserPickerOut


# ============ manager-chain (vacation approve scope) ============

def test_chain_direct_manager():
    # 1 -> manager 2
    assert is_in_manager_chain_pure(
        {1: 2, 2: None}, employee_id=1, approver_id=2
    ) is True


def test_chain_transitive_manager():
    # 1 -> 2 -> 3 ; 3 руководит 1 транзитивно
    assert is_in_manager_chain_pure(
        {1: 2, 2: 3, 3: None}, employee_id=1, approver_id=3
    ) is True


def test_chain_not_in_chain():
    # 99 не в цепочке руководителей сотрудника 1
    assert is_in_manager_chain_pure(
        {1: 2, 2: 3, 3: None}, employee_id=1, approver_id=99
    ) is False


def test_chain_self_is_not_approver():
    # сам сотрудник не считается своим руководителем
    assert is_in_manager_chain_pure(
        {1: 2, 2: None}, employee_id=1, approver_id=1
    ) is False


def test_chain_handles_broken_cycle_without_hang():
    # битые данные 1 -> 2 -> 1 не должны зациклить и approver вне цикла -> False
    assert is_in_manager_chain_pure(
        {1: 2, 2: 1}, employee_id=1, approver_id=42
    ) is False


def test_chain_no_manager():
    assert is_in_manager_chain_pure(
        {1: None}, employee_id=1, approver_id=2
    ) is False


# ============ cycle detection (assign_user_department) ============

def test_cycle_self_assignment():
    assert manager_assignment_creates_cycle(
        {}, user_id=1, new_manager_id=1
    ) is True


def test_cycle_direct_back_reference():
    # 2 уже подчинён 1 -> назначить 2 руководителем 1 = цикл
    assert manager_assignment_creates_cycle(
        {2: 1, 1: None}, user_id=1, new_manager_id=2
    ) is True


def test_cycle_transitive_back_reference():
    # 3 -> 2 -> 1 ; назначить 3 руководителем 1 = цикл
    assert manager_assignment_creates_cycle(
        {3: 2, 2: 1, 1: None}, user_id=1, new_manager_id=3
    ) is True


def test_cycle_valid_assignment_ok():
    # 5 не подчинён 1 -> назначить 5 руководителем 1 безопасно
    assert manager_assignment_creates_cycle(
        {5: None, 1: None}, user_id=1, new_manager_id=5
    ) is False


def test_cycle_handles_preexisting_broken_data():
    # уже битый цикл 5 -> 6 -> 5, user_id=1 не в нём -> False, без зависания
    assert manager_assignment_creates_cycle(
        {5: 6, 6: 5}, user_id=1, new_manager_id=5
    ) is False


# ============ _extract_bearer (oauth /userinfo refactor) ============

def test_extract_bearer_standard():
    assert _extract_bearer("Bearer abc123") == "abc123"


def test_extract_bearer_lowercase():
    assert _extract_bearer("bearer abc123") == "abc123"


def test_extract_bearer_extra_spaces():
    assert _extract_bearer("Bearer    abc123  ") == "abc123"


def test_extract_bearer_empty_token():
    assert _extract_bearer("Bearer ") is None


def test_extract_bearer_none():
    assert _extract_bearer(None) is None


def test_extract_bearer_not_bearer():
    assert _extract_bearer("Basic abc123") is None


# ============ UserPickerOut — PII leak fix ============

def test_user_picker_out_omits_sensitive_fields():
    fields = set(UserPickerOut.model_fields)
    # чувствительные поля НЕ должны утекать рядовым ролям
    assert "telegram_user_id" not in fields
    assert "manager_id" not in fields
    assert "department_id" not in fields
    assert "signature_url" not in fields
    # минимально нужное для пикеров — присутствует
    assert {"id", "full_name", "role", "email"} <= fields
