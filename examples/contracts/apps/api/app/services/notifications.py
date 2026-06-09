"""Эпик 21 — UX Upgrade: in-app notifications service.

Centralized helper, через который все остальные модули dispatch'ат
нотификации в UI inbox юзеру. НЕ заменяет TG-нотификации (они продолжают
ходить параллельно через app.services.telegram).

Архитектура:
- `create_notification(...)` — низкоуровневая фабрика; принимает session
  + все поля и пишет ровно одну строку. Не коммитит — caller отвечает.
- `safe_create_notification(...)` — обёртка, которая catch'ит любую
  ошибку (например, у юзера #N теперь нет id → FK error) и просто
  логирует. Используется в integration points (deals, approvals,
  onboarding) — мы НЕ хотим, чтобы упавшая нотификация уронила
  основную бизнес-операцию (создание задачи / переход этапа).
- `build_*_notification(...)` — pure-function builders по kind'у:
  принимают сущности (task, deal, approval, course) и возвращают
  словарь {user_id, kind, title, body, link, metadata}. Pure → удобно
  тестировать без БД и переиспользовать.

Whitelist kind'ов держим в коде (NOTIFICATION_KINDS), НЕ в БД CHECK —
чтобы добавление нового типа было быстрее.
"""
from __future__ import annotations

import logging
from typing import Any

from sqlalchemy import desc, func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import Notification

logger = logging.getLogger(__name__)

# Whitelist допустимых kind. Pure данные для UI-фильтров и тестов.
# При добавлении нового kind:
# 1) расширь tuple;
# 2) добавь build_<kind>_notification если нужен helper;
# 3) обнови frontend types в lib/api/types.ts.
NOTIFICATION_KINDS: tuple[str, ...] = (
    "task_assigned",     # назначена задача (Activity kind=task с responsible_id)
    "deal_won",          # сделка перешла в is_won-этап (для owner_user_id)
    "approval_needed",   # юзер добавлен как approver на этапе договора
    "sla_breach",        # просрочена дедлайн-задача / договор (cron-зона)
    "course_assigned",   # назначен онбординг-курс (Эпик 13)
    "contract_signed",   # договор подписан (для author)
    "mention",           # @упоминание в заметке/комментарии (резерв)
    "system",            # системное сообщение от админа / релиз-ноут
)


# ============ Core API ============

async def create_notification(
    session: AsyncSession,
    user_id: int,
    kind: str,
    title: str,
    body: str | None = None,
    link: str | None = None,
    metadata: dict[str, Any] | None = None,
) -> Notification:
    """Создать нотификацию для юзера.

    НЕ коммитит — caller отвечает (обычно нотификации идут в той же
    транзакции, что и основная операция: создание задачи, переход
    этапа, подписание договора). Это сохраняет атомарность: или всё
    зафиксируется, или ничего.

    kind проверяется на whitelist (NOTIFICATION_KINDS) — если незнаком,
    бросаем ValueError. Тех. подсказка caller'у: «добавь kind в whitelist».

    title ограничен 256 chars (БД-лимит). body / link — без лимита (TEXT).
    metadata — JSONB-словарь, любая extension data per-kind.
    """
    if kind not in NOTIFICATION_KINDS:
        raise ValueError(
            f"Unknown notification kind: {kind!r}. "
            f"Add to NOTIFICATION_KINDS or use 'system'."
        )
    notif = Notification(
        user_id=user_id,
        kind=kind,
        title=title[:256],
        body=body,
        link=link,
        meta=metadata,
    )
    session.add(notif)
    await session.flush()
    return notif


async def safe_create_notification(
    session: AsyncSession,
    user_id: int | None,
    kind: str,
    title: str,
    body: str | None = None,
    link: str | None = None,
    metadata: dict[str, Any] | None = None,
) -> Notification | None:
    """Catch-all обёртка для integration points.

    Возвращает Notification если создано, None если skipped/failed.
    Skip без exception если:
    - user_id is None (например, у сделки нет owner) — это норма,
      не повод ронять stage transition;
    - user_id is 0 или отрицательный (бессмыслица).

    На любой Exception (включая FK violation, проблемы с БД) логирует
    и возвращает None — НЕ пробрасывает наверх.
    """
    if user_id is None or user_id <= 0:
        return None
    try:
        return await create_notification(
            session, user_id, kind, title, body, link, metadata,
        )
    except Exception as e:  # noqa: BLE001
        logger.warning(
            "safe_create_notification failed (user=%s, kind=%s): %s",
            user_id, kind, e,
        )
        return None


