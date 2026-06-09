"""Epic 14.2 — Rights transfer service.

Передача прав/задач/контрагентов от одного пользователя другому. Используется
при увольнении (auto-вызов из dismiss endpoint'а) или вручную через
/admin/rights-transfers.

Поддерживаемые категории (whitelist):
- 'contacts' — Counterparty.responsible_user_id + owner_user_id
- 'deals' — Deal.owner_user_id
- 'tasks_assigner' — Activity.created_by_id (кто поставил задачу)
- 'tasks_executor' — Activity.responsible_id (кто выполняет)
- 'approvals' — Approval.user_id (одобряющий)
- 'settings' — (placeholder, пока не имплементировано)

Каждая категория мапится на пару (Model, field_name) в _CATEGORY_MAP.

Pure-function `compute_reversible_until(executed_at)` — для тестов.
"""
from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from typing import Sequence

from sqlalchemy import update
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    Activity,
    Approval,
    Counterparty,
    Deal,
    Lead,
    RightsTransferItem,
    RightsTransferLog,
)

# Сколько дней операция считается обратимой (можно отменить через /revert).
REVERSIBLE_WINDOW_DAYS = 7

# Whitelist категорий с маппингом на (Model, field_name, entity_type_label).
# entity_type_label идёт в RightsTransferItem.entity_type для аудита.
# Один category может разворачиваться в несколько (Model, field) — например
# 'contacts' = Counterparty по 2 полям (responsible + owner). В этом случае
# элемент в _CATEGORY_MAP — list[tuple[Model, field, entity_type]].
_CATEGORY_MAP: dict[str, list[tuple[type, str, str]]] = {
    "contacts": [
        (Counterparty, "responsible_user_id", "counterparty"),
        (Counterparty, "owner_user_id", "counterparty"),
    ],
    "deals": [
        (Deal, "owner_user_id", "deal"),
    ],
    "leads": [
        (Lead, "owner_id", "lead"),
    ],
    "tasks_assigner": [
        (Activity, "created_by_id", "activity"),
    ],
    "tasks_executor": [
        (Activity, "responsible_id", "activity"),
    ],
    "approvals": [
        (Approval, "user_id", "approval"),
    ],
    # 'settings' — на MVP отсутствует, разработка отложена. Логируем категорию,
    # но items не создаём.
    "settings": [],
}


ALLOWED_CATEGORIES = frozenset(_CATEGORY_MAP.keys())


@dataclass(frozen=True)
class TransferOutcome:
    """Pure-DTO результата transfer/revert операции (для тестов)."""

    log_id: int
    items_count: int
    is_reverted: bool


def compute_reversible_until(executed_at: datetime) -> datetime:
    """Граница обратимости = executed_at + REVERSIBLE_WINDOW_DAYS."""
    return executed_at + timedelta(days=REVERSIBLE_WINDOW_DAYS)


def is_reversible(log: RightsTransferLog, now: datetime | None = None) -> bool:
    """Проверка: можно ли откатить.

    Не откатывается если: уже откачен (is_reverted=True) или истёк
    reversible_until (now > reversible_until).
    """
    if log.is_reverted:
        return False
    if log.reversible_until is None:
        return False
    cur = now or datetime.now(UTC)
    # Защита от naive datetime у log.reversible_until (если из БД пришёл
    # без tz): тогда сравним без tz.
    rev = log.reversible_until
    if rev.tzinfo is None and cur.tzinfo is not None:
        cur = cur.replace(tzinfo=None)
    return cur <= rev


def validate_categories(categories: Sequence[str]) -> list[str]:
    """Валидация массива категорий — оставляет только whitelisted.

    На дубликаты не падает, просто dedup'ит. Сохраняет порядок.
    """
    seen = set()
    out: list[str] = []
    for cat in categories:
        if cat not in ALLOWED_CATEGORIES:
            raise ValueError(
                f"Unknown rights_transfer category: {cat!r}. "
                f"Allowed: {sorted(ALLOWED_CATEGORIES)}"
            )
        if cat in seen:
            continue
        seen.add(cat)
        out.append(cat)
    return out


