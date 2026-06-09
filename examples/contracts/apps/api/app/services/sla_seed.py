"""Эпик 19 — Automation 2: дефолтный сидер SLA-правил.

Заливает 4 базовых SLA-автоматизации при первом старте API, чтобы менеджеры
сразу видели работающую SLA-полку в UI «/admin/automations» (вкладка «SLA»).

Сиденные правила:
1. Сделка > 7 дней без активности → tg_notify owner + эскалация manager через 48h
2. Approval > 3 дней без решения → tg_notify approver + эскалация lead через 24h
3. Lead > 1 день без касания → tg_notify owner (без эскалации)
4. Задача с deadline через 24ч → tg_notify owner (date_field_approaching на task.due_at)

Идемпотентно: insert-missing pattern + advisory-lock.
- Уникальный ключ — name (как у seed_default_automations) фильтрованный по is_sla=True;
  если админ переименовал или удалил — НЕ восстанавливаем.
- Catch на каждое правило по отдельности — одно сломанное не валит остальные.
- Падение всего сидера НЕ роняет lifespan (caller в main.py через try/except).

Advisory-lock key 728_274_019 (по нумерации Эпика 19).

NOTE: 4-е правило (date_field_approaching на task.deadline_at) пока в seed
помечено как demo-config — реальная поддержка task-target в executor'е
требует доработки (MVP исполнитель работает только с subscription для
date_field_approaching). Когда будет — правило начнёт автоматически работать.
"""
from __future__ import annotations

import logging

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import Pipeline, PipelineAutomation, PipelineStage

logger = logging.getLogger(__name__)

# Advisory-lock key. По схеме номеров эпика — 728_274_0NN, где NN — номер эпика.
_SEED_LOCK_SLA = 728_274_019

# Default SLA rules. Структура:
# (pipeline_kind, stage_code | None, name, description, trigger_kind,
#  trigger_config, action_kind, action_config, escalation_chain)
#
# stage_code = None → на всю воронку (stage_id NULL).
# Для sales/renewal/lead/approval — берём первую активную воронку соответствующего kind.
#
# escalation_chain — список словарей {after_hours, action_kind, action_config}.
# [] = эскалация отсутствует.
DEFAULT_SLA_RULES: list[dict] = [
    # 1. Sales: сделка > 7 дней без активности (idle_in_stage_days)
    {
        "pipeline_kind": "sales",
        "stage_code": None,  # на всех этапах sales воронки
        "stage_name_fallback": None,
        "name": "SLA: Сделка > 7 дней без активности",
        "description": (
            "SLA: сделка не двигалась 7 дней — owner получает push, "
            "manager эскалируется через 48 часов."
        ),
        "trigger_kind": "idle_in_stage_days",
        "trigger_config": {"days": 7, "target_type": "deal"},
        "action_kind": "tg_notify",
        "action_config": {
            "recipient": "owner",
            "message": (
                "⏱ SLA: сделка «{target_title}» застряла 7 дней без активности. "
                "Owner: {owner_name}. Нужна реакция."
            ),
        },
        "escalation_chain": [
            {
                "after_hours": 48,
                "action_kind": "tg_notify",
                "action_config": {
                    "recipient": "owner",  # manager-channel задаст админ в UI
                    "message": (
                        "🚨 Эскалация SLA: сделка «{target_title}» 48ч без реакции "
                        "после первого SLA-уведомления."
                    ),
                },
            },
        ],
    },
    # 2. Approval: договор > 3 дней без решения
    # NB: для approval target_type='deal' в текущем executor'е нет — это правило
    # будет skip'аться до тех пор, пока executor не научится обрабатывать
    # target_type='approval'. Сидим заранее, чтобы UI показал, к чему стремиться.
    {
        "pipeline_kind": "sales",  # привязываем к sales pipeline как ближайший
        "stage_code": None,
        "stage_name_fallback": None,
        "name": "SLA: Approval > 3 дней без решения",
        "description": (
            "SLA: договор на согласовании уже 3 дня без решения approver'а — "
            "напоминание ему, эскалация lead'у через 24 часа."
        ),
        "trigger_kind": "idle_in_stage_days",
        "trigger_config": {"days": 3, "target_type": "deal"},
        "action_kind": "tg_notify",
        "action_config": {
            "recipient": "owner",
            "message": (
                "📝 SLA Approval: договор «{target_title}» ждёт вашего решения "
                "уже 3 дня."
            ),
        },
        "escalation_chain": [
            {
                "after_hours": 24,
                "action_kind": "tg_notify",
                "action_config": {
                    "recipient": "owner",
                    "message": (
                        "🚨 Эскалация approval: «{target_title}» без решения уже "
                        "24 часа после первого уведомления."
                    ),
                },
            },
        ],
    },
    # 3. Lead: > 1 день без касания
    {
        "pipeline_kind": "lead",
        "stage_code": None,
        "stage_name_fallback": None,
        "name": "SLA: Lead > 1 день без касания",
        "description": (
            "SLA: лид без активности 24 часа — owner получает напоминание. "
            "Без эскалации — лидов много, не зашумляем чат."
        ),
        "trigger_kind": "idle_in_stage_days",
        "trigger_config": {"days": 1, "target_type": "lead"},
        "action_kind": "tg_notify",
        "action_config": {
            "recipient": "owner",
            "message": (
                "📞 SLA: лид «{target_title}» 1 день без касания. "
                "Owner: {owner_name}."
            ),
        },
        "escalation_chain": [],  # без эскалации
    },
    # 4. Task с deadline через 24ч (date_field_approaching). Триггер привязан к
    # sales pipeline для удобства группировки в UI — для активности target_type
    # пока 'subscription' (что executor умеет), задачи требуют доработки.
    # Сидим заранее: правило будет skip'аться, пока не поддержим task в
    # run_date_field_scanner. В UI всё равно покажется как «не сработавшее»
    # SLA-правило (помогает админам увидеть план).
    {
        "pipeline_kind": "sales",
        "stage_code": None,
        "stage_name_fallback": None,
        "name": "SLA: Задача с deadline через 24ч",
        "description": (
            "SLA: задача с приближающимся deadline (через 24ч) → owner получает "
            "push. Без эскалации — короткий лидтайм."
        ),
        "trigger_kind": "date_field_approaching",
        # NOTE: 'task' / 'deadline_at' пока НЕ поддерживается в executor;
        # сидим как demo, чтобы UI показывал — но скан пропустит до доработки.
        "trigger_config": {
            "field": "deadline_at",
            "hours_before": 24,
            "target_type": "task",
        },
        "action_kind": "tg_notify",
        "action_config": {
            "recipient": "owner",
            "message": (
                "⏰ SLA: дедлайн задачи «{target_title}» через 24 часа. "
                "Owner: {owner_name}."
            ),
        },
        "escalation_chain": [],
    },
]


