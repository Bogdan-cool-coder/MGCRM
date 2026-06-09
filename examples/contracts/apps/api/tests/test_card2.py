"""Card 2.0 (Эпик 8) — pure-function тесты сервисов и схем.

Без DB-фикстуры: проверяем whitelist'ы, нормализаторы, валидацию, scoring,
правила доступа, структуру миграции 0026.
"""
from __future__ import annotations

from datetime import date, datetime, UTC
from decimal import Decimal
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import MagicMock

import pytest
from fastapi import HTTPException

from app.models import CustomFieldDef, EntityAuditLog, SavedFilter, User, UserRole
from app.routers.audit import AuditLogEntryOut
from app.routers.custom_fields import (
    CustomFieldDefCreate,
    CustomFieldDefOut,
    ExtraFieldsPatch,
)
from app.routers.duplicates import (
    DismissIn,
    DuplicateGroupOut,
    MergeIn,
)
from app.routers.saved_filters import (
    SAVED_FILTER_PAGE_KEYS,
    SavedFilterCreate,
    can_read_filter,
    can_write_filter,
)
from app.routers.search import SearchResultItem
from app.services.audit import (
    AUDIT_ACTIONS,
    AUDIT_ENTITY_TYPES,
    DEFAULT_IGNORE_KEYS,
    compute_diff,
    snapshot_entity,
    validate_action,
    validate_entity_type,
    _serialize_for_diff,
)
from app.services.custom_fields import (
    CUSTOM_FIELD_KINDS,
    CUSTOM_FIELD_SCOPES,
    normalize_value,
    validate_extra_fields,
    validate_kind,
    validate_scope,
)
from app.services.duplicates import (
    DUPLICATE_ENTITY_TYPES,
    compute_similarity,
    normalize_email,
    normalize_name,
    normalize_pair,
    normalize_phone,
)
from app.services.merge import MERGE_FIELDS
from app.services.search import (
    MAX_QUERY_LENGTH,
    MIN_QUERY_LENGTH,
    SEARCH_ENTITY_TYPES,
    _sanitize_query,
    validate_query,
)


# ============================================================================
# Whitelist'ы — фиксация набора значений (контракты с frontend)
# ============================================================================


def test_custom_field_scopes_whitelist():
    """7 scope'ов — sync с frontend EntityScope."""
    assert CUSTOM_FIELD_SCOPES == (
        "lead", "contact", "company", "counterparty",
        "deal", "contract", "subscription",
    )
    assert len(set(CUSTOM_FIELD_SCOPES)) == len(CUSTOM_FIELD_SCOPES)


def test_custom_field_kinds_whitelist():
    """8 kind'ов — sync с frontend CustomFieldKind."""
    assert CUSTOM_FIELD_KINDS == (
        "text", "textarea", "number", "date",
        "select", "multiselect", "url", "checkbox",
    )


def test_duplicate_entity_types_whitelist():
    """4 entity'ы для дублескана."""
    assert DUPLICATE_ENTITY_TYPES == ("counterparty", "contact", "company", "lead")


def test_audit_actions_whitelist():
    """Базовые 6 actions (sync с frontend AuditAction) + fin-actions (Ф1)."""
    assert AUDIT_ACTIONS == (
        "create", "update", "delete", "merge",
        "extra_fields_change", "bulk_action",
        # fin-actions модуля «Финансы» (Ф1): проводка/сторно/закрытие/открытие периода.
        "post", "reverse", "lock_period", "unlock_period",
        # fin-actions Ф2: жизненный цикл заявок/реестров/согласования.
        "submit", "approve_decision", "fulfill",
        # fin-actions Ф5: жизненный цикл инвойсов/актов/вендор-счетов.
        "issue", "confirm", "pay", "cancel",
        # fin-actions Ф4: признание выручки / переоценка / смена базовой валюты.
        "recognize", "revalue", "change_base",
    )


