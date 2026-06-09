"""Epic 14.2 — Rights transfer pure-function tests.

Тестируем pure-функции:
- compute_reversible_until
- is_reversible
- validate_categories

DB-функции transfer_rights / revert_transfer тестируются end-to-end в
интеграционных тестах (тут не имитируем БД).
"""
from __future__ import annotations

from datetime import UTC, datetime, timedelta

import pytest

from app.models import RightsTransferLog
from app.services.rights_transfer import (
    ALLOWED_CATEGORIES,
    REVERSIBLE_WINDOW_DAYS,
    compute_reversible_until,
    is_reversible,
    validate_categories,
)


# ============ compute_reversible_until ============


def test_compute_reversible_until_adds_seven_days():
    now = datetime(2026, 6, 1, 12, 0, tzinfo=UTC)
    out = compute_reversible_until(now)
    assert out == now + timedelta(days=REVERSIBLE_WINDOW_DAYS)
    # должно быть 2026-06-08
    assert out == datetime(2026, 6, 8, 12, 0, tzinfo=UTC)


def test_compute_reversible_until_naive():
    now = datetime(2026, 6, 1, 12, 0)
    out = compute_reversible_until(now)
    assert out == now + timedelta(days=REVERSIBLE_WINDOW_DAYS)


# ============ is_reversible ============


def _log(
    *,
    is_reverted: bool = False,
    reversible_until: datetime | None = None,
    executed_at: datetime | None = None,
) -> RightsTransferLog:
    """Минимальный фейк ORM-объекта для теста чистой функции."""
    log = RightsTransferLog()
    log.is_reverted = is_reverted
    log.reversible_until = reversible_until
    log.executed_at = executed_at or datetime.now(UTC)
    return log


def test_is_reversible_true_within_window():
    now = datetime(2026, 6, 1, 12, 0, tzinfo=UTC)
    log = _log(reversible_until=now + timedelta(days=1))
    assert is_reversible(log, now=now) is True


def test_is_reversible_false_when_already_reverted():
    now = datetime(2026, 6, 1, 12, 0, tzinfo=UTC)
    log = _log(
        is_reverted=True,
        reversible_until=now + timedelta(days=1),
    )
    assert is_reversible(log, now=now) is False


def test_is_reversible_false_when_expired():
    now = datetime(2026, 6, 10, 12, 0, tzinfo=UTC)
    log = _log(reversible_until=now - timedelta(days=1))
    assert is_reversible(log, now=now) is False


def test_is_reversible_false_when_no_until():
    now = datetime(2026, 6, 1, 12, 0, tzinfo=UTC)
    log = _log(reversible_until=None)
    assert is_reversible(log, now=now) is False


def test_is_reversible_handles_naive_until():
    # БД может вернуть naive datetime (TIMESTAMPTZ конвертируется → naive в
    # asyncpg иногда). Проверяем что не падаем по сравнению tz vs no-tz.
    now = datetime(2026, 6, 1, 12, 0, tzinfo=UTC)
    log = _log(reversible_until=datetime(2026, 6, 2, 12, 0))  # naive
    assert is_reversible(log, now=now) is True


def test_is_reversible_uses_default_now():
    # Без передачи now должно работать (default = now UTC).
    log = _log(
        reversible_until=datetime.now(UTC) + timedelta(days=1),
    )
    assert is_reversible(log) is True


# ============ validate_categories ============


def test_validate_categories_all_valid():
    out = validate_categories(["contacts", "deals"])
    assert out == ["contacts", "deals"]


def test_validate_categories_dedupes():
    out = validate_categories(["contacts", "deals", "contacts"])
    assert out == ["contacts", "deals"]


def test_validate_categories_preserves_order():
    out = validate_categories(["deals", "contacts", "leads"])
    assert out == ["deals", "contacts", "leads"]


def test_validate_categories_unknown_raises():
    with pytest.raises(ValueError, match="Unknown rights_transfer category"):
        validate_categories(["bogus"])


def test_validate_categories_partial_known_raises():
    with pytest.raises(ValueError):
        validate_categories(["contacts", "bogus"])


def test_validate_categories_empty_returns_empty():
    assert validate_categories([]) == []


def test_validate_categories_all_allowed_pass():
    # Все из whitelist должны проходить.
    out = validate_categories(sorted(ALLOWED_CATEGORIES))
    assert set(out) == ALLOWED_CATEGORIES


def test_allowed_categories_contains_expected():
    # Ловим если кто-то случайно сломает константу.
    expected = {
        "contacts", "deals", "leads",
        "tasks_assigner", "tasks_executor",
        "approvals", "settings",
    }
    assert ALLOWED_CATEGORIES == frozenset(expected)


def test_reversible_window_is_seven_days():
    # Зафиксированный inline-договор: 7 дней. Если меняем — должны
    # переделать тесты + UI + alembic комментарии.
    assert REVERSIBLE_WINDOW_DAYS == 7
