"""Движок согласования финмодуля (Ф2, решение G §4).

Полиморфное согласование операций / реестров / заявок под НАСТРАИВАЕМЫЕ сценарии
(`fin_approval_scenario`), привязанные к типу операции (`op_type`) + опц. юрлицо +
порог суммы. Этапы сценария хранятся JSON в формате `ApprovalRoute.stages`
(`[{order, name, user_ids, min_required, mode}]`) — паттерн переиспользован из
`approval_engine.py`, НО контрактный путь (`Approval.contract_id`) НЕ трогается:
здесь отдельная таблица `fin_approval` с ключом (approvable_kind, approvable_id).

Архитектура как и весь финмодуль: ЧИСТОЕ ЯДРО (выбор сценария, прохождение этапов,
агрегаты) тестируется без БД; async-обёртки (`select_scenario`, `start_approval`,
`record_decision`) — тонкие оркестраторы поверх чистых функций.

Режим этапа `mode`:
  - "all"  — нужны голоса ВСЕХ аппруверов этапа (min_required = len(user_ids));
  - "any"  — достаточно `min_required` голосов (default 1).

Один reject в активном этапе → весь target отклонён (как контрактный путь).
"""

from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal
from typing import Any

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import (
    FinApproval,
    FinApprovalScenario,
)

#: Допустимые цели согласования (полиморфизм G §4 — 4 стабильные цели).
APPROVABLE_KINDS: tuple[str, ...] = ("operation", "registry", "request", "invoice")

#: Режимы этапа.
STAGE_MODES: tuple[str, ...] = ("any", "all")


class ApprovalError(Exception):
    """Базовая ошибка движка согласования (→ 422/409 в роутере)."""


class NoScenarioMatched(ApprovalError):
    """Не найден сценарий под op_type+entity+сумму — согласование невозможно."""


class AlreadyDecided(ApprovalError):
    """Голос уже отдан / этап завершён / target в терминальном статусе."""


class NotAnApprover(ApprovalError):
    """Пользователь не входит в активный этап (не может голосовать)."""


class SelfApproval(ApprovalError):
    """Автор цели согласования голосует по своей же заявке/реестру (запрещено, → 403)."""


def journal_gross_debit(lines: list[Any]) -> Decimal:
    """Сумма-порог ручного журнала = Σ дебетовых (side='dt') строк. Pure.

    Для сбалансированной проводки Σ dt == Σ kt == «величина» проводки. Используется
    гейтом B7 CRIT-2 как `amount` для подбора operation-сценария согласования. Строки
    разных валют складываются по номиналу (упрощение порога; точная func-конвертация —
    забота движка при фактической проводке). `lines` — любые объекты с .side и .amount.
    """
    total = Decimal("0")
    for ln in lines:
        if getattr(ln, "side", None) == "dt":
            total += Decimal(ln.amount)
    return total


def scenario_requires_signoff(stages: list[dict[str, Any]] | None) -> bool:
    """Требует ли сценарий реальной подписи (хотя бы один валидный этап). Pure.

    Используется гейтом прямых операций/журналов (B7 CRIT-2): если под цель найден
    сценарий, у которого ЕСТЬ валидные этапы → проводить мгновенно нельзя, операция
    уходит в согласование. Пустые/невалидные этапы (normalize даёт []) = авто-апрув →
    можно проводить сразу (поведение совпадает со start_approval='approved').
    """
    return bool(normalize_stages(stages))


def can_decide(actor_id: int | None, creator_id: int | None) -> bool:
    """Может ли actor голосовать по цели, созданной creator. Pure (CRITICAL #2).

    Запрещено согласовывать собственную заявку/реестр (разделение «деньги ↔ власть»).
    Если автор неизвестен (creator_id None — напр. SET NULL после удаления юзера) —
    запрета нет (некого защищать), решает только членство в этапе.
    """
    if actor_id is None or creator_id is None:
        return True
    return actor_id != creator_id


# ───────────────────────────── чистое ядро: stages ─────────────────────────────


@dataclass
class StageDecision:
    """Лёгкий дакти-тип голоса для pure-тестов (зеркало FinApproval-полей)."""

    user_id: int
    stage_order: int
    decision: str  # pending|approved|rejected


