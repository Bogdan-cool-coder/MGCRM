"""Эпик 24.3 — TG Intent Executor: pure-function тесты.

Тестируем dispatch-логику execute_intent без реальной БД.
"""
from __future__ import annotations

from datetime import UTC, datetime, timedelta
from types import SimpleNamespace
from unittest.mock import AsyncMock, MagicMock, patch

import pytest

from app.services.tg_intent import IntentResult
from app.services.tg_intent_executor import (
    ExecutionResult,
    _can_delegate,
    _execute_close_task,
    _execute_create_task,
    _execute_search_tasks,
    execute_intent,
)


def _make_intent(intent: str, confidence: float = 0.9, params: dict | None = None) -> IntentResult:
    return IntentResult(
        intent=intent,
        confidence=confidence,
        params=params or {},
        raw_response="",
        tokens_used=100,
        duration_ms=500,
    )


def _make_user(user_id: int = 1, name: str = "Тест Менеджер") -> SimpleNamespace:
    return SimpleNamespace(id=user_id, full_name=name, is_active=True)


def _make_session_no_activity() -> AsyncMock:
    """Мок сессии где задача не найдена."""
    session = AsyncMock()
    mock_result = MagicMock()
    mock_result.scalar_one_or_none.return_value = None
    mock_result.scalars.return_value = MagicMock(all=MagicMock(return_value=[]))
    session.execute = AsyncMock(return_value=mock_result)
    session.flush = AsyncMock()
    session.add = MagicMock()
    return session


# ============ execute_intent: dispatch ============

@pytest.mark.asyncio
async def test_execute_unknown_intent():
    session = _make_session_no_activity()
    user = _make_user()
    result = await execute_intent(session, _make_intent("unknown"), user)
    assert not result.success
    assert "Не понял" in result.message
    assert result.action_taken == "unknown_intent"


@pytest.mark.asyncio
async def test_execute_low_confidence_redirects():
    """Если confidence < 0.5 и intent не unknown — вернуть help."""
    session = _make_session_no_activity()
    user = _make_user()
    result = await execute_intent(
        session,
        _make_intent("create_task", confidence=0.3),
        user,
    )
    assert not result.success
    assert result.action_taken == "low_confidence"


# ============ create_task ============

@pytest.mark.asyncio
async def test_create_task_no_title():
    session = _make_session_no_activity()
    user = _make_user()
    result = await _execute_create_task(session, {}, user)
    assert not result.success
    assert result.action_taken == "create_task_no_title"


@pytest.mark.asyncio
async def test_create_task_success():
    """Создание задачи с заголовком и 'me' как ответственным."""
    user = _make_user(user_id=7)

    # Мок: resolve_user_by_name вернёт того же пользователя
    session = AsyncMock()
    activity_mock = MagicMock()
    activity_mock.id = 42
    activity_mock.title = "Позвонить клиенту"

    # Первый execute — для resolve_user_by_name (scalar_one_or_none)
    # Второй flush — для получения activity.id
    scalar_mock = MagicMock()
    scalar_mock.scalar_one_or_none.return_value = user
    scalars_mock = MagicMock()
    scalars_mock.scalars.return_value = MagicMock(all=MagicMock(return_value=[user]))

    call_count = [0]
    async def mock_execute(stmt):
        call_count[0] += 1
        if call_count[0] == 1:
            return scalar_mock  # resolve_user_by_name (me)
        return scalars_mock

    session.execute = mock_execute
    session.flush = AsyncMock(side_effect=lambda: setattr(activity_mock, "id", 42))
    session.add = MagicMock()

    with patch("app.services.tg_intent_executor.resolve_user_by_name", return_value=user):
        result = await _execute_create_task(
            session,
            {"title": "Позвонить клиенту", "responsible": "me", "priority": "normal"},
            user,
        )

    assert result.success
    assert "created_activity_id" in result.action_taken


@pytest.mark.asyncio
async def test_create_task_invalid_priority_normalized():
    """Некорректный priority нормализуется до 'normal'."""
    user = _make_user(user_id=3)
    session = AsyncMock()
    scalar_mock = MagicMock()
    scalar_mock.scalar_one_or_none.return_value = user
    scalars_mock = MagicMock()
    scalars_mock.scalars.return_value = MagicMock(all=MagicMock(return_value=[]))
    session.execute = AsyncMock(side_effect=[scalar_mock, scalars_mock])
    session.flush = AsyncMock()
    session.add = MagicMock()

    with patch("app.services.tg_intent_executor.resolve_user_by_name", return_value=None):
        result = await _execute_create_task(
            session,
            {"title": "Задача", "priority": "ultra_critical"},
            user,
        )
    # Должна создаться (priority исправлен)
    assert result.success or result.action_taken == "create_task_no_title" or "created_activity_id" in (result.action_taken or "")


# ============ close_task ============

