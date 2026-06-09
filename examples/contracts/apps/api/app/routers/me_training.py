"""Task 8 — Call Trainer endpoints /api/me/training/*.

Тренажёр холодных звонков для отдела продаж. AI (Claude Sonnet) играет роль
клиента по выбранному сценарию; на финише EVALUATOR-промпт выставляет оценку.
Сессии персистятся в `call_training_sessions` (source-of-truth), а не в
in-memory dict — результаты сохраняются и доступны в истории.

Контракт строго совпадает с фронтом (apps/web/.../Me/training):
- POST /me/training/sessions          {scenario_type, company_type, company_name}
                                       → {id, opening_line, scenario_brief}
- POST /me/training/sessions/{id}/message  {content} → {content, hints?}
- POST /me/training/sessions/{id}/finish   → {score, scores, feedback}
- GET  /me/training/sessions          → [TrainingSessionOut]
- GET  /me/training/sessions/{id}     → TrainingSessionDetailOut (+ transcript, scorecard)

Доступ — только отдел продаж (см. call_trainer.can_use_trainer). Если
ANTHROPIC_API_KEY не задан — 503 с RU-сообщением.
"""
from __future__ import annotations

from datetime import UTC, datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser
from app.models import CallTrainingSession, Department, User
from app.services.auth_rate_limit import check_ai_user_rate_limit
from app.services.anthropic_client import (
    AINotConfiguredError,
    AIServiceError,
    call_claude,
    parse_json_response,
)
from app.services.call_trainer import (
    CRITERIA_KEYS,
    build_client_system_prompt,
    build_evaluator_prompt,
    can_use_trainer,
    fallback_scorecard,
    parse_scorecard,
    scenario_brief,
    transcript_to_text,
)

router = APIRouter(prefix="/me/training", tags=["me-training"])

_AI_NOT_CONFIGURED_MSG = "ИИ-тренажёр не настроен (нет ANTHROPIC_API_KEY)"


# ============ Schemas ============


class SessionStartIn(BaseModel):
    scenario_type: str = Field(..., min_length=2, max_length=40)
    company_type: str = Field(..., min_length=1, max_length=80)
    company_name: str | None = Field(default=None, max_length=255)


class SessionStartOut(BaseModel):
    id: int
    opening_line: str
    scenario_brief: str


class MessageIn(BaseModel):
    content: str = Field(..., min_length=1, max_length=2000)


class MessageOut(BaseModel):
    content: str
    hints: list[str] | None = None


class ScoresOut(BaseModel):
    speech_clarity: float
    empathy: float
    objection_handling: float
    deal_closing: float


class FinishOut(BaseModel):
    score: float
    scores: ScoresOut
    feedback: str
    recommendations: list[str] = []
    good_decisions: list[str] = []


class TranscriptMessage(BaseModel):
    role: str
    content: str
    ts: str | None = None


class SessionListItem(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    scenario_type: str
    company_type: str
    company_name: str | None
    status: str
    score: float | None
    created_at: datetime
    finished_at: datetime | None


class SessionDetailOut(SessionListItem):
    transcript: list[TranscriptMessage]
    scores: ScoresOut | None
    recommendations: list[str]
    good_decisions: list[str]
    feedback: str


# ============ Access guard ============


async def _ensure_sales_access(session: AsyncSession, user: User) -> None:
    """403 если пользователь не из отдела продаж.

    Sales department определяем по имени (Department.name LIKE 'Отдел продаж' /
    'ОП' / 'Sales'). Если не нашли — fallback на ролевую проверку внутри
    can_use_trainer (manager/director/admin).
    """
    sales_dept_id: int | None = None
    rows = (
        await session.execute(
            select(Department.id, Department.name)
        )
    ).all()
    for dep_id, name in rows:
        low = (name or "").lower()
        if "продаж" in low or low.strip() in {"оп", "sales"}:
            sales_dept_id = dep_id
            break

    if not can_use_trainer(user.role, user.department_id, sales_dept_id):
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Тренажёр звонков доступен только отделу продаж",
        )


async def _load_own_session(
    session: AsyncSession, session_id: int, user: User
) -> CallTrainingSession:
    obj = (
        await session.execute(
            select(CallTrainingSession).where(
                CallTrainingSession.id == session_id
            )
        )
    ).scalar_one_or_none()
    if obj is None:
        raise HTTPException(status_code=404, detail="Сессия не найдена")
    if obj.user_id != user.id:
        raise HTTPException(status_code=403, detail="Это не ваша сессия")
    return obj


