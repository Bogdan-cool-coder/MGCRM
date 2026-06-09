"""Activity (Эпик 2 + Эпик 24 Tasks v2): CRUD + Timeline + Task Machine.

Activity — единая полиморфная сущность (call/meeting/task/note) для всех 7 типов
целей (Lead, Contact, Company, Counterparty, Deal, Contract, Subscription).

Эпик 24 расширяет:
- category_id, priority, status (new|in_progress|done|rejected), is_closed
- collaborators (co_executor|auditor|observer)
- checklist items (per-activity)
- attachments (multipart upload, max 20MB)
- related links (related|blocks|blocked_by|duplicates)
- bulk operations (change_deadline|reassign|close|delete)
- presets (pinned|overdue|today|this_week|my_tasks|my_orders|favorites)
- extend-deadline request → notification to creator

ACL (P0 security Unit 3a — IDOR закрыт):
- list — owner-scope (responsible/created_by/collaborator) для не-elevated ролей;
  по target — видимость наследуется от target (assert_target_visible);
- get — видимость по owner/collaborator/target (404 на чужую);
- create — target должен быть видим пользователю;
- patch/complete/reopen/status/favorite/pin/extend-deadline/collaborators/
  checklist/related — постановщик/исполнитель/admin/director (assert_can_mutate);
- status done/rejected — только исполнитель или admin/director;
- delete — author (created_by_id) ИЛИ admin/director;
- bulk — owner-guard на каждое действие (не только delete).
elevated = admin/director (видят/правят всё).
"""
from __future__ import annotations

import os
import uuid
from datetime import UTC, date, datetime, timedelta
from decimal import Decimal
from typing import Annotated, Any

from fastapi import APIRouter, Depends, File, HTTPException, Query, UploadFile, status
from pydantic import BaseModel, ConfigDict, Field, model_validator
from sqlalchemy import and_, case, func, or_, select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.db import get_session
from app.deps import CurrentUser
from app.models import (
    Activity,
    ActivityAttachment,
    ActivityChecklistItem,
    ActivityCollaborator,
    ActivityRelatedLink,
    TaskCategory,
    User,
    UserRole,
)
from app.services.activities import (
    ACTIVITY_KINDS,
    ACTIVITY_TARGET_TYPES,
    validate_target_exists,
)
from app.services.activity_visibility import (
    activity_owner_scope_clause,
    assert_can_mutate_activity,
    assert_target_visible,
    can_mutate_activity,
    is_elevated,
)
from app.services.owner_resolver import resolve_owner_param
from app.services.task_category_apply import apply_category_defaults
from app.services.task_notifications import on_assigned, on_deadline_extend_requested, on_status_changed
# Эпик 24.2 — Google Calendar 2-way sync.
# Hook'и push'а Activity → Google вызываются inline после commit (как
# on_assigned). Не падают наружу — sync_activity_to_gcal/delete_gcal_event
# сами ловят httpx-ошибки и логируют.
from app.services.gcal_sync import delete_gcal_event, sync_activity_to_gcal

router = APIRouter(prefix="/activities", tags=["activities"])

# Константы
_MAX_UPLOAD_BYTES = 20 * 1024 * 1024  # 20 MB
_UPLOAD_DIR = "uploads/activities"

ACTIVITY_PRIORITIES: tuple[str, ...] = ("low", "normal", "high", "critical")
ACTIVITY_STATUSES: tuple[str, ...] = ("new", "in_progress", "done", "rejected")
COLLABORATOR_ROLES: tuple[str, ...] = ("co_executor", "auditor", "observer")
LINK_TYPES: tuple[str, ...] = ("related", "blocks", "blocked_by", "duplicates")

# Переходы статусов: из какого в какой разрешено.
# done/rejected могут приходить только от исполнителя (responsible_id).
_STATUS_TRANSITIONS: dict[str, set[str]] = {
    "new": {"in_progress", "rejected"},
    "in_progress": {"done", "rejected", "new"},
    "done": {"in_progress"},   # переоткрыть
    "rejected": {"new"},       # вернуть в работу
}


# ============ Pydantic-схемы ============


class CollaboratorOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    activity_id: int
    user_id: int
    role: str
    added_at: datetime
    user_name: str | None = None


class ChecklistItemOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    activity_id: int
    title: str
    is_done: bool
    sort_order: int
    completed_by_user_id: int | None
    completed_at: datetime | None
    created_at: datetime


class AttachmentOut(BaseModel):
    # P0 security (Unit 3b, C5): абсолютный серверный file_path удалён из ответа —
    # утечка топологии диска. Скачивание — через download URL, не через путь.
    model_config = ConfigDict(from_attributes=True)
    id: int
    activity_id: int
    original_name: str | None
    file_size: int | None
    mime_type: str | None
    uploaded_by_user_id: int | None
    uploaded_at: datetime


class RelatedLinkOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    activity_id_from: int
    activity_id_to: int
    link_type: str
    created_by_user_id: int | None
    created_at: datetime


class ActivityOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    kind: str
    # Эпик 24 hotfix: target — optional (standalone задачи без CRM-сущности).
    target_type: str | None = None
    target_id: int | None = None
    title: str
    body: str | None
    due_at: datetime | None
    completed_at: datetime | None
    completed_by_id: int | None
    responsible_id: int | None
    created_by_id: int | None
    created_at: datetime
    updated_at: datetime
    # Денормализованные имена пользователей (для удобства UI — без N+1)
    created_by_name: str | None = None
    responsible_name: str | None = None
    completed_by_name: str | None = None
    # Epic 10.5 FTM
    is_first_time_meeting: bool = False
    ftm_decision_maker_attended: bool = False
    ftm_presentation_shown: bool = False
    ftm_report_url: str | None = None
    ftm_telegram_announced: bool = False
    # DEALS 2.0 (Ф0): ответы конструктора отчёта о встрече (meeting-активность).
    meeting_report_json: dict[str, Any] | None = None
    # Epic 24 Tasks v2
    category_id: int | None = None
    parent_activity_id: int | None = None
    priority: str = "normal"
    status: str = "new"
    is_closed: bool = False
    progress_pct: int = 0
    planned_hours: Decimal | None = None
    actual_hours: Decimal | None = None
    result_text: str | None = None
    tags: list[str] = []
    recurrence_rule: str | None = None
    recurrence_until: date | None = None
    recurrence_parent_id: int | None = None
    rejected_at: datetime | None = None
    rejected_by_user_id: int | None = None
    color_label: str | None = None
    is_favorite: bool = False
    is_pinned: bool = False
    # Эпик 24.2 — computed: True если у Activity есть хотя бы один активный
    # GoogleCalendarEventLink (т.е. задача синхронизирована с Google Calendar).
    # UI рисует badge "bi-google · Синхронизировано" в TaskDetailHeader.
    google_calendar_synced: bool = False
    # Вложенные объекты (опционально)
    collaborators: list[CollaboratorOut] = []
    checklist_items: list[ChecklistItemOut] = []
    attachments: list[AttachmentOut] = []


class ActivityCreate(BaseModel):
    kind: str
    # Эпик 24 hotfix: target — optional. Если оба поля пусты — standalone-задача
    # (не привязана к Lead/Deal/Counterparty/...). Если задан один из двух —
    # роутер вернёт 400 (см. create_activity).
    target_type: str | None = None
    target_id: int | None = None
    title: str = Field(min_length=1, max_length=255)
    body: str | None = None
    due_at: datetime | None = None
    responsible_id: int | None = None
    # Epic 24
    category_id: int | None = None
    parent_activity_id: int | None = None
    priority: str = "normal"
    planned_hours: Decimal | None = None
    tags: list[str] = []
    color_label: str | None = None
    recurrence_rule: str | None = None
    recurrence_until: date | None = None


class ActivityUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=255)
    body: str | None = None
    due_at: datetime | None = None
    responsible_id: int | None = None
    # Epic 24
    priority: str | None = None
    planned_hours: Decimal | None = None
    actual_hours: Decimal | None = None
    result_text: str | None = None
    tags: list[str] | None = None
    color_label: str | None = None
    progress_pct: int | None = Field(default=None, ge=0, le=100)
    recurrence_rule: str | None = None
    recurrence_until: date | None = None
    parent_activity_id: int | None = None
    # FTM fields
    is_first_time_meeting: bool | None = None
    ftm_decision_maker_attended: bool | None = None
    ftm_presentation_shown: bool | None = None
    ftm_report_url: str | None = None


