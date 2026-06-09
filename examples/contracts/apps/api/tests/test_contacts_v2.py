"""CONTACTS 2.0 Ф1 — unit-тесты для pure-function helpers (services/contacts_v2.py).

Pure-function pytest (без DB fixture): unified-маппинг, holding parent-conflict,
file size/path/sanitize validation.
"""
from __future__ import annotations

import pytest

from app.services.contacts_v2 import (
    CRM_ENTITY_TYPES,
    DEFAULT_FOLDERS,
    HOLDING_ROLES,
    MAX_CRM_FILE_SIZE,
    UNIFIED_KIND_COMPANY,
    UNIFIED_KIND_PERSON,
    company_to_unified,
    contact_to_unified,
    detect_parent_conflict,
    is_path_within,
    sanitize_filename,
    sort_unified,
    validate_entity_type,
    validate_file_size,
    validate_holding_role,
)


# ============ Unified mapping ============

def test_contact_to_unified_maps_full_name_to_name():
    out = contact_to_unified({
        "id": 7, "full_name": "Иван Петров", "phone": "+7999", "email": "i@x.ru",
        "owner_id": 3, "source": "cold_call", "tags": ["vip"],
        "position": "CEO", "status": "active", "company_id": 12,
    })
    assert out["id"] == 7
    assert out["kind"] == UNIFIED_KIND_PERSON
    assert out["name"] == "Иван Петров"
    assert out["phone"] == "+7999"
    assert out["owner_id"] == 3
    assert out["tags"] == ["vip"]
    assert out["extra"]["position"] == "CEO"
    assert out["extra"]["company_id"] == 12


def test_company_to_unified_prefers_common_name_over_legal():
    out = company_to_unified({
        "id": 5, "name": "ПТС Казахстан", "legal_name": "ТОО ПТС Казахстан",
        "phone": None, "email": "c@x.kz", "owner_user_id": 9, "source": "partner",
        "tags": [], "tax_id": "123", "country": "KZ", "counterparty_id": 88,
        "company_type_id": 2, "holding_role": "parent",
    })
    assert out["kind"] == UNIFIED_KIND_COMPANY
    assert out["name"] == "ПТС Казахстан"  # common name wins
    assert out["owner_id"] == 9  # mapped from owner_user_id
    assert out["extra"]["legal_name"] == "ТОО ПТС Казахстан"
    assert out["extra"]["counterparty_id"] == 88
    assert out["extra"]["holding_role"] == "parent"


def test_company_to_unified_falls_back_to_legal_name():
    out = company_to_unified({"id": 1, "name": "  ", "legal_name": "ООО Ромашка"})
    assert out["name"] == "ООО Ромашка"


def test_company_to_unified_tags_default_empty():
    out = company_to_unified({"id": 1, "legal_name": "X", "tags": None})
    assert out["tags"] == []


def test_sort_unified_case_insensitive_empty_last():
    items = [
        {"name": "Яндекс"}, {"name": ""}, {"name": "альфа"}, {"name": "Бета"},
    ]
    names = [it["name"] for it in sort_unified(items)]
    assert names == ["альфа", "Бета", "Яндекс", ""]


# ============ Holding parent-conflict ============

def test_detect_parent_conflict_returns_existing_parent():
    existing = [(1, "parent"), (2, "subsidiary"), (3, "subsidiary")]
    # company 3 wants to become parent → conflict with company 1
    assert detect_parent_conflict(existing, company_id=3, new_role="parent") == 1


def test_detect_parent_conflict_none_when_self_is_parent():
    existing = [(1, "parent"), (2, "subsidiary")]
    # company 1 re-asserts parent → no conflict (it's itself)
    assert detect_parent_conflict(existing, company_id=1, new_role="parent") is None


def test_detect_parent_conflict_none_for_subsidiary():
    existing = [(1, "parent")]
    assert detect_parent_conflict(existing, company_id=5, new_role="subsidiary") is None


def test_detect_parent_conflict_none_when_no_parent_yet():
    existing = [(2, "subsidiary"), (3, "subsidiary")]
    assert detect_parent_conflict(existing, company_id=4, new_role="parent") is None


def test_validate_holding_role_ok():
    for r in HOLDING_ROLES:
        validate_holding_role(r)  # no raise


def test_validate_holding_role_bad():
    with pytest.raises(ValueError):
        validate_holding_role("owner")


# ============ File validation ============

def test_validate_entity_type_ok():
    for t in CRM_ENTITY_TYPES:
        validate_entity_type(t)


def test_validate_entity_type_bad():
    # Wave 4: 'deal' стал валидным (файлы на сделке). Невалидный — произвольный.
    with pytest.raises(ValueError):
        validate_entity_type("lead")


