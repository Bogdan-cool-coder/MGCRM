"""AutomationRun (Эпик 4): read-only history of automation executions.

Журнал выполнений автоматизаций. Используется для:
- Истории «автоматизация X отработала Y раз» — фильтр по automation_id.
- Истории по сущности «какие автоматизации затрагивали этот deal» — фильтр по
  target_type+target_id.
- Дашборда failed/skipped — фильтр по status.

ACL: LawyerOrAdmin (как и сами автоматизации).
"""
from __future__ import annotations

from datetime import datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, ConfigDict
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import LawyerOrAdmin
from app.models import AutomationRun, PipelineAutomation
from app.services.automation_executor import AUTOMATION_ACTIONS, AUTOMATION_TARGET_TYPES

router = APIRouter(prefix="/automation-runs", tags=["automation-runs"])


# ============ Pydantic-схемы ============


class AutomationRunOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    automation_id: int
    target_type: str
    target_id: int
    status: str
    started_at: datetime
    finished_at: datetime | None
    error_text: str | None
    result_json: dict[str, Any] | None
    # Денормализовано — имя автоматизации (для UI без N+1)
    automation_name: str | None = None


_ALLOWED_STATUSES = {"pending", "success", "failed", "skipped"}


# ============ Endpoints ============


@router.get("", response_model=list[AutomationRunOut])
async def list_runs(
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    automation_id: int | None = None,
    target_type: str | None = None,
    target_id: int | None = None,
    status: str | None = None,
    action_kind: str | None = None,
    limit: int = 100,
    offset: int = 0,
):
    """Список выполнений автоматизаций. Order by started_at DESC.

    Фильтры:
    - automation_id — конкретная автоматизация;
    - target_type+target_id — все запуски по сущности (target_id можно и отдельно);
    - status — pending/success/failed/skipped.
    - action_kind — фильтр по типу действия (tg_notify/webhook/email/...).
      Применяется через join с pipeline_automations, чтобы UI «failed webhooks»
      работал без N+1.
    """
    if target_type is not None and target_type not in AUTOMATION_TARGET_TYPES:
        raise HTTPException(
            400,
            f"Недопустимый target_type: {target_type}. Ожидается одно из {list(AUTOMATION_TARGET_TYPES)}",
        )
    if status is not None and status not in _ALLOWED_STATUSES:
        raise HTTPException(
            400,
            f"Недопустимый status: {status}. Ожидается одно из {sorted(_ALLOWED_STATUSES)}",
        )
    if action_kind is not None and action_kind not in AUTOMATION_ACTIONS:
        raise HTTPException(
            400,
            f"Недопустимый action_kind: {action_kind}. Ожидается одно из {list(AUTOMATION_ACTIONS)}",
        )

    stmt = select(AutomationRun).order_by(AutomationRun.started_at.desc())
    if automation_id is not None:
        stmt = stmt.where(AutomationRun.automation_id == automation_id)
    if target_type is not None:
        stmt = stmt.where(AutomationRun.target_type == target_type)
    if target_id is not None:
        stmt = stmt.where(AutomationRun.target_id == target_id)
    if status is not None:
        stmt = stmt.where(AutomationRun.status == status)
    if action_kind is not None:
        # Подзапрос: id автоматизаций с action_kind == X. Без явного join, чтобы
        # не ломать select(AutomationRun) и не плодить дубликаты.
        sub_ids = select(PipelineAutomation.id).where(
            PipelineAutomation.action_kind == action_kind
        )
        stmt = stmt.where(AutomationRun.automation_id.in_(sub_ids))
    stmt = stmt.limit(max(1, min(1000, limit))).offset(max(0, offset))

    rows = (await session.execute(stmt)).scalars().all()

    # Денормализованные имена автоматизаций (одним запросом, без N+1)
    automation_ids = {r.automation_id for r in rows}
    names: dict[int, str] = {}
    if automation_ids:
        for a in (
            await session.execute(
                select(PipelineAutomation).where(
                    PipelineAutomation.id.in_(automation_ids)
                )
            )
        ).scalars().all():
            names[a.id] = a.name

    return [
        AutomationRunOut(
            id=r.id,
            automation_id=r.automation_id,
            target_type=r.target_type,
            target_id=r.target_id,
            status=r.status,
            started_at=r.started_at,
            finished_at=r.finished_at,
            error_text=r.error_text,
            result_json=r.result_json,
            automation_name=names.get(r.automation_id),
        )
        for r in rows
    ]


@router.post("/{run_id}/retry", response_model=AutomationRunOut)
async def retry_automation_run(
    run_id: int,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Tech Sprint Фаза 0 (задача 4): повторить failed/skipped AutomationRun.

    Создаёт НОВЫЙ AutomationRun (через execute_action на том же automation+target),
    исходный остаётся для аудита. Возвращает новый run с актуальным статусом
    (success/failed/skipped).

    Сценарии:
    - tg_notify failed из-за rate-limit Telegram → попытаемся снова;
    - generate_document failed из-за temporary LibreOffice timeout → попытаемся;
    - skipped из-за «target не найден» → если target воссоздан, можно ретраить.

    400 если automation удалён (CASCADE через FK pipeline_automations).
    """
    from app.services.automation_executor import execute_action

    original = (
        await session.execute(select(AutomationRun).where(AutomationRun.id == run_id))
    ).scalar_one_or_none()
    if original is None:
        raise HTTPException(404, "AutomationRun не найден")

    # MEDIUM-фикс: ретраить можно только failed/skipped. Повтор success-run
    # дублировал бы реальное действие (вторая отправка TG/webhook/задача).
    if original.status not in ("failed", "skipped"):
        raise HTTPException(
            400,
            f"Ретрай разрешён только для failed/skipped run "
            f"(текущий статус: {original.status})",
        )

    automation = (
        await session.execute(
            select(PipelineAutomation).where(
                PipelineAutomation.id == original.automation_id
            )
        )
    ).scalar_one_or_none()
    if automation is None:
        raise HTTPException(
            400, "Автоматизация удалена — нельзя ретраить",
        )

    # execute_action создаёт новый AutomationRun (через session.add); commit ниже.
    new_run = await execute_action(
        session,
        automation,
        original.target_type,
        original.target_id,
    )
    await session.commit()
    await session.refresh(new_run)
    return AutomationRunOut(
        id=new_run.id,
        automation_id=new_run.automation_id,
        target_type=new_run.target_type,
        target_id=new_run.target_id,
        status=new_run.status,
        started_at=new_run.started_at,
        finished_at=new_run.finished_at,
        error_text=new_run.error_text,
        result_json=new_run.result_json,
        automation_name=automation.name,
    )