class ActivityStatusPatch(BaseModel):
    status: str
    result_text: str | None = None  # Required if restrict_close_without_result


class ExtendDeadlineIn(BaseModel):
    """Продление дедлайна. Принимаем ДВА контракта:
    - new_due_at: точная дата (каноничный shape);
    - days: сдвиг от текущего момента (фронтовый TaskListItem шлёт {days}).
    Хотя бы одно из полей обязательно (см. validator). Если задан только days —
    new_due_at вычисляется в роутере (now + days), т.к. зависит от server-now.
    """

    new_due_at: datetime | None = None
    days: int | None = Field(default=None, ge=1, le=3650)
    # reason необязателен, если фронт его не шлёт (TaskListItem «+N дней»);
    # дефолт даёт осмысленный текст для нотификации постановщику.
    reason: str = Field(default="Продление дедлайна", max_length=1000)

    @model_validator(mode="after")
    def _require_target(self) -> "ExtendDeadlineIn":
        if self.new_due_at is None and self.days is None:
            raise ValueError("Укажите new_due_at или days")
        return self


class CollaboratorIn(BaseModel):
    user_id: int
    role: str  # co_executor|auditor|observer


class RelatedLinkIn(BaseModel):
    target_id: int
    link_type: str = "related"


class ChecklistItemIn(BaseModel):
    title: str = Field(min_length=1, max_length=512)
    sort_order: int = 0


class ChecklistReorderIn(BaseModel):
    items: list[dict[str, int]]  # [{"id": 1, "sort_order": 0}, ...]


class BulkActionIn(BaseModel):
    """Bulk-действие над задачами.

    Принимаем ДВА контракта (silent-422 guard, C1):
    - каноничный: {action, entity_ids, params}
    - фронтовый BulkActionsBar: {action, ids, payload}
    `ids`→entity_ids и `payload`→params коэрсятся в pre-validator, чтобы оба
    payload'а валидировались без 422.
    """

    model_config = ConfigDict(populate_by_name=True)

    action: str  # change_deadline|reassign|close|delete
    entity_ids: list[int] = Field(min_length=1, alias="ids")
    params: dict[str, Any] = Field(default_factory=dict, alias="payload")

    @model_validator(mode="before")
    @classmethod
    def _accept_both_shapes(cls, data: Any) -> Any:
        """Если пришли plural-ключи `ids`/`payload` — мапим на каноничные.
        populate_by_name позволяет принять и каноничные имена тоже."""
        if not isinstance(data, dict):
            return data
        out = dict(data)
        if "entity_ids" not in out and "ids" in out:
            out["entity_ids"] = out["ids"]
        if "params" not in out and "payload" in out:
            out["params"] = out["payload"]
        return out


# ============ Helpers ============


def _load_options_full():
    return [
        selectinload(Activity.created_by),
        selectinload(Activity.responsible),
        selectinload(Activity.completed_by),
        selectinload(Activity.rejected_by),
        selectinload(Activity.collaborators).selectinload(ActivityCollaborator.user),
        selectinload(Activity.checklist_items),
        selectinload(Activity.attachments),
        # Эпик 24.2 — для computed google_calendar_synced без N+1.
        selectinload(Activity.gcal_event_links),
    ]


def _load_options_minimal():
    return [
        selectinload(Activity.created_by),
        selectinload(Activity.responsible),
        selectinload(Activity.completed_by),
        # Эпик 24.2 — list/preset тоже должны возвращать google_calendar_synced
        # (UI рендерит badge в карточках задач). Eager-load — без N+1.
        selectinload(Activity.gcal_event_links),
    ]


def _to_out(activity: Activity, *, full: bool = False) -> ActivityOut:
    """ORM Activity → ActivityOut."""
    collaborators = []
    if full and activity.collaborators:
        for collab in activity.collaborators:
            collaborators.append(CollaboratorOut(
                id=collab.id,
                activity_id=collab.activity_id,
                user_id=collab.user_id,
                role=collab.role,
                added_at=collab.added_at,
                user_name=collab.user.full_name if collab.user else None,
            ))

    checklist_items = []
    if full and activity.checklist_items:
        for ci in activity.checklist_items:
            checklist_items.append(ChecklistItemOut(
                id=ci.id,
                activity_id=ci.activity_id,
                title=ci.title,
                is_done=ci.is_done,
                sort_order=ci.sort_order,
                completed_by_user_id=ci.completed_by_user_id,
                completed_at=ci.completed_at,
                created_at=ci.created_at,
            ))

    attachments = []
    if full and activity.attachments:
        for att in activity.attachments:
            attachments.append(AttachmentOut(
                id=att.id,
                activity_id=att.activity_id,
                original_name=att.original_name,
                file_size=att.file_size,
                mime_type=att.mime_type,
                uploaded_by_user_id=att.uploaded_by_user_id,
                uploaded_at=att.uploaded_at,
            ))

    # Эпик 24.2 — синхронизирован ли с Google Calendar.
    # True если есть хотя бы один активный link. gcal_event_links eager-loaded
    # обоими _load_options_* (см. selectinload выше), значит N+1 нет.
    gcal_links = getattr(activity, "gcal_event_links", None) or []
    google_calendar_synced = any(link.is_active for link in gcal_links)

    return ActivityOut(
        id=activity.id,
        kind=activity.kind,
        target_type=activity.target_type,
        target_id=activity.target_id,
        title=activity.title,
        body=activity.body,
        due_at=activity.due_at,
        completed_at=activity.completed_at,
        completed_by_id=activity.completed_by_id,
        responsible_id=activity.responsible_id,
        created_by_id=activity.created_by_id,
        created_at=activity.created_at,
        updated_at=activity.updated_at,
        created_by_name=activity.created_by.full_name if activity.created_by else None,
        responsible_name=activity.responsible.full_name if activity.responsible else None,
        completed_by_name=activity.completed_by.full_name if activity.completed_by else None,
        is_first_time_meeting=activity.is_first_time_meeting,
        ftm_decision_maker_attended=activity.ftm_decision_maker_attended,
        ftm_presentation_shown=activity.ftm_presentation_shown,
        ftm_report_url=activity.ftm_report_url,
        ftm_telegram_announced=activity.ftm_telegram_announced,
        category_id=activity.category_id,
        parent_activity_id=activity.parent_activity_id,
        priority=activity.priority,
        status=activity.status,
        is_closed=activity.is_closed,
        progress_pct=activity.progress_pct,
        planned_hours=activity.planned_hours,
        actual_hours=activity.actual_hours,
        result_text=activity.result_text,
        tags=activity.tags or [],
        recurrence_rule=activity.recurrence_rule,
        recurrence_until=activity.recurrence_until,
        recurrence_parent_id=activity.recurrence_parent_id,
        rejected_at=activity.rejected_at,
        rejected_by_user_id=activity.rejected_by_user_id,
        color_label=activity.color_label,
        is_favorite=activity.is_favorite,
        is_pinned=activity.is_pinned,
        google_calendar_synced=google_calendar_synced,
        collaborators=collaborators,
        checklist_items=checklist_items,
        attachments=attachments,
    )


async def _get_activity_or_404(
    session: AsyncSession, activity_id: int, *, full: bool = False
) -> Activity:
    """Подгружает Activity с eager-load users (и опционально child objects)."""
    opts = _load_options_full() if full else _load_options_minimal()
    activity = (await session.execute(
        select(Activity)
        .where(Activity.id == activity_id)
        .options(*opts)
    )).scalar_one_or_none()
    if not activity:
        raise HTTPException(404, "Активность не найдена")
    return activity


