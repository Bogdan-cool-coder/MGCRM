"""Эпик 18 — AI Features: Anthropic Claude SDK wrapper.

Тонкая обёртка над `anthropic.AsyncAnthropic`, обеспечивающая:

1. Lazy-singleton клиента. Создаём один на процесс, после первого call_claude().
   Если ANTHROPIC_API_KEY пуст → возвращаем None (caller'у ясно, что 503).

2. `call_claude(prompt, system, model=...)` — высокоуровневый интерфейс.
   - Шлёт messages-API request с одним user-сообщением + system-prompt.
   - Возвращает `ClaudeResponse` (text + usage) при успехе.
   - При ошибке (rate-limit, server-error, network) — повторяем 3 раза с
     exponential backoff, потом бросаем `AIServiceError`.
   - Все вызовы автоматически логируются в `ai_request_log` через
     `log_ai_request()` (caller передаёт session + контекст).

3. `parse_json_response(text)` — pure helper, парсит ответ Claude, который
   мы просили вернуть JSON. Claude иногда оборачивает JSON в ```json …```
   код-блок — снимаем обёртку. Бросаем `AIResponseError` при невалидном JSON.

Архитектурные решения:
- Используем `anthropic.AsyncAnthropic`, НЕ синхронный — наш api FastAPI async.
- Token usage хранится в `ClaudeResponse.usage` (prompt/completion/total).
- model по умолчанию — `settings.anthropic_model` (Sonnet 4.5). Caller может
  передать другую модель для лёгких задач (но в MVP не переопределяем).
- Streaming-вариант (`call_claude_streaming`) — задел для эпика 10.5 (chat),
  в этом эпике не используется.
- ВАЖНО: catch на anthropic.AuthenticationError → graceful 503, чтобы
  устаревший ключ не уронил весь endpoint stack-trace'ом.

Тесты: `tests/test_ai_features.py` — pure-function без сети (мокаем
AsyncAnthropic.messages.create через monkeypatch).
"""
from __future__ import annotations

import asyncio
import json
import logging
import re
import time
from dataclasses import dataclass
from typing import TYPE_CHECKING, Any

from sqlalchemy.ext.asyncio import AsyncSession

from app.config import get_settings
from app.models import AIRequestLog

if TYPE_CHECKING:
    # anthropic SDK импортируется лениво (см. _get_client) — чтобы тесты
    # не падали при отсутствии установленного пакета.
    from anthropic import AsyncAnthropic  # noqa: F401

logger = logging.getLogger(__name__)


# ============ Exceptions ============

class AINotConfiguredError(Exception):
    """ANTHROPIC_API_KEY пуст или клиент не инициализирован.

    Caller'у это сигнал отдать 503 — фронт показывает «AI not configured».
    """


class AIServiceError(Exception):
    """Ошибка при вызове Anthropic API (после всех retry).

    Caller'у это сигнал отдать 502 или 503 в зависимости от причины.
    """


class AIResponseError(Exception):
    """Невалидный JSON в ответе Claude (ожидали structured, получили мусор).

    Бросается только из `parse_json_response`. Caller'у обычно стоит ретрайнуть
    с другим промптом или вернуть 502.
    """


# ============ Data classes ============

@dataclass(frozen=True)
class ClaudeUsage:
    """Usage metric из Anthropic response.usage."""
    prompt_tokens: int
    completion_tokens: int
    total_tokens: int

    @classmethod
    def from_anthropic_usage(cls, usage: Any) -> ClaudeUsage:
        """Парсит anthropic.types.Usage (input_tokens, output_tokens)."""
        prompt = int(getattr(usage, "input_tokens", 0) or 0)
        completion = int(getattr(usage, "output_tokens", 0) or 0)
        return cls(
            prompt_tokens=prompt,
            completion_tokens=completion,
            total_tokens=prompt + completion,
        )


