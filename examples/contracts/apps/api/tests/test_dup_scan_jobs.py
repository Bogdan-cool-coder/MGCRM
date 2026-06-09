"""Эпик 20 — Performance Scale: pure-function тесты для dup_scan_jobs.

Покрывает:
- build_cache_key (формат ключа, lower/strip);
- validate_entity_type (whitelist);
- validate_status_transition (state machine pending→running→completed/failed);
- serialize_scan_result (DuplicateGroup → JSON dict);
- deserialize_scan_result (JSON → dict, защита от мусора);
- build_realtime_check_cache_key (формат, нормализация).

БД и Redis НЕ требуются — все функции pure.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

import pytest

from app.services.dup_scan_jobs import (
    ALLOWED_STATUSES,
    REDIS_CACHE_TTL_SECONDS,
    REDIS_KEY_PREFIX,
    STATUS_TRANSITIONS,
    build_cache_key,
    deserialize_scan_result,
    serialize_scan_result,
    validate_entity_type,
    validate_status_transition,
)


# ============ build_cache_key ============


def test_build_cache_key_simple():
    """counterparty → 'dup_scan:counterparty'."""
    assert build_cache_key("counterparty") == "dup_scan:counterparty"


def test_build_cache_key_strip_and_lower():
    """Whitespace и uppercase нормализуются."""
    assert build_cache_key("  CONTACT  ") == "dup_scan:contact"
    assert build_cache_key("LEAD") == "dup_scan:lead"


def test_build_cache_key_empty_raises():
    """Пустая entity_type → ValueError (защита от случайного truncate)."""
    with pytest.raises(ValueError, match="non-empty"):
        build_cache_key("")
    with pytest.raises(ValueError, match="non-empty"):
        build_cache_key("   ")


def test_build_cache_key_prefix_constant():
    """Префикс соответствует REDIS_KEY_PREFIX (защита от случайного rename)."""
    key = build_cache_key("lead")
    assert key.startswith(REDIS_KEY_PREFIX)


# ============ validate_entity_type ============


def test_validate_entity_type_allowed():
    """Все 4 поддерживаемых типа проходят."""
    for et in ("counterparty", "contact", "company", "lead"):
        validate_entity_type(et)  # не должно raise


def test_validate_entity_type_rejects_unknown():
    """Неизвестный тип → ValueError."""
    with pytest.raises(ValueError, match="Invalid entity_type"):
        validate_entity_type("foo")
    with pytest.raises(ValueError):
        validate_entity_type("Counterparty")  # case sensitive
    with pytest.raises(ValueError):
        validate_entity_type("")


# ============ validate_status_transition ============


def test_status_transition_pending_to_running():
    """pending → running OK."""
    validate_status_transition("pending", "running")


def test_status_transition_pending_to_failed():
    """pending → failed OK (если crash до running)."""
    validate_status_transition("pending", "failed")


def test_status_transition_running_to_completed():
    """running → completed OK."""
    validate_status_transition("running", "completed")


def test_status_transition_running_to_failed():
    """running → failed OK."""
    validate_status_transition("running", "failed")


def test_status_transition_completed_to_anything_rejected():
    """completed — terminal статус; никакие переходы не разрешены."""
    for target in ("pending", "running", "failed", "completed"):
        with pytest.raises(ValueError, match="Invalid transition"):
            validate_status_transition("completed", target)


def test_status_transition_failed_to_anything_rejected():
    """failed — тоже terminal."""
    for target in ("pending", "running", "completed", "failed"):
        with pytest.raises(ValueError, match="Invalid transition"):
            validate_status_transition("failed", target)


def test_status_transition_backwards_rejected():
    """Нельзя running → pending."""
    with pytest.raises(ValueError, match="Invalid transition"):
        validate_status_transition("running", "pending")


def test_status_transition_unknown_status_raises():
    """Невалидный source/target → ValueError."""
    with pytest.raises(ValueError, match="Unknown source"):
        validate_status_transition("nonsense", "running")
    with pytest.raises(ValueError, match="Unknown target"):
        validate_status_transition("pending", "nonsense")


# ============ STATUS_TRANSITIONS / ALLOWED_STATUSES constants ============


def test_allowed_statuses_match_check_constraint():
    """ALLOWED_STATUSES sync с CHECK constraint в migration 0040."""
    expected = {"pending", "running", "completed", "failed"}
    assert ALLOWED_STATUSES == expected


def test_status_transitions_completeness():
    """Каждый non-terminal статус имеет хотя бы 1 разрешённый переход."""
    assert len(STATUS_TRANSITIONS["pending"]) > 0
    assert len(STATUS_TRANSITIONS["running"]) > 0
    # completed/failed — terminal (frozenset()).
    assert STATUS_TRANSITIONS["completed"] == frozenset()
    assert STATUS_TRANSITIONS["failed"] == frozenset()


# ============ serialize_scan_result ============


@dataclass
class _FakeGroup:
    """Mock DuplicateGroup для тестов — имеет to_dict()."""

    id: str
    entity: str
    records: list[dict[str, Any]] = field(default_factory=list)
    similarity_score: int = 50

    def to_dict(self) -> dict[str, Any]:
        return {
            "id": self.id,
            "entity": self.entity,
            "records": self.records,
            "similarity_score": self.similarity_score,
        }


def test_serialize_empty_groups():
    """Пустой список → result содержит group_count=0."""
    result = serialize_scan_result([])
    assert result["group_count"] == 0
    assert result["groups"] == []
    assert "scanned_at" in result


def test_serialize_with_groups():
    """Список групп сериализуется через to_dict() каждой."""
    groups = [
        _FakeGroup(id="cp:1", entity="counterparty", similarity_score=85),
        _FakeGroup(id="cp:7", entity="counterparty", similarity_score=60),
    ]
    result = serialize_scan_result(groups)
    assert result["group_count"] == 2
    assert len(result["groups"]) == 2
    assert result["groups"][0]["id"] == "cp:1"
    assert result["groups"][0]["similarity_score"] == 85
    assert result["groups"][1]["id"] == "cp:7"


def test_serialize_scanned_at_iso_format():
    """scanned_at — ISO 8601 строка с timezone."""
    result = serialize_scan_result([])
    scanned = result["scanned_at"]
    # Basic ISO: YYYY-MM-DDTHH:MM:SS+00:00 или с микросекундами.
    assert "T" in scanned
    assert ":" in scanned
    # Допускается '+00:00' или 'Z' в конце; в нашей реализации datetime.now
    # возвращает '+00:00'.
    assert "+00:00" in scanned or scanned.endswith("Z")


def test_serialize_dict_groups_passthrough():
    """Если в list прилетел dict (не DuplicateGroup) — оставляем as-is.

    Защищает от случайных моков/тестовых вызовов с уже-dict объектами.
    """
    raw = [{"id": "fake", "entity": "lead", "records": [], "similarity_score": 100}]
    result = serialize_scan_result(raw)  # type: ignore[arg-type]
    assert result["groups"] == raw


# ============ deserialize_scan_result ============


def test_deserialize_valid_json_string():
    """Корректная JSON-строка → dict."""
    raw = '{"groups": [], "group_count": 0, "scanned_at": "2026-06-02T10:00:00Z"}'
    result = deserialize_scan_result(raw)
    assert result == {
        "groups": [], "group_count": 0, "scanned_at": "2026-06-02T10:00:00Z",
    }


def test_deserialize_valid_json_bytes():
    """JSON в bytes (как Redis иногда возвращает) → dict."""
    raw = b'{"groups": [], "group_count": 0}'
    result = deserialize_scan_result(raw)
    assert result == {"groups": [], "group_count": 0}


def test_deserialize_empty_returns_none():
    """Пустой input → None (cache miss-эквивалент)."""
    assert deserialize_scan_result("") is None
    assert deserialize_scan_result(b"") is None


def test_deserialize_invalid_json_returns_none():
    """Битый JSON → None (а не raise)."""
    assert deserialize_scan_result("not json {{{") is None
    assert deserialize_scan_result(b"\xff\xfe\xfd") is None  # bad utf-8


def test_deserialize_unicode():
    """Unicode (кириллица) — proper decode."""
    raw = '{"name": "Ромашка"}'
    result = deserialize_scan_result(raw)
    assert result == {"name": "Ромашка"}


# ============ Round-trip serialize → JSON → deserialize ============


def test_roundtrip_serialize_deserialize():
    """Сериализуем, JSON.dumps, deserialize → должен совпасть."""
    import json
    groups = [_FakeGroup(id="x:1", entity="contact", similarity_score=75)]
    serialized = serialize_scan_result(groups)
    json_str = json.dumps(serialized, ensure_ascii=False, default=str)
    restored = deserialize_scan_result(json_str)
    assert restored == serialized


# ============ Constants sanity ============


def test_cache_ttl_reasonable():
    """TTL не нулевой и не безумно большой (sanity)."""
    assert 60 <= REDIS_CACHE_TTL_SECONDS <= 86_400  # 1мин..24ч


# ============ build_realtime_check_cache_key ============


def test_realtime_cache_key_basic():
    """Простой случай: 'dup_check:counterparty:email:foo@bar.com'."""
    from app.routers.duplicates import build_realtime_check_cache_key
    key = build_realtime_check_cache_key("counterparty", "email", "foo@bar.com")
    assert key == "dup_check:counterparty:email:foo@bar.com"


def test_realtime_cache_key_normalizes_value():
    """value → lower + strip."""
    from app.routers.duplicates import build_realtime_check_cache_key
    key = build_realtime_check_cache_key("contact", "email", "  ABC@TEST.COM  ")
    assert key == "dup_check:contact:email:abc@test.com"


def test_realtime_cache_key_empty_entity_raises():
    """Пустой entity_type / field → ValueError."""
    from app.routers.duplicates import build_realtime_check_cache_key
    with pytest.raises(ValueError):
        build_realtime_check_cache_key("", "email", "x")
    with pytest.raises(ValueError):
        build_realtime_check_cache_key("counterparty", "", "x")


def test_realtime_cache_key_truncates_long_value():
    """value > 256 chars обрезается."""
    from app.routers.duplicates import build_realtime_check_cache_key
    long = "x" * 1000
    key = build_realtime_check_cache_key("lead", "name", long)
    # ключ = 'dup_check:lead:name:' + max 256 chars
    prefix = "dup_check:lead:name:"
    assert key.startswith(prefix)
    suffix = key[len(prefix):]
    assert len(suffix) == 256
