"""Seed дефолтных PipelineAutomation'ов (Эпик 4.2).

Аудит-фикс: на проде `/admin/automations` показывал «Автоматизаций пока нет»,
out-of-box ценность нулевая. Этот сидер дозаливает 6 базовых автоматизаций
при старте API, чтобы менеджеры сразу видели работающие триггеры:

1. Lead pipeline / Новый / on_create → tg_notify owner (новый лид)
2. Sales / Холодные (заморозка) / idle_in_stage_days(7) → tg_notify owner (idle)
3. Sales / Назначить встречу / on_enter_stage → create_task("call", due+1)
4. Sales / Успех / on_enter_stage → tg_notify owner (закрыта успешно)
5. Sales / Проигрыш / on_enter_stage → tg_notify owner (заполни причину)
6. Renewal / Готов к продлению / on_create → tg_notify owner (новая renewal-сделка)

Идемпотентно: insert-missing по уникальному ключу
(pipeline_kind, stage_code|None, trigger_kind, action_kind, name). Существующие
не трогает (если админ переименовал/выключил — НЕ восстанавливаем). Падение
любого шага не валит lifespan (catch и log в caller — main.py).

Advisory-lock key 728_274_010 — следующий свободный после
{002..008} (см. existing seeders в services/{deals,categories,pricing,
customer_success,leads,renewal}.py). 009 зарезервирован за CS-hotfixes,
ведущимися параллельно.
"""
from __future__ import annotations

import logging

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import Pipeline, PipelineAutomation, PipelineStage

logger = logging.getLogger(__name__)

# Advisory-lock key. См. модульный docstring выше.
_SEED_LOCK_AUTOMATIONS = 728_274_010

# Имена шаблонов сообщений — переиспользуем встроенный _format_message executor'а
# (поддерживает {target_id}/{target_type}/{target_title}/{owner_name}). Никакой
# полноценной Jinja — это runtime safety.

