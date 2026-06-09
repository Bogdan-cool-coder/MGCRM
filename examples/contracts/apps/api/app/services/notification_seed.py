"""Эпик 21.2 — Seed для notification preferences + templates.

Два сидера:
1. `seed_notification_preferences(session)` — для каждого активного юзера
   создаёт записи (user, kind, channel, is_enabled=True) для всех пар
   kind × channel. Insert-missing pattern + advisory-lock.
   Идемпотентно: не дублирует, не перезатирает (если админ уже выключил
   что-то через UI — мы это не трогаем).

2. `seed_notification_templates(session)` — заливает дефолтные Jinja-шаблоны
   для всех kind × channel × locale=ru. Insert-missing по (kind, channel,
   locale). Не перезатирает кастомные шаблоны (`is_active=true` маркер).

Оба вызываются из lifespan через `try/except` чтобы не валить старт api.
"""
from __future__ import annotations

import logging

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    NotificationChannelPreference,
    NotificationTemplate,
    User,
)
from app.services.notification_dispatcher import NOTIFICATION_CHANNELS
from app.services.notifications import NOTIFICATION_KINDS

logger = logging.getLogger(__name__)

# Advisory-lock keys. По нумерации эпика 21.2.
_SEED_LOCK_PREFS = 728_274_212
_SEED_LOCK_TPLS = 728_274_213


# ============ Preferences seeder ============


async def seed_notification_preferences(session: AsyncSession) -> int:
    """Создаёт default preferences для всех (user × kind × channel) пар.

    Существующие пары (например, выключенные админом) НЕ трогаются —
    insert-missing pattern. По умолчанию `is_enabled=True` (full opt-in
    из коробки; юзер сам приглушает шумные каналы через UI).

    Returns: количество вновь добавленных записей.

    Идемпотентно — повторный вызов после старта api ничего не добавит,
    кроме случая, когда появились новые юзеры (новый сотрудник).

    Защита advisory-lock: на scale=2 не плодим дублей.
    """
    await session.execute(
        text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_PREFS}
    )
    try:
        # Все активные юзеры (uvolnennye и инактивные — пропускаем).
        users = (
            await session.execute(
                select(User.id).where(User.is_active.is_(True))
            )
        ).scalars().all()
        if not users:
            return 0

        # Сначала — все существующие пары (user_id, kind, channel) одним SELECT'ом.
        # Это дешевле, чем N×M запросов на existence-check для каждой пары.
        existing_rows = (
            await session.execute(
                select(
                    NotificationChannelPreference.user_id,
                    NotificationChannelPreference.kind,
                    NotificationChannelPreference.channel,
                )
            )
        ).all()
        existing: set[tuple[int, str, str]] = {
            (r[0], r[1], r[2]) for r in existing_rows
        }

        added = 0
        for user_id in users:
            for kind in NOTIFICATION_KINDS:
                for channel in NOTIFICATION_CHANNELS:
                    key = (int(user_id), kind, channel)
                    if key in existing:
                        continue
                    session.add(NotificationChannelPreference(
                        user_id=int(user_id),
                        kind=kind,
                        channel=channel,
                        is_enabled=True,
                    ))
                    added += 1

        if added:
            await session.commit()
        return added
    finally:
        await session.execute(
            text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_PREFS}
        )


# ============ Templates seeder ============


