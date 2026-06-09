"""Epic 14.2 — Dismissal flow pure-function tests.

Тесты на типы / справочники / валидацию данных. Не имитируют БД.
"""
from __future__ import annotations

import pytest

from app.routers.admin_users import (
    DismissUserIn,
    RestoreUserIn,
    RightsTransferCreateIn,
)
from app.services.rights_transfer import ALLOWED_CATEGORIES, _CATEGORY_MAP


# ============ Schemas: валидация входящих ============


def test_dismiss_user_in_minimal():
    """Минимальный payload — никаких полей не требуется."""
    payload = DismissUserIn()
    assert payload.substitute_user_id is None
    assert payload.transfer_categories == []
    assert payload.reason is None


def test_dismiss_user_in_with_all_fields():
    payload = DismissUserIn(
        substitute_user_id=42,
        transfer_categories=["contacts", "deals"],
        reason="по соглашению сторон",
    )
    assert payload.substitute_user_id == 42
    assert payload.transfer_categories == ["contacts", "deals"]
    assert payload.reason == "по соглашению сторон"


def test_dismiss_user_in_partial_fields():
    payload = DismissUserIn(reason="just because")
    assert payload.reason == "just because"
    assert payload.substitute_user_id is None


def test_restore_user_in_with_new_role():
    from app.models import UserRole
    payload = RestoreUserIn(new_role=UserRole.manager)
    assert payload.new_role == UserRole.manager


def test_restore_user_in_without_role():
    payload = RestoreUserIn()
    assert payload.new_role is None


# ============ Rights transfer: валидация categories ============


def test_rights_transfer_create_requires_categories():
    """categories — обязательное поле (валидируется Pydantic)."""
    with pytest.raises(Exception):
        RightsTransferCreateIn(from_user_id=1, to_user_id=2)


def test_rights_transfer_create_full():
    payload = RightsTransferCreateIn(
        from_user_id=1, to_user_id=2,
        categories=["contacts"],
        reason="test",
    )
    assert payload.from_user_id == 1
    assert payload.to_user_id == 2


# ============ _CATEGORY_MAP: маппинг категорий на entity ============


def test_category_map_has_all_allowed_categories():
    """Каждая категория из ALLOWED_CATEGORIES должна быть в _CATEGORY_MAP."""
    for cat in ALLOWED_CATEGORIES:
        assert cat in _CATEGORY_MAP, f"Category '{cat}' missing in _CATEGORY_MAP"


def test_category_map_contacts_covers_both_fields():
    """contacts = responsible_user_id + owner_user_id Counterparty."""
    fields = {field for _, field, _ in _CATEGORY_MAP["contacts"]}
    assert fields == {"responsible_user_id", "owner_user_id"}


def test_category_map_deals_field():
    fields = {field for _, field, _ in _CATEGORY_MAP["deals"]}
    assert fields == {"owner_user_id"}


def test_category_map_leads_field():
    fields = {field for _, field, _ in _CATEGORY_MAP["leads"]}
    assert fields == {"owner_id"}


def test_category_map_tasks_use_activity_fields():
    """tasks_assigner = created_by_id, tasks_executor = responsible_id."""
    assigner_fields = {f for _, f, _ in _CATEGORY_MAP["tasks_assigner"]}
    executor_fields = {f for _, f, _ in _CATEGORY_MAP["tasks_executor"]}
    assert assigner_fields == {"created_by_id"}
    assert executor_fields == {"responsible_id"}


def test_category_map_approvals_field():
    fields = {field for _, field, _ in _CATEGORY_MAP["approvals"]}
    assert fields == {"user_id"}


def test_category_map_settings_empty_placeholder():
    """settings — placeholder, на MVP пустой list (без entity-полей)."""
    assert _CATEGORY_MAP["settings"] == []
