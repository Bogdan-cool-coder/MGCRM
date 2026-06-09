"""Эпик 18 — AI Features: pure-function тесты.

Без сети, без БД (in-memory MagicMock для AsyncSession). Тестируем:
- parse_json_response: markdown wrap / clean JSON / встроенный JSON
- parse_contract_analysis_response: whitelist severity / status, fallbacks
- parse_prefill_response: whitelist field / confidence, дефолтный label
- normalize_source / normalize_period_days
- build_contract_analysis_prompt: truncation на 50K
- build_prefill_prompt: пустой контекст
- _format_activities: пустой / нормальный
- is_analysis_cache_fresh: edge cases с tzinfo
- ClaudeUsage.from_anthropic_usage parser
- get_ai_status: shape
"""
from __future__ import annotations

from datetime import UTC, datetime, timedelta
from types import SimpleNamespace
from unittest.mock import MagicMock

import pytest

from app.services.ai_features import (
    ALLOWED_CONFIDENCE,
    ALLOWED_DEAL_FIELDS,
    ALLOWED_ISSUE_SEVERITY,
    ALLOWED_PREFILL_SOURCE,
    ALLOWED_SECTION_STATUS,
    CONTRACT_ANALYSIS_CACHE_TTL,
    DEFAULT_FIELD_LABELS,
    _format_activities,
    build_contract_analysis_prompt,
    build_prefill_prompt,
    get_ai_status,
    is_analysis_cache_fresh,
    normalize_period_days,
    normalize_source,
    parse_contract_analysis_response,
    parse_prefill_response,
)
from app.services.anthropic_client import (
    AIResponseError,
    ClaudeUsage,
    parse_json_response,
)


# ============ Whitelists ============


def test_whitelists_are_frozensets():
    """Защита от мутации в runtime — whitelist'ы — frozenset."""
    assert isinstance(ALLOWED_ISSUE_SEVERITY, frozenset)
    assert isinstance(ALLOWED_SECTION_STATUS, frozenset)
    assert isinstance(ALLOWED_CONFIDENCE, frozenset)
    assert isinstance(ALLOWED_DEAL_FIELDS, frozenset)
    assert isinstance(ALLOWED_PREFILL_SOURCE, frozenset)


def test_severity_whitelist_contains_expected():
    assert "error" in ALLOWED_ISSUE_SEVERITY
    assert "warning" in ALLOWED_ISSUE_SEVERITY
    assert "info" in ALLOWED_ISSUE_SEVERITY
    assert "critical" not in ALLOWED_ISSUE_SEVERITY  # fallback на info


def test_confidence_whitelist():
    assert ALLOWED_CONFIDENCE == frozenset({"high", "medium", "low"})


def test_deal_fields_whitelist_includes_core_fields():
    assert "qualification_score" in ALLOWED_DEAL_FIELDS
    assert "expected_close_date" in ALLOWED_DEAL_FIELDS
    assert "amount" in ALLOWED_DEAL_FIELDS
    # запрещены опасные системные поля
    assert "id" not in ALLOWED_DEAL_FIELDS
    assert "owner_user_id" not in ALLOWED_DEAL_FIELDS


def test_default_field_labels_cover_whitelist():
    """Каждое поле в whitelist должно иметь дефолтный RU label."""
    for field in ALLOWED_DEAL_FIELDS:
        assert field in DEFAULT_FIELD_LABELS, f"No default label for {field}"


# ============ parse_json_response ============


def test_parse_json_response_plain_json():
    raw = '{"foo": 1, "bar": "baz"}'
    result = parse_json_response(raw)
    assert result == {"foo": 1, "bar": "baz"}


def test_parse_json_response_markdown_wrapped():
    raw = '```json\n{"foo": 1}\n```'
    result = parse_json_response(raw)
    assert result == {"foo": 1}


def test_parse_json_response_markdown_no_lang():
    raw = '```\n{"a": [1, 2]}\n```'
    result = parse_json_response(raw)
    assert result == {"a": [1, 2]}