# ============ Reads ============

async def list_notifications_for_user(
    session: AsyncSession,
    user_id: int,
    limit: int = 20,
    offset: int = 0,
    unread_only: bool = False,
) -> tuple[list[Notification], int, int]:
    """Постраничный список нотификаций юзера.

    Возвращает (items, unread_count, total_count).
    items отсортированы по created_at DESC.
    total — общее число нотификаций (без фильтра unread_only),
    unread_count — число непрочитанных всегда (нужно для badge).
    """
    base_q = select(Notification).where(Notification.user_id == user_id)
    if unread_only:
        items_q = base_q.where(Notification.is_read.is_(False))
    else:
        items_q = base_q
    items_q = items_q.order_by(desc(Notification.created_at)).limit(limit).offset(offset)
    items = (await session.execute(items_q)).scalars().all()

    # Counts — отдельные count(*) запросы. Покрываются idx_notifications_user.
    total = (
        await session.execute(
            select(func.count()).select_from(Notification).where(
                Notification.user_id == user_id,
            )
        )
    ).scalar_one()
    unread = (
        await session.execute(
            select(func.count()).select_from(Notification).where(
                Notification.user_id == user_id,
                Notification.is_read.is_(False),
            )
        )
    ).scalar_one()
    return list(items), int(unread), int(total)


async def get_unread_count(session: AsyncSession, user_id: int) -> int:
    """Дешёвый count для badge в шапке. Покрыт idx_notifications_user."""
    result = await session.execute(
        select(func.count()).select_from(Notification).where(
            Notification.user_id == user_id,
            Notification.is_read.is_(False),
        )
    )
    return int(result.scalar_one())


# ============ Pure-function builders (для тестов и helper-вызовов) ============

# Все builders возвращают одинаковый shape:
# {user_id: int, kind: str, title: str, body: str|None,
#  link: str|None, metadata: dict|None}
# Можно прямо передать в create_notification(**builder_result(...)).


def build_task_assigned_notification(
    task_id: int,
    task_title: str,
    responsible_user_id: int,
    target_type: str | None = None,
    target_id: int | None = None,
    due_at_iso: str | None = None,
) -> dict[str, Any]:
    """Назначена задача (Activity kind=task с responsible_id).

    Link ведёт в target (deal/lead/subscription/counterparty) если он
    есть, иначе на общую страницу задач — фронт разрулит.
    """
    link: str | None = None
    if target_type and target_id:
        link = _target_link(target_type, target_id)
    body = task_title[:200]
    if due_at_iso:
        body = f"{body}\nСрок: {due_at_iso[:10]}"
    return {
        "user_id": responsible_user_id,
        "kind": "task_assigned",
        "title": f"Назначена задача: {task_title[:200]}",
        "body": body,
        "link": link,
        "metadata": {
            "task_id": task_id,
            "target_type": target_type,
            "target_id": target_id,
            "due_at": due_at_iso,
        },
    }


def build_deal_won_notification(
    deal_id: int,
    deal_title: str,
    owner_user_id: int,
    amount: float | None = None,
    currency: str | None = None,
) -> dict[str, Any]:
    """Сделка перешла в is_won этап. Notif идёт owner'у (поздравление).

    metadata хранит amount/currency для возможной аналитики.
    """
    amount_str = ""
    if amount is not None and currency:
        amount_str = f" ({_format_money(amount)} {currency})"
    return {
        "user_id": owner_user_id,
        "kind": "deal_won",
        "title": f"Сделка выиграна: {deal_title[:200]}{amount_str}",
        "body": None,
        "link": f"/deals/{deal_id}",
        "metadata": {
            "deal_id": deal_id,
            "amount": amount,
            "currency": currency,
        },
    }


