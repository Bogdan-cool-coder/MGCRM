"""Реестр платежей (Ф2, G §Ф2) — батч расходов по одному счёту-источнику.

Чистое ядро — переходы approval_status, валидация состава (все expense + один счёт),
производный payment_status — тестируется без БД. Async-оркестраторы поверх posting-движка
(массовое проведение проводит все expense-операции реестра).

Инварианты (E/F11):
  - состав реестра можно менять ТОЛЬКО в approval_status='draft';
  - после submit ('on_review') состав заморожен — согласование реестра ЗАМЕНЯЕТ
    индивидуальное согласование операций;
  - все операции реестра — расходные (direction='out') и по одному source_account_id;
  - массовое проведение допустимо только из approval_status='approved'.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime
from decimal import Decimal

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import FinOperation, FinPaymentRegistry


class RegistryError(Exception):
    """Ошибка флоу реестра (→ 409/422 в роутере)."""


class RegistryFrozen(RegistryError):
    """Состав реестра заморожен (после submit) — изменение запрещено."""


class RegistryMemberInvalid(RegistryError):
    """Операция не подходит в реестр (не expense / чужой счёт / чужое юрлицо / уже в реестре)."""


# ───────────────────────────── чистое ядро ─────────────────────────────


@dataclass
class MemberRow:
    """Дакти-тип операции-кандидата в реестр (для pure-тестов)."""

    id: int
    legal_entity_id: int
    direction: str
    account_from_id: int | None
    status: str
    registry_id: int | None
    amount: Decimal


def assert_draft_composable(approval_status: str) -> None:
    """Состав реестра меняется только в draft. Pure."""
    if approval_status != "draft":
        raise RegistryFrozen(
            f"Состав реестра можно менять только в черновике (текущий статус: {approval_status!r})"
        )


def validate_member(
    op: MemberRow,
    *,
    registry_id: int,
    legal_entity_id: int,
    source_account_id: int,
) -> None:
    """Проверить, что операция допустима в реестр. Pure. Бросает RegistryMemberInvalid.

    Допустимы: расход (direction='out'), своё юрлицо, тот же счёт-источник, не проведена
    (planned/to_pay/on_hold/draft), не входит в ДРУГОЙ реестр.
    """
    if op.legal_entity_id != legal_entity_id:
        raise RegistryMemberInvalid(
            f"Операция id={op.id} принадлежит другому юрлицу — не может войти в реестр"
        )
    if op.direction != "out":
        raise RegistryMemberInvalid(
            f"Операция id={op.id} не расходная (direction={op.direction!r}) — реестр только для расходов"
        )
    if op.account_from_id != source_account_id:
        raise RegistryMemberInvalid(
            f"Операция id={op.id} по другому счёту-источнику — реестр строится по одному счёту"
        )
    if op.status in ("posted", "reversed", "cancelled", "rejected"):
        raise RegistryMemberInvalid(
            f"Операция id={op.id} в статусе {op.status!r} — нельзя добавить в реестр"
        )
    if op.registry_id is not None and op.registry_id != registry_id:
        raise RegistryMemberInvalid(
            f"Операция id={op.id} уже входит в другой реестр (id={op.registry_id})"
        )


def derive_payment_status(member_statuses: list[str]) -> str:
    """Производный payment_status реестра по статусам позиций. Pure.

    Все posted → 'paid'; хотя бы одна posted (но не все) → 'partial'; иначе → 'new'.
    Пустой состав → 'new'.
    """
    if not member_statuses:
        return "new"
    posted = sum(1 for s in member_statuses if s == "posted")
    if posted == 0:
        return "new"
    if posted == len(member_statuses):
        return "paid"
    return "partial"


def assert_submittable(approval_status: str, member_count: int) -> None:
    """Submit реестра допустим из draft и при непустом составе. Pure."""
    if approval_status != "draft":
        raise RegistryError(
            f"Реестр можно отправить на согласование только из черновика (статус: {approval_status!r})"
        )
    if member_count == 0:
        raise RegistryError("Нельзя отправить пустой реестр на согласование")


def assert_postable(approval_status: str) -> None:
    """Массовое проведение допустимо только из approved. Pure."""
    if approval_status != "approved":
        raise RegistryError(
            f"Провести реестр можно только после согласования (статус: {approval_status!r})"
        )


# ───────────────────────────── async-помощники ─────────────────────────────


async def registry_members(
    session: AsyncSession, registry_id: int
) -> list[FinOperation]:
    """Операции, входящие в реестр (по registry_id)."""
    return list(
        (
            await session.execute(
                select(FinOperation)
                .where(FinOperation.registry_id == registry_id)
                .order_by(FinOperation.id)
            )
        ).scalars().all()
    )


async def refresh_payment_status(
    session: AsyncSession, registry: FinPaymentRegistry
) -> None:
    """Пересчитать производный payment_status по текущему составу."""
    members = await registry_members(session, registry.id)
    registry.payment_status = derive_payment_status([m.status for m in members])
    if registry.payment_status == "paid" and registry.posted_at is None:
        registry.posted_at = datetime.now(UTC)
    await session.flush()


async def total_amount(session: AsyncSession, registry_id: int) -> Decimal:
    """Сумма позиций реестра (в валюте операций; для отчёта UI)."""
    members = await registry_members(session, registry_id)
    return sum((m.amount for m in members), Decimal("0"))