def test_audit_entity_types_whitelist():
    """CRM-entity (== custom field scopes) + fin_* (Ф1 — аудит финмутаций).

    Аудит финмодуля раскоррелирован с custom-field scopes: fin-сущности логируются,
    но не имеют кастом-полей. Поэтому проверяем, что CRM-scopes ⊆ AUDIT_ENTITY_TYPES
    и что добавлены ровно ожидаемые fin_*-типы.
    """
    assert set(CUSTOM_FIELD_SCOPES).issubset(set(AUDIT_ENTITY_TYPES))
    fin_types = {et for et in AUDIT_ENTITY_TYPES if et.startswith("fin_")}
    assert fin_types == {
        "fin_operation", "fin_journal_entry", "fin_manual_journal",
        "fin_period_lock", "fin_permission",
        # Ф2: реестр/заявки/сценарии/голоса согласования.
        "fin_request", "fin_payment_registry", "fin_approval_scenario", "fin_approval",
        # Ф5: инвойсы/акты/вендор-счета.
        "fin_invoice", "fin_act", "fin_vendor_bill",
        # Ф4: план признания выручки + задание пересчёта базы.
        "fin_revenue_schedule", "fin_base_recompute_job",
    }


def test_search_entity_types_whitelist():
    """6 entity types для search (no subscription)."""
    assert SEARCH_ENTITY_TYPES == (
        "lead", "contact", "company", "counterparty", "deal", "contract",
    )


def test_saved_filter_page_keys():
    """6 page_keys — sync с frontend PageKey."""
    assert SAVED_FILTER_PAGE_KEYS == (
        "leads", "contacts", "companies", "counterparties", "deals", "registry",
    )


def test_merge_fields_covers_all_duplicate_entities():
    """Для каждого dup-entity есть MERGE_FIELDS whitelist."""
    for et in DUPLICATE_ENTITY_TYPES:
        assert et in MERGE_FIELDS, f"MERGE_FIELDS не покрывает {et}"
        assert len(MERGE_FIELDS[et]) > 0, f"MERGE_FIELDS[{et}] не должен быть пустой"


def test_merge_fields_include_extra_fields():
    """extra_fields должен быть мерджимым полем — иначе кастомка теряется."""
    for et in DUPLICATE_ENTITY_TYPES:
        assert "extra_fields" in MERGE_FIELDS[et], (
            f"MERGE_FIELDS[{et}] должен включать extra_fields"
        )


# ============================================================================
# validate_scope / validate_kind
# ============================================================================


def test_validate_scope_ok():
    for s in CUSTOM_FIELD_SCOPES:
        validate_scope(s)  # не должен бросить


def test_validate_scope_invalid():
    with pytest.raises(HTTPException) as ex:
        validate_scope("invalid_scope")
    assert ex.value.status_code == 400


def test_validate_kind_ok():
    for k in CUSTOM_FIELD_KINDS:
        validate_kind(k)


def test_validate_kind_invalid():
    with pytest.raises(HTTPException) as ex:
        validate_kind("invalid_kind")
    assert ex.value.status_code == 400


# ============================================================================
# normalize_value (custom_fields)
# ============================================================================


def test_normalize_value_none_returns_none():
    for k in CUSTOM_FIELD_KINDS:
        if k in ("checkbox", "multiselect"):
            continue  # эти имеют особое поведение для None
        assert normalize_value(k, None) is None


def test_normalize_value_text_to_str():
    assert normalize_value("text", "hello") == "hello"
    assert normalize_value("text", 42) == "42"
    assert normalize_value("textarea", "  text  ") == "  text  "


def test_normalize_value_text_empty_to_none():
    assert normalize_value("text", "") is None
    assert normalize_value("text", "   ") is None


def test_normalize_value_text_too_long_raises():
    # JSONB-bloat guard: строковое значение длиннее лимита → 422.
    with pytest.raises(HTTPException) as exc:
        normalize_value("text", "x" * 10_001)
    assert exc.value.status_code == 422


def test_normalize_value_multiselect_too_many_items_raises():
    with pytest.raises(HTTPException) as exc:
        normalize_value("multiselect", [str(i) for i in range(201)])
    assert exc.value.status_code == 422


def test_normalize_value_number_to_float():
    assert normalize_value("number", 42) == 42.0
    assert normalize_value("number", "12.5") == 12.5
    assert normalize_value("number", 0) == 0.0


def test_normalize_value_number_invalid_raises_422():
    with pytest.raises(HTTPException) as ex:
        normalize_value("number", "not a number")
    assert ex.value.status_code == 422


def test_normalize_value_date_iso():
    assert normalize_value("date", "2026-05-31") == "2026-05-31"


def test_normalize_value_date_ddmmyyyy():
    """Формат ДД.ММ.ГГГГ конвертируется в ISO."""
    assert normalize_value("date", "31.05.2026") == "2026-05-31"


