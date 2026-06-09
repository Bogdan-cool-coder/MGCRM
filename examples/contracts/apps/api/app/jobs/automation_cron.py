"""Cron-задача для PipelineAutomation (Эпик 4) + cron-генератор renewal (Эпик 6)
+ outbound webhook delivery (Эпик 11.2).

Раз в час просыпается и проходит cron-триггеры:
- idle_in_stage_days — сделки/лиды висящие в этапе ≥ N дней;
- date_field_approaching — подписки с приближающейся датой (discount_until и др).
- scan_subscriptions_for_renewal — подписки с приближающимся discount_until →
  создание renewal-сделки (Эпик 6 MVP).
- scan_pending_sequence_runs — продвижение многошаговых cadences (Эпик 4.1).
- scan_pending_deliveries — ретрай outbound webhook'ов (Эпик 11.2).

Запуск — через asyncio.create_task в `app.main.lifespan`. Cancel в shutdown.

Конвенция:
- НИКОГДА не падать наружу: catch на каждом цикле, log + continue.
- ОДНА сломанная автоматизация / сканер НЕ блокирует остальные.
- Не зависит от Telegram (если бот не запущен — tg_notify пишет skipped).
"""
from __future__ import annotations

import asyncio
import logging

from sqlalchemy import text

from app.db import SessionLocal
from app.services.automation_executor import (
    run_date_field_escalation_scanner,
    run_date_field_scanner,
    run_escalation_scanner,
    run_idle_in_stage_scanner,
)
from app.services.customer_success import (
    recompute_all_health,
    snapshot_registry_kpis,
)
from app.services.renewal import scan_subscriptions_for_renewal
from app.services.sequence_executor import scan_pending_sequence_runs
from app.services.webhook_dispatcher import scan_pending_deliveries

logger = logging.getLogger(__name__)

# Leader-guard ключ для idle/date/escalation сканеров. На scale=2 обе реплики
# запускают cron-loop, но действия должны выполниться РОВНО один раз за проход.
# pg_try_advisory_lock(SESSION-level) на весь проход: реплика, не взявшая lock,
# тихо скипает этот тик. Дополняет (не заменяет) ux_automation_runs_idem — даже
# если leader-guard когда-нибудь не сработает, ON CONFLICT не даст дубль.
# Произвольная стабильная константа (не пересекается с seed advisory-lock'ами,
# которые транзакционные и в другом «пространстве» try-vs-xact значения не
# конфликтуют, но держим уникальной для читаемости).
_SCAN_LEADER_LOCK_KEY = 74_100_001

# Период сканирования. В MVP — раз в час. Не выносим в Settings, чтобы не плодить
# конфиги; нужно реже/чаще — простая правка тут.
SCAN_INTERVAL_SECONDS = 60 * 60  # 1 час
# Webhook deliveries проверяем чаще — раз в минуту. Retry-расписание начинается
# с 1m, и cron с 1h-периодом сильно растягивал бы пользовательский SLO.
WEBHOOK_SCAN_INTERVAL_SECONDS = 60  # 1 мин


async def _scan_once() -> None:
    """Один проход сканеров. Не падает наружу.

    CRITICAL C1: idle/date/escalation сканеры выполняют побочные действия
    (TG/webhook/задачи). На scale=2 обе реплики дёргают _scan_once. Берём
    session-level pg_try_advisory_lock на весь проход — реплика, не взявшая
    lock, тихо скипает. renewal/sequence сканеры уже идемпотентны/SKIP LOCKED,
    но включены под тот же guard для простоты (двойной запуск им не вредит).
    """
    try:
        async with SessionLocal() as session:
            got_lock = (
                await session.execute(
                    text("SELECT pg_try_advisory_lock(:k)"),
                    {"k": _SCAN_LEADER_LOCK_KEY},
                )
            ).scalar()
            if not got_lock:
                logger.debug(
                    "automation cron: leader-lock занят другой репликой — скип тика"
                )
                return
            try:
                await _run_scanners(session)
            finally:
                # Снимаем session-level lock явно (одна сессия может держать
                # несколько; unlock симметричен lock). Не критично при закрытии
                # сессии, но явный unlock освобождает раньше для соседней реплики.
                await session.execute(
                    text("SELECT pg_advisory_unlock(:k)"),
                    {"k": _SCAN_LEADER_LOCK_KEY},
                )
    except Exception as e:  # noqa: BLE001
        logger.exception("automation cron: session-level failure: %s", e)


