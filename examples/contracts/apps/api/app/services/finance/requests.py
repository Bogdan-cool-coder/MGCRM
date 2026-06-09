"""Заявки менеджеров (Ф2, G §6) — флоу draft→submit→approve→fulfill→операция.

Чистое ядро — переходы статусов (`assert_*`, валидаторы) тестируются без БД.
Async-оркестраторы (`submit_request`, `fulfill_request`) поверх движка согласования
(fin_approval) и posting-движка (posting_db). Конвертация approved-заявки в операцию:
создаётся expense-`fin_operation` (cash_out), проводится штатно, заявка → paid,
resulting_operation_id проставлен (идемпотентно: повторный fulfill запрещён).
"""

from __future__ import annotations

from datetime import UTC, date, datetime

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import FinOperation, FinOpType, FinRequest

# Терминальные статусы заявки (нельзя править/отзывать дальше).
_TERMINAL = ("paid", "cancelled")
# Статусы, после которых заявка иммутабельна (правка запрещена).
_IMMUTABLE_FOR_EDIT = ("submitted", "approved", "rejected", "paid", "cancelled")


class RequestError(Exception):
    """Ошибка флоу заявки (→ 409/422 в роутере)."""


class RequestImmutable(RequestError):
    """Заявка в статусе, не допускающем эту операцию."""


def assert_editable(status: str) -> None:
    """Править/удалять можно только draft-заявку. Pure."""
    if status in _IMMUTABLE_FOR_EDIT:
        raise RequestImmutable(
            f"Заявка в статусе {status!r} иммутабельна — правка запрещена (только отзыв до submit)"
        )


def assert_submittable(status: str) -> None:
    """Submit допустим только из draft. Pure."""
    if status != "draft":
        raise RequestImmutable(
            f"Заявку можно подать на согласование только из черновика (текущий статус: {status!r})"
        )


def assert_fulfillable(status: str, resulting_operation_id: int | None) -> None:
    """Конвертировать в операцию можно только approved-заявку без уже созданной операции. Pure."""
    if status != "approved":
        raise RequestImmutable(
            f"Заявку можно провести только после согласования (текущий статус: {status!r})"
        )
    if resulting_operation_id is not None:
        raise RequestImmutable(
            "Заявка уже конвертирована в операцию (resulting_operation_id заполнен) — повтор запрещён"
        )


def assert_cancellable(status: str) -> None:
    """Отменить можно draft/submitted/approved (не paid/cancelled/rejected). Pure."""
    if status in ("paid", "cancelled", "rejected"):
        raise RequestImmutable(
            f"Заявку в статусе {status!r} нельзя отменить"
        )


async def build_operation_from_request(
    session: AsyncSession,
    req: FinRequest,
    *,
    account_from_id: int,
    op_date: date | None,
    op_type_id: int | None,
    created_by_user_id: int | None,
) -> FinOperation:
    """Создать draft expense-операцию из approved-заявки (НЕ проводит).

    Тип берётся: переданный op_type_id → op_type заявки → требуется явно. Шаблон op_type
    должен быть cash_out (расход). Денежный счёт-источник — account_from_id. Заявка
    связана обратной ссылкой fin_request_id; source='request' для идемпотентности.
    """
    chosen_op_type_id = op_type_id or req.op_type_id
    if chosen_op_type_id is None:
        raise RequestError(
            "Не указан тип операции (op_type_id) для проведения заявки — невозможно выбрать "
            "posting_template"
        )
    op_type = (
        await session.execute(
            select(FinOpType).where(FinOpType.id == chosen_op_type_id)
        )
    ).scalar_one_or_none()
    if op_type is None:
        raise RequestError(f"Тип операции id={chosen_op_type_id} не найден")
    if op_type.posting_template != "cash_out":
        raise RequestError(
            f"Тип операции {op_type.code!r} имеет шаблон {op_type.posting_template!r}, "
            "а заявка проводится только расходной операцией (cash_out)"
        )

    op = FinOperation(
        legal_entity_id=req.legal_entity_id,
        op_type_id=chosen_op_type_id,
        direction="out",
        status="draft",
        op_date=op_date or req.desired_date or date.today(),
        due_date=req.desired_date,
        amount=req.amount,
        currency=req.currency,
        account_from_id=account_from_id,
        cashflow_category_id=req.cashflow_category_id,
        counterparty_company_id=req.counterparty_company_id,
        purpose=req.description,
        fin_request_id=req.id,
        source="request",
        source_ref_id=req.id,
        created_by_user_id=created_by_user_id,
    )
    session.add(op)
    await session.flush()
    return op


def mark_submitted(req: FinRequest) -> None:
    req.status = "submitted"
    req.submitted_at = datetime.now(UTC)


def mark_approved(req: FinRequest) -> None:
    req.status = "approved"
    req.decided_at = datetime.now(UTC)


def mark_rejected(req: FinRequest, reason: str | None) -> None:
    req.status = "rejected"
    req.rejected_reason = reason
    req.decided_at = datetime.now(UTC)


def mark_paid(req: FinRequest, operation_id: int) -> None:
    req.status = "paid"
    req.resulting_operation_id = operation_id