def normalize_stages(stages: list[dict[str, Any]] | None) -> list[dict[str, Any]]:
    """Нормализует JSON этапов: сортировка по order, дефолты mode/min_required. Pure.

    Каждый этап → {order, name, user_ids[list[int]], min_required, mode}. Для mode='all'
    min_required переопределяется на число аппруверов (нужны все).

    Защитно (WARNING #4): этап с пустым user_ids ПРОПУСКАЕТСЯ (он не блокирует согласование
    и не может быть пройден — иначе зависший этап). min_required клампится в [1, len].
    Сам факт «у сценария нет валидных этапов» решается выше (validate_stages / submit).
    """
    if not stages:
        return []
    out: list[dict[str, Any]] = []
    for st in sorted(stages, key=lambda s: s.get("order", 0)):
        user_ids = [int(u) for u in st.get("user_ids", [])]
        if not user_ids:
            # этап без согласантов не имеет смысла и зависнет — отбрасываем
            continue
        mode = st.get("mode", "any")
        if mode not in STAGE_MODES:
            mode = "any"
        if mode == "all":
            min_required = len(user_ids)
        else:
            min_required = int(st.get("min_required", 1) or 1)
            # кламп в допустимый диапазон [1, число согласантов]
            min_required = max(1, min(min_required, len(user_ids)))
        out.append({
            "order": int(st.get("order", 0)),
            "name": st.get("name") or "Согласование",
            "user_ids": user_ids,
            "min_required": min_required,
            "mode": mode,
        })
    return out


def validate_stages(stages: list[dict[str, Any]] | None) -> list[str]:
    """Жёсткая валидация этапов сценария (WARNING #4). Pure. Возвращает список ошибок.

    Требования: непустой список; каждый этап — len(user_ids) >= 1 И
    1 <= min_required <= len(user_ids); order уникальны и последовательны (0,1,2,…).
    Пустой результат = валидно. Используется pydantic-валидатором схемы и защитно
    в submit. Раздельно от normalize_stages (та «чинит» молча для рантайма).
    """
    errs: list[str] = []
    if not stages:
        return ["Сценарий согласования должен содержать хотя бы один этап"]
    orders: list[int] = []
    for i, st in enumerate(stages):
        user_ids = list(st.get("user_ids", []))
        if len(user_ids) < 1:
            errs.append(f"Этап #{i}: нужен хотя бы один согласант (user_ids)")
        mode = st.get("mode", "any")
        if mode == "all":
            min_required = len(user_ids)
        else:
            min_required = int(st.get("min_required", 1) or 0)
        if user_ids and not (1 <= min_required <= len(user_ids)):
            errs.append(
                f"Этап #{i}: min_required должен быть 1..{len(user_ids)} "
                f"(получено {min_required})"
            )
        orders.append(int(st.get("order", i)))
    if len(set(orders)) != len(orders):
        errs.append("Поля order этапов должны быть уникальны")
    elif sorted(orders) != list(range(len(orders))):
        errs.append("Поля order этапов должны быть последовательны от 0 (0,1,2,…)")
    return errs


def stage_state(stage: dict[str, Any], decisions: list[StageDecision]) -> str:
    """Статус этапа по голосам: rejected | approved | pending. Pure.

    rejected — есть хотя бы один reject среди аппруверов этапа (фейл-фаст);
    approved — набрано min_required голосов approved от уникальных аппруверов этапа;
    иначе pending.
    """
    members = set(stage["user_ids"])
    order = stage["order"]
    rel = [d for d in decisions if d.stage_order == order and d.user_id in members]
    if any(d.decision == "rejected" for d in rel):
        return "rejected"
    approved = {d.user_id for d in rel if d.decision == "approved"}
    if len(approved) >= stage["min_required"]:
        return "approved"
    return "pending"


def overall_state(
    stages: list[dict[str, Any]], decisions: list[StageDecision]
) -> tuple[str, int]:
    """Итог согласования: (status, active_index). Pure.

    Проходим этапы по порядку. Любой rejected → ("rejected", i). Первый pending →
    ("pending", i). Все approved → ("approved", len(stages)). Пустые stages →
    ("approved", 0) (нет этапов = автосогласовано).
    """
    norm = normalize_stages(stages)
    if not norm:
        return ("approved", 0)
    for i, st in enumerate(norm):
        s = stage_state(st, decisions)
        if s == "rejected":
            return ("rejected", i)
        if s == "pending":
            return ("pending", i)
    return ("approved", len(norm))


