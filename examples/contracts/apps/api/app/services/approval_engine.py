"""Логика многоэтапного согласования."""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Any

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    Approval,
    ApprovalDecision,
    ApprovalRoute,
    Contract,
    ContractStatus,
)


def normalize_stages(route: ApprovalRoute) -> list[dict[str, Any]]:
    """Возвращает список этапов: из stages если есть, иначе один legacy этап."""
    if route.stages:
        return sorted(route.stages, key=lambda s: s.get("order", 0))
    # Legacy: один этап из approver_user_ids
    if route.approver_user_ids:
        return [{
            "order": 0,
            "name": "Согласование",
            "user_ids": route.approver_user_ids,
            "min_required": route.min_required or 1,
        }]
    return []


async def create_stage_approvals(
    session: AsyncSession,
    contract: Contract,
    stage: dict[str, Any],
    attempt: int,
) -> None:
    """Создаёт pending-approvals для всех аппруверов в этапе."""
    for uid in stage.get("user_ids", []):
        session.add(Approval(
            contract_id=contract.id,
            user_id=int(uid),
            stage_order=stage.get("order", 0),
            attempt=attempt,
            decision=ApprovalDecision.pending,
        ))


def can_decide_contract(actor_id: int | None, author_id: int | None) -> bool:
    """Может ли actor принимать решение по договору, чьим автором является author. Pure.

    B3 WARN-5 (no-self-approval): автор договора НЕ может согласовать/отклонить/вернуть на
    доработку собственный договор — разделение «составил ↔ согласовал», зеркало
    finance.can_decide. Если автор неизвестен (author_id None — напр. SET NULL после
    удаления юзера) — запрета нет (некого защищать), решает только членство в этапе.
    """
    if actor_id is None or author_id is None:
        return True
    return actor_id != author_id


async def current_attempt(session: AsyncSession, contract_id: int) -> int:
    """Текущая попытка согласования (последняя)."""
    rows = (await session.execute(
        select(Approval.attempt).where(Approval.contract_id == contract_id).order_by(Approval.attempt.desc()).limit(1)
    )).first()
    if not rows:
        return 1
    return rows[0]


async def approvals_for_attempt(
    session: AsyncSession,
    contract_id: int,
    attempt: int,
) -> list[Approval]:
    return list((await session.execute(
        select(Approval)
        .where(Approval.contract_id == contract_id, Approval.attempt == attempt)
        .order_by(Approval.stage_order, Approval.id)
    )).scalars().all())


def is_stage_completed(stage: dict[str, Any], approvals: list[Approval]) -> bool:
    """Этап считается пройденным если набрано min_required голосов «согласовано» от уникальных user_id этого этапа."""
    user_ids_in_stage = set(stage.get("user_ids", []))
    approved = {a.user_id for a in approvals if a.stage_order == stage.get("order") and a.decision == ApprovalDecision.approved}
    return len(approved & user_ids_in_stage) >= stage.get("min_required", 1)


def active_stage_index(stages: list[dict[str, Any]], approvals: list[Approval]) -> int:
    """Индекс текущего активного этапа. Если все пройдены — len(stages)."""
    for i, st in enumerate(stages):
        if not is_stage_completed(st, approvals):
            return i
    return len(stages)


async def advance_stage_if_needed(
    session: AsyncSession,
    contract: Contract,
    route: ApprovalRoute,
    attempt: int,
) -> tuple[bool, int]:
    """Если текущий этап пройден — создаёт approvals для следующего.
    Возвращает (advanced, new_active_index)."""
    stages = normalize_stages(route)
    approvals = await approvals_for_attempt(session, contract.id, attempt)
    active = active_stage_index(stages, approvals)
    existing_orders = {a.stage_order for a in approvals}
    if active < len(stages) and stages[active]["order"] not in existing_orders:
        # Этап ещё не создан — создаём
        await create_stage_approvals(session, contract, stages[active], attempt)
        return True, active
    return False, active


async def build_approval_summary(
    session: AsyncSession,
    contract: Contract,
    route: ApprovalRoute | None,
) -> dict[str, Any]:
    """Собирает сводку для карточки договора."""
    attempt = await current_attempt(session, contract.id)
    approvals = await approvals_for_attempt(session, contract.id, attempt)
    stages = normalize_stages(route) if route else []
    active = active_stage_index(stages, approvals) if stages else 0

    stages_payload = []
    for i, st in enumerate(stages):
        st_approvals = [a for a in approvals if a.stage_order == st["order"]]
        stages_payload.append({
            "order": st["order"],
            "name": st.get("name", f"Этап {i + 1}"),
            "user_ids": st.get("user_ids", []),
            "min_required": st.get("min_required", 1),
            "approved": sum(1 for a in st_approvals if a.decision == ApprovalDecision.approved),
            "rejected": sum(1 for a in st_approvals if a.decision == ApprovalDecision.rejected),
            # P2 (audit A3): возврат-на-доработку — отдельный счётчик, не смешиваем
            # с rejected (жёсткое отклонение).
            "needs_rework": sum(1 for a in st_approvals if a.decision == ApprovalDecision.needs_rework),
            "pending": sum(1 for a in st_approvals if a.decision == ApprovalDecision.pending),
            "is_active": i == active and contract.status == ContractStatus.in_review,
        })

    return {
        "current_stage": active,
        "total_stages": len(stages),
        "stages": stages_payload,
        "approvals": approvals,
        "can_resubmit": contract.status in (
            ContractStatus.rejected,
            ContractStatus.needs_rework,
        ),
    }