async def _assert_activity_readable(
    session: AsyncSession, activity: Activity, user: User
) -> None:
    """P0 security (C1): 404 если пользователь не вправе ВИДЕТЬ активность.

    Видна, если: elevated (admin/director) | постановщик | исполнитель |
    коллаборатор | имеет доступ к target-сущности (Timeline наследует видимость
    target). 404 (не 403), чтобы не палить существование.
    """
    if is_elevated(user):
        return
    if user.id in (activity.created_by_id, activity.responsible_id):
        return
    # collaborator?
    is_collab = (await session.execute(
        select(ActivityCollaborator.id).where(
            ActivityCollaborator.activity_id == activity.id,
            ActivityCollaborator.user_id == user.id,
        )
    )).scalar_one_or_none()
    if is_collab is not None:
        return
    # target-видимость (заметка/задача по чужой видимой сделке/компании).
    if activity.target_type is not None and activity.target_id is not None:
        try:
            await assert_target_visible(
                session, user, activity.target_type, activity.target_id
            )
            return
        except HTTPException:
            pass
    raise HTTPException(404, "Задача не найдена")


def _validate_kind(kind: str) -> None:
    if kind not in ACTIVITY_KINDS:
        raise HTTPException(400, f"Недопустимый kind: {kind}. Ожидается одно из {list(ACTIVITY_KINDS)}")


def _validate_target_type(target_type: str) -> None:
    if target_type not in ACTIVITY_TARGET_TYPES:
        raise HTTPException(
            400,
            f"Недопустимый target_type: {target_type}. Ожидается одно из {list(ACTIVITY_TARGET_TYPES)}",
        )


def resolve_related_targets(
    target_type: str,
    target_id: int,
    *,
    deal_company_id: int | None = None,
) -> tuple[list[tuple[str, int]], bool]:
    """Wave 5: разрешение «связанных» целей для двунаправленной видимости активностей.

    Возвращает кортеж ``(explicit_pairs, expand_company_deals)``:

    - ``explicit_pairs`` — список конкретных пар ``(target_type, target_id)``, которые
      нужно объединить через OR (всегда включает саму исходную цель).
    - ``expand_company_deals`` — True, если к OR-набору нужно добавить «все сделки
      этой компании» (``Deal.company_id == target_id``). Это разрешается на уровне
      запроса подзапросом, т.к. список id заранее неизвестен.

    Правила:
    - ``company`` → сама компания + все её сделки (``expand_company_deals=True``).
    - ``deal``    → сама сделка + её компания (если ``deal_company_id`` задан).
    - прочие типы → только сама цель (связанных нет).

    Pure-функция: не ходит в БД, тестируется напрямую. Резолв ``deal_company_id``
    и подзапрос по сделкам компании выполняет вызывающий код.
    """
    pairs: list[tuple[str, int]] = [(target_type, target_id)]
    expand_company_deals = False
    if target_type == "company":
        expand_company_deals = True
    elif target_type == "deal" and deal_company_id is not None:
        pairs.append(("company", deal_company_id))
    return pairs, expand_company_deals


def _validate_priority(priority: str) -> None:
    if priority not in ACTIVITY_PRIORITIES:
        raise HTTPException(400, f"Недопустимый priority: {priority}. Ожидается одно из {list(ACTIVITY_PRIORITIES)}")


async def _validate_responsible_id(session: AsyncSession, responsible_id: int | None) -> None:
    if responsible_id is None:
        return
    exists = (await session.execute(
        select(User.id).where(User.id == responsible_id, User.is_active.is_(True))
    )).scalar_one_or_none()
    if not exists:
        raise HTTPException(400, f"Исполнитель {responsible_id} не найден или неактивен")


# ============ Base CRUD Endpoints ============


@router.get("/presets/{preset}", response_model=list[ActivityOut])
async def get_activity_preset(
    preset: str,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
):
    """Пресеты sidebar для быстрого доступа к задачам.

    preset:
    - pinned     — закреплённые задачи пользователя
    - favorites  — избранные задачи
    - overdue    — просроченные (due_at < now, not closed)
    - today      — дедлайн сегодня
    - this_week  — дедлайн на этой неделе
    - my_tasks   — assigned to me (responsible_id=me, not closed)
    - my_orders  — created by me, not closed
    """
    now = datetime.now(UTC)
    today_start = now.replace(hour=0, minute=0, second=0, microsecond=0)
    today_end = today_start + timedelta(days=1)
    week_end = today_start + timedelta(days=7)

    preset_filters: dict[str, Any] = {
        "pinned": and_(Activity.is_pinned.is_(True), Activity.responsible_id == current_user.id),
        "favorites": and_(Activity.is_favorite.is_(True), Activity.responsible_id == current_user.id),
        "overdue": and_(
            Activity.due_at < now,
            Activity.is_closed.is_(False),
            or_(
                Activity.responsible_id == current_user.id,
                Activity.created_by_id == current_user.id,
            ),
        ),
        "today": and_(
            Activity.due_at >= today_start,
            Activity.due_at < today_end,
            Activity.is_closed.is_(False),
            or_(
                Activity.responsible_id == current_user.id,
                Activity.created_by_id == current_user.id,
            ),
        ),
        "this_week": and_(
            Activity.due_at >= today_start,
            Activity.due_at < week_end,
            Activity.is_closed.is_(False),
            or_(
                Activity.responsible_id == current_user.id,
                Activity.created_by_id == current_user.id,
            ),
        ),
        "my_tasks": and_(
            Activity.responsible_id == current_user.id,
            Activity.is_closed.is_(False),
        ),
        "my_orders": and_(
            Activity.created_by_id == current_user.id,
            Activity.is_closed.is_(False),
        ),
    }

    if preset not in preset_filters:
        raise HTTPException(400, f"Неизвестный пресет: {preset}. Доступны: {list(preset_filters)}")

    stmt = (
        select(Activity)
        .where(preset_filters[preset])
        .options(*_load_options_minimal())
        .order_by(Activity.due_at.asc().nulls_last(), Activity.created_at.desc())
        .limit(limit)
    )
    # Defense-in-depth: для non-elevated ролей дополнительно применяем owner-scope
    # (как в list_activities), чтобы даже при ошибке в preset-фильтре не утекали
    # чужие задачи. Elevated (admin/director) видят всё — owner_clause вернёт None.
    owner_clause = activity_owner_scope_clause(current_user)
    if owner_clause is not None:
        stmt = stmt.where(owner_clause)
    rows = (await session.execute(stmt)).scalars().all()
    return [_to_out(a) for a in rows]


