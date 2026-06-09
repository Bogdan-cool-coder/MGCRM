"""Epic 10.5 — AI Assistant Chat + Cold Call Trainer.

Endpoints:
- POST /api/ai/chat — SSE streaming chat с Claude Sonnet
- POST /api/ai/cold-call-trainer/start — начать сессию холодного звонка
- POST /api/ai/cold-call-trainer/{session_id}/message — ответить в сессии
- POST /api/ai/cold-call-trainer/{session_id}/end — завершить и получить оценку
- POST /api/counterparties/{id}/ai-analyze — AI-анализ контрагента (Haiku, кэш 24h)
"""
from __future__ import annotations

import json
import logging
import time
from datetime import UTC, datetime
from typing import Any, AsyncGenerator, Annotated

from fastapi import APIRouter, Depends, HTTPException, Request, status
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field
from sqlalchemy import or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser
from app.models import Company, Counterparty, Pipeline, User
from app.services.access_control import ensure_object_visible
from app.services.auth_rate_limit import check_ai_user_rate_limit
from app.services.ai_assistant import (
    ASSISTANT_SYSTEM_PROMPT,
    TOOL_SCHEMAS,
    AssistantArgError,
    build_proposed_action,
    missing_required,
    validate_and_normalize_args,
)
from app.services.anthropic_client import (
    AINotConfiguredError,
    AIServiceError,
    call_claude,
    call_claude_streaming,
    call_claude_with_tools,
    parse_json_response,
)

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/ai", tags=["ai-chat"])
counterparty_ai_router = APIRouter(tags=["counterparty-ai"])


async def _enforce_ai_rate_limit(user_id: int, bucket: str) -> None:
    """P0 Security: per-user кап на платные Claude-вызовы (denial-of-wallet).
    20/мин на bucket; при превышении — 429. Raise HTTPException."""
    ok, retry = await check_ai_user_rate_limit(user_id, bucket)
    if not ok:
        raise HTTPException(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            detail="Слишком много AI-запросов. Попробуйте через минуту.",
            headers={"Retry-After": str(retry)},
        )

# In-memory хранилище сессий cold-call тренера (MVP — не persistent)
_cold_call_sessions: dict[str, dict[str, Any]] = {}
_SESSION_TTL_SECONDS = 3600  # 1 час

# Кэш AI-анализа контрагентов (MVP — in-memory, 24h TTL)
_counterparty_cache: dict[int, dict[str, Any]] = {}
_CACHE_TTL_SECONDS = 86400  # 24 часа


# ============ Schemas ============

class ChatMessageIn(BaseModel):
    message: str = Field(..., min_length=1, max_length=4000)
    session_id: str | None = None
    context: dict[str, Any] | None = None


class ColdCallStartIn(BaseModel):
    scenario: str = Field(..., min_length=5, max_length=500)


class ColdCallStartOut(BaseModel):
    session_id: str
    opening_line: str
    scenario_brief: str


class ColdCallMessageIn(BaseModel):
    message: str = Field(..., min_length=1, max_length=2000)


class ColdCallMessageOut(BaseModel):
    response: str
    hints: list[str] | None = None


class ColdCallScore(BaseModel):
    clarity: float
    empathy: float
    objection_handling: float
    closing: float


class ColdCallEndOut(BaseModel):
    scores: ColdCallScore
    total: float
    feedback: str


class CounterpartyAnalysisOut(BaseModel):
    summary: str
    risks: list[str]
    recommendations: list[str]
    priority_score: float
    model: str
    analyzed_at: str


# ============ System prompts ============

CHAT_SYSTEM_PROMPT = """Ты — AI-ассистент менеджера MACRO CRM. Помогаешь со сделками, аналитикой, продажами.

Ты работаешь в системе MACRO CRM — CRM для команды продаж компании MACRO Global Technologies.
Отвечай кратко и по делу. Если нужно — задавай уточняющие вопросы.
Пиши на русском языке. Используй конкретные факты и числа где возможно.

Ты можешь помочь с:
- Анализом сделок и контрагентов
- Советами по продажам и переговорам
- Подготовкой к встречам (FTM)
- Интерпретацией метрик и KPI
- Составлением email/сообщений клиентам"""

COLD_CALL_SYSTEM_PROMPT = """Ты — строгий тренер по холодным звонкам. Ты симулируешь потенциального клиента.

Сценарий: {scenario}

Правила:
- Ты — скептически настроенный руководитель/директор, занятый человек
- Отвечай как реальный клиент: иногда равнодушно, иногда с возражениями
- НЕ помогай менеджеру — ты клиент, а не тренер
- Отвечай короткими фразами (1-3 предложения)
- Если менеджер говорит убедительно — постепенно открывайся
- Если неубедительно — будь холодным или завершай разговор"""

