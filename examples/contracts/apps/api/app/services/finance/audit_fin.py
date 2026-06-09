"""Аудит финмутаций (Ф1) — тонкая обёртка над services.audit.log_change.

Подключает EntityAuditLog к мутациям модуля «Финансы» (отложенный хвост Ф0): create/
post/reverse операций и ручных журналов, закрытие/открытие периода, изменение матрицы
прав. Снимок ключевых полей сущности фиксируется до/после — для ленты изменений
бухгалтера. НЕ делает commit (caller владеет транзакцией, как и весь audit-механизм).

Фиксируем именно ключевые финполя (суммы/статусы/счёт/контрагент/проводку), служебные
таймстампы игнорируются базовым DEFAULT_IGNORE_KEYS.
"""

from __future__ import annotations

from typing import Any

from sqlalchemy.ext.asyncio import AsyncSession

from app.services.audit import log_change, snapshot_entity

#: Поля операции, попадающие в before/after снимок (значимые для аудита бухгалтера).
OPERATION_FIELDS: list[str] = [
    "number", "legal_entity_id", "direction", "status", "op_type_id",
    "amount", "currency", "to_amount", "account_from_id", "account_to_id",
    "op_date", "due_date", "cashflow_category_id", "counterparty_company_id",
    "vat_rate_id", "vat_amount", "amount_net", "purpose",
    "deal_id", "contract_id", "subscription_id", "journal_entry_id",
    "is_for_management", "rejected_reason",
]

MANUAL_JOURNAL_FIELDS: list[str] = [
    "number", "legal_entity_id", "date", "status", "memo", "journal_entry_id",
]

PERMISSION_FIELDS: list[str] = [
    "role", "user_id", "legal_entity_id", "capability", "allowed",
]

#: Ф2 — поля заявки/реестра/сценария для ленты изменений.
REQUEST_FIELDS: list[str] = [
    "number", "request_type", "legal_entity_id", "requester_user_id", "amount",
    "currency", "op_type_id", "counterparty_company_id", "payee_user_id",
    "cashflow_category_id", "period_year", "period_month", "desired_date",
    "status", "resulting_operation_id", "rejected_reason",
]

REGISTRY_FIELDS: list[str] = [
    "number", "legal_entity_id", "source_account_id", "registry_date", "title",
    "approval_status", "payment_status",
]

SCENARIO_FIELDS: list[str] = [
    "name", "applies_to", "op_type_id", "legal_entity_id", "min_amount",
    "max_amount", "stages", "priority", "is_active",
]

#: Ф5 — поля инвойса/акта/вендор-счёта для ленты изменений.
INVOICE_FIELDS: list[str] = [
    "number", "legal_entity_id", "counterparty_company_id", "issue_date", "due_date",
    "currency", "amount_net", "vat_amount", "amount_gross", "paid_amount", "status",
    "revenue_account_code", "deal_id", "contract_id", "subscription_id", "journal_entry_id",
]

ACT_FIELDS: list[str] = [
    "number", "legal_entity_id", "counterparty_company_id", "invoice_id", "act_date",
    "period_year", "period_month", "currency", "amount_net", "vat_amount", "amount_gross",
    "status", "signed_at",
]

VENDOR_BILL_FIELDS: list[str] = [
    "number", "bill_no", "legal_entity_id", "supplier_company_id", "bill_date", "due_date",
    "currency", "amount_net", "vat_amount", "amount_gross", "paid_amount", "status",
    "expense_account_code", "journal_entry_id",
]

#: Ф4 — поля плана признания выручки / задания пересчёта базы.
REVENUE_SCHEDULE_FIELDS: list[str] = [
    "subscription_id", "legal_entity_id", "counterparty_company_id", "period_year",
    "period_month", "amount_net", "vat_amount", "currency", "revenue_account_code",
    "status", "recognition_key", "recognized_journal_entry_id",
]

BASE_RECOMPUTE_JOB_FIELDS: list[str] = [
    "target_currency", "previous_currency", "status", "total_lines", "processed_lines",
    "skipped_closed", "missing_rate_lines", "error",
]

#: Действия, для которых кладём явный before/after-снимок (не generic diff).
_SNAPSHOT_ACTIONS = (
    "post", "reverse", "lock_period", "unlock_period",
    "submit", "approve_decision", "fulfill",
    # Ф5: выставление/подтверждение/оплата/отмена документов.
    "issue", "confirm", "pay", "cancel",
    # Ф4: признание выручки / переоценка / смена базовой валюты.
    "recognize", "revalue", "change_base",
)


def snapshot_operation(op: Any) -> dict[str, Any]:
    return snapshot_entity(op, OPERATION_FIELDS)


def snapshot_manual_journal(j: Any) -> dict[str, Any]:
    return snapshot_entity(j, MANUAL_JOURNAL_FIELDS)


def snapshot_permission(p: Any) -> dict[str, Any]:
    return snapshot_entity(p, PERMISSION_FIELDS)


def snapshot_request(r: Any) -> dict[str, Any]:
    return snapshot_entity(r, REQUEST_FIELDS)


def snapshot_registry(r: Any) -> dict[str, Any]:
    return snapshot_entity(r, REGISTRY_FIELDS)


def snapshot_scenario(s: Any) -> dict[str, Any]:
    return snapshot_entity(s, SCENARIO_FIELDS)


def snapshot_invoice(i: Any) -> dict[str, Any]:
    return snapshot_entity(i, INVOICE_FIELDS)


def snapshot_act(a: Any) -> dict[str, Any]:
    return snapshot_entity(a, ACT_FIELDS)


def snapshot_vendor_bill(b: Any) -> dict[str, Any]:
    return snapshot_entity(b, VENDOR_BILL_FIELDS)


def snapshot_revenue_schedule(s: Any) -> dict[str, Any]:
    return snapshot_entity(s, REVENUE_SCHEDULE_FIELDS)


def snapshot_base_recompute_job(j: Any) -> dict[str, Any]:
    return snapshot_entity(j, BASE_RECOMPUTE_JOB_FIELDS)


async def log_fin(
    session: AsyncSession,
    *,
    entity_type: str,
    entity_id: int,
    user_id: int | None,
    action: str,
    before: dict[str, Any] | None = None,
    after: dict[str, Any] | None = None,
) -> None:
    """Записать финаудит-событие. Для post/reverse/lock_period/unlock_period —
    кладём снимок (before+after) явным diff_override, т.к. это не generic update.

    create/update/delete обрабатываются стандартной семантикой log_change (diff/
    snapshot). НЕ commit — caller владеет транзакцией.
    """
    if action in _SNAPSHOT_ACTIONS:
        diff_override: dict[str, Any] = {}
        if before is not None:
            diff_override["before"] = before
        if after is not None:
            diff_override["after"] = after
        await log_change(
            session,
            entity_type=entity_type,
            entity_id=entity_id,
            user_id=user_id,
            action=action,
            diff_override=diff_override,
        )
        return
    await log_change(
        session,
        entity_type=entity_type,
        entity_id=entity_id,
        user_id=user_id,
        action=action,
        before=before,
        after=after,
    )
