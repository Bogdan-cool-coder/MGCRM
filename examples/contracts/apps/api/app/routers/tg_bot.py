"""Эпик 24.3 — TG Bot API endpoints.

POST /api/tg-bot/intent  — NL-парсинг + исполнение команды бота.

Эндпоинт не требует cookie-auth (вызывается из бот-сервиса, не из браузера).
Защита (FAIL-CLOSED): Bearer-токен из settings.tg_bot_api_secret.
Если секрет НЕ задан → 503 для всех (эндпоинт интернет-доступен через
Traefik, body несёт attacker-chosen tg_user_id — fail-open был бы
неаутентифицированным impersonation). Задать TG_BOT_API_SECRET в проде.

Response включает:
  - reply_text   — текст ответа для Telegram
  - log_id       — ID записи TGIntentLog
  - inline_keyboard — опциональные inline-кнопки для Telegram
"""

from __future__ import annotations

import hmac
import logging
import time
from typing import Annotated, Any

from fastapi import APIRouter, Depends, Header, HTTPException, status
from pydantic import BaseModel, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.db import get_session
from app.models import TGIntentLog, User
from app.services.auth_rate_limit import check_tg_intent_rate_limit
from app.services.tg_intent import build_intent_context, parse_intent
from app.services.tg_intent_executor import execute_intent

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/api/tg-bot", tags=["tg-bot"])

settings = get_settings()


# ============ Auth guard ============

async def verify_bot_token(authorization: Annotated[str | None, Header()] = None) -> None:
    """Проверяет Bearer-токен бота. FAIL-CLOSED.

    P0 Security (A1/C6): эндпоинт интернет-доступен (Traefik PathPrefix /api),
    а body несёт attacker-chosen tg_user_id → раньше при пустом секрете мы
    fail-OPEN пропускали ВСЕХ = неаутентифицированный impersonation.

    Теперь:
    - Секрет НЕ задан → 503 для ВСЕХ запросов (никогда не allow).
    - Секрет задан → требуем точный Bearer-match (constant-time compare).
    """
    # Читаем секрет из get_settings() каждый раз (а не из модульного settings),
    # чтобы тесты могли override через monkeypatch.
    secret = get_settings().tg_bot_api_secret
    if not secret:
        # FAIL-CLOSED: без секрета не существует способа аутентифицировать
        # бота — отклоняем всё, а не пропускаем.
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="TG bot integration not configured",
        )
    if not authorization or not authorization.startswith("Bearer "):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Bot API token required",
        )
    token = authorization.removeprefix("Bearer ").strip()
    # constant-time compare против timing-атаки на угадывание секрета.
    if not hmac.compare_digest(token, secret):
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Invalid bot API token",
        )


# ============ Schemas ============

class IntentRequest(BaseModel):
    tg_user_id: int = Field(..., description="Telegram user ID (from_user.id)")
    tg_chat_id: int = Field(..., description="Telegram chat ID (chat.id)")
    text: str = Field(..., min_length=1, max_length=4096, description="Text message from user")


class InlineButton(BaseModel):
    text: str
    callback_data: str | None = None
    url: str | None = None


class IntentResponse(BaseModel):
    reply_text: str
    inline_keyboard: list[list[InlineButton]] | None = None
    log_id: int | None = None


# ============ Endpoint ============

