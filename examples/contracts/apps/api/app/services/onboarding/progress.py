"""Эпик 13: progress — отметка прохождения уроков + soft-gate для bulk-actions.

- get_or_create_progress(session, user, course) — идемпотентно достать/создать row
- mark_lesson_completed(session, user, lesson) — для theory/video отметить пройденным
- recompute_course_status(session, progress, course) — пересчитать percent + status
- enforce_soft_gate(session, user, action) — проверка для bulk-endpoints

Webhook events:
- При смене status → 'completed' дёргаем `course.completed` (inline).
"""
from __future__ import annotations

import logging
from datetime import UTC, datetime
from typing import Iterable

from sqlalchemy import and_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    Course,
    CourseLesson,
    CourseModule,
    CourseProgress,
    User,
    UserCourseAssignment,
)
from app.services.onboarding.courses import (
    compute_course_percent,
    is_course_completed,
)

logger = logging.getLogger(__name__)


# Bulk-действия, на которые distributes soft-gate (overdue mandatory курсы блокируют).
# Расширяется по мере добавления других bulk-actions (см. bulk_tasks.py).
BULK_GATED_ACTIONS: frozenset[str] = frozenset({
    "bulk_generate_documents",
    "bulk_update_fields",
    "bulk_assign_tasks",
})


async def get_or_create_progress(
    session: AsyncSession, user_id: int, course_id: int,
) -> CourseProgress:
    """Идемпотентно достать (или создать) CourseProgress(user, course)."""
    progress = (
        await session.execute(
            select(CourseProgress).where(
                and_(
                    CourseProgress.user_id == user_id,
                    CourseProgress.course_id == course_id,
                )
            )
        )
    ).scalar_one_or_none()
    if progress is not None:
        return progress
    progress = CourseProgress(
        user_id=user_id,
        course_id=course_id,
        status="not_started",
        percent=0,
        lesson_states={},
    )
    session.add(progress)
    await session.flush()
    return progress


async def _required_lesson_ids_for_course(
    session: AsyncSession, course_id: int,
) -> list[int]:
    """Список id required-уроков курса (через modules → lessons)."""
    stmt = (
        select(CourseLesson.id)
        .join(CourseModule, CourseLesson.module_id == CourseModule.id)
        .where(
            CourseModule.course_id == course_id,
            CourseLesson.is_required.is_(True),
        )
    )
    return [row for row in (await session.execute(stmt)).scalars().all()]


async def mark_lesson_completed(
    session: AsyncSession, user: User, lesson: CourseLesson,
) -> CourseProgress:
    """Отметить урок пройденным (для kind='theory' и 'video').

    Для kind='quiz' — это делается через submit_quiz_attempt (если passed).
    Идемпотентно: повторный вызов не меняет completed_at.

    Возвращает CourseProgress после пересчёта status.
    """
    # Найдём course для урока
    module = (
        await session.execute(
            select(CourseModule).where(CourseModule.id == lesson.module_id)
        )
    ).scalar_one_or_none()
    if module is None:
        raise ValueError(f"Module {lesson.module_id} не найден")

    course = (
        await session.execute(
            select(Course).where(Course.id == module.course_id)
        )
    ).scalar_one_or_none()
    if course is None:
        raise ValueError(f"Course {module.course_id} не найден")

    progress = await get_or_create_progress(session, user.id, course.id)

    states = dict(progress.lesson_states or {})
    key = str(lesson.id)
    prev = states.get(key) or {}
    new_state = dict(prev) if isinstance(prev, dict) else {}
    if not new_state.get("completed_at"):
        new_state["completed_at"] = datetime.now(UTC).isoformat()
    new_state.setdefault("attempts_count", 0)
    states[key] = new_state
    progress.lesson_states = states

    await recompute_course_status(session, progress, course)
    return progress


