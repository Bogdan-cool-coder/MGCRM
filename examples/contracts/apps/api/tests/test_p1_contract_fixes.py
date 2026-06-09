"""P1 — pure-function тесты для фиксов silent frontend↔backend mismatch (audit S4).

Покрывают:
- normalize_recipient_filter — fail-safe «всем только при {type:all}» (C7);
- sanitize_broadcast_link — only internal relative paths (C7 open-redirect);
- BroadcastIn — alias recipients_filter (plural) + channels-object coercion +
  scheduled_at присутствие (C7);
- BulkActionIn — both-shape {ids,payload} ↔ {entity_ids,params} (C1);
- ExtendDeadlineIn — days|new_due_at контракт (C1).

Без сети, без БД (Pydantic-валидация + pure helpers).
"""
from __future__ import annotations

import pytest

from app.services.notification_dispatcher import (
    BroadcastFilterError,
    normalize_recipient_filter,
    sanitize_broadcast_link,
)


# ============ C7: normalize_recipient_filter (fail-safe «всем») ============


def test_normalize_none_raises():
    """None → ошибка. «Не указано» НИКОГДА не значит «всем» (это и был баг)."""
    with pytest.raises(BroadcastFilterError):
        normalize_recipient_filter(None)


def test_normalize_explicit_all_returns_empty_dict():
    """{type:'all'} → {} (resolve трактует пустой filter как все активные)."""
    assert normalize_recipient_filter({"type": "all"}) == {}


def test_normalize_explicit_all_bool_flag():
    """{all: true} — тоже явное намерение разослать всем."""
    assert normalize_recipient_filter({"all": True}) == {}


def test_normalize_empty_dict_raises():
    """Пустой {} БЕЗ {type:all} → ошибка (раньше провалился бы в «всем»)."""
    with pytest.raises(BroadcastFilterError):
        normalize_recipient_filter({})


def test_normalize_empty_values_raise():
    """targeting-intent есть, но значения пусты → ошибка, не «всем»."""
    with pytest.raises(BroadcastFilterError):
        normalize_recipient_filter({"role": None, "department_id": "", "user_ids": []})


def test_normalize_role_passes_through():
    assert normalize_recipient_filter({"role": "manager"}) == {"role": "manager"}


def test_normalize_department_passes_through():
    assert normalize_recipient_filter({"department_id": 5}) == {"department_id": 5}


def test_normalize_user_ids_passes_through():
    out = normalize_recipient_filter({"user_ids": [1, 2, 3]})
    assert out == {"user_ids": [1, 2, 3]}


def test_normalize_user_ids_not_list_raises():
    with pytest.raises(BroadcastFilterError):
        normalize_recipient_filter({"user_ids": 5})


def test_normalize_combined_filter():
    out = normalize_recipient_filter({"role": "manager", "department_id": 3})
    assert out == {"role": "manager", "department_id": 3}


def test_normalize_non_dict_raises():
    with pytest.raises(BroadcastFilterError):
        normalize_recipient_filter("everyone")  # type: ignore[arg-type]


# ============ C7: sanitize_broadcast_link ============


def test_link_none_and_empty():
    assert sanitize_broadcast_link(None) is None
    assert sanitize_broadcast_link("") is None
    assert sanitize_broadcast_link("   ") is None


def test_link_internal_path_ok():
    assert sanitize_broadcast_link("/deals/42") == "/deals/42"
    assert sanitize_broadcast_link("/tasks?status=open&p=2") == "/tasks?status=open&p=2"
    assert sanitize_broadcast_link("/me#section") == "/me#section"


def test_link_javascript_rejected():
    with pytest.raises(BroadcastFilterError):
        sanitize_broadcast_link("javascript:alert(1)")


def test_link_http_rejected():
    with pytest.raises(BroadcastFilterError):
        sanitize_broadcast_link("https://evil.com/phish")
    with pytest.raises(BroadcastFilterError):
        sanitize_broadcast_link("http://evil.com")


def test_link_protocol_relative_rejected():
    """//evil.com — open-redirect через protocol-relative URL."""
    with pytest.raises(BroadcastFilterError):
        sanitize_broadcast_link("//evil.com/x")


def test_link_relative_without_leading_slash_rejected():
    with pytest.raises(BroadcastFilterError):
        sanitize_broadcast_link("deals/42")