def test_normalize_value_date_from_date_obj():
    assert normalize_value("date", date(2026, 5, 31)) == "2026-05-31"


def test_normalize_value_date_invalid_raises_422():
    with pytest.raises(HTTPException) as ex:
        normalize_value("date", "not a date")
    assert ex.value.status_code == 422


def test_normalize_value_checkbox():
    assert normalize_value("checkbox", True) is True
    assert normalize_value("checkbox", False) is False
    assert normalize_value("checkbox", "true") is True
    assert normalize_value("checkbox", "false") is False
    assert normalize_value("checkbox", "да") is True
    assert normalize_value("checkbox", 1) is True
    assert normalize_value("checkbox", 0) is False


def test_normalize_value_multiselect():
    assert normalize_value("multiselect", ["a", "b"]) == ["a", "b"]
    assert normalize_value("multiselect", []) == []
    assert normalize_value("multiselect", [1, 2]) == ["1", "2"]


def test_normalize_value_multiselect_non_list_raises_422():
    with pytest.raises(HTTPException) as ex:
        normalize_value("multiselect", "not a list")
    assert ex.value.status_code == 422


def test_normalize_value_select_to_str():
    assert normalize_value("select", "option_a") == "option_a"
    assert normalize_value("select", 1) == "1"


def test_normalize_value_url_to_str():
    assert normalize_value("url", "https://example.com") == "https://example.com"


# ============================================================================
# validate_extra_fields (required, types, options)
# ============================================================================


def _make_def(scope, code, kind, *, is_required=False, options=None, is_active=True):
    """Создать mock CustomFieldDef без БД."""
    return SimpleNamespace(
        id=1,
        entity_scope=scope,
        code=code,
        label_ru=code.upper(),
        kind=kind,
        is_required=is_required,
        default_value=None,
        options_json=options or [],
        sort_order=0,
        is_active=is_active,
    )


def test_validate_extra_fields_required_missing_raises_422():
    defs = [_make_def("lead", "region", "text", is_required=True)]
    with pytest.raises(HTTPException) as ex:
        validate_extra_fields("lead", {}, defs)
    assert ex.value.status_code == 422
    assert "field_errors" in ex.value.detail
    assert "region" in ex.value.detail["field_errors"]


def test_validate_extra_fields_required_empty_string_raises_422():
    defs = [_make_def("lead", "region", "text", is_required=True)]
    with pytest.raises(HTTPException) as ex:
        validate_extra_fields("lead", {"region": "  "}, defs)
    assert ex.value.status_code == 422


def test_validate_extra_fields_unknown_field_raises_422():
    defs = [_make_def("lead", "region", "text")]
    with pytest.raises(HTTPException) as ex:
        validate_extra_fields("lead", {"unknown_field": "x"}, defs)
    assert ex.value.status_code == 422
    assert "unknown_field" in ex.value.detail["field_errors"]


def test_validate_extra_fields_select_options_enforced():
    defs = [
        _make_def("lead", "potential", "select", options=["high", "medium", "low"]),
    ]
    # Допустимое — ok
    result = validate_extra_fields("lead", {"potential": "high"}, defs)
    assert result == {"potential": "high"}
    # Недопустимое — 422
    with pytest.raises(HTTPException) as ex:
        validate_extra_fields("lead", {"potential": "extreme"}, defs)
    assert ex.value.status_code == 422
    assert "potential" in ex.value.detail["field_errors"]


def test_validate_extra_fields_multiselect_options_enforced():
    defs = [_make_def("lead", "tags", "multiselect", options=["A", "B", "C"])]
    # Допустимое
    result = validate_extra_fields("lead", {"tags": ["A", "B"]}, defs)
    assert result == {"tags": ["A", "B"]}
    # Один из вариантов недопустим
    with pytest.raises(HTTPException) as ex:
        validate_extra_fields("lead", {"tags": ["A", "X"]}, defs)
    assert ex.value.status_code == 422


def test_validate_extra_fields_inactive_def_ignored_for_required():
    """Inactive def не проверяется на required (soft-delete не блокирует update'ы)."""
    defs = [
        _make_def("lead", "region", "text", is_required=True, is_active=False),
    ]
    # required=True но is_active=False — не должно бросить
    result = validate_extra_fields("lead", {}, defs)
    assert result == {}