@router.get("", response_model=list[ActivityOut])
async def list_activities(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    target_type: str | None = None,
    target_id: int | None = None,
    # Wave 5: двунаправленная видимость активностей сделка↔компания.
    # include_related=true расширяет фильтр target_type/target_id на связанные цели:
    #   company → активности компании + всех её сделок;
    #   deal    → активности сделки + её компании.
    # По умолчанию (false/absent) — строгое равенство, как раньше (callers не ломаются).
    include_related: bool = False,
    kind: str | None = None,
    responsible_id: str | None = None,
    # C1: фронт шлёт created_by_id / department_id — раньше игнорировались.
    # created_by_id — точечный фильтр по постановщику. department_id —
    # «задачи на исполнителей этого отдела» (responsible_id ∈ users of dept).
    # Owner-scope (ниже) всё равно ограничивает не-elevated роли их задачами.
    created_by_id: int | None = None,
    department_id: int | None = None,
    completed: bool | None = None,
    overdue: bool | None = None,
    # Epic 24 filters
    category_ids: str | None = None,        # comma-separated ids
    priority: str | None = None,
    tags: str | None = None,                # comma-separated tags
    activity_status: str | None = None,     # filter by status field
    parent_only: bool = False,              # только корневые (parent_activity_id IS NULL)
    has_overdue: bool | None = None,
    my_tasks: bool = False,                 # responsible_id=me, not closed
    my_orders: bool = False,                # created_by=me, not closed
    is_favorite: bool | None = None,
    is_pinned: bool | None = None,
    # DEALS 2.0 Ф1a (фикс контракта DealTaskView): фронт шлёт status[]/priority[]/
    # category_ids[] (multi), due_from/due_to, is_closed, include_closed,
    # pipeline_id, sort. Согласуем их с backend, не ломая Epic 24 имена.
    status: Annotated[list[str] | None, Query(alias="status[]")] = None,
    priority_list: Annotated[list[str] | None, Query(alias="priority[]")] = None,
    category_ids_multi: Annotated[list[str] | None, Query(alias="category_ids[]")] = None,
    due_from: date | None = None,
    due_to: date | None = None,
    is_closed: bool | None = None,
    include_closed: bool | None = None,
    pipeline_id: int | None = None,
    sort: str | None = None,
    limit: int = 100,
    offset: int = 0,
):
    """Список активностей с расширенными фильтрами (Эпик 24 + DEALS 2.0 Ф1a)."""
    if target_type is not None:
        _validate_target_type(target_type)
    if kind is not None:
        _validate_kind(kind)

    # P0 security (C1 CRITICAL): если запрошен Timeline по конкретной цели —
    # проверяем, что пользователь вправе видеть эту цель (иначе перебором
    # target_id любой читал всю ленту чужой сделки/компании). include_related
    # (company→deals) тоже наследует видимость корневой цели.
    if target_type is not None and target_id is not None:
        await assert_target_visible(session, current_user, target_type, target_id)

    responsible_id_int = resolve_owner_param(responsible_id, user_id=current_user.id)

    stmt = select(Activity).options(*_load_options_minimal())

    # Wave 5: union связанных целей (сделка↔компания) при include_related=true.
    # Применяется только когда заданы оба — target_type И target_id — и тип deal/company;
    # иначе падаем в строгое поведение (отдельные равенства).
    related_applied = False
    if (
        include_related
        and target_type in ("company", "deal")
        and target_id is not None
    ):
        from app.models import Deal

        deal_company_id: int | None = None
        if target_type == "deal":
            deal_company_id = (
                await session.execute(
                    select(Deal.company_id).where(Deal.id == target_id)
                )
            ).scalar_one_or_none()

        pairs, expand_company_deals = resolve_related_targets(
            target_type, target_id, deal_company_id=deal_company_id
        )
        or_clauses = [
            and_(Activity.target_type == t, Activity.target_id == i)
            for (t, i) in pairs
        ]
        if expand_company_deals:
            # все сделки этой компании (target_type='deal' AND target_id IN deals)
            or_clauses.append(
                and_(
                    Activity.target_type == "deal",
                    Activity.target_id.in_(
                        select(Deal.id).where(Deal.company_id == target_id)
                    ),
                )
            )
        stmt = stmt.where(or_(*or_clauses))
        related_applied = True

    if not related_applied:
        if target_type is not None:
            stmt = stmt.where(Activity.target_type == target_type)
        if target_id is not None:
            stmt = stmt.where(Activity.target_id == target_id)
    if kind is not None:
        stmt = stmt.where(Activity.kind == kind)
    if responsible_id_int is not None:
        stmt = stmt.where(Activity.responsible_id == responsible_id_int)
    # C1: фильтры created_by_id / department_id (раньше игнорировались).
    if created_by_id is not None:
        stmt = stmt.where(Activity.created_by_id == created_by_id)
    if department_id is not None:
        stmt = stmt.where(
            Activity.responsible_id.in_(
                select(User.id).where(User.department_id == department_id)
            )
        )
    if completed is True:
        stmt = stmt.where(Activity.completed_at.is_not(None))
    elif completed is False:
        stmt = stmt.where(Activity.completed_at.is_(None))
    if overdue is True:
        now = datetime.now(UTC)
        stmt = stmt.where(
            Activity.due_at.is_not(None),
            Activity.due_at < now,
            Activity.completed_at.is_(None),
        )

    # Epic 24 фильтры
    # category_ids — поддерживаем и CSV (category_ids=1,2), и multi (category_ids[]=1&...).
    cat_id_set: set[int] = set()
    if category_ids:
        cat_id_set.update(int(x.strip()) for x in category_ids.split(",") if x.strip().isdigit())
    if category_ids_multi:
        for x in category_ids_multi:
            xs = x.strip()
            if xs.isdigit():
                cat_id_set.add(int(xs))
    if cat_id_set:
        stmt = stmt.where(Activity.category_id.in_(cat_id_set))
    # priority — одиночный (priority=high) или multi (priority[]=high&priority[]=low).
    priority_set: set[str] = set()
    if priority:
        priority_set.add(priority)
    if priority_list:
        priority_set.update(p for p in priority_list if p)
    if priority_set:
        stmt = stmt.where(Activity.priority.in_(priority_set))
    # status[] — multi-фильтр по полю status (new|in_progress|done|rejected).
    if status:
        status_set = {s for s in status if s}
        if status_set:
            stmt = stmt.where(Activity.status.in_(status_set))
    # due_from / due_to — диапазон по due_at (для пресетов «сегодня» / «неделя»).
    if due_from is not None:
        stmt = stmt.where(
            Activity.due_at.is_not(None),
            Activity.due_at >= datetime(due_from.year, due_from.month, due_from.day, tzinfo=UTC),
        )
    if due_to is not None:
        # включительно по конец дня due_to
        due_to_end = datetime(due_to.year, due_to.month, due_to.day, tzinfo=UTC) + timedelta(days=1)
        stmt = stmt.where(
            Activity.due_at.is_not(None),
            Activity.due_at < due_to_end,
        )
    # is_closed / include_closed:
    #   is_closed=true/false      — точечный фильтр (приоритетнее).
    #   include_closed=false      — явный запрос open-only (Задачник/DealTaskView).
    #   include_closed=true|None  — без фильтра по is_closed (вернуть open+closed).
    # ВАЖНО: default None = «параметр не передан» = старое поведение (все активности).
    # Это сохраняет ленту Timeline и субтаски (parent_activity_id), которые не шлют
    # include_closed и должны видеть закрытые. Open-only фильтр применяется ТОЛЬКО
    # когда потребитель явно прислал include_closed=false.
    if is_closed is not None:
        stmt = stmt.where(Activity.is_closed.is_(is_closed))
    elif include_closed is False:
        stmt = stmt.where(Activity.is_closed.is_(False))
    # pipeline_id: только задачи по сделкам данной воронки (target_type='deal' +
    # JOIN Deal по target_id → Deal.pipeline_id).
    if pipeline_id is not None:
        from app.models import Deal
        stmt = stmt.where(
            Activity.target_type == "deal",
            Activity.target_id.in_(
                select(Deal.id).where(Deal.pipeline_id == pipeline_id)
            ),
        )
    if tags:
        tag_list = [t.strip() for t in tags.split(",") if t.strip()]
        if tag_list:
            # PostgreSQL ARRAY overlap: ANY
            from sqlalchemy.dialects.postgresql import array as pg_array
            stmt = stmt.where(Activity.tags.overlap(tag_list))  # type: ignore[attr-defined]
    if activity_status:
        stmt = stmt.where(Activity.status == activity_status)
    if parent_only:
        stmt = stmt.where(Activity.parent_activity_id.is_(None))
    if my_tasks:
        stmt = stmt.where(Activity.responsible_id == current_user.id, Activity.is_closed.is_(False))
    if my_orders:
        stmt = stmt.where(Activity.created_by_id == current_user.id, Activity.is_closed.is_(False))
    if is_favorite is not None:
        stmt = stmt.where(Activity.is_favorite.is_(is_favorite))
    if is_pinned is not None:
        stmt = stmt.where(Activity.is_pinned.is_(is_pinned))
    if has_overdue is True:
        now = datetime.now(UTC)
        stmt = stmt.where(
            Activity.due_at < now,
            Activity.is_closed.is_(False),
        )

    # P0 security (C1 CRITICAL): owner-scope для не-elevated ролей.
    # Если задан КОНКРЕТНЫЙ target (Timeline по сущности) — видимость уже
    # подтверждена через assert_target_visible выше, показываем всю ленту цели.
    # Иначе (Задачник без target / responsible_id=чужой) — ограничиваем
    # «активность касается меня» (responsible/created_by/collaborator).
    target_scoped = target_type is not None and target_id is not None
    if not target_scoped:
        owner_clause = activity_owner_scope_clause(current_user)
        if owner_clause is not None:
            stmt = stmt.where(owner_clause)

    # Сортировка (DEALS 2.0 Ф1a): согласовано с фронтовым SortOption.
    if sort == "due_at_asc":
        stmt = stmt.order_by(Activity.due_at.asc().nulls_last(), Activity.id.asc())
    elif sort == "due_at_desc":
        stmt = stmt.order_by(Activity.due_at.desc().nulls_last(), Activity.id.desc())
    elif sort == "priority_desc":
        # critical > high > normal > low — упорядочим через CASE.
        prio_rank = case(
            (Activity.priority == "critical", 4),
            (Activity.priority == "high", 3),
            (Activity.priority == "normal", 2),
            (Activity.priority == "low", 1),
            else_=0,
        )
        stmt = stmt.order_by(prio_rank.desc(), Activity.id.desc())
    elif sort == "created_at_asc":
        stmt = stmt.order_by(Activity.created_at.asc())
    elif sort == "updated_at_desc":
        stmt = stmt.order_by(Activity.updated_at.desc())
    else:  # created_at_desc (default)
        stmt = stmt.order_by(Activity.created_at.desc())

    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))
    rows = (await session.execute(stmt)).scalars().all()
    return [_to_out(a) for a in rows]


