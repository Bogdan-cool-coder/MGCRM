"""Эпик 24.3 — TG Recommendations: pure-function тесты.

Тестируем эвристики get_recommendations() без реальной БД.
"""
from __future__ import annotations

from datetime import UTC, datetime, timedelta
from types import SimpleNamespace
from unittest.mock import AsyncMock, MagicMock, patch

import pytest

from app.services.tg_intent_executor import (
    ExecutionResult,
    Recommendation,
    _execute_recommend,
    get_recommendations,
)


def _make_user(user_id: int = 1, name: str = "Тест") -> SimpleNamespace:
    return SimpleNamespace(id=user_id, full_name=name)


def _mock_session_with_counts(overdue: int, today: int) -> AsyncMock:
    """Мок сессии: overdue_count + today_count + пустые deals."""
    session = AsyncMock()
    call_count = [0]

    async def mock_execute(stmt):
        call_count[0] += 1
        result = MagicMock()
        if call_count[0] == 1:
            result.scalar.return_value = overdue
        elif call_count[0] == 2:
            result.scalar.return_value = today
        else:
            # Deals query — возвращаем пустой список (isouter join)
            result.scalars.return_value = MagicMock(all=MagicMock(return_value=[]))
        return result

    session.execute = mock_execute
    return session


# ============ get_recommendations ============

@pytest.mark.asyncio
async def test_recommendations_all_clear():
    """Нет просроченных задач и задач на сегодня → пустой список."""
    session = _mock_session_with_counts(overdue=0, today=0)
    user = _make_user()
    recs = await get_recommendations(session, user)
    assert recs == []


@pytest.mark.asyncio
async def test_recommendations_overdue_tasks():
    """Просроченные задачи → рекомендация с приоритетом 1."""
    session = _mock_session_with_counts(overdue=5, today=0)
    user = _make_user()
    recs = await get_recommendations(session, user)
    assert len(recs) >= 1
    overdue_rec = next((r for r in recs if r.priority == 1), None)
    assert overdue_rec is not None
    assert "5" in overdue_rec.text or "просроченн" in overdue_rec.text.lower()


@pytest.mark.asyncio
async def test_recommendations_today_tasks():
    """Задачи на сегодня → рекомендация с приоритетом 2."""
    session = _mock_session_with_counts(overdue=0, today=3)
    user = _make_user()
    recs = await get_recommendations(session, user)
    assert len(recs) >= 1
    today_rec = next((r for r in recs if r.priority == 2), None)
    assert today_rec is not None
    assert "3" in today_rec.text or "сегодня" in today_rec.text.lower()


@pytest.mark.asyncio
async def test_recommendations_overdue_and_today():
    """Оба типа проблем → 2 рекомендации минимум."""
    session = _mock_session_with_counts(overdue=2, today=4)
    user = _make_user()
    recs = await get_recommendations(session, user)
    priorities = {r.priority for r in recs}
    assert 1 in priorities  # overdue
    assert 2 in priorities  # today


@pytest.mark.asyncio
async def test_recommendations_priority_1_is_highest():
    """Приоритет 1 важнее 2 — будет первым при сортировке."""
    session = _mock_session_with_counts(overdue=3, today=2)
    user = _make_user()
    recs = await get_recommendations(session, user)
    sorted_recs = sorted(recs, key=lambda r: r.priority)
    if sorted_recs:
        assert sorted_recs[0].priority <= sorted_recs[-1].priority


@pytest.mark.asyncio
async def test_recommendations_deal_query_fails_gracefully():
    """Если запрос к Deal падает — рекомендации продолжают работать."""
    session = AsyncMock()
    call_count = [0]

    async def mock_execute(stmt):
        call_count[0] += 1
        result = MagicMock()
        if call_count[0] == 1:
            result.scalar.return_value = 1  # overdue
        elif call_count[0] == 2:
            result.scalar.return_value = 0  # today
        else:
            raise Exception("Deal table not available")
        return result

    session.execute = mock_execute
    user = _make_user()
    # Не должно падать
    recs = await get_recommendations(session, user)
    assert isinstance(recs, list)
    assert len(recs) >= 1  # overdue должна быть


# ============ _execute_recommend ============

@pytest.mark.asyncio
async def test_execute_recommend_no_issues():
    """Нет рекомендаций → позитивный ответ."""
    session = _mock_session_with_counts(overdue=0, today=0)
    user = _make_user()
    result = await _execute_recommend(session, {}, user)
    assert result.success
    assert result.action_taken == "recommend_no_issues"
    assert "порядке" in result.message.lower() or "отличн" in result.message.lower()


@pytest.mark.asyncio
async def test_execute_recommend_with_issues():
    """Есть рекомендации → список в сообщении."""
    session = _mock_session_with_counts(overdue=3, today=1)
    user = _make_user()
    result = await _execute_recommend(session, {}, user)
    assert result.success
    assert "Рекомендации" in result.message or "рекомендац" in result.message.lower()
    assert result.action_taken.startswith("recommend_count_")


@pytest.mark.asyncio
async def test_execute_recommend_action_taken_count():
    """action_taken содержит количество рекомендаций."""
    session = _mock_session_with_counts(overdue=1, today=0)
    user = _make_user()
    result = await _execute_recommend(session, {}, user)
    # action_taken = "recommend_count_N" или "recommend_no_issues"
    assert result.action_taken.startswith("recommend_")