async def _collect_items_for_category(
    session: AsyncSession,
    category: str,
    from_user_id: int,
    to_user_id: int,
) -> list[dict]:
    """Возвращает список dict'ов для RightsTransferItem (без apply UPDATE).

    Чтобы можно было сначала собрать всё, потом одной транзакцией
    апдейтнуть и заINSERTить items.
    """
    items: list[dict] = []
    for Model, field_name, entity_type in _CATEGORY_MAP[category]:
        col = getattr(Model, field_name)
        rows = (
            await session.execute(
                select(Model.id).where(col == from_user_id)
            )
        ).scalars().all()
        for entity_id in rows:
            items.append({
                "entity_type": entity_type,
                "entity_id": entity_id,
                "field_name": field_name,
                "Model": Model,
                "old_owner_user_id": from_user_id,
                "new_owner_user_id": to_user_id,
            })
    return items


async def transfer_rights(
    session: AsyncSession,
    *,
    from_user_id: int,
    to_user_id: int,
    categories: Sequence[str],
    initiated_by_user_id: int,
    reason: str | None = None,
) -> RightsTransferLog:
    """Передаёт права по выбранным категориям + создаёт audit log.

    Атомарно: либо все обновления + log + items, либо ничего (raise → caller
    делает rollback).

    Возвращает свежесозданный RightsTransferLog с populated items.
    """
    cats = validate_categories(categories)
    now = datetime.now(UTC)
    reversible_until = compute_reversible_until(now)

    # 1. Создаём заголовок лога
    log = RightsTransferLog(
        from_user_id=from_user_id,
        to_user_id=to_user_id,
        initiated_by_user_id=initiated_by_user_id,
        categories=cats,
        reason=reason,
        executed_at=now,
        reversible_until=reversible_until,
        is_reverted=False,
    )
    session.add(log)
    await session.flush()  # нужен log.id для items

    # 2. Собираем items + применяем UPDATE'ы по entity
    total_items: list[RightsTransferItem] = []
    for cat in cats:
        items_data = await _collect_items_for_category(
            session, cat, from_user_id, to_user_id,
        )
        for entry in items_data:
            Model = entry.pop("Model")
            # Massive UPDATE по конкретным entity_id (per-id, чтобы не
            # обновить ничего лишнего; и собрать items).
            await session.execute(
                update(Model)
                .where(Model.id == entry["entity_id"])
                .values({entry["field_name"]: to_user_id})
            )
            item = RightsTransferItem(
                transfer_log_id=log.id,
                entity_type=entry["entity_type"],
                entity_id=entry["entity_id"],
                old_owner_user_id=entry["old_owner_user_id"],
                new_owner_user_id=entry["new_owner_user_id"],
                field_name=entry["field_name"],
            )
            session.add(item)
            total_items.append(item)

    await session.flush()
    return log


async def revert_transfer(
    session: AsyncSession,
    *,
    log_id: int,
    reverted_by_user_id: int,
) -> RightsTransferLog:
    """Откат операции. Только если is_reversible(log) = True.

    Поднимает ValueError если откат запрещён (caller трансформирует в 400).
    """
    log = (
        await session.execute(
            select(RightsTransferLog).where(RightsTransferLog.id == log_id)
        )
    ).scalar_one_or_none()
    if log is None:
        raise ValueError("Transfer log not found")
    if not is_reversible(log):
        raise ValueError(
            "Operation not reversible: already reverted or window expired"
        )

    # Восстановим все entity по items (UPDATE entity SET field = old_owner_user_id).
    items = list(
        (
            await session.execute(
                select(RightsTransferItem).where(
                    RightsTransferItem.transfer_log_id == log_id,
                    RightsTransferItem.reverted_at.is_(None),
                )
            )
        )
        .scalars()
        .all()
    )

    # Маппинг entity_type → Model. Для отката используем тот же класс что
    # хранили (но нужен из-за того что в RightsTransferItem только строка).
    entity_to_model = {
        "counterparty": Counterparty,
        "deal": Deal,
        "lead": Lead,
        "activity": Activity,
        "approval": Approval,
    }

    now = datetime.now(UTC)
    for item in items:
        Model = entity_to_model.get(item.entity_type)
        if Model is None:
            # 'setting' и т.п. — на MVP не имплементировано, пропускаем.
            continue
        await session.execute(
            update(Model)
            .where(Model.id == item.entity_id)
            .values({item.field_name: item.old_owner_user_id})
        )
        item.reverted_at = now

    log.is_reverted = True
    log.reverted_at = now
    log.reverted_by_user_id = reverted_by_user_id

    await session.flush()
    return log