COLD_CALL_EVALUATOR_PROMPT = """Ты — эксперт по продажам. Оцени разговор менеджера с клиентом.

История разговора:
{history}

Оцени по 4 критериям (каждый 0.0 до 10.0):
1. clarity — ясность и структура презентации
2. empathy — умение слушать и понимать клиента
3. objection_handling — работа с возражениями
4. closing — попытка закрыть сделку или получить следующий шаг

Ответь ТОЛЬКО JSON (без markdown):
{{"scores": {{"clarity": 7.5, "empathy": 6.0, "objection_handling": 8.0, "closing": 5.5}}, "total": 6.75, "feedback": "Краткий feedback (2-3 предложения)"}}"""

COUNTERPARTY_ANALYSIS_PROMPT = """Проанализируй контрагента и дай структурированную оценку.

Данные контрагента:
{counterparty_data}

Ответь ТОЛЬКО JSON:
{{
  "summary": "2-3 предложения о клиенте и его бизнесе",
  "risks": ["риск 1", "риск 2"],
  "recommendations": ["рекомендация 1", "рекомендация 2"],
  "priority_score": 7.5
}}

priority_score: 0-10, где 10 = высший приоритет для продажи."""


# ============ Chat endpoint ============

@router.post("/chat")
async def ai_chat(
    body: ChatMessageIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> StreamingResponse:
    """SSE streaming chat с Claude."""
    # P0 denial-of-wallet: кап на платные Claude-вызовы ДО открытия SSE-потока,
    # чтобы 429 вернулся как обычная HTTP-ошибка, а не зарытый в data:-кадр.
    await _enforce_ai_rate_limit(current_user.id, "chat")

    async def generate() -> AsyncGenerator[str, None]:
        try:
            context_str = ""
            if body.context:
                context_str = f"\n\nКонтекст: {json.dumps(body.context, ensure_ascii=False)}"

            prompt = body.message + context_str

            async for chunk in call_claude_streaming(
                prompt=prompt,
                system=CHAT_SYSTEM_PROMPT,
                max_tokens=2048,
            ):
                yield f"data: {json.dumps({'text': chunk})}\n\n"

            yield "data: [DONE]\n\n"

        except AINotConfiguredError:
            yield f"data: {json.dumps({'error': 'AI not configured. Set ANTHROPIC_API_KEY.'})}\n\n"
        except AIServiceError as e:
            yield f"data: {json.dumps({'error': f'AI service error: {e}'})}\n\n"
        except Exception as e:  # noqa: BLE001
            logger.error("AI chat error: %s", e)
            yield f"data: {json.dumps({'error': 'Internal error'})}\n\n"

    return StreamingResponse(
        generate(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "X-Accel-Buffering": "no",
        },
    )


# ============ Cold Call Trainer (DEPRECATED) ============
#
# B8: эти эндпоинты хранили сессию тренажёра в in-memory словаре
# (`_cold_call_sessions`). В проде api работает в scale=2 за round-robin
# балансировщиком — start попадает на реплику A, а следующий message/end может
# уйти на реплику B, где сессии нет → 404 «Session not found». Это делало старый
# тренажёр нерабочим под нагрузкой нескольких реплик.
#
# Тренажёр переписан на DB-backed путь `/api/me/training/*` (роутер
# app/routers/me_training.py, модель CallTrainingSession) — он персистит сессию
# в Postgres и работает на любой реплике. Фронт (apps/web/.../me/training)
# использует ТОЛЬКО новый путь. Старые эндпоинты ниже отвечают 410 Gone, чтобы
# любой забытый клиент получил явный сигнал, а не молчаливый 404 при балансировке.
#
# `_cold_call_sessions`, `COLD_CALL_*` промпты и схемы `ColdCall*` оставлены как
# модульные символы (на них опираются юнит-тесты валидации промптов/схем).

_COLD_CALL_GONE_DETAIL = (
    "Тренажёр холодных звонков переехал на /api/me/training/* "
    "(персистентные сессии). Старый /ai/cold-call-trainer/* отключён."
)


def _cleanup_old_sessions() -> None:
    """Совместимость: чистка просроченных in-memory сессий старого тренажёра.

    Старый путь отключён (410), но функция сохранена — на неё ссылаются тесты.
    Никем в проде больше не вызывается.
    """
    now = time.time()
    expired = [
        sid
        for sid, s in _cold_call_sessions.items()
        if now - s["created_at"] > _SESSION_TTL_SECONDS
    ]
    for sid in expired:
        del _cold_call_sessions[sid]


@router.post("/cold-call-trainer/start", response_model=ColdCallStartOut)
async def cold_call_start(
    body: ColdCallStartIn,
    current_user: CurrentUser,
) -> ColdCallStartOut:
    """DEPRECATED. Переехало на POST /api/me/training/sessions. Отвечает 410 Gone."""
    raise HTTPException(status_code=status.HTTP_410_GONE, detail=_COLD_CALL_GONE_DETAIL)


@router.post(
    "/cold-call-trainer/{session_id}/message",
    response_model=ColdCallMessageOut,
)
async def cold_call_message(
    session_id: str,
    body: ColdCallMessageIn,
    current_user: CurrentUser,
) -> ColdCallMessageOut:
    """DEPRECATED. Переехало на /api/me/training/sessions/{id}/message. 410 Gone."""
    raise HTTPException(status_code=status.HTTP_410_GONE, detail=_COLD_CALL_GONE_DETAIL)


@router.post(
    "/cold-call-trainer/{session_id}/end",
    response_model=ColdCallEndOut,
)
async def cold_call_end(
    session_id: str,
    current_user: CurrentUser,
) -> ColdCallEndOut:
    """DEPRECATED. Переехало на /api/me/training/sessions/{id}/finish. 410 Gone."""
    raise HTTPException(status_code=status.HTTP_410_GONE, detail=_COLD_CALL_GONE_DETAIL)


# ============ Counterparty AI analysis ============

@counterparty_ai_router.post(
    "/counterparties/{counterparty_id}/ai-analyze",
    response_model=CounterpartyAnalysisOut,
)
async def ai_analyze_counterparty(
    counterparty_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    force_refresh: bool = False,
) -> CounterpartyAnalysisOut:
    """AI-анализ контрагента (Claude Haiku, кэш 24h)."""
    # P0 object-level auth (горизонтальное разграничение): загрузка + visibility
    # ДО чтения кэша. Иначе менеджер мог бы получить закэшированный анализ чужого
    # контрагента, не имея доступа к самой записи. ensure_object_visible бросает
    # 404 на чужой объект (как и при "не найдено") — существование не палим.
    cp = (
        await session.execute(
            select(Counterparty).where(Counterparty.id == counterparty_id)
        )
    ).scalar_one_or_none()
    if cp is None:
        raise HTTPException(status_code=404, detail="Counterparty not found")
    await ensure_object_visible(session, cp, "counterparty", current_user)

    if not force_refresh and counterparty_id in _counterparty_cache:
        cached = _counterparty_cache[counterparty_id]
        if time.time() - cached["analyzed_at"] < _CACHE_TTL_SECONDS:
            return CounterpartyAnalysisOut(**cached["result"])

    cp_data = {
        "name": cp.name,
        "full_legal_form": cp.full_legal_form,
        "country_code": cp.country_code,
        "city": cp.city,
        "category_code": cp.category_code,
        "turnover_rub": float(cp.turnover_rub) if cp.turnover_rub else None,
        "notes": cp.notes,
        "phone": cp.phone,
        "email": cp.email,
        "website": cp.website,
    }

    prompt = COUNTERPARTY_ANALYSIS_PROMPT.format(
        counterparty_data=json.dumps(cp_data, ensure_ascii=False, indent=2)
    )

    try:
        response = await call_claude(
            prompt=prompt,
            system="Ты — бизнес-аналитик MACRO CRM. Отвечай строго в JSON.",
            model="claude-haiku-4-5",
            max_tokens=1024,
        )
        parsed = parse_json_response(response.text)
        used_model = response.model
    except AINotConfiguredError:
        raise HTTPException(status_code=503, detail="AI not configured")
    except (AIServiceError, Exception) as e:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=f"AI error: {e}")

    analyzed_at = datetime.now(tz=UTC).isoformat()
    result = CounterpartyAnalysisOut(
        summary=str(parsed.get("summary", "")),
        risks=list(parsed.get("risks", [])),
        recommendations=list(parsed.get("recommendations", [])),
        priority_score=float(parsed.get("priority_score", 5.0)),
        model=used_model,
        analyzed_at=analyzed_at,
    )

    _counterparty_cache[counterparty_id] = {
        "result": result.model_dump(),
        "analyzed_at": time.time(),
    }

    return result