@dataclass(frozen=True)
class ClaudeResponse:
    """Результат вызова Claude messages.create."""
    text: str
    usage: ClaudeUsage
    model: str
    duration_ms: int


# ============ Whitelist features ============

AI_FEATURES: tuple[str, ...] = (
    "contract_analyze",
    "deal_prefill",
    "lead_prefill",
    "summarize",
)


# ============ Lazy singleton ============

_client_instance: Any | None = None
_client_init_attempted = False


def _get_client() -> Any | None:
    """Lazy-инициализация AsyncAnthropic singleton.

    Возвращает None если ANTHROPIC_API_KEY не задан ИЛИ если impport anthropic
    упал (пакет не установлен). Caller проверяет на None и бросает 503.
    """
    global _client_instance, _client_init_attempted
    if _client_init_attempted:
        return _client_instance
    _client_init_attempted = True
    settings = get_settings()
    if not settings.anthropic_api_key:
        logger.info("ANTHROPIC_API_KEY not set — AI features disabled")
        return None
    try:
        from anthropic import AsyncAnthropic
    except ImportError as e:
        logger.warning("anthropic SDK not installed: %s — AI features disabled", e)
        return None
    _client_instance = AsyncAnthropic(api_key=settings.anthropic_api_key)
    return _client_instance


def reset_client_for_tests() -> None:
    """Сброс singleton'а — только для тестов (monkey-patched API key)."""
    global _client_instance, _client_init_attempted
    _client_instance = None
    _client_init_attempted = False


# ============ Core call ============

async def call_claude(
    prompt: str,
    system: str,
    model: str | None = None,
    max_tokens: int = 4096,
    *,
    max_retries: int = 3,
    initial_backoff_s: float = 1.0,
) -> ClaudeResponse:
    """Отправить запрос Claude (messages-API) и вернуть текст + usage.

    Retries: на rate-limit (429) и server errors (5xx) — до 3 попыток с
    exponential backoff (1s → 2s → 4s). AuthenticationError → не ретраим
    (ключ невалидный).

    Бросает:
    - AINotConfiguredError если ключа нет (НЕ AIServiceError, чтобы caller
      различал «настройка» от «временная ошибка»).
    - AIServiceError после всех retry.
    """
    client = _get_client()
    if client is None:
        raise AINotConfiguredError("ANTHROPIC_API_KEY not configured")

    settings = get_settings()
    effective_model = model or settings.anthropic_model
    started = time.perf_counter()

    # Импортируем exception классы лениво — анти-bootstrap трюк, тесты
    # обходятся без anthropic пакета.
    try:
        from anthropic import (
            APIConnectionError,
            APIStatusError,
            AuthenticationError,
            RateLimitError,
        )
    except ImportError as e:
        raise AINotConfiguredError(f"anthropic SDK not installed: {e}") from e

    last_error: Exception | None = None
    for attempt in range(max_retries):
        try:
            response = await client.messages.create(
                model=effective_model,
                max_tokens=max_tokens,
                system=system,
                messages=[{"role": "user", "content": prompt}],
            )
            text = _extract_text(response)
            duration_ms = int((time.perf_counter() - started) * 1000)
            return ClaudeResponse(
                text=text,
                usage=ClaudeUsage.from_anthropic_usage(response.usage),
                model=effective_model,
                duration_ms=duration_ms,
            )
        except AuthenticationError as e:
            # Невалидный API key — не ретраим, граф 503 на caller'е.
            logger.warning("Anthropic auth error: %s", e)
            raise AINotConfiguredError(f"Anthropic auth failed: {e}") from e
        except (RateLimitError, APIConnectionError, APIStatusError) as e:
            last_error = e
            if attempt + 1 == max_retries:
                break
            backoff = initial_backoff_s * (2 ** attempt)
            logger.info(
                "Anthropic call failed (%s), retry %d/%d in %.1fs: %s",
                type(e).__name__, attempt + 1, max_retries, backoff, e,
            )
            await asyncio.sleep(backoff)
        except Exception as e:  # noqa: BLE001
            # Любая непредвиденная ошибка — не ретраим, фейлим сразу.
            last_error = e
            break

    raise AIServiceError(
        f"Anthropic API failed after {max_retries} retries: {last_error}"
    ) from last_error


