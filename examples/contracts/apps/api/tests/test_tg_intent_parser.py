"""Эпик 24.3 — TG Intent Parser: pure-function тесты.

Тесты для:
- parse_relative_date(): 15+ кейсов с различными форматами дат
- resolve_user_by_name(): поиск по имени / 'me' / подстроке
- _build_user_prompt(): состав промпта
- IntentResult dataclass
"""
from __future__ import annotations

from datetime import UTC, datetime, timedelta
from types import SimpleNamespace
from unittest.mock import AsyncMock, MagicMock, patch

import pytest

from app.services.tg_intent import (
    IntentResult,
    _build_user_prompt,
    _next_weekday,
    _with_default_time,
    parse_relative_date,
    resolve_user_by_name,
)


# ============ parse_relative_date ============

def _now_monday() -> datetime:
    """Возвращает понедельник на этой неделе (UTC 10:00)."""
    now = datetime(2026, 6, 1, 10, 0, 0, tzinfo=UTC)  # 1 июня 2026 — понедельник
    return now


def test_parse_today():
    now = _now_monday()
    result = parse_relative_date("сегодня", now=now)
    assert result is not None
    assert result.date() == now.date()
    assert result.hour == 18


def test_parse_today_en():
    now = _now_monday()
    result = parse_relative_date("today", now=now)
    assert result is not None
    assert result.date() == now.date()


def test_parse_tomorrow():
    now = _now_monday()
    result = parse_relative_date("завтра", now=now)
    assert result is not None
    assert result.date() == (now + timedelta(days=1)).date()
    assert result.hour == 18


def test_parse_tomorrow_en():
    now = _now_monday()
    result = parse_relative_date("tomorrow", now=now)
    assert result is not None
    assert result.date() == (now + timedelta(days=1)).date()


def test_parse_day_after_tomorrow():
    now = _now_monday()
    result = parse_relative_date("послезавтра", now=now)
    assert result is not None
    assert result.date() == (now + timedelta(days=2)).date()


def test_parse_next_week():
    now = _now_monday()
    result = parse_relative_date("через неделю", now=now)
    assert result is not None
    assert result.date() == (now + timedelta(weeks=1)).date()


def test_parse_through_n_days():
    now = _now_monday()
    result = parse_relative_date("через 3 дня", now=now)
    assert result is not None
    assert result.date() == (now + timedelta(days=3)).date()
    assert result.hour == 18


def test_parse_through_1_den():
    now = _now_monday()
    result = parse_relative_date("через 1 день", now=now)
    assert result is not None
    assert result.date() == (now + timedelta(days=1)).date()


def test_parse_through_5_dney():
    now = _now_monday()
    result = parse_relative_date("через 5 дней", now=now)
    assert result is not None
    assert result.date() == (now + timedelta(days=5)).date()


def test_parse_through_hours():
    now = _now_monday()
    result = parse_relative_date("через 2 часа", now=now)
    assert result is not None
    assert result == now + timedelta(hours=2)


def test_parse_wednesday():
    # Понедельник → следующая среда = +2 дня
    now = _now_monday()
    result = parse_relative_date("в среду", now=now)
    assert result is not None
    assert result.weekday() == 2  # Wednesday
    assert result > now


def test_parse_friday():
    now = _now_monday()
    result = parse_relative_date("в пятницу", now=now)
    assert result is not None
    assert result.weekday() == 4  # Friday


def test_parse_monday_next_week():
    # Сегодня понедельник — «в понедельник» = следующий понедельник
    now = _now_monday()
    result = parse_relative_date("в понедельник", now=now)
    assert result is not None
    assert result.weekday() == 0  # Monday
    assert result.date() > now.date()


def test_parse_iso_date_in_text():
    now = _now_monday()
    result = parse_relative_date("до 2026-06-15", now=now)
    assert result is not None
    assert result.year == 2026
    assert result.month == 6
    assert result.day == 15


def test_parse_dd_mm_yyyy():
    now = _now_monday()
    result = parse_relative_date("до 15.06.2026", now=now)
    assert result is not None
    assert result.year == 2026
    assert result.month == 6
    assert result.day == 15


def test_parse_unknown_returns_none():
    now = _now_monday()
    result = parse_relative_date("абракадабра", now=now)
    assert result is None


def test_parse_empty_returns_none():
    now = _now_monday()
    result = parse_relative_date("", now=now)
    assert result is None


# ============ _next_weekday ============

def test_next_weekday_same_day():
    # Понедельник → следующий понедельник (+7 дней)
    now = _now_monday()
    result = _next_weekday(now, 0)  # 0 = Monday
    assert result.weekday() == 0
    assert result.date() > now.date()


def test_next_weekday_future_day():
    # Понедельник → ближайшая пятница (+4 дня)
    now = _now_monday()
    result = _next_weekday(now, 4)  # 4 = Friday
    assert result.weekday() == 4
    assert result == _with_default_time(now + timedelta(days=4), 18, 0)


# ============ _build_user_prompt ============

def test_build_user_prompt_contains_date():
    prompt = _build_user_prompt("тест", {
        "current_date": "2026-06-01",
        "user_name": "Иван",
        "recent_tasks": [],
    })
    assert "2026-06-01" in prompt
    assert "Иван" in prompt
    assert "тест" in prompt