def test_parse_json_response_with_leading_prose():
    """Claude иногда добавляет вступление перед JSON. Должны вырезать первый {…}."""
    raw = 'Вот результат анализа:\n\n{"issues": []}\n\nГотово.'
    result = parse_json_response(raw)
    assert result == {"issues": []}


def test_parse_json_response_invalid_raises():
    with pytest.raises(AIResponseError):
        parse_json_response("not json at all")


def test_parse_json_response_non_dict_raises():
    """Claude вернул массив — это не наш контракт."""
    with pytest.raises(AIResponseError):
        parse_json_response("[1, 2, 3]")


# ============ parse_json_response — robust strategies (hotfix Эпика 18) ============


def test_parse_json_response_empty_raises():
    """Пустой/whitespace-only text → AIResponseError."""
    with pytest.raises(AIResponseError):
        parse_json_response("")
    with pytest.raises(AIResponseError):
        parse_json_response("   \n\t  ")


def test_parse_json_response_trailing_comma_repaired():
    """Trailing comma в массиве — невалидный JSON. json_repair должен починить."""
    raw = '{"issues": [1, 2, 3,]}'
    result = parse_json_response(raw)
    assert result == {"issues": [1, 2, 3]}


def test_parse_json_response_unescaped_inner_quotes_repaired():
    """Реальный кейс из прода: Claude вернул JSON с неэкранированной кавычкой
    внутри строкового значения. json_repair должен это починить.

    Это именно тот случай, который падал в проде с
    'Expecting , delimiter: line 68 column 6'.
    """
    raw = '{"quote": "Общество с "ограниченной" ответственностью", "ok": true}'
    result = parse_json_response(raw)
    # Главное — что не падает; точная форма строки зависит от json_repair.
    assert isinstance(result, dict)
    assert "quote" in result
    assert result.get("ok") is True


def test_parse_json_response_markdown_with_unescaped_quotes_repaired():
    """Комбинированный кейс: markdown wrap + неэкранированные кавычки.
    Strategy 1 (markdown strip) + Strategy 4 (json_repair).
    """
    raw = '```json\n{"issues": [{"quote": "Договор "АБВ" № 1", "severity": "warning"}]}\n```'
    result = parse_json_response(raw)
    assert "issues" in result
    assert isinstance(result["issues"], list)
    assert len(result["issues"]) == 1


def test_parse_json_response_prose_around_broken_json_repaired():
    """Strategy 3 (extract {...}) + Strategy 4 (json_repair) каскадно."""
    raw = (
        'Вот результат анализа договора:\n\n'
        '{"issues": [{"quote": "пункт "5.1" говорит", "severity": "info"}]}\n\n'
        'Спасибо за внимание.'
    )
    result = parse_json_response(raw)
    assert "issues" in result


def test_parse_json_response_completely_broken_raises():
    """Совсем мусор (никакой { не находится) → AIResponseError с диагностикой."""
    with pytest.raises(AIResponseError) as exc_info:
        parse_json_response("absolutely no json here, just words")
    # Сообщение должно содержать длину и начало текста для диагностики.
    msg = str(exc_info.value)
    assert "length" in msg.lower() or "len" in msg.lower()


def test_parse_json_response_single_quotes_repaired():
    """Невалидный JSON с одинарными кавычками вместо двойных. json_repair чинит."""
    raw = "{'foo': 1, 'bar': 'baz'}"
    result = parse_json_response(raw)
    assert result == {"foo": 1, "bar": "baz"}


def test_parse_json_response_markdown_with_lang_variants():
    """Поддержка разных языковых тегов в markdown fence: ```json, ```JSON, ```javascript."""
    for lang in ("json", "JSON", "javascript", "js"):
        raw = f"```{lang}\n{{\"k\": \"v\"}}\n```"
        result = parse_json_response(raw)
        assert result == {"k": "v"}, f"Failed for lang={lang}"


def test_parse_json_response_markdown_fence_no_trailing_newline():
    """Markdown без trailing newline перед закрывающим ```."""
    raw = '```json\n{"x": 1}```'
    result = parse_json_response(raw)
    assert result == {"x": 1}


# ============ parse_contract_analysis_response ============