@dataclass(frozen=True)
class ClaudeToolUse:
    """Tool-use блок из anthropic response (model хочет вызвать функцию)."""
    id: str
    name: str
    input: dict[str, Any]


@dataclass(frozen=True)
class ClaudeToolResponse:
    """Результат call_claude_with_tools.

    Либо модель вернула текст (text заполнен, tool_use=None) — это
    уточняющий вопрос; либо модель запросила tool-call (tool_use заполнен).
    `stop_reason` — 'tool_use' если модель остановилась на tool-call.
    """
    text: str
    tool_use: ClaudeToolUse | None
    stop_reason: str | None
    usage: ClaudeUsage
    model: str
    duration_ms: int


async def call_claude_with_tools(
    messages: list[dict[str, Any]],
    system: str,
    tools: list[dict[str, Any]],
    model: str | None = None,
    max_tokens: int = 2048,
    *,
    max_retries: int = 3,
    initial_backoff_s: float = 1.0,
) -> ClaudeToolResponse:
    """Отправить запрос Claude с tool-use (function calling).

    Параметры:
    - `messages` — полная история диалога в anthropic-формате
      ([{role, content}], где content — str или список блоков).
    - `tools` — список tool-схем (anthropic JSON Schema формата:
      {name, description, input_schema}).

    Возвращает ClaudeToolResponse: если модель решила вызвать tool —
    `tool_use` заполнен (первый tool_use блок), иначе `text` (уточняющий
    вопрос). Retry-логика идентична call_claude.

    Бросает AINotConfiguredError если ключа нет, AIServiceError после retry.
    """
    client = _get_client()
    if client is None:
        raise AINotConfiguredError("ANTHROPIC_API_KEY not configured")

    settings = get_settings()
    effective_model = model or settings.anthropic_model
    started = time.perf_counter()

    try:
        from anthropic import (
            APIConnectionError,
            APIStatusError,
            AuthenticationError,
            RateLimitError,
        )
    except ImportError as e:
        raise AINotConfiguredError(f"anthropic SDK not installed: {e}") from e

    last_error: Exception | None = None
    for attempt in range(max_retries):
        try:
            response = await client.messages.create(
                model=effective_model,
                max_tokens=max_tokens,
                system=system,
                tools=tools,
                messages=messages,
            )
            duration_ms = int((time.perf_counter() - started) * 1000)
            return _build_tool_response(response, effective_model, duration_ms)
        except AuthenticationError as e:
            logger.warning("Anthropic auth error: %s", e)
            raise AINotConfiguredError(f"Anthropic auth failed: {e}") from e
        except (RateLimitError, APIConnectionError, APIStatusError) as e:
            last_error = e
            if attempt + 1 == max_retries:
                break
            backoff = initial_backoff_s * (2 ** attempt)
            await asyncio.sleep(backoff)
        except Exception as e:  # noqa: BLE001
            last_error = e
            break

    raise AIServiceError(
        f"Anthropic tool-call failed after {max_retries} retries: {last_error}"
    ) from last_error


