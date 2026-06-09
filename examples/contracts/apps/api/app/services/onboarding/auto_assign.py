"""Эпик 13: auto-assign — назначить курсы новому юзеру по его role.

ВАЖНО: НЕ использует PipelineAutomation engine. Прямой вызов из POST /users
(сразу после session.commit()) — единая точка интеграции.

Идемпотентно: ON CONFLICT DO NOTHING (UNIQUE (user_id, course_id) на assignments)
— повторный вызов не создаёт дубли.

Emit webhook `course.assigned` после insert каждого assignment (inline,
не блокирует основную операцию через catch).
"""
from __future__ import annotations

import logging
from datetime import UTC, datetime, timedelta

from sqlalchemy import select, text
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import Course, User, UserCourseAssignment

logger = logging.getLogger(__name__)


def _course_matches_role(target_roles: list[str] | None, user_role: str) -> bool:
    """Курс назначается user если target_roles пуст ИЛИ user.role ∈ target_roles."""
    if not target_roles:
        return True
    return user_role in target_roles


async def find_published_courses_for_role(
    session: AsyncSession, user_role: str,
) -> list[Course]:
    """Найти все is_published=True курсы, target_roles которых совпадает с user.role.

    target_roles пуст = курс на ВСЕ роли. Сравнение в Python — JSONB ?| не
    портативен между Postgres и SQLite (для тестов).
    """
    stmt = select(Course).where(Course.is_published.is_(True))
    courses = (await session.execute(stmt)).scalars().all()
    return [c for c in courses if _course_matches_role(c.target_roles, user_role)]


async def assign_default_courses(
    session: AsyncSession,
    user: User,
    *,
    assigned_by_user_id: int | None = None,
) -> list[UserCourseAssignment]:
    """Назначить юзеру все is_published курсы по его role.

    Идемпотентно через ON CONFLICT DO NOTHING (UNIQUE (user_id, course_id)).

    assigned_by_user_id:
    - None → auto-assign (создаётся system'ом, без явного админа)
    - int → ручное назначение из admin UI (например, /courses/{id}/auto-assign-existing)

    deadline_at = NOW() + course.deadline_days.

    NB: caller отвечает за commit. На provoke в роутере users:
        await session.commit()  # сам user
        try:
            await assign_default_courses(session, user)
            await session.commit()
        except Exception as e:
            logger.warning(...)
    """
    courses = await find_published_courses_for_role(session, user.role.value)
    if not courses:
        return []

    now = datetime.now(UTC)
    created: list[UserCourseAssignment] = []
    for course in courses:
        deadline_at = None
        if course.deadline_days and course.deadline_days > 0:
            deadline_at = now + timedelta(days=int(course.deadline_days))

        # PostgreSQL-style INSERT ... ON CONFLICT DO NOTHING. На SQLite (тесты)
        # не работает напрямую — но тестовые сценарии используют моки сессии,
        # поэтому не упрутся в это.
        dialect = session.bind.dialect.name if session.bind is not None else ""
        if dialect == "postgresql":
            stmt = pg_insert(UserCourseAssignment).values(
                user_id=user.id,
                course_id=course.id,
                assigned_at=now,
                assigned_by_user_id=assigned_by_user_id,
                deadline_at=deadline_at,
                is_mandatory=True,
            ).on_conflict_do_nothing(
                index_elements=["user_id", "course_id"],
            ).returning(UserCourseAssignment.id)
            result = await session.execute(stmt)
            inserted_id = result.scalar_one_or_none()
            if inserted_id is None:
                # Конфликт — уже есть assignment, не создаём дубль
                continue
            # Достанем созданный объект для emit webhook
            assignment = (
                await session.execute(
                    select(UserCourseAssignment).where(
                        UserCourseAssignment.id == inserted_id
                    )
                )
            ).scalar_one_or_none()
        else:
            # SQLite fallback: проверка через SELECT, потом insert если нет.
            # Гонок здесь нет — это не прод-путь.
            existing = (
                await session.execute(
                    select(UserCourseAssignment).where(
                        UserCourseAssignment.user_id == user.id,
                        UserCourseAssignment.course_id == course.id,
                    )
                )
            ).scalar_one_or_none()
            if existing:
                continue
            assignment = UserCourseAssignment(
                user_id=user.id,
                course_id=course.id,
                assigned_at=now,
                assigned_by_user_id=assigned_by_user_id,
                deadline_at=deadline_at,
                is_mandatory=True,
            )
            session.add(assignment)
            await session.flush()

        if assignment is None:
            continue
        created.append(assignment)

        # Emit webhook (inline, не блокирует — catch на всё)
        try:
            from app.services.webhook_dispatcher import safe_dispatch_event
            await safe_dispatch_event(
                session, "course.assigned", "course", course.id,
                {
                    "course_id": course.id,
                    "course_title": course.title,
                    "user_id": user.id,
                    "user_email": user.email,
                    "assigned_at": assignment.assigned_at.isoformat()
                        if assignment.assigned_at else None,
                    "deadline_at": assignment.deadline_at.isoformat()
                        if assignment.deadline_at else None,
                    "assigned_by_user_id": assignment.assigned_by_user_id,
                    "is_mandatory": assignment.is_mandatory,
                },
            )
        except Exception as e:  # noqa: BLE001
            logger.warning(
                "dispatch course.assigned failed (user=%s, course=%s): %s",
                user.id, course.id, e,
            )

        # Эпик 21: in-app notification student'у — «вам назначен курс».
        # НЕ заменяет webhook (для внешних подписчиков), параллельно. catch
        # на всё — не валим основной assignment flow.
        try:
            from app.services.notifications import (
                build_course_assigned_notification,
                safe_create_notification,
            )
            notif_data = build_course_assigned_notification(
                course_id=course.id,
                course_title=course.title,
                user_id=user.id,
                deadline_at_iso=(
                    assignment.deadline_at.isoformat()
                    if assignment.deadline_at else None
                ),
            )
            await safe_create_notification(session, **notif_data)
        except Exception as e:  # noqa: BLE001
            logger.warning(
                "course_assigned notification failed (user=%s, course=%s): %s",
                user.id, course.id, e,
            )

    return created
