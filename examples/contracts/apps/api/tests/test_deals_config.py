"""DEALS 2.0 (Ф1b) — pure-function тесты валидаторов настроек воронки и
межворонночных переходов + проверка миграции 0078.

Без БД-фикстуры. Покрывает чистую логику services/deals_v2:
- validate_transition_conditions (whitelist предикатов перехода);
- normalize_pipeline_settings / validate_pipeline_settings (настройки воронки).
"""
from __future__ import annotations

from pathlib import Path

from app.services.deals_v2 import (
    DEFAULT_PIPELINE_SETTINGS,
    DUPLICATE_CHECK_FIELD_WHITELIST,
    TRANSITION_CONDITION_KEYS,
    normalize_pipeline_settings,
    validate_pipeline_settings,
    validate_transition_conditions,
)


# ============ validate_transition_conditions ============


def test_empty_conditions_valid():
    assert validate_transition_conditions({}) == []


def test_require_signed_scan_bool_ok():
    assert validate_transition_conditions({"require_signed_scan": True}) == []
    assert validate_transition_conditions({"require_paid": False}) == []


def test_require_signed_scan_non_bool_rejected():
    errs = validate_transition_conditions({"require_signed_scan": "yes"})
    assert errs and "require_signed_scan" in errs[0]


def test_require_field_string_ok():
    assert validate_transition_conditions({"require_field": "implementation_date"}) == []


def test_require_field_list_ok():
    assert validate_transition_conditions({"require_field": ["a", "b"]}) == []


def test_require_field_empty_rejected():
    assert validate_transition_conditions({"require_field": ""}) != []
    assert validate_transition_conditions({"require_field": "  "}) != []
    assert validate_transition_conditions({"require_field": []}) != []
    assert validate_transition_conditions({"require_field": ["a", ""]}) != []


def test_unknown_condition_key_rejected():
    errs = validate_transition_conditions({"bogus_key": True})
    assert errs and "bogus_key" in errs[0]


def test_non_dict_conditions_rejected():
    assert validate_transition_conditions(["x"]) != []  # type: ignore[arg-type]


def test_all_whitelist_keys_individually_valid():
    """Каждый разрешённый ключ с корректным значением проходит."""
    samples = {
        "require_signed_scan": True,
        "require_paid": True,
        "require_field": "x",
    }
    for key in TRANSITION_CONDITION_KEYS:
        assert validate_transition_conditions({key: samples[key]}) == []


def test_combined_conditions_valid():
    cond = {
        "require_signed_scan": True,
        "require_paid": False,
        "require_field": ["implementation_date", "amount"],
    }
    assert validate_transition_conditions(cond) == []


# ============ normalize_pipeline_settings ============


def test_normalize_none_returns_defaults():
    out = normalize_pipeline_settings(None)
    assert out["auto_assign"] is False
    assert out["duplicate_check_enabled"] is False
    assert out["duplicate_check_fields"] == DEFAULT_PIPELINE_SETTINGS["duplicate_check_fields"]


def test_normalize_empty_returns_defaults():
    assert normalize_pipeline_settings({}) == {
        "auto_assign": False,
        "duplicate_check_enabled": False,
        "duplicate_check_fields": ["email", "phone"],
    }


def test_normalize_coerces_booleans():
    out = normalize_pipeline_settings({"auto_assign": 1, "duplicate_check_enabled": "x"})
    assert out["auto_assign"] is True
    assert out["duplicate_check_enabled"] is True


def test_normalize_filters_unknown_dup_fields():
    out = normalize_pipeline_settings(
        {"duplicate_check_fields": ["email", "bogus", "phone", "password_hash"]}
    )
    assert out["duplicate_check_fields"] == ["email", "phone"]


def test_normalize_dedups_dup_fields_preserving_order():
    out = normalize_pipeline_settings(
        {"duplicate_check_fields": ["phone", "email", "phone", "email"]}
    )
    assert out["duplicate_check_fields"] == ["phone", "email"]


def test_normalize_non_list_dup_fields_becomes_empty():
    out = normalize_pipeline_settings({"duplicate_check_fields": "email"})
    assert out["duplicate_check_fields"] == []


def test_normalize_drops_extra_keys():
    out = normalize_pipeline_settings({"auto_assign": True, "secret": "x"})
    assert set(out.keys()) == {
        "auto_assign", "duplicate_check_enabled", "duplicate_check_fields"
    }


def test_all_whitelist_fields_accepted():
    fields = list(DUPLICATE_CHECK_FIELD_WHITELIST)
    out = normalize_pipeline_settings({"duplicate_check_fields": fields})
    assert set(out["duplicate_check_fields"]) == DUPLICATE_CHECK_FIELD_WHITELIST


# ============ validate_pipeline_settings ============


def test_validate_settings_ok():
    assert validate_pipeline_settings({"auto_assign": True}) == []
    assert validate_pipeline_settings({"duplicate_check_fields": ["email"]}) == []


def test_validate_settings_bad_dup_field():
    errs = validate_pipeline_settings({"duplicate_check_fields": ["email", "ssn"]})
    assert errs and "ssn" in errs[0]


def test_validate_settings_dup_fields_not_list():
    assert validate_pipeline_settings({"duplicate_check_fields": "email"}) != []


def test_validate_settings_non_dict():
    assert validate_pipeline_settings(["x"]) != []  # type: ignore[arg-type]


# ============ Миграция 0078 ============

_VERSIONS = Path(__file__).parent.parent / "alembic" / "versions"


def test_migration_0078_revision_fits_varchar_limit():
    rev = "0078_deals2_pipeline_settings"
    assert len(rev) <= 32, f"{rev} = {len(rev)} chars > 32"


def test_migration_0078_uses_advisory_lock_and_columns():
    src = (_VERSIONS / "0078_deals2_pipeline_settings.py").read_text(encoding="utf-8")
    assert "pg_advisory_xact_lock" in src
    assert "settings" in src
    assert "visible_role" in src
    assert "visible_user_ids" in src
    # Down-revision цепляется к 0077 (sales-агент, Ф1a), чтобы цепочка миграций
    # оставалась линейной: 0076 → 0077 → 0078 → 0079.
    assert 'down_revision: Union[str, None] = "0077_deals2_f1a"' in src


def test_migration_0078_has_downgrade():
    src = (_VERSIONS / "0078_deals2_pipeline_settings.py").read_text(encoding="utf-8")
    assert 'op.drop_column("pipelines", "settings")' in src
    assert 'op.drop_column("pipelines", "visible_role")' in src
    assert 'op.drop_column("pipelines", "visible_user_ids")' in src
