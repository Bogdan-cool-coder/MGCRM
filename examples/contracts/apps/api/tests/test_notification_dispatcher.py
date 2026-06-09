"""Эпик 21.2 — pure-function тесты dispatcher'а notification channels.

Без сети, без БД. Тестируем:
- is_in_quiet_hours: разные time ranges + edge cases (полуночь, без quiet hours)
- render_notification_template: разные templates + missing vars + Jinja errors
- channel fan-out logic dispatch() с mock preferences

15+ tests.
"""
from __future__ import annotations

from datetime import datetime, time
from types import SimpleNamespace
from unittest.mock import AsyncMock, MagicMock

import pytest

from app.services.notification_dispatcher import (
    NOTIFICATION_CHANNELS,
    ChannelResult,
    DispatchResult,
    is_in_quiet_hours,
    render_notification_template,
)


# ============ is_in_quiet_hours ============


def test_is_in_quiet_hours_disabled_when_null():
    """NULL start или end → quiet hours отключены → False."""
    user = SimpleNamespace(
        tg_quiet_hours_start=None,
        tg_quiet_hours_end=None,
    )
    assert is_in_quiet_hours(user) is False

    user2 = SimpleNamespace(
        tg_quiet_hours_start=time(21, 0),
        tg_quiet_hours_end=None,
    )
    assert is_in_quiet_hours(user2) is False

    user3 = SimpleNamespace(
        tg_quiet_hours_start=None,
        tg_quiet_hours_end=time(9, 0),
    )
    assert is_in_quiet_hours(user3) is False


def test_is_in_quiet_hours_disabled_when_equal():
    """start == end → окно нулевой длины → False (пользователь сбросил)."""
    user = SimpleNamespace(
        tg_quiet_hours_start=time(12, 0),
        tg_quiet_hours_end=time(12, 0),
    )
    now = datetime(2026, 6, 2, 12, 0, 0)
    assert is_in_quiet_hours(user, now) is False


def test_is_in_quiet_hours_normal_interval_inside():
    """start < end, now внутри окна → True."""
    user = SimpleNamespace(
        tg_quiet_hours_start=time(13, 0),
        tg_quiet_hours_end=time(15, 0),
    )
    now = datetime(2026, 6, 2, 14, 30, 0)
    assert is_in_quiet_hours(user, now) is True


def test_is_in_quiet_hours_normal_interval_outside_before():
    """start < end, now до окна → False."""
    user = SimpleNamespace(
        tg_quiet_hours_start=time(13, 0),
        tg_quiet_hours_end=time(15, 0),
    )
    now = datetime(2026, 6, 2, 12, 0, 0)
    assert is_in_quiet_hours(user, now) is False


def test_is_in_quiet_hours_normal_interval_outside_after():
    """start < end, now после окна → False."""
    user = SimpleNamespace(
        tg_quiet_hours_start=time(13, 0),
        tg_quiet_hours_end=time(15, 0),
    )
    now = datetime(2026, 6, 2, 16, 0, 0)
    assert is_in_quiet_hours(user, now) is False


def test_is_in_quiet_hours_normal_interval_exact_start_is_inside():
    """start ≤ cur — на границе включён (start является минимально-included)."""
    user = SimpleNamespace(
        tg_quiet_hours_start=time(13, 0),
        tg_quiet_hours_end=time(15, 0),
    )
    now = datetime(2026, 6, 2, 13, 0, 0)
    assert is_in_quiet_hours(user, now) is True


def test_is_in_quiet_hours_normal_interval_exact_end_is_outside():
    """cur < end — на границе exclusive (end НЕ included)."""
    user = SimpleNamespace(
        tg_quiet_hours_start=time(13, 0),
        tg_quiet_hours_end=time(15, 0),
    )
    now = datetime(2026, 6, 2, 15, 0, 0)
    assert is_in_quiet_hours(user, now) is False