def active_stage(
    stages: list[dict[str, Any]], decisions: list[StageDecision]
) -> dict[str, Any] | None:
    """Текущий активный (первый pending) этап или None если завершено. Pure."""
    norm = normalize_stages(stages)
    status, idx = overall_state(norm, decisions)
    if status == "pending" and idx < len(norm):
        return norm[idx]
    return None


# ───────────────────────────── чистое ядро: выбор сценария ─────────────────────────────


@dataclass
class ScenarioRow:
    """Дакти-тип сценария для pure-тестов (зеркало FinApprovalScenario)."""

    id: int
    op_type_id: int | None
    legal_entity_id: int | None
    applies_to: str
    min_amount: Decimal | None
    max_amount: Decimal | None
    priority: int
    is_active: bool


def _scenario_matches(
    s: ScenarioRow,
    *,
    applies_to: str,
    op_type_id: int | None,
    legal_entity_id: int | None,
    amount: Decimal,
) -> bool:
    """Подходит ли сценарий под цель. Pure.

    NULL op_type_id/legal_entity_id в сценарии = «для всех». min/max — включающие границы
    (NULL = без границы). applies_to должен совпадать точно.
    """
    if not s.is_active:
        return False
    if s.applies_to != applies_to:
        return False
    if s.op_type_id is not None and s.op_type_id != op_type_id:
        return False
    if s.legal_entity_id is not None and s.legal_entity_id != legal_entity_id:
        return False
    if s.min_amount is not None and amount < s.min_amount:
        return False
    if s.max_amount is not None and amount > s.max_amount:
        return False
    return True


def pick_scenario(
    scenarios: list[ScenarioRow],
    *,
    applies_to: str,
    op_type_id: int | None,
    legal_entity_id: int | None,
    amount: Decimal,
) -> ScenarioRow | None:
    """Выбрать сценарий: первый по priority (DESC), затем по специфичности. Pure.

    Среди подходящих берём с максимальным priority; при равном priority — более
    специфичный (op_type+entity заданы конкретно), затем меньший id (детерминизм).
    None — если ничего не подошло (→ NoScenarioMatched / автосогласование решает caller).
    """
    matched = [
        s
        for s in scenarios
        if _scenario_matches(
            s,
            applies_to=applies_to,
            op_type_id=op_type_id,
            legal_entity_id=legal_entity_id,
            amount=amount,
        )
    ]
    if not matched:
        return None

    def specificity(s: ScenarioRow) -> int:
        return (1 if s.op_type_id is not None else 0) + (
            1 if s.legal_entity_id is not None else 0
        )

    matched.sort(key=lambda s: (-s.priority, -specificity(s), s.id))
    return matched[0]


# ───────────────────────────── async-оркестраторы ─────────────────────────────


async def select_scenario(
    session: AsyncSession,
    *,
    applies_to: str,
    op_type_id: int | None,
    legal_entity_id: int | None,
    amount: Decimal,
) -> FinApprovalScenario | None:
    """Найти применимый сценарий из БД (None = нет → caller решает авто-approve/422)."""
    rows = (
        await session.execute(
            select(FinApprovalScenario).where(
                FinApprovalScenario.is_active.is_(True),
                FinApprovalScenario.applies_to == applies_to,
            )
        )
    ).scalars().all()
    duck = [
        ScenarioRow(
            id=r.id,
            op_type_id=r.op_type_id,
            legal_entity_id=r.legal_entity_id,
            applies_to=r.applies_to,
            min_amount=r.min_amount,
            max_amount=r.max_amount,
            priority=r.priority,
            is_active=r.is_active,
        )
        for r in rows
    ]
    chosen = pick_scenario(
        duck,
        applies_to=applies_to,
        op_type_id=op_type_id,
        legal_entity_id=legal_entity_id,
        amount=amount,
    )
    if chosen is None:
        return None
    return next(r for r in rows if r.id == chosen.id)


async def _decisions_for(
    session: AsyncSession, approvable_kind: str, approvable_id: int
) -> list[FinApproval]:
    return list(
        (
            await session.execute(
                select(FinApproval)
                .where(
                    FinApproval.approvable_kind == approvable_kind,
                    FinApproval.approvable_id == approvable_id,
                )
                .order_by(FinApproval.stage_order, FinApproval.id)
            )
        ).scalars().all()
    )


def _duck(rows: list[FinApproval]) -> list[StageDecision]:
    return [
        StageDecision(
            user_id=r.user_id,
            stage_order=r.stage_order,
            decision=r.decision,
        )
        for r in rows
    ]


