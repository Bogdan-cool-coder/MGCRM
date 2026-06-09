"""Конструктор воронок: отделы, воронки, этапы (CRUD — admin/director; GET — все)."""
from __future__ import annotations

from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel
from sqlalchemy import func
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, DirectorOrAdmin
from app.models import (
    Channel,
    Deal,
    Pipeline,
    PipelineAutomation,
    PipelineStage,
    PipelineTransition,
)
from app.schemas import (
    DealCardConfigIn,
    DealCardConfigOut,
    PipelineChannelLinkIn,
    PipelineChannelOut,
    PipelineIn,
    PipelineOut,
    PipelineSettingsOut,
    PipelineSettingsPatch,
    PipelineStageCreate,
    PipelineStageIn,
    PipelineStageOut,
    PipelineStagePatch,
    PipelineTransitionIn,
    PipelineTransitionOut,
    PipelineTransitionPatch,
)
from app.services.bulk_reorder import (
    apply_reorder_simple,
    validate_reorder_payload,
)
from app.services.deals_v2 import (
    STAGE_FEATURES_WHITELIST,
    normalize_pipeline_settings,
    validate_pipeline_settings,
    validate_transition_conditions,
)
from app.services.deals_wave4 import (
    DEAL_CARD_FIELDS_KEY,
    STAGE_REQUIRED_FIELDS_KEY,
    normalize_deal_card_config,
    validate_deal_card_config,
)


class ReorderItem(BaseModel):
    """Один элемент в payload bulk-reorder."""

    id: int
    sort_order: int


class ReorderItemOrderIndex(BaseModel):
    """Один элемент в payload bulk-reorder для моделей с order_index."""

    id: int
    order_index: int


class ReorderOut(BaseModel):
    updated: int


router = APIRouter(tags=["pipelines"])


# ---------- Отделы (Эпик 14) ----------
# Минимальные CRUD `/departments` ранее жили здесь (минимальная Department модель).
# С Эпиком 14 (миграции 0035+0036) Department расширен: parent_id, head_user_id,
# is_active. Полноценный CRUD + tree + members живёт в app.routers.departments
# и регистрируется отдельно. Legacy-эндпоинты НЕ дублируем — был мёртвый код.
#
# Для совместимости: `PUT /api/departments/{id}/members/{user_id}` и парный DELETE
# тоже больше нет — фронт должен пользоваться `PATCH /api/users/{user_id}/department`.
# Это решение принимается потому, что в `apps/web/src` поиск `departments` ничего
# не возвращает на момент Эпика 14 — старые эндпоинты были не подключены к UI.


# ---------- Воронки ----------

