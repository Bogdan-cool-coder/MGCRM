"""DEALS 2.0 (Ф1b) — справочники конструктора воронки.

Эндпоинты:
- /api/deals/lost-reasons — реестр причин отказа (CRUD).
- /api/deals/meeting-questions — конструктор отчёта о встрече (вопросы + варианты).

ACL: GET — любой CurrentUser (нужно менеджеру для формы отчёта и выбора причины
отказа); мутации (POST/PATCH/DELETE) — admin/director.

DELETE — hard-delete (это настроечные справочники, не несущие исторических
ссылок: Deal.lost_reason хранится свободным текстом, ответы отчёта — копией в
Activity.meeting_report_json). is_active=false скрывает из выбора без удаления.
"""
from __future__ import annotations

from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin
from app.models import (
    MeetingReportOption,
    MeetingReportQuestion,
    LostReason,
    Pipeline,
)
from app.schemas import (
    LostReasonIn,
    LostReasonOut,
    LostReasonPatch,
    MeetingReportOptionIn,
    MeetingReportOptionOut,
    MeetingReportOptionPatch,
    MeetingReportQuestionIn,
    MeetingReportQuestionOut,
    MeetingReportQuestionPatch,
)

router = APIRouter(prefix="/deals", tags=["deals-config"])

_MEETING_QUESTION_KINDS = {"text", "select"}


# ============ Реестр причин отказа ============


@router.get("/lost-reasons", response_model=list[LostReasonOut])
async def list_lost_reasons(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    active_only: bool = False,
):
    """Список причин отказа. active_only=true — только активные (для выпадашки
    при переводе сделки в проигрыш)."""
    stmt = select(LostReason).order_by(LostReason.sort_order, LostReason.id)
    if active_only:
        stmt = stmt.where(LostReason.is_active.is_(True))
    return (await session.execute(stmt)).scalars().all()