def default_templates_payload() -> list[dict]:
    """Дефолтные Jinja-шаблоны на русском для всех kind × channel.

    Структура каждого spec'а:
        {
            "kind": str,
            "channel": "in_app" | "tg" | "email",
            "locale": "ru",
            "subject": str | None,
            "body_template": str | None,
            "variables": list[dict],  # для UI редактора
        }

    Жанры:
    - in_app: краткий subject + body для UI inbox.
    - tg: только body (markdown-light), без subject.
    - email: subject + полный body с MACRO Global брендом.
    """
    # Общий список переменных для подсказок UI (для kind'ов где они применимы).
    task_vars = [
        {"name": "task.title", "type": "string", "required": True},
        {"name": "task.due_at", "type": "datetime"},
        {"name": "creator.full_name", "type": "string"},
    ]
    deal_vars = [
        {"name": "deal.title", "type": "string", "required": True},
        {"name": "deal.amount", "type": "number"},
        {"name": "deal.currency", "type": "string"},
        {"name": "deal.stage_name", "type": "string"},
    ]
    contract_vars = [
        {"name": "contract.title", "type": "string", "required": True},
        {"name": "contract.id", "type": "integer", "required": True},
    ]
    approval_vars = [
        {"name": "contract.title", "type": "string"},
        {"name": "stage_order", "type": "integer"},
    ]
    course_vars = [
        {"name": "course.title", "type": "string", "required": True},
        {"name": "deadline_at", "type": "datetime"},
    ]
    system_vars = [
        {"name": "title", "type": "string", "required": True},
        {"name": "body", "type": "string"},
    ]

    out: list[dict] = []

    # ============ task_assigned ============
    out.append({
        "kind": "task_assigned", "channel": "in_app", "locale": "ru",
        "subject": "Назначена задача: {{ task.title }}",
        "body_template": (
            "{{ task.title }}{% if creator %} от {{ creator.full_name }}{% endif %}"
            "{% if task.due_at %}\nДедлайн: {{ task.due_at }}{% endif %}"
        ),
        "variables": task_vars,
    })
    out.append({
        "kind": "task_assigned", "channel": "tg", "locale": "ru",
        "subject": None,
        "body_template": (
            "📋 *Новая задача*\n*{{ task.title }}*"
            "{% if task.due_at %}\nДедлайн: {{ task.due_at }}{% endif %}"
            "{% if creator %}\nОт: {{ creator.full_name }}{% endif %}"
        ),
        "variables": task_vars,
    })
    out.append({
        "kind": "task_assigned", "channel": "email", "locale": "ru",
        "subject": "MACRO CRM: Новая задача — {{ task.title }}",
        "body_template": (
            "Здравствуйте.\n\n"
            "На вас назначена новая задача:\n"
            "{{ task.title }}\n"
            "{% if task.due_at %}Дедлайн: {{ task.due_at }}\n{% endif %}"
            "{% if creator %}Поставил: {{ creator.full_name }}\n{% endif %}"
            "\nОткройте задачу в MACRO CRM, чтобы взять её в работу.\n"
        ),
        "variables": task_vars,
    })

    # ============ deal_won ============
    out.append({
        "kind": "deal_won", "channel": "in_app", "locale": "ru",
        "subject": "Сделка выиграна: {{ deal.title }}",
        "body_template": (
            "Сделка «{{ deal.title }}» закрыта успешно."
            "{% if deal.amount %} Сумма: {{ deal.amount }} {{ deal.currency or '' }}.{% endif %}"
        ),
        "variables": deal_vars,
    })
    out.append({
        "kind": "deal_won", "channel": "tg", "locale": "ru",
        "subject": None,
        "body_template": (
            "🎉 *Сделка выиграна*\n*{{ deal.title }}*"
            "{% if deal.amount %}\nСумма: {{ deal.amount }} {{ deal.currency or '' }}{% endif %}"
        ),
        "variables": deal_vars,
    })
    out.append({
        "kind": "deal_won", "channel": "email", "locale": "ru",
        "subject": "MACRO CRM: Поздравляем! Сделка выиграна — {{ deal.title }}",
        "body_template": (
            "Поздравляем!\n\n"
            "Сделка «{{ deal.title }}» закрыта успешно."
            "{% if deal.amount %} Сумма: {{ deal.amount }} {{ deal.currency or '' }}.{% endif %}\n"
            "\nПодробности — в карточке сделки в MACRO CRM.\n"
        ),
        "variables": deal_vars,
    })

    # ============ deal_stage_changed ============ (резерв на будущее)
    # пока не в whitelist NOTIFICATION_KINDS — skip

    # ============ approval_needed ============
    out.append({
        "kind": "approval_needed", "channel": "in_app", "locale": "ru",
        "subject": "Нужно согласование: {{ contract.title or 'договор' }}",
        "body_template": (
            "Договор «{{ contract.title or contract.id }}» ожидает вашего согласования."
            "{% if stage_order is defined %} Этап {{ stage_order + 1 }}.{% endif %}"
        ),
        "variables": approval_vars,
    })
    out.append({
        "kind": "approval_needed", "channel": "tg", "locale": "ru",
        "subject": None,
        "body_template": (
            "🔔 *Нужно согласование*\n"
            "{{ contract.title or 'Договор #' ~ contract.id }}"
            "{% if stage_order is defined %}\nЭтап {{ stage_order + 1 }}{% endif %}"
        ),
        "variables": approval_vars,
    })
    out.append({
        "kind": "approval_needed", "channel": "email", "locale": "ru",
        "subject": "MACRO CRM: Запрос на согласование — {{ contract.title or 'договор' }}",
        "body_template": (
            "Здравствуйте.\n\n"
            "Договор «{{ contract.title or contract.id }}» ожидает вашего согласования.\n"
            "{% if stage_order is defined %}Этап маршрута: {{ stage_order + 1 }}.\n{% endif %}"
            "\nОткройте договор в MACRO CRM, чтобы согласовать или отклонить.\n"
        ),
        "variables": approval_vars,
    })

    # ============ sla_breach ============
    out.append({
        "kind": "sla_breach", "channel": "in_app", "locale": "ru",
        "subject": "Просрочка: {{ target.title }}",
        "body_template": (
            "{{ reason or 'Превышен SLA по объекту.' }}"
        ),
        "variables": [
            {"name": "target.title", "type": "string", "required": True},
            {"name": "reason", "type": "string"},
        ],
    })
    out.append({
        "kind": "sla_breach", "channel": "tg", "locale": "ru",
        "subject": None,
        "body_template": (
            "🚨 *Просрочка SLA*\n*{{ target.title }}*"
            "{% if reason %}\n{{ reason }}{% endif %}"
        ),
        "variables": [
            {"name": "target.title", "type": "string", "required": True},
            {"name": "reason", "type": "string"},
        ],
    })
    out.append({
        "kind": "sla_breach", "channel": "email", "locale": "ru",
        "subject": "MACRO CRM: SLA нарушен — {{ target.title }}",
        "body_template": (
            "Внимание!\n\n"
            "Превышен SLA по объекту «{{ target.title }}»."
            "{% if reason %} Причина: {{ reason }}.{% endif %}\n"
            "\nЗайдите в MACRO CRM и примите меры.\n"
        ),
        "variables": [
            {"name": "target.title", "type": "string", "required": True},
            {"name": "reason", "type": "string"},
        ],
    })

    # ============ course_assigned ============
    out.append({
        "kind": "course_assigned", "channel": "in_app", "locale": "ru",
        "subject": "Назначен курс: {{ course.title }}",
        "body_template": (
            "{{ course.title }}"
            "{% if deadline_at %}\nДедлайн: {{ deadline_at }}{% endif %}"
        ),
        "variables": course_vars,
    })
    out.append({
        "kind": "course_assigned", "channel": "tg", "locale": "ru",
        "subject": None,
        "body_template": (
            "📚 *Назначен курс*\n*{{ course.title }}*"
            "{% if deadline_at %}\nДедлайн: {{ deadline_at }}{% endif %}"
        ),
        "variables": course_vars,
    })
    out.append({
        "kind": "course_assigned", "channel": "email", "locale": "ru",
        "subject": "MACRO CRM: Назначен курс обучения — {{ course.title }}",
        "body_template": (
            "Здравствуйте.\n\n"
            "Вам назначен курс обучения: «{{ course.title }}»."
            "{% if deadline_at %} Срок прохождения: {{ deadline_at }}.{% endif %}\n"
            "\nОткройте раздел «Обучение» в MACRO CRM, чтобы приступить.\n"
        ),
        "variables": course_vars,
    })

    # ============ contract_signed ============
    out.append({
        "kind": "contract_signed", "channel": "in_app", "locale": "ru",
        "subject": "Договор подписан: {{ contract.title or contract.id }}",
        "body_template": "Клиент подписал договор «{{ contract.title or contract.id }}».",
        "variables": contract_vars,
    })
    out.append({
        "kind": "contract_signed", "channel": "tg", "locale": "ru",
        "subject": None,
        "body_template": (
            "✅ *Договор подписан*\n"
            "{{ contract.title or 'Договор #' ~ contract.id }}"
        ),
        "variables": contract_vars,
    })
    out.append({
        "kind": "contract_signed", "channel": "email", "locale": "ru",
        "subject": "MACRO CRM: Договор подписан — {{ contract.title or contract.id }}",
        "body_template": (
            "Здравствуйте.\n\n"
            "Договор «{{ contract.title or contract.id }}» подписан клиентом.\n"
            "\nПодробности — в карточке договора в MACRO CRM.\n"
        ),
        "variables": contract_vars,
    })

    # ============ mention ============
    out.append({
        "kind": "mention", "channel": "in_app", "locale": "ru",
        "subject": "Вас упомянули",
        "body_template": (
            "{{ author.full_name or 'Кто-то' }} упомянул(а) вас"
            "{% if context %} в {{ context }}{% endif %}."
        ),
        "variables": [
            {"name": "author.full_name", "type": "string"},
            {"name": "context", "type": "string"},
        ],
    })
    out.append({
        "kind": "mention", "channel": "tg", "locale": "ru",
        "subject": None,
        "body_template": (
            "💬 *Вас упомянули*\n"
            "{{ author.full_name or 'Кто-то' }} упомянул(а) вас"
            "{% if context %} в {{ context }}{% endif %}."
        ),
        "variables": [
            {"name": "author.full_name", "type": "string"},
            {"name": "context", "type": "string"},
        ],
    })
    out.append({
        "kind": "mention", "channel": "email", "locale": "ru",
        "subject": "MACRO CRM: Вас упомянули в комментарии",
        "body_template": (
            "Здравствуйте.\n\n"
            "{{ author.full_name or 'Кто-то' }} упомянул(а) вас"
            "{% if context %} в {{ context }}{% endif %}.\n"
            "\nОткройте MACRO CRM, чтобы посмотреть подробности.\n"
        ),
        "variables": [
            {"name": "author.full_name", "type": "string"},
            {"name": "context", "type": "string"},
        ],
    })

    # ============ system ============
    out.append({
        "kind": "system", "channel": "in_app", "locale": "ru",
        "subject": "{{ title }}",
        "body_template": "{{ body or '' }}",
        "variables": system_vars,
    })
    out.append({
        "kind": "system", "channel": "tg", "locale": "ru",
        "subject": None,
        "body_template": "📢 *{{ title }}*{% if body %}\n{{ body }}{% endif %}",
        "variables": system_vars,
    })
    out.append({
        "kind": "system", "channel": "email", "locale": "ru",
        "subject": "MACRO CRM: {{ title }}",
        "body_template": "{{ body or title }}",
        "variables": system_vars,
    })

    return out