async def recompute_course_status(
    session: AsyncSession,
    progress: CourseProgress,
    course: Course,
) -> CourseProgress:
    """Пересчитать percent + status у progress + emit webhook если completed.

    started_at — заполняется при первом не-нулевом percent.
    completed_at — заполняется при is_course_completed.

    Status переходы:
        not_started → in_progress (когда percent > 0)
        in_progress → completed (когда is_course_completed = True)
        * → overdue (если deadline_at прошёл И status != completed) — НЕ здесь,
            а в отдельном cron (на MVP — вычисляется на лету в /my-courses).

    После перехода → 'completed' эмитим webhook course.completed.
    """
    required_ids = await _required_lesson_ids_for_course(session, course.id)
    percent = compute_course_percent(progress.lesson_states, required_ids)
    completed = is_course_completed(progress.lesson_states, required_ids)

    progress.percent = percent
    if percent > 0 and progress.started_at is None:
        progress.started_at = datetime.now(UTC)

    was_completed = progress.status == "completed"
    if completed:
        progress.status = "completed"
        if progress.completed_at is None:
            progress.completed_at = datetime.now(UTC)
        # Emit webhook только при переходе not-completed → completed
        if not was_completed:
            try:
                from app.services.webhook_dispatcher import safe_dispatch_event
                # NB: вызов в transaction'е caller'а — safe_dispatch_event сам коммитит,
                # это работает потому что мы сами не комитим до этого момента.
                await safe_dispatch_event(
                    session, "course.completed", "course", course.id,
                    {
                        "course_id": course.id,
                        "course_title": course.title,
                        "user_id": progress.user_id,
                        "completed_at": progress.completed_at.isoformat()
                            if progress.completed_at else None,
                        "percent": progress.percent,
                    },
                )
            except Exception as e:  # noqa: BLE001
                logger.warning("dispatch course.completed failed: %s", e)
    elif percent > 0:
        progress.status = "in_progress"
    else:
        progress.status = "not_started"

    return progress


async def get_overdue_mandatory_assignments(
    session: AsyncSession, user_id: int,
) -> list[UserCourseAssignment]:
    """Список mandatory assignments где deadline_at прошёл И курс не completed.

    Используется в enforce_soft_gate и в UI (badge с количеством).
    """
    now = datetime.now(UTC)
    # JOIN assignment с progress (LEFT OUTER если progress нет — это not_started)
    stmt = (
        select(UserCourseAssignment, CourseProgress)
        .outerjoin(
            CourseProgress,
            and_(
                CourseProgress.user_id == UserCourseAssignment.user_id,
                CourseProgress.course_id == UserCourseAssignment.course_id,
            ),
        )
        .where(
            UserCourseAssignment.user_id == user_id,
            UserCourseAssignment.is_mandatory.is_(True),
            UserCourseAssignment.deadline_at.isnot(None),
            UserCourseAssignment.deadline_at < now,
        )
    )
    rows = (await session.execute(stmt)).all()
    overdue: list[UserCourseAssignment] = []
    for assignment, progress in rows:
        # Если progress.status='completed' — не overdue
        if progress is not None and progress.status == "completed":
            continue
        overdue.append(assignment)
    return overdue


async def enforce_soft_gate(
    session: AsyncSession, user: User, action: str,
) -> bool:
    """Soft-gate: если у user есть overdue mandatory курсы С policy='soft_gate' —
    блокирует bulk-действия (action ∈ BULK_GATED_ACTIONS).

    Returns False если блокировать, True если разрешить.

    NB: курсы с completion_policy='informational' НЕ блокируют (это рекомендуемые).
    """
    if action not in BULK_GATED_ACTIONS:
        return True

    overdue = await get_overdue_mandatory_assignments(session, user.id)
    if not overdue:
        return True

    # Грузим courses для проверки completion_policy
    course_ids = [a.course_id for a in overdue]
    if not course_ids:
        return True
    stmt = select(Course).where(
        Course.id.in_(course_ids),
        Course.completion_policy == "soft_gate",
    )
    blocking_courses = (await session.execute(stmt)).scalars().all()
    if not blocking_courses:
        # Все overdue курсы — informational
        return True
    return False


async def compute_assignment_status(
    progress: CourseProgress | None,
    deadline_at: datetime | None,
) -> str:
    """Compute 'live' status для UI: учитывает overdue по deadline.

    Pure-function (без БД). Используется в /my-courses listing.
    """
    if progress is None:
        if deadline_at is not None and deadline_at < datetime.now(UTC):
            return "overdue"
        return "not_started"
    if progress.status == "completed":
        return "completed"
    if deadline_at is not None and deadline_at < datetime.now(UTC):
        return "overdue"
    return progress.status or "not_started"