def test_is_in_quiet_hours_overnight_interval_late_evening():
    """start > end (21:00..09:00) → late evening → True."""
    user = SimpleNamespace(
        tg_quiet_hours_start=time(21, 0),
        tg_quiet_hours_end=time(9, 0),
    )
    now = datetime(2026, 6, 2, 22, 30, 0)
    assert is_in_quiet_hours(user, now) is True


def test_is_in_quiet_hours_overnight_interval_early_morning():
    """start > end (21:00..09:00) → early morning → True."""
    user = SimpleNamespace(
        tg_quiet_hours_start=time(21, 0),
        tg_quiet_hours_end=time(9, 0),
    )
    now = datetime(2026, 6, 2, 7, 0, 0)
    assert is_in_quiet_hours(user, now) is True


def test_is_in_quiet_hours_overnight_interval_middle_of_day():
    """start > end (21:00..09:00) → день → False."""
    user = SimpleNamespace(
        tg_quiet_hours_start=time(21, 0),
        tg_quiet_hours_end=time(9, 0),
    )
    now = datetime(2026, 6, 2, 12, 0, 0)
    assert is_in_quiet_hours(user, now) is False


def test_is_in_quiet_hours_overnight_exact_midnight():
    """start > end, ровно полночь — внутри окна (cur=00:00 < 09:00)."""
    user = SimpleNamespace(
        tg_quiet_hours_start=time(21, 0),
        tg_quiet_hours_end=time(9, 0),
    )
    now = datetime(2026, 6, 2, 0, 0, 0)
    assert is_in_quiet_hours(user, now) is True


# ============ render_notification_template ============


def test_render_template_basic_substitution():
    """{{ var }} подставляется из payload."""
    tpl = SimpleNamespace(
        subject="Hello, {{ name }}!",
        body_template="Task: {{ task.title }}",
    )
    rendered = render_notification_template(
        tpl, {"name": "Ada", "task": {"title": "Walk dog"}},
    )
    assert rendered["subject"] == "Hello, Ada!"
    assert rendered["body"] == "Task: Walk dog"


def test_render_template_with_jinja_if():
    """{% if %} — условный блок."""
    tpl = SimpleNamespace(
        subject=None,
        body_template="A{% if show %}+B{% endif %}",
    )
    rendered_with = render_notification_template(tpl, {"show": True})
    rendered_without = render_notification_template(tpl, {"show": False})
    assert rendered_with["body"] == "A+B"
    assert rendered_without["body"] == "A"


def test_render_template_missing_variable_returns_raw():
    """Если переменной нет — StrictUndefined → ошибка → возвращает raw."""
    tpl = SimpleNamespace(
        subject=None,
        body_template="Hello, {{ missing_var }}!",
    )
    rendered = render_notification_template(tpl, {})
    # Возвращаем raw template вместо краша pipeline'а
    assert rendered["body"] == "Hello, {{ missing_var }}!"


def test_render_template_none_subject():
    """Если subject в шаблоне null — возвращает None."""
    tpl = SimpleNamespace(
        subject=None,
        body_template="x",
    )
    rendered = render_notification_template(tpl, {})
    assert rendered["subject"] is None
    assert rendered["body"] == "x"


def test_render_template_empty_strings_handled():
    """Пустая строка subject — рендер возвращает None (через if не пустая)."""
    tpl = SimpleNamespace(
        subject="",
        body_template="",
    )
    rendered = render_notification_template(tpl, {})
    # Пустая строка проходит через _safe_render → ""; trim до None в caller
    # (мы оставляем "" — caller сам сделает or kind).
    assert rendered["subject"] is None
    assert rendered["body"] is None


def test_render_template_handles_jinja_syntax_error():
    """Если шаблон сломан (например, незакрытый тег) — возвращает raw, не падает."""
    tpl = SimpleNamespace(
        subject=None,
        body_template="{% if x %}",  # незакрытый
    )
    # Не должно бросить
    rendered = render_notification_template(tpl, {"x": True})
    assert rendered["body"] == "{% if x %}"


