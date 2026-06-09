"""FastAPI entry point."""

from __future__ import annotations

import asyncio
import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from sqlalchemy.future import select

from app.config import get_settings
from app.db import SessionLocal
from app.models import User, UserRole
from app.routers import (
    approval_routes as approval_routes_router,
)
from app.routers import (
    auth as auth_router,
)
from app.routers import (
    auth_2fa as auth_2fa_router,
)
from app.routers import (
    sso as sso_router,
)
from app.routers import (
    contracts as contracts_router,
)
from app.routers import (
    counterparties as counterparties_router,
)
from app.routers import (
    drive as drive_router,
)
from app.routers import (
    integrations as integrations_router,
)
from app.routers import (
    licensor_accounts as licensor_accounts_router,
)
from app.routers import (
    licensors as licensors_router,
)
from app.routers import (
    template_variables as template_variables_router,
)
from app.routers import (
    templates as templates_router,
)
from app.routers import (
    users as users_router,
)
from app.routers import (
    client_categories as client_categories_router,
)
from app.routers import (
    client_groups as client_groups_router,
)
from app.routers import (
    companies as companies_router,
)
from app.routers import (
    contacts as contacts_router,
)
from app.routers import (
    contacts_v2 as contacts_v2_router,
)
from app.routers import (
    crm as crm_router,
)
from app.routers import (
    reference_registries as reference_registries_router,
)
from app.routers import (
    cs_config as cs_config_router,
)
from app.routers import (
    deals as deals_router,
)
from app.routers import (
    activities as activities_router,
)
from app.routers import (
    automation_runs as automation_runs_router,
)
from app.routers import (
    automations as automations_router,
)
from app.routers import (
    leads as leads_router,
)
from app.routers import (
    channels as channels_router,
)
from app.routers import (
    inbox as inbox_router,
)
from app.routers import (
    forms as forms_router,
)
from app.routers import (
    registry as registry_router,
)
from app.routers import (
    analytics as analytics_router,
)
from app.routers import (
    bulk_tasks as bulk_tasks_router,
)
from app.routers import (
    pipelines as pipelines_router,
)
from app.routers import (
    deals_config as deals_config_router,
)
from app.routers import (
    sequences as sequences_router,
)
from app.routers import (
    products as products_router,
)
from app.routers import (
    settings as settings_router,
)
from app.routers import (
    utils as utils_router,
)
from app.routers import (
    custom_fields as custom_fields_router,
)
from app.routers import (
    duplicates as duplicates_router,
)
from app.routers import (
    audit as audit_router,
)
from app.routers import (
    saved_filters as saved_filters_router,
)
from app.routers import (
    search as search_router,
)
from app.routers import (
    api_tokens as api_tokens_router,
)
from app.routers import (
    webhooks as webhooks_router,
)
from app.routers import (
    onboarding as onboarding_router,
)
from app.routers import (
    admin_onboarding as admin_onboarding_router,
)
from app.routers import (
    departments as departments_router,
)
from app.routers import (
    visibility as visibility_router,
)
from app.routers import (
    notifications as notifications_router,
)
# Эпик 21.2 — Notification channels (preferences + templates + broadcasts)
from app.routers import (
    notification_preferences as notification_preferences_router,
)
from app.routers import (
    notification_templates as notification_templates_router,
)
from app.routers import (
    notification_broadcasts as notification_broadcasts_router,
)
from app.routers import (
    analytics_onboarding as analytics_onboarding_router,
)
# Epic 10.5 — Personal cabinet, KPI, Multi-currency, AI chat, Motivational Card
from app.routers import (
    currency_rates as currency_rates_router,
)
from app.routers import (
    commission_rules as commission_rules_router,
)
from app.routers import (
    team_targets as team_targets_router,
)
from app.routers import (
    salary_plans as salary_plans_router,
)
from app.routers import (
    me as me_router,
)
from app.routers import (
    me_training as me_training_router,
)
from app.routers import (
    ai_chat as ai_chat_router,
)
from app.routers import (
    contract_payments as contract_payments_router,
)
# Epic 15 — Integration Hub + OAuth 2.0 Provider
from app.routers import (
    oauth as oauth_router,
)
# Epic 24 — Tasks v2: справочник категорий задач
from app.routers import (
    task_categories as task_categories_router,
)
# Epic 14.2 — Company Management (dismissal, rights transfer, schedules, vacations, prod calendar)
from app.routers import (
    admin_users as admin_users_router,
)
from app.routers import (
    work_schedules as work_schedules_router,
)
from app.routers import (
    vacations as vacations_router,
)
from app.routers import (
    production_calendar as production_calendar_router,
)
# Эпик 24.3 — TG Bot NL Intent API
from app.routers import (
    tg_bot as tg_bot_router,
)
# Эпик 24.2 — Google Calendar 2-way sync (per-user OAuth)
from app.routers import (
    google_calendar as google_calendar_router,
)
# Модуль «Финансы» Ф0 — GL-ядро, операции, остатки, простой ДДС
from app.routers import (
    finance as finance_router,
)
from app.security import hash_password
from app.services.automation_seed import seed_default_automations
from app.services.currency import seed_currency_rates
from app.services.categories import seed_categories
from app.services.customer_success import seed_cs_reference, seed_lifecycle_pipeline
from app.services.deals import seed_lost_reasons, seed_pipeline
from app.services.leads import seed_lead_pipeline
from app.services.pricing import seed_products_from_json
from app.services.renewal import seed_renewal_pipeline
from app.services.notification_seed import (
    seed_notification_preferences,
    seed_notification_templates,
)
from app.services.sla_seed import seed_default_sla_rules
from app.services.templates import (
    reseed_unchanged_templates,
    seed_licensors_from_files,
    seed_templates_from_files,
)
from app.services.visibility import seed_default_visibility_settings