def test_validate_file_size_ok():
    validate_file_size(MAX_CRM_FILE_SIZE)  # exactly 20MB allowed


def test_validate_file_size_too_big():
    with pytest.raises(ValueError):
        validate_file_size(MAX_CRM_FILE_SIZE + 1)


@pytest.mark.parametrize("raw,expected_safe", [
    ("normal.pdf", "normal.pdf"),
    ("../../etc/passwd", "passwd"),
    ("/abs/path/док.docx", "док.docx"),
    ("with spaces (1).xlsx", "with spaces (1).xlsx"),
    ("..", "file"),
    (".", "file"),
    ("", "file"),
    (None, "file"),
    (".hidden", "hidden"),
    ("a/b\\c.txt", "c.txt"),
])
def test_sanitize_filename(raw, expected_safe):
    assert sanitize_filename(raw) == expected_safe


def test_sanitize_filename_strips_dangerous_chars():
    out = sanitize_filename("rm;rf*?.sh")
    assert "/" not in out and ";" not in out and "*" not in out and "?" not in out


def test_is_path_within_true():
    assert is_path_within("/data/storage/crm_files", "/data/storage/crm_files/contact/5/a.pdf")


def test_is_path_within_traversal_blocked():
    assert not is_path_within("/data/storage/crm_files", "/data/storage/crm_files/../../etc/passwd")


def test_is_path_within_sibling_blocked():
    assert not is_path_within("/data/storage/crm_files", "/data/storage/crm_files_evil/x")


def test_default_folders_has_system_flag():
    names = {n: sys for n, sys in DEFAULT_FOLDERS}
    assert names["Системная"] is True
    assert names["Документы"] is False


# ---- CONTACTS 2.0 alignment: Out-схемы обогащены полями для фронта ----

def test_contact_deal_out_has_enriched_fields():
    """ContactDealOut несёт pipeline_name / responsible_name / created_at для UI."""
    from datetime import datetime

    from app.routers.contacts import ContactDealOut

    now = datetime(2026, 6, 2, 12, 0, 0)
    out = ContactDealOut(
        id=1, title="Сделка", pipeline_id=2, stage_id=3,
        pipeline_name="Продажи", stage_name="Квалификация",
        amount=100.0, currency="RUB",
        owner_user_id=5, responsible_name="Иван Иванов",
        company_id=7, counterparty_id=8, created_at=now,
    )
    assert out.pipeline_name == "Продажи"
    assert out.responsible_name == "Иван Иванов"
    assert out.created_at == now
    # дефолты остаются None, поля опциональны
    assert ContactDealOut(id=1, title="x", pipeline_id=2, stage_id=3).pipeline_name is None


def test_holding_member_out_has_geo_fields():
    """HoldingMemberOut несёт country/city для UI-списка участников холдинга."""
    from app.routers.companies import HoldingMemberOut

    out = HoldingMemberOut(
        company_id=1, name="ООО Ромашка", legal_name="ООО «Ромашка»",
        holding_role="parent", country="RU", city="Москва",
    )
    assert out.country == "RU"
    assert out.city == "Москва"
    # поля опциональны
    bare = HoldingMemberOut(company_id=2, legal_name="ООО «Тест»")
    assert bare.country is None and bare.city is None


# ============ Download mime whitelist (XSS-защита) ============

def test_safe_inline_mime_excludes_html_and_svg():
    """text/html и image/svg+xml НЕ в whitelist — форсятся в octet-stream."""
    from app.routers.contacts_v2 import _SAFE_INLINE_MIME

    assert "text/html" not in _SAFE_INLINE_MIME
    assert "image/svg+xml" not in _SAFE_INLINE_MIME
    assert "application/xhtml+xml" not in _SAFE_INLINE_MIME


def test_safe_inline_mime_includes_pdf_and_images():
    """PDF и растровые картинки безопасны для inline."""
    from app.routers.contacts_v2 import _SAFE_INLINE_MIME

    assert "application/pdf" in _SAFE_INLINE_MIME
    assert "image/png" in _SAFE_INLINE_MIME
    assert "image/jpeg" in _SAFE_INLINE_MIME


# ============ merge company relinker покрывает все FK ============

def test_merge_company_relinker_covers_all_fk_tables():
    """_relink_company_fks должен трогать Deal / ClientSubscription /
    ContactCompanyLink / Folder / File / Contact / Activity — иначе при merge
    данные осиротеют."""
    import inspect

    from app.services import merge

    src = inspect.getsource(merge._relink_company_fks)
    for token in (
        "Deal", "ClientSubscription", "ContactCompanyLink",
        "Folder", "CrmFile", "Contact", "Activity",
    ):
        assert token in src, f"_relink_company_fks не покрывает {token}"
