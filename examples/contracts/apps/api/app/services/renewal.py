"""Renewal pipeline (Эпик 6 MVP).

Воронка продлений `Pipeline.kind="renewal"` — отдельная от продаж/ЖЦ/лидов.
Запускается через сидер `seed_renewal_pipeline` в lifespan (advisory-lock
728_274_008, insert-missing — идемпотентно).

Cron-генератор `scan_subscriptions_for_renewal` живёт в одном cron'е с
автоматизациями (см. app.jobs.automation_cron). Раз в час просыпается, ищет
подписки, у которых `discount_until` попадает в окно [today, today+lookahead],
и создаёт сделку в renewal-воронке. Дедуп — по `subscription_id` (из
Deal.extra_fields["renewal_subscription_id"]) + окно 60 дней. Это защищает
мульти-платформенного клиента (KZ MacroSales + UAE MacroERP): каждая
подписка получит свою renewal-сделку, вторая не потеряется.

Сам auto_prolongation flow (триггер `discount_until` через PipelineAutomation
с действием `change_stage` / `generate_document`) — отложено: action `change_stage`
требует расширения automation_executor (зона automation-specialist), а полноценная
`generate_document` — зона contract-specialist.
"""
from __future__ import annotations

import logging
from datetime import UTC, date, datetime, timedelta

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import ClientSubscription, Deal, Pipeline, PipelineStage
from app.services.party import resolve_party

logger = logging.getLogger(__name__)
_logger = logger  # alias для cs-hotfix логов (используется ниже)

# Уникальный seed-key для renewal-воронки (728_274_001..007 уже заняты;
# 008 — наш). См. apps/api/app/services/{deals,categories,pricing,
# customer_success,leads}.py.
_SEED_LOCK_RENEWAL = 728_274_008

RENEWAL_PIPELINE_NAME = "Продления"

# (name, code, order). Этапы продления — короткая воронка от готовности до
# подписания / отказа. Цвета подобраны под существующую палитру (см. LIFECYCLE_STAGES
# и AMO_STAGES в services/customer_success.py / services/deals.py).
RENEWAL_STAGES: list[tuple[str, str, int]] = [
    # ВАЖНО: pipeline_stages.code = VARCHAR(16). Все коды ≤16 символов.
    ("Готов к продлению", "renew_ready", 1),
    ("КП отправлено", "proposal_sent", 2),
    ("На согласовании", "negotiation", 3),
    ("Подписан", "signed", 4),
    ("Закрыт-оплачен", "paid", 5),
    ("Отказ", "lost", 6),
]

# Окно дедупа: если за последние 60 дней по ПОДПИСКЕ уже была создана
# renewal-сделка — повторно не создаём. Защищает от race condition cron'а
# (например, ручное закрытие сделки + автотик в тот же день).
# До hotfix мая 2026 дедуп шёл по counterparty_id и мульти-платформенный
# клиент терял вторую подписку — теперь по subscription_id из extra_fields.
_RENEWAL_DEDUP_WINDOW_DAYS = 60

# Дефолтный lookahead: подписки с discount_until в [today, today+30] считаются
# приближающимися к продлению.
DEFAULT_RENEWAL_LOOKAHEAD_DAYS = 30


async def seed_renewal_pipeline(session: AsyncSession) -> int:
    """Сид воронки «Продления» и её этапов. Insert-missing pattern + advisory-lock.

    Идемпотентно: можно вызывать многократно. Возвращает число добавленных
    сущностей (воронка как одна + добавленные этапы).
    """
    await session.execute(text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_RENEWAL})
    try:
        added = 0
        pipe = (await session.execute(
            select(Pipeline).where(
                Pipeline.name == RENEWAL_PIPELINE_NAME,
                Pipeline.kind == "renewal",
            )
        )).scalar_one_or_none()
        if pipe is None:
            pipe = Pipeline(
                name=RENEWAL_PIPELINE_NAME,
                kind="renewal",
                is_active=True,
                sort_order=4,  # после sales=1 / lifecycle=2 / lead=3
            )
            session.add(pipe)
            await session.flush()
            added += 1

        existing_codes = {
            s.code for s in (await session.execute(
                select(PipelineStage).where(PipelineStage.pipeline_id == pipe.id)
            )).scalars().all() if s.code
        }
        for name, code, order in RENEWAL_STAGES:
            if code in existing_codes:
                continue
            session.add(PipelineStage(
                pipeline_id=pipe.id,
                name=name,
                code=code,
                sort_order=order,
                is_won=(code in {"signed", "paid"}),
                is_lost=(code == "lost"),
            ))
            added += 1
        await session.commit()
        return added
    finally:
        await session.execute(text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_RENEWAL})