settings = get_settings()
logging.basicConfig(level=settings.log_level)
logger = logging.getLogger(__name__)

# Sentry init на импорте модуля — ДО создания FastAPI app, чтобы интеграции
# (FastApi/Starlette) подхватили приложение, а logging-интеграция ловила ранние
# ошибки. Полный no-op при пустом SENTRY_DSN (логирует «Sentry disabled (no DSN)»).
from app.services.sentry_setup import init_sentry  # noqa: E402

init_sentry(settings)


async def ensure_first_admin() -> None:
    """Создаёт первого админа из ENV, если ещё нет ни одного пользователя."""
    async with SessionLocal() as session:
        existing = (await session.execute(select(User).limit(1))).scalar_one_or_none()
        if existing:
            return
        if not settings.admin_email or not settings.admin_password:
            logger.warning("ADMIN_EMAIL/ADMIN_PASSWORD не заданы, не создаю seed-админа")
            return
        admin = User(
            email=settings.admin_email.lower(),
            password_hash=hash_password(settings.admin_password),
            full_name=settings.admin_name,
            role=UserRole.admin,
        )
        session.add(admin)
        await session.commit()
        logger.info("Создан первый администратор: %s", admin.email)


async def seed_initial_data() -> None:
    """Seed шаблонов и licensors из файлов на первом старте + обновление v1-шаблонов."""
    async with SessionLocal() as session:
        await seed_templates_from_files(session)
        await seed_licensors_from_files(session)
        # Перезаписать шаблоны version=1, если файлы в репо обновились (не трогает редакции юриста)
        await reseed_unchanged_templates(session)
        # Дозалить новые продукты из products_seed.json (по code; существующие не трогает)
        await seed_products_from_json(session)
        # Категории клиентов L/M/S1/S2 (insert-missing по code, advisory-lock)
        await seed_categories(session)
        # Воронка «Продажи» + отдел + этапы DEALS 2.0 (advisory-lock, insert-missing)
        await seed_pipeline(session)
        # DEALS 2.0: реестр причин отказа (insert-missing, advisory-lock 020)
        await seed_lost_reasons(session)
        # Реестр CS: платформы/регионы/модули/чек-листы (insert-missing, advisory-lock)
        await seed_cs_reference(session)
        # Воронка «Жизненный цикл клиента» (B0–B6 / A1–A6 / C0, advisory-lock)
        await seed_lifecycle_pipeline(session)
        # Воронка лидов (эпик 1.0) — этапы new/processing/qualified/in_work/archived
        await seed_lead_pipeline(session)
        # Воронка продлений (эпик 6) — этапы ready_for_renewal..lost (advisory-lock 008)
        await seed_renewal_pipeline(session)
        # Эпик 4.2: 6 базовых PipelineAutomation'ов (advisory-lock 010).
        # Должно идти ПОСЛЕ всех seed_*_pipeline — нужны воронки + этапы для FK.
        try:
            await seed_default_automations(session)
        except Exception as e:  # noqa: BLE001
            # Падение сидера автоматизаций НЕ роняет lifespan — лишь нагружаем log.
            # Менеджер увидит «Автоматизаций пока нет», но api поднимется.
            logger.warning("seed_default_automations failed: %s", e)
        # Эпик 19: 4 базовых SLA-правила (advisory-lock 019). Должно идти ПОСЛЕ
        # seed_default_automations — он использует те же воронки, и UI группирует
        # обычные/SLA по вкладкам. Идемпотентно, catch на всё.
        try:
            await seed_default_sla_rules(session)
        except Exception as e:  # noqa: BLE001
            logger.warning("seed_default_sla_rules failed: %s", e)
        # Эпик 14: дефолтные правила visibility (scope='all' для всех entity ×
        # NULL role). Insert-missing, advisory-lock 142. Catch на всё — если
        # упадёт, поведение остаётся бэквард-совместимым (все видят всё).
        try:
            await seed_default_visibility_settings(session)
        except Exception as e:  # noqa: BLE001
            logger.warning("seed_default_visibility_settings failed: %s", e)
        # Эпик 10.5: seed начальных курсов валют (placeholder 1.0 при пустой таблице)
        try:
            await seed_currency_rates(session)
        except Exception as e:  # noqa: BLE001
            logger.warning("seed_currency_rates failed: %s", e)
        # Эпик 21.2: дефолтные notification templates (insert-missing). Идемпотентно,
        # advisory-lock. Должно идти ПОСЛЕ ensure_first_admin — preferences seeder
        # ниже использует список юзеров, так что хотя бы один должен быть.
        try:
            await seed_notification_templates(session)
        except Exception as e:  # noqa: BLE001
            logger.warning("seed_notification_templates failed: %s", e)
        # Эпик 21.2: per-user × kind × channel default preferences.
        # Idempotent, advisory-lock. Новые юзеры получат свои дефолты при
        # следующем рестарте api.
        try:
            await seed_notification_preferences(session)
        except Exception as e:  # noqa: BLE001
            logger.warning("seed_notification_preferences failed: %s", e)