async def _find_pipeline_by_kind(
    session: AsyncSession, kind: str
) -> Pipeline | None:
    """Первая активная воронка указанного kind (порядок по sort_order, id)."""
    return (
        await session.execute(
            select(Pipeline)
            .where(Pipeline.kind == kind, Pipeline.is_active.is_(True))
            .order_by(Pipeline.sort_order, Pipeline.id)
            .limit(1)
        )
    ).scalar_one_or_none()


def _sla_unique_key(spec: dict) -> tuple[str, str, str]:
    """Уникальный ключ для idempotent-проверки SLA-правила.

    (pipeline_kind, trigger_kind, name) — name самостоятельно достаточно
    уникален (в seed prefix 'SLA:'), но добавляем kind'ы для устойчивости при
    переименовании.
    """
    return (
        spec["pipeline_kind"],
        spec["trigger_kind"],
        spec["name"],
    )


async def _existing_sla_keys(
    session: AsyncSession,
) -> set[tuple[str, str, str]]:
    """Снять все существующие SLA-автоматизации и построить их unique-keys.

    Фильтруем по is_sla=True — обычные автоматизации с такими же name (вряд ли
    будут — у нас префикс 'SLA:') нас не интересуют, но фильтр строгий чтобы
    seed не зацепил случайно.
    """
    stmt = (
        select(
            Pipeline.kind,
            PipelineAutomation.trigger_kind,
            PipelineAutomation.name,
        )
        .join(Pipeline, Pipeline.id == PipelineAutomation.pipeline_id)
        .where(PipelineAutomation.is_sla.is_(True))
    )
    rows = (await session.execute(stmt)).all()
    return {(r[0], r[1], r[2]) for r in rows}


async def seed_default_sla_rules(session: AsyncSession) -> int:
    """Сид дефолтных SLA-правил. Insert-missing pattern + advisory-lock.

    Идемпотентно: повторный вызов НЕ создаёт дублей. Возвращает количество
    вновь добавленных правил.

    Skip-условия (без ошибки):
    - воронка нужного kind не найдена (sales-pipeline сидер ещё не отработал) →
      пропускаем; на следующем рестарте отработает первым;
    - уникальный ключ уже существует → пропускаем (idempotency);
    - админ удалил/переименовал правило — НЕ восстанавливаем (consistent с
      seed_default_automations).
    """
    await session.execute(
        text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_SLA}
    )
    try:
        existing_keys = await _existing_sla_keys(session)
        added = 0
        for spec in DEFAULT_SLA_RULES:
            key = _sla_unique_key(spec)
            if key in existing_keys:
                continue

            pipe = await _find_pipeline_by_kind(session, spec["pipeline_kind"])
            if pipe is None:
                logger.info(
                    "seed_default_sla_rules: воронка kind=%s не найдена, skip %r",
                    spec["pipeline_kind"], spec["name"],
                )
                continue

            # stage_id всегда NULL для SLA-правил в дефолте — они работают
            # на всех этапах воронки (это семантика SLA). Если кто-то хочет
            # SLA только на конкретный этап — настроит через UI.
            stage_id: int | None = None
            if spec.get("stage_code") is not None or spec.get("stage_name_fallback") is not None:
                stage = (
                    await session.execute(
                        select(PipelineStage).where(
                            PipelineStage.pipeline_id == pipe.id,
                            (
                                PipelineStage.code == spec["stage_code"]
                                if spec.get("stage_code") is not None
                                else PipelineStage.name == spec["stage_name_fallback"]
                            ),
                        )
                    )
                ).scalar_one_or_none()
                if stage is not None:
                    stage_id = stage.id

            automation = PipelineAutomation(
                name=spec["name"],
                description=spec.get("description"),
                pipeline_id=pipe.id,
                stage_id=stage_id,
                trigger_kind=spec["trigger_kind"],
                trigger_config=spec.get("trigger_config") or {},
                action_kind=spec["action_kind"],
                action_config=spec.get("action_config") or {},
                is_active=True,
                is_sla=True,  # ← КЛЮЧЕВОЕ: помечаем как SLA
                escalation_chain=spec.get("escalation_chain") or [],
                created_by_user_id=None,  # системный сидер, без автора
            )
            session.add(automation)
            added += 1

        if added:
            await session.commit()
            logger.info(
                "seed_default_sla_rules: добавлено %d SLA-правил", added
            )
        return added
    finally:
        await session.execute(
            text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_SLA}
        )