async def _run_scanners(session) -> None:
    """Тело прохода под leader-lock'ом. Каждый сканер — в своём try/except:
    одна сломанная автоматизация/сканер НЕ блокирует остальные."""
    try:
        idle_runs = await run_idle_in_stage_scanner(session)
        if idle_runs:
            logger.info(
                "automation cron: idle_in_stage processed %d runs",
                len(idle_runs),
            )
    except Exception as e:  # noqa: BLE001
        logger.exception("automation cron: idle scanner failed: %s", e)
    try:
        esc_runs = await run_escalation_scanner(session)
        if esc_runs:
            logger.info(
                "automation cron: escalation processed %d runs",
                len(esc_runs),
            )
    except Exception as e:  # noqa: BLE001
        logger.exception("automation cron: escalation scanner failed: %s", e)
    try:
        date_runs = await run_date_field_scanner(session)
        if date_runs:
            logger.info(
                "automation cron: date_field processed %d runs",
                len(date_runs),
            )
    except Exception as e:  # noqa: BLE001
        logger.exception("automation cron: date_field scanner failed: %s", e)
    # POST-AUDIT #7: 2-й уровень SLA для date_field — проход по escalation_chain
    # для trigger_kind='date_field_approaching' (раньше эскалации работали только
    # для idle-based SLA). Дедуп — через ux_automation_runs_idem, как у соседей.
    try:
        date_esc_runs = await run_date_field_escalation_scanner(session)
        if date_esc_runs:
            logger.info(
                "automation cron: date_field escalation processed %d runs",
                len(date_esc_runs),
            )
    except Exception as e:  # noqa: BLE001
        logger.exception(
            "automation cron: date_field escalation scanner failed: %s", e
        )
    # Эпик 6: cron-генератор renewal-сделок. На практике в большинстве тиков
    # будет no-op (нет подписок с приближающимся discount_until).
    try:
        renewal_created = await scan_subscriptions_for_renewal(session)
        if renewal_created:
            logger.info(
                "renewal cron: создано %d renewal-сделок", renewal_created,
            )
    except Exception as e:  # noqa: BLE001
        logger.exception("renewal cron: scanner failed: %s", e)
    # Эпик 4.1: продвижение SequenceRun (многошаговые cadences). Один тик =
    # один шаг для каждого active run; долгие cadences растягиваются
    # естественным образом.
    try:
        seq_processed = await scan_pending_sequence_runs(session)
        if seq_processed:
            logger.info(
                "sequence cron: processed %d sequence runs",
                seq_processed,
            )
    except Exception as e:  # noqa: BLE001
        logger.exception("sequence cron: scanner failed: %s", e)
    # CS #3: фоновый пересчёт health/attention по всем активным подпискам.
    # Раньше health обновлялся только при ручном вводе активности → флаги
    # устаревали. Recompute коммитит внутри себя (батчами), webhook не шлёт.
    try:
        health_processed = await recompute_all_health(session)
        if health_processed:
            logger.info(
                "cs cron: recomputed health for %d subscriptions",
                health_processed,
            )
    except Exception as e:  # noqa: BLE001
        logger.exception("cs cron: health recompute failed: %s", e)
    # CS #2: daily-снимок KPI реестра (тренды дашборда). Self-gating до раза
    # в сутки (snapshot_registry_kpis скипает, если за сегодня уже писали),
    # поэтому безопасно вызывать каждый часовой тик. Коммитит внутри себя.
    try:
        kpi_rows = await snapshot_registry_kpis(session)
        if kpi_rows:
            logger.info(
                "cs cron: wrote %d registry KPI snapshot rows", kpi_rows,
            )
    except Exception as e:  # noqa: BLE001
        logger.exception("cs cron: KPI snapshot failed: %s", e)
    await session.commit()


async def _scan_webhooks_once() -> None:
    """Один проход webhook-сканера. Не падает наружу."""
    try:
        async with SessionLocal() as session:
            try:
                processed = await scan_pending_deliveries(session)
                if processed:
                    logger.info(
                        "webhook cron: processed %d deliveries", processed,
                    )
            except Exception as e:  # noqa: BLE001
                logger.exception("webhook cron: scanner failed: %s", e)
    except Exception as e:  # noqa: BLE001
        logger.exception("webhook cron: session-level failure: %s", e)


async def start_automation_cron() -> None:
    """Бесконечный fire-and-forget loop. Запускается через asyncio.create_task
    в lifespan. Прерывается через task.cancel() в shutdown.

    KeyboardInterrupt и CancelledError — нормальная остановка, не лог.
    """
    logger.info("automation cron started (interval=%ds)", SCAN_INTERVAL_SECONDS)
    try:
        while True:
            await _scan_once()
            await asyncio.sleep(SCAN_INTERVAL_SECONDS)
    except (asyncio.CancelledError, KeyboardInterrupt):
        logger.info("automation cron stopped")
        raise
    except Exception as e:  # noqa: BLE001
        # Если упало по непонятной причине — лог и тихо умираем (рестарт api поднимет).
        logger.exception("automation cron crashed: %s", e)


async def start_webhook_cron() -> None:
    """Отдельный fire-and-forget loop для webhook-доставки (period=1 min).

    Раздельный loop с automation_cron, потому что период намного меньше:
    automation cron 1 час (idle/date scanners инерционны), webhook — 1 мин
    (retry-schedule начинается с 60s).
    """
    logger.info(
        "webhook cron started (interval=%ds)", WEBHOOK_SCAN_INTERVAL_SECONDS,
    )
    try:
        while True:
            await _scan_webhooks_once()
            await asyncio.sleep(WEBHOOK_SCAN_INTERVAL_SECONDS)
    except (asyncio.CancelledError, KeyboardInterrupt):
        logger.info("webhook cron stopped")
        raise
    except Exception as e:  # noqa: BLE001
        logger.exception("webhook cron crashed: %s", e)