@asynccontextmanager
async def lifespan(app: FastAPI):
    # P0 Security: в production отказываем запуск при слабом/дефолтном
    # JWT_SECRET (forgeable HS256). В dev — no-op. Бросает RuntimeError →
    # uvicorn не поднимется (намеренно — misconfigured prod не должен жить).
    settings.validate_production_secrets()
    # tg_bot_api_secret не обязателен (интент-эндпоинт fail-CLOSED 503 если
    # пуст), но громко предупреждаем в проде, чтобы интеграция бота не молчала.
    if settings.is_production and not settings.tg_bot_api_secret:
        logger.warning(
            "SECURITY: TG_BOT_API_SECRET не задан в production — "
            "/api/tg-bot/intent будет отдавать 503 (fail-closed). "
            "Задайте TG_BOT_API_SECRET для api+bot контейнеров."
        )

    await ensure_first_admin()
    await seed_initial_data()

    # C7: восстановить «зависшие» рассылки (pending/running старше 30 мин),
    # осиротевшие fire-and-forget задачей при прошлом rolling-restart — помечаем
    # их failed, чтобы они не висели вечно и были видны админу. Idempotent;
    # падение sweep НЕ роняет lifespan.
    try:
        from app.routers.notification_broadcasts import recover_stuck_broadcasts
        await recover_stuck_broadcasts()
    except Exception as e:  # noqa: BLE001
        import sentry_sdk
        sentry_sdk.capture_exception(e)
        logger.warning("recover_stuck_broadcasts failed: %s", e)

    # POST-AUDIT #4: восстановить «зависшие» сетевые автоматизации (tg_notify/
    # webhook/email в status='queued' старше 15 мин), осиротевшие fire-and-forget
    # таском при прошлом rolling-restart или мёртвым bg-исполнителем — помечаем
    # failed и освобождаем idem-слот, чтобы cron мог переретраить. Idempotent;
    # падение sweep НЕ роняет lifespan.
    try:
        from app.services.automation_executor import (
            recover_stuck_automation_runs,
        )
        await recover_stuck_automation_runs()
    except Exception as e:  # noqa: BLE001
        import sentry_sdk
        sentry_sdk.capture_exception(e)
        logger.warning("recover_stuck_automation_runs failed: %s", e)

    # Запуск Telegram polling в фоне (если токен задан И эта роль должна поллить).
    # В проде polling вынесен в отдельный bot-сервис, api-реплики его не запускают
    # (RUN_TELEGRAM_POLLING=false) — иначе несколько getUpdates дают Telegram 409.
    tg_task: asyncio.Task | None = None
    if settings.telegram_bot_token and settings.run_telegram_polling:
        from app.services.telegram import start_polling
        tg_task = asyncio.create_task(start_polling())

    # Эпик 4: cron-сканер PipelineAutomation (idle_in_stage_days, date_field_approaching).
    # Fire-and-forget; одна сломанная автоматизация НЕ роняет cron (catch в executor'е).
    from app.jobs.automation_cron import start_automation_cron, start_webhook_cron
    cron_task: asyncio.Task = asyncio.create_task(start_automation_cron())

    # Эпик 11.2: outbound webhook retry-loop (period=1min, отдельно от automation).
    # На scale=2 обе реплики работают параллельно — берут разные deliveries через
    # SELECT FOR UPDATE SKIP LOCKED (см. webhook_dispatcher.scan_pending_deliveries).
    webhook_cron_task: asyncio.Task = asyncio.create_task(start_webhook_cron())

    # Эпик 24.2: Google Calendar 2-way sync (period=10min). Pull новых events
    # из GCal + push любых модифицированных Activity. Если OAuth не настроен —
    # start_gcal_cron сама возвращается сразу (no-op).
    from app.jobs.gcal_sync import start_gcal_cron
    gcal_cron_task: asyncio.Task = asyncio.create_task(start_gcal_cron())

    yield

    if tg_task:
        tg_task.cancel()
        try:
            await tg_task
        except (asyncio.CancelledError, Exception):  # noqa: BLE001
            pass

    cron_task.cancel()
    try:
        await cron_task
    except (asyncio.CancelledError, Exception):  # noqa: BLE001
        pass

    webhook_cron_task.cancel()
    try:
        await webhook_cron_task
    except (asyncio.CancelledError, Exception):  # noqa: BLE001
        pass

    gcal_cron_task.cancel()
    try:
        await gcal_cron_task
    except (asyncio.CancelledError, Exception):  # noqa: BLE001
        pass


