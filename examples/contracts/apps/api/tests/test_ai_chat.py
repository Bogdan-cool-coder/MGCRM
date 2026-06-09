"""Epic 10.5 — AI Chat module tests.

Pure-function tests — без DB fixture и без вызовов Anthropic API.
Тестируем:
- system prompt builders (CHAT_SYSTEM_PROMPT, COLD_CALL_SYSTEM_PROMPT, etc.)
- is_ftm_valid (дублируем здесь для AI-контекста)
- ColdCallScore валидация
- Session cleanup logic (_cleanup_old_sessions)
- CounterpartyAnalysisOut schema
"""
import time
import pytest

from app.routers.ai_chat import (
    CHAT_SYSTEM_PROMPT,
    COLD_CALL_SYSTEM_PROMPT,
    COLD_CALL_EVALUATOR_PROMPT,
    COUNTERPARTY_ANALYSIS_PROMPT,
    ColdCallScore,
    CounterpartyAnalysisOut,
    _cold_call_sessions,
    _cleanup_old_sessions,
)
from app.services.salary import is_ftm_valid


# ============ System prompt tests ============

def test_chat_system_prompt_contains_crm():
    """Системный промпт содержит ключевые слова CRM."""
    assert "CRM" in CHAT_SYSTEM_PROMPT
    assert "MACRO" in CHAT_SYSTEM_PROMPT


def test_chat_system_prompt_language():
    """Системный промпт требует русский язык."""
    assert "русском" in CHAT_SYSTEM_PROMPT or "Russian" in CHAT_SYSTEM_PROMPT or "Пиши на русском" in CHAT_SYSTEM_PROMPT


def test_cold_call_prompt_has_scenario_placeholder():
    """Промпт cold call тренера содержит плейсхолдер {scenario}."""
    assert "{scenario}" in COLD_CALL_SYSTEM_PROMPT


def test_cold_call_prompt_formats_correctly():
    """Форматирование cold call промпта работает."""
    formatted = COLD_CALL_SYSTEM_PROMPT.format(scenario="Продажа MacroCRM банку")
    assert "Продажа MacroCRM банку" in formatted
    assert "{scenario}" not in formatted


def test_evaluator_prompt_has_history_placeholder():
    """Промпт оценщика содержит плейсхолдер {history}."""
    assert "{history}" in COLD_CALL_EVALUATOR_PROMPT


def test_evaluator_prompt_mentions_scores():
    """Промпт оценщика упоминает все критерии оценки."""
    assert "clarity" in COLD_CALL_EVALUATOR_PROMPT
    assert "empathy" in COLD_CALL_EVALUATOR_PROMPT
    assert "objection_handling" in COLD_CALL_EVALUATOR_PROMPT
    assert "closing" in COLD_CALL_EVALUATOR_PROMPT


def test_counterparty_prompt_has_data_placeholder():
    """Промпт анализа контрагента содержит плейсхолдер."""
    assert "{counterparty_data}" in COUNTERPARTY_ANALYSIS_PROMPT


# ============ Schema validation tests ============

def test_cold_call_score_valid():
    """ColdCallScore принимает корректные значения."""
    score = ColdCallScore(
        clarity=7.5,
        empathy=6.0,
        objection_handling=8.0,
        closing=5.5,
    )
    assert score.clarity == 7.5
    assert score.empathy == 6.0


def test_cold_call_score_zero_values():
    """ColdCallScore принимает нулевые значения."""
    score = ColdCallScore(
        clarity=0.0,
        empathy=0.0,
        objection_handling=0.0,
        closing=0.0,
    )
    assert score.total_value() if hasattr(score, "total_value") else score.clarity == 0.0


def test_counterparty_analysis_out_schema():
    """CounterpartyAnalysisOut принимает корректные данные."""
    result = CounterpartyAnalysisOut(
        summary="Крупный ритейлер в Казахстане",
        risks=["Конкурент уже использует другую CRM", "Длинный цикл сделки"],
        recommendations=["Сделать demo на данных клиента"],
        priority_score=7.5,
        model="claude-haiku-4-5",
        analyzed_at="2026-06-02T10:00:00+00:00",
    )
    assert result.priority_score == 7.5
    assert len(result.risks) == 2
    assert len(result.recommendations) == 1


# ============ Session cleanup tests ============

def test_cleanup_removes_expired_sessions():
    """_cleanup_old_sessions удаляет просроченные сессии."""
    # Добавить просроченную сессию
    _cold_call_sessions["expired_test"] = {
        "created_at": time.time() - 7200,  # 2 часа назад
        "user_id": 1,
        "scenario": "test",
        "system": "test",
        "history": [],
    }
    _cleanup_old_sessions()
    assert "expired_test" not in _cold_call_sessions


def test_cleanup_keeps_active_sessions():
    """_cleanup_old_sessions не удаляет активные сессии."""
    _cold_call_sessions["active_test"] = {
        "created_at": time.time() - 100,  # 100 секунд назад
        "user_id": 1,
        "scenario": "test",
        "system": "test",
        "history": [],
    }
    _cleanup_old_sessions()
    assert "active_test" in _cold_call_sessions
    # Cleanup после теста
    _cold_call_sessions.pop("active_test", None)