def test_parse_contract_analysis_happy_path():
    raw = {
        "issues": [
            {
                "severity": "warning",
                "quote": "Цитата",
                "explanation": "Объяснение",
                "suggestion": "Совет",
                "section": "п. 3.2",
            }
        ],
        "standard_sections": [
            {"section": "Предмет", "status": "ok"},
            {"section": "Срок", "status": "warning"},
        ],
        "recommendations": ["Пересмотреть тариф"],
    }
    result = parse_contract_analysis_response(raw)
    assert len(result["issues"]) == 1
    assert result["issues"][0]["severity"] == "warning"
    assert result["issues"][0]["quote"] == "Цитата"
    assert result["issues"][0]["section"] == "п. 3.2"
    assert len(result["standard_sections"]) == 2
    assert result["recommendations"] == ["Пересмотреть тариф"]


def test_parse_contract_analysis_unknown_severity_falls_back_to_info():
    raw = {
        "issues": [
            {"severity": "CRITICAL", "explanation": "test"},  # неизвестное
        ]
    }
    result = parse_contract_analysis_response(raw)
    assert result["issues"][0]["severity"] == "info"


def test_parse_contract_analysis_missing_fields_get_defaults():
    raw = {"issues": [{"severity": "error"}]}
    result = parse_contract_analysis_response(raw)
    issue = result["issues"][0]
    assert issue["severity"] == "error"
    assert issue["quote"] == ""
    assert issue["explanation"] == ""
    assert issue["suggestion"] is None
    assert issue["section"] is None


def test_parse_contract_analysis_drops_non_dict_items():
    raw = {
        "issues": [
            {"severity": "error", "explanation": "ok"},
            "garbage string",
            None,
        ]
    }
    result = parse_contract_analysis_response(raw)
    assert len(result["issues"]) == 1


def test_parse_contract_analysis_empty_input():
    """Пустой dict не должен взрываться."""
    result = parse_contract_analysis_response({})
    assert result == {"issues": [], "standard_sections": [], "recommendations": []}


def test_parse_contract_analysis_non_dict_input():
    """Безопасность от мусора."""
    result = parse_contract_analysis_response([])  # type: ignore[arg-type]
    assert result == {"issues": [], "standard_sections": [], "recommendations": []}


def test_parse_contract_analysis_truncates_long_quote():
    """Quote ограничен 2000 chars."""
    raw = {"issues": [{"severity": "info", "quote": "x" * 5000, "explanation": "ok"}]}
    result = parse_contract_analysis_response(raw)
    assert len(result["issues"][0]["quote"]) == 2000


def test_parse_contract_analysis_filters_invalid_section_status():
    raw = {"standard_sections": [{"section": "Foo", "status": "weird"}]}
    result = parse_contract_analysis_response(raw)
    assert result["standard_sections"][0]["status"] == "ok"  # fallback


def test_parse_contract_analysis_skips_empty_recommendations():
    raw = {"recommendations": ["valid", "  ", "", 123, None]}
    result = parse_contract_analysis_response(raw)
    assert result["recommendations"] == ["valid"]


# ============ parse_prefill_response ============


def test_parse_prefill_happy_path():
    raw = {
        "summary": "Клиент ищет CRM",
        "suggestions": [
            {
                "field": "qualification_score",
                "label": "Оценка",
                "suggested_value": 7,
                "confidence": "high",
                "reasoning": "Клиент сказал '7 из 10'",
                "source_activity_id": 42,
                "source_text": "В переписке от 1 июня",
            }
        ],
    }
    result = parse_prefill_response(raw)
    assert result["summary"] == "Клиент ищет CRM"
    assert len(result["suggestions"]) == 1
    s = result["suggestions"][0]
    assert s["field"] == "qualification_score"
    assert s["suggested_value"] == 7
    assert s["confidence"] == "high"
    assert s["source_activity_id"] == 42


def test_parse_prefill_drops_unwhitelisted_field():
    """Claude предложил `password` — он не в whitelist."""
    raw = {
        "suggestions": [
            {"field": "password", "label": "Пароль", "suggested_value": "1234", "confidence": "high"},
            {"field": "amount", "label": "Сумма", "suggested_value": 1000, "confidence": "medium"},
        ]
    }
    result = parse_prefill_response(raw)
    assert len(result["suggestions"]) == 1
    assert result["suggestions"][0]["field"] == "amount"