def _build_tool_response(
    response: Any, model: str, duration_ms: int
) -> ClaudeToolResponse:
    """Разбирает anthropic Message с tool-use на text + первый tool_use блок."""
    content = getattr(response, "content", None) or []
    text_parts: list[str] = []
    tool_use: ClaudeToolUse | None = None
    for block in content:
        btype = getattr(block, "type", None) or (
            block.get("type") if isinstance(block, dict) else None
        )
        if btype == "text":
            t = getattr(block, "text", None) or (
                block.get("text") if isinstance(block, dict) else None
            )
            if isinstance(t, str):
                text_parts.append(t)
        elif btype == "tool_use" and tool_use is None:
            tu_id = getattr(block, "id", None) or (
                block.get("id") if isinstance(block, dict) else None
            )
            tu_name = getattr(block, "name", None) or (
                block.get("name") if isinstance(block, dict) else None
            )
            tu_input = getattr(block, "input", None)
            if tu_input is None and isinstance(block, dict):
                tu_input = block.get("input")
            tool_use = ClaudeToolUse(
                id=str(tu_id or ""),
                name=str(tu_name or ""),
                input=dict(tu_input or {}),
            )
    return ClaudeToolResponse(
        text="".join(text_parts).strip(),
        tool_use=tool_use,
        stop_reason=getattr(response, "stop_reason", None),
        usage=ClaudeUsage.from_anthropic_usage(response.usage),
        model=model,
        duration_ms=duration_ms,
    )


def _extract_text(response: Any) -> str:
    """Достаём текст из anthropic Message response.

    Response.content — список TextBlock'ов; в обычном messages-вызове там
    один блок типа `text`. Защитимся от пустого списка.
    """
    content = getattr(response, "content", None) or []
    if not content:
        return ""
    parts: list[str] = []
    for block in content:
        # Поддерживаем как объекты (TextBlock с .text), так и dict-ответы
        # (если кто-то подменит SDK на raw httpx).
        text_attr = getattr(block, "text", None)
        if isinstance(text_attr, str):
            parts.append(text_attr)
        elif isinstance(block, dict) and isinstance(block.get("text"), str):
            parts.append(block["text"])
    return "".join(parts).strip()


# ============ Streaming (зарезервировано под эпик 10.5) ============

async def call_claude_streaming(
    prompt: str,
    system: str,
    model: str | None = None,
    max_tokens: int = 4096,
):
    """Streaming-вариант для chat (эпик 10.5). В эпике 18 НЕ используется.

    Генератор: yield-ит куски текста по мере их прихода. usage недоступен
    пока stream не закроется (тогда последний chunk содержит final-usage).
    Возвращаем только текстовые куски — caller сам конкатенирует/собирает.

    Возвращает AsyncIterator[str].
    """
    client = _get_client()
    if client is None:
        raise AINotConfiguredError("ANTHROPIC_API_KEY not configured")
    settings = get_settings()
    effective_model = model or settings.anthropic_model

    async with client.messages.stream(
        model=effective_model,
        max_tokens=max_tokens,
        system=system,
        messages=[{"role": "user", "content": prompt}],
    ) as stream:
        async for text_chunk in stream.text_stream:
            yield text_chunk


# ============ JSON parsing helper ============