# ============ C7: BroadcastIn schema (alias + channels coercion) ============


def test_broadcast_in_plural_alias():
    """recipients_filter (plural, фронтовый) маппится на recipient_filter."""
    from app.routers.notification_broadcasts import BroadcastIn

    b = BroadcastIn.model_validate(
        {"title": "T", "recipients_filter": {"role": "manager"}}
    )
    assert b.recipient_filter == {"role": "manager"}


def test_broadcast_in_canonical_key_still_works():
    from app.routers.notification_broadcasts import BroadcastIn

    b = BroadcastIn.model_validate(
        {"title": "T", "recipient_filter": {"department_id": 5}}
    )
    assert b.recipient_filter == {"department_id": 5}


def test_broadcast_in_channels_object_coerced():
    """Легаси {in_app,tg,email} объект → list только из truthy-каналов."""
    from app.routers.notification_broadcasts import BroadcastIn

    b = BroadcastIn.model_validate(
        {"title": "T", "channels": {"in_app": True, "tg": False, "email": True}}
    )
    assert set(b.channels or []) == {"in_app", "email"}


def test_broadcast_in_channels_list_passthrough():
    from app.routers.notification_broadcasts import BroadcastIn

    b = BroadcastIn.model_validate({"title": "T", "channels": ["in_app", "tg"]})
    assert b.channels == ["in_app", "tg"]


def test_broadcast_in_scheduled_at_captured():
    """scheduled_at попадает в поле — роутер потом отклонит 422."""
    from app.routers.notification_broadcasts import BroadcastIn

    b = BroadcastIn.model_validate({"title": "T", "scheduled_at": "2026-07-01T10:00:00"})
    assert b.scheduled_at is not None


# ============ C1: BulkActionIn both-shape ============


def test_bulk_action_canonical_shape():
    from app.routers.activities import BulkActionIn

    b = BulkActionIn.model_validate(
        {"action": "close", "entity_ids": [1, 2], "params": {"x": 1}}
    )
    assert b.entity_ids == [1, 2]
    assert b.params == {"x": 1}


def test_bulk_action_frontend_shape():
    """Фронтовый BulkActionsBar шлёт {ids, payload} — должен валидироваться."""
    from app.routers.activities import BulkActionIn

    b = BulkActionIn.model_validate(
        {"action": "reassign", "ids": [3, 4], "payload": {"responsible_id": 9}}
    )
    assert b.entity_ids == [3, 4]
    assert b.params == {"responsible_id": 9}


def test_bulk_action_ids_without_payload():
    from app.routers.activities import BulkActionIn

    b = BulkActionIn.model_validate({"action": "delete", "ids": [7]})
    assert b.entity_ids == [7]
    assert b.params == {}


def test_bulk_action_empty_ids_invalid():
    from app.routers.activities import BulkActionIn
    from pydantic import ValidationError

    with pytest.raises(ValidationError):
        BulkActionIn.model_validate({"action": "close", "ids": []})


# ============ C1: ExtendDeadlineIn (days | new_due_at) ============


def test_extend_deadline_new_due_at():
    from app.routers.activities import ExtendDeadlineIn

    e = ExtendDeadlineIn.model_validate(
        {"new_due_at": "2026-07-01T12:00:00Z", "reason": "нужно ещё время"}
    )
    assert e.new_due_at is not None
    assert e.days is None


def test_extend_deadline_days_only():
    """Фронтовый TaskListItem шлёт {days} — без new_due_at/reason."""
    from app.routers.activities import ExtendDeadlineIn

    e = ExtendDeadlineIn.model_validate({"days": 3})
    assert e.days == 3
    assert e.new_due_at is None
    assert e.reason  # дефолт подставился


def test_extend_deadline_requires_one_target():
    from app.routers.activities import ExtendDeadlineIn
    from pydantic import ValidationError

    with pytest.raises(ValidationError):
        ExtendDeadlineIn.model_validate({"reason": "пусто"})


def test_extend_deadline_days_bounds():
    from app.routers.activities import ExtendDeadlineIn
    from pydantic import ValidationError

    with pytest.raises(ValidationError):
        ExtendDeadlineIn.model_validate({"days": 0})
    with pytest.raises(ValidationError):
        ExtendDeadlineIn.model_validate({"days": 99999})
