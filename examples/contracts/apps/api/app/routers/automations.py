"""PipelineAutomation (Эпик 4): CRUD + dry-run test endpoint.

Универсальный движок триггеров и действий на воронках. Применяется к любой
воронке (sales / lifecycle / lead / renewal). Каждая автоматизация = триггер +
действие + конфиг (JSON).

Endpoints:
- GET /automations — список с фильтрами (pipeline_id / trigger_kind / is_active).
- POST /automations — создать. LawyerOrAdmin / DirectorOrAdmin.
- GET /automations/{id}.
- PATCH /automations/{id}.
- DELETE /automations/{id}.
- POST /automations/{id}/test — dry-run preview без выполнения и без записи
  AutomationRun. Если в body передан target_type+target_id — preview для конкретной
  цели. Если нет — для inline-триггеров возвращаем ошибку (нужен target), для
  cron-триггеров пробегаем первые 5 matched и возвращаем 5 preview.
"""
from __future__ import annotations

from datetime import UTC, datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser, LawyerOrAdmin
from app.models import (
    AutomationRun,
    ClientSubscription,
    Deal,
    Lead,
    Pipeline,
    PipelineAutomation,
    PipelineStage,
    UserRole,
)
from app.services.automation_executor import (
    AUTOMATION_ACTIONS,
    AUTOMATION_TARGET_TYPES,
    AUTOMATION_TRIGGERS,
    dry_run_action,
    execute_action,
    floor_to_hour,
    parse_idle_window,
)

router = APIRouter(prefix="/automations", tags=["automations"])


# ============ Pydantic-схемы ============


class AutomationOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    name: str
    description: str | None
    pipeline_id: int
    stage_id: int | None
    trigger_kind: str
    trigger_config: dict[str, Any]
    action_kind: str
    action_config: dict[str, Any]
    is_active: bool
    # Эпик 19: SLA flag + escalation chain.
    is_sla: bool = False
    escalation_chain: list[dict[str, Any]] | None = None
    created_by_user_id: int | None
    last_run_at: datetime | None
    created_at: datetime
    updated_at: datetime
    # Денормализованные поля для UI
    pipeline_name: str | None = None
    stage_name: str | None = None
    runs_count: int = 0


class AutomationCreate(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    description: str | None = None
    pipeline_id: int
    stage_id: int | None = None
    trigger_kind: str
    trigger_config: dict[str, Any] = Field(default_factory=dict)
    action_kind: str
    action_config: dict[str, Any] = Field(default_factory=dict)
    is_active: bool = True
    # Эпик 19: SLA flag + escalation chain опциональны.
    is_sla: bool = False
    escalation_chain: list[dict[str, Any]] | None = None


class AutomationUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=255)
    description: str | None = None
    stage_id: int | None = None
    trigger_kind: str | None = None
    trigger_config: dict[str, Any] | None = None
    action_kind: str | None = None
    action_config: dict[str, Any] | None = None
    is_active: bool | None = None
    # Эпик 19: разрешаем PATCH'ить is_sla и escalation_chain.
    is_sla: bool | None = None
    escalation_chain: list[dict[str, Any]] | None = None


class AutomationTestIn(BaseModel):
    """Body для /automations/{id}/test. target_type+target_id опциональны:
    для inline-триггеров обязательны, для cron — если не задан, пробегаем первые
    5 matched targets."""
    target_type: str | None = None
    target_id: int | None = None


class AutomationTestOut(BaseModel):
    automation_id: int
    previews: list[dict[str, Any]]


# ============ Helpers ============


def _validate_trigger_kind(kind: str) -> None:
    if kind not in AUTOMATION_TRIGGERS:
        raise HTTPException(
            400,
            f"Недопустимый trigger_kind: {kind}. Ожидается одно из {list(AUTOMATION_TRIGGERS)}",
        )


def _validate_action_kind(kind: str) -> None:
    if kind not in AUTOMATION_ACTIONS:
        raise HTTPException(
            400,
            f"Недопустимый action_kind: {kind}. Ожидается одно из {list(AUTOMATION_ACTIONS)}",
        )