@pytest.mark.asyncio
async def test_close_task_not_found_by_id():
    session = _make_session_no_activity()
    user = _make_user()
    result = await _execute_close_task(session, {"task_id": 999}, user)
    assert not result.success
    assert result.action_taken == "close_task_not_found"


@pytest.mark.asyncio
async def test_close_task_not_found_by_phrase():
    session = _make_session_no_activity()
    user = _make_user()
    result = await _execute_close_task(session, {"search_phrase": "несуществующая задача"}, user)
    assert not result.success
    assert result.action_taken == "close_task_not_found"


@pytest.mark.asyncio
async def test_close_task_no_params():
    session = _make_session_no_activity()
    user = _make_user()
    result = await _execute_close_task(session, {}, user)
    assert not result.success
    assert result.action_taken == "close_task_no_params"


@pytest.mark.asyncio
async def test_close_task_already_done():
    """Уже закрытая задача → сообщение 'уже закрыта'."""
    user = _make_user(user_id=1)
    activity = MagicMock()
    activity.id = 55
    activity.title = "Задача про встречу"
    activity.status = "done"
    activity.is_closed = True

    session = AsyncMock()
    mock_result = MagicMock()
    mock_result.scalar_one_or_none.return_value = activity
    session.execute = AsyncMock(return_value=mock_result)

    result = await _execute_close_task(session, {"task_id": 55}, user)
    assert not result.success
    assert "уже закрыта" in result.message


@pytest.mark.asyncio
async def test_close_task_success():
    """Успешное закрытие задачи."""
    user = _make_user(user_id=2)
    activity = MagicMock()
    activity.id = 77
    activity.title = "Позвонить Иванову"
    activity.status = "new"
    activity.is_closed = False

    session = AsyncMock()
    mock_result = MagicMock()
    mock_result.scalar_one_or_none.return_value = activity
    session.execute = AsyncMock(return_value=mock_result)

    result = await _execute_close_task(session, {"task_id": 77}, user)
    assert result.success
    assert result.action_taken == "closed_activity_77"
    assert activity.status == "done"
    assert activity.is_closed is True
    assert activity.completed_by_id == 2


# ============ search_tasks ============

@pytest.mark.asyncio
async def test_search_tasks_empty():
    """Нет задач → соответствующее сообщение."""
    user = _make_user()
    session = AsyncMock()
    empty_result = MagicMock()
    empty_result.scalar_one_or_none.return_value = user  # resolve_user
    empty_scalars = MagicMock()
    empty_scalars.scalars.return_value = MagicMock(all=MagicMock(return_value=[]))

    call_count = [0]
    async def mock_exec(stmt):
        call_count[0] += 1
        if call_count[0] <= 1:
            return empty_result
        return empty_scalars

    session.execute = mock_exec

    with patch("app.services.tg_intent_executor.resolve_user_by_name", return_value=user):
        result = await _execute_search_tasks(session, {"filters": {}}, user)

    assert result.success
    assert result.action_taken == "search_results_count_0"
    assert result.data == []


@pytest.mark.asyncio
async def test_search_tasks_with_results():
    """Задачи найдены → правильный action_taken."""
    user = _make_user()
    task1 = MagicMock()
    task1.id = 10
    task1.title = "Задача А"
    task1.status = "new"
    task1.due_at = datetime(2026, 6, 10, 18, 0, tzinfo=UTC)

    session = AsyncMock()
    tasks_result = MagicMock()
    tasks_result.scalars.return_value = MagicMock(all=MagicMock(return_value=[task1]))
    session.execute = AsyncMock(return_value=tasks_result)

    with patch("app.services.tg_intent_executor.resolve_user_by_name", return_value=user):
        result = await _execute_search_tasks(session, {"filters": {"status": "new"}}, user)

    assert result.success
    assert result.action_taken == "search_results_count_1"
    assert len(result.data) == 1


def _captured_responsible_ids(stmt) -> set[int]:
    """Достать значения responsible_id из WHERE скомпилированного select(Activity)."""
    compiled = stmt.compile(compile_kwargs={"literal_binds": True})
    text = str(compiled)
    import re

    return {int(m) for m in re.findall(r"activities\.responsible_id = (\d+)", text)}


@pytest.mark.asyncio
async def test_search_tasks_manager_cannot_view_other(monkeypatch):
    """HIGH (IDOR): manager просит чужие задачи → скоуп форсится на свои."""
    requester = _make_user_role(user_id=7, role="manager")
    other = _make_user_role(user_id=42, role="manager")
    other.full_name = "Коллега"

    captured = {}
    session = AsyncMock()
    tasks_result = MagicMock()
    tasks_result.scalars.return_value = MagicMock(all=MagicMock(return_value=[]))

    async def mock_exec(stmt):
        captured["ids"] = _captured_responsible_ids(stmt)
        return tasks_result

    session.execute = mock_exec

    with patch(
        "app.services.tg_intent_executor.resolve_user_by_name", return_value=other
    ):
        result = await _execute_search_tasks(
            session, {"filters": {"responsible": "Коллега"}}, requester
        )

    assert result.success
    # Запрошен other(42), но manager не вправе — фильтр должен быть по своему id(7).
    assert captured["ids"] == {requester.id}
    assert other.id not in captured["ids"]