# Описания дефолтных автоматизаций.
#
# Структура: (pipeline_kind, stage_code | None, trigger_kind, action_kind,
#             name, description, trigger_config, action_config)
#
# stage_code = None → автоматизация на всей воронке (stage_id NULL).
# stage_code задано → находим PipelineStage.code в этой воронке.
# Для sales-воронки исторически stage.code пуст, поэтому мапим по stage.name
# (см. _find_sales_stage_id_by_name).
_DEFAULT_AUTOMATIONS: list[dict] = [
    # 1. Lead pipeline — новый лид
    {
        "pipeline_kind": "lead",
        "stage_code": "new",
        "stage_name_fallback": "Новый",
        "trigger_kind": "on_create",
        "action_kind": "tg_notify",
        "name": "Новый лид → уведомить owner",
        "description": "Уведомление в Telegram при создании нового лида.",
        "trigger_config": {},
        "action_config": {
            "recipient": "owner",
            "message": "🆕 Новый лид: {target_title}. Owner: {owner_name}.",
        },
    },
    # 2. Sales — холодные idle 7 дней
    {
        "pipeline_kind": "sales",
        "stage_code": None,  # sales stages не имеют code — мапим по имени
        "stage_name_fallback": "Холодные (заморозка)",
        "trigger_kind": "idle_in_stage_days",
        "action_kind": "tg_notify",
        "name": "Холодная сделка >7 дней → напомнить owner",
        "description": (
            "SLA: сделка лежит в этапе «Холодные (заморозка)» больше 7 дней — "
            "напоминаем owner'у проверить и обновить."
        ),
        "trigger_config": {"days": 7, "target_type": "deal"},
        "action_config": {
            "recipient": "owner",
            "message": (
                "❄️ Сделка «{target_title}» лежит в «Холодных» 7+ дней. "
                "Owner: {owner_name}. Обнови статус или передвинь в воронке."
            ),
        },
    },
    # 3. Sales — назначить встречу → создать задачу
    {
        "pipeline_kind": "sales",
        "stage_code": None,
        "stage_name_fallback": "Назначить встречу",
        "trigger_kind": "on_enter_stage",
        "action_kind": "create_task",
        "name": "Назначить встречу → задача «Подтвердить встречу»",
        "description": (
            "При входе в этап «Назначить встречу» автоматически создаётся "
            "задача (kind=call) на owner'а со сроком +1 день."
        ),
        "trigger_config": {},
        "action_config": {
            "title": "Подтвердить встречу с клиентом",
            "body": (
                "Сделка «{target_title}» вошла в этап «Назначить встречу». "
                "Позвонить клиенту и зафиксировать дату/время."
            ),
            "responsible": "owner",
            "due_days": 1,
        },
    },
    # 4. Sales — успех → уведомить owner (директора попозже, через изменение
    # recipient'а в UI). Сейчас recipient='owner' — менеджер получит триггер,
    # а директор узнает через дашборд (Эпик 10).
    {
        "pipeline_kind": "sales",
        "stage_code": None,
        "stage_name_fallback": "Успех",
        "trigger_kind": "on_enter_stage",
        "action_kind": "tg_notify",
        "name": "Сделка успешно закрыта → уведомление",
        "description": "Триггер на этапе «Успех» — поздравительное уведомление.",
        "trigger_config": {},
        "action_config": {
            "recipient": "owner",
            "message": (
                "🎉 Сделка «{target_title}» закрыта успешно! "
                "Owner: {owner_name}."
            ),
        },
    },
    # 5. Sales — проигрыш → уведомить owner с просьбой заполнить lost_reason
    {
        "pipeline_kind": "sales",
        "stage_code": None,
        "stage_name_fallback": "Проигрыш",
        "trigger_kind": "on_enter_stage",
        "action_kind": "tg_notify",
        "name": "Сделка проиграна → заполнить причину",
        "description": (
            "Триггер на этапе «Проигрыш» — owner получает напоминание заполнить "
            "поле lost_reason в карточке сделки (Эпик 4.2)."
        ),
        "trigger_config": {},
        "action_config": {
            "recipient": "owner",
            "message": (
                "❌ Сделка «{target_title}» проиграна. "
                "Owner: {owner_name}. Заполни причину в карточке (lost_reason)."
            ),
        },
    },
    # 6. Renewal — готов к продлению → уведомить owner
    {
        "pipeline_kind": "renewal",
        "stage_code": "renew_ready",
        "stage_name_fallback": "Готов к продлению",
        "trigger_kind": "on_create",
        "action_kind": "tg_notify",
        "name": "Renewal-сделка создана → уведомить owner",
        "description": (
            "Когда cron создаёт renewal-сделку (подписка с discount_until "
            "приближается) — уведомляем owner'а."
        ),
        "trigger_config": {},
        "action_config": {
            "recipient": "owner",
            "message": (
                "🔄 Создана сделка на продление: «{target_title}». "
                "Owner: {owner_name}. Подготовь предложение."
            ),
        },
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


async def _find_stage(
    session: AsyncSession,
    pipeline_id: int,
    *,
    code: str | None,
    name_fallback: str | None,
) -> PipelineStage | None:
    """Найти этап по code (если задан) или name_fallback.

    Sales-воронка исторически не использует stage.code (см. AMO_STAGES в
    services/deals.py) — там матчим по name. Lead/lifecycle/renewal — по code.
    """
    if code is not None:
        stage = (
            await session.execute(
                select(PipelineStage).where(
                    PipelineStage.pipeline_id == pipeline_id,
                    PipelineStage.code == code,
                )
            )
        ).scalar_one_or_none()
        if stage is not None:
            return stage
    if name_fallback is not None:
        stage = (
            await session.execute(
                select(PipelineStage).where(
                    PipelineStage.pipeline_id == pipeline_id,
                    PipelineStage.name == name_fallback,
                )
            )
        ).scalar_one_or_none()
        if stage is not None:
            return stage
    return None


def _automation_unique_key(spec: dict) -> tuple[str, str | None, str, str, str]:
    """Уникальный ключ для idempotent-проверки.

    (pipeline_kind, stage_code|None, trigger_kind, action_kind, name)
    — менее формальный, но достаточный, чтобы не плодить дубли при рестартах.
    """
    return (
        spec["pipeline_kind"],
        spec["stage_code"],
        spec["trigger_kind"],
        spec["action_kind"],
        spec["name"],
    )


async def _existing_automation_keys(
    session: AsyncSession,
) -> set[tuple[str, str | None, str, str, str]]:
    """Снять все существующие автоматизации и построить их unique-keys.

    Объединяем JOIN с pipelines/stages, чтобы получить pipeline_kind и stage_code.
    """
    stmt = (
        select(
            Pipeline.kind,
            PipelineStage.code,
            PipelineAutomation.trigger_kind,
            PipelineAutomation.action_kind,
            PipelineAutomation.name,
        )
        .join(Pipeline, Pipeline.id == PipelineAutomation.pipeline_id)
        .outerjoin(
            PipelineStage, PipelineStage.id == PipelineAutomation.stage_id
        )
    )
    rows = (await session.execute(stmt)).all()
    return {
        (
            r[0],  # pipeline.kind
            r[1],  # stage.code (None если stage_id NULL)
            r[2],  # trigger_kind
            r[3],  # action_kind
            r[4],  # name
        )
        for r in rows
    }


async def seed_default_automations(session: AsyncSession) -> int:
    """Сид 6 базовых PipelineAutomation'ов. Insert-missing pattern + advisory-lock.

    Идемпотентно: повторный вызов НЕ создаёт дублей и НЕ перезаписывает
    существующие. Возвращает количество вновь добавленных автоматизаций.

    Условие skip автоматизации:
    - в этой pipeline_kind воронки нет (сидер pipeline'а ещё не отработал) →
      пропускаем без ошибки, на следующем рестарте сидер pipeline отработает первым;
    - целевой этап не найден (например, пользователь переименовал) → пропускаем;
    - уникальный ключ уже существует → пропускаем (idempotency).
    """
    await session.execute(
        text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_AUTOMATIONS}
    )
    try:
        existing_keys = await _existing_automation_keys(session)
        added = 0
        for spec in _DEFAULT_AUTOMATIONS:
            key = _automation_unique_key(spec)
            if key in existing_keys:
                continue

            pipe = await _find_pipeline_by_kind(session, spec["pipeline_kind"])
            if pipe is None:
                logger.info(
                    "seed_default_automations: воронка kind=%s не найдена, skip %r",
                    spec["pipeline_kind"], spec["name"],
                )
                continue

            stage_id: int | None = None
            stage = await _find_stage(
                session,
                pipe.id,
                code=spec.get("stage_code"),
                name_fallback=spec.get("stage_name_fallback"),
            )
            if stage is None and (
                spec.get("stage_code") is not None
                or spec.get("stage_name_fallback") is not None
            ):
                # Этап явно указан, но не найден — это валидно (например, у юзера
                # удалили этап). Пропускаем эту автоматизацию.
                logger.info(
                    "seed_default_automations: этап %s/%s не найден в %s, skip %r",
                    spec.get("stage_code"), spec.get("stage_name_fallback"),
                    pipe.name, spec["name"],
                )
                continue
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
                created_by_user_id=None,  # системный сидер, без автора
            )
            session.add(automation)
            added += 1

        if added:
            await session.commit()
            logger.info(
                "seed_default_automations: добавлено %d автоматизаций", added
            )
        return added
    finally:
        await session.execute(
            text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_AUTOMATIONS}
        )