def test_validate_extra_fields_normalize_runs():
    """Date конвертируется в ISO, number — во float."""
    defs = [
        _make_def("lead", "kpi_date", "date"),
        _make_def("lead", "potential_score", "number"),
    ]
    result = validate_extra_fields(
        "lead",
        {"kpi_date": "31.05.2026", "potential_score": "7.5"},
        defs,
    )
    assert result == {"kpi_date": "2026-05-31", "potential_score": 7.5}


def test_validate_extra_fields_scope_filter():
    """defs от другого scope не должны участвовать в валидации текущего."""
    defs = [
        _make_def("lead", "region", "text", is_required=True),
        _make_def("contact", "phone_type", "text", is_required=True),  # другой scope
    ]
    # Для contact-scope только phone_type должен требоваться
    with pytest.raises(HTTPException) as ex:
        validate_extra_fields("contact", {}, defs)
    err = ex.value.detail["field_errors"]
    assert "phone_type" in err
    assert "region" not in err


# ============================================================================
# Duplicates — нормализация
# ============================================================================


def test_normalize_email():
    assert normalize_email("FOO@BAR.COM") == "foo@bar.com"
    assert normalize_email("  foo@bar.com  ") == "foo@bar.com"
    assert normalize_email(None) == ""
    assert normalize_email("") == ""


def test_normalize_phone_extracts_digits():
    assert normalize_phone("+7 (701) 234 56 78") == "7012345678"
    assert normalize_phone("8-701-234-56-78") == "7012345678"
    assert normalize_phone("701-234-56-78") == "7012345678"


def test_normalize_phone_returns_last_10_digits():
    """Длинные международные — только последние 10."""
    assert normalize_phone("+1 234 567 8901 0") == "3456789010"


def test_normalize_phone_short_kept():
    """Короткие номера не падают."""
    assert normalize_phone("12345") == "12345"


def test_normalize_phone_empty():
    assert normalize_phone(None) == ""
    assert normalize_phone("") == ""
    assert normalize_phone("abc") == ""


def test_normalize_name_strips_legal_forms():
    assert normalize_name("ООО Ромашка") == "ромашка"
    assert normalize_name("ИП Иванов") == "иванов"
    assert normalize_name("ТОО Macroglobal") == "macroglobal"
    assert normalize_name("Macroglobal Tech Ltd.") == "macroglobal tech"
    assert normalize_name("Acme LLC") == "acme"


def test_normalize_name_removes_quotes():
    assert normalize_name('ООО "Ромашка"') == "ромашка"
    assert normalize_name("ООО «Ромашка»") == "ромашка"


def test_normalize_name_collapses_spaces():
    assert normalize_name("  Multi    Spaces   ") == "multi spaces"


def test_normalize_name_empty():
    assert normalize_name(None) == ""
    assert normalize_name("") == ""


# ============================================================================
# Duplicates — scoring
# ============================================================================


def test_similarity_zero_when_no_overlap():
    a = {"email": "a@a.com", "phone": "1111111111", "name": "alpha"}
    b = {"email": "b@b.com", "phone": "2222222222", "name": "bravo"}
    assert compute_similarity(a, b) == 0


def test_similarity_email_match():
    a = {"email": "x@y.com", "name": "alpha"}
    b = {"email": "x@y.com", "name": "bravo"}
    assert compute_similarity(a, b) == 50


def test_similarity_phone_match():
    a = {"phone": "7012345678", "name": "alpha"}
    b = {"phone": "7012345678", "name": "bravo"}
    assert compute_similarity(a, b) == 30


def test_similarity_tax_id_match():
    a = {"tax_id": "771234567", "name": "alpha"}
    b = {"tax_id": "771234567", "name": "bravo"}
    assert compute_similarity(a, b) == 40


def test_similarity_name_exact_match():
    a = {"name": "ромашка"}
    b = {"name": "ромашка"}
    assert compute_similarity(a, b) == 25


def test_similarity_name_substring_partial():
    a = {"name": "ромашка kz"}
    b = {"name": "ромашка"}
    assert compute_similarity(a, b) == 10


def test_similarity_sum_capped_at_100():
    """email + phone + tax_id = 120 → обрезается до 100."""
    a = {
        "email": "x@y.com", "phone": "7012345678",
        "tax_id": "12345", "name": "alpha",
    }
    b = {
        "email": "x@y.com", "phone": "7012345678",
        "tax_id": "12345", "name": "alpha",
    }
    assert compute_similarity(a, b) == 100