def test_build_user_prompt_contains_tasks():
    ctx = {
        "current_date": "2026-06-01",
        "user_name": "Мария",
        "recent_tasks": [
            {"id": 42, "title": "Позвонить клиенту X", "due_at": "2026-06-05T18:00:00"},
        ],
    }
    prompt = _build_user_prompt("покажи задачи", ctx)
    assert "42" in prompt
    assert "Позвонить клиенту X" in prompt


def test_build_user_prompt_max_tasks():
    # Максимум 5 задач в контексте
    tasks = [{"id": i, "title": f"Задача {i}", "due_at": None} for i in range(10)]
    ctx = {
        "current_date": "2026-06-01",
        "user_name": "Тест",
        "recent_tasks": tasks,
    }
    prompt = _build_user_prompt("тест", ctx)
    # Должны быть задачи 0-4 (первые 5), но не 5-9
    assert "Задача 4" in prompt
    assert "Задача 9" not in prompt


def test_build_user_prompt_no_tasks():
    prompt = _build_user_prompt("создай задачу", {"recent_tasks": []})
    assert "Активные задачи" not in prompt


# ============ resolve_user_by_name (async) ============

@pytest.mark.asyncio
async def test_resolve_user_me():
    """'me' → текущий пользователь."""
    user = SimpleNamespace(id=1, full_name="Иван Иванов", is_active=True)
    session = AsyncMock()
    # scalars().all() вернёт список, scalar_one_or_none() вернёт user
    mock_result = MagicMock()
    mock_result.scalar_one_or_none.return_value = user
    session.execute = AsyncMock(return_value=mock_result)

    result = await resolve_user_by_name(session, "me", current_user_id=1)
    assert result == user


@pytest.mark.asyncio
async def test_resolve_user_ya():
    """'я' → текущий пользователь."""
    user = SimpleNamespace(id=5, full_name="Мария", is_active=True)
    session = AsyncMock()
    mock_result = MagicMock()
    mock_result.scalar_one_or_none.return_value = user
    session.execute = AsyncMock(return_value=mock_result)

    result = await resolve_user_by_name(session, "я", current_user_id=5)
    assert result == user


@pytest.mark.asyncio
async def test_resolve_user_exact_match():
    """Точное совпадение full_name."""
    users_list = [
        SimpleNamespace(id=1, full_name="Иван Петров", is_active=True),
        SimpleNamespace(id=2, full_name="Алексей Смирнов", is_active=True),
    ]
    session = AsyncMock()
    mock_result = MagicMock()
    # Первый вызов — список всех пользователей
    mock_scalars = MagicMock()
    mock_scalars.all.return_value = users_list
    mock_result.scalars.return_value = mock_scalars
    session.execute = AsyncMock(return_value=mock_result)

    result = await resolve_user_by_name(session, "Алексей Смирнов", current_user_id=99)
    assert result is not None
    assert result.id == 2


@pytest.mark.asyncio
async def test_resolve_user_first_name():
    """Поиск только по имени (первое слово)."""
    users_list = [
        SimpleNamespace(id=1, full_name="Илья Волков", is_active=True),
    ]
    session = AsyncMock()
    mock_result = MagicMock()
    mock_scalars = MagicMock()
    mock_scalars.all.return_value = users_list
    mock_result.scalars.return_value = mock_scalars
    session.execute = AsyncMock(return_value=mock_result)

    result = await resolve_user_by_name(session, "Илья", current_user_id=99)
    assert result is not None
    assert result.full_name == "Илья Волков"


@pytest.mark.asyncio
async def test_resolve_user_substring():
    """Поиск по подстроке фамилии."""
    users_list = [
        SimpleNamespace(id=3, full_name="Петр Кузнецов", is_active=True),
    ]
    session = AsyncMock()
    mock_result = MagicMock()
    mock_scalars = MagicMock()
    mock_scalars.all.return_value = users_list
    mock_result.scalars.return_value = mock_scalars
    session.execute = AsyncMock(return_value=mock_result)

    result = await resolve_user_by_name(session, "Кузнецов", current_user_id=99)
    assert result is not None
    assert result.id == 3


@pytest.mark.asyncio
async def test_resolve_user_not_found():
    """Пользователь не найден → None."""
    session = AsyncMock()
    mock_result = MagicMock()
    mock_scalars = MagicMock()
    mock_scalars.all.return_value = []
    mock_result.scalars.return_value = mock_scalars
    session.execute = AsyncMock(return_value=mock_result)

    result = await resolve_user_by_name(session, "Несуществующий", current_user_id=99)
    assert result is None


# ============ IntentResult dataclass ============

def test_intent_result_creation():
    r = IntentResult(
        intent="create_task",
        confidence=0.95,
        params={"title": "Позвонить"},
        raw_response='{"intent": "create_task", "confidence": 0.95, "params": {}}',
        tokens_used=150,
        duration_ms=1200,
    )
    assert r.intent == "create_task"
    assert r.confidence == 0.95
    assert r.tokens_used == 150


def test_intent_result_unknown():
    r = IntentResult(
        intent="unknown",
        confidence=0.0,
        params={},
        raw_response="",
        tokens_used=0,
        duration_ms=0,
    )
    assert r.intent == "unknown"
    assert r.params == {}
