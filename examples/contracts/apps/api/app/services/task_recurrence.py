"""Эпик 24 — Tasks v2: сервис повторяющихся задач.

Cron-job (запускается утром из app.jobs.automation_cron или отдельного cron)
сканирует шаблоны серий (recurrence_rule != NULL AND recurrence_parent_id IS NULL)
и создаёт следующий экземпляр по правилу.

Модель повторения:
- Шаблон серии: recurrence_parent_id IS NULL, recurrence_rule != NULL
- Экземпляры: recurrence_parent_id = template.id, recurrence_rule = NULL
- При достижении recurrence_until — не создаём новый экземпляр

Функция next_due_at() — pure, тестируется без БД.
"""
from __future__ import annotations

import logging
from datetime import date, timedelta
from decimal import Decimal

from sqlalchemy import and_, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import Activity, ActivityChecklistItem, ActivityCollaborator

logger = logging.getLogger(__name__)

# Поддерживаемые правила повторения.
RECURRENCE_RULES: frozenset[str] = frozenset({"daily", "weekly", "monthly"})


def next_due_at(current_due: date, rule: str) -> date:
    """Вычисляет дату следующего экземпляра по правилу.

    Pure-function — не обращается к БД.

    Args:
        current_due: дата текущего (последнего) экземпляра.
        rule: 'daily' | 'weekly' | 'monthly'

    Returns:
        Дата следующего экземпляра.

    Raises:
        ValueError: если rule не из RECURRENCE_RULES.
    """
    if rule not in RECURRENCE_RULES:
        raise ValueError(f"Unknown recurrence rule: {rule!r}. Expected one of {sorted(RECURRENCE_RULES)}")

    if rule == "daily":
        return current_due + timedelta(days=1)
    if rule == "weekly":
        return current_due + timedelta(weeks=1)
    # monthly: +1 месяц с клэмпом на последний день месяца.
    return _add_one_month(current_due)


def _add_one_month(d: date) -> date:
    """Прибавляет один месяц, клэмпая день на последний в месяце.

    Pure-function.
    """
    year = d.year + (d.month // 12)
    month = (d.month % 12) + 1
    # Последний день нового месяца.
    import calendar
    last_day = calendar.monthrange(year, month)[1]
    day = min(d.day, last_day)
    return date(year, month, day)


def should_create_next_instance(
    template: Activity,
    today: date,
) -> bool:
    """Определяет нужно ли создавать следующий экземпляр сегодня.

    Pure-function.

    Логика:
    - template.due_at должен быть установлен (без дедлайна серия не работает)
    - сегодня >= next_due (т.е. наступил день создания следующей задачи)
    - recurrence_until: если задано и today > until — не создаём

    Args:
        template: шаблонная Activity (recurrence_parent_id IS NULL, recurrence_rule != NULL)
        today: текущая дата (передаётся для тестируемости)

    Returns:
        True если нужно создать экземпляр.
    """
    if not template.recurrence_rule:
        return False
    if not template.due_at:
        return False

    due_date = template.due_at.date() if hasattr(template.due_at, "date") else template.due_at
    next_d = next_due_at(due_date, template.recurrence_rule)

    if template.recurrence_until and today > template.recurrence_until:
        return False

    return today >= next_d


def _copy_activity_for_recurrence(template: Activity, new_due: date) -> Activity:
    """Создаёт копию Activity для нового экземпляра серии.

    Pure (только создаёт объект, не добавляет в session).
    """
    from datetime import datetime, timezone

    due_dt = datetime.combine(new_due, datetime.min.time()).replace(tzinfo=timezone.utc)

    new_activity = Activity(
        kind=template.kind,
        target_type=template.target_type,
        target_id=template.target_id,
        title=template.title,
        body=template.body,
        due_at=due_dt,
        responsible_id=template.responsible_id,
        created_by_id=template.created_by_id,
        priority=template.priority,
        status="new",
        is_closed=False,
        progress_pct=0,
        planned_hours=template.planned_hours,
        tags=list(template.tags) if template.tags else [],
        category_id=template.category_id,
        recurrence_parent_id=template.id,
        color_label=template.color_label,
    )
    return new_activity


async def process_recurrence_templates(
    session: AsyncSession,
    today: date | None = None,
) -> int:
    """Cron-функция: создаёт следующие экземпляры для всех активных серий.

    Сканирует шаблоны (recurrence_rule != NULL AND recurrence_parent_id IS NULL
    AND NOT is_closed) и для каждого создаёт новый экземпляр если наступил срок.

    После создания экземпляра — обновляет шаблон: due_at сдвигается вперёд
    по правилу (чтобы при следующем запуске cron не дублировал).

    Returns:
        Количество созданных экземпляров.
    """
    if today is None:
        today = date.today()

    # Загружаем все активные шаблоны серий.
    result = await session.execute(
        select(Activity).where(
            and_(
                Activity.recurrence_rule.is_not(None),
                Activity.recurrence_parent_id.is_(None),
                Activity.is_closed.is_(False),
            )
        )
    )
    templates = result.scalars().all()

    created_count = 0
    for template in templates:
        try:
            if not should_create_next_instance(template, today):
                continue

            due_date = template.due_at.date() if hasattr(template.due_at, "date") else template.due_at
            new_due = next_due_at(due_date, template.recurrence_rule)

            # Проверяем: не создан ли уже экземпляр с этой датой (idempotency).
            from datetime import datetime, timezone
            new_due_dt = datetime.combine(new_due, datetime.min.time()).replace(tzinfo=timezone.utc)
            existing = await session.execute(
                select(Activity.id).where(
                    Activity.recurrence_parent_id == template.id,
                    Activity.due_at == new_due_dt,
                )
            )
            if existing.scalar_one_or_none():
                # Уже создан — обновляем шаблон и продолжаем.
                _advance_template_due(template, new_due)
                continue

            new_instance = _copy_activity_for_recurrence(template, new_due)
            session.add(new_instance)

            # Сдвигаем due_at шаблона на следующий срок.
            _advance_template_due(template, new_due)

            created_count += 1
        except Exception as exc:  # noqa: BLE001
            logger.error(
                "process_recurrence_templates: error processing template %s: %s",
                template.id,
                exc,
            )

    if created_count:
        await session.commit()
        logger.info("process_recurrence_templates: created %d instances", created_count)

    return created_count


def _advance_template_due(template: Activity, new_due: date) -> None:
    """Обновляет template.due_at на новое значение (inplace, без commit)."""
    from datetime import datetime, timezone
    template.due_at = datetime.combine(new_due, datetime.min.time()).replace(tzinfo=timezone.utc)