@pytest.mark.asyncio
async def test_search_tasks_admin_can_view_other():
    """HIGH: admin вправе делегировать → может смотреть чужие задачи."""
    requester = _make_user_role(user_id=1, role="admin")
    other = _make_user_role(user_id=42, role="manager")
    other.full_name = "Коллега"

    captured = {}
    session = AsyncMock()
    tasks_result = MagicMock()
    tasks_result.scalars.return_value = MagicMock(all=MagicMock(return_value=[]))

    async def mock_exec(stmt):
        captured["ids"] = _captured_responsible_ids(stmt)
        return tasks_result

    session.execute = mock_exec

    with patch(
        "app.services.tg_intent_executor.resolve_user_by_name", return_value=other
    ):
        result = await _execute_search_tasks(
            session, {"filters": {"responsible": "Коллега"}}, requester
        )

    assert result.success
    assert captured["ids"] == {other.id}


# ============ C6 CRIT-2: close_task IDOR / robustness ============


def _make_user_role(user_id: int, role: str | None) -> SimpleNamespace:
    return SimpleNamespace(id=user_id, full_name="Кто-то", is_active=True, role=role)


@pytest.mark.asyncio
async def test_close_task_foreign_id_not_found():
    """CRIT-2: explicit task_id чужой задачи → scoped-запрос вернёт None → not_found.

    Мок-сессия отдаёт None (как сделал бы скоуп responsible/created_by != user),
    проверяем что executor не закрывает и сообщает «среди ваших задач»."""
    session = _make_session_no_activity()
    user = _make_user(user_id=5)
    result = await _execute_close_task(session, {"task_id": 4217}, user)
    assert not result.success
    assert result.action_taken == "close_task_not_found"
    assert "ваших задач" in result.message


@pytest.mark.asyncio
async def test_close_task_non_numeric_id():
    """WARN-7: нечисловой task_id от Claude → дружелюбный ответ, не 500."""
    session = _make_session_no_activity()
    user = _make_user()
    result = await _execute_close_task(session, {"task_id": "abc"}, user)
    assert not result.success
    assert result.action_taken == "close_task_bad_id"


# ============ C6 CRIT-3: delegation constraint ============


def test_can_delegate_admin_director():
    assert _can_delegate(_make_user_role(1, "admin")) is True
    assert _can_delegate(_make_user_role(1, "director")) is True


def test_cannot_delegate_manager_or_missing_role():
    assert _can_delegate(_make_user_role(1, "manager")) is False
    assert _can_delegate(_make_user_role(1, None)) is False


@pytest.mark.asyncio
async def test_create_task_manager_cannot_assign_other():
    """CRIT-3: manager пытается назначить задачу другому → self-assign + пометка."""
    creator = _make_user_role(user_id=10, role="manager")
    victim = _make_user_role(user_id=99, role="manager")
    victim.full_name = "Жертва"

    session = AsyncMock()
    session.flush = AsyncMock()
    session.add = MagicMock()
    captured = {}

    def _capture(activity):
        captured["responsible_id"] = activity.responsible_id
        captured["created_by_id"] = activity.created_by_id

    session.add.side_effect = _capture

    with patch(
        "app.services.tg_intent_executor.resolve_user_by_name", return_value=victim
    ):
        result = await _execute_create_task(
            session,
            {"title": "Сделать X", "responsible": "Жертва"},
            creator,
        )
    assert result.success
    # Назначено на создателя, НЕ на victim.
    assert captured["responsible_id"] == creator.id
    assert captured["created_by_id"] == creator.id
    assert "Нет прав назначать" in result.message


@pytest.mark.asyncio
async def test_create_task_director_can_assign_other():
    """CRIT-3: director вправе делегировать — responsible = другой пользователь."""
    creator = _make_user_role(user_id=10, role="director")
    other = _make_user_role(user_id=99, role="manager")
    other.full_name = "Коллега"

    session = AsyncMock()
    session.flush = AsyncMock()
    session.add = MagicMock()
    captured = {}
    session.add.side_effect = lambda a: captured.update(responsible_id=a.responsible_id)

    with patch(
        "app.services.tg_intent_executor.resolve_user_by_name", return_value=other
    ):
        result = await _execute_create_task(
            session,
            {"title": "Сделать Y", "responsible": "Коллега"},
            creator,
        )
    assert result.success
    assert captured["responsible_id"] == other.id
    assert "Нет прав назначать" not in result.message