def test_similarity_empty_strings_dont_match():
    """email = '' не должен матчиться с email = ''."""
    a = {"email": "", "phone": "", "name": ""}
    b = {"email": "", "phone": "", "name": ""}
    assert compute_similarity(a, b) == 0


# ============================================================================
# Duplicates — normalize_pair (для unique-constraint)
# ============================================================================


def test_normalize_pair_orders_ascending():
    assert normalize_pair(5, 3) == (3, 5)
    assert normalize_pair(3, 5) == (3, 5)


def test_normalize_pair_same_raises():
    with pytest.raises(ValueError):
        normalize_pair(7, 7)


# ============================================================================
# Audit — diff helper
# ============================================================================


def test_compute_diff_no_changes_returns_empty():
    before = {"name": "alpha", "amount": 100}
    after = {"name": "alpha", "amount": 100}
    assert compute_diff(before, after) == {}


def test_compute_diff_simple_change():
    before = {"name": "alpha", "amount": 100}
    after = {"name": "beta", "amount": 100}
    diff = compute_diff(before, after)
    assert diff == {"name": {"old": "alpha", "new": "beta"}}


def test_compute_diff_ignores_default_keys():
    """updated_at / created_at / др. служебные — НЕ в diff'е."""
    before = {"name": "x", "updated_at": "2026-05-30"}
    after = {"name": "x", "updated_at": "2026-05-31"}
    assert compute_diff(before, after) == {}


def test_compute_diff_added_key():
    before = {"name": "x"}
    after = {"name": "x", "phone": "+7..."}
    diff = compute_diff(before, after)
    assert "phone" in diff
    assert diff["phone"]["old"] is None
    assert diff["phone"]["new"] == "+7..."


def test_compute_diff_removed_key():
    before = {"name": "x", "phone": "+7..."}
    after = {"name": "x"}
    diff = compute_diff(before, after)
    assert "phone" in diff
    assert diff["phone"]["new"] is None


def test_compute_diff_decimal_to_float():
    """Decimal значения сериализуются в float для diff."""
    before = {"amount": Decimal("100.50")}
    after = {"amount": Decimal("200.75")}
    diff = compute_diff(before, after)
    assert diff == {"amount": {"old": 100.5, "new": 200.75}}


def test_compute_diff_datetime_to_iso():
    """datetime сериализуется в ISO."""
    before = {"ts": datetime(2026, 5, 30, tzinfo=UTC)}
    after = {"ts": datetime(2026, 5, 31, tzinfo=UTC)}
    diff = compute_diff(before, after)
    assert "ts" in diff
    assert "2026-05-30" in diff["ts"]["old"]
    assert "2026-05-31" in diff["ts"]["new"]


def test_compute_diff_custom_ignore_keys():
    """Кастомный ignore_keys работает в дополнение / вместо default'а."""
    before = {"a": 1, "b": 2}
    after = {"a": 10, "b": 20}
    diff = compute_diff(before, after, ignore_keys={"a"})
    assert "a" not in diff
    assert "b" in diff


def test_default_ignore_keys_includes_updated_at():
    """В default ignore — updated_at, created_at, и др. служебные."""
    assert "updated_at" in DEFAULT_IGNORE_KEYS
    assert "created_at" in DEFAULT_IGNORE_KEYS
    assert "stage_changed_at" in DEFAULT_IGNORE_KEYS


def test_validate_audit_action():
    for a in AUDIT_ACTIONS:
        validate_action(a)
    with pytest.raises(ValueError):
        validate_action("invalid")


def test_validate_audit_entity_type():
    for t in AUDIT_ENTITY_TYPES:
        validate_entity_type(t)
    with pytest.raises(ValueError):
        validate_entity_type("invalid")


def test_serialize_for_diff_handles_nested():
    """Вложенные структуры сериализуются."""
    v = {"a": [Decimal("1.5"), date(2026, 5, 31)], "b": {"c": Decimal("2")}}
    out = _serialize_for_diff(v)
    assert out == {"a": [1.5, "2026-05-31"], "b": {"c": 2.0}}