@router.get("/pipelines", response_model=list[PipelineOut])
async def list_pipelines(current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    return (await session.execute(select(Pipeline).order_by(Pipeline.sort_order, Pipeline.id))).scalars().all()


@router.post("/pipelines", response_model=PipelineOut, status_code=status.HTTP_201_CREATED)
async def create_pipeline(data: PipelineIn, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    p = Pipeline(**data.model_dump())
    session.add(p)
    await session.commit()
    await session.refresh(p)
    return p


@router.patch("/pipelines/{pid}", response_model=PipelineOut)
async def update_pipeline(pid: int, data: PipelineIn, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    p = (await session.execute(select(Pipeline).where(Pipeline.id == pid))).scalar_one_or_none()
    if not p:
        raise HTTPException(404, "Воронка не найдена")
    for k, v in data.model_dump().items():
        setattr(p, k, v)
    await session.commit()
    await session.refresh(p)
    return p


# ---------- Этапы ----------

@router.get("/pipelines/{pid}/stages", response_model=list[PipelineStageOut])
async def list_stages(pid: int, current_user: CurrentUser, session: Annotated[AsyncSession, Depends(get_session)]):
    return (await session.execute(
        select(PipelineStage).where(PipelineStage.pipeline_id == pid).order_by(PipelineStage.sort_order)
    )).scalars().all()


def _apply_stage(s: PipelineStage, data: PipelineStageIn) -> None:
    """Полное применение PipelineStageIn (legacy + Эпик 23 + DEALS 2.0)."""
    s.name = data.name
    s.code = data.code
    s.sort_order = data.sort_order
    s.color = data.color
    s.is_won = data.is_won
    s.is_lost = data.is_lost
    s.is_active = data.is_active
    s.responsible_user_ids = data.responsible_user_ids
    s.task_types = data.task_types
    s.visible_department_ids = data.visible_department_ids
    s.visible_user_ids = data.visible_user_ids
    # Эпик 23
    s.description = data.description
    s.sla_hours = data.sla_hours
    s.default_task_category_id = data.default_task_category_id
    # DEALS 2.0 (Ф0/Ф1b)
    s.hidden_by_default = data.hidden_by_default
    s.parent_stage_id = data.parent_stage_id
    s.stage_features = data.stage_features
    s.allowed_task_category_ids = data.allowed_task_category_ids
    s.won_gate = data.won_gate


def _apply_stage_create(s: PipelineStage, data: PipelineStageCreate) -> None:
    """Inline-create (Эпик 23 + DEALS 2.0): POST /api/pipelines/{pid}/stages."""
    s.name = data.name
    s.code = data.code
    s.sort_order = data.sort_order
    s.color = data.color
    s.is_won = data.is_won
    s.is_lost = data.is_lost
    s.is_active = data.is_active
    s.responsible_user_ids = data.responsible_user_ids
    s.task_types = data.task_types
    s.visible_department_ids = data.visible_department_ids
    s.visible_user_ids = data.visible_user_ids
    s.description = data.description
    s.sla_hours = data.sla_hours
    s.default_task_category_id = data.default_task_category_id
    # DEALS 2.0 (Ф0/Ф1b): подстатусы, фичи, гейт, ограничение типов задач.
    s.hidden_by_default = data.hidden_by_default
    s.parent_stage_id = data.parent_stage_id
    s.stage_features = data.stage_features
    s.allowed_task_category_ids = data.allowed_task_category_ids
    s.won_gate = data.won_gate


def _apply_stage_patch(s: PipelineStage, data: PipelineStagePatch) -> None:
    """Эпик 23: partial PATCH. None в payload = «не трогать», explicit values
    в payload = «применить». exclude_unset=True гарантирует, что отсутствующие
    в JSON ключи не перезатирают БД-данные.
    """
    diff = data.model_dump(exclude_unset=True)
    for k, v in diff.items():
        setattr(s, k, v)


def _validate_stage_features(features: list[str]) -> None:
    """DEALS 2.0: фичи этапа должны быть в whitelist (services/deals_v2)."""
    bad = [f for f in features if f not in STAGE_FEATURES_WHITELIST]
    if bad:
        raise HTTPException(400, f"Недопустимые фичи этапа: {bad}")


async def _validate_parent_stage(
    session: AsyncSession, pid: int, sid: int | None, parent_stage_id: int | None
) -> None:
    """DEALS 2.0: подстатус (parent_stage_id) должен принадлежать той же воронке,
    не быть самим этапом, и сам не быть подстатусом (без вложенности >1).
    """
    if parent_stage_id is None:
        return
    if sid is not None and parent_stage_id == sid:
        raise HTTPException(400, "Этап не может быть подстатусом самого себя")
    parent = (
        await session.execute(
            select(PipelineStage).where(PipelineStage.id == parent_stage_id)
        )
    ).scalar_one_or_none()
    if not parent:
        raise HTTPException(400, "Родительский этап не найден")
    if parent.pipeline_id != pid:
        raise HTTPException(400, "Родительский этап из другой воронки")
    if parent.parent_stage_id is not None:
        raise HTTPException(400, "Нельзя вкладывать подстатус в подстатус")


@router.post("/pipelines/{pid}/stages", response_model=PipelineStageOut, status_code=status.HTTP_201_CREATED)
async def create_stage(
    pid: int,
    data: PipelineStageCreate,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Эпик 23: inline-создание этапа из визуального конструктора.

    Body — PipelineStageCreate (включает description / sla_hours /
    default_task_category_id). Legacy-сигнатура с PipelineStageIn заменена на
    Create-DTO; defaults в Create совпадают с PipelineStageIn для backward
    compat (поля не обязательные, всё что фронт раньше слал — продолжает работать).
    """
    if not (await session.execute(select(Pipeline).where(Pipeline.id == pid))).scalar_one_or_none():
        raise HTTPException(404, "Воронка не найдена")
    _validate_stage_features(data.stage_features)
    await _validate_parent_stage(session, pid, None, data.parent_stage_id)
    s = PipelineStage(pipeline_id=pid)
    _apply_stage_create(s, data)
    session.add(s)
    await session.commit()
    await session.refresh(s)
    return s


@router.patch("/pipelines/{pid}/stages/{sid}", response_model=PipelineStageOut)
async def update_stage(
    pid: int,
    sid: int,
    data: PipelineStagePatch,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Эпик 23: PATCH partial обновление этапа.

    Поля, отсутствующие в JSON, НЕ перезатирают БД-значения (используется
    model_dump(exclude_unset=True)). Это позволяет фронту обновлять одну
    подпись/SLA без необходимости перепосылать весь объект этапа.
    """
    s = (
        await session.execute(
            select(PipelineStage).where(
                PipelineStage.id == sid, PipelineStage.pipeline_id == pid
            )
        )
    ).scalar_one_or_none()
    if not s:
        raise HTTPException(404, "Этап не найден")
    patch = data.model_dump(exclude_unset=True)
    if "stage_features" in patch and patch["stage_features"] is not None:
        _validate_stage_features(patch["stage_features"])
    if "parent_stage_id" in patch:
        await _validate_parent_stage(session, pid, sid, patch["parent_stage_id"])
    _apply_stage_patch(s, data)
    await session.commit()
    await session.refresh(s)
    return s


@router.delete("/pipelines/{pid}/stages/{sid}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_stage(pid: int, sid: int, current_user: DirectorOrAdmin, session: Annotated[AsyncSession, Depends(get_session)]):
    s = (await session.execute(select(PipelineStage).where(PipelineStage.id == sid, PipelineStage.pipeline_id == pid))).scalar_one_or_none()
    if not s:
        raise HTTPException(404, "Этап не найден")
    # DEALS 2.0 (фикс): запрещаем удаление этапа с подстатусами (child_stages).
    # Иначе parent_stage_id у подстатусов обнулится (ondelete=SET NULL) и сделки
    # на подстатусах пропадут с доски (она вкладывает их под родителя).
    child_count = (await session.execute(
        select(func.count()).select_from(PipelineStage).where(PipelineStage.parent_stage_id == sid)
    )).scalar_one()
    if child_count:
        raise HTTPException(
            status.HTTP_409_CONFLICT,
            "У этапа есть подстатусы — сначала удалите или перенесите их",
        )
    # Считаем сделки по всему поддереву (сам этап + подстатусы). На случай гонки
    # оставляем и прямую проверку.
    used = (await session.execute(
        select(func.count()).select_from(Deal).where(Deal.stage_id == sid)
    )).scalar_one()
    if used:
        raise HTTPException(
            status.HTTP_409_CONFLICT,
            "Нельзя удалить: есть связанные сделки/записи. Сначала перенесите или удалите их.",
        )
    # P2: миграция 0106 поставила ON DELETE RESTRICT на deals.stage_id и
    # deal_stage_history.from/to_stage_id. Pre-check выше закрывает обычный путь,
    # но при гонке (сделку создали между проверкой и delete) БД бросит
    # IntegrityError — ловим и отдаём чистый 409 вместо необработанного 500.
    try:
        await session.delete(s)
        await session.commit()
    except IntegrityError:
        await session.rollback()
        raise HTTPException(
            status.HTTP_409_CONFLICT,
            "Нельзя удалить: есть связанные сделки/записи. Сначала перенесите или удалите их.",
        )


# ============ Bulk-reorder (Tech Sprint Фаза 0) ============


@router.patch("/pipelines/{pid}/stages/reorder", response_model=ReorderOut)
async def reorder_stages(
    pid: int,
    payload: list[ReorderItem],
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Bulk-обновить sort_order этапов воронки одним запросом (DirectorOrAdmin).

    Используется для drag-and-drop переупорядочивания этапов в /pipelines/{id}.
    """
    if not (await session.execute(select(Pipeline).where(Pipeline.id == pid))).scalar_one_or_none():
        raise HTTPException(404, "Воронка не найдена")
    items_dict = [p.model_dump() for p in payload]
    pairs = validate_reorder_payload(items_dict, order_field="sort_order")
    count = await apply_reorder_simple(
        session,
        PipelineStage,
        pairs,
        scope_filter=(PipelineStage.pipeline_id == pid),
        order_field="sort_order",
    )
    await session.commit()
    return ReorderOut(updated=count)


# ============ Эпик 23: visual-config — единый endpoint для конструктора ============

class StageVisualOut(BaseModel):
    """Этап для визуального конструктора. Расширенная версия PipelineStageOut
    с агрегированными счётчиками automations_count + deals_count.
    """
    id: int
    name: str
    code: str | None
    sort_order: int
    description: str | None
    sla_hours: int | None
    default_task_category_id: int | None
    color: str | None
    is_won: bool
    is_lost: bool
    is_active: bool
    automations_count: int
    deals_count: int


class AutomationSummaryOut(BaseModel):
    """Краткая info по автоматизации для визуального конструктора.

    Полные поля редактируются через /api/automations/{id} — здесь только что
    нужно для отрисовки бэйджа «3 автоматизации» на этапе.
    """
    id: int
    name: str
    stage_id: int | None
    trigger_kind: str
    action_kind: str
    is_active: bool
    is_sla: bool


class PipelineVisualConfigOut(BaseModel):
    """Эпик 23: всё что нужно для рендера визуального конструктора одной воронки.

    Уменьшает количество frontend round-trip'ов (раньше: GET /pipelines/{id},
    GET /pipelines/{id}/stages, GET /automations?pipeline_id=..., COUNT каналов
    — теперь один эндпоинт).
    """
    pipeline: PipelineOut
    stages: list[StageVisualOut]
    automations: list[AutomationSummaryOut]
    # Сколько каналов привязаны к этой воронке (для левого сайдбара
    # «Источники сделок»). Канал считается привязанным если у него
    # default_pipeline_id == pid OR default_stage_id ∈ stages этой воронки.
    channel_sources_count: int


@router.get("/pipelines/{pid}/visual-config", response_model=PipelineVisualConfigOut)
async def pipeline_visual_config(
    pid: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Эпик 23: единый endpoint для рендера визуального конструктора воронки.

    Возвращает pipeline + stages с агрегированными счётчиками + automations +
    channel_sources_count одним запросом. Все CurrentUser имеют доступ (read-only),
    мутации этапов / автоматизаций — DirectorOrAdmin через отдельные эндпоинты.
    """
    pipeline = (
        await session.execute(select(Pipeline).where(Pipeline.id == pid))
    ).scalar_one_or_none()
    if not pipeline:
        raise HTTPException(404, "Воронка не найдена")

    stages = (
        await session.execute(
            select(PipelineStage)
            .where(PipelineStage.pipeline_id == pid)
            .order_by(PipelineStage.sort_order)
        )
    ).scalars().all()
    stage_ids = [s.id for s in stages]

    # Кол-во сделок per-этап (один SQL вместо N+1).
    deals_count_map: dict[int, int] = {}
    if stage_ids:
        rows = (
            await session.execute(
                select(Deal.stage_id, func.count(Deal.id))
                .where(Deal.stage_id.in_(stage_ids))
                .group_by(Deal.stage_id)
            )
        ).all()
        deals_count_map = {sid: int(cnt) for sid, cnt in rows}

    # Автоматизации этой воронки (вкл. stage_id IS NULL — pipeline-wide).
    automations = (
        await session.execute(
            select(PipelineAutomation)
            .where(PipelineAutomation.pipeline_id == pid)
            .order_by(PipelineAutomation.stage_id.nullsfirst(), PipelineAutomation.id)
        )
    ).scalars().all()
    # Кол-во автоматизаций per-этап (stage_id IS NULL не приписывается ни одному
    # конкретному этапу — это «pipeline-wide» автоматизации, рендерим в шапке).
    automations_count_map: dict[int, int] = {}
    for a in automations:
        if a.stage_id is None:
            continue
        automations_count_map[a.stage_id] = (
            automations_count_map.get(a.stage_id, 0) + 1
        )

    # Кол-во каналов с default_pipeline_id == pid.
    channel_sources_count = (
        await session.execute(
            select(func.count(Channel.id)).where(
                Channel.default_pipeline_id == pid,
                Channel.is_active.is_(True),
            )
        )
    ).scalar_one()

    return PipelineVisualConfigOut(
        pipeline=PipelineOut.model_validate(pipeline),
        stages=[
            StageVisualOut(
                id=s.id,
                name=s.name,
                code=s.code,
                sort_order=s.sort_order,
                description=s.description,
                sla_hours=s.sla_hours,
                default_task_category_id=s.default_task_category_id,
                color=s.color,
                is_won=s.is_won,
                is_lost=s.is_lost,
                is_active=s.is_active,
                automations_count=automations_count_map.get(s.id, 0),
                deals_count=deals_count_map.get(s.id, 0),
            )
            for s in stages
        ],
        automations=[
            AutomationSummaryOut(
                id=a.id,
                name=a.name,
                stage_id=a.stage_id,
                trigger_kind=a.trigger_kind,
                action_kind=a.action_kind,
                is_active=a.is_active,
                is_sla=bool(a.is_sla),
            )
            for a in automations
        ],
        channel_sources_count=int(channel_sources_count or 0),
    )


# ============ DEALS 2.0 (Ф1b) — настройки воронки ============


async def _get_pipeline_or_404(session: AsyncSession, pid: int) -> Pipeline:
    p = (
        await session.execute(select(Pipeline).where(Pipeline.id == pid))
    ).scalar_one_or_none()
    if not p:
        raise HTTPException(404, "Воронка не найдена")
    return p


@router.get("/pipelines/{pid}/settings", response_model=PipelineSettingsOut)
async def get_pipeline_settings(
    pid: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Настройки воронки (auto_assign / duplicate_check_*). GET — любой
    CurrentUser; нормализуем сырой JSON к канон-набору с дефолтами."""
    p = await _get_pipeline_or_404(session, pid)
    return PipelineSettingsOut(**normalize_pipeline_settings(p.settings))


@router.patch("/pipelines/{pid}/settings", response_model=PipelineSettingsOut)
async def update_pipeline_settings(
    pid: int,
    data: PipelineSettingsPatch,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Частичное обновление настроек воронки (admin/director). Незаданные ключи
    сохраняют текущее значение; дубль-чек поля валидируются по whitelist."""
    p = await _get_pipeline_or_404(session, pid)
    patch = data.model_dump(exclude_unset=True)
    errors = validate_pipeline_settings(patch)
    if errors:
        raise HTTPException(400, "; ".join(errors))
    # Мержим поверх текущих нормализованных настроек.
    merged = normalize_pipeline_settings(p.settings)
    merged.update({k: v for k, v in patch.items() if v is not None})
    p.settings = normalize_pipeline_settings(merged)
    await session.commit()
    return PipelineSettingsOut(**p.settings)


# ============ Wave 4 — конфиг карточки сделки (deal_card_fields) ============

@router.get("/pipelines/{pid}/deal-card-config", response_model=DealCardConfigOut)
async def get_deal_card_config(
    pid: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Конфиг карточки сделки воронки (видимость/порядок/required полей).

    Хранится в Pipeline.settings под ключами deal_card_fields /
    stage_required_fields. Если не задан — возвращаем дефолт (все стандартные
    поля видимы, ничего не required)."""
    p = await _get_pipeline_or_404(session, pid)
    return DealCardConfigOut(**normalize_deal_card_config(p.settings))


@router.put("/pipelines/{pid}/deal-card-config", response_model=DealCardConfigOut)
async def put_deal_card_config(
    pid: int,
    data: DealCardConfigIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Сохранить конфиг карточки сделки (admin/director). Остальные ключи
    Pipeline.settings (auto_assign / duplicate_check_*) сохраняются."""
    p = await _get_pipeline_or_404(session, pid)
    raw = data.model_dump()
    errors = validate_deal_card_config(raw)
    if errors:
        raise HTTPException(400, "; ".join(errors))
    normalized = normalize_deal_card_config(raw)
    # Мержим в существующий settings, НЕ затирая прочие ключи (auto_assign и т.д.).
    new_settings = dict(p.settings or {})
    new_settings[DEAL_CARD_FIELDS_KEY] = normalized[DEAL_CARD_FIELDS_KEY]
    new_settings[STAGE_REQUIRED_FIELDS_KEY] = normalized[STAGE_REQUIRED_FIELDS_KEY]
    p.settings = new_settings
    await session.commit()
    return DealCardConfigOut(**normalized)


# ============ DEALS 2.0 (Ф1b) — каналы воронки (источники сделок) ============


@router.get("/pipelines/{pid}/channels", response_model=list[PipelineChannelOut])
async def list_pipeline_channels(
    pid: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Все каналы с флагом linked — привязан ли канал к ЭТОЙ воронке
    (Channel.default_pipeline_id == pid). Junction не вводим — у канала одна
    воронка-назначение."""
    await _get_pipeline_or_404(session, pid)
    channels = (
        await session.execute(select(Channel).order_by(Channel.name, Channel.id))
    ).scalars().all()
    return [
        PipelineChannelOut(
            id=c.id,
            name=c.name,
            kind=c.kind,
            is_active=c.is_active,
            linked=(c.default_pipeline_id == pid),
        )
        for c in channels
    ]


@router.patch("/pipelines/{pid}/channels", response_model=PipelineChannelOut)
async def link_pipeline_channel(
    pid: int,
    data: PipelineChannelLinkIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Привязать/отвязать канал к воронке (admin/director). is_active=True →
    Channel.default_pipeline_id=pid; False → NULL (если канал был привязан к этой
    воронке). default_stage_id, ставший «чужим» после отвязки, сбрасываем."""
    await _get_pipeline_or_404(session, pid)
    ch = (
        await session.execute(select(Channel).where(Channel.id == data.channel_id))
    ).scalar_one_or_none()
    if not ch:
        raise HTTPException(404, "Канал не найден")
    if data.is_active:
        ch.default_pipeline_id = pid
    else:
        # Отвязываем только если канал был привязан именно к этой воронке.
        if ch.default_pipeline_id == pid:
            ch.default_pipeline_id = None
            ch.default_stage_id = None
    await session.commit()
    await session.refresh(ch)
    return PipelineChannelOut(
        id=ch.id,
        name=ch.name,
        kind=ch.kind,
        is_active=ch.is_active,
        linked=(ch.default_pipeline_id == pid),
    )


# ============ DEALS 2.0 (Ф1b) — межворонночные переходы ============


async def _validate_transition_stages(
    session: AsyncSession,
    pid: int,
    from_stage_id: int,
    to_pipeline_id: int,
    to_stage_id: int,
) -> None:
    """from_stage принадлежит воронке pid; to_stage принадлежит to_pipeline_id;
    to_pipeline существует."""
    from_stage = (
        await session.execute(
            select(PipelineStage).where(PipelineStage.id == from_stage_id)
        )
    ).scalar_one_or_none()
    if not from_stage:
        raise HTTPException(400, "Этап-источник не найден")
    if from_stage.pipeline_id != pid:
        raise HTTPException(400, "Этап-источник из другой воронки")
    to_pipeline = (
        await session.execute(select(Pipeline).where(Pipeline.id == to_pipeline_id))
    ).scalar_one_or_none()
    if not to_pipeline:
        raise HTTPException(400, "Целевая воронка не найдена")
    to_stage = (
        await session.execute(
            select(PipelineStage).where(PipelineStage.id == to_stage_id)
        )
    ).scalar_one_or_none()
    if not to_stage:
        raise HTTPException(400, "Целевой этап не найден")
    if to_stage.pipeline_id != to_pipeline_id:
        raise HTTPException(400, "Целевой этап не принадлежит целевой воронке")


@router.get(
    "/pipelines/{pid}/transitions", response_model=list[PipelineTransitionOut]
)
async def list_transitions(
    pid: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Межворонночные переходы, чьи from_stage принадлежат этой воронке."""
    await _get_pipeline_or_404(session, pid)
    stage_ids = (
        await session.execute(
            select(PipelineStage.id).where(PipelineStage.pipeline_id == pid)
        )
    ).scalars().all()
    if not stage_ids:
        return []
    rows = (
        await session.execute(
            select(PipelineTransition)
            .where(PipelineTransition.from_stage_id.in_(stage_ids))
            .order_by(PipelineTransition.id)
        )
    ).scalars().all()
    return rows


@router.post(
    "/pipelines/{pid}/transitions",
    response_model=PipelineTransitionOut,
    status_code=status.HTTP_201_CREATED,
)
async def create_transition(
    pid: int,
    data: PipelineTransitionIn,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать межворонночный переход (admin/director). Валидируем
    принадлежность этапов воронкам + предикаты conditions."""
    await _get_pipeline_or_404(session, pid)
    await _validate_transition_stages(
        session, pid, data.from_stage_id, data.to_pipeline_id, data.to_stage_id
    )
    cond_errors = validate_transition_conditions(data.conditions)
    if cond_errors:
        raise HTTPException(400, "; ".join(cond_errors))
    t = PipelineTransition(
        name=data.name,
        from_stage_id=data.from_stage_id,
        to_pipeline_id=data.to_pipeline_id,
        to_stage_id=data.to_stage_id,
        conditions=data.conditions,
        is_active=data.is_active,
    )
    session.add(t)
    await session.commit()
    await session.refresh(t)
    return t


@router.patch(
    "/pipelines/{pid}/transitions/{tid}", response_model=PipelineTransitionOut
)
async def update_transition(
    pid: int,
    tid: int,
    data: PipelineTransitionPatch,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Частичное обновление перехода (admin/director). Если затронуты stage'ы —
    перевалидируем принадлежность воронкам; если conditions — предикаты."""
    await _get_pipeline_or_404(session, pid)
    t = (
        await session.execute(
            select(PipelineTransition).where(PipelineTransition.id == tid)
        )
    ).scalar_one_or_none()
    if not t:
        raise HTTPException(404, "Переход не найден")
    patch = data.model_dump(exclude_unset=True)
    # Резолвим итоговые значения stage'ей для перевалидации.
    new_from = patch.get("from_stage_id", t.from_stage_id)
    new_to_pipeline = patch.get("to_pipeline_id", t.to_pipeline_id)
    new_to_stage = patch.get("to_stage_id", t.to_stage_id)
    if any(
        k in patch for k in ("from_stage_id", "to_pipeline_id", "to_stage_id")
    ):
        await _validate_transition_stages(
            session, pid, new_from, new_to_pipeline, new_to_stage
        )
    if "conditions" in patch and patch["conditions"] is not None:
        cond_errors = validate_transition_conditions(patch["conditions"])
        if cond_errors:
            raise HTTPException(400, "; ".join(cond_errors))
    for k, v in patch.items():
        if v is not None:
            setattr(t, k, v)
    await session.commit()
    await session.refresh(t)
    return t


@router.delete(
    "/pipelines/{pid}/transitions/{tid}",
    status_code=status.HTTP_204_NO_CONTENT,
)
async def delete_transition(
    pid: int,
    tid: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить межворонночный переход (admin/director)."""
    await _get_pipeline_or_404(session, pid)
    t = (
        await session.execute(
            select(PipelineTransition).where(PipelineTransition.id == tid)
        )
    ).scalar_one_or_none()
    if not t:
        raise HTTPException(404, "Переход не найден")
    await session.delete(t)
    await session.commit()
