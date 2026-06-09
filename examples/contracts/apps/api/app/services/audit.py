"""Audit log (Эпик 8 / Card 2.0): запись изменений и diff helper.

Generic audit log по сущности (EntityAuditLog) — отдельный от старого AuditLog,
который привязан к contract_id. Этот служит для leads / contacts / companies /
counterparties / deals / contracts / subscriptions.

API:
- compute_diff(before, after, *, ignore_keys=...) → {field: {old, new}}
- log_change(session, *, entity_type, entity_id, user_id, action,
             before=None, after=None, request_id=None) — пишет запись.
  Не делает commit — caller отвечает за транзакцию.

Какие поля логируются — определяет caller через before/after. Стандартный
паттерн в роутере:

    snapshot = _snapshot(entity)
    # ...мутации...
    await session.commit()
    await session.refresh(entity)
    await log_change(session, entity_type="lead", entity_id=entity.id,
                     user_id=current_user.id, action="update",
                     before=snapshot, after=_snapshot(entity))
    await session.commit()
"""
from __future__ import annotations

from datetime import date, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy.ext.asyncio import AsyncSession

from app.models import EntityAuditLog


# Допустимые actions — синхронизировано с frontend и моделью EntityAuditLog.
# fin-actions (post/reverse/lock_period/unlock_period) — мутации финмодуля (Ф1):
# проводка/сторно/закрытие/открытие периода логируются как отдельные действия, а не
# generic update, чтобы аудит-лента бухгалтера читалась как журнал событий.
AUDIT_ACTIONS: tuple[str, ...] = (
    "create",
    "update",
    "delete",
    "merge",
    "extra_fields_change",
    "bulk_action",
    "post",
    "reverse",
    "lock_period",
    "unlock_period",
    # Ф2: жизненный цикл заявок/реестров/согласования.
    "submit",
    "approve_decision",
    "fulfill",
    # Ф5: жизненный цикл инвойсов/актов/вендор-счетов.
    "issue",
    "confirm",
    "pay",
    "cancel",
    # Ф4: признание выручки / переоценка / смена базовой валюты.
    "recognize",
    "revalue",
    "change_base",
)

# Допустимые entity_type. fin_* добавлены в Ф1 (аудит финмутаций — спека J требует
# логировать create/post/reverse/period-lock/manual journal). Posting-движок уже
# пишет posted_by_user_id/created_by_user_id; здесь — лента изменений по сущности.
AUDIT_ENTITY_TYPES: tuple[str, ...] = (
    "lead",
    "contact",
    "company",
    "counterparty",
    "deal",
    "contract",
    "subscription",
    "fin_operation",
    "fin_journal_entry",
    "fin_manual_journal",
    "fin_period_lock",
    "fin_permission",
    # Ф2: реестр/заявки/сценарии/голоса согласования.
    "fin_request",
    "fin_payment_registry",
    "fin_approval_scenario",
    "fin_approval",
    # Ф5: инвойсы/акты/вендор-счета (AR/AP-документы).
    "fin_invoice",
    "fin_act",
    "fin_vendor_bill",
    # Ф4: план признания выручки + задание пересчёта базы.
    "fin_revenue_schedule",
    "fin_base_recompute_job",
)

# Поля, которые НЕ логируем по умолчанию (служебные таймстампы, кеши)
DEFAULT_IGNORE_KEYS: frozenset[str] = frozenset({
    "updated_at",
    "created_at",
    "category_recalc_at",
    "health_computed_at",
    "stage_changed_at",
    "last_run_at",
    "started_at",
    "finished_at",
})


def _serialize_for_diff(v: Any) -> Any:
    """Превратить значение в JSON-friendly для diff'а (даты → строки и т.п.)."""
    if v is None:
        return None
    if isinstance(v, (str, int, float, bool)):
        return v
    if isinstance(v, Decimal):
        return float(v)
    if isinstance(v, (datetime, date)):
        return v.isoformat()
    if isinstance(v, (list, tuple)):
        return [_serialize_for_diff(x) for x in v]
    if isinstance(v, dict):
        return {str(k): _serialize_for_diff(x) for k, x in v.items()}
    return str(v)