def test_snapshot_entity_picks_only_requested_fields():
    """snapshot_entity извлекает только заданные поля + сериализует."""
    entity = SimpleNamespace(
        name="Test", amount=Decimal("100.50"), notes="ignored", id=42,
    )
    snap = snapshot_entity(entity, ["name", "amount"])
    assert snap == {"name": "Test", "amount": 100.5}
    assert "notes" not in snap
    assert "id" not in snap


def test_snapshot_entity_missing_field_skipped():
    """Если поля нет на объекте — не падаем, просто пропускаем."""
    entity = SimpleNamespace(name="X")
    snap = snapshot_entity(entity, ["name", "missing_field"])
    assert snap == {"name": "X"}


# ============================================================================
# Saved filters — ACL
# ============================================================================


def _make_user(uid: int, role: UserRole = UserRole.manager) -> User:
    """Mock User объект."""
    u = MagicMock(spec=User)
    u.id = uid
    u.role = role
    return u


def _make_filter(filter_id: int, user_id: int | None) -> SavedFilter:
    f = MagicMock(spec=SavedFilter)
    f.id = filter_id
    f.user_id = user_id
    return f


def test_can_read_own_filter():
    user = _make_user(42)
    f = _make_filter(1, 42)
    assert can_read_filter(f, user) is True


def test_can_read_global_filter():
    user = _make_user(42, UserRole.manager)
    f = _make_filter(1, None)
    assert can_read_filter(f, user) is True


def test_cannot_read_other_users_filter():
    user = _make_user(42)
    f = _make_filter(1, 99)  # чужой
    assert can_read_filter(f, user) is False


def test_can_write_own_filter():
    user = _make_user(42)
    f = _make_filter(1, 42)
    assert can_write_filter(f, user) is True


def test_admin_can_write_global_filter():
    admin = _make_user(1, UserRole.admin)
    f = _make_filter(1, None)
    assert can_write_filter(f, admin) is True


def test_manager_cannot_write_global_filter():
    """Manager НЕ может удалить глобальный фильтр."""
    user = _make_user(42, UserRole.manager)
    f = _make_filter(1, None)
    assert can_write_filter(f, user) is False


def test_cannot_write_other_users_filter():
    user = _make_user(42)
    f = _make_filter(1, 99)
    assert can_write_filter(f, user) is False


# ============================================================================
# Search — sanitize / validate query
# ============================================================================


def test_sanitize_query_strips_whitespace():
    assert _sanitize_query("  hello  ") == "hello"


def test_sanitize_query_truncates_to_max_length():
    long_q = "a" * 200
    assert len(_sanitize_query(long_q)) == MAX_QUERY_LENGTH


def test_sanitize_query_escapes_wildcards():
    """% и _ — wildcards ILIKE, должны экранироваться."""
    assert _sanitize_query("50%") == r"50\%"
    assert _sanitize_query("a_b") == r"a\_b"


def test_sanitize_query_escapes_backslash():
    """\\ → \\\\ для PG escape."""
    assert "\\\\" in _sanitize_query("foo\\bar")


def test_validate_query_too_short_raises():
    with pytest.raises(ValueError):
        validate_query("a")  # 1 char
    with pytest.raises(ValueError):
        validate_query("")


def test_validate_query_returns_sanitized():
    s = validate_query("  hello  ")
    assert s == "hello"


def test_min_query_length():
    assert MIN_QUERY_LENGTH == 2


def test_search_entity_types_consistent_with_frontend():
    """6 типов sync с фронтом (нет subscription пока)."""
    assert "subscription" not in SEARCH_ENTITY_TYPES


# ============================================================================
# Pydantic schemas — sanity
# ============================================================================


def test_custom_field_def_create_validates_code_snake_case():
    """Код должен быть snake_case (latin + digits + _)."""
    # Валидно
    CustomFieldDefCreate(
        entity_scope="lead", code="my_field", label_ru="Поле", kind="text",
    )
    CustomFieldDefCreate(
        entity_scope="lead", code="region2", label_ru="Регион", kind="text",
    )
    # Невалидно — кириллица
    with pytest.raises(Exception):
        CustomFieldDefCreate(
            entity_scope="lead", code="регион", label_ru="x", kind="text",
        )
    # Невалидно — начинается с цифры
    with pytest.raises(Exception):
        CustomFieldDefCreate(
            entity_scope="lead", code="1region", label_ru="x", kind="text",
        )