def test_parse_prefill_default_label_from_field():
    """Claude не дал label — берём DEFAULT_FIELD_LABELS."""
    raw = {"suggestions": [{"field": "amount", "confidence": "high", "suggested_value": 1000}]}
    result = parse_prefill_response(raw)
    assert result["suggestions"][0]["label"] == "Сумма сделки"


def test_parse_prefill_invalid_confidence_falls_back_to_low():
    raw = {"suggestions": [{"field": "amount", "confidence": "EXTRA-HIGH", "suggested_value": 1}]}
    result = parse_prefill_response(raw)
    assert result["suggestions"][0]["confidence"] == "low"


def test_parse_prefill_no_summary():
    """summary опционально."""
    raw = {"suggestions": []}
    result = parse_prefill_response(raw)
    assert result["summary"] == ""


def test_parse_prefill_truncates_long_reasoning():
    raw = {"suggestions": [
        {"field": "amount", "confidence": "high", "suggested_value": 1,
         "reasoning": "x" * 1000}
    ]}
    result = parse_prefill_response(raw)
    assert len(result["suggestions"][0]["reasoning"]) == 500


def test_parse_prefill_drops_non_int_source_activity_id():
    raw = {"suggestions": [
        {"field": "amount", "confidence": "high", "suggested_value": 1,
         "source_activity_id": "not-int"}
    ]}
    result = parse_prefill_response(raw)
    assert result["suggestions"][0]["source_activity_id"] is None


# ============ normalize_source ============


def test_normalize_source_valid():
    assert normalize_source("all") == "all"
    assert normalize_source("tg") == "tg"
    assert normalize_source("EMAIL") == "email"  # lowercase


def test_normalize_source_invalid_falls_back_to_all():
    assert normalize_source("garbage") == "all"
    assert normalize_source(None) == "all"
    assert normalize_source("") == "all"


# ============ normalize_period_days ============


def test_normalize_period_days_zero_or_negative_is_zero():
    """0 = всё время. Отрицательное тоже → 0."""
    assert normalize_period_days(0) is 0  # noqa: F632 — оба int 0
    assert normalize_period_days(-5) == 0
    assert normalize_period_days(None) == 0


def test_normalize_period_days_caps_at_365():
    assert normalize_period_days(1000) == 365
    assert normalize_period_days(365) == 365


def test_normalize_period_days_normal():
    assert normalize_period_days(7) == 7
    assert normalize_period_days(30) == 30


# ============ build_contract_analysis_prompt ============


def test_build_contract_analysis_prompt_short():
    prompt = build_contract_analysis_prompt(
        contract_number="TST-123",
        contract_text="Краткий текст договора",
        product_code="macrocrm",
        country_code="kz",
    )
    assert "TST-123" in prompt
    assert "macrocrm" in prompt
    assert "kz" in prompt
    assert "Краткий текст договора" in prompt
    assert "обрезан" not in prompt


def test_build_contract_analysis_prompt_truncates_long_text():
    big_text = "x" * 100_000
    prompt = build_contract_analysis_prompt(
        contract_number=None,
        contract_text=big_text,
        product_code="macrocrm",
        country_code="kz",
    )
    # Должен содержать truncation pointer
    assert "обрезан" in prompt
    # И полный текст не должен быть в промпте
    assert len(prompt) < 100_000 + 1000


def test_build_contract_analysis_prompt_no_number():
    """Если number=None — подставляем '-'."""
    prompt = build_contract_analysis_prompt(
        contract_number=None,
        contract_text="Текст",
        product_code="x",
        country_code="y",
    )
    assert "#-" in prompt


# ============ build_prefill_prompt ============


def test_build_prefill_prompt_empty_activities():
    prompt = build_prefill_prompt("сделке", 42, "")
    assert "не найдено" in prompt
    assert "42" in prompt
    assert "suggestions: []" in prompt