async def seed_notification_templates(session: AsyncSession) -> int:
    """Заливает дефолтные шаблоны (kind × channel × locale='ru').

    Insert-missing pattern: если шаблон для (kind, channel, locale) уже есть —
    НЕ перезаписываем (админ мог кастомизировать через UI). Идемпотентно.

    Returns: количество вновь добавленных шаблонов.

    Advisory-lock защищает от гонки реплик при первом старте.
    """
    await session.execute(
        text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_TPLS}
    )
    try:
        existing_rows = (
            await session.execute(
                select(
                    NotificationTemplate.kind,
                    NotificationTemplate.channel,
                    NotificationTemplate.locale,
                )
            )
        ).all()
        existing: set[tuple[str, str, str]] = {
            (r[0], r[1], r[2]) for r in existing_rows
        }

        added = 0
        for spec in default_templates_payload():
            key = (spec["kind"], spec["channel"], spec["locale"])
            if key in existing:
                continue
            session.add(NotificationTemplate(
                kind=spec["kind"],
                channel=spec["channel"],
                locale=spec["locale"],
                subject=spec.get("subject"),
                body_template=spec.get("body_template"),
                variables=spec.get("variables"),
                is_active=True,
            ))
            added += 1

        if added:
            await session.commit()
        return added
    finally:
        await session.execute(
            text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_TPLS}
        )