def test_extra_fields_patch_default_empty_dict():
    """Пустой body → extra_fields={}, no error."""
    p = ExtraFieldsPatch()
    assert p.extra_fields == {}


def test_dismiss_in_required_fields():
    """DismissIn требует entity_type + a_id + b_id."""
    d = DismissIn(entity_type="counterparty", entity_a_id=1, entity_b_id=2)
    assert d.entity_type == "counterparty"


def test_merge_in_field_choices_default():
    """MergeIn: field_choices default = {} (просто перепривязать FK без смены полей)."""
    m = MergeIn(entity_type="counterparty", primary_id=1, secondary_id=2)
    assert m.field_choices == {}


def test_saved_filter_create_default_pinned_false():
    """SavedFilterCreate: is_pinned default False, filter_json default {}."""
    f = SavedFilterCreate(page_key="leads", name="Test")
    assert f.is_pinned is False
    assert f.filter_json == {}


def test_search_result_item_secondary_optional():
    item = SearchResultItem(entity_type="lead", id=1, display_name="X")
    assert item.secondary is None


# ============================================================================
# Структура миграции 0026
# ============================================================================


def test_migration_0026_exists_and_revises_0025():
    """Миграция 0026 продолжает 0025."""
    path = (
        Path(__file__).resolve().parents[1]
        / "alembic" / "versions" / "0026_card2_extensions.py"
    )
    assert path.exists()
    src = path.read_text(encoding="utf-8")
    assert 'revision: str = "0026_card2_extensions"' in src
    assert 'down_revision: Union[str, None] = "0025_sequences"' in src


def test_migration_0026_adds_extra_fields_to_7_tables():
    """Колонка extra_fields добавляется на 7 сущностях."""
    path = (
        Path(__file__).resolve().parents[1]
        / "alembic" / "versions" / "0026_card2_extensions.py"
    )
    src = path.read_text(encoding="utf-8")
    for tbl in (
        "leads", "crm_contacts", "crm_companies", "counterparties",
        "deals", "contracts", "client_subscriptions",
    ):
        assert f'"{tbl}"' in src, f"Таблица {tbl} не упомянута в миграции 0026"


def test_migration_0026_creates_4_new_tables():
    """Новые таблицы: custom_field_defs, entity_audit_logs, dismissed_duplicates,
    saved_filters."""
    path = (
        Path(__file__).resolve().parents[1]
        / "alembic" / "versions" / "0026_card2_extensions.py"
    )
    src = path.read_text(encoding="utf-8")
    for tbl in (
        "custom_field_defs",
        "entity_audit_logs",
        "dismissed_duplicates",
        "saved_filters",
    ):
        assert f'create_table(\n        "{tbl}"' in src, (
            f"create_table('{tbl}') должен быть в миграции 0026"
        )


def test_migration_0026_has_required_indexes():
    """Композитные/горячие индексы созданы."""
    path = (
        Path(__file__).resolve().parents[1]
        / "alembic" / "versions" / "0026_card2_extensions.py"
    )
    src = path.read_text(encoding="utf-8")
    for ix in (
        "ix_custom_field_defs_entity_scope",
        "ix_custom_field_defs_is_active",
        "ix_entity_audit_logs_entity_occurred",
        "ix_dismissed_duplicates_entity",
        "ix_saved_filters_user_page",
        "uq_cfd_scope_code",
        "uq_dismissed_pair",
    ):
        assert ix in src, f"Индекс/constraint {ix} должен быть в миграции 0026"


def test_migration_0026_downgrade_drops_all():
    """Downgrade удаляет все 4 таблицы и колонки extra_fields."""
    path = (
        Path(__file__).resolve().parents[1]
        / "alembic" / "versions" / "0026_card2_extensions.py"
    )
    src = path.read_text(encoding="utf-8")
    assert "def downgrade()" in src
    for tbl in (
        "custom_field_defs", "entity_audit_logs",
        "dismissed_duplicates", "saved_filters",
    ):
        assert f'drop_table("{tbl}")' in src
    # extra_fields columns drop
    assert 'drop_column' in src
    assert 'extra_fields' in src


def test_migration_0026_uses_jsonb():
    """extra_fields и diff_json — JSONB (postgres-native)."""
    path = (
        Path(__file__).resolve().parents[1]
        / "alembic" / "versions" / "0026_card2_extensions.py"
    )
    src = path.read_text(encoding="utf-8")
    assert "JSONB()" in src