# ============ NOTIFICATION_CHANNELS whitelist ============


def test_notification_channels_contains_expected():
    """Все обещанные каналы в whitelist."""
    assert "in_app" in NOTIFICATION_CHANNELS
    assert "tg" in NOTIFICATION_CHANNELS
    assert "email" in NOTIFICATION_CHANNELS


# ============ DispatchResult ============


def test_dispatch_result_any_delivered_true_when_one_ok():
    r = DispatchResult(user_id=1, kind="system")
    r.channels.append(ChannelResult(channel="in_app", status="delivered"))
    r.channels.append(ChannelResult(channel="tg", status="failed"))
    assert r.any_delivered is True


def test_dispatch_result_any_delivered_false_when_all_skipped():
    r = DispatchResult(user_id=1, kind="system")
    r.channels.append(ChannelResult(channel="in_app", status="skipped_disabled"))
    r.channels.append(ChannelResult(channel="tg", status="skipped_quiet_hours"))
    assert r.any_delivered is False


def test_dispatch_result_any_delivered_false_when_empty():
    r = DispatchResult(user_id=1, kind="system")
    assert r.any_delivered is False


# ============ dispatch() with mock session ============


@pytest.mark.asyncio
async def test_dispatch_rejects_unknown_kind():
    """Неизвестный kind → ValueError."""
    from app.services.notification_dispatcher import dispatch

    session = MagicMock()
    with pytest.raises(ValueError, match="Unknown notification kind"):
        await dispatch(session, user_id=1, kind="not_a_kind")


@pytest.mark.asyncio
async def test_dispatch_returns_empty_when_user_not_found():
    """User None → возвращает пустой DispatchResult."""
    from app.services.notification_dispatcher import dispatch

    session = MagicMock()
    # Сделаем session.execute → user не найден
    user_result = MagicMock()
    user_result.scalar_one_or_none = MagicMock(return_value=None)
    session.execute = AsyncMock(return_value=user_result)
    res = await dispatch(session, user_id=999, kind="system")
    assert res.user_id == 999
    assert res.channels == []


@pytest.mark.asyncio
async def test_safe_dispatch_skips_invalid_user_id():
    """None / 0 / отрицательный user_id → возвращает None."""
    from app.services.notification_dispatcher import safe_dispatch

    session = MagicMock()
    assert await safe_dispatch(session, None, "system") is None
    assert await safe_dispatch(session, 0, "system") is None
    assert await safe_dispatch(session, -1, "system") is None


@pytest.mark.asyncio
async def test_safe_dispatch_swallows_dispatch_exception():
    """Любая ошибка в dispatch → swallow + None."""
    from app.services.notification_dispatcher import safe_dispatch

    session = MagicMock()
    # session.execute сразу падает → dispatch падает
    session.execute = AsyncMock(side_effect=RuntimeError("DB down"))
    result = await safe_dispatch(session, 5, "system")
    assert result is None


# ============ C7 — render_html_notification (autoescape для HTML email) ============


def test_render_html_escapes_user_input():
    """HTML-env экранирует пользовательский ввод — XSS в письме невозможен."""
    from app.services.notification_dispatcher import render_html_notification

    out = render_html_notification(
        "Привет, {{ name }}!", {"name": "<script>alert(1)</script>"}
    )
    assert "<script>" not in out
    assert "&lt;script&gt;" in out


def test_render_html_renders_plain_when_safe():
    from app.services.notification_dispatcher import render_html_notification

    out = render_html_notification("Сделка {{ id }}", {"id": 42})
    assert out == "Сделка 42"


def test_render_html_empty_returns_empty():
    from app.services.notification_dispatcher import render_html_notification

    assert render_html_notification("", {}) == ""


def test_render_html_broken_template_returns_escaped_raw():
    """Битый шаблон не валит pipeline и не утекает как сырой HTML."""
    from app.services.notification_dispatcher import render_html_notification

    out = render_html_notification("{{ <b>broken", {})
    assert "<b>" not in out