@router.post(
    "/lost-reasons",
    response_model=LostReasonOut,
    status_code=status.HTTP_201_CREATED,
)
async def create_lost_reason(
    data: LostReasonIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать причину отказа (admin/director). name уникален."""
    dup = (
        await session.execute(
            select(LostReason.id).where(LostReason.name == data.name)
        )
    ).scalar_one_or_none()
    if dup:
        raise HTTPException(409, "Причина с таким названием уже существует")
    r = LostReason(
        name=data.name, sort_order=data.sort_order, is_active=data.is_active
    )
    session.add(r)
    await session.commit()
    await session.refresh(r)
    return r


@router.patch("/lost-reasons/{rid}", response_model=LostReasonOut)
async def update_lost_reason(
    rid: int,
    data: LostReasonPatch,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Частичное обновление причины отказа (admin/director)."""
    r = (
        await session.execute(select(LostReason).where(LostReason.id == rid))
    ).scalar_one_or_none()
    if not r:
        raise HTTPException(404, "Причина отказа не найдена")
    patch = data.model_dump(exclude_unset=True)
    if "name" in patch and patch["name"] is not None and patch["name"] != r.name:
        dup = (
            await session.execute(
                select(LostReason.id).where(
                    LostReason.name == patch["name"], LostReason.id != rid
                )
            )
        ).scalar_one_or_none()
        if dup:
            raise HTTPException(409, "Причина с таким названием уже существует")
    for k, v in patch.items():
        if v is not None:
            setattr(r, k, v)
    await session.commit()
    await session.refresh(r)
    return r


@router.delete(
    "/lost-reasons/{rid}", status_code=status.HTTP_204_NO_CONTENT
)
async def delete_lost_reason(
    rid: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить причину отказа (admin/director)."""
    r = (
        await session.execute(select(LostReason).where(LostReason.id == rid))
    ).scalar_one_or_none()
    if not r:
        raise HTTPException(404, "Причина отказа не найдена")
    await session.delete(r)
    await session.commit()


# ============ Конструктор отчёта о встрече ============

_Q_LOAD = [selectinload(MeetingReportQuestion.options)]


def _validate_kind(kind: str) -> None:
    if kind not in _MEETING_QUESTION_KINDS:
        raise HTTPException(400, "kind должен быть 'text' или 'select'")


async def _validate_pipeline(session: AsyncSession, pipeline_id: int | None) -> None:
    if pipeline_id is None:
        return
    if not (
        await session.execute(select(Pipeline.id).where(Pipeline.id == pipeline_id))
    ).scalar_one_or_none():
        raise HTTPException(400, "Воронка не найдена")


async def _get_question_or_404(
    session: AsyncSession, qid: int
) -> MeetingReportQuestion:
    q = (
        await session.execute(
            select(MeetingReportQuestion)
            .where(MeetingReportQuestion.id == qid)
            .options(*_Q_LOAD)
        )
    ).scalar_one_or_none()
    if not q:
        raise HTTPException(404, "Вопрос отчёта не найден")
    return q


@router.get("/meeting-questions", response_model=list[MeetingReportQuestionOut])
async def list_meeting_questions(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    pipeline_id: int | None = None,
    active_only: bool = False,
):
    """Вопросы конструктора отчёта о встрече. pipeline_id фильтрует: возвращает
    глобальные (pipeline_id IS NULL) + привязанные к этой воронке. Без фильтра —
    все. GET доступен менеджеру (нужно для формы отчёта)."""
    stmt = (
        select(MeetingReportQuestion)
        .options(*_Q_LOAD)
        .order_by(MeetingReportQuestion.sort_order, MeetingReportQuestion.id)
    )
    if pipeline_id is not None:
        stmt = stmt.where(
            (MeetingReportQuestion.pipeline_id == pipeline_id)
            | (MeetingReportQuestion.pipeline_id.is_(None))
        )
    if active_only:
        stmt = stmt.where(MeetingReportQuestion.is_active.is_(True))
    return (await session.execute(stmt)).scalars().all()


@router.post(
    "/meeting-questions",
    response_model=MeetingReportQuestionOut,
    status_code=status.HTTP_201_CREATED,
)
async def create_meeting_question(
    data: MeetingReportQuestionIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать вопрос + варианты ответа (admin/director)."""
    _validate_kind(data.kind)
    await _validate_pipeline(session, data.pipeline_id)
    q = MeetingReportQuestion(
        pipeline_id=data.pipeline_id,
        text=data.text,
        kind=data.kind,
        sort_order=data.sort_order,
        is_active=data.is_active,
    )
    # Варианты ответа имеют смысл только для kind='select'.
    if data.kind == "select":
        for i, opt in enumerate(data.options):
            q.options.append(
                MeetingReportOption(
                    text=opt.text,
                    sort_order=opt.sort_order if opt.sort_order != 0 else i,
                )
            )
    session.add(q)
    await session.commit()
    return await _get_question_or_404(session, q.id)


@router.patch(
    "/meeting-questions/{qid}", response_model=MeetingReportQuestionOut
)
async def update_meeting_question(
    qid: int,
    data: MeetingReportQuestionPatch,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Частичное обновление вопроса (admin/director). Варианты ответа
    редактируются отдельными эндпоинтами (options ниже)."""
    q = await _get_question_or_404(session, qid)
    patch = data.model_dump(exclude_unset=True)
    if "kind" in patch and patch["kind"] is not None:
        _validate_kind(patch["kind"])
    if "pipeline_id" in patch:
        await _validate_pipeline(session, patch["pipeline_id"])
    # pipeline_id допускаем явный None (глобальный вопрос) — поэтому не
    # фильтруем по `is not None`, а применяем все ключи из exclude_unset.
    for k, v in patch.items():
        setattr(q, k, v)
    await session.commit()
    return await _get_question_or_404(session, q.id)


@router.delete(
    "/meeting-questions/{qid}", status_code=status.HTTP_204_NO_CONTENT
)
async def delete_meeting_question(
    qid: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить вопрос (admin/director). Варианты ответа удаляются каскадом."""
    q = await _get_question_or_404(session, qid)
    await session.delete(q)
    await session.commit()


# ============ Варианты ответа вопроса (kind='select') ============


@router.post(
    "/meeting-questions/{qid}/options",
    response_model=MeetingReportOptionOut,
    status_code=status.HTTP_201_CREATED,
)
async def add_meeting_option(
    qid: int,
    data: MeetingReportOptionIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Добавить вариант ответа к вопросу (admin/director). Только для
    kind='select'."""
    q = await _get_question_or_404(session, qid)
    if q.kind != "select":
        raise HTTPException(400, "Варианты ответа доступны только для kind='select'")
    opt = MeetingReportOption(
        question_id=qid, text=data.text, sort_order=data.sort_order
    )
    session.add(opt)
    await session.commit()
    await session.refresh(opt)
    return opt


@router.patch(
    "/meeting-questions/{qid}/options/{oid}",
    response_model=MeetingReportOptionOut,
)
async def update_meeting_option(
    qid: int,
    oid: int,
    data: MeetingReportOptionPatch,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Обновить вариант ответа (admin/director)."""
    opt = (
        await session.execute(
            select(MeetingReportOption).where(
                MeetingReportOption.id == oid,
                MeetingReportOption.question_id == qid,
            )
        )
    ).scalar_one_or_none()
    if not opt:
        raise HTTPException(404, "Вариант ответа не найден")
    patch = data.model_dump(exclude_unset=True)
    for k, v in patch.items():
        if v is not None:
            setattr(opt, k, v)
    await session.commit()
    await session.refresh(opt)
    return opt


@router.delete(
    "/meeting-questions/{qid}/options/{oid}",
    status_code=status.HTTP_204_NO_CONTENT,
)
async def delete_meeting_option(
    qid: int,
    oid: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить вариант ответа (admin/director)."""
    opt = (
        await session.execute(
            select(MeetingReportOption).where(
                MeetingReportOption.id == oid,
                MeetingReportOption.question_id == qid,
            )
        )
    ).scalar_one_or_none()
    if not opt:
        raise HTTPException(404, "Вариант ответа не найден")
    await session.delete(opt)
    await session.commit()