def parse_json_response(text: str) -> dict[str, Any]:
    """Robust парсер JSON-ответов LLM (caskad стратегий).

    Проблема: Claude иногда возвращает JSON с неэкранированными кавычками
    внутри string-значений (особенно когда в quote/explanation попадают
    русские тексты с цитатами «…» или нативными "). `json.loads` слишком
    строгий для таких случаев — падает с `Expecting ',' delimiter`.

    Стратегии (применяются по порядку, первая успешная — возвращает результат):

    1. Срезаем markdown код-блок (```json … ``` или ``` … ```) → пробуем
       прямой `json.loads` на оставшемся тексте.
    2. Прямой `json.loads` на тексте (если markdown-обёртки нет).
    3. Извлекаем подстроку от первой `{` до последней `}` — игнорируем
       вступление / прощание текстом → `json.loads`.
    4. Прогоняем text через `json_repair.repair_json` (лечит неэкранированные
       кавычки, trailing commas, single quotes, etc) → `json.loads` поверх.
    5. Все попытки провалились → AIResponseError с диагностикой (длина,
       первые 200 символов raw text).

    Bypass-ы между стратегиями делаем мягкими (no exception leakage) кроме
    финальной — она единственная бросает.

    Бросает:
    - AIResponseError если все 4 стратегии не дали валидный JSON ИЛИ
      если результат не dict (Claude вернул массив / число).
    """
    text = (text or "").strip()
    if not text:
        raise AIResponseError("Empty response text")

    # --- Strategy 1: strip markdown code fences ---
    cleaned = text
    if cleaned.startswith("```"):
        # Срезаем открывающий ```json / ```{lang} / просто ``` (с переносом).
        cleaned = re.sub(r"^```(?:[a-zA-Z0-9_-]+)?\s*\n?", "", cleaned)
        # Срезаем закрывающий ``` (с возможным переносом перед).
        cleaned = re.sub(r"\n?```\s*$", "", cleaned)
        cleaned = cleaned.strip()

    # --- Strategy 2: direct json.loads ---
    parsed = _try_json_loads(cleaned)
    if parsed is not None:
        return _ensure_dict(parsed)

    # --- Strategy 3: extract {...} substring (Claude может добавить prose
    # до/после JSON, особенно если забыл про "no markdown wrapper") ---
    start = cleaned.find("{")
    end = cleaned.rfind("}")
    if start >= 0 and end > start:
        candidate = cleaned[start:end + 1]
        parsed = _try_json_loads(candidate)
        if parsed is not None:
            return _ensure_dict(parsed)
        # Сохраним candidate для strategy 4 — он чище чем cleaned (без prose).
        repair_target = candidate
    else:
        repair_target = cleaned

    # --- Strategy 4: json_repair (лечит неэкранированные кавычки и т.п.) ---
    try:
        from json_repair import repair_json
    except ImportError:
        repair_json = None  # type: ignore[assignment]
        logger.warning("json_repair not installed — strategy 4 skipped")

    if repair_json is not None:
        try:
            repaired = repair_json(repair_target)
            if repaired and isinstance(repaired, str):
                parsed = _try_json_loads(repaired)
                if parsed is not None:
                    logger.info(
                        "parse_json_response: recovered via json_repair "
                        "(text length=%d)", len(text),
                    )
                    return _ensure_dict(parsed)
        except Exception as e:  # noqa: BLE001
            # repair_json сам кидать не должен — но защищаемся.
            logger.warning("json_repair raised %s: %s", type(e).__name__, e)

    # --- All strategies failed ---
    raise AIResponseError(
        f"Failed to parse JSON response after all strategies. "
        f"Text length: {len(text)}, starts with: {text[:200]!r}"
    )


def _try_json_loads(text: str) -> Any | None:
    """Пытается json.loads(text). None если JSONDecodeError. Иначе возвращает
    результат (любой тип — dict / list / str / int).
    """
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        return None


def _ensure_dict(parsed: Any) -> dict[str, Any]:
    """Гарантирует что parsed — dict. Иначе AIResponseError."""
    if not isinstance(parsed, dict):
        raise AIResponseError(
            f"Expected dict from AI, got {type(parsed).__name__}"
        )
    return parsed


# ============ Logging into ai_request_log ============

async def log_ai_request(
    session: AsyncSession,
    *,
    user_id: int | None,
    feature: str,
    entity_type: str | None = None,
    entity_id: int | None = None,
    status: str = "success",
    response: ClaudeResponse | None = None,
    error_message: str | None = None,
) -> AIRequestLog:
    """Записать AI-вызов в ai_request_log. НЕ коммитит — caller отвечает.

    status: 'success' | 'error' | 'not_configured' | 'rate_limited'.
    response: при status=='success' — извлекаем usage/model/duration.
    """
    row = AIRequestLog(
        user_id=user_id,
        feature=feature,
        entity_type=entity_type,
        entity_id=entity_id,
        status=status,
        error_message=error_message,
        prompt_tokens=response.usage.prompt_tokens if response else None,
        completion_tokens=response.usage.completion_tokens if response else None,
        total_tokens=response.usage.total_tokens if response else None,
        model=response.model if response else None,
        duration_ms=response.duration_ms if response else None,
    )
    session.add(row)
    await session.flush()
    return row


