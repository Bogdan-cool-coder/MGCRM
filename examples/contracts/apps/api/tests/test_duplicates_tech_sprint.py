"""Tech Sprint Фаза 0 (задачи 7+8): pure-function тесты для:
- MergeIn back-compat (single secondary_id vs new duplicate_ids list);
- build_realtime_check_query_spec — построение query без БД;
- RealtimeCheckOut / DuplicateMatchOut Pydantic схемы.

Без БД — мокаем session через AsyncMock когда нужно, проверяем основную
логику pure-функций.
"""
from __future__ import annotations

from types import SimpleNamespace

import pytest

from app.models import Company, Contact, Counterparty, Lead, UserRole
from app.routers.duplicates import (
    ChainMergeStepOut,
    DuplicateMatchOut,
    MergeIn,
    MergeOut,
    RealtimeCheckOut,
    _is_dedup_elevated,
    _redact_realtime_check,
)
from app.services.duplicates import (
    build_realtime_check_query_spec,
)


# ============ MergeIn back-compat ============


def test_merge_in_legacy_format():
    """Старый формат: primary_id + secondary_id (back-compat)."""
    m = MergeIn(entity_type="counterparty", primary_id=1, secondary_id=2)
    assert m.primary_id == 1
    assert m.secondary_id == 2
    assert m.master_id is None
    assert m.duplicate_ids is None


def test_merge_in_new_format():
    """Новый формат: master_id + duplicate_ids."""
    m = MergeIn(entity_type="lead", master_id=10, duplicate_ids=[11, 12, 13])
    assert m.master_id == 10
    assert m.duplicate_ids == [11, 12, 13]
    assert m.primary_id is None
    assert m.secondary_id is None


def test_merge_in_field_choices_default_empty():
    """field_choices по умолчанию — пустой dict."""
    m = MergeIn(entity_type="contact", master_id=1, duplicate_ids=[2])
    assert m.field_choices == {}


def test_merge_in_field_choices_passed():
    """field_choices можно явно передать."""
    m = MergeIn(
        entity_type="counterparty",
        master_id=1, duplicate_ids=[2],
        field_choices={"name": "primary", "phone": "secondary"},
    )
    assert m.field_choices == {"name": "primary", "phone": "secondary"}


def test_merge_in_validation_entity_type_required():
    """entity_type обязателен."""
    with pytest.raises(ValueError):
        MergeIn(master_id=1, duplicate_ids=[2])  # type: ignore[call-arg]


# ============ MergeOut schema ============


def test_merge_out_chain_steps_optional():
    """chain_steps опциональны — для одиночного merge не передаются."""
    m = MergeOut(merged_id=1, entity_type="lead")
    assert m.chain_steps is None
    assert m.field_changes == {}
    assert m.fk_relinks == {}


def test_merge_out_chain_steps_filled():
    """chain_steps содержат все merge'и в цепочке."""
    m = MergeOut(
        merged_id=1, entity_type="lead",
        chain_steps=[
            ChainMergeStepOut(secondary_id=2, field_changes={}, fk_relinks={"deals": 1}),
            ChainMergeStepOut(secondary_id=3, field_changes={"name": {"from": "a", "to": "b"}}, fk_relinks={}),
        ],
    )
    assert m.chain_steps is not None
    assert len(m.chain_steps) == 2
    assert m.chain_steps[0].secondary_id == 2
    assert m.chain_steps[1].field_changes == {"name": {"from": "a", "to": "b"}}


# ============ build_realtime_check_query_spec ============


def test_realtime_spec_counterparty_email():
    """counterparty + email → (Counterparty, 'email', 'name', normalized, 'exact')."""
    model, field, display, norm, kind = build_realtime_check_query_spec(
        "counterparty", "email", "  TEST@example.COM  ",
    )
    assert model is Counterparty
    assert field == "email"
    assert display == "name"
    assert norm == "test@example.com"
    assert kind == "exact"


def test_realtime_spec_counterparty_phone():
    """counterparty + phone → последние 10 цифр."""
    model, field, display, norm, kind = build_realtime_check_query_spec(
        "counterparty", "phone", "+7 (701) 234-56-78",
    )
    assert model is Counterparty
    assert field == "phone"
    assert norm == "7012345678"
    assert kind == "exact"


def test_realtime_spec_counterparty_tax_id():
    """counterparty + tax_id → strip и точное совпадение."""
    model, field, display, norm, kind = build_realtime_check_query_spec(
        "counterparty", "tax_id", "  123456789012  ",
    )
    assert norm == "123456789012"
    assert kind == "exact"


def test_realtime_spec_counterparty_name():
    """counterparty + name → ilike с normalize_name."""
    model, field, display, norm, kind = build_realtime_check_query_spec(
        "counterparty", "name", 'ООО "Ромашка"',
    )
    assert model is Counterparty
    assert field == "name"
    assert norm == "ромашка"
    assert kind == "ilike"


def test_realtime_spec_contact_uses_full_name():
    """contact.display = full_name (не name)."""
    model, field, display, norm, kind = build_realtime_check_query_spec(
        "contact", "name", "Иван Иванов",
    )
    assert model is Contact
    assert field == "full_name"
    assert display == "full_name"