app = FastAPI(
    title="MACRO CRM API",
    version="0.2.0",
    lifespan=lifespan,
    docs_url="/api/docs",
    redoc_url="/api/redoc",
    openapi_url="/api/openapi.json",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=[settings.public_base_url],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Глобальный лимит размера тела запроса — отбиваем JSONB-bloat / DoS большими телами.
# 30 МБ — выше самого крупного легитимного аплоада (DOCX-шаблон 20 МБ + multipart-оверхед),
# но режет мегабайтные JSON-payload'ы. Проверяем заявленный Content-Length (дёшево,
# до чтения тела); стрим без заголовка не блокируем (uvicorn сам ограничит).
MAX_REQUEST_BODY_BYTES = 30 * 1024 * 1024


@app.middleware("http")
async def _limit_request_body(request, call_next):
    from fastapi.responses import JSONResponse

    cl = request.headers.get("content-length")
    if cl is not None:
        try:
            too_big = int(cl) > MAX_REQUEST_BODY_BYTES
        except ValueError:
            return JSONResponse(
                status_code=400, content={"detail": "Некорректный Content-Length"}
            )
        if too_big:
            return JSONResponse(
                status_code=413,
                content={"detail": "Тело запроса слишком большое"},
            )
    return await call_next(request)


# Routers
app.include_router(auth_router.router, prefix="/api")
# Epic 16 — Security: 2FA TOTP + SSO (Google/Yandex)
app.include_router(auth_2fa_router.router, prefix="/api")
app.include_router(sso_router.router, prefix="/api")
app.include_router(users_router.router, prefix="/api")
app.include_router(counterparties_router.router, prefix="/api")
app.include_router(templates_router.router, prefix="/api")
app.include_router(template_variables_router.router, prefix="/api")
app.include_router(contracts_router.router, prefix="/api")
app.include_router(drive_router.router, prefix="/api")
app.include_router(approval_routes_router.router, prefix="/api")
app.include_router(licensors_router.router, prefix="/api")
app.include_router(licensor_accounts_router.router, prefix="/api")
app.include_router(utils_router.router, prefix="/api")
app.include_router(integrations_router.router, prefix="/api")
app.include_router(products_router.router, prefix="/api")
app.include_router(settings_router.router, prefix="/api")
app.include_router(client_categories_router.router, prefix="/api")
app.include_router(client_groups_router.router, prefix="/api")
app.include_router(companies_router.router, prefix="/api")
app.include_router(contacts_router.router, prefix="/api")
# CONTACTS 2.0 Ф1: холдинги, файлы/папки, теги, справочники
app.include_router(contacts_v2_router.holdings_router, prefix="/api")
app.include_router(contacts_v2_router.crm_router, prefix="/api")
app.include_router(contacts_v2_router.positions_router, prefix="/api")
app.include_router(contacts_v2_router.company_types_router, prefix="/api")
# Wave 3 — admin справочники (страны/города/источники/группы продуктов)
app.include_router(reference_registries_router.countries_router, prefix="/api")
app.include_router(reference_registries_router.admin_countries_router, prefix="/api")
app.include_router(reference_registries_router.cities_router, prefix="/api")
app.include_router(reference_registries_router.admin_cities_router, prefix="/api")
app.include_router(reference_registries_router.sources_router, prefix="/api")
app.include_router(reference_registries_router.admin_sources_router, prefix="/api")
app.include_router(reference_registries_router.product_groups_router, prefix="/api")
app.include_router(reference_registries_router.admin_product_groups_router, prefix="/api")
app.include_router(crm_router.router, prefix="/api")
app.include_router(pipelines_router.router, prefix="/api")
app.include_router(deals_config_router.router, prefix="/api")
app.include_router(deals_router.router, prefix="/api")
app.include_router(leads_router.router, prefix="/api")
app.include_router(activities_router.router, prefix="/api")
app.include_router(channels_router.router, prefix="/api")
app.include_router(inbox_router.router, prefix="/api")
app.include_router(forms_router.router, prefix="/api")
app.include_router(automations_router.router, prefix="/api")
app.include_router(automation_runs_router.router, prefix="/api")
app.include_router(sequences_router.router, prefix="/api")
app.include_router(sequences_router.runs_router, prefix="/api")
app.include_router(cs_config_router.router, prefix="/api")
app.include_router(registry_router.router, prefix="/api")
app.include_router(analytics_router.router, prefix="/api")
app.include_router(bulk_tasks_router.router, prefix="/api")
# Эпик 8 — Карточка 2.0: custom fields / duplicates / audit / saved filters / search.
app.include_router(custom_fields_router.router, prefix="/api")
app.include_router(custom_fields_router.extra_fields_router, prefix="/api")
app.include_router(duplicates_router.router, prefix="/api")
app.include_router(audit_router.router, prefix="/api")
app.include_router(saved_filters_router.router, prefix="/api")
app.include_router(search_router.router, prefix="/api")
# Эпик 11.1 + 11.2: Public API tokens + Outbound webhooks
app.include_router(api_tokens_router.router, prefix="/api")
app.include_router(webhooks_router.router, prefix="/api")
app.include_router(webhooks_router.deliveries_router, prefix="/api")
# Эпик 13: Онбординг — обучающие курсы (student + admin)
app.include_router(onboarding_router.router, prefix="/api")
app.include_router(onboarding_router.wizard_router, prefix="/api")
app.include_router(admin_onboarding_router.router, prefix="/api")
# Эпик 14: Departments + Visibility ACL
app.include_router(departments_router.router, prefix="/api")
app.include_router(departments_router.assignment_router, prefix="/api")
app.include_router(visibility_router.router, prefix="/api")
# Эпик 21 — UX Upgrade: in-app notifications inbox
app.include_router(notifications_router.router, prefix="/api")
# Эпик 21.2 — Notification channels: preferences + templates + broadcasts
app.include_router(notification_preferences_router.router, prefix="/api")
app.include_router(notification_templates_router.router, prefix="/api")
app.include_router(notification_broadcasts_router.router, prefix="/api")
# Эпик 17 — Onboarding Analytics + Team Progress
app.include_router(analytics_onboarding_router.router, prefix="/api")
# Эпик 10.5 — Личный кабинет + KPI + Multi-currency + AI chat + Motivational Card
app.include_router(currency_rates_router.router, prefix="/api")
app.include_router(currency_rates_router.admin_router, prefix="/api")
app.include_router(commission_rules_router.router, prefix="/api")
app.include_router(team_targets_router.router, prefix="/api")
app.include_router(salary_plans_router.router, prefix="/api")
app.include_router(me_router.router, prefix="/api")
app.include_router(me_router.mk_router, prefix="/api")
app.include_router(me_training_router.router, prefix="/api")
app.include_router(ai_chat_router.router, prefix="/api")
app.include_router(ai_chat_router.counterparty_ai_router, prefix="/api")
app.include_router(contract_payments_router.router, prefix="/api")
app.include_router(contract_payments_router.payments_router, prefix="/api")
# Epic 15 — OAuth 2.0 Provider (third-party приложения, PKCE, /authorize, /token, /revoke, /userinfo)
app.include_router(oauth_router.router, prefix="/api")
# Epic 24 — Tasks v2
app.include_router(task_categories_router.router, prefix="/api")
# Epic 14.2 — Company Management
app.include_router(admin_users_router.router, prefix="/api")
app.include_router(work_schedules_router.router, prefix="/api")
app.include_router(vacations_router.router, prefix="/api")
app.include_router(production_calendar_router.router, prefix="/api")
# Эпик 24.3 — TG Bot NL Intent API (no cookie-auth, Bearer от бот-сервиса)
app.include_router(tg_bot_router.router)
# Эпик 24.2 — Google Calendar 2-way sync (per-user OAuth, cookie auth)
app.include_router(google_calendar_router.router, prefix="/api")
# Модуль «Финансы» Ф0 — /api/finance/* (cookie auth, fin_can-гейтинг)
app.include_router(finance_router.router, prefix="/api")


# HEAD тоже: UptimeRobot и прочие аплайв-чекеры по умолчанию шлют HEAD, иначе 405 = ложный Down
@app.api_route("/api/health", methods=["GET", "HEAD"])
async def health():
    return {"status": "ok"}