# ============ AI Assistant actions (propose → confirm) ============
#
# Ассистент умеет СОЗДАВАТЬ задачу / сделку / черновик договора через диалог.
# Безопасность: модель НЕ исполняет действие сама — она ПРЕДЛАГАЕТ (proposed_action),
# менеджер подтверждает вторым запросом (/confirm). Логика tool-схем и валидации —
# в app.services.ai_assistant (чистая, тестируемая). Здесь — оркестрация + БД-резолв
# + переиспользование существующих create-эндпоинтов.


class AssistantMessageIn(BaseModel):
    message: str = Field(..., min_length=1, max_length=4000)
    # История диалога в anthropic-формате [{role, content}]. Опционально —
    # фронт может слать предыдущие реплики для контекста сбора полей.
    history: list[dict[str, Any]] | None = None


class ProposedAction(BaseModel):
    type: str
    args: dict[str, Any]
    summary: str
    title: str


class AssistantMessageOut(BaseModel):
    # Либо ассистент задаёт уточняющий вопрос (assistant_text), либо предлагает
    # действие (proposed_action). Оба одновременно непустыми не бывают, но фронт
    # должен отдавать приоритет proposed_action.
    assistant_text: str | None = None
    proposed_action: ProposedAction | None = None


class AssistantConfirmIn(BaseModel):
    type: str
    args: dict[str, Any]