def test_realtime_spec_company_uses_legal_name():
    """company.display = legal_name."""
    model, field, display, norm, kind = build_realtime_check_query_spec(
        "company", "name", "ABC Inc",
    )
    assert model is Company
    assert field == "legal_name"
    assert display == "legal_name"


def test_realtime_spec_lead_email_uses_contact_email():
    """lead.email → contact_email field (не email — у Lead нет email)."""
    model, field, display, norm, kind = build_realtime_check_query_spec(
        "lead", "email", "test@example.com",
    )
    assert model is Lead
    assert field == "contact_email"
    assert display == "name"


def test_realtime_spec_unsupported_pair_raises():
    """Неподдерживаемая пара (entity, field) → ValueError."""
    with pytest.raises(ValueError) as exc_info:
        build_realtime_check_query_spec("counterparty", "nonsense_field", "x")
    assert "не поддержано" in str(exc_info.value)


def test_realtime_spec_unsupported_entity_raises():
    """Неподдерживаемый entity_type → ValueError."""
    with pytest.raises(ValueError):
        build_realtime_check_query_spec("foo", "email", "x")


# ============ RealtimeCheckOut + DuplicateMatchOut ============


def test_realtime_check_out_empty():
    """Пустой результат — match_count=0."""
    out = RealtimeCheckOut(
        matches=[], match_count=0, field="email", normalized_value="x",
    )
    assert out.matches == []
    assert out.match_count == 0


def test_realtime_check_out_with_matches():
    """Result с матчами."""
    out = RealtimeCheckOut(
        matches=[
            DuplicateMatchOut(
                id=1, display_name="ABC Corp", similarity=1.0, matched_field="email",
            ),
            DuplicateMatchOut(
                id=2, display_name="ABCD Co", similarity=0.7, matched_field="email",
            ),
        ],
        match_count=2,
        field="email",
        normalized_value="abc@x.com",
    )
    assert out.match_count == 2
    assert out.matches[0].similarity == 1.0
    assert out.matches[1].similarity == 0.7


def test_duplicate_match_similarity_bounds():
    """similarity ∈ [0,1] — Pydantic enforced."""
    with pytest.raises(ValueError):
        DuplicateMatchOut(id=1, display_name="x", similarity=1.5, matched_field="email")
    with pytest.raises(ValueError):
        DuplicateMatchOut(id=1, display_name="x", similarity=-0.1, matched_field="email")
    # Boundary OK
    DuplicateMatchOut(id=1, display_name="x", similarity=0.0, matched_field="email")
    DuplicateMatchOut(id=1, display_name="x", similarity=1.0, matched_field="email")


# ============ Realtime-check PII redaction (scope guard) ============


@pytest.mark.parametrize(
    "role,expected",
    [
        (UserRole.admin, True),
        (UserRole.director, True),
        (UserRole.manager, False),
        (UserRole.lawyer, False),
        (UserRole.accountant, False),
        (UserRole.cfo, False),
    ],
)
def test_is_dedup_elevated(role, expected):
    """Полные данные кандидата-дубля видят только admin/director."""
    user = SimpleNamespace(role=role)
    assert _is_dedup_elevated(user) is expected


def _sample_check() -> RealtimeCheckOut:
    return RealtimeCheckOut(
        matches=[
            DuplicateMatchOut(
                id=1, display_name="ABC Corp", similarity=1.0, matched_field="email",
            ),
            DuplicateMatchOut(
                id=2, display_name="ABCD Co", similarity=0.7, matched_field="email",
            ),
        ],
        match_count=2,
        field="email",
        normalized_value="abc@x.com",
    )


def test_redact_realtime_check_elevated_passthrough():
    """Elevated — отдаём как есть, id+имя сохранены."""
    src = _sample_check()
    out = _redact_realtime_check(src, elevated=True)
    assert out is src
    assert out.matches[0].id == 1
    assert out.matches[0].display_name == "ABC Corp"


def test_redact_realtime_check_non_elevated_strips_pii():
    """Non-elevated — id и display_name = None, но факт совпадения сохранён."""
    out = _redact_realtime_check(_sample_check(), elevated=False)
    # Сигнал «дубль есть» остаётся: счётчик, similarity, matched_field, поле.
    assert out.match_count == 2
    assert out.field == "email"
    assert out.normalized_value == "abc@x.com"
    assert [m.similarity for m in out.matches] == [1.0, 0.7]
    assert [m.matched_field for m in out.matches] == ["email", "email"]
    # PII вырезана: ни id, ни имени чужой записи.
    assert all(m.id is None for m in out.matches)
    assert all(m.display_name is None for m in out.matches)


def test_redact_realtime_check_non_elevated_empty():
    """Пустой результат после редакции остаётся пустым."""
    src = RealtimeCheckOut(
        matches=[], match_count=0, field="phone", normalized_value="",
    )
    out = _redact_realtime_check(src, elevated=False)
    assert out.matches == []
    assert out.match_count == 0