def test_build_prefill_prompt_with_activities():
    prompt = build_prefill_prompt("лиду", 7, "Активность 1\nАктивность 2")
    assert "лиду #7" in prompt
    assert "Активность 1" in prompt
    assert "BANT" in prompt


# ============ _format_activities ============


def test_format_activities_empty():
    assert _format_activities([]) == ""


def test_format_activities_single():
    """Mock Activity-like объект через SimpleNamespace."""
    act = SimpleNamespace(
        id=1,
        kind="call",
        title="Звонок клиенту",
        body="Обсудили бюджет 500K",
        created_at=datetime(2026, 6, 1, tzinfo=UTC),
    )
    result = _format_activities([act])
    assert "2026-06-01" in result
    assert "call" in result
    assert "id=1" in result
    assert "Звонок клиенту" in result
    assert "Обсудили бюджет 500K" in result


def test_format_activities_no_body():
    act = SimpleNamespace(
        id=2, kind="task", title="Задача", body=None,
        created_at=datetime(2026, 6, 1, tzinfo=UTC),
    )
    result = _format_activities([act])
    assert "Задача" in result
    # пустой body не должен ничего добавить
    assert result.count("\n") <= 3  # title строка + пустая разделительная


def test_format_activities_no_date():
    act = SimpleNamespace(
        id=3, kind="note", title="Заметка", body=None,
        created_at=None,
    )
    result = _format_activities([act])
    assert "—" in result  # placeholder для даты


def test_format_activities_truncates_long_body():
    big_body = "y" * 5000
    act = SimpleNamespace(
        id=4, kind="note", title="t", body=big_body,
        created_at=datetime(2026, 6, 1, tzinfo=UTC),
    )
    result = _format_activities([act])
    # 2000 ограничение
    assert "y" * 2000 in result
    assert "y" * 2001 not in result


# ============ is_analysis_cache_fresh ============


def test_cache_fresh_none():
    """analyzed_at=None → не свежий."""
    assert is_analysis_cache_fresh(None) is False


def test_cache_fresh_recent():
    now = datetime.now(UTC)
    fresh = now - timedelta(minutes=10)
    assert is_analysis_cache_fresh(fresh) is True


def test_cache_fresh_old():
    now = datetime.now(UTC)
    stale = now - timedelta(hours=2)
    assert is_analysis_cache_fresh(stale) is False


def test_cache_fresh_at_ttl_edge():
    """Точно на границе TTL — не свежий."""
    now = datetime(2026, 6, 1, 12, 0, 0, tzinfo=UTC)
    at_edge = now - CONTRACT_ANALYSIS_CACHE_TTL
    assert is_analysis_cache_fresh(at_edge, now=now) is False


def test_cache_fresh_naive_datetime_handled():
    """Старая запись без tzinfo — не должна крашить (assume UTC)."""
    naive = datetime.now() - timedelta(minutes=10)
    # Падать не должно (раньше — TypeError на сравнении aware vs naive).
    result = is_analysis_cache_fresh(naive)
    assert isinstance(result, bool)


# ============ ClaudeUsage parser ============


def test_claude_usage_from_anthropic_usage():
    """Anthropic Usage SDK: input_tokens / output_tokens."""
    mock_usage = SimpleNamespace(input_tokens=100, output_tokens=50)
    u = ClaudeUsage.from_anthropic_usage(mock_usage)
    assert u.prompt_tokens == 100
    assert u.completion_tokens == 50
    assert u.total_tokens == 150


def test_claude_usage_from_anthropic_missing_attrs():
    """Если attrs отсутствуют — 0."""
    mock_usage = SimpleNamespace()
    u = ClaudeUsage.from_anthropic_usage(mock_usage)
    assert u.prompt_tokens == 0
    assert u.completion_tokens == 0
    assert u.total_tokens == 0


def test_claude_usage_handles_none_values():
    mock_usage = SimpleNamespace(input_tokens=None, output_tokens=None)
    u = ClaudeUsage.from_anthropic_usage(mock_usage)
    assert u.total_tokens == 0


# ============ get_ai_status ============


def test_get_ai_status_shape():
    status = get_ai_status()
    assert "available" in status
    assert "model" in status
    assert isinstance(status["available"], bool)
