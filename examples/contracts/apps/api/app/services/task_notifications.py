"""Эпик 24 — Tasks v2: нотификации для задач.

Хуки, которые вызываются из роутера activities при ключевых событиях.
Интегрируются с существующим notifications-сервисом (Эпик 21).

Все функции — async, принимают session. НЕ коммитят сами (caller отвечает).
Используют safe_create_notification (не роняет основную операцию).

Новые kind нотификаций (добавляются в NOTIFICATION_KINDS):
- task_status_changed  — статус задачи изменился
- task_deadline_extend — запрос на продление дедлайна

Существующие kind (переиспользуем):
- task_assigned        — задача назначена (responsible_id)
"""
from __future__ import annotations

import logging
from typing import Any

from sqlalchemy.ext.asyncio import AsyncSession

from app.models import Activity
from app.services.notifications import NOTIFICATION_KINDS, safe_create_notification

logger = logging.getLogger(__name__)

# Расширяем NOTIFICATION_KINDS новыми типами (через monkey-patch tuple).
# Это нужно сделать ДО первого вызова safe_create_notification с этими kind.
_TASK_NOTIFICATION_KINDS = ("task_status_changed", "task_deadline_extend")
for _kind in _TASK_NOTIFICATION_KINDS:
    if _kind not in NOTIFICATION_KINDS:
        # Tuple immutable → пересоздаём. Делаем это один раз при импорте модуля.
        import app.services.notifications as _notif_mod
        _notif_mod.NOTIFICATION_KINDS = NOTIFICATION_KINDS + (_kind,)  # type: ignore[attr-defined]


def build_task_assigned_notification(
    activity: Activity,
    assignee_user_id: int,
) -> dict[str, Any]:
    """Pure-function: строит словарь нотификации «задача назначена».

    Возвращает dict готовый для передачи в create_notification(**payload).
    """
    return {
        "user_id": assignee_user_id,
        "kind": "task_assigned",
        "title": f"Вам назначена задача: {activity.title}",
        "body": activity.body[:200] if activity.body else None,
        "link": f"/activities/{activity.id}",
        "metadata": {
            "activity_id": activity.id,
            "activity_kind": activity.kind,
            "target_type": activity.target_type,
            "target_id": activity.target_id,
        },
    }


def build_task_status_changed_notification(
    activity: Activity,
    recipient_user_id: int,
    old_status: str,
    new_status: str,
) -> dict[str, Any]:
    """Pure-function: строит словарь нотификации «статус задачи изменился»."""
    status_labels = {
        "new": "Новая",
        "in_progress": "В работе",
        "done": "Выполнена",
        "rejected": "Отклонена",
    }
    old_label = status_labels.get(old_status, old_status)
    new_label = status_labels.get(new_status, new_status)
    return {
        "user_id": recipient_user_id,
        "kind": "task_status_changed",
        "title": f"Статус задачи изменён: {activity.title}",
        "body": f"{old_label} → {new_label}",
        "link": f"/activities/{activity.id}",
        "metadata": {
            "activity_id": activity.id,
            "old_status": old_status,
            "new_status": new_status,
        },
    }


def build_deadline_extend_notification(
    activity: Activity,
    recipient_user_id: int,
    requester_name: str,
    reason: str,
) -> dict[str, Any]:
    """Pure-function: строит словарь нотификации «запрос на продление дедлайна»."""
    return {
        "user_id": recipient_user_id,
        "kind": "task_deadline_extend",
        "title": f"Запрос на продление дедлайна: {activity.title}",
        "body": f"{requester_name}: {reason[:200]}",
        "link": f"/activities/{activity.id}",
        "metadata": {
            "activity_id": activity.id,
            "requester": requester_name,
            "reason": reason,
        },
    }


async def on_assigned(
    session: AsyncSession,
    activity: Activity,
) -> None:
    """Нотификации при назначении задачи.

    Уведомляет: responsible_id + co_executors + auditors.
    Использует safe_create_notification — не роняет основную операцию.
    """
    notified: set[int] = set()

    if activity.responsible_id:
        payload = build_task_assigned_notification(activity, activity.responsible_id)
        await safe_create_notification(session, **payload)
        notified.add(activity.responsible_id)

    # co_executors и auditors из collaborators (если уже загружены).
    if hasattr(activity, "collaborators") and activity.collaborators:
        for collab in activity.collaborators:
            if collab.role in ("co_executor", "auditor") and collab.user_id not in notified:
                payload = build_task_assigned_notification(activity, collab.user_id)
                await safe_create_notification(session, **payload)
                notified.add(collab.user_id)


async def on_status_changed(
    session: AsyncSession,
    activity: Activity,
    old_status: str,
    new_status: str,
) -> None:
    """Нотификации при смене статуса задачи.

    Уведомляет: created_by_id + collaborators (кроме самого инициатора).
    """
    recipients: set[int] = set()

    if activity.created_by_id:
        recipients.add(activity.created_by_id)

    if hasattr(activity, "collaborators") and activity.collaborators:
        for collab in activity.collaborators:
            recipients.add(collab.user_id)

    for user_id in recipients:
        payload = build_task_status_changed_notification(
            activity, user_id, old_status, new_status
        )
        await safe_create_notification(session, **payload)


async def on_deadline_extend_requested(
    session: AsyncSession,
    activity: Activity,
    requester_name: str,
    reason: str,
) -> None:
    """Нотификация постановщику при запросе продления дедлайна."""
    if not activity.created_by_id:
        return
    payload = build_deadline_extend_notification(
        activity, activity.created_by_id, requester_name, reason
    )
    await safe_create_notification(session, **payload)