def _require_admin_for_webhook_action(
    action_kind: str | None, user: Any
) -> None:
    """C3 WARN-фикс: action_kind='webhook' — admin-grade capability (исходящий
    POST на произвольный URL). LawyerOrAdmin может создавать/менять обычные
    автоматизации (tg_notify/create_task/set_field/...), но webhook-действие —
    только Admin. SSRF-guard (P0) блокирует приватные таргеты, но сама
    конфигурация исходящего HTTP должна быть под админом.
    """
    if action_kind != "webhook":
        return
    role = getattr(user, "role", None)
    if role != UserRole.admin:
        raise HTTPException(
            status.HTTP_403_FORBIDDEN,
            "Действие 'webhook' доступно только администратору",
        )


async def _validate_webhook_action_url(
    action_kind: str | None, action_config: dict[str, Any] | None
) -> None:
    """P0 SSRF: если action_kind == 'webhook' — URL не должен указывать на
    приватный/loopback/link-local (cloud-metadata) хост. 422 иначе."""
    if action_kind != "webhook":
        return
    from app.services.ssrf_guard import SSRFBlockedError, assert_safe_webhook_url

    url = (action_config or {}).get("url")
    if not url or not isinstance(url, str):
        return  # URL не задан — это другая валидация (executor пометит skipped)
    try:
        await assert_safe_webhook_url(url)
    except SSRFBlockedError as e:
        raise HTTPException(422, SSRFBlockedError.safe_reason) from e


def _validate_target_type(target_type: str) -> None:
    if target_type not in AUTOMATION_TARGET_TYPES:
        raise HTTPException(
            400,
            f"Недопустимый target_type: {target_type}. Ожидается одно из {list(AUTOMATION_TARGET_TYPES)}",
        )


async def _get_or_404(session: AsyncSession, automation_id: int) -> PipelineAutomation:
    a = (
        await session.execute(
            select(PipelineAutomation).where(PipelineAutomation.id == automation_id)
        )
    ).scalar_one_or_none()
    if not a:
        raise HTTPException(404, "Автоматизация не найдена")
    return a


async def _to_out(
    session: AsyncSession, a: PipelineAutomation
) -> AutomationOut:
    """ORM → AutomationOut с подгрузкой имени pipeline/stage + runs_count."""
    pipe = (
        await session.execute(select(Pipeline).where(Pipeline.id == a.pipeline_id))
    ).scalar_one_or_none()
    stage = None
    if a.stage_id is not None:
        stage = (
            await session.execute(
                select(PipelineStage).where(PipelineStage.id == a.stage_id)
            )
        ).scalar_one_or_none()
    runs_count = (
        await session.execute(
            select(func.count(AutomationRun.id)).where(
                AutomationRun.automation_id == a.id
            )
        )
    ).scalar_one() or 0
    return AutomationOut(
        id=a.id,
        name=a.name,
        description=a.description,
        pipeline_id=a.pipeline_id,
        stage_id=a.stage_id,
        trigger_kind=a.trigger_kind,
        trigger_config=a.trigger_config or {},
        action_kind=a.action_kind,
        action_config=a.action_config or {},
        is_active=a.is_active,
        is_sla=bool(getattr(a, "is_sla", False)),
        escalation_chain=getattr(a, "escalation_chain", None),
        created_by_user_id=a.created_by_user_id,
        last_run_at=a.last_run_at,
        created_at=a.created_at,
        updated_at=a.updated_at,
        pipeline_name=pipe.name if pipe else None,
        stage_name=stage.name if stage else None,
        runs_count=int(runs_count),
    )


async def _validate_pipeline_and_stage(
    session: AsyncSession, pipeline_id: int, stage_id: int | None
) -> None:
    pipe = (
        await session.execute(select(Pipeline).where(Pipeline.id == pipeline_id))
    ).scalar_one_or_none()
    if not pipe:
        raise HTTPException(404, f"Воронка {pipeline_id} не найдена")
    if stage_id is not None:
        stage = (
            await session.execute(
                select(PipelineStage).where(PipelineStage.id == stage_id)
            )
        ).scalar_one_or_none()
        if not stage:
            raise HTTPException(404, f"Этап {stage_id} не найден")
        if stage.pipeline_id != pipeline_id:
            raise HTTPException(400, "Этап не принадлежит указанной воронке")


# ============ Endpoints ============