async def _seed_stage(
    session: AsyncSession,
    *,
    approvable_kind: str,
    approvable_id: int,
    scenario_id: int,
    stage: dict[str, Any],
) -> None:
    """Создать pending-голоса для всех аппруверов этапа (если ещё не созданы)."""
    existing = {
        r.user_id
        for r in await _decisions_for(session, approvable_kind, approvable_id)
        if r.stage_order == stage["order"]
    }
    for uid in stage["user_ids"]:
        if uid in existing:
            continue
        session.add(
            FinApproval(
                approvable_kind=approvable_kind,
                approvable_id=approvable_id,
                scenario_id=scenario_id,
                user_id=uid,
                stage_order=stage["order"],
                decision="pending",
            )
        )
    await session.flush()


async def start_approval(
    session: AsyncSession,
    *,
    approvable_kind: str,
    approvable_id: int,
    scenario: FinApprovalScenario,
) -> str:
    """Запустить согласование: создать голоса первого этапа. Возвращает overall status.

    Если у сценария нет этапов → ("approved") сразу (автосогласование).
    """
    stages = normalize_stages(scenario.stages)
    if not stages:
        return "approved"
    await _seed_stage(
        session,
        approvable_kind=approvable_kind,
        approvable_id=approvable_id,
        scenario_id=scenario.id,
        stage=stages[0],
    )
    return "pending"


async def record_decision(
    session: AsyncSession,
    *,
    approvable_kind: str,
    approvable_id: int,
    scenario: FinApprovalScenario,
    user_id: int,
    decision: str,
    comment: str | None,
) -> str:
    """Записать голос аппрувера и продвинуть этапы. Возвращает overall status.

    Бросает NotAnApprover, если user не в активном этапе; AlreadyDecided, если уже
    проголосовал или target завершён. При прохождении этапа — сидит следующий.
    """
    from datetime import UTC, datetime

    stages = normalize_stages(scenario.stages)
    rows = await _decisions_for(session, approvable_kind, approvable_id)
    status, _ = overall_state(stages, _duck(rows))
    if status in ("approved", "rejected"):
        raise AlreadyDecided("Согласование уже завершено")

    act = active_stage(stages, _duck(rows))
    if act is None:
        raise AlreadyDecided("Нет активного этапа")
    if user_id not in act["user_ids"]:
        raise NotAnApprover("Пользователь не является согласантом текущего этапа")

    my = next(
        (
            r
            for r in rows
            if r.user_id == user_id and r.stage_order == act["order"]
        ),
        None,
    )
    if my is None:
        raise NotAnApprover("Голос для пользователя в активном этапе не найден")
    if my.decision != "pending":
        raise AlreadyDecided("Голос уже отдан")

    my.decision = decision
    my.comment = comment
    my.decided_at = datetime.now(UTC)
    await session.flush()

    rows = await _decisions_for(session, approvable_kind, approvable_id)
    status, idx = overall_state(stages, _duck(rows))
    norm = normalize_stages(stages)
    if status == "pending" and idx < len(norm):
        await _seed_stage(
            session,
            approvable_kind=approvable_kind,
            approvable_id=approvable_id,
            scenario_id=scenario.id,
            stage=norm[idx],
        )
    return status


async def approval_summary(
    session: AsyncSession,
    *,
    approvable_kind: str,
    approvable_id: int,
    scenario: FinApprovalScenario | None,
) -> dict[str, Any]:
    """Сводка согласования для карточки (этапы + голоса + статус)."""
    rows = await _decisions_for(session, approvable_kind, approvable_id)
    stages = normalize_stages(scenario.stages) if scenario else []
    status, active_idx = overall_state(stages, _duck(rows))
    payload = []
    for i, st in enumerate(stages):
        st_rows = [r for r in rows if r.stage_order == st["order"]]
        payload.append({
            "order": st["order"],
            "name": st["name"],
            "mode": st["mode"],
            "user_ids": st["user_ids"],
            "min_required": st["min_required"],
            "approved": sum(1 for r in st_rows if r.decision == "approved"),
            "rejected": sum(1 for r in st_rows if r.decision == "rejected"),
            "pending": sum(1 for r in st_rows if r.decision == "pending"),
            "is_active": status == "pending" and i == active_idx,
        })
    return {
        "status": status,
        "active_stage": active_idx,
        "total_stages": len(stages),
        "stages": payload,
        "scenario_id": scenario.id if scenario else None,
    }
