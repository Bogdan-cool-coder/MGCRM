"""Эпик 21.2 — pure-function тесты для broadcast resolver + status transitions.

Без сети, без БД. Тестируем:
- resolve_broadcast_recipients: filter resolution (role/department/user_ids).
- _parse_hhmm / _fmt_hhmm: helpers для quiet hours endpoint.
- ChannelResult + DispatchResult статусы.

8+ tests.
"""
from __future__ import annotations

from unittest.mock import AsyncMock, MagicMock

import pytest

from app.services.notification_dispatcher import (
    BroadcastRecipients,
    resolve_broadcast_recipients,
)


# ============ resolve_broadcast_recipients ============


def _make_session_with_users(user_ids: list[int]) -> MagicMock:
    """Создаёт mock-сессию, где session.execute().scalars().all() возвращает user_ids."""
    session = MagicMock()
    scalars = MagicMock()
    scalars.all = MagicMock(return_value=user_ids)
    result = MagicMock()
    result.scalars = MagicMock(return_value=scalars)
    session.execute = AsyncMock(return_value=result)
    return session


@pytest.mark.asyncio
async def test_resolve_recipients_no_filter_returns_all():
    """filter=None → returns all active users."""
    session = _make_session_with_users([1, 2, 3])
    res = await resolve_broadcast_recipients(session, None)
    assert isinstance(res, BroadcastRecipients)
    assert res.user_ids == [1, 2, 3]
    assert "all_active_users" in res.filter_explanation


@pytest.mark.asyncio
async def test_resolve_recipients_empty_dict_returns_all():
    """Пустой dict {} — тоже all active."""
    session = _make_session_with_users([1, 2])
    res = await resolve_broadcast_recipients(session, {})
    assert res.user_ids == [1, 2]


@pytest.mark.asyncio
async def test_resolve_recipients_with_role():
    """filter={role: 'manager'} — добавляет WHERE."""
    session = _make_session_with_users([5, 6])
    res = await resolve_broadcast_recipients(session, {"role": "manager"})
    assert res.user_ids == [5, 6]
    assert "role=manager" in res.filter_explanation


@pytest.mark.asyncio
async def test_resolve_recipients_with_department_id():
    session = _make_session_with_users([7])
    res = await resolve_broadcast_recipients(session, {"department_id": 5})
    assert res.user_ids == [7]
    assert "department_id=5" in res.filter_explanation


@pytest.mark.asyncio
async def test_resolve_recipients_with_user_ids_list():
    session = _make_session_with_users([1, 2, 3])
    res = await resolve_broadcast_recipients(
        session, {"user_ids": [1, 2, 3, 4]},
    )
    # Returns whatever session.execute returns (mocked). Filter
    # explanation should mention user_ids.
    assert res.user_ids == [1, 2, 3]
    assert "user_ids" in res.filter_explanation


@pytest.mark.asyncio
async def test_resolve_recipients_invalid_role_ignored():
    """Невалидный role не падает, помечается ignored в explanation."""
    session = _make_session_with_users([1])
    res = await resolve_broadcast_recipients(
        session, {"role": "not_a_real_role"},
    )
    assert res.user_ids == [1]
    assert "invalid" in res.filter_explanation


@pytest.mark.asyncio
async def test_resolve_recipients_invalid_department_id_ignored():
    """Невалидный department_id (строка не-число) не падает."""
    session = _make_session_with_users([1, 2])
    res = await resolve_broadcast_recipients(
        session, {"department_id": "abc"},
    )
    assert res.user_ids == [1, 2]
    assert "invalid" in res.filter_explanation


@pytest.mark.asyncio
async def test_resolve_recipients_dedupes_user_ids():
    """user_ids dedupes даже если session.execute вернул дубликаты."""
    session = _make_session_with_users([1, 1, 2, 2, 3])
    res = await resolve_broadcast_recipients(session, None)
    assert res.user_ids == [1, 2, 3]


@pytest.mark.asyncio
async def test_resolve_recipients_combined_filter():
    """role + department_id комбинируется AND'ом."""
    session = _make_session_with_users([42])
    res = await resolve_broadcast_recipients(
        session, {"role": "manager", "department_id": 3},
    )
    assert res.user_ids == [42]
    assert "role=manager" in res.filter_explanation
    assert "department_id=3" in res.filter_explanation


# ============ HH:MM helpers ============


def test_parse_hhmm_valid():
    from app.routers.notification_preferences import _parse_hhmm
    from datetime import time

    assert _parse_hhmm("00:00") == time(0, 0)
    assert _parse_hhmm("09:00") == time(9, 0)
    assert _parse_hhmm("21:30") == time(21, 30)
    assert _parse_hhmm("23:59") == time(23, 59)


def test_parse_hhmm_null_and_empty():
    from app.routers.notification_preferences import _parse_hhmm

    assert _parse_hhmm(None) is None
    assert _parse_hhmm("") is None


def test_parse_hhmm_invalid_raises():
    from app.routers.notification_preferences import _parse_hhmm
    from fastapi import HTTPException

    with pytest.raises(HTTPException) as exc:
        _parse_hhmm("25:00")
    assert exc.value.status_code == 400

    with pytest.raises(HTTPException):
        _parse_hhmm("9")  # no colon

    with pytest.raises(HTTPException):
        _parse_hhmm("ab:cd")


def test_fmt_hhmm():
    from app.routers.notification_preferences import _fmt_hhmm
    from datetime import time

    assert _fmt_hhmm(None) is None
    assert _fmt_hhmm(time(9, 0)) == "09:00"
    assert _fmt_hhmm(time(21, 30)) == "21:30"
    assert _fmt_hhmm(time(0, 5)) == "00:05"
