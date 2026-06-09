"""Эпик 24 — Tasks v2: применение дефолтов категории к новой задаче.

apply_category_defaults() вызывается из роутера POST /api/activities при
наличии category_id. Копирует:
- description_template → activity.body (если у задачи нет body)
- auto_title_from_category=True → activity.title = category.name (если нет явного)
- checklist_items → ActivityChecklistItem (в порядке sort_order)
- co_executors / auditors / observers → ActivityCollaborator

Функция НЕ коммитит — caller отвечает за commit (атомарность вместе с Activity).
"""
from __future__ import annotations

import logging

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.orm import selectinload

from app.models import (
    Activity,
    ActivityChecklistItem,
    ActivityCollaborator,
    TaskCategory,
)

logger = logging.getLogger(__name__)


async def get_category_with_defaults(
    session: AsyncSession,
    category_id: int,
) -> TaskCategory | None:
    """Загружает категорию с eager-load всех junction-таблиц."""
    result = await session.execute(
        select(TaskCategory)
        .where(TaskCategory.id == category_id, TaskCategory.is_active.is_(True))
        .options(
            selectinload(TaskCategory.checklist_items),
            selectinload(TaskCategory.co_executor_links),
            selectinload(TaskCategory.auditor_links),
            selectinload(TaskCategory.observer_links),
        )
    )
    return result.scalar_one_or_none()


async def apply_category_defaults(
    session: AsyncSession,
    activity: Activity,
    category_id: int,
) -> Activity:
    """Применяет дефолты категории к Activity.

    Предполагается что activity уже добавлена в session (session.add(activity)),
    но session.commit() ещё не вызван.

    Args:
        session: async SQLAlchemy session
        activity: только что созданная Activity (category_id уже проставлен)
        category_id: ID категории (должна быть активна)

    Returns:
        activity (тот же объект, мутированный inplace)
    """
    category = await get_category_with_defaults(session, category_id)
    if category is None:
        logger.warning(
            "apply_category_defaults: category %s not found or inactive, skipping",
            category_id,
        )
        return activity

    # auto_title_from_category: если title не задан явно — берём из категории.
    # Считаем «не задан явно», если title == '' (пустой) или ==  category.name (уже был).
    if category.auto_title_from_category and not activity.title:
        activity.title = category.name

    # description_template → body (только если body ещё нет).
    if category.description_template and not activity.body:
        activity.body = _render_description_template(
            category.description_template, activity
        )

    # default_executor → responsible_id (только если не задан явно).
    if category.default_executor_user_id and activity.responsible_id is None:
        activity.responsible_id = category.default_executor_user_id

    # Пункты шаблонного чек-листа → ActivityChecklistItem.
    for item in sorted(category.checklist_items, key=lambda i: i.sort_order):
        checklist_item = ActivityChecklistItem(
            activity_id=activity.id,
            title=item.title,
            sort_order=item.sort_order,
        )
        session.add(checklist_item)

    # Участники из категории → ActivityCollaborator.
    _add_collaborators_from_links(session, activity, category.co_executor_links, "co_executor")
    _add_collaborators_from_links(session, activity, category.auditor_links, "auditor")
    _add_collaborators_from_links(session, activity, category.observer_links, "observer")

    return activity


def _render_description_template(template: str, activity: Activity) -> str:
    """Простой рендер шаблона без внешних зависимостей.

    Заменяет {{ title }}, {{ responsible_id }}, {{ target_type }}, {{ target_id }}
    значениями из activity. Jinja2-синтаксис намеренно не используем — нет
    зависимости в requirements, а для базовых подстановок хватает str.replace.

    Pure-function (без IO) — тестируется напрямую.
    """
    result = template
    result = result.replace("{{ title }}", activity.title or "")
    result = result.replace("{{ target_type }}", activity.target_type or "")
    result = result.replace("{{ target_id }}", str(activity.target_id) if activity.target_id else "")
    result = result.replace(
        "{{ responsible_id }}", str(activity.responsible_id) if activity.responsible_id else ""
    )
    return result


def _add_collaborators_from_links(
    session: AsyncSession,
    activity: Activity,
    links: list,
    role: str,
) -> None:
    """Создаёт ActivityCollaborator'ов из junction-ссылок категории."""
    for link in links:
        collab = ActivityCollaborator(
            activity_id=activity.id,
            user_id=link.user_id,
            role=role,
        )
        session.add(collab)