@router.post("/intent", response_model=IntentResponse)
async def handle_intent(
    body: IntentRequest,
    session: Annotated[AsyncSession, Depends(get_session)],
    _: Annotated[None, Depends(verify_bot_token)],
) -> IntentResponse:
    """Принять текстовое сообщение из Telegram-бота и выполнить NL-команду.

    Шаги:
    1. Lookup user по tg_user_id → User.telegram_user_id
    2. Если не найден → reply «Привяжите аккаунт через /whoami»
    3. parse_intent() → IntentResult (Claude Sonnet)
    4. Log в TGIntentLog (pending)
    5. execute_intent() → ExecutionResult
    6. commit() — включает созданные задачи
    7. Обновить log, вернуть ответ
    """
    tg_user_id = body.tg_user_id
    tg_chat_id = body.tg_chat_id
    text = body.text.strip()

    # P0 Security: per-tg_user кап (denial-of-wallet на платные Claude-вызовы +
    # анти-спам). 30/мин; при превышении — 429 без обращения к Claude.
    rl_ok, rl_retry = await check_tg_intent_rate_limit(tg_user_id)
    if not rl_ok:
        raise HTTPException(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            detail="Слишком много запросов. Попробуйте через минуту.",
            headers={"Retry-After": str(rl_retry)},
        )

    started_total = time.perf_counter()

    # 1. Lookup user
    user_result = await session.execute(
        select(User).where(User.telegram_user_id == tg_user_id)
    )
    user = user_result.scalar_one_or_none()
    if not user:
        log = TGIntentLog(
            tg_user_id=tg_user_id,
            tg_chat_id=tg_chat_id,
            raw_message=text,
            intent=None,
            status="processed",
            result_action_taken="user_not_linked",
        )
        session.add(log)
        await session.commit()
        return IntentResponse(
            reply_text=(
                "Ваш Telegram не привязан к учётной записи MACRO CRM.\n\n"
                "Войдите на сайт → Профиль → «Привязать Telegram»."
            ),
            log_id=log.id,
        )

    # 2. Build context (последние задачи для disambiguation)
    ctx = await build_intent_context(session, user)

    # 3. Parse intent via Claude
    intent_result = await parse_intent(text=text, user_id=user.id, context=ctx)

    # 4. Создаём лог (flush для ID)
    log = TGIntentLog(
        user_id=user.id,
        tg_user_id=tg_user_id,
        tg_chat_id=tg_chat_id,
        raw_message=text,
        intent=intent_result.intent,
        intent_confidence=round(intent_result.confidence, 2) if intent_result.confidence else None,
        parsed_params=intent_result.params or None,
        claude_response_text=intent_result.raw_response or None,
        claude_tokens_used=intent_result.tokens_used or None,
        duration_ms=intent_result.duration_ms or None,
        status="processed",
    )
    session.add(log)
    await session.flush()

    # 5. Execute intent
    try:
        exec_result = await execute_intent(
            session=session,
            intent_result=intent_result,
            user=user,
        )
    except Exception as exc:  # noqa: BLE001
        logger.exception("execute_intent failed for tg_user=%s", tg_user_id)
        log.status = "failed"
        log.error_message = str(exc)[:1000]
        await session.commit()
        return IntentResponse(
            reply_text="Произошла ошибка при выполнении команды. Попробуйте позже.",
            log_id=log.id,
        )

    # 6. Обновляем лог результатом
    log.result_action_taken = exec_result.action_taken[:64] if exec_result.action_taken else None
    if not exec_result.success and exec_result.action_taken not in (
        "unknown_intent", "low_confidence",
    ):
        log.status = "action_required"
    total_ms = int((time.perf_counter() - started_total) * 1000)
    if log.duration_ms is None:
        log.duration_ms = total_ms

    await session.commit()

    # 7. Inline keyboard
    inline_keyboard = _build_inline_keyboard(intent_result.intent, exec_result)

    return IntentResponse(
        reply_text=exec_result.message,
        inline_keyboard=inline_keyboard or None,
        log_id=log.id,
    )


# ============ Inline keyboard builder ============

def _build_inline_keyboard(
    intent: str,
    exec_result: Any,
) -> list[list[InlineButton]] | None:
    """Строит inline keyboard для подтверждения действий.

    Для create_task / close_task добавляем кнопки после успешного выполнения.
    Для search_tasks — кнопку «Открыть в CRM» если есть data.
    """
    if not exec_result.success:
        return None

    public_url = settings.public_base_url

    if intent == "search_tasks":
        tasks = exec_result.data or []
        if tasks and isinstance(tasks, list):
            url = f"{public_url}/activities?kind=task"
            return [[InlineButton(text="Открыть в CRM", url=url)]]
        return None

    if intent == "create_task":
        data = exec_result.data or {}
        act_id = data.get("activity_id")
        if act_id:
            url = f"{public_url}/activities/{act_id}"
            return [[InlineButton(text="Открыть задачу", url=url)]]
        return None

    if intent == "close_task":
        data = exec_result.data or {}
        act_id = data.get("activity_id")
        if act_id:
            url = f"{public_url}/activities/{act_id}"
            return [[InlineButton(text="Открыть в CRM", url=url)]]
        return None

    return None
