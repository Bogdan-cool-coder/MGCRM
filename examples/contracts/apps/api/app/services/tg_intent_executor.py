"""Эпик 24.3 — TG Intent Executor.

Принимает IntentResult из parse_intent() и выполняет реальное действие в БД:
- create_task  → создаёт Activity(kind='task')
- close_task   → находит задачу и ставит status='done' + is_closed=True
- search_tasks → запрашивает список задач по фильтрам
- recommend    → собирает эвристические рекомендации
- unknown      → возвращает help-текст

ExecutionResult содержит reply_text для бота, action_taken для лога,
и data (list или dict) для inline-кнопок.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from datetime import UTC, datetime, timedelta
from typing import Any

from sqlalchemy import func, or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import Activity, Deal, User
from app.services.tg_intent import IntentResult, parse_relative_date, resolve_user_by_name

logger = logging.getLogger(__name__)


# ============ Data classes ============

@dataclass
class ExecutionResult:
    """Результат выполнения интента."""
    success: bool
    message: str           # текст для ответа в Telegram (HTML)
    action_taken: str      # для поля result_action_taken в TGIntentLog
    data: Any = None       # dict/list — доп. данные (для inline keyboard)
    # Для create_task / close_task — inline keyboard подтверждения
    needs_confirmation: bool = False
    confirmation_data: dict[str, Any] = field(default_factory=dict)


@dataclass
class Recommendation:
    """Одна рекомендация для менеджера."""
    text: str
    priority: int   # 1 = высокий, 3 = низкий
    link: str | None = None  # URL в CRM (опционально)


# ============ Главный dispatcher ============

async def execute_intent(
    session: AsyncSession,
    intent_result: IntentResult,
    user: User,
) -> ExecutionResult:
    """Выполнить интент из IntentResult.

    user — уже найденный User из БД (lookup сделан до вызова).
    """
    intent = intent_result.intent
    params = intent_result.params
    confidence = intent_result.confidence

    # При низкой уверенности лучше попросить уточнить
    if confidence < 0.5 and intent != "unknown":
        return ExecutionResult(
            success=False,
            message=(
                "Не уверен, что правильно понял. Попробуйте уточнить:\n"
                "• <b>Создать задачу</b>: «Поставь задачу позвонить клиенту X в пятницу»\n"
                "• <b>Закрыть задачу</b>: «Закрой задачу про встречу с X»\n"
                "• <b>Показать задачи</b>: «Мои просроченные задачи»\n"
                "• <b>Рекомендации</b>: «Что мне делать сейчас?»"
            ),
            action_taken="low_confidence",
        )

    if intent == "create_task":
        return await _execute_create_task(session, params, user)
    elif intent == "close_task":
        return await _execute_close_task(session, params, user)
    elif intent == "search_tasks":
        return await _execute_search_tasks(session, params, user)
    elif intent == "recommend":
        return await _execute_recommend(session, params, user)
    else:
        return ExecutionResult(
            success=False,
            message=(
                "Не понял, что нужно сделать. Попробуйте:\n"
                "• «<i>Поставь Илье задачу позвонить клиенту X в среду</i>»\n"
                "• «<i>Закрой задачу про звонок клиенту X</i>»\n"
                "• «<i>Покажи мои просроченные задачи</i>»\n"
                "• «<i>Что мне делать прямо сейчас?</i>»"
            ),
            action_taken="unknown_intent",
        )


# Роли, которым разрешено назначать задачи на других пользователей через
# NL-интент (CRIT-3). Сравниваем по .value, т.к. role — Enum (UserRole), но
# у мок-объектов в тестах может быть строка.
_DELEGATION_ROLES = {"admin", "director"}


def _can_delegate(user: User) -> bool:
    """Имеет ли пользователь право назначать задачи на ДРУГИХ людей.

    Защита от prompt-injection: рядовой менеджер не должен через свободный
    текст назначать задачи коллегам/директору. Только admin/director.
    """
    role = getattr(user, "role", None)
    role_str = getattr(role, "value", role)
    return isinstance(role_str, str) and role_str in _DELEGATION_ROLES


# ============ create_task ============

async def _execute_create_task(
    session: AsyncSession,
    params: dict[str, Any],
    creator: User,
) -> ExecutionResult:
    """Создать Activity(kind='task') из params."""
    title = params.get("title") or ""
    if not title or not title.strip():
        return ExecutionResult(
            success=False,
            message="Не указан заголовок задачи. Уточните: «Поставь задачу <i>позвонить Ивану</i>»",
            action_taken="create_task_no_title",
        )

    # Responsible.
    # CRIT-3 (prompt injection → назначение чужому): NL-текст может протащить
    # «назначь задачу директору». Делегирование на ДРУГОГО пользователя
    # разрешаем только если создатель имеет полномочия (admin/director).
    # Иначе — задача назначается на самого создателя (fail-safe), с пометкой.
    responsible_id: int | None = None
    delegation_blocked = False
    responsible_name_str = params.get("responsible") or "me"
    responsible_user = await resolve_user_by_name(session, responsible_name_str, creator.id)
    if responsible_user:
        if responsible_user.id == creator.id or _can_delegate(creator):
            responsible_id = responsible_user.id
        else:
            # Нет права делегировать — назначаем на себя.
            responsible_user = creator
            responsible_id = creator.id
            delegation_blocked = True

    # due_at
    due_at: datetime | None = None
    due_at_str = params.get("due_at")
    if due_at_str:
        # Попробуем сначала как ISO datetime
        try:
            due_at = datetime.fromisoformat(due_at_str.replace("Z", "+00:00"))
            if due_at.tzinfo is None:
                due_at = due_at.replace(tzinfo=UTC)
        except (ValueError, AttributeError):
            due_at = parse_relative_date(due_at_str, now=datetime.now(UTC))

    priority = params.get("priority") or "normal"
    if priority not in ("low", "normal", "high", "critical"):
        priority = "normal"

    activity = Activity(
        kind="task",
        target_type="user",
        target_id=creator.id,
        title=title.strip()[:255],
        responsible_id=responsible_id,
        created_by_id=creator.id,
        due_at=due_at,
        priority=priority,
        status="new",
    )
    session.add(activity)
    await session.flush()  # получаем activity.id без commit

    responsible_display = (
        responsible_user.full_name if responsible_user else "не назначен"
    )
    due_display = (
        due_at.strftime("%d.%m.%Y %H:%M") if due_at else "без дедлайна"
    )

    delegation_note = (
        "\n<i>Нет прав назначать задачи другим — назначено на вас.</i>"
        if delegation_blocked
        else ""
    )

    return ExecutionResult(
        success=True,
        message=(
            f"Задача создана:\n"
            f"<b>{activity.title}</b>\n"
            f"Исполнитель: {responsible_display}\n"
            f"Дедлайн: {due_display}\n"
            f"ID: <code>{activity.id}</code>"
            f"{delegation_note}"
        ),
        action_taken=f"created_activity_id_{activity.id}",
        data={"activity_id": activity.id},
        needs_confirmation=False,  # создаём сразу (commit делает роутер)
    )


# ============ close_task ============

async def _execute_close_task(
    session: AsyncSession,
    params: dict[str, Any],
    user: User,
) -> ExecutionResult:
    """Закрыть задачу по task_id или search_phrase."""
    task_id = params.get("task_id")
    search_phrase = params.get("search_phrase") or ""

    if task_id:
        # WARN-7: Claude может вернуть нечисловой task_id → int() бросит
        # ValueError/TypeError. Не роняем в 500 — отвечаем по-человечески.
        try:
            task_id_int = int(task_id)
        except (ValueError, TypeError):
            return ExecutionResult(
                success=False,
                message=(
                    f"Не понял ID задачи «{task_id}». Укажите числовой ID "
                    "или фразу для поиска."
                ),
                action_taken="close_task_bad_id",
            )
        # CRIT-2 (IDOR): закрывать можно ТОЛЬКО свою задачу (исполнитель или
        # автор). Раньше explicit-ID шёл без скоупинга → любой привязанный
        # пользователь закрывал чужую задачу по номеру. Скоуп совпадает с
        # веткой search_phrase ниже.
        activity = (await session.execute(
            select(Activity).where(
                Activity.id == task_id_int,
                Activity.kind == "task",
                or_(
                    Activity.responsible_id == user.id,
                    Activity.created_by_id == user.id,
                ),
            )
        )).scalar_one_or_none()
        if not activity:
            return ExecutionResult(
                success=False,
                message=(
                    f"Задача с ID <code>{task_id}</code> не найдена среди ваших задач."
                ),
                action_taken="close_task_not_found",
            )
    elif search_phrase.strip():
        result = await session.execute(
            select(Activity)
            .where(
                Activity.kind == "task",
                Activity.status.in_(["new", "in_progress"]),
                or_(
                    Activity.responsible_id == user.id,
                    Activity.created_by_id == user.id,
                ),
                Activity.title.ilike(f"%{search_phrase.strip()}%"),
            )
            .order_by(Activity.created_at.desc())
            .limit(1)
        )
        activity = result.scalar_one_or_none()
        if not activity:
            return ExecutionResult(
                success=False,
                message=(
                    f"Задача по фразе «{search_phrase}» не найдена среди ваших активных задач. "
                    "Попробуйте указать ID задачи или изменить фразу поиска."
                ),
                action_taken="close_task_not_found",
            )
    else:
        return ExecutionResult(
            success=False,
            message=(
                "Укажите ID задачи или фразу для поиска. Например: "
                "«Закрой задачу <i>про звонок клиенту X</i>»"
            ),
            action_taken="close_task_no_params",
        )

    if activity.status in ("done",) and activity.is_closed:
        return ExecutionResult(
            success=False,
            message=f"Задача <b>{activity.title}</b> уже закрыта.",
            action_taken=f"close_task_already_closed_{activity.id}",
        )

    # Закрываем
    now = datetime.now(UTC)
    activity.status = "done"
    activity.completed_at = now
    activity.completed_by_id = user.id
    activity.is_closed = True
    activity.updated_at = now

    return ExecutionResult(
        success=True,
        message=f"Задача <b>{activity.title}</b> закрыта.",
        action_taken=f"closed_activity_{activity.id}",
        data={"activity_id": activity.id},
    )


# ============ search_tasks ============

async def _execute_search_tasks(
    session: AsyncSession,
    params: dict[str, Any],
    user: User,
) -> ExecutionResult:
    """Поиск задач по фильтрам из params.filters."""
    filters_raw = params.get("filters") or {}
    if not isinstance(filters_raw, dict):
        filters_raw = {}

    status_filter = filters_raw.get("status")
    due_period = filters_raw.get("due_period")
    responsible_name = filters_raw.get("responsible") or "me"

    now = datetime.now(UTC)

    # Строим WHERE
    conditions = [Activity.kind == "task"]

    # Responsible.
    # HIGH (IDOR через NL-фильтр): свободный текст может протащить чужое имя
    # («покажи задачи Ильи»). Просмотр чужих задач разрешаем только тем, кто
    # вправе делегировать (admin/director) — тот же гейт, что в _execute_create_task.
    # Иначе игнорируем запрошенное имя и показываем только свои задачи (fail-safe).
    responsible_user = await resolve_user_by_name(session, responsible_name, user.id)
    if responsible_user and (
        responsible_user.id == user.id or _can_delegate(user)
    ):
        conditions.append(Activity.responsible_id == responsible_user.id)
    else:
        # По умолчанию (или нет прав смотреть чужие) — задачи текущего пользователя.
        conditions.append(Activity.responsible_id == user.id)

    # Status / period
    if due_period == "overdue" or status_filter == "overdue":
        conditions.append(Activity.due_at < now)
        conditions.append(Activity.status.in_(["new", "in_progress"]))
    elif due_period == "today":
        today_start = now.replace(hour=0, minute=0, second=0, microsecond=0)
        today_end = now.replace(hour=23, minute=59, second=59, microsecond=999999)
        conditions.append(Activity.due_at.between(today_start, today_end))
        conditions.append(Activity.status.in_(["new", "in_progress"]))
    elif due_period == "this_week":
        week_start = now - timedelta(days=now.weekday())
        week_start = week_start.replace(hour=0, minute=0, second=0, microsecond=0)
        week_end = week_start + timedelta(days=7)
        conditions.append(Activity.due_at.between(week_start, week_end))
        conditions.append(Activity.status.in_(["new", "in_progress"]))
    elif status_filter in ("new", "in_progress", "done"):
        conditions.append(Activity.status == status_filter)
    else:
        # По умолчанию — активные
        conditions.append(Activity.status.in_(["new", "in_progress"]))

    result = await session.execute(
        select(Activity)
        .where(*conditions)
        .order_by(Activity.due_at.asc().nullslast())
        .limit(10)
    )
    tasks = result.scalars().all()

    if not tasks:
        return ExecutionResult(
            success=True,
            message="Задач по вашему запросу не найдено.",
            action_taken="search_results_count_0",
            data=[],
        )

    lines = [f"Найдено задач: <b>{len(tasks)}</b>\n"]
    for t in tasks:
        due_s = t.due_at.strftime("%d.%m %H:%M") if t.due_at else "—"
        overdue_mark = " ⚠️" if (t.due_at and t.due_at < now and t.status not in ("done",)) else ""
        lines.append(f"• <code>{t.id}</code> {t.title} [{due_s}]{overdue_mark}")

    return ExecutionResult(
        success=True,
        message="\n".join(lines),
        action_taken=f"search_results_count_{len(tasks)}",
        data=[
            {
                "id": t.id,
                "title": t.title,
                "status": t.status,
                "due_at": t.due_at.isoformat() if t.due_at else None,
            }
            for t in tasks
        ],
    )


# ============ recommend ============

async def _execute_recommend(
    session: AsyncSession,
    params: dict[str, Any],
    user: User,
) -> ExecutionResult:
    """Эвристические рекомендации для менеджера."""
    recs = await get_recommendations(session, user)
    if not recs:
        return ExecutionResult(
            success=True,
            message="Всё в порядке! Нет срочных задач и рисков. Отличная работа!",
            action_taken="recommend_no_issues",
            data=[],
        )

    lines = ["<b>Рекомендации:</b>\n"]
    for r in sorted(recs, key=lambda x: x.priority):
        bullet = "🔴" if r.priority == 1 else ("🟡" if r.priority == 2 else "🟢")
        lines.append(f"{bullet} {r.text}")

    return ExecutionResult(
        success=True,
        message="\n".join(lines),
        action_taken=f"recommend_count_{len(recs)}",
        data=[{"text": r.text, "priority": r.priority, "link": r.link} for r in recs],
    )


async def get_recommendations(
    session: AsyncSession,
    user: User,
) -> list[Recommendation]:
    """Собирает эвристические рекомендации.

    Эвристики (в порядке приоритета):
    1. Просроченные задачи → «У вас N просроченных задач»
    2. Горячие сделки без активности 3+ дней
    3. Задачи с сегодняшним дедлайном
    """
    now = datetime.now(UTC)
    recs: list[Recommendation] = []

    # 1. Просроченные задачи
    overdue_count_result = await session.execute(
        select(func.count(Activity.id)).where(
            Activity.kind == "task",
            Activity.responsible_id == user.id,
            Activity.status.in_(["new", "in_progress"]),
            Activity.due_at < now,
        )
    )
    overdue_count = overdue_count_result.scalar() or 0
    if overdue_count > 0:
        recs.append(Recommendation(
            text=f"У вас {overdue_count} просроченных задач. Закройте или перенесите дедлайны.",
            priority=1,
        ))

    # 2. Задачи с дедлайном сегодня (ещё не закрыты)
    today_end = now.replace(hour=23, minute=59, second=59, microsecond=999999)
    today_count_result = await session.execute(
        select(func.count(Activity.id)).where(
            Activity.kind == "task",
            Activity.responsible_id == user.id,
            Activity.status.in_(["new", "in_progress"]),
            Activity.due_at <= today_end,
            Activity.due_at >= now,
        )
    )
    today_count = today_count_result.scalar() or 0
    if today_count > 0:
        recs.append(Recommendation(
            text=f"Сегодня нужно закрыть {today_count} задач.",
            priority=2,
        ))

    # 3. Горячие сделки без активности 3+ дней (только если есть Deal модель)
    try:
        three_days_ago = now - timedelta(days=3)
        hot_deals_result = await session.execute(
            select(Deal)
            .join(
                Activity,
                (Activity.target_type == "deal")
                & (Activity.target_id == Deal.id),
                isouter=True,
            )
            .where(
                Deal.responsible_id == user.id,
                Deal.is_archived == False,  # noqa: E712
            )
            .group_by(Deal.id)
            .having(
                or_(
                    func.max(Activity.created_at) < three_days_ago,
                    func.max(Activity.created_at).is_(None),
                )
            )
            .limit(5)
        )
        hot_deals = hot_deals_result.scalars().all()
        if hot_deals:
            names = ", ".join(d.title or f"#{d.id}" for d in hot_deals[:3])
            suffix = f" (и ещё {len(hot_deals) - 3})" if len(hot_deals) > 3 else ""
            recs.append(Recommendation(
                text=f"Сделки без активности 3+ дней: {names}{suffix}. Добавьте задачу или звонок.",
                priority=2,
            ))
    except Exception:  # noqa: BLE001
        pass  # Если Deal недоступен — просто пропускаем

    return recs