def _now_iso() -> str:
    return datetime.now(tz=UTC).isoformat()


# ============ Endpoints ============


@router.post("/sessions", response_model=SessionStartOut)
async def start_session(
    body: SessionStartIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> SessionStartOut:
    """Начать тренировку: AI отвечает на звонок первой репликой клиента."""
    await _ensure_sales_access(session, current_user)

    sys_prompt = build_client_system_prompt(
        body.scenario_type, body.company_type, body.company_name
    )
    try:
        resp = await call_claude(
            prompt="Менеджер по продажам позвонил тебе. Ответь на звонок — "
            "произнеси первую реплику клиента (по своей роли).",
            system=sys_prompt,
            max_tokens=256,
        )
        opening_line = resp.text.strip() or "Алло, слушаю вас."
    except AINotConfiguredError:
        raise HTTPException(status_code=503, detail=_AI_NOT_CONFIGURED_MSG)
    except AIServiceError as e:
        raise HTTPException(status_code=502, detail=f"Ошибка ИИ-сервиса: {e}")

    brief = scenario_brief(
        body.scenario_type, body.company_type, body.company_name
    )

    row = CallTrainingSession(
        user_id=current_user.id,
        scenario_type=body.scenario_type,
        company_type=body.company_type,
        company_name=body.company_name,
        status="active",
        transcript=[
            {"role": "assistant", "content": opening_line, "ts": _now_iso()}
        ],
    )
    session.add(row)
    await session.commit()
    await session.refresh(row)

    return SessionStartOut(
        id=row.id, opening_line=opening_line, scenario_brief=brief
    )


@router.post("/sessions/{session_id}/message", response_model=MessageOut)
async def post_message(
    session_id: int,
    body: MessageIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> MessageOut:
    """Отправить реплику менеджера и получить ответ клиента (AI)."""
    await _ensure_sales_access(session, current_user)
    # P0 Security: per-user кап на платные Claude-вызовы (denial-of-wallet).
    ok, retry = await check_ai_user_rate_limit(current_user.id, "training")
    if not ok:
        raise HTTPException(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            detail="Слишком много запросов к тренажёру. Попробуйте через минуту.",
            headers={"Retry-After": str(retry)},
        )
    obj = await _load_own_session(session, session_id, current_user)
    if obj.status != "active":
        raise HTTPException(status_code=409, detail="Сессия уже завершена")

    transcript: list[dict[str, Any]] = list(obj.transcript or [])
    transcript.append(
        {"role": "user", "content": body.content, "ts": _now_iso()}
    )

    sys_prompt = build_client_system_prompt(
        obj.scenario_type, obj.company_type, obj.company_name
    )
    history_text = transcript_to_text(transcript) + "\nКлиент:"

    try:
        resp = await call_claude(
            prompt=history_text, system=sys_prompt, max_tokens=256
        )
        client_reply = resp.text.strip() or "…"
    except AINotConfiguredError:
        raise HTTPException(status_code=503, detail=_AI_NOT_CONFIGURED_MSG)
    except AIServiceError as e:
        raise HTTPException(status_code=502, detail=f"Ошибка ИИ-сервиса: {e}")

    transcript.append(
        {"role": "assistant", "content": client_reply, "ts": _now_iso()}
    )
    obj.transcript = transcript
    await session.commit()

    hints: list[str] | None = None
    low = client_reply.lower()
    if any(w in low for w in ["нет", "не интересует", "не нужно", "занят", "дорого"]):
        hints = [
            "Клиент возражает — выясни причину, не убеждай сразу",
            "Задай открытый вопрос: «Что мешает рассмотреть предложение?»",
        ]
    elif len(transcript) > 8:
        hints = ["Пора переходить к конкретному следующему шагу"]

    return MessageOut(content=client_reply, hints=hints)


@router.post("/sessions/{session_id}/finish", response_model=FinishOut)
async def finish_session(
    session_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> FinishOut:
    """Завершить сессию и вернуть скоркарту (идемпотентно)."""
    await _ensure_sales_access(session, current_user)
    obj = await _load_own_session(session, session_id, current_user)

    # Идемпотентность: повторный finish — возвращаем уже посчитанное.
    if obj.status == "finished" and obj.criteria_scores is not None:
        return _finish_out_from_row(obj)

    transcript: list[dict[str, Any]] = list(obj.transcript or [])
    try:
        resp = await call_claude(
            prompt=build_evaluator_prompt(transcript),
            system="Ты — эксперт по продажам. Отвечай строго в JSON-формате.",
            max_tokens=1024,
        )
        card = parse_scorecard(parse_json_response(resp.text))
    except AINotConfiguredError:
        raise HTTPException(status_code=503, detail=_AI_NOT_CONFIGURED_MSG)
    except (AIServiceError, Exception):  # noqa: BLE001 — оценка не должна ронять finish
        card = fallback_scorecard()

    obj.status = "finished"
    obj.score = card.score
    obj.recommendations = card.recommendations
    obj.good_decisions = card.good_decisions
    # feedback сохраняем внутри criteria_scores под служебным ключом '_feedback'
    # (не входит в 4 критерия, фильтруется при чтении в _scores_and_feedback) —
    # чтобы не плодить отдельную колонку под одно текстовое поле.
    obj.criteria_scores = {**card.criteria_scores, "_feedback": card.feedback}
    obj.finished_at = datetime.now(tz=UTC)
    await session.commit()

    return FinishOut(
        score=card.score,
        scores=ScoresOut(**card.criteria_scores),
        feedback=card.feedback,
        recommendations=card.recommendations,
        good_decisions=card.good_decisions,
    )


@router.get("/sessions", response_model=list[SessionListItem])
async def list_sessions(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> list[SessionListItem]:
    """История тренировок текущего пользователя (свежие сверху)."""
    await _ensure_sales_access(session, current_user)
    rows = (
        await session.execute(
            select(CallTrainingSession)
            .where(CallTrainingSession.user_id == current_user.id)
            .order_by(CallTrainingSession.created_at.desc())
        )
    ).scalars().all()
    return [
        SessionListItem(
            id=r.id,
            scenario_type=r.scenario_type,
            company_type=r.company_type,
            company_name=r.company_name,
            status=r.status,
            score=float(r.score) if r.score is not None else None,
            created_at=r.created_at,
            finished_at=r.finished_at,
        )
        for r in rows
    ]


@router.get("/sessions/{session_id}", response_model=SessionDetailOut)
async def get_session_detail(
    session_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> SessionDetailOut:
    """Одна сессия: транскрипт + скоркарта."""
    await _ensure_sales_access(session, current_user)
    obj = await _load_own_session(session, session_id, current_user)

    scores, feedback = _scores_and_feedback(obj)
    return SessionDetailOut(
        id=obj.id,
        scenario_type=obj.scenario_type,
        company_type=obj.company_type,
        company_name=obj.company_name,
        status=obj.status,
        score=float(obj.score) if obj.score is not None else None,
        created_at=obj.created_at,
        finished_at=obj.finished_at,
        transcript=[
            TranscriptMessage(
                role=str(m.get("role", "assistant")),
                content=str(m.get("content", "")),
                ts=m.get("ts"),
            )
            for m in (obj.transcript or [])
        ],
        scores=scores,
        recommendations=list(obj.recommendations or []),
        good_decisions=list(obj.good_decisions or []),
        feedback=feedback,
    )


# ============ Helpers (read-side) ============


def _scores_and_feedback(
    obj: CallTrainingSession,
) -> tuple[ScoresOut | None, str]:
    """Достаёт ScoresOut + feedback из criteria_scores JSONB (где feedback лежит
    под служебным ключом '_feedback')."""
    raw = obj.criteria_scores
    if not isinstance(raw, dict) or obj.status != "finished":
        return None, ""
    feedback = str(raw.get("_feedback") or "")
    scores = ScoresOut(
        **{k: float(raw.get(k, 5.0)) for k in CRITERIA_KEYS}
    )
    return scores, feedback


def _finish_out_from_row(obj: CallTrainingSession) -> FinishOut:
    scores, feedback = _scores_and_feedback(obj)
    return FinishOut(
        score=float(obj.score) if obj.score is not None else 5.0,
        scores=scores or ScoresOut(**{k: 5.0 for k in CRITERIA_KEYS}),
        feedback=feedback,
        recommendations=list(obj.recommendations or []),
        good_decisions=list(obj.good_decisions or []),
    )