def build_approval_needed_notification(
    approval_id: int,
    contract_id: int,
    contract_title: str | None,
    approver_user_id: int,
    stage_order: int | None = None,
) -> dict[str, Any]:
    """Юзера добавили approver'ом на этапе договора — нужно зайти и согласовать."""
    title = (
        f"Нужно согласование: {contract_title[:200]}"
        if contract_title
        else f"Нужно согласование договора #{contract_id}"
    )
    return {
        "user_id": approver_user_id,
        "kind": "approval_needed",
        "title": title,
        "body": f"Этап {stage_order + 1}" if stage_order is not None else None,
        "link": f"/contracts/{contract_id}",
        "metadata": {
            "approval_id": approval_id,
            "contract_id": contract_id,
            "stage_order": stage_order,
        },
    }


def build_sla_breach_notification(
    target_type: str,
    target_id: int,
    target_title: str,
    owner_user_id: int,
    breach_reason: str = "deadline_passed",
) -> dict[str, Any]:
    """Просрочка по сделке / задаче / договору. Cron-зона."""
    return {
        "user_id": owner_user_id,
        "kind": "sla_breach",
        "title": f"Просрочка: {target_title[:200]}",
        "body": breach_reason,
        "link": _target_link(target_type, target_id),
        "metadata": {
            "target_type": target_type,
            "target_id": target_id,
            "reason": breach_reason,
        },
    }


def build_course_assigned_notification(
    course_id: int,
    course_title: str,
    user_id: int,
    deadline_at_iso: str | None = None,
) -> dict[str, Any]:
    """Назначен курс онбординга (Эпик 13)."""
    body = course_title[:200]
    if deadline_at_iso:
        body = f"{body}\nСрок: {deadline_at_iso[:10]}"
    return {
        "user_id": user_id,
        "kind": "course_assigned",
        "title": f"Назначен курс: {course_title[:200]}",
        "body": body,
        "link": f"/courses/{course_id}/play",
        "metadata": {
            "course_id": course_id,
            "deadline_at": deadline_at_iso,
        },
    }


def build_contract_signed_notification(
    contract_id: int,
    contract_title: str | None,
    author_user_id: int,
) -> dict[str, Any]:
    """Договор подписан клиентом — author видит финальный статус."""
    title = (
        f"Договор подписан: {contract_title[:200]}"
        if contract_title
        else f"Договор #{contract_id} подписан"
    )
    return {
        "user_id": author_user_id,
        "kind": "contract_signed",
        "title": title,
        "body": None,
        "link": f"/contracts/{contract_id}",
        "metadata": {"contract_id": contract_id},
    }


def build_system_notification(
    user_id: int,
    title: str,
    body: str | None = None,
    link: str | None = None,
) -> dict[str, Any]:
    """Системное сообщение от админа / релиз-ноут / напоминание."""
    return {
        "user_id": user_id,
        "kind": "system",
        "title": title[:256],
        "body": body,
        "link": link,
        "metadata": None,
    }


# ============ Internal helpers ============

def _target_link(target_type: str, target_id: int) -> str | None:
    """Map target_type → frontend route. Возвращает relative URL.

    Pure-функция, чтобы тесты не дёргали БД. Если target_type незнаком,
    возвращает None — frontend просто не сделает link clickable.
    """
    routes = {
        "deal": f"/deals/{target_id}",
        "lead": f"/leads/{target_id}",
        "subscription": f"/registry/{target_id}",
        "counterparty": f"/clients/{target_id}",
        "company": f"/companies/{target_id}",
        "contract": f"/contracts/{target_id}",
        "course": f"/courses/{target_id}/play",
    }
    return routes.get(target_type)


def _format_money(amount: float | int) -> str:
    """Простой форматтер с разделителями тысяч (через `_format_money(1234567)` → '1 234 567').

    NB: используется только в title нотификации (не в БД-полях), точность
    флоата нам тут не критична. Если будут проблемы — переключим на Decimal.
    """
    try:
        intval = int(round(float(amount)))
    except (ValueError, TypeError):
        return str(amount)
    s = f"{intval:,}".replace(",", " ")
    return s