# ============ High-level helpers (используются роутерами) ============

CONTRACT_ANALYSIS_SYSTEM_PROMPT = """Ты — опытный юрист компании MACRO Global Technologies. \
Ты анализируешь сублицензионные договоры на программное обеспечение.

Твоя задача — найти в договоре:
1. Нестандартные пункты, риски, противоречия (issues с severity: error/warning/info).
2. Подтвердить наличие стандартных секций (standard_sections со status: ok/warning).
3. Дать общие рекомендации по улучшению (recommendations).

ВАЖНО: верни ТОЛЬКО валидный JSON без markdown-обёртки (без ```json и ```).
Все строковые значения должны иметь правильно экранированные кавычки. НЕ используй \
настоящие двойные кавычки внутри полей quote/explanation/suggestion — заменяй на \
ёлочки «…» или одинарные '…'. Любой перенос строки внутри значения — это \\n, \
а не настоящий перенос.

Формат ответа:
{
  "issues": [
    {
      "severity": "error" | "warning" | "info",
      "quote": "точная цитата из договора, по которой замечание",
      "explanation": "что не так и почему это риск",
      "suggestion": "конкретная рекомендация — как переписать"
    }
  ],
  "standard_sections": [
    {"section": "Предмет договора", "status": "ok"},
    {"section": "Срок действия", "status": "warning"}
  ],
  "recommendations": [
    "Краткие текстовые рекомендации общего характера"
  ]
}

severity:
- error — критичный риск (потеря денег, юр. незащищённость)
- warning — отклонение от стандартного подхода
- info — для сведения, не блокер

Если договор выглядит чисто — возвращай пустой массив issues."""


DEAL_PREFILL_SYSTEM_PROMPT = """Ты — sales-аналитик компании MACRO Global Technologies. \
Анализируешь переписку, заметки и звонки с клиентом, чтобы извлечь BANT-данные \
для CRM-сделки.

Твоя задача — предложить значения полей сделки на основе предоставленного \
контекста. Каждое предложение должно содержать confidence (high/medium/low) \
и reasoning — короткое объяснение откуда ты это взял.

ВАЖНО: верни ТОЛЬКО валидный JSON без markdown-обёртки (без ```json и ```).
Все строковые значения должны иметь правильно экранированные кавычки. НЕ используй \
настоящие двойные кавычки внутри полей reasoning/source_text — заменяй на \
ёлочки «…» или одинарные '…'. Любой перенос строки внутри значения — \\n.

Формат ответа:
{
  "summary": "1-2 предложения краткого резюме клиента",
  "suggestions": [
    {
      "field": "qualification_score" | "expected_close_date" | "amount" | "currency" | "description" | "contact_name" | "country" | "company_name",
      "label": "Человеко-читаемый RU label поля",
      "suggested_value": <число, строка, или дата YYYY-MM-DD>,
      "confidence": "high" | "medium" | "low",
      "reasoning": "Откуда ты это взял (цитата или короткое объяснение)"
    }
  ]
}

confidence:
- high — клиент явно сказал, есть прямая цитата
- medium — подразумевается из контекста или нескольких фраз
- low — догадка по косвенным признакам, лучше уточнить

Если в контексте недостаточно данных — возвращай suggestions: []."""


__all__ = [
    "AINotConfiguredError",
    "AIServiceError",
    "AIResponseError",
    "ClaudeResponse",
    "ClaudeUsage",
    "ClaudeToolUse",
    "ClaudeToolResponse",
    "AI_FEATURES",
    "CONTRACT_ANALYSIS_SYSTEM_PROMPT",
    "DEAL_PREFILL_SYSTEM_PROMPT",
    "call_claude",
    "call_claude_with_tools",
    "call_claude_streaming",
    "parse_json_response",
    "log_ai_request",
    "reset_client_for_tests",
]