@router.get("", response_model=list[AutomationOut])
async def list_automations(
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    pipeline_id: int | None = None,
    trigger_kind: str | None = None,
    is_active: bool | None = None,
    is_sla: bool | None = None,
    limit: int = 100,
    offset: int = 0,
):
    """Список автоматизаций с фильтрами. LawyerOrAdmin.

    Эпик 19: добавлен фильтр `is_sla` (true = только SLA-правила, false = только
    обычные) — для отдельной вкладки в UI.
    """
    if trigger_kind is not None:
        _validate_trigger_kind(trigger_kind)

    stmt = select(PipelineAutomation).order_by(
        PipelineAutomation.pipeline_id, PipelineAutomation.id
    )
    if pipeline_id is not None:
        stmt = stmt.where(PipelineAutomation.pipeline_id == pipeline_id)
    if trigger_kind is not None:
        stmt = stmt.where(PipelineAutomation.trigger_kind == trigger_kind)
    if is_active is not None:
        stmt = stmt.where(PipelineAutomation.is_active.is_(is_active))
    if is_sla is not None:
        stmt = stmt.where(PipelineAutomation.is_sla.is_(is_sla))
    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))

    rows = (await session.execute(stmt)).scalars().all()
    return [await _to_out(session, a) for a in rows]