class AssistantConfirmOut(BaseModel):
    entity_type: str  # deal | activity | contract
    entity_id: int
    link: str
    message: str


ASSISTANT_MODEL = "claude-sonnet-4-5"


@router.post("/assistant/message", response_model=AssistantMessageOut)
async def assistant_message(
    body: AssistantMessageIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> AssistantMessageOut:
    """Шаг 1 (propose): модель решает — спросить уточнение или предложить действие.

    Возвращает либо assistant_text (вопрос), либо proposed_action (превью). НИЧЕГО
    не создаёт. 503 если ANTHROPIC_API_KEY не задан.
    """
    await _enforce_ai_rate_limit(current_user.id, "assistant")
    messages: list[dict[str, Any]] = list(body.history or [])
    messages.append({"role": "user", "content": body.message})

    try:
        resp = await call_claude_with_tools(
            messages=messages,
            system=ASSISTANT_SYSTEM_PROMPT,
            tools=TOOL_SCHEMAS,
            model=ASSISTANT_MODEL,
        )
    except AINotConfiguredError:
        raise HTTPException(
            status_code=503,
            detail="AI-ассистент недоступен: не настроен ключ ANTHROPIC_API_KEY",
        )
    except AIServiceError as e:
        raise HTTPException(status_code=502, detail=f"Ошибка AI-сервиса: {e}")

    # Модель не запросила tool — это уточняющий вопрос / обычный ответ.
    if resp.tool_use is None:
        return AssistantMessageOut(assistant_text=resp.text or "")

    action_type = resp.tool_use.name
    try:
        normalized = validate_and_normalize_args(action_type, resp.tool_use.input)
    except AssistantArgError as e:
        # Модель прислала кривые аргументы — просим уточнить.
        return AssistantMessageOut(
            assistant_text=f"Уточните, пожалуйста: {e}",
        )

    missing = missing_required(action_type, normalized)
    if missing:
        # Не хватает обязательных полей — продолжаем диалог. Если модель добавила
        # текст рядом с tool-call, отдаём его; иначе формируем подсказку.
        hint = resp.text or (
            f"Чтобы продолжить, уточните: {', '.join(missing)}"
        )
        return AssistantMessageOut(assistant_text=hint)

    proposed = build_proposed_action(action_type, normalized)
    return AssistantMessageOut(proposed_action=ProposedAction(**proposed))


async def _resolve_company_id_by_name(
    session: AsyncSession, name: str
) -> int | None:
    """Найти Company по обиходному/юридическому/короткому названию (ilike).

    Возвращает id первой совпавшей компании или None. Используется в confirm,
    когда ассистент собрал company_name, но не company_id.
    """
    pattern = f"%{name.strip()}%"
    row = (await session.execute(
        select(Company.id).where(
            or_(
                Company.name.ilike(pattern),
                Company.legal_name.ilike(pattern),
                Company.short_name.ilike(pattern),
            )
        ).limit(1)
    )).scalar_one_or_none()
    return row


async def _default_sales_pipeline_id(session: AsyncSession) -> int | None:
    row = (await session.execute(
        select(Pipeline.id).where(
            Pipeline.kind == "sales", Pipeline.is_active.is_(True)
        ).order_by(Pipeline.sort_order).limit(1)
    )).scalar_one_or_none()
    return row


@router.post("/assistant/confirm", response_model=AssistantConfirmOut)
async def assistant_confirm(
    body: AssistantConfirmIn,
    current_user: CurrentUser,
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> AssistantConfirmOut:
    """Шаг 2 (confirm): фактически создаёт сущность, переиспользуя существующие
    create-эндпоинты (та же валидация и бизнес-правила что в обычном UI).

    Действия выполняются ОТ ИМЕНИ current_user (owner/responsible по умолчанию —
    он). 503 если ключ не настроен на шаге message (здесь ключ не нужен — мы уже
    не зовём Claude). Возвращает {entity_type, entity_id, link}.
    """
    # Повторная нормализация (defense-in-depth: клиент мог изменить args).
    try:
        args = validate_and_normalize_args(body.type, dict(body.args or {}))
    except AssistantArgError as e:
        raise HTTPException(status_code=400, detail=str(e))
    missing = missing_required(body.type, args)
    if missing:
        raise HTTPException(
            status_code=400,
            detail=f"Не хватает обязательных полей: {', '.join(missing)}",
        )

    if body.type == "create_task":
        return await _confirm_create_task(session, current_user, args)
    if body.type == "create_deal":
        return await _confirm_create_deal(session, current_user, args)
    if body.type == "create_contract":
        return await _confirm_create_contract(session, current_user, request, args)
    raise HTTPException(status_code=400, detail=f"Неизвестное действие: {body.type}")


async def _confirm_create_task(
    session: AsyncSession, current_user: User, args: dict[str, Any]
) -> AssistantConfirmOut:
    from datetime import datetime as _dt

    from app.routers.activities import create_activity
    from app.schemas import ActivityCreate

    due_at = None
    if args.get("due_at"):
        try:
            due_at = _dt.fromisoformat(str(args["due_at"]).replace("Z", "+00:00"))
        except ValueError:
            raise HTTPException(400, "due_at должен быть в формате ISO 8601")

    payload = ActivityCreate(
        kind=args["kind"],
        title=args["title"],
        body=args.get("description"),
        due_at=due_at,
        responsible_id=args.get("responsible_id") or current_user.id,
        target_type=args.get("target_type"),
        target_id=args.get("target_id"),
    )
    out = await create_activity(payload, current_user, session)
    return AssistantConfirmOut(
        entity_type="activity",
        entity_id=out.id,
        link=f"/tasks/{out.id}",
        message=f"Задача «{out.title}» создана",
    )


async def _confirm_create_deal(
    session: AsyncSession, current_user: User, args: dict[str, Any]
) -> AssistantConfirmOut:
    from app.routers.deals import create_deal
    from app.schemas import DealIn

    company_id = args.get("company_id")
    if company_id is None and args.get("company_name"):
        company_id = await _resolve_company_id_by_name(session, args["company_name"])
        if company_id is None:
            raise HTTPException(
                404,
                f"Компания «{args['company_name']}» не найдена. "
                "Уточните название или создайте компанию.",
            )

    pipeline_id = args.get("pipeline_id") or await _default_sales_pipeline_id(session)
    if pipeline_id is None:
        raise HTTPException(404, "Воронка продаж не найдена")

    payload = DealIn(
        pipeline_id=pipeline_id,
        title=args["title"],
        company_id=company_id,
        amount=args.get("amount"),
        currency=args.get("currency"),
        product=args.get("product"),
        owner_user_id=args.get("owner_user_id") or current_user.id,
    )
    out = await create_deal(payload, current_user, session)
    return AssistantConfirmOut(
        entity_type="deal",
        entity_id=out.id,
        link=f"/deals/{out.id}",
        message=f"Сделка «{out.title}» создана",
    )


async def _confirm_create_contract(
    session: AsyncSession,
    current_user: User,
    request: Request,
    args: dict[str, Any],
) -> AssistantConfirmOut:
    from app.routers.contracts import create_contract
    from app.schemas import ContractIn

    company_id = args.get("company_id")
    if company_id is None and args.get("company_name"):
        company_id = await _resolve_company_id_by_name(session, args["company_name"])
        if company_id is None:
            raise HTTPException(
                404,
                f"Компания «{args['company_name']}» не найдена. "
                "Уточните название или создайте компанию.",
            )

    payload = ContractIn(
        product_code=args["product_code"],
        country_code=args["country_code"],
        city=args["city"],
        company_id=company_id,
    )
    out = await create_contract(payload, current_user, request, session)
    return AssistantConfirmOut(
        entity_type="contract",
        entity_id=out.id,
        link=f"/contracts/{out.id}",
        message="Черновик договора создан",
    )