async def find_renewal_pipeline(session: AsyncSession) -> Pipeline | None:
    """Возвращает первую активную renewal-воронку (или None — если сид не отработал)."""
    return (await session.execute(
        select(Pipeline)
        .where(Pipeline.kind == "renewal", Pipeline.is_active.is_(True))
        .order_by(Pipeline.id)
        .limit(1)
    )).scalar_one_or_none()


async def find_first_renewal_stage(session: AsyncSession) -> PipelineStage | None:
    """Первый этап renewal-воронки («Готов к продлению»). None если воронка пуста."""
    pipe = await find_renewal_pipeline(session)
    if pipe is None:
        return None
    return (await session.execute(
        select(PipelineStage)
        .where(PipelineStage.pipeline_id == pipe.id)
        .order_by(PipelineStage.sort_order)
        .limit(1)
    )).scalar_one_or_none()


async def scan_subscriptions_for_renewal(
    session: AsyncSession,
    lookahead_days: int = DEFAULT_RENEWAL_LOOKAHEAD_DAYS,
    today: date | None = None,
) -> int:
    """Cron-функция: создать renewal-сделки для подписок с приближающимся discount_until.

    Алгоритм:
    1. Находим Pipeline.kind='renewal' (если нет — return 0 без ошибок).
    2. Берём подписки с is_active=true и discount_until ∈ [today, today+lookahead_days].
    3. Для каждой проверяем: не было ли уже renewal-сделки по этой ПОДПИСКЕ
       (extra_fields.renewal_subscription_id) за последние 60 дней. Если была — skip.
       До hotfix мая 2026 дедуп шёл по counterparty_id — мульти-платформенный
       клиент терял вторую подписку. Теперь корректно.
    4. Создаём Deal на первом этапе renewal-воронки. owner — sup_pm_user_id
       подписки (фолбэк am/imp), валюта/сумма из fee_actual. В
       extra_fields["renewal_subscription_id"] кладём sub.id для дедупа
       и future-flow (например payment confirmation).

    Возвращает количество созданных сделок. Не падает наружу: ошибки логируются,
    транзакция коммитится по итогам цикла.

    Этой функции достаточно для MVP. Полный auto-prolongation flow (триггер →
    смена этапа → генерация документа) — следующая итерация (automation-specialist
    расширяет executor + contract-specialist подцепляет реальный рендер).
    """
    today = today or datetime.now(UTC).date()
    pipe = await find_renewal_pipeline(session)
    if pipe is None:
        logger.info("scan_subscriptions_for_renewal: renewal pipeline не найдена, skip")
        return 0
    first_stage = (await session.execute(
        select(PipelineStage)
        .where(PipelineStage.pipeline_id == pipe.id)
        .order_by(PipelineStage.sort_order)
        .limit(1)
    )).scalar_one_or_none()
    if first_stage is None:
        logger.warning("scan_subscriptions_for_renewal: renewal pipeline без этапов, skip")
        return 0

    window_high = today + timedelta(days=lookahead_days)
    # Окно дедупа — за этот срок ищем уже существующие сделки по контрагенту в renewal-воронке
    dedup_cutoff = datetime.now(UTC) - timedelta(days=_RENEWAL_DEDUP_WINDOW_DAYS)

    subs = (await session.execute(
        select(ClientSubscription).where(
            ClientSubscription.is_active.is_(True),
            ClientSubscription.discount_until.is_not(None),
            ClientSubscription.discount_until >= today,
            ClientSubscription.discount_until <= window_high,
        )
    )).scalars().all()

    created = 0
    marker_touched = False
    for sub in subs:
        # Дедуп по ПОДПИСКЕ (а не контрагенту): мульти-платформенный клиент
        # (KZ MacroSales + UAE MacroERP) — каждая подписка должна получить свою
        # renewal-сделку. До hotfix мая 2026 дедуп шёл по counterparty_id и
        # вторая подписка терялась тихо.
        #
        # CS-hotfix (0080): первичный дедуп — по маркеру НА ПОДПИСКЕ
        # (last_renewal_generated_at). Раньше дедуп опирался только на наличие
        # Deal в воронке (extra_fields.renewal_subscription_id) — если сделку
        # удалили, cron пересоздавал дубль. Теперь маркер на подписке переживает
        # удаление сделки и блокирует повторную генерацию в окне дедупа.
        if (
            sub.last_renewal_generated_at is not None
            and sub.last_renewal_generated_at >= dedup_cutoff
        ):
            _logger.info(
                "renewal: subscription %s already generated renewal at %s, skipping",
                sub.id, sub.last_renewal_generated_at,
            )
            continue

        # Вторичный дедуп (страховка): если по какой-то причине маркер не
        # проставлен, но активная renewal-сделка по этой подписке всё ещё есть —
        # не плодим. Хранение: Deal.extra_fields["renewal_subscription_id"] = sub.id.
        existing = (await session.execute(
            select(Deal.id)
            .where(
                Deal.pipeline_id == pipe.id,
                Deal.created_at >= dedup_cutoff,
                Deal.extra_fields["renewal_subscription_id"].astext == str(sub.id),
            )
            .limit(1)
        )).scalar_one_or_none()
        if existing:
            _logger.info(
                "renewal: subscription %s already has recent renewal deal (%s), skipping",
                sub.id, existing,
            )
            # Проставим маркер, чтобы в следующий тик дедупить уже по подписке.
            sub.last_renewal_generated_at = datetime.now(UTC)
            marker_touched = True
            continue

        owner_user_id = (
            sub.sup_pm_user_id or sub.am_user_id or sub.imp_pm_user_id
        )
        title = f"Продление подписки #{sub.id}"
        if sub.discount_until:
            title = f"{title} (до {sub.discount_until.isoformat()})"

        # CONTACTS 2.0 Ф4: сторона renewal-сделки — Company (источник истины).
        # Резолвим через зеркало (sub.company_id primary, counterparty_id fallback)
        # и проставляем ОБА id на Deal (company_id новый, counterparty_id зеркало),
        # как в Ф3-A для основной генерации. Реквизиты Company понадобятся, когда
        # подключим auto-generate (см. модуль-докстринг) — резолв уже здесь.
        rn_company, rn_counterparty, _rn_sublicensee = await resolve_party(
            session, company_id=sub.company_id, counterparty_id=sub.counterparty_id,
        )
        deal_company_id = rn_company.id if rn_company else sub.company_id
        deal_counterparty_id = (
            (rn_company.counterparty_id if rn_company else None)
            or (rn_counterparty.id if rn_counterparty else None)
            or sub.counterparty_id
        )

        deal = Deal(
            pipeline_id=pipe.id,
            stage_id=first_stage.id,
            company_id=deal_company_id,
            counterparty_id=deal_counterparty_id,
            title=title[:255],
            amount=sub.fee_actual,
            currency=sub.fee_currency,
            owner_user_id=owner_user_id,
            stage_changed_at=datetime.now(UTC),
            # Хранится для дедупа повторных тиков cron и future-flow
            # (e.g. payment confirmation pings обратно в подписку).
            extra_fields={"renewal_subscription_id": sub.id},
        )
        session.add(deal)
        # CS-hotfix (0080): маркер на подписке — основной дедуп для cron.
        sub.last_renewal_generated_at = datetime.now(UTC)
        created += 1

    if created or marker_touched:
        await session.commit()
        logger.info("scan_subscriptions_for_renewal: создано %d renewal-сделок", created)
    return created