def compute_diff(
    before: dict[str, Any] | None,
    after: dict[str, Any] | None,
    *,
    ignore_keys: frozenset[str] | set[str] | None = None,
) -> dict[str, dict[str, Any]]:
    """Сравнить два словаря, вернуть только изменённые поля.

    Формат: {field_name: {"old": ..., "new": ...}}

    - Поля из ignore_keys (default — DEFAULT_IGNORE_KEYS) пропускаются.
    - Если ключ есть только в before — old != null, new = null.
    - Если ключ есть только в after — old = null, new != null.
    - Сравнение — после _serialize_for_diff (даты → строки).
    """
    if before is None:
        before = {}
    if after is None:
        after = {}
    ignore = frozenset(ignore_keys or DEFAULT_IGNORE_KEYS)

    all_keys = set(before.keys()) | set(after.keys())
    result: dict[str, dict[str, Any]] = {}
    for k in all_keys:
        if k in ignore:
            continue
        old_v = _serialize_for_diff(before.get(k))
        new_v = _serialize_for_diff(after.get(k))
        if old_v != new_v:
            result[k] = {"old": old_v, "new": new_v}
    return result


def validate_action(action: str) -> None:
    if action not in AUDIT_ACTIONS:
        raise ValueError(
            f"Недопустимый action: {action}. Ожидается одно из {list(AUDIT_ACTIONS)}"
        )


def validate_entity_type(entity_type: str) -> None:
    if entity_type not in AUDIT_ENTITY_TYPES:
        raise ValueError(
            f"Недопустимый entity_type: {entity_type}. "
            f"Ожидается одно из {list(AUDIT_ENTITY_TYPES)}"
        )


async def log_change(
    session: AsyncSession,
    *,
    entity_type: str,
    entity_id: int,
    user_id: int | None,
    action: str,
    before: dict[str, Any] | None = None,
    after: dict[str, Any] | None = None,
    diff_override: dict[str, Any] | None = None,
    request_id: str | None = None,
    ignore_keys: frozenset[str] | set[str] | None = None,
) -> EntityAuditLog | None:
    """Записать audit-событие в entity_audit_logs.

    - Для action='update' / 'extra_fields_change': если before и after переданы,
      считается diff (compute_diff с ignore_keys). Если diff пустой — НЕ пишем
      запись (чтобы не засорять лог пустыми событиями).
    - Для action='create' / 'delete' / 'merge' / 'bulk_action':
      diff_json = diff_override (если передан), иначе snapshot из after / before.

    НЕ делает commit — caller отвечает за транзакцию (это позволяет атомарно
    откатить event при ошибке основной операции).

    Возвращает созданную запись или None (если diff был пустой для update).
    """
    validate_entity_type(entity_type)
    validate_action(action)

    diff_json: dict[str, Any] | None
    if diff_override is not None:
        diff_json = diff_override
    elif action in ("update", "extra_fields_change"):
        diff = compute_diff(before, after, ignore_keys=ignore_keys)
        if not diff:
            return None
        diff_json = {"fields": diff}
    elif action == "create":
        diff_json = {"snapshot": _serialize_for_diff(after or {})}
    elif action == "delete":
        diff_json = {"snapshot": _serialize_for_diff(before or {})}
    else:
        # merge / bulk_action — caller должен передать diff_override
        diff_json = None

    entry = EntityAuditLog(
        entity_type=entity_type,
        entity_id=entity_id,
        user_id=user_id,
        action=action,
        diff_json=diff_json,
        request_id=request_id,
    )
    session.add(entry)
    return entry


def snapshot_entity(entity: Any, fields: list[str]) -> dict[str, Any]:
    """Снять snapshot выбранных полей entity. Для before/after в роутерах.

    Возвращает {field_name: value} с сериализацией (Decimal/datetime → JSON).
    Поля, которых нет на объекте, пропускаются (не падаем).
    """
    out: dict[str, Any] = {}
    for f in fields:
        if hasattr(entity, f):
            out[f] = _serialize_for_diff(getattr(entity, f))
    return out