# ============ Counters (sidebar badges + preset counts) ============
#
# КРИТИЧНО: эти endpoints должны быть объявлены ДО @router.get("/{activity_id}"),
# иначе FastAPI попытается интерпретировать "my-open-count" / "counts-by-preset"
# как activity_id (int) и вернёт 422 Unprocessable Entity. Маршруты матчатся в
# порядке регистрации — конкретные пути всегда перед параметризованными.


class OpenCountOut(BaseModel):
    """Простой счётчик открытых задач текущего пользователя для sidebar-badge."""

    count: int


class PresetCountsOut(BaseModel):
    """Счётчики по каждому пресету sidebar страницы /tasks.

    Ключи синхронизированы с TaskPreset на фронте (apps/web/src/components/
    Tasks/TaskPresetSidebar.tsx). 'all' не возвращаем — фронт его и не
    показывает в badge (см. logic getCount → undefined).
    """

    pinned: int = 0
    overdue: int = 0
    done_unclosed: int = 0
    today: int = 0
    this_week: int = 0
    next_week: int = 0
    future: int = 0
    mine: int = 0
    my_orders: int = 0


@router.get("/my-open-count", response_model=OpenCountOut)
async def get_my_open_count(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Количество открытых задач текущего пользователя (sidebar badge).

    «Открытая задача» = kind='task', responsible_id=me, is_closed=false.
    Используется в Sidebar для badge «Задачи: N» (60s refresh) и в шапке
    страницы /tasks как «N открытых».
    """
    stmt = select(func.count(Activity.id)).where(
        Activity.kind == "task",
        Activity.responsible_id == current_user.id,
        Activity.is_closed.is_(False),
    )
    count = (await session.execute(stmt)).scalar_one() or 0
    return OpenCountOut(count=int(count))


@router.get("/counts-by-preset", response_model=PresetCountsOut)
async def get_counts_by_preset(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Счётчики задач по каждому пресету sidebar страницы /tasks.

    Все счётчики ограничены kind='task' и is_closed=false (кроме случаев когда
    пресет явно подразумевает иное — например done_unclosed это status=done).
    Запросы выполняются параллельно одним SELECT с FILTER (BOOL-aggregate) для
    минимизации round-trip к БД.
    """
    now = datetime.now(UTC)
    today_start = now.replace(hour=0, minute=0, second=0, microsecond=0)

    # Понедельник текущей недели (date.weekday(): Mon=0..Sun=6).
    # Берём 00:00 локально-наивно в UTC — это упрощение, для счётчика badge
    # этого достаточно (точный TZ-aware подсчёт сделаем в эпике аналитики).
    days_since_monday = today_start.weekday()
    this_monday = today_start - timedelta(days=days_since_monday)
    next_monday = this_monday + timedelta(days=7)
    after_next_monday = next_monday + timedelta(days=7)
    today_end = today_start + timedelta(days=1)

    me_id = current_user.id

    # «Касается меня» = responsible OR creator. Используется в overdue/done_unclosed/
    # today/this_week/next_week/future — чтобы фронт-пресеты совпадали с тем,
    # что юзер видит когда жмёт preset (там фильтра по responsible нет, но если
    # юзер ни автор, ни исполнитель — задача и не должна попадать в счётчик
    # его sidebar'а).
    touches_me = or_(
        Activity.responsible_id == me_id,
        Activity.created_by_id == me_id,
    )

    base = and_(
        Activity.kind == "task",
        Activity.is_closed.is_(False),
    )

    # Каждый счётчик — отдельный COUNT(*) FILTER ... одним SELECT.
    stmt = select(
        func.count().filter(
            and_(
                base,
                Activity.is_pinned.is_(True),
                Activity.responsible_id == me_id,
            )
        ).label("pinned"),
        func.count().filter(
            and_(
                base,
                Activity.due_at.is_not(None),
                Activity.due_at < now,
                touches_me,
            )
        ).label("overdue"),
        func.count().filter(
            and_(
                Activity.kind == "task",
                Activity.is_closed.is_(False),
                Activity.status == "done",
                touches_me,
            )
        ).label("done_unclosed"),
        func.count().filter(
            and_(
                base,
                Activity.due_at >= today_start,
                Activity.due_at < today_end,
                touches_me,
            )
        ).label("today"),
        func.count().filter(
            and_(
                base,
                Activity.due_at >= this_monday,
                Activity.due_at < next_monday,
                touches_me,
            )
        ).label("this_week"),
        func.count().filter(
            and_(
                base,
                Activity.due_at >= next_monday,
                Activity.due_at < after_next_monday,
                touches_me,
            )
        ).label("next_week"),
        func.count().filter(
            and_(
                base,
                Activity.due_at >= after_next_monday,
                touches_me,
            )
        ).label("future"),
        func.count().filter(
            and_(
                base,
                Activity.responsible_id == me_id,
            )
        ).label("mine"),
        func.count().filter(
            and_(
                base,
                Activity.created_by_id == me_id,
            )
        ).label("my_orders"),
    )
    row = (await session.execute(stmt)).one()
    return PresetCountsOut(
        pinned=int(row.pinned or 0),
        overdue=int(row.overdue or 0),
        done_unclosed=int(row.done_unclosed or 0),
        today=int(row.today or 0),
        this_week=int(row.this_week or 0),
        next_week=int(row.next_week or 0),
        future=int(row.future or 0),
        mine=int(row.mine or 0),
        my_orders=int(row.my_orders or 0),
    )


@router.post("", response_model=ActivityOut, status_code=status.HTTP_201_CREATED)
async def create_activity(
    data: ActivityCreate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создаёт Activity с автоприменением дефолтов категории (если category_id задан).

    Эпик 24: при category_id:
    - apply_category_defaults() копирует description_template → body, checklist items,
      co_executors/auditors/observers → ActivityCollaborator
    - category.auto_title_from_category → title = category.name (если title не задан)
    - category.default_executor_user_id → responsible_id (если не задан явно)
    """
    _validate_kind(data.kind)
    # Эпик 24 hotfix: target_type/target_id — опциональны (standalone задачи).
    # Если задан один из двух — ошибка (это не consistent state).
    # Если оба заданы — валидируем target_type whitelist + существование записи.
    if (data.target_type is None) != (data.target_id is None):
        raise HTTPException(
            400,
            "target_type и target_id должны быть указаны вместе либо оба пусты (standalone задача)",
        )
    if data.target_type is not None:
        _validate_target_type(data.target_type)
    if data.priority:
        _validate_priority(data.priority)
    if data.recurrence_rule and data.recurrence_rule not in ("daily", "weekly", "monthly"):
        raise HTTPException(400, "recurrence_rule должно быть daily|weekly|monthly")

    if data.target_type is not None and data.target_id is not None:
        if not await validate_target_exists(session, data.target_type, data.target_id):
            raise HTTPException(404, f"Цель {data.target_type}#{data.target_id} не найдена")
        # P0 security (C1 WARNING): нельзя создавать активность/заметку против
        # невидимой пользователю цели (спам/инъекция в чужой Timeline).
        await assert_target_visible(
            session, current_user, data.target_type, data.target_id
        )

    await _validate_responsible_id(session, data.responsible_id)

    # Проверяем category
    if data.category_id:
        cat_exists = (await session.execute(
            select(TaskCategory.id).where(
                TaskCategory.id == data.category_id, TaskCategory.is_active.is_(True)
            )
        )).scalar_one_or_none()
        if not cat_exists:
            raise HTTPException(400, f"Категория {data.category_id} не найдена или неактивна")

    # Для заметок дедлайн не имеет смысла
    due_at = None if data.kind == "note" else data.due_at

    activity = Activity(
        kind=data.kind,
        target_type=data.target_type,
        target_id=data.target_id,
        title=data.title,
        body=data.body,
        due_at=due_at,
        responsible_id=data.responsible_id,
        created_by_id=current_user.id,
        category_id=data.category_id,
        parent_activity_id=data.parent_activity_id,
        priority=data.priority or "normal",
        status="new",
        planned_hours=data.planned_hours,
        tags=data.tags or [],
        color_label=data.color_label,
        recurrence_rule=data.recurrence_rule,
        recurrence_until=data.recurrence_until,
    )
    session.add(activity)
    await session.flush()  # Получаем activity.id

    # Применяем дефолты категории (checklist, collaborators, template body, auto-title).
    if data.category_id:
        activity = await apply_category_defaults(session, activity, data.category_id)

    await session.commit()

    # Нотификации назначения.
    try:
        fresh = await _get_activity_or_404(session, activity.id, full=True)
        await on_assigned(session, fresh)
    except Exception:  # noqa: BLE001
        pass

    # Эпик 24.2: Google Calendar push (meeting/call → event).
    try:
        await sync_activity_to_gcal(session, activity.id)
    except Exception:  # noqa: BLE001
        pass

    fresh = await _get_activity_or_404(session, activity.id, full=True)
    return _to_out(fresh, full=True)


@router.get("/{activity_id}", response_model=ActivityOut)
async def get_activity(
    activity_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    activity = await _get_activity_or_404(session, activity_id, full=True)
    # P0 security (C1): видимость по owner/collaborator/target.
    await _assert_activity_readable(session, activity, current_user)
    return _to_out(activity, full=True)


@router.patch("/{activity_id}", response_model=ActivityOut)
async def update_activity(
    activity_id: int,
    data: ActivityUpdate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Partial update: title / body / due_at / responsible_id + Epic 24 поля."""
    activity = await _get_activity_or_404(session, activity_id, full=True)
    # P0 security (C1 CRITICAL): менять задачу может постановщик/исполнитель/elevated.
    assert_can_mutate_activity(activity, current_user)
    patch = data.model_dump(exclude_unset=True)

    if "responsible_id" in patch:
        await _validate_responsible_id(session, patch["responsible_id"])
    if "priority" in patch and patch["priority"]:
        _validate_priority(patch["priority"])
    if "recurrence_rule" in patch and patch["recurrence_rule"] not in (None, "daily", "weekly", "monthly"):
        raise HTTPException(400, "recurrence_rule должно быть daily|weekly|monthly или null")

    # Для note due_at не имеет смысла
    if "due_at" in patch and activity.kind == "note":
        patch.pop("due_at")

    for k, v in patch.items():
        setattr(activity, k, v)

    await session.commit()

    # Эпик 24.2: Google Calendar push после PATCH (title/due_at/body/etc).
    try:
        await sync_activity_to_gcal(session, activity.id)
    except Exception:  # noqa: BLE001
        pass

    fresh = await _get_activity_or_404(session, activity.id, full=True)
    return _to_out(fresh, full=True)


@router.post("/{activity_id}/complete", response_model=ActivityOut)
async def complete_activity(
    activity_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Отмечает activity выполненной. Идемпотентно."""
    activity = await _get_activity_or_404(session, activity_id, full=True)
    # P0 security (C1 CRITICAL): завершить может исполнитель/постановщик/elevated.
    assert_can_mutate_activity(activity, current_user)
    if activity.completed_at is None:
        activity.completed_at = datetime.now(UTC)
        activity.completed_by_id = current_user.id
        await session.commit()
        activity = await _get_activity_or_404(session, activity.id, full=True)
    return _to_out(activity, full=True)


@router.post("/{activity_id}/reopen", response_model=ActivityOut)
async def reopen_activity(
    activity_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Снимает completed_at + completed_by_id. Только для task/call/meeting."""
    activity = await _get_activity_or_404(session, activity_id, full=True)
    # P0 security (C1 CRITICAL): переоткрыть может исполнитель/постановщик/elevated.
    assert_can_mutate_activity(activity, current_user)
    if activity.kind == "note":
        raise HTTPException(400, "Заметку нельзя 'переоткрыть' — это не задача")
    if activity.completed_at is not None:
        activity.completed_at = None
        activity.completed_by_id = None
        await session.commit()
        activity = await _get_activity_or_404(session, activity.id, full=True)
    return _to_out(activity, full=True)


@router.delete("/{activity_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_activity(
    activity_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удаление: только автор (created_by_id) ИЛИ admin/director."""
    activity = await _get_activity_or_404(session, activity_id)
    is_author = activity.created_by_id == current_user.id
    is_privileged = current_user.role in (UserRole.admin, UserRole.director)
    if not (is_author or is_privileged):
        raise HTTPException(403, "Удалить активность может только автор или admin/director")

    # Эпик 24.2: удалить связанные events из Google Calendar ДО CASCADE.
    # После session.delete cascade убьёт GoogleCalendarEventLink — там
    # уже не получим google_event_id для DELETE в API.
    try:
        await delete_gcal_event(session, activity_id)
    except Exception:  # noqa: BLE001
        pass

    await session.delete(activity)
    await session.commit()


# ============ Epic 24 — Status Machine ============


@router.patch("/{activity_id}/status", response_model=ActivityOut)
async def change_activity_status(
    activity_id: int,
    data: ActivityStatusPatch,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Смена статуса по машине состояний.

    Переходы: new→in_progress|rejected; in_progress→done|rejected|new;
    done→in_progress; rejected→new.

    При status=done с category.restrict_close_without_result=True — требуется result_text.
    """
    if data.status not in ACTIVITY_STATUSES:
        raise HTTPException(400, f"Недопустимый status: {data.status}. Допустимо: {list(ACTIVITY_STATUSES)}")

    activity = await _get_activity_or_404(session, activity_id, full=True)

    # P0 security (C1 CRITICAL): статус меняет постановщик/исполнитель/elevated;
    # done/rejected — только исполнитель или elevated (модель «done/rejected от
    # исполнителя»).
    is_executor = current_user.id == activity.responsible_id
    is_creator = current_user.id == activity.created_by_id
    is_priv = is_elevated(current_user)
    if not (is_executor or is_creator or is_priv):
        raise HTTPException(404, "Задача не найдена")
    if data.status in ("done", "rejected") and not (is_executor or is_priv):
        raise HTTPException(403, "Завершить/отклонить задачу может только исполнитель")

    if activity.is_closed:
        raise HTTPException(400, "Задача закрыта постановщиком. Нельзя менять статус.")

    allowed = _STATUS_TRANSITIONS.get(activity.status, set())
    if data.status not in allowed:
        raise HTTPException(
            400,
            f"Переход {activity.status!r} → {data.status!r} недопустим. "
            f"Разрешено: {sorted(allowed)}",
        )

    # Проверка restrict_close_without_result.
    if data.status == "done" and activity.category_id:
        cat = await session.get(TaskCategory, activity.category_id)
        if cat and cat.restrict_close_without_result:
            result = data.result_text or activity.result_text
            if not result:
                raise HTTPException(
                    400,
                    "Категория требует заполнения результата работы (result_text) перед закрытием.",
                )
            activity.result_text = result

    old_status = activity.status
    activity.status = data.status
    if data.result_text is not None:
        activity.result_text = data.result_text

    if data.status == "rejected":
        activity.rejected_at = datetime.now(UTC)
        activity.rejected_by_user_id = current_user.id

    await session.commit()

    # Нотификации смены статуса.
    try:
        await on_status_changed(session, activity, old_status, data.status)
    except Exception:  # noqa: BLE001
        pass

    fresh = await _get_activity_or_404(session, activity.id, full=True)
    return _to_out(fresh, full=True)


@router.patch("/{activity_id}/close", response_model=ActivityOut)
async def close_activity(
    activity_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Финальное закрытие постановщиком (is_closed=true).

    Только creator (created_by_id) или admin/director.
    Задача должна быть в статусе done.
    """
    activity = await _get_activity_or_404(session, activity_id, full=True)
    is_creator = activity.created_by_id == current_user.id
    is_privileged = current_user.role in (UserRole.admin, UserRole.director)
    if not (is_creator or is_privileged):
        raise HTTPException(403, "Закрыть задачу может только постановщик или admin/director")
    if activity.status != "done":
        raise HTTPException(400, f"Закрыть можно только задачу в статусе done, текущий: {activity.status!r}")
    if activity.is_closed:
        raise HTTPException(400, "Задача уже закрыта")

    activity.is_closed = True
    activity.completed_at = activity.completed_at or datetime.now(UTC)
    await session.commit()

    fresh = await _get_activity_or_404(session, activity.id, full=True)
    return _to_out(fresh, full=True)


@router.patch("/{activity_id}/favorite", response_model=ActivityOut)
async def toggle_favorite(
    activity_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Переключает is_favorite."""
    activity = await _get_activity_or_404(session, activity_id)
    assert_can_mutate_activity(activity, current_user)
    activity.is_favorite = not activity.is_favorite
    await session.commit()
    fresh = await _get_activity_or_404(session, activity.id, full=True)
    return _to_out(fresh, full=True)


@router.patch("/{activity_id}/pin", response_model=ActivityOut)
async def toggle_pin(
    activity_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Переключает is_pinned."""
    activity = await _get_activity_or_404(session, activity_id)
    assert_can_mutate_activity(activity, current_user)
    activity.is_pinned = not activity.is_pinned
    await session.commit()
    fresh = await _get_activity_or_404(session, activity.id, full=True)
    return _to_out(fresh, full=True)


@router.post("/{activity_id}/extend-deadline", response_model=ActivityOut)
async def extend_deadline(
    activity_id: int,
    data: ExtendDeadlineIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Запрос на продление дедлайна — уведомляет постановщика.

    Обновляет due_at И отправляет нотификацию created_by (если это не сам пользователь).
    """
    activity = await _get_activity_or_404(session, activity_id, full=True)
    assert_can_mutate_activity(activity, current_user)
    old_due = activity.due_at
    # Контракт both-shape: если задан days — считаем new_due_at от базы.
    # База = текущий due_at (если есть) иначе now. Если задан new_due_at явно —
    # он приоритетнее (точная дата важнее относительного сдвига).
    if data.new_due_at is not None:
        new_due_at = data.new_due_at
    else:
        base = activity.due_at or datetime.now(UTC)
        new_due_at = base + timedelta(days=int(data.days or 0))
    activity.due_at = new_due_at
    await session.commit()

    try:
        requester_name = current_user.full_name
        await on_deadline_extend_requested(session, activity, requester_name, data.reason)
        await session.commit()
    except Exception:  # noqa: BLE001
        pass

    fresh = await _get_activity_or_404(session, activity.id, full=True)
    return _to_out(fresh, full=True)


# ============ Epic 24 — Collaborators ============


@router.post("/{activity_id}/collaborators", response_model=CollaboratorOut, status_code=status.HTTP_201_CREATED)
async def add_collaborator(
    activity_id: int,
    data: CollaboratorIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Добавляет участника к задаче (co_executor|auditor|observer)."""
    activity = await _get_activity_or_404(session, activity_id)
    assert_can_mutate_activity(activity, current_user)

    if data.role not in COLLABORATOR_ROLES:
        raise HTTPException(400, f"Недопустимая role: {data.role}. Допустимо: {list(COLLABORATOR_ROLES)}")

    user_exists = (await session.execute(
        select(User.id).where(User.id == data.user_id, User.is_active.is_(True))
    )).scalar_one_or_none()
    if not user_exists:
        raise HTTPException(400, f"Пользователь {data.user_id} не найден или неактивен")

    # Проверка дублирования.
    existing = (await session.execute(
        select(ActivityCollaborator).where(
            ActivityCollaborator.activity_id == activity_id,
            ActivityCollaborator.user_id == data.user_id,
            ActivityCollaborator.role == data.role,
        )
    )).scalar_one_or_none()
    if existing:
        raise HTTPException(409, "Такой участник уже добавлен")

    collab = ActivityCollaborator(
        activity_id=activity_id,
        user_id=data.user_id,
        role=data.role,
    )
    session.add(collab)
    await session.commit()
    await session.refresh(collab)

    user = await session.get(User, collab.user_id)
    return CollaboratorOut(
        id=collab.id,
        activity_id=collab.activity_id,
        user_id=collab.user_id,
        role=collab.role,
        added_at=collab.added_at,
        user_name=user.full_name if user else None,
    )


@router.delete("/{activity_id}/collaborators/{user_id}", status_code=status.HTTP_204_NO_CONTENT)
async def remove_collaborator(
    activity_id: int,
    user_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Убирает участника из задачи (все его роли)."""
    activity = await _get_activity_or_404(session, activity_id)
    assert_can_mutate_activity(activity, current_user)
    collabs = (await session.execute(
        select(ActivityCollaborator).where(
            ActivityCollaborator.activity_id == activity_id,
            ActivityCollaborator.user_id == user_id,
        )
    )).scalars().all()
    if not collabs:
        raise HTTPException(404, "Участник не найден в этой задаче")
    for c in collabs:
        await session.delete(c)
    await session.commit()


# ============ Epic 24 — Checklist ============


@router.post("/{activity_id}/checklist", response_model=ChecklistItemOut, status_code=status.HTTP_201_CREATED)
async def add_checklist_item(
    activity_id: int,
    data: ChecklistItemIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Добавляет пункт чек-листа к задаче."""
    activity = await _get_activity_or_404(session, activity_id)
    assert_can_mutate_activity(activity, current_user)

    # sort_order: если не задан явно — ставим в конец.
    max_order_row = (await session.execute(
        select(func.max(ActivityChecklistItem.sort_order)).where(
            ActivityChecklistItem.activity_id == activity_id
        )
    )).scalar_one()
    sort_order = data.sort_order if data.sort_order != 0 else ((max_order_row or 0) + 1)

    item = ActivityChecklistItem(
        activity_id=activity_id,
        title=data.title,
        sort_order=sort_order,
    )
    session.add(item)
    await session.commit()
    await session.refresh(item)
    return ChecklistItemOut(
        id=item.id,
        activity_id=item.activity_id,
        title=item.title,
        is_done=item.is_done,
        sort_order=item.sort_order,
        completed_by_user_id=item.completed_by_user_id,
        completed_at=item.completed_at,
        created_at=item.created_at,
    )


@router.patch("/{activity_id}/checklist/{item_id}", response_model=ChecklistItemOut)
async def toggle_checklist_item(
    activity_id: int,
    item_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Переключает is_done у пункта чек-листа."""
    activity = await _get_activity_or_404(session, activity_id)
    assert_can_mutate_activity(activity, current_user)
    item = (await session.execute(
        select(ActivityChecklistItem).where(
            ActivityChecklistItem.id == item_id,
            ActivityChecklistItem.activity_id == activity_id,
        )
    )).scalar_one_or_none()
    if not item:
        raise HTTPException(404, "Пункт чек-листа не найден")

    item.is_done = not item.is_done
    if item.is_done:
        item.completed_at = datetime.now(UTC)
        item.completed_by_user_id = current_user.id
    else:
        item.completed_at = None
        item.completed_by_user_id = None

    await session.commit()
    await session.refresh(item)
    return ChecklistItemOut(
        id=item.id,
        activity_id=item.activity_id,
        title=item.title,
        is_done=item.is_done,
        sort_order=item.sort_order,
        completed_by_user_id=item.completed_by_user_id,
        completed_at=item.completed_at,
        created_at=item.created_at,
    )


@router.delete("/{activity_id}/checklist/{item_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_checklist_item(
    activity_id: int,
    item_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удаляет пункт чек-листа."""
    activity = await _get_activity_or_404(session, activity_id)
    assert_can_mutate_activity(activity, current_user)
    item = (await session.execute(
        select(ActivityChecklistItem).where(
            ActivityChecklistItem.id == item_id,
            ActivityChecklistItem.activity_id == activity_id,
        )
    )).scalar_one_or_none()
    if not item:
        raise HTTPException(404, "Пункт чек-листа не найден")
    await session.delete(item)
    await session.commit()


@router.patch("/{activity_id}/checklist/reorder", response_model=list[ChecklistItemOut])
async def reorder_checklist(
    activity_id: int,
    data: ChecklistReorderIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Массовое обновление sort_order пунктов чек-листа."""
    activity = await _get_activity_or_404(session, activity_id)
    assert_can_mutate_activity(activity, current_user)

    for entry in data.items:
        item_id = entry.get("id")
        new_order = entry.get("sort_order")
        if item_id is None or new_order is None:
            continue
        item = (await session.execute(
            select(ActivityChecklistItem).where(
                ActivityChecklistItem.id == item_id,
                ActivityChecklistItem.activity_id == activity_id,
            )
        )).scalar_one_or_none()
        if item:
            item.sort_order = new_order

    await session.commit()

    items = (await session.execute(
        select(ActivityChecklistItem)
        .where(ActivityChecklistItem.activity_id == activity_id)
        .order_by(ActivityChecklistItem.sort_order)
    )).scalars().all()

    return [
        ChecklistItemOut(
            id=i.id,
            activity_id=i.activity_id,
            title=i.title,
            is_done=i.is_done,
            sort_order=i.sort_order,
            completed_by_user_id=i.completed_by_user_id,
            completed_at=i.completed_at,
            created_at=i.created_at,
        )
        for i in items
    ]


# ============ Epic 24 — Attachments ============


@router.post("/{activity_id}/attachments", response_model=AttachmentOut, status_code=status.HTTP_201_CREATED)
async def upload_attachment(
    activity_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    file: UploadFile = File(...),
):
    """Загружает вложение к задаче. Максимум 20 MB."""
    activity = await _get_activity_or_404(session, activity_id)
    assert_can_mutate_activity(activity, current_user)

    content = await file.read()
    if len(content) > _MAX_UPLOAD_BYTES:
        raise HTTPException(413, "Файл превышает лимит 20 МБ")

    os.makedirs(_UPLOAD_DIR, exist_ok=True)
    ext = os.path.splitext(file.filename or "")[1]
    filename = f"{uuid.uuid4().hex}{ext}"
    file_path = os.path.join(_UPLOAD_DIR, filename)

    with open(file_path, "wb") as f:
        f.write(content)

    att = ActivityAttachment(
        activity_id=activity_id,
        file_path=file_path,
        original_name=file.filename,
        file_size=len(content),
        mime_type=file.content_type,
        uploaded_by_user_id=current_user.id,
    )
    session.add(att)
    await session.commit()
    await session.refresh(att)

    return AttachmentOut(
        id=att.id,
        activity_id=att.activity_id,
        original_name=att.original_name,
        file_size=att.file_size,
        mime_type=att.mime_type,
        uploaded_by_user_id=att.uploaded_by_user_id,
        uploaded_at=att.uploaded_at,
    )


@router.delete("/{activity_id}/attachments/{attachment_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_attachment(
    activity_id: int,
    attachment_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удаляет вложение. Автор загрузки или admin/director."""
    att = (await session.execute(
        select(ActivityAttachment).where(
            ActivityAttachment.id == attachment_id,
            ActivityAttachment.activity_id == activity_id,
        )
    )).scalar_one_or_none()
    if not att:
        raise HTTPException(404, "Вложение не найдено")

    is_uploader = att.uploaded_by_user_id == current_user.id
    is_privileged = current_user.role in (UserRole.admin, UserRole.director)
    if not (is_uploader or is_privileged):
        raise HTTPException(403, "Удалить вложение может только загрузивший или admin/director")

    # Удаляем файл с диска.
    try:
        if os.path.exists(att.file_path):
            os.remove(att.file_path)
    except OSError:
        pass  # Не блокируем удаление записи из БД если файл пропал

    await session.delete(att)
    await session.commit()


# ============ Epic 24 — Related Links ============


@router.post("/{activity_id}/related", response_model=RelatedLinkOut, status_code=status.HTTP_201_CREATED)
async def add_related_link(
    activity_id: int,
    data: RelatedLinkIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Связывает две задачи (related|blocks|blocked_by|duplicates)."""
    if data.link_type not in LINK_TYPES:
        raise HTTPException(400, f"Недопустимый link_type: {data.link_type}. Допустимо: {list(LINK_TYPES)}")

    activity = await _get_activity_or_404(session, activity_id)
    assert_can_mutate_activity(activity, current_user)
    target_exists = (await session.execute(
        select(Activity.id).where(Activity.id == data.target_id)
    )).scalar_one_or_none()
    if not target_exists:
        raise HTTPException(404, f"Активность {data.target_id} не найдена")
    if data.target_id == activity_id:
        raise HTTPException(400, "Нельзя связать задачу с самой собой")

    existing = (await session.execute(
        select(ActivityRelatedLink).where(
            ActivityRelatedLink.activity_id_from == activity_id,
            ActivityRelatedLink.activity_id_to == data.target_id,
        )
    )).scalar_one_or_none()
    if existing:
        raise HTTPException(409, "Такая связь уже существует")

    link = ActivityRelatedLink(
        activity_id_from=activity_id,
        activity_id_to=data.target_id,
        link_type=data.link_type,
        created_by_user_id=current_user.id,
    )
    session.add(link)
    await session.commit()
    await session.refresh(link)
    return RelatedLinkOut(
        id=link.id,
        activity_id_from=link.activity_id_from,
        activity_id_to=link.activity_id_to,
        link_type=link.link_type,
        created_by_user_id=link.created_by_user_id,
        created_at=link.created_at,
    )


@router.delete("/{activity_id}/related/{target_id}", status_code=status.HTTP_204_NO_CONTENT)
async def remove_related_link(
    activity_id: int,
    target_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удаляет связь между задачами."""
    activity = await _get_activity_or_404(session, activity_id)
    assert_can_mutate_activity(activity, current_user)
    link = (await session.execute(
        select(ActivityRelatedLink).where(
            ActivityRelatedLink.activity_id_from == activity_id,
            ActivityRelatedLink.activity_id_to == target_id,
        )
    )).scalar_one_or_none()
    if not link:
        raise HTTPException(404, "Связь не найдена")
    await session.delete(link)
    await session.commit()


# ============ Epic 24 — Bulk Operations ============


@router.post("/bulk", response_model=dict)
async def bulk_action(
    data: BulkActionIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Массовые операции над задачами.

    action:
    - change_deadline  — params: {new_due_at: "ISO datetime"}
    - reassign         — params: {responsible_id: int}
    - close            — закрыть задачи (is_closed=true, status должен быть done)
    - delete           — удалить (только admin/director или author)

    Возвращает {updated: N, failed: [...ids]}.
    """
    allowed_actions = {"change_deadline", "reassign", "close", "delete"}
    if data.action not in allowed_actions:
        raise HTTPException(400, f"Недопустимое action: {data.action}. Допустимо: {sorted(allowed_actions)}")

    is_privileged = current_user.role in (UserRole.admin, UserRole.director)
    updated = 0
    failed: list[int] = []

    activities = (await session.execute(
        select(Activity).where(Activity.id.in_(data.entity_ids))
    )).scalars().all()

    found_ids = {a.id for a in activities}
    for missing_id in set(data.entity_ids) - found_ids:
        failed.append(missing_id)

    for activity in activities:
        try:
            # P0 security (C1 CRITICAL): owner-guard на КАЖДОЕ действие (раньше
            # только delete). Менеджер не может bulk-переносить/переназначать/
            # закрывать чужие задачи.
            if not can_mutate_activity(activity, current_user):
                failed.append(activity.id)
                continue

            if data.action == "change_deadline":
                new_due = data.params.get("new_due_at")
                if not new_due:
                    raise ValueError("new_due_at обязателен для change_deadline")
                activity.due_at = datetime.fromisoformat(str(new_due))
                updated += 1

            elif data.action == "reassign":
                new_responsible = data.params.get("responsible_id")
                if not new_responsible:
                    raise ValueError("responsible_id обязателен для reassign")
                await _validate_responsible_id(session, int(new_responsible))
                activity.responsible_id = int(new_responsible)
                updated += 1

            elif data.action == "close":
                if activity.status != "done":
                    raise ValueError(f"Задача {activity.id} не в статусе done")
                activity.is_closed = True
                updated += 1

            elif data.action == "delete":
                is_author = activity.created_by_id == current_user.id
                if not (is_author or is_privileged):
                    raise PermissionError("Нет прав на удаление")
                await session.delete(activity)
                updated += 1

        except Exception:  # noqa: BLE001
            failed.append(activity.id)

    await session.commit()
    return {"updated": updated, "failed": failed}