@router.post("", response_model=AutomationOut, status_code=status.HTTP_201_CREATED)
async def create_automation(
    data: AutomationCreate,
    current_user: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    _validate_trigger_kind(data.trigger_kind)
    _validate_action_kind(data.action_kind)
    _require_admin_for_webhook_action(data.action_kind, current_user)
    await _validate_webhook_action_url(data.action_kind, data.action_config)
    await _validate_pipeline_and_stage(session, data.pipeline_id, data.stage_id)

    a = PipelineAutomation(
        name=data.name,
        description=data.description,
        pipeline_id=data.pipeline_id,
        stage_id=data.stage_id,
        trigger_kind=data.trigger_kind,
        trigger_config=data.trigger_config or {},
        action_kind=data.action_kind,
        action_config=data.action_config or {},
        is_active=data.is_active,
        is_sla=data.is_sla,
        escalation_chain=data.escalation_chain,
        created_by_user_id=current_user.id,
    )
    session.add(a)
    await session.commit()
    await session.refresh(a)
    return await _to_out(session, a)


@router.get("/{automation_id}", response_model=AutomationOut)
async def get_automation(
    automation_id: int,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    a = await _get_or_404(session, automation_id)
    return await _to_out(session, a)


@router.patch("/{automation_id}", response_model=AutomationOut)
async def update_automation(
    automation_id: int,
    data: AutomationUpdate,
    current_user: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    a = await _get_or_404(session, automation_id)
    patch = data.model_dump(exclude_unset=True)

    if "trigger_kind" in patch and patch["trigger_kind"] is not None:
        _validate_trigger_kind(patch["trigger_kind"])
    if "action_kind" in patch and patch["action_kind"] is not None:
        _validate_action_kind(patch["action_kind"])
    # P0 SSRF + C3 WARN: если меняется action_kind или action_config — проверяем
    # эффективный webhook URL (новые значения с fallback на текущие) и требуем
    # Admin, если эффективный action_kind == 'webhook'.
    if "action_kind" in patch or "action_config" in patch:
        eff_kind = patch.get("action_kind", a.action_kind)
        eff_config = patch.get("action_config", a.action_config)
        _require_admin_for_webhook_action(eff_kind, current_user)
        await _validate_webhook_action_url(eff_kind, eff_config)
    if "stage_id" in patch:
        # перепривязка только в рамках той же воронки (для cross-pipeline — DELETE+POST)
        await _validate_pipeline_and_stage(session, a.pipeline_id, patch["stage_id"])

    for k, v in patch.items():
        setattr(a, k, v)
    await session.commit()
    await session.refresh(a)
    return await _to_out(session, a)


@router.delete("/{automation_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_automation(
    automation_id: int,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    a = await _get_or_404(session, automation_id)
    await session.delete(a)
    await session.commit()


@router.post("/{automation_id}/test", response_model=AutomationTestOut)
async def test_automation(
    automation_id: int,
    data: AutomationTestIn,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Dry-run preview: что бы сделалось, БЕЗ side-effects и БЕЗ AutomationRun.

    - Если передан (target_type, target_id) — preview для конкретной цели.
    - Если нет:
      * для inline-триггера (on_enter_stage) — 400 (нужен target);
      * для cron-триггера — пробегаем первые 5 matched и возвращаем 5 preview.
    """
    a = await _get_or_404(session, automation_id)

    # 1) Явно заданный target
    if data.target_type is not None and data.target_id is not None:
        _validate_target_type(data.target_type)
        preview = await dry_run_action(session, a, data.target_type, data.target_id)
        return AutomationTestOut(automation_id=a.id, previews=[preview])

    # 2) Без target — inline-триггер требует явный target
    if a.trigger_kind == "on_enter_stage":
        raise HTTPException(
            400,
            "Для триггера on_enter_stage требуется target_type+target_id в body",
        )

    # 3) Cron-триггер — пробегаем первые 5 matched
    previews: list[dict[str, Any]] = []
    cfg = a.trigger_config or {}

    if a.trigger_kind == "idle_in_stage_days":
        target_type = cfg.get("target_type", "deal")
        if target_type not in ("deal", "lead"):
            raise HTTPException(400, f"idle_in_stage_days: target_type='{target_type}' не поддерживается")
        model = Deal if target_type == "deal" else Lead
        target_stmt = (
            select(model.id)
            .where(model.pipeline_id == a.pipeline_id)
            .limit(5)
        )
        if a.stage_id is not None:
            target_stmt = target_stmt.where(model.stage_id == a.stage_id)
        ids = (await session.execute(target_stmt)).scalars().all()
        for tid in ids:
            previews.append(await dry_run_action(session, a, target_type, tid))
    elif a.trigger_kind == "date_field_approaching":
        target_type = cfg.get("target_type", "subscription")
        if target_type != "subscription":
            raise HTTPException(400, f"date_field_approaching: target_type='{target_type}' не поддерживается в MVP")
        # просто пробегаем 5 любых подписок — для preview достаточно
        ids = (
            await session.execute(
                select(ClientSubscription.id).limit(5)
            )
        ).scalars().all()
        for tid in ids:
            previews.append(await dry_run_action(session, a, target_type, tid))

    return AutomationTestOut(automation_id=a.id, previews=previews)


# ============ Эпик 19: Dry-run + Execute endpoints ============


class DryRunIn(BaseModel):
    """Параметры dry-run: лимит на кол-во матчей.

    target_type/target_id поддерживаются если админ хочет проверить КОНКРЕТНЫЙ
    target (помимо общего матча по trigger query). Без них — полный список
    матчей по trigger config, ограниченный limit (default 100, max 500).
    """
    limit: int = Field(default=100, ge=1, le=500)
    target_type: str | None = None
    target_id: int | None = None


class MatchedRecord(BaseModel):
    """Один матч в dry-run результате: entity_type+id+label+опциональная отметка."""
    entity_type: str
    entity_id: int
    entity_label: str
    # Момент срабатывания — для idle/date это вычисленный момент по конфигу.
    # null для триггеров без временной метки (on_enter_stage в превью).
    matches_at: str | None = None


class ActionPlanItem(BaseModel):
    """Описание одного запланированного действия в dry-run результате."""
    kind: str
    description: str
    target: str


class AutomationInfo(BaseModel):
    id: int
    name: str
    trigger_kind: str


class DryRunOut(BaseModel):
    """Полный результат dry-run: что бы сработало БЕЗ выполнения."""
    automation: AutomationInfo
    matched_records: list[MatchedRecord]
    match_count: int
    actions_plan: list[ActionPlanItem]


class ExecuteIn(BaseModel):
    """Параметры execute: можно ограничить выполнение конкретными entity_ids
    (полученными из предыдущего dry-run). Если entity_ids не задан —
    выполнится на ВСЕХ матчах (как cron сделал бы), но синхронно в одном
    запросе. Используй для bulk-операций (admin trigger).

    target_type обязателен если entity_ids задан — чтобы знать модель.
    Если entity_ids НЕ задан — используется target_type из trigger_config.
    """
    entity_ids: list[int] | None = None
    target_type: str | None = None
    # Жёсткий лимит, чтобы не зависнуть на огромном scope. 500 матчей × 1 действие
    # — это уже ~500 SQL-операций + 500 telegram-вызовов; больше — это работа cron'а.
    limit: int = Field(default=100, ge=1, le=500)
    # MEDIUM-фикс: по умолчанию ручной /execute уважает дедуп (не повторяет
    # действие, если по этой паре automation+target уже был success/skipped в
    # окне 1 день). Установи false для осознанной повторной рассылки.
    respect_dedup: bool = True


class ExecuteOut(BaseModel):
    """Результат execute: сколько выполнено, краткая статистика по статусам."""
    automation: AutomationInfo
    runs_created: int
    success_count: int
    failed_count: int
    skipped_count: int
    run_ids: list[int]


# ============ Helpers для dry-run матчинга ============


def _make_entity_label(target_type: str, target) -> str:
    """Сделать читаемую метку для UI dropdown'а: deal#123: <title>, lead#42: <name>..."""
    if target_type == "deal":
        title = getattr(target, "title", None) or "(без названия)"
        return f"Сделка #{target.id}: {title}"
    if target_type == "lead":
        name = getattr(target, "name", None) or "(без имени)"
        return f"Лид #{target.id}: {name}"
    if target_type == "subscription":
        return f"Подписка #{target.id}"
    return f"{target_type}#{target.id}"


async def _collect_matched_targets(
    session: AsyncSession,
    automation: PipelineAutomation,
    limit: int,
) -> list[tuple[str, int, str, str | None]]:
    """Pure helper для dry-run: вернуть список (target_type, target_id, label,
    matches_at) первых `limit` матчей по trigger config.

    Логика matched-выборки идентична run_idle_in_stage_scanner /
    run_date_field_scanner, но БЕЗ выполнения и БЕЗ записи AutomationRun.
    """
    from datetime import UTC, date, datetime, timedelta
    cfg = automation.trigger_config or {}
    results: list[tuple[str, int, str, str | None]] = []

    if automation.trigger_kind == "idle_in_stage_days":
        days, hours = parse_idle_window(cfg)
        target_type = cfg.get("target_type", "deal")
        if target_type not in ("deal", "lead"):
            return []
        model = Deal if target_type == "deal" else Lead
        cutoff = datetime.now(UTC) - timedelta(days=days, hours=hours)
        stmt = select(model).where(model.pipeline_id == automation.pipeline_id)
        if automation.stage_id is not None:
            stmt = stmt.where(model.stage_id == automation.stage_id)
        if target_type == "deal":
            stmt = stmt.where(
                model.stage_changed_at.is_not(None),
                model.stage_changed_at <= cutoff,
            )
        else:
            stmt = stmt.where(model.updated_at <= cutoff)
        stmt = stmt.limit(limit)
        targets = (await session.execute(stmt)).scalars().all()
        for t in targets:
            ts_field = getattr(t, "stage_changed_at", None) or getattr(t, "updated_at", None)
            ts_iso = ts_field.isoformat() if ts_field else None
            results.append((target_type, int(t.id), _make_entity_label(target_type, t), ts_iso))
        return results

    if automation.trigger_kind == "date_field_approaching":
        target_type = cfg.get("target_type", "subscription")
        if target_type != "subscription":
            return []
        field = cfg.get("field")
        try:
            days = int(cfg.get("days", 7))
        except (ValueError, TypeError):
            days = 7
        if days < 0:
            days = 0
        DATE_FIELDS = {
            "subscription": frozenset({
                "discount_until", "impl_start_date", "act_signed_date",
                "last_fee_increase_at", "qa_date",
            }),
        }
        allowed = DATE_FIELDS.get(target_type, frozenset())
        if not field or field not in allowed:
            return []
        col = getattr(ClientSubscription, field, None)
        if col is None:
            return []
        today = date.today()
        low = today + timedelta(days=max(0, days - 1))
        high = today + timedelta(days=days + 1)
        stmt = (
            select(ClientSubscription)
            .where(col.is_not(None), col >= low, col <= high)
            .limit(limit)
        )
        targets = (await session.execute(stmt)).scalars().all()
        for t in targets:
            date_v = getattr(t, field, None)
            iso = date_v.isoformat() if date_v else None
            results.append((target_type, int(t.id), _make_entity_label(target_type, t), iso))
        return results

    if automation.trigger_kind in ("on_enter_stage", "on_create"):
        # Inline-триггеры — нет «матчей в БД», есть только превью на конкретном
        # target. Возвращаем пустой список — UI скажет «нужен target_type+target_id».
        return []
    return []


def _build_actions_plan(
    automation: PipelineAutomation, matched_count: int,
) -> list[ActionPlanItem]:
    """Pure helper: build actions_plan для dry-run результата.

    Описывает планируемое действие текстом для UI. Включает escalation_chain
    если задан (показывает каскад: основное + эскалации с указанием after_hours).
    """
    plan: list[ActionPlanItem] = []
    cfg = automation.action_config or {}
    target_str = f"{matched_count} матч{'ей' if matched_count != 1 else ''}"

    # Описание основного действия
    if automation.action_kind == "tg_notify":
        recipient = cfg.get("recipient", "owner")
        msg = cfg.get("message", "")
        plan.append(ActionPlanItem(
            kind="tg_notify",
            description=f"TG получатель: {recipient}, текст: '{msg[:80]}'",
            target=target_str,
        ))
    elif automation.action_kind == "create_task":
        title = cfg.get("title", "(без названия)")
        plan.append(ActionPlanItem(
            kind="create_task",
            description=f"Создать задачу: '{title[:80]}'",
            target=target_str,
        ))
    elif automation.action_kind == "set_field":
        plan.append(ActionPlanItem(
            kind="set_field",
            description=f"Установить {cfg.get('field')}={cfg.get('value')}",
            target=target_str,
        ))
    elif automation.action_kind == "generate_document":
        plan.append(ActionPlanItem(
            kind="generate_document",
            description=f"Сгенерировать документ: {cfg.get('template_code')}",
            target=target_str,
        ))
    elif automation.action_kind == "change_owner":
        plan.append(ActionPlanItem(
            kind="change_owner",
            description=f"Сменить owner по правилу: {cfg.get('rule', 'round_robin')}",
            target=target_str,
        ))
    elif automation.action_kind == "webhook":
        plan.append(ActionPlanItem(
            kind="webhook",
            description=f"POST → {cfg.get('url', '(url не задан)')}",
            target=target_str,
        ))
    elif automation.action_kind == "email":
        plan.append(ActionPlanItem(
            kind="email",
            description=f"Email: {cfg.get('subject_template', '(без темы)')[:80]}",
            target=target_str,
        ))
    elif automation.action_kind == "start_sequence":
        plan.append(ActionPlanItem(
            kind="start_sequence",
            description=f"Запустить sequence #{cfg.get('sequence_id', '?')}",
            target=target_str,
        ))
    else:
        plan.append(ActionPlanItem(
            kind=automation.action_kind,
            description="(описание недоступно)",
            target=target_str,
        ))

    # Эскалации (если есть)
    escalation = automation.escalation_chain or []
    if isinstance(escalation, list):
        for i, esc in enumerate(escalation):
            if not isinstance(esc, dict):
                continue
            after_hours = esc.get("after_hours", "?")
            esc_kind = esc.get("action_kind", "?")
            plan.append(ActionPlanItem(
                kind=f"escalation_{i + 1}",
                description=f"Через {after_hours}ч: {esc_kind}",
                target=target_str,
            ))
    return plan


@router.post("/{automation_id}/dry-run", response_model=DryRunOut)
async def dry_run_automation(
    automation_id: int,
    data: DryRunIn,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Dry-run: показать что произошло бы при выполнении автоматизации,
    БЕЗ создания AutomationRun и БЕЗ side-effects.

    Логика:
    - применяем trigger query как в реальном cron'е → список матчей;
    - НЕ выполняем actions, только описание (см. _build_actions_plan);
    - возвращаем matched_records + match_count + actions_plan.

    Auth: AdminUser (отделяется от обычного /test, который доступен LawyerOrAdmin —
    dry-run возвращает потенциально чувствительные данные и пред-вычисляет
    bulk-операции).
    """
    a = await _get_or_404(session, automation_id)

    # Если задан конкретный target — возвращаем один matched record (без trigger query)
    if data.target_type is not None and data.target_id is not None:
        _validate_target_type(data.target_type)
        model = Deal if data.target_type == "deal" else (
            Lead if data.target_type == "lead" else ClientSubscription
        )
        target = (
            await session.execute(select(model).where(model.id == data.target_id))
        ).scalar_one_or_none()
        if target is None:
            raise HTTPException(404, f"{data.target_type}#{data.target_id} не найден")
        matched = [
            MatchedRecord(
                entity_type=data.target_type,
                entity_id=data.target_id,
                entity_label=_make_entity_label(data.target_type, target),
                matches_at=None,
            )
        ]
    else:
        # Общий dry-run по trigger query
        rows = await _collect_matched_targets(session, a, data.limit)
        matched = [
            MatchedRecord(
                entity_type=tt,
                entity_id=tid,
                entity_label=label,
                matches_at=mat_at,
            )
            for (tt, tid, label, mat_at) in rows
        ]

    return DryRunOut(
        automation=AutomationInfo(
            id=a.id, name=a.name, trigger_kind=a.trigger_kind,
        ),
        matched_records=matched,
        match_count=len(matched),
        actions_plan=_build_actions_plan(a, len(matched)),
    )


@router.post("/{automation_id}/execute", response_model=ExecuteOut)
async def execute_automation(
    automation_id: int,
    data: ExecuteIn,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Выполнить автоматизацию вручную на конкретных entity_ids или на всех
    матчах из dry-run. Создаёт реальные AutomationRun и выполняет actions.

    Сценарии:
    1. data.entity_ids задан → выполняем на этих конкретных target'ах;
       target_type обязателен (для модели).
    2. data.entity_ids НЕ задан → находим все матчи через _collect_matched_targets
       (как в dry-run), выполняем на каждом.

    Жёсткий лимит data.limit (default 100, max 500) защищает от случайного
    bulk на 10K записей через UI.

    Auth: AdminUser. Действие потенциально дорогое (синхронное выполнение N
    actions) — только админ.
    """
    a = await _get_or_404(session, automation_id)

    targets: list[tuple[str, int]] = []

    if data.entity_ids:
        if not data.target_type:
            # Фолбэк: вытаскиваем target_type из trigger_config
            cfg = a.trigger_config or {}
            inferred = cfg.get("target_type")
            if inferred is None or inferred not in AUTOMATION_TARGET_TYPES:
                raise HTTPException(
                    400,
                    "target_type обязателен, когда задан entity_ids — "
                    "не удалось определить автоматически",
                )
            target_type = inferred
        else:
            _validate_target_type(data.target_type)
            target_type = data.target_type
        # Лимит — для bulk-операций
        ids_to_use = list(data.entity_ids)[:data.limit]
        targets = [(target_type, int(tid)) for tid in ids_to_use]
    else:
        # Общий run по trigger query (как делает cron)
        rows = await _collect_matched_targets(session, a, data.limit)
        targets = [(tt, tid) for (tt, tid, _label, _mat) in rows]

    if not targets:
        return ExecuteOut(
            automation=AutomationInfo(
                id=a.id, name=a.name, trigger_kind=a.trigger_kind,
            ),
            runs_created=0,
            success_count=0,
            failed_count=0,
            skipped_count=0,
            run_ids=[],
        )

    # P1-фикс (idempotency race): при respect_dedup НЕ делаем read-then-write
    # через _was_recently_run — две реплики scale=2 могли одновременно пройти
    # SELECT и обе выполнить действие (дубль). Вместо этого передаём
    # детерминированный trigger_event_ts (floored до часа) в execute_action,
    # которое транзакционно «застолбляет» слот через claim_run_slot
    # (INSERT ... ON CONFLICT DO NOTHING на ux_automation_runs_idem). Проигравшая
    # гонку реплика/повторный клик получит transient-skipped (run.id is None) и
    # НЕ продублирует side-effect.
    #
    # respect_dedup=False (осознанная повторная рассылка) → trigger_event_ts=None
    # → дедуп выключен, как раньше.
    event_ts = floor_to_hour(datetime.now(UTC)) if data.respect_dedup else None

    run_ids: list[int] = []
    success_count = 0
    failed_count = 0
    skipped_count = 0
    for (tt, tid) in targets:
        try:
            run = await execute_action(
                session, a, tt, tid, trigger_event_ts=event_ts
            )
            # Transient-skipped из claim-конфликта НЕ персистится (run.id None) —
            # не льём его id в ответ, но считаем как skipped (для статистики UI).
            if run.id is not None:
                run_ids.append(run.id)
            if run.status == "success":
                success_count += 1
            elif run.status == "failed":
                failed_count += 1
            elif run.status == "skipped":
                skipped_count += 1
        except Exception:  # noqa: BLE001
            # execute_action сам ловит ошибки в AutomationRun; этот catch — на
            # внешние сбои (например, target_type не зарезолвился). Не падаем.
            failed_count += 1

    await session.commit()
    return ExecuteOut(
        automation=AutomationInfo(
            id=a.id, name=a.name, trigger_kind=a.trigger_kind,
        ),
        runs_created=len(run_ids),
        success_count=success_count,
        failed_count=failed_count,
        skipped_count=skipped_count,
        run_ids=run_ids,
    )
