"""PipelineAutomation executor (Эпик 4).

Универсальный движок: триггеры (on_enter_stage / idle_in_stage_days /
date_field_approaching) запускают действия (tg_notify / create_task / set_field /
generate_document) на сделках, лидах, подписках.

Архитектура:
- `run_on_enter_stage` — inline-путь запроса, дёргается из роутеров /deals|/leads
  после смены stage_id. Выполняет все active автоматизации с
  trigger_kind='on_enter_stage' и (stage_id=Y OR stage_id IS NULL) для воронки.
  POST-AUDIT #4: DB-local действия синхронны, СЕТЕВЫЕ (tg_notify/webhook/email)
  откладываются в фоновый таск на свежей сессии (defer_network=True по умолчанию)
  — drag карточки не ждёт сеть.
- `run_idle_in_stage_scanner` — cron, раз в час. Ищет цели, висящие в этапе ≥ N дней.
- `run_date_field_scanner` — cron, раз в час. Ищет цели, у которых поле даты попало
  в окно [today + N - 1, today + N + 1].
- `execute_action` — единая точка выполнения одного действия с записью AutomationRun.
- `dry_run_action` — preview для UI test endpoint; не выполняет side-effects, не
  пишет AutomationRun.

Защита от повтора (cron-триггеры):
- перед выполнением проверяем последний AutomationRun (automation_id, target,
  status IN ('success','skipped')) за окно (для idle — N дней, для date_field —
  N дней). Если был — skip с status='skipped'.

Безопасность action_kind='set_field':
- whitelist полей per-target — security-чувствительные (`role`, `password_hash`,
  `stage_id` для cross-stage moves) запрещены.

Идемпотентность миграций НЕ нужна — executor исключительно runtime-сервис.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import logging
from datetime import UTC, date, datetime, timedelta
from typing import Any

from sqlalchemy import func, or_, text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import SessionLocal
from app.models import (
    Activity,
    AutomationRun,
    ClientSubscription,
    Counterparty,
    Deal,
    Lead,
    PipelineAutomation,
    Setting,
    User,
    UserRole,
)

logger = logging.getLogger(__name__)


# ============ Whitelists / константы (используются также в роутерах и тестах) ============

# Триггеры. Эпик 4 MVP — три cron/inline-триггера. Эпик 4.1 — добавлен on_create
# (синхронный, дёргается из POST /leads, POST /deals, inbox.auto_create_lead_*).
# Расширения — `field_value_changed`, `activity_completed`, `on_update` — отложено.
AUTOMATION_TRIGGERS: tuple[str, ...] = (
    "on_enter_stage",
    "idle_in_stage_days",
    "date_field_approaching",
    "on_create",
)

# Действия. Эпик 4 MVP — четыре базовых. Эпик 4.1 — добавлены change_owner,
# webhook, email, start_sequence.
AUTOMATION_ACTIONS: tuple[str, ...] = (
    "tg_notify",
    "create_task",
    "set_field",
    "generate_document",
    "change_owner",
    "webhook",
    "email",
    "start_sequence",
    # Эпик 23 — Конструктор воронок AmoCRM-style: 4 новых action_kind для
    # визуального конструктора.
    "set_tags",
    "complete_tasks",
    "change_stage",
    "create_deal",
)

# POST-AUDIT #4 (fire-and-forget): СЕТЕВЫЕ действия, чьё исполнение делает
# блокирующий outbound IO (TG send / webhook POST / SMTP). В inline-пути запроса
# (move/create) их откладываем в фон, чтобы drag карточки / POST не ждал сеть.
# DB-local действия (set_field / create_task / change_owner / generate_document /
# set_tags / complete_tasks / change_stage / create_deal / start_sequence) НЕ
# здесь — они быстрые и их семантика «применилось сразу» важна (остаются inline).
NETWORK_ACTIONS: frozenset[str] = frozenset({"tg_notify", "webhook", "email"})

# Статус AutomationRun, означающий «слот заклеймлен, действие отложено в фон и
# ещё не доставлено». Держит idem-слот (как success/skipped) → конкурентный
# cron-тик / вторая реплика scale=2 не переклеймят строку, пока фоновый таск
# в полёте. Фоновый таск переводит run в success/failed/skipped на свежей сессии.
_QUEUED_STATUS = "queued"


def should_defer_action(action_kind: str, defer_network: bool) -> bool:
    """Pure-функция диспетчеризации (POST-AUDIT #4): уходит ли действие в фон.

    True ТОЛЬКО если:
    - defer_network=True (т.е. вызов из inline-пути запроса — move/create), И
    - action_kind ∈ NETWORK_ACTIONS (tg_notify/webhook/email — блокирующий IO).

    Cron-сканеры зовут execute_action с defer_network=False (default) → всегда
    False → классификация не меняется, действие исполняется синхронно как раньше
    (cron уже в фоне, дедуп завязан на синхронную запись run).

    DB-local действия (set_field/create_task/change_owner/…) → всегда False:
    их «применилось сразу» важно и они быстрые.
    """
    return defer_network and action_kind in NETWORK_ACTIONS


# Правила маршрутизации для change_owner. Эпик 4.1.
CHANGE_OWNER_RULES: tuple[str, ...] = (
    "round_robin",
    "by_product",
    "by_country",
    "by_department",
)

# Target types для AutomationRun.target_type — те три сущности, у которых есть
# понятие «этап воронки + owner» в MVP.
AUTOMATION_TARGET_TYPES: tuple[str, ...] = ("deal", "lead", "subscription")

# Whitelist полей для set_field, per-target. Security-чувствительные поля
# (`stage_id` для cross-stage moves, owner для re-assign, контрактные поля
# `amount`/`currency` для Deal) намеренно НЕ включены — для них есть отдельные
# роутер-эндпоинты с правильной валидацией. Расширяется по запросу.
SET_FIELD_WHITELIST: dict[str, frozenset[str]] = {
    "deal": frozenset({"notes", "title"}),
    "lead": frozenset({"notes", "status"}),
    "subscription": frozenset({"notes", "health_tier", "manual_tier_override"}),
}

# MEDIUM-фикс: допустимые значения health_tier / manual_tier_override для
# subscription. set_field больше НЕ пишет произвольную строку в эти поля —
# валидируем против CS-набора A1..A6 + C0 (см. cs-specialist lifecycle). Пустая
# строка/None допускаются для manual_tier_override (снять ручной override).
SUBSCRIPTION_TIER_VALUES: frozenset[str] = frozenset(
    {"A1", "A2", "A3", "A4", "A5", "A6", "C0"}
)

# HIGH-фикс: whitelist дат-полей для date_field_approaching, per-target.
# Расширен на deal/lead (раньше был inline только subscription). Module-level —
# переиспользуется escalation-сканером и тестами.
DATE_FIELDS: dict[str, frozenset[str]] = {
    "subscription": frozenset({
        "discount_until",
        "impl_start_date",
        "act_signed_date",
        "last_fee_increase_at",
        "qa_date",
    }),
    "deal": frozenset({"closed_at"}),
    "lead": frozenset({"converted_at"}),
}

# Эпик 23 — set_tags: режимы изменения списка тегов на target.
# - add     — добавить теги (объединение с текущими), без дубликатов
# - replace — полная замена текущих тегов на указанные
# - remove  — удалить указанные теги из текущих (no-op если не было)
SET_TAGS_MODES: tuple[str, ...] = ("add", "replace", "remove")

# Эпик 23 — set_tags: какие модели поддерживают tags. Сейчас только Lead имеет
# tags JSON-колонку (см. models.Lead.tags). Counterparty/Deal — в roadmap
# отдельной миграции эпика разделения сущностей; пока пропускаем с warning.
SET_TAGS_TARGETS: frozenset[str] = frozenset({"lead"})

# Эпик 23 — complete_tasks: режимы фильтрации задач для массового завершения.
# - all       — завершить ВСЕ activities(kind='task') у target (включая уже
#               завершённые — даст timestamp заново; нужно для re-confirm)
# - open_only — завершить только незавершённые (completed_at IS NULL); default
COMPLETE_TASKS_FILTERS: tuple[str, ...] = ("all", "open_only")


# ============ Внутренние helpers ============

_TARGET_MODEL_MAP = {
    "deal": Deal,
    "lead": Lead,
    "subscription": ClientSubscription,
}


def _get_target_model(target_type: str):
    """Возвращает SQLAlchemy-модель по target_type (или None если не поддерживаем)."""
    return _TARGET_MODEL_MAP.get(target_type)


async def _fetch_target(
    session: AsyncSession, target_type: str, target_id: int
):
    """Подгружает target по типу+id или None. Не падает на unknown target_type."""
    model = _get_target_model(target_type)
    if model is None:
        return None
    return (
        await session.execute(select(model).where(model.id == target_id))
    ).scalar_one_or_none()


def _get_target_owner_user_id(target) -> int | None:
    """Извлекает user_id владельца из target (Deal.owner_user_id / Lead.owner_id /
    Subscription.sup_pm_user_id с фолбэками)."""
    if isinstance(target, Deal):
        return target.owner_user_id
    if isinstance(target, Lead):
        return target.owner_id
    if isinstance(target, ClientSubscription):
        return target.sup_pm_user_id or target.am_user_id or target.imp_pm_user_id
    return None


async def _sync_department_from_owner(
    session: AsyncSession, target, new_owner_id: int | None
) -> None:
    """HIGH-фикс: при смене owner на Deal/Lead пересчитать зеркало department_id
    из User.department_id нового owner.

    Deal.department_id / Lead.department_id — денормализованное зеркало
    owner.department_id, которое раньше поддерживалось ТОЛЬКО в роутерах
    deals.py/leads.py. Автоматизации (_action_change_owner / _action_set_field /
    _action_create_deal) меняли owner напрямую и оставляли department_id
    устаревшим → ломали department-scoped visibility и KPI-снапшоты.

    Subscription не имеет owner→department-зеркала (CS-scope иной) — для него
    no-op. Если у target нет атрибута department_id — no-op.
    """
    if not hasattr(target, "department_id"):
        return
    if new_owner_id is None:
        target.department_id = None
        return
    user = (
        await session.execute(select(User).where(User.id == new_owner_id))
    ).scalar_one_or_none()
    target.department_id = user.department_id if user else None


def _resolve_recipient(
    recipient_str: str | None, target, owner: User | None
) -> tuple[str, int | str | None]:
    """Resolve recipient_str в (kind, value).

    Forms:
    - "owner"         → ("telegram_user_id", owner.telegram_user_id) — личка владельцу
    - "user_id:N"     → ("user_id", N) — резолвить телеграм перед отправкой
    - "chat_id:N"     → ("chat_id", N) — отправить напрямую в чат/группу
    - None / ""       → ("none", None)
    """
    if not recipient_str:
        return ("none", None)
    rs = recipient_str.strip()
    if rs == "owner":
        if owner and owner.telegram_user_id:
            return ("telegram_user_id", owner.telegram_user_id)
        return ("none", None)
    if rs.startswith("user_id:"):
        try:
            return ("user_id", int(rs.split(":", 1)[1]))
        except (ValueError, IndexError):
            return ("none", None)
    if rs.startswith("chat_id:"):
        try:
            return ("chat_id", int(rs.split(":", 1)[1]))
        except (ValueError, IndexError):
            return ("none", None)
    return ("none", None)


def _resolve_user_id(
    spec: str | None, target, owner: User | None
) -> int | None:
    """Resolve assignee/responsible spec → user_id.

    Forms:
    - "owner"     → owner.id (если есть)
    - "user_id:N" → N
    - None / ""   → owner.id (default) или None
    """
    if not spec or spec == "owner":
        return owner.id if owner else None
    if spec.startswith("user_id:"):
        try:
            return int(spec.split(":", 1)[1])
        except (ValueError, IndexError):
            return None
    return None


def _format_message(template: str, target, owner: User | None) -> str:
    """Простая подстановка плейсхолдеров в шаблон сообщения.

    Поддерживает: {target_id}, {target_type}, {target_title}, {owner_name}.
    Без jinja — runtime safety и нулевой риск инъекций.
    """
    if not template:
        return ""
    target_title = ""
    if isinstance(target, Deal) and target.title:
        target_title = target.title
    elif isinstance(target, Lead) and target.name:
        target_title = target.name
    elif isinstance(target, ClientSubscription):
        target_title = f"подписка #{target.id}"
    target_type_label = {
        "deal": "сделка",
        "lead": "лид",
        "subscription": "подписка",
    }
    target_type = (
        "deal" if isinstance(target, Deal)
        else "lead" if isinstance(target, Lead)
        else "subscription" if isinstance(target, ClientSubscription)
        else "?"
    )
    return (
        template.replace("{target_id}", str(getattr(target, "id", "")))
        .replace("{target_type}", target_type_label.get(target_type, target_type))
        .replace("{target_title}", target_title)
        .replace("{owner_name}", owner.full_name if owner else "—")
    )


# ============ Защита от повторного срабатывания (cron-триггеры) ============


def floor_to_hour(dt: datetime) -> datetime:
    """Округлить datetime вниз до начала часа (UTC-aware сохраняется).

    Используется для idle-триггера: trigger_event_ts = начало окна,
    округлённое до часа, чтобы повторные тики cron в пределах одного часа
    давали один и тот же trigger_event_ts → INSERT ON CONFLICT дедупит.
    """
    return dt.replace(minute=0, second=0, microsecond=0)


# Терминальные статусы, при которых заклеймленный idem-слот (trigger_event_ts)
# СОХРАНЯЕТСЯ → дедуп держит, повтора нет. Всё, что НЕ здесь (т.е. 'failed'),
# освобождает слот → следующий cron-тик заново claim'нет и повторит действие.
# 'queued' (POST-AUDIT #4 fire-and-forget) тоже держит слот: пока фоновый таск
# в полёте, конкурентный тик/реплика не должны переклеймить и продублировать
# доставку. Фоновый таск сам переведёт run в success/failed/skipped (и при
# failed освободит слот через should_release_idem_slot).
_DEDUP_HOLDING_STATUSES: frozenset[str] = frozenset(
    {"success", "skipped", _QUEUED_STATUS}
)


def should_release_idem_slot(status: str, trigger_event_ts: datetime | None) -> bool:
    """Pure-функция: нужно ли освободить idem-слот (обнулить trigger_event_ts)
    после терминального статуса прогона.

    Контракт at-least-once-on-failure + dedup-on-success:
    - claim_run_slot вставляет pending-строку по partial UNIQUE
      (automation_id, target_type, target_id, trigger_event_ts).
    - success / skipped → слот ДЕРЖИМ (return False): повторный тик/реплика
      получит ON CONFLICT → не продублирует доставленное действие.
    - failed → слот ОСВОБОЖДАЕМ (return True): обнуление trigger_event_ts
      выводит строку из-под `WHERE trigger_event_ts IS NOT NULL`, и следующий
      cron-тик с тем же event_ts заново claim'нет → действие ретраится
      (transient TG 500 / webhook timeout больше не теряется навсегда).
    - trigger_event_ts is None (ручной execute / retry) → дедупа и так нет,
      освобождать нечего (return False).
    """
    if trigger_event_ts is None:
        return False
    return status not in _DEDUP_HOLDING_STATUSES


async def claim_run_slot(
    session: AsyncSession,
    automation_id: int,
    target_type: str,
    target_id: int,
    trigger_event_ts: datetime,
) -> bool:
    """Транзакционно «застолбить» слот выполнения через INSERT ... ON CONFLICT
    DO NOTHING на partial UNIQUE-индексе ux_automation_runs_idem.

    Возвращает True, если строка вставлена (слот наш — можно выполнять действие),
    False, если такой (automation_id, target_type, target_id, trigger_event_ts)
    уже есть (другая реплика scale=2 / повторный тик cron уже отработал).

    Создаёт run в статусе 'pending'; вызывающий execute_action затем обновляет
    его до success/failed/skipped. Это закрывает CRITICAL C1/C2/C3: даже если
    обе реплики одновременно зашли в сканер, INSERT победит только у одной.

    NB: рассчитывает на наличие колонки trigger_event_ts и индекса (миграция
    0081). RETURNING id отличает вставку от конфликта.
    """
    started = datetime.now(UTC)
    row = (
        await session.execute(
            text(
                "INSERT INTO automation_runs "
                "(automation_id, target_type, target_id, trigger_event_ts, "
                " status, started_at) "
                "VALUES (:aid, :tt, :tid, :ts, 'pending', :started) "
                "ON CONFLICT (automation_id, target_type, target_id, "
                " trigger_event_ts) WHERE trigger_event_ts IS NOT NULL "
                "DO NOTHING "
                "RETURNING id"
            ),
            {
                "aid": automation_id,
                "tt": target_type,
                "tid": target_id,
                "ts": trigger_event_ts,
                "started": started,
            },
        )
    ).first()
    return row is not None


def is_recently_run_for_window(
    last_run_at: datetime | None,
    now: datetime,
    window_days: int,
    window_hours: int = 0,
) -> bool:
    """Pure-функция для теста dedup-логики: окно (window_days + window_hours) с
    момента последнего запуска ещё не истёк. last_run_at None = НЕ был запущен.

    Эпик 19: добавлен `window_hours` для SLA-сценариев. Минимум окна — 1 час.
    """
    if last_run_at is None:
        return False
    delta = timedelta(days=max(0, window_days), hours=max(0, window_hours))
    if delta.total_seconds() < 3600:
        delta = timedelta(hours=1)
    cutoff = now - delta
    return last_run_at >= cutoff


def parse_idle_window(trigger_config: dict[str, Any]) -> tuple[int, int]:
    """Pure-функция: распарсить окно idle из trigger_config.

    Поддерживает два формата:
    - {"days": N}              → (N, 0) — стандартный MVP-формат
    - {"idle_in_stage_hours": H}  → (0, H) — SLA-формат Эпика 19 для часов
    - {"days": N, "idle_in_stage_hours": H} → (N, H) — комбинированный

    Если ни одного — возвращает (7, 0) (дефолт MVP).
    Все значения нормализуются: int → max(0, .), нечитаемые → 0.
    """
    def _safe_int(v: Any) -> int:
        try:
            return max(0, int(v))
        except (ValueError, TypeError):
            return 0

    days_raw = trigger_config.get("days")
    hours_raw = trigger_config.get("idle_in_stage_hours")

    if days_raw is None and hours_raw is None:
        return (7, 0)  # дефолт MVP

    days = _safe_int(days_raw) if days_raw is not None else 0
    hours = _safe_int(hours_raw) if hours_raw is not None else 0

    # Если оба 0 — отдадим (1, 0) (минимальное окно), чтобы избежать
    # бесконечного срабатывания.
    if days == 0 and hours == 0:
        return (1, 0)
    return (days, hours)


# ============ Действия (action_kind) ============

async def _action_tg_notify(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """Отправить TG-сообщение. Owner / chat_id / user_id (с резолвом telegram_user_id)."""
    cfg = automation.action_config or {}
    recipient_spec = cfg.get("recipient", "owner")
    message_template = cfg.get("message", "")

    kind, value = _resolve_recipient(recipient_spec, target, owner)
    if kind == "user_id" and value is not None:
        # резолвим telegram_user_id из User
        user = (
            await session.execute(select(User).where(User.id == int(value)))
        ).scalar_one_or_none()
        if user and user.telegram_user_id:
            kind, value = ("telegram_user_id", user.telegram_user_id)
        else:
            return {
                "skipped": True,
                "reason": f"у пользователя {value} не привязан Telegram",
            }
    if kind == "none" or value is None:
        return {"skipped": True, "reason": "не задан получатель"}

    message = _format_message(message_template, target, owner)
    if not message:
        return {"skipped": True, "reason": "пустое сообщение"}

    # Реальная отправка через aiogram. Импорт lazy (не падать в тестах без токена).
    try:
        from app.config import get_settings
        from app.services.telegram import get_bot
        settings = get_settings()
        if not settings.telegram_bot_token:
            return {"skipped": True, "reason": "TELEGRAM_BOT_TOKEN не задан"}
        bot = get_bot()
        await bot.send_message(chat_id=int(value), text=message)
        return {"sent": True, "chat_id": value, "message": message}
    except Exception as e:  # noqa: BLE001
        logger.warning("tg_notify failed: %s", e)
        raise


async def _action_create_task(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """Создать Activity(kind='task') для target. Defaults: responsible=owner, due_days=1."""
    cfg = automation.action_config or {}
    title_tpl = cfg.get("title") or f"Автозадача: {automation.name}"
    body_tpl = cfg.get("body") or ""
    responsible_spec = cfg.get("responsible", "owner")
    due_days = cfg.get("due_days")
    try:
        due_days_int = int(due_days) if due_days is not None else None
    except (ValueError, TypeError):
        due_days_int = None

    target_type = (
        "deal" if isinstance(target, Deal)
        else "lead" if isinstance(target, Lead)
        else "subscription"
    )
    responsible_id = _resolve_user_id(responsible_spec, target, owner)

    title = _format_message(title_tpl, target, owner)
    body = _format_message(body_tpl, target, owner) if body_tpl else None
    due_at = (
        datetime.now(UTC) + timedelta(days=due_days_int)
        if due_days_int is not None and due_days_int > 0
        else None
    )

    activity = Activity(
        kind="task",
        target_type=target_type,
        target_id=target.id,
        title=title[:255],
        body=body,
        due_at=due_at,
        responsible_id=responsible_id,
        created_by_id=automation.created_by_user_id or (owner.id if owner else None),
    )
    # created_by_id NOT NULL — фолбэк: если автоматизация без author и target без owner,
    # ищем любого admin (не должно случаться в норме, но защита от 500).
    if activity.created_by_id is None:
        admin = (
            await session.execute(
                select(User).where(User.is_active.is_(True)).limit(1)
            )
        ).scalar_one_or_none()
        if admin:
            activity.created_by_id = admin.id
        else:
            return {"skipped": True, "reason": "нет пользователя для created_by_id"}

    session.add(activity)
    await session.flush()

    # Эпик 21: in-app notification responsible'у. НЕ заменяет TG (если
    # отдельная tg_notify-автоматизация на том же этапе) — оба канала
    # работают параллельно. catch на всё — не валим основной flow.
    if responsible_id:
        try:
            from app.services.notifications import (
                build_task_assigned_notification,
                safe_create_notification,
            )
            notif_data = build_task_assigned_notification(
                task_id=activity.id,
                task_title=title or f"Задача #{activity.id}",
                responsible_user_id=responsible_id,
                target_type=target_type,
                target_id=target.id,
                due_at_iso=due_at.isoformat() if due_at else None,
            )
            await safe_create_notification(session, **notif_data)
        except Exception as e:  # noqa: BLE001
            logger.warning(
                "task_assigned notification failed for activity %s: %s",
                activity.id, e,
            )
    return {
        "created_activity_id": activity.id,
        "responsible_id": responsible_id,
        "due_at": due_at.isoformat() if due_at else None,
    }


async def _action_set_field(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """Обновить одно поле у target. Whitelist обязателен."""
    cfg = automation.action_config or {}
    field = cfg.get("field")
    value = cfg.get("value")
    if not field:
        return {"skipped": True, "reason": "не указано поле"}

    target_type = (
        "deal" if isinstance(target, Deal)
        else "lead" if isinstance(target, Lead)
        else "subscription" if isinstance(target, ClientSubscription)
        else None
    )
    if target_type is None:
        return {"skipped": True, "reason": "неизвестный target_type"}

    allowed = SET_FIELD_WHITELIST.get(target_type, frozenset())
    if field not in allowed:
        return {
            "skipped": True,
            "reason": f"поле '{field}' не разрешено для {target_type}",
        }

    # MEDIUM-фикс: tier-поля subscription нельзя писать произвольной строкой.
    # health_tier — обязан быть из A1..A6/C0. manual_tier_override — то же, но
    # допускает None/"" (снятие override).
    if target_type == "subscription" and field in (
        "health_tier", "manual_tier_override"
    ):
        if field == "manual_tier_override" and (value is None or value == ""):
            value = None  # снять override — допустимо
        elif str(value) not in SUBSCRIPTION_TIER_VALUES:
            return {
                "skipped": True,
                "reason": (
                    f"невалидный tier '{value}' для {field}; "
                    f"допустимы {sorted(SUBSCRIPTION_TIER_VALUES)}"
                ),
            }

    old = getattr(target, field, None)
    try:
        setattr(target, field, value)
        await session.flush()
        return {"field": field, "old": str(old) if old is not None else None, "new": value}
    except Exception as e:  # noqa: BLE001
        logger.warning("set_field %s.%s failed: %s", target_type, field, e)
        raise


async def _action_generate_document(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """Заглушка-делегат: для MVP пишем note-Activity «Запрошен документ X».

    Полная интеграция (вызов `render.generate_contract_files` с реальным контекстом
    из Deal/Counterparty/Contract) — это зона `contract-specialist`. Для MVP
    автоматизация фиксирует факт запроса в timeline; реальный рендер будет
    подключён в следующей итерации после ТЗ от contract-specialist'a.
    """
    cfg = automation.action_config or {}
    template_code = cfg.get("template_code", "")
    if not template_code:
        return {"skipped": True, "reason": "template_code не задан"}

    if not isinstance(target, (Deal, Lead, ClientSubscription)):
        return {"skipped": True, "reason": "неподходящий target_type"}

    target_type = (
        "deal" if isinstance(target, Deal)
        else "lead" if isinstance(target, Lead)
        else "subscription"
    )

    note = Activity(
        kind="note",
        target_type=target_type,
        target_id=target.id,
        title=f"Автоматизация запросила документ: {template_code}",
        body=(
            f"Автоматизация «{automation.name}» инициировала генерацию документа "
            f"по шаблону `{template_code}`. Полная интеграция с рендером — TBD "
            "(зона contract-specialist)."
        ),
        created_by_id=automation.created_by_user_id or (owner.id if owner else None),
    )
    if note.created_by_id is None:
        admin = (
            await session.execute(
                select(User).where(User.is_active.is_(True)).limit(1)
            )
        ).scalar_one_or_none()
        if admin:
            note.created_by_id = admin.id
        else:
            return {"skipped": True, "reason": "нет пользователя для created_by_id"}

    session.add(note)
    await session.flush()
    return {
        "stub": True,
        "template_code": template_code,
        "note_activity_id": note.id,
        "tbd": "real render via contract-specialist",
    }


# ============ Pure helpers для change_owner / webhook (Эпик 4.1) ============

# Префикс ключа в settings для хранения round-robin курсора.
ROUND_ROBIN_CURSOR_PREFIX = "automation_round_robin_cursor:"


def round_robin_pick(pool: list[int], cursor: int) -> tuple[int, int]:
    """Pure-функция: из pool выбрать элемент по cursor, вернуть (picked, next_cursor).

    cursor % len(pool) даёт текущий индекс; next_cursor = (cursor + 1) % len(pool).
    Это позволяет хранить любую растущую целочисленную метку в Setting (счётчик
    запусков) и не зависеть от модификаций pool между итерациями.

    Raises:
        ValueError: если pool пуст.
    """
    if not pool:
        raise ValueError("round_robin_pick: pool пуст")
    n = len(pool)
    idx = cursor % n
    next_cursor = (cursor + 1) % max(n, 1)
    return pool[idx], next_cursor


def build_webhook_signature(secret: str, body: bytes) -> str:
    """HMAC-SHA256 от body с secret; формат заголовка X-Macro-Signature: sha256=<hex>.

    Pure-функция: возвращает значение для header (без префикса 'X-Macro-Signature:').
    """
    digest = hmac.new(
        secret.encode("utf-8"), body, hashlib.sha256
    ).hexdigest()
    return f"sha256={digest}"


def resolve_owner_field_name(target_type: str) -> str | None:
    """Имя колонки owner на target_type (для UPDATE в change_owner).

    Возвращает None для unsupported target. ClientSubscription: используем
    sup_pm_user_id как «owner» по умолчанию (см. _get_target_owner_user_id).
    """
    return {
        "deal": "owner_user_id",
        "lead": "owner_id",
        "subscription": "sup_pm_user_id",
    }.get(target_type)


def compute_next_step_at(now: datetime, delay_days: int) -> datetime:
    """now + delay_days (нормализуем отрицательный delay в 0)."""
    return now + timedelta(days=max(0, int(delay_days)))


# ============ Setting helpers (cursor для round_robin) ============

# Префикс для PG advisory_xact_lock — используется чтобы получить детерминированный
# int4 lock-key из automation_id. Тот же паттерн что в миграциях seed.
_ROUND_ROBIN_LOCK_KEY_PREFIX = "rr:automation:"


async def _acquire_round_robin_lock(
    session: AsyncSession, automation_id: int
) -> None:
    """Получить транзакционный advisory lock per-automation_id для round_robin курсора.

    Защита от race condition при scale=2 (несколько api-воркеров могут одновременно
    SELECT курсор → одинаковый next_owner → дубль назначения):
    - pg_advisory_xact_lock(hashtext('rr:automation:<id>')) сериализует доступ к
      курсору этой конкретной автоматизации.
    - Lock автоматически снимается при commit/rollback транзакции — не нужно явно
      освобождать (xact_lock в имени).
    - Hashtext возвращает int4 (стабильный hash строки), идеален для advisory_lock,
      который принимает bigint/int4 ключ.
    - Per-automation_id означает, что разные автоматизации не блокируют друг друга.
    """
    key = f"{_ROUND_ROBIN_LOCK_KEY_PREFIX}{automation_id}"
    await session.execute(
        text("SELECT pg_advisory_xact_lock(hashtext(:k))"), {"k": key}
    )


async def _read_round_robin_cursor(
    session: AsyncSession, automation_id: int
) -> int:
    """Прочитать cursor из settings; вернуть 0 если пусто или невалидно."""
    key = f"{ROUND_ROBIN_CURSOR_PREFIX}{automation_id}"
    setting = (
        await session.execute(select(Setting).where(Setting.key == key))
    ).scalar_one_or_none()
    if setting is None or setting.value is None:
        return 0
    try:
        return int(setting.value)
    except (ValueError, TypeError):
        return 0


async def _write_round_robin_cursor(
    session: AsyncSession, automation_id: int, cursor: int
) -> None:
    """Записать cursor в settings (upsert через select+update/insert)."""
    key = f"{ROUND_ROBIN_CURSOR_PREFIX}{automation_id}"
    setting = (
        await session.execute(select(Setting).where(Setting.key == key))
    ).scalar_one_or_none()
    if setting is None:
        setting = Setting(key=key, value=str(cursor))
        session.add(setting)
    else:
        setting.value = str(cursor)
    await session.flush()


# ============ change_owner / webhook / email / start_sequence handlers (Эпик 4.1) ============

async def _resolve_user_pool(
    session: AsyncSession, user_pool_filter: dict[str, Any]
) -> list[int]:
    """Собрать список user_ids по фильтру `{role?, department?, is_active?: true}`.

    Возвращает отсортированный по id список (стабильный порядок для round_robin).
    is_active по умолчанию True (отключённых не назначаем).
    """
    stmt = select(User)
    role = user_pool_filter.get("role")
    if role:
        try:
            stmt = stmt.where(User.role == UserRole(role))
        except ValueError:
            # Неизвестная роль — возвращаем пустой пул (handler пометит skipped)
            return []
    department = user_pool_filter.get("department")
    if department is not None:
        # `department` может быть id (int) или строкой
        try:
            stmt = stmt.where(User.department_id == int(department))
        except (ValueError, TypeError):
            return []
    is_active = user_pool_filter.get("is_active", True)
    if is_active is not None:
        stmt = stmt.where(User.is_active.is_(bool(is_active)))
    stmt = stmt.order_by(User.id)
    users = (await session.execute(stmt)).scalars().all()
    return [u.id for u in users]


async def _resolve_target_attributes(
    session: AsyncSession, target
) -> dict[str, Any]:
    """Извлечь product / country / department атрибуты цели для матчинга в change_owner.

    Возвращает dict с ключами: product (str|None), country (str|None),
    department (int|None). product берётся из target.product_codes[0]/product_code
    для Deal; для Subscription — из platform_code (если есть); для Lead — None.
    country — из Counterparty.country_code (если есть связь).
    department — из owner.department_id (если есть текущий owner).
    """
    result: dict[str, Any] = {"product": None, "country": None, "department": None}
    counterparty_id: int | None = getattr(target, "counterparty_id", None)
    if counterparty_id:
        cp = (
            await session.execute(
                select(Counterparty).where(Counterparty.id == counterparty_id)
            )
        ).scalar_one_or_none()
        if cp is not None:
            result["country"] = cp.country_code
    # product
    pc = getattr(target, "product_code", None)
    if pc:
        result["product"] = pc
    # platform_code — для Subscription
    plat = getattr(target, "platform_code", None)
    if not result["product"] and plat:
        result["product"] = plat
    # department — из текущего owner
    owner_id = _get_target_owner_user_id(target)
    if owner_id:
        owner = (
            await session.execute(select(User).where(User.id == owner_id))
        ).scalar_one_or_none()
        if owner is not None:
            result["department"] = owner.department_id
    return result


async def _action_change_owner(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """Сменить owner у target по правилу round_robin / by_product / by_country / by_department.

    Config:
    - rule: один из CHANGE_OWNER_RULES
    - user_pool_filter: dict — кого вообще можно назначать (default is_active=True)
    - map (для by_*): dict {key → user_id}; если match нет — берём первого из pool
      как «дефолтного».

    target_type по типу target (deal/lead/subscription).
    """
    cfg = automation.action_config or {}
    rule = cfg.get("rule", "round_robin")
    if rule not in CHANGE_OWNER_RULES:
        return {"skipped": True, "reason": f"неизвестное правило: {rule}"}

    user_pool_filter = cfg.get("user_pool_filter") or {}
    if not isinstance(user_pool_filter, dict):
        return {"skipped": True, "reason": "user_pool_filter должен быть dict"}

    pool = await _resolve_user_pool(session, user_pool_filter)
    if not pool:
        return {"skipped": True, "reason": "пул кандидатов пуст"}

    target_type = (
        "deal" if isinstance(target, Deal)
        else "lead" if isinstance(target, Lead)
        else "subscription" if isinstance(target, ClientSubscription)
        else None
    )
    field_name = resolve_owner_field_name(target_type) if target_type else None
    if field_name is None:
        return {"skipped": True, "reason": "unsupported target_type"}

    picked: int | None = None

    if rule == "round_robin":
        # КРИТИЧНО: lock на курсор ПЕРЕД read, чтобы соседний воркер scale=2
        # не успел прочитать тот же cursor и выбрать того же owner. Lock держится
        # до конца транзакции (xact_lock); снимается автоматически при flush+commit
        # caller'а execute_action.
        await _acquire_round_robin_lock(session, automation.id)
        cursor = await _read_round_robin_cursor(session, automation.id)
        picked, next_cursor = round_robin_pick(pool, cursor)
        await _write_round_robin_cursor(session, automation.id, next_cursor)
    else:
        # by_product / by_country / by_department
        attrs = await _resolve_target_attributes(session, target)
        mapping = cfg.get("map") or {}
        if not isinstance(mapping, dict):
            return {"skipped": True, "reason": "map должен быть dict"}
        key_attr = (
            "product" if rule == "by_product"
            else "country" if rule == "by_country"
            else "department"
        )
        key_value = attrs.get(key_attr)
        # Ключи в map — строки (JSON); сравниваем по str(key_value)
        if key_value is not None and str(key_value) in mapping:
            try:
                candidate = int(mapping[str(key_value)])
                if candidate in pool:
                    picked = candidate
            except (ValueError, TypeError):
                picked = None
        # Фолбэк: первый из pool
        if picked is None:
            picked = pool[0]

    if picked is None:
        return {"skipped": True, "reason": "не удалось выбрать owner"}

    old = getattr(target, field_name, None)
    try:
        setattr(target, field_name, picked)
        # HIGH-фикс: пересчитать зеркало department_id из нового owner
        # (Deal/Lead). Иначе visibility-scope и KPI-снапшоты рассинхронятся.
        await _sync_department_from_owner(session, target, picked)
        await session.flush()
        return {
            "rule": rule,
            "field": field_name,
            "old": old,
            "new": picked,
            "pool_size": len(pool),
        }
    except Exception as e:  # noqa: BLE001
        logger.warning("change_owner failed: %s", e)
        raise


async def _action_webhook(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """POST JSON на cfg.url с опциональным HMAC-SHA256 заголовком X-Macro-Signature.

    Config:
    - url: HTTPS URL (REQUIRED)
    - secret: str (optional) — для HMAC подписи
    - headers: dict (optional) — дополнительные заголовки

    Body:
    {event, automation_id, target_type, target_id, payload: {...}}

    Status code пишется в result_json.status_code; >= 400 → status='failed'.
    Таймаут 10s.
    """
    cfg = automation.action_config or {}
    url = cfg.get("url")
    if not url or not isinstance(url, str):
        return {"skipped": True, "reason": "url не задан"}

    secret = cfg.get("secret")
    headers = cfg.get("headers") or {}
    if not isinstance(headers, dict):
        headers = {}

    target_type = (
        "deal" if isinstance(target, Deal)
        else "lead" if isinstance(target, Lead)
        else "subscription" if isinstance(target, ClientSubscription)
        else "unknown"
    )

    payload = {
        "event": "automation_fired",
        "automation_id": automation.id,
        "automation_name": automation.name,
        "trigger_kind": automation.trigger_kind,
        "target_type": target_type,
        "target_id": getattr(target, "id", None),
        "owner_user_id": _get_target_owner_user_id(target),
    }
    body_bytes = json.dumps(payload, default=str, ensure_ascii=False).encode("utf-8")

    # Кастомные admin-заголовки фильтруем от reserved (Host/Authorization/подпись
    # и т.п.) и кладём первыми; наши Content-Type/подпись пишем поверх — баг
    # C4 WARN-3 (header-injection усиливал SSRF второго webhook-канала).
    from app.services.webhook_signature import filter_custom_headers

    hdrs = filter_custom_headers(headers)
    hdrs["Content-Type"] = "application/json"
    if secret:
        hdrs["X-Macro-Signature"] = build_webhook_signature(str(secret), body_bytes)

    # P0 SSRF guard: блокируем приватные/loopback/link-local (cloud-metadata)
    # таргеты ДО отправки. DNS резолвится и проверяется каждый IP.
    from app.services.ssrf_guard import SSRFBlockedError, assert_safe_webhook_url

    try:
        await assert_safe_webhook_url(url)
    except SSRFBlockedError as e:
        # Внутреннюю причину пишем в лог, наружу/в result — только safe_reason.
        logger.warning("webhook blocked by SSRF guard: %s", e)
        raise RuntimeError(SSRFBlockedError.safe_reason) from e

    try:
        import httpx
        # follow_redirects=False: 30x в internal URL иначе обходит SSRF-проверку.
        async with httpx.AsyncClient(
            timeout=10.0, follow_redirects=False
        ) as client:
            response = await client.post(url, content=body_bytes, headers=hdrs)
        status_code = response.status_code
        # Cap response body до 256 символов (не льём error-page'ы в result_json).
        result = {
            "url": url,
            "status_code": status_code,
            "response_preview": (response.text or "")[:256],
        }
        if status_code >= 400:
            # Делаем это явной ошибкой — caller execute_action пометит failed
            raise RuntimeError(
                f"webhook returned status {status_code}: {(response.text or '')[:200]}"
            )
        return result
    except Exception as e:  # noqa: BLE001
        logger.warning("webhook %s failed: %s", url, e)
        raise


async def _action_email(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """Послать email через aiosmtplib (если SMTP сконфигурирован).

    Config:
    - recipient_role: 'owner' | 'specific'
    - recipient_user_id: int (если recipient_role='specific')
    - subject_template: str
    - body_template: str

    Если SMTP не сконфигурирован — status='failed' с result_json
    {status: 'smtp_not_configured'} (caller увидит в /admin/automation-runs).

    Jinja-подстановка простая (через _format_message) — никакой полноценной Jinja
    (избегаем инъекций). Поддерживаемые плейсхолдеры — те же, что в tg_notify.
    """
    from app.config import get_settings
    settings = get_settings()

    if not settings.smtp_host:
        return {
            "skipped": True,
            "reason": "smtp_not_configured",
            "status": "smtp_not_configured",
        }

    cfg = automation.action_config or {}
    recipient_role = cfg.get("recipient_role", "owner")
    subject_tpl = cfg.get("subject_template", "")
    body_tpl = cfg.get("body_template", "")

    if recipient_role == "owner":
        if owner is None or not owner.email:
            return {"skipped": True, "reason": "у владельца нет email"}
        to_email = owner.email
    elif recipient_role == "specific":
        rid = cfg.get("recipient_user_id")
        if not rid:
            return {"skipped": True, "reason": "recipient_user_id не задан"}
        try:
            uid = int(rid)
        except (ValueError, TypeError):
            return {"skipped": True, "reason": "recipient_user_id невалиден"}
        user = (
            await session.execute(select(User).where(User.id == uid))
        ).scalar_one_or_none()
        if user is None or not user.email:
            return {"skipped": True, "reason": f"пользователь {uid} не найден или без email"}
        to_email = user.email
    else:
        return {"skipped": True, "reason": f"неизвестный recipient_role: {recipient_role}"}

    subject = _format_message(subject_tpl, target, owner) or "(без темы)"
    body = _format_message(body_tpl, target, owner)
    from_email = settings.smtp_from or settings.smtp_user or "noreply@example.com"

    try:
        import aiosmtplib  # type: ignore[import-not-found]
        from email.message import EmailMessage
        msg = EmailMessage()
        msg["From"] = from_email
        msg["To"] = to_email
        msg["Subject"] = subject[:255]
        msg.set_content(body or "")
        await aiosmtplib.send(
            msg,
            hostname=settings.smtp_host,
            port=settings.smtp_port,
            username=settings.smtp_user,
            password=settings.smtp_pass,
            start_tls=settings.smtp_use_tls,
        )
        return {
            "sent": True,
            "to": to_email,
            "subject": subject[:255],
        }
    except ImportError:
        # aiosmtplib не установлен в окружении — то же поведение, что нет SMTP
        return {
            "skipped": True,
            "reason": "aiosmtplib_not_installed",
            "status": "smtp_not_configured",
        }
    except Exception as e:  # noqa: BLE001
        logger.warning("email send failed: %s", e)
        raise


async def _action_start_sequence(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """Запустить SequenceRun для (target_type, target.id). Делегат в sequence_executor.

    Config: {"sequence_id": int}.
    """
    cfg = automation.action_config or {}
    sequence_id = cfg.get("sequence_id")
    if sequence_id is None:
        return {"skipped": True, "reason": "sequence_id не задан"}
    try:
        seq_id = int(sequence_id)
    except (ValueError, TypeError):
        return {"skipped": True, "reason": "sequence_id невалиден"}

    target_type = (
        "deal" if isinstance(target, Deal)
        else "lead" if isinstance(target, Lead)
        else "subscription" if isinstance(target, ClientSubscription)
        else None
    )
    if target_type is None:
        return {"skipped": True, "reason": "unsupported target_type"}

    from app.services.sequence_executor import start_sequence_run
    try:
        run = await start_sequence_run(
            session, seq_id, target_type, int(getattr(target, "id"))
        )
    except Exception as e:  # noqa: BLE001
        logger.warning("start_sequence failed: %s", e)
        raise

    if run is None:
        return {"skipped": True, "reason": "sequence не найдена или неактивна"}
    return {
        "sequence_id": seq_id,
        "sequence_run_id": run.id,
        "status": run.status,
    }


# ============ Эпик 23 — pure helpers + handlers ============


def apply_tag_mode(
    current_tags: list[str] | None,
    delta_tags: list[str],
    mode: str,
) -> list[str]:
    """Pure-функция: применить mode (add/replace/remove) к current_tags.

    - add     — объединение без дубликатов (порядок: current + новые-уникальные).
    - replace — возвращает копию delta_tags (current полностью игнорируется).
    - remove  — current минус все элементы, которые есть в delta_tags.

    None/empty current нормализуется в []. delta_tags нормализуется в list
    (на случай tuple/set из неподготовленных config'ов).
    """
    current = list(current_tags) if current_tags else []
    delta = list(delta_tags) if delta_tags else []
    if mode == "replace":
        # Удаляем дубликаты сохраняя порядок (dict.fromkeys idiom)
        return list(dict.fromkeys(delta))
    if mode == "add":
        merged = list(current)
        for t in delta:
            if t not in merged:
                merged.append(t)
        return merged
    if mode == "remove":
        delta_set = set(delta)
        return [t for t in current if t not in delta_set]
    # Неизвестный mode → сохраняем current (safe default).
    return current


def render_simple_template(template: str, ctx: dict[str, Any]) -> str:
    """Простая jinja-like подстановка `{var}` без условий/циклов.

    Используется в create_deal для title_template. Не jinja-полный (никакого
    {% if %} / |filter), чтобы избежать инъекций и сложности — для UI-форм
    с {target_id}, {target_title} и т.п. этого достаточно.

    Неизвестные placeholder'ы остаются в шаблоне как есть (для дебага).
    """
    if not template:
        return ""
    result = template
    for k, v in ctx.items():
        result = result.replace(f"{{{k}}}", str(v) if v is not None else "")
    return result


async def _action_set_tags(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """Эпик 23: установить теги на target.

    Config:
    - tags: list[str] — теги для add/replace/remove (REQUIRED, non-empty unless mode=replace)
    - mode: 'add' | 'replace' | 'remove' (default 'add')

    Сейчас поддерживается только target_type='lead' (у него есть tags JSON
    колонка). Для deal/counterparty модель НЕ имеет tags — пропускаем с
    explicit skipped.reason, не валим executor.
    """
    cfg = automation.action_config or {}
    raw_tags = cfg.get("tags") or []
    if not isinstance(raw_tags, list):
        return {"skipped": True, "reason": "tags должен быть list"}
    # Нормализуем элементы в str (защита от config corruption).
    delta_tags = [str(t).strip() for t in raw_tags if str(t).strip()]

    mode = cfg.get("mode", "add")
    if mode not in SET_TAGS_MODES:
        return {"skipped": True, "reason": f"неизвестный mode: {mode}"}

    target_type = (
        "deal" if isinstance(target, Deal)
        else "lead" if isinstance(target, Lead)
        else "subscription" if isinstance(target, ClientSubscription)
        else "unknown"
    )
    if target_type not in SET_TAGS_TARGETS:
        # Counterparty/Deal не имеют tags JSON — мягко пропускаем с warning.
        # НЕ failed — это не ошибка автоматизации, это семантический no-op.
        logger.warning(
            "set_tags skipped: target_type=%s не поддерживает tags (automation %s)",
            target_type, automation.id,
        )
        return {
            "skipped": True,
            "reason": f"target_type={target_type} не поддерживает tags",
        }

    current = getattr(target, "tags", None)
    new_tags = apply_tag_mode(current, delta_tags, mode)
    try:
        setattr(target, "tags", new_tags)
        await session.flush()
        return {
            "mode": mode,
            "old_tags": list(current) if current else [],
            "new_tags": new_tags,
            "delta_tags": delta_tags,
        }
    except Exception as e:  # noqa: BLE001
        logger.warning("set_tags failed for %s#%s: %s", target_type, target.id, e)
        raise


async def _action_complete_tasks(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """Эпик 23: завершить задачи (Activity kind='task') у target.

    Config:
    - filter: 'all' | 'open_only' (default 'open_only')
    - target_type: optional override (default — выводится из самого target)

    Логика:
        SET activities.completed_at = NOW(),
            activities.completed_by_id = automation.created_by_user_id (или owner)
        WHERE activities.target_type = X AND activities.target_id = Y
              AND activities.kind = 'task'
              AND (completed_at IS NULL  если filter='open_only')

    Возвращает {affected: N} — количество затронутых строк.
    """
    cfg = automation.action_config or {}
    filter_mode = cfg.get("filter", "open_only")
    if filter_mode not in COMPLETE_TASKS_FILTERS:
        return {"skipped": True, "reason": f"неизвестный filter: {filter_mode}"}

    target_type_override = cfg.get("target_type")
    target_type = target_type_override or (
        "deal" if isinstance(target, Deal)
        else "lead" if isinstance(target, Lead)
        else "subscription" if isinstance(target, ClientSubscription)
        else None
    )
    if target_type is None:
        return {"skipped": True, "reason": "неизвестный target_type"}

    completer_id = automation.created_by_user_id or (owner.id if owner else None)
    if completer_id is None:
        # Используем любого активного admin (тот же fallback что в create_task).
        admin = (
            await session.execute(
                select(User).where(User.is_active.is_(True)).limit(1)
            )
        ).scalar_one_or_none()
        if admin:
            completer_id = admin.id
        else:
            return {"skipped": True, "reason": "нет пользователя для completed_by_id"}

    now = datetime.now(UTC)
    stmt = (
        select(Activity)
        .where(
            Activity.target_type == target_type,
            Activity.target_id == int(getattr(target, "id")),
            Activity.kind == "task",
        )
    )
    if filter_mode == "open_only":
        stmt = stmt.where(Activity.completed_at.is_(None))

    tasks = (await session.execute(stmt)).scalars().all()
    affected = 0
    for t in tasks:
        t.completed_at = now
        t.completed_by_id = completer_id
        affected += 1
    if affected:
        await session.flush()
    return {
        "affected": affected,
        "filter": filter_mode,
        "completed_by_id": completer_id,
        "target_type": target_type,
    }


async def _action_change_stage(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """Эпик 23: сменить этап у target (deal/lead) на new_stage_id + создать
    Activity(note) с причиной.

    Config:
    - new_stage_id: int (REQUIRED)
    - reason: str — записывается в Activity.body (для timeline)

    Защита:
    - target должен быть deal или lead (subscription не имеет stage_id)
    - new_stage_id должен существовать и принадлежать той же воронке что и
      target.pipeline_id (защита от cross-pipeline jumps)

    Side-effects:
    - target.stage_id = new_stage_id
    - target.stage_changed_at = now (только для Deal — у Lead нет такого поля)
    - INSERT Activity(kind='note', target=..., title='Автоматизация: смена этапа',
      body=reason)

    NB: ВНИМАНИЕ — этот action MOVE'ит сделку в обход роутера /deals/{id}/move,
    т.е. БЕЗ запуска on_enter_stage автоматизаций целевого этапа. Это
    осознанный выбор: иначе цепочки могут зациклиться (автоматизация А
    меняет этап → автоматизация Б меняет обратно → ...). Если в будущем
    нужны рекурсивные переходы — добавим depth-limit через AutomationRun.
    """
    cfg = automation.action_config or {}
    new_stage_id_raw = cfg.get("new_stage_id")
    if new_stage_id_raw is None:
        return {"skipped": True, "reason": "new_stage_id не задан"}
    try:
        new_stage_id = int(new_stage_id_raw)
    except (ValueError, TypeError):
        return {"skipped": True, "reason": "new_stage_id невалиден"}
    reason = str(cfg.get("reason") or "").strip()

    if not isinstance(target, (Deal, Lead)):
        return {
            "skipped": True,
            "reason": "change_stage поддерживается только для deal/lead",
        }
    target_type = "deal" if isinstance(target, Deal) else "lead"

    # Импортируем PipelineStage локально (избегаем top-level cycle).
    from app.models import PipelineStage as _PipelineStage
    new_stage = (
        await session.execute(
            select(_PipelineStage).where(_PipelineStage.id == new_stage_id)
        )
    ).scalar_one_or_none()
    if new_stage is None:
        return {"skipped": True, "reason": f"этап #{new_stage_id} не найден"}
    if new_stage.pipeline_id != getattr(target, "pipeline_id", None):
        return {
            "skipped": True,
            "reason": (
                f"этап #{new_stage_id} принадлежит другой воронке "
                f"(target.pipeline_id={getattr(target, 'pipeline_id', None)}, "
                f"stage.pipeline_id={new_stage.pipeline_id})"
            ),
        }

    old_stage_id = target.stage_id
    if old_stage_id == new_stage_id:
        return {"skipped": True, "reason": "целевой этап совпадает с текущим"}

    target.stage_id = new_stage_id
    # Только для Deal есть stage_changed_at — у Lead такого поля нет.
    if isinstance(target, Deal):
        target.stage_changed_at = datetime.now(UTC)

    # Создаём timeline-note с reason. Без note — пропускаем INSERT (не каждое
    # изменение должно генерировать запись если автор не пояснил).
    note_id: int | None = None
    if reason:
        creator_id = automation.created_by_user_id or (owner.id if owner else None)
        if creator_id is None:
            admin = (
                await session.execute(
                    select(User).where(User.is_active.is_(True)).limit(1)
                )
            ).scalar_one_or_none()
            if admin:
                creator_id = admin.id
        if creator_id is not None:
            note = Activity(
                kind="note",
                target_type=target_type,
                target_id=int(getattr(target, "id")),
                title=f"Автоматизация «{automation.name}»: смена этапа",
                body=reason,
                created_by_id=creator_id,
            )
            session.add(note)
            await session.flush()
            note_id = note.id

    return {
        "target_type": target_type,
        "old_stage_id": old_stage_id,
        "new_stage_id": new_stage_id,
        "reason": reason or None,
        "note_activity_id": note_id,
    }


async def _action_create_deal(
    session: AsyncSession,
    automation: PipelineAutomation,
    target,
    owner: User | None,
) -> dict[str, Any]:
    """Эпик 23: создать новую сделку (Deal).

    Config:
    - title_template: str — jinja-like с {target_id}, {target_title}, {owner_name},
                           {counterparty_name}. REQUIRED, не пустая.
    - amount: float? — сумма (default null = пусто)
    - currency: str? — валюта (default null)
    - owner_user_id: int? — явный owner (default — owner текущего target)
    - pipeline_id: int (REQUIRED) — в какой воронке создаём
    - stage_id: int? — конкретный этап (default — первый по sort_order)
    - counterparty_id_from_context: bool (default True) —
        если True: copy counterparty_id из current target (если он lead/counterparty);
        если False: counterparty_id = None.

    Логика:
    1) Резолвим counterparty_id из контекста.
    2) Рендерим title.
    3) Резолвим stage_id (если не задан — первый по sort_order в pipeline).
    4) INSERT Deal.

    Возвращает {created_deal_id, title, stage_id}.
    """
    cfg = automation.action_config or {}
    title_tpl = str(cfg.get("title_template") or "").strip()
    if not title_tpl:
        return {"skipped": True, "reason": "title_template не задан"}

    pipeline_id_raw = cfg.get("pipeline_id")
    if pipeline_id_raw is None:
        return {"skipped": True, "reason": "pipeline_id не задан"}
    try:
        pipeline_id = int(pipeline_id_raw)
    except (ValueError, TypeError):
        return {"skipped": True, "reason": "pipeline_id невалиден"}

    # Проверка существования воронки. Импорт локально (избегаем top-level cycle).
    from app.models import Pipeline as _Pipeline
    pipeline = (
        await session.execute(
            select(_Pipeline).where(_Pipeline.id == pipeline_id)
        )
    ).scalar_one_or_none()
    if pipeline is None:
        return {"skipped": True, "reason": f"воронка #{pipeline_id} не найдена"}

    # Резолвим counterparty_id.
    use_context = cfg.get("counterparty_id_from_context", True)
    counterparty_id: int | None = None
    counterparty_name: str = ""
    if use_context:
        if isinstance(target, Counterparty):
            counterparty_id = target.id
            counterparty_name = target.name or ""
        elif isinstance(target, Lead):
            counterparty_id = target.converted_to_counterparty_id
            # Если лид ещё не сконвертирован — counterparty_id остаётся None.
            counterparty_name = target.name or ""
        elif isinstance(target, Deal):
            counterparty_id = target.counterparty_id
            counterparty_name = target.title or ""
        elif isinstance(target, ClientSubscription):
            counterparty_id = target.counterparty_id

    # Если задан counterparty_id и нет name в контексте — подтянем из БД.
    if counterparty_id and not counterparty_name:
        cp = (
            await session.execute(
                select(Counterparty).where(Counterparty.id == counterparty_id)
            )
        ).scalar_one_or_none()
        if cp:
            counterparty_name = cp.name or ""

    # Резолвим stage_id.
    from app.models import PipelineStage as _PipelineStage
    stage_id_raw = cfg.get("stage_id")
    stage_id: int | None = None
    if stage_id_raw is not None:
        try:
            stage_id = int(stage_id_raw)
        except (ValueError, TypeError):
            return {"skipped": True, "reason": "stage_id невалиден"}
        # Проверка что этап принадлежит этой воронке.
        stage = (
            await session.execute(
                select(_PipelineStage).where(
                    _PipelineStage.id == stage_id,
                    _PipelineStage.pipeline_id == pipeline_id,
                )
            )
        ).scalar_one_or_none()
        if stage is None:
            return {
                "skipped": True,
                "reason": f"этап #{stage_id} не найден в воронке #{pipeline_id}",
            }
    else:
        # Берём первый активный по sort_order.
        first_stage = (
            await session.execute(
                select(_PipelineStage)
                .where(
                    _PipelineStage.pipeline_id == pipeline_id,
                    _PipelineStage.is_active.is_(True),
                )
                .order_by(_PipelineStage.sort_order)
                .limit(1)
            )
        ).scalar_one_or_none()
        if first_stage is None:
            return {
                "skipped": True,
                "reason": f"в воронке #{pipeline_id} нет активных этапов",
            }
        stage_id = first_stage.id

    # Рендерим title.
    target_id_val = getattr(target, "id", "")
    target_title_val = (
        getattr(target, "title", None)
        or getattr(target, "name", None)
        or f"target #{target_id_val}"
    )
    rendered_title = render_simple_template(
        title_tpl,
        {
            "target_id": target_id_val,
            "target_title": target_title_val,
            "owner_name": owner.full_name if owner else "",
            "counterparty_name": counterparty_name,
        },
    )[:255]
    if not rendered_title.strip():
        return {"skipped": True, "reason": "rendered title пустой"}

    # Резолвим owner_user_id.
    owner_user_id_cfg = cfg.get("owner_user_id")
    new_owner_id: int | None
    if owner_user_id_cfg is None:
        new_owner_id = _get_target_owner_user_id(target)
    else:
        try:
            new_owner_id = int(owner_user_id_cfg)
        except (ValueError, TypeError):
            new_owner_id = _get_target_owner_user_id(target)

    # Amount / currency.
    amount_val = cfg.get("amount")
    amount_decimal = None
    if amount_val is not None:
        try:
            from decimal import Decimal as _Decimal
            amount_decimal = _Decimal(str(amount_val))
        except Exception:  # noqa: BLE001
            amount_decimal = None
    currency_val = cfg.get("currency")
    currency_str = str(currency_val) if currency_val else None

    # HIGH-фикс: сразу заполняем зеркало department_id из owner нового Deal
    # (иначе department-scoped visibility/KPI не увидят свежую сделку).
    new_dept_id: int | None = None
    if new_owner_id is not None:
        owner_user = (
            await session.execute(select(User).where(User.id == new_owner_id))
        ).scalar_one_or_none()
        new_dept_id = owner_user.department_id if owner_user else None

    deal = Deal(
        pipeline_id=pipeline_id,
        stage_id=int(stage_id),
        counterparty_id=counterparty_id,
        title=rendered_title,
        amount=amount_decimal,
        currency=currency_str,
        owner_user_id=new_owner_id,
        department_id=new_dept_id,
        stage_changed_at=datetime.now(UTC),
    )
    session.add(deal)
    await session.flush()
    return {
        "created_deal_id": deal.id,
        "title": rendered_title,
        "pipeline_id": pipeline_id,
        "stage_id": stage_id,
        "counterparty_id": counterparty_id,
        "owner_user_id": new_owner_id,
    }


_ACTION_HANDLERS = {
    "tg_notify": _action_tg_notify,
    "create_task": _action_create_task,
    "set_field": _action_set_field,
    "generate_document": _action_generate_document,
    "change_owner": _action_change_owner,
    "webhook": _action_webhook,
    "email": _action_email,
    "start_sequence": _action_start_sequence,
    # Эпик 23 — Конструктор воронок AmoCRM-style.
    "set_tags": _action_set_tags,
    "complete_tasks": _action_complete_tasks,
    "change_stage": _action_change_stage,
    "create_deal": _action_create_deal,
}


# ============ Public API ============

async def recover_stuck_automation_runs(stale_minutes: int = 15) -> int:
    """POST-AUDIT #4 recovery: восстановление «зависших» сетевых действий.

    Сетевые action'ы (tg_notify/webhook/email) клеймятся в inline-пути как
    AutomationRun(status='queued') и исполняются в fire-and-forget
    `asyncio.create_task` на свежей сессии. Если фоновый таск умер до перевода
    run в терминальный статус (реплику перезапустили rolling-restart'ом ИЛИ
    фоновый исполнитель упал ДО claim'а свежей сессии — напр. историческая
    регрессия с незаимпорченным SessionLocal), row остаётся в 'queued' навсегда.

    Беда: 'queued' ∈ _DEDUP_HOLDING_STATUSES → idem-слот (trigger_event_ts)
    держится вечно → cron тоже не переретраит (INSERT ON CONFLICT DO NOTHING
    видит занятый слот). Действие потеряно молча.

    Этот sweep вызывается на старте каждой реплики (idempotent): любой run в
    'queued' старше `stale_minutes` помечается 'failed' с finished_at, и idem-
    слот ОСВОБОЖДАЕТСЯ ровно тем же путём, что и обычный failed-путь
    execute_action / _run_deferred_network_action — через
    should_release_idem_slot("failed", trigger_event_ts) → обнуление
    trigger_event_ts (выводит строку из-под partial-UNIQUE
    `WHERE trigger_event_ts IS NOT NULL`). После этого cron-ретрай (для
    cron-триггеров) сможет переклеймить слот.

    Свежие (< stale_minutes) queued-run'ы НЕ трогаем — их, скорее всего,
    исполняет живой фоновый таск прямо сейчас. Возвращает число помеченных строк.
    """
    cutoff = datetime.now(UTC) - timedelta(minutes=stale_minutes)
    async with SessionLocal() as session:
        rows = list(
            (
                await session.execute(
                    select(AutomationRun).where(
                        AutomationRun.status == _QUEUED_STATUS,
                        AutomationRun.started_at < cutoff,
                    )
                )
            ).scalars().all()
        )
        if not rows:
            return 0
        now = datetime.now(UTC)
        for run in rows:
            run.status = "failed"
            if run.error_text is None:
                run.error_text = (
                    "queued network action stuck > "
                    f"{stale_minutes}m — recovered on startup (orphaned task)"
                )
            if run.finished_at is None:
                run.finished_at = now
            # Освобождаем idem-слот тем же путём, что и обычный failed (чтобы
            # cron мог переретраить cron-триггеры). NULL trigger_event_ts даёт
            # дедупа нет → should_release_idem_slot вернёт False, ничего не
            # обнуляем (ручной/legacy run).
            if should_release_idem_slot("failed", run.trigger_event_ts):
                run.result_json = {
                    **(run.result_json or {}),
                    "released_event_ts": run.trigger_event_ts.isoformat()
                    if run.trigger_event_ts
                    else None,
                    "released_reason": (
                        "stuck queued network action recovered — slot freed"
                    ),
                }
                run.trigger_event_ts = None
        await session.commit()
        logger.warning(
            "recover_stuck_automation_runs: marked %d stuck queued run(s) as "
            "failed (ids=%s); likely orphaned by a restart or dead bg task",
            len(rows), [r.id for r in rows],
        )
        return len(rows)


async def _run_deferred_network_action(
    run_id: int,
    automation_id: int,
    target_type: str,
    target_id: int,
    trigger_event_ts: datetime | None,
) -> None:
    """POST-AUDIT #4: фоновый исполнитель СЕТЕВОГО действия на СВЕЖЕЙ сессии.

    Вызывается через asyncio.create_task из execute_action, когда действие
    отложено (defer_network). Принимает ТОЛЬКО примитивы (id/строки) — никаких
    ORM-объектов из реквест-сессии (она закроется после ответа → use-after-close).

    Открывает новую сессию из SessionLocal, перезагружает claimed-run (status=
    'queued') + automation, исполняет handler и переводит run в success/failed/
    skipped с commit'ом. На failed — освобождает idem-слот (как inline-путь), так
    что cron сможет переретраить. Любая ошибка — Sentry + log, наружу НЕ падает
    (фоновый таск, некому ловить). ИСКЛЮЧЕНИЕ: asyncio.CancelledError (graceful
    shutdown) — освобождаем idem-слот и RE-RAISE (глушить отмену нельзя).
    """
    import asyncio

    try:
        async with SessionLocal() as session:
            run = (
                await session.execute(
                    select(AutomationRun).where(AutomationRun.id == run_id)
                )
            ).scalar_one_or_none()
            if run is None:
                logger.warning(
                    "deferred action: run #%s не найден (удалён?)", run_id
                )
                return
            # Защита от двойного исполнения: если run уже не 'queued' (другой
            # путь/ретрай его перевёл) — ничего не делаем.
            if run.status != _QUEUED_STATUS:
                return
            automation = (
                await session.execute(
                    select(PipelineAutomation).where(
                        PipelineAutomation.id == automation_id
                    )
                )
            ).scalar_one_or_none()
            if automation is None:
                run.status = "skipped"
                run.finished_at = datetime.now(UTC)
                run.error_text = "automation удалена до фонового исполнения"
                await session.commit()
                return

            target = await _fetch_target(session, target_type, target_id)
            if target is None:
                run.status = "skipped"
                run.finished_at = datetime.now(UTC)
                run.error_text = f"target {target_type}#{target_id} не найден"
                await session.commit()
                return

            handler = _ACTION_HANDLERS.get(automation.action_kind)
            if handler is None:
                run.status = "skipped"
                run.finished_at = datetime.now(UTC)
                run.error_text = (
                    f"неизвестный action_kind: {automation.action_kind}"
                )
                await session.commit()
                return

            owner_id = _get_target_owner_user_id(target)
            owner: User | None = None
            if owner_id:
                owner = (
                    await session.execute(
                        select(User).where(User.id == owner_id)
                    )
                ).scalar_one_or_none()

            try:
                result = await handler(session, automation, target, owner)
                run.result_json = result
                if isinstance(result, dict) and result.get("skipped"):
                    run.status = "skipped"
                    run.error_text = result.get("reason")
                else:
                    run.status = "success"
                run.finished_at = datetime.now(UTC)
                automation.last_run_at = run.finished_at
            except asyncio.CancelledError:
                # Graceful shutdown (uvicorn останавливается на rolling-restart):
                # in-flight таск отменяется. БЕЗ обработки run остался бы 'queued'
                # навсегда ('queued' держит дедуп-слот → cron не переретраит →
                # действие потеряно). Освобождаем слот тем же путём, что и failed
                # (should_release_idem_slot("cancelled", ...) → True, т.к.
                # 'cancelled' не в _DEDUP_HOLDING_STATUSES), коммитим best-effort и
                # ОБЯЗАТЕЛЬНО re-raise — глушить отмену нельзя (контракт asyncio).
                run.status = "failed"
                run.error_text = "deferred action cancelled (graceful shutdown)"
                run.finished_at = datetime.now(UTC)
                if should_release_idem_slot("cancelled", trigger_event_ts):
                    run.result_json = {
                        **(run.result_json or {}),
                        "released_event_ts": trigger_event_ts.isoformat()
                        if trigger_event_ts
                        else None,
                        "released_reason": (
                            "deferred network action cancelled — slot freed"
                        ),
                    }
                    run.trigger_event_ts = None
                try:
                    await session.commit()
                except Exception as commit_err:  # noqa: BLE001
                    # Коммит освобождения сам упал под отменой — best-effort + лог,
                    # но CancelledError всё равно прорастёт наружу ниже.
                    logger.warning(
                        "deferred action: release-commit failed under "
                        "cancellation for run #%s: %s",
                        run_id, commit_err,
                    )
                logger.warning(
                    "deferred action cancelled (run #%s) — slot freed for retry",
                    run_id,
                )
                raise
            except Exception as e:  # noqa: BLE001
                run.status = "failed"
                run.error_text = str(e)[:2000]
                run.finished_at = datetime.now(UTC)
                # На failed освобождаем idem-слот (как inline-путь execute_action),
                # чтобы cron-ретрай (если применимо) смог переклеймить.
                if should_release_idem_slot("failed", trigger_event_ts):
                    run.result_json = {
                        **(run.result_json or {}),
                        "released_event_ts": trigger_event_ts.isoformat()
                        if trigger_event_ts
                        else None,
                        "released_reason": (
                            "deferred network action failed — slot freed"
                        ),
                    }
                    run.trigger_event_ts = None
                import sentry_sdk
                sentry_sdk.capture_exception(e)
                logger.warning(
                    "deferred action %s failed for %s#%s (run #%s): %s",
                    automation.action_kind, target_type, target_id, run_id, e,
                )
            await session.commit()
    except Exception as e:  # noqa: BLE001
        # Сессионный/инфраструктурный сбой фонового таска. Наружу не падаем.
        import sentry_sdk
        sentry_sdk.capture_exception(e)
        logger.exception(
            "deferred action: session-level failure for run #%s: %s", run_id, e
        )


def _spawn_deferred_network_action(
    run_id: int,
    automation_id: int,
    target_type: str,
    target_id: int,
    trigger_event_ts: datetime | None,
) -> None:
    """Запустить фоновый таск сетевого действия (fire-and-forget).

    Вынесено отдельной функцией, чтобы тесты могли пропатчить точку запуска и
    проверить диспетчеризацию без реального asyncio-таска. Держим ссылку на
    задачу в module-set, чтобы GC не собрал её до завершения (asyncio хранит
    только weak-ref на pending-таски)."""
    import asyncio

    task = asyncio.create_task(
        _run_deferred_network_action(
            run_id, automation_id, target_type, target_id, trigger_event_ts
        )
    )
    _DEFERRED_TASKS.add(task)
    task.add_done_callback(_DEFERRED_TASKS.discard)


# Сильные ссылки на in-flight фоновые таски (asyncio держит только weak-ref →
# без этого set задача может быть собрана GC до завершения).
_DEFERRED_TASKS: set = set()


async def execute_action(
    session: AsyncSession,
    automation: PipelineAutomation,
    target_type: str,
    target_id: int,
    trigger_event_ts: datetime | None = None,
    defer_network: bool = False,
) -> AutomationRun:
    """Выполнить одно действие и записать AutomationRun (success/failed/skipped).

    Не падает наружу: исключение действия становится status='failed'. Caller
    может смотреть .status и .error_text в возвращённом AutomationRun.

    Идемпотентность (миграция 0081): если передан trigger_event_ts — слот
    выполнения «застолбляется» транзакционно через claim_run_slot
    (INSERT ... ON CONFLICT DO NOTHING на ux_automation_runs_idem). Если слот
    уже занят (другая реплика scale=2 / повторный тик) — возвращаем
    transient-skipped run БЕЗ side-effect (не пишем новую строку, чтобы не
    плодить дубли «skipped» в истории). При trigger_event_ts=None (ручной
    execute / retry) дедуп выключен — поведение как раньше.

    POST-AUDIT #4 (fire-and-forget): defer_network=True (только inline-путь
    move/create из роутеров) + СЕТЕВОЕ действие (tg_notify/webhook/email) →
    run пишется синхронно со status='queued', реальная отправка уходит в фоновый
    таск на СВЕЖЕЙ сессии (см. _run_deferred_network_action), и execute_action
    возвращается СРАЗУ, не блокируя HTTP-ответ на сети. DB-local действия и cron
    (defer_network=False) исполняются синхронно как раньше — поведение не меняется.
    """
    started = datetime.now(UTC)

    if trigger_event_ts is not None:
        claimed = await claim_run_slot(
            session, automation.id, target_type, target_id, trigger_event_ts
        )
        if not claimed:
            # Слот занят — действие уже выполнено (или выполняется) другим
            # тиком/репликой. Возвращаем transient run-объект (НЕ персистим),
            # чтобы caller увидел status='skipped' и не дёргал side-effect.
            return AutomationRun(
                automation_id=automation.id,
                target_type=target_type,
                target_id=target_id,
                trigger_event_ts=trigger_event_ts,
                status="skipped",
                started_at=started,
                finished_at=started,
                error_text="dedup: уже отработано (idempotency)",
            )
        # claim_run_slot уже вставил pending-строку с нашим trigger_event_ts —
        # подгружаем её, чтобы продолжить обновление в том же объекте.
        run = (
            await session.execute(
                select(AutomationRun)
                .where(
                    AutomationRun.automation_id == automation.id,
                    AutomationRun.target_type == target_type,
                    AutomationRun.target_id == target_id,
                    AutomationRun.trigger_event_ts == trigger_event_ts,
                )
                .order_by(AutomationRun.id.desc())
                .limit(1)
            )
        ).scalar_one()
    else:
        run = AutomationRun(
            automation_id=automation.id,
            target_type=target_type,
            target_id=target_id,
            status="pending",
            started_at=started,
        )
        session.add(run)
        await session.flush()

    # POST-AUDIT #4: если действие СЕТЕВОЕ и мы в inline-пути (defer_network) —
    # помечаем run как 'queued', коммитим (чтобы строка была видна свежей сессии
    # фонового таска), запускаем фоновую отправку и возвращаемся СРАЗУ, не
    # блокируя HTTP-ответ на сети. Idem-слот уже заклеймлен синхронно выше →
    # scale=2 / повторный тик дедуплены ('queued' держит слот). Реальная
    # доставка + перевод run в success/failed/skipped — в _run_deferred_network_action.
    if should_defer_action(automation.action_kind, defer_network):
        run.status = _QUEUED_STATUS
        # commit здесь, а не flush: фоновый таск открывает ОТДЕЛЬНУЮ сессию и
        # должен увидеть claimed-строку. Без commit'а её транзакция реквест-
        # сессии ещё не зафиксирована → фоновый SELECT вернёт None.
        await session.commit()
        _spawn_deferred_network_action(
            run.id, automation.id, target_type, target_id, trigger_event_ts
        )
        return run

    target = await _fetch_target(session, target_type, target_id)
    if target is None:
        run.status = "skipped"
        run.finished_at = datetime.now(UTC)
        run.error_text = f"target {target_type}#{target_id} не найден"
        await session.flush()
        return run

    handler = _ACTION_HANDLERS.get(automation.action_kind)
    if handler is None:
        run.status = "skipped"
        run.finished_at = datetime.now(UTC)
        run.error_text = f"неизвестный action_kind: {automation.action_kind}"
        await session.flush()
        return run

    owner_id = _get_target_owner_user_id(target)
    owner: User | None = None
    if owner_id:
        owner = (
            await session.execute(select(User).where(User.id == owner_id))
        ).scalar_one_or_none()

    try:
        result = await handler(session, automation, target, owner)
        run.result_json = result
        if isinstance(result, dict) and result.get("skipped"):
            run.status = "skipped"
            run.error_text = result.get("reason")
        else:
            run.status = "success"
        run.finished_at = datetime.now(UTC)
        automation.last_run_at = run.finished_at
        await session.flush()
    except Exception as e:  # noqa: BLE001
        run.status = "failed"
        run.error_text = str(e)[:2000]
        run.finished_at = datetime.now(UTC)
        # РЕГРЕССИЯ-ФИКС (at-most-once → at-least-once при transient-ошибках):
        # claim_run_slot застолбил pending-строку по partial UNIQUE
        # (automation_id, target_type, target_id, trigger_event_ts). Если
        # действие упало (TG 500 / webhook timeout / etc.), оставлять строку с
        # этим trigger_event_ts нельзя — иначе следующий cron-тик с тем же
        # event_ts получит ON CONFLICT DO NOTHING → claim вернёт False →
        # transient-skipped → действие НИКОГДА не доставится повторно.
        #
        # Решение: освобождаем слот, ОБНУЛЯЯ trigger_event_ts на упавшей
        # строке. Partial-индекс имеет `WHERE trigger_event_ts IS NOT NULL`,
        # поэтому NULL-строка выходит из-под уникальности → следующий тик
        # заново claim'нет (automation_id, target_type, target_id, event_ts) и
        # повторит действие. При этом:
        #   - история failed-прогона сохраняется (видна в /admin/automation-runs
        #     + доступна для ручного retry);
        #   - исходный момент события стэшим в result_json.released_event_ts
        #     (для аудита/дебага, т.к. колонку обнуляем);
        #   - success/skipped-строки trigger_event_ts НЕ трогаем → дедуп от
        #     дублей (concurrent scale=2 + повторный success) держится.
        if should_release_idem_slot("failed", trigger_event_ts):
            run.result_json = {
                **(run.result_json or {}),
                "released_event_ts": trigger_event_ts.isoformat(),
                "released_reason": "transient failure — slot freed for retry",
            }
            run.trigger_event_ts = None
        await session.flush()
        logger.warning(
            "automation %s action %s failed for %s#%s: %s",
            automation.id, automation.action_kind, target_type, target_id, e,
        )
    return run


async def run_on_create_automations(
    session: AsyncSession,
    target_type: str,
    target_id: int,
    defer_network: bool = True,
) -> list[AutomationRun]:
    """Inline executor для триггера on_create. Дёргается из POST /leads, POST /deals,
    inbox.auto_create_lead_from_message.

    POST-AUDIT #4: defer_network=True по умолчанию (этот путь — всегда из
    реквеста). Сетевые действия (tg_notify/webhook/email) уходят в фоновый таск
    на свежей сессии, не блокируя HTTP-ответ. DB-local — синхронно как раньше.

    Логика:
    - Подгружаем target → определяем его pipeline_id (для Lead/Deal — поле pipeline_id;
      для Subscription — None, on_create на subscriptions пока не поддержано).
    - Берём все active автоматизации с trigger_kind='on_create' для этого pipeline_id
      (stage_id=NULL обязателен — on_create не привязан к этапу).
    - Catch на всё: одна сломанная автоматизация НЕ блокирует остальные и НЕ роняет
      сам POST /leads/.../deals.
    """
    target = await _fetch_target(session, target_type, target_id)
    if target is None:
        return []
    # subscription не имеет pipeline_id (CS lifecycle привязан через
    # lifecycle_stage_id, но как target on_create не семантичен — отложено)
    pipeline_id = getattr(target, "pipeline_id", None)
    if pipeline_id is None:
        return []

    stmt = (
        select(PipelineAutomation)
        .where(
            PipelineAutomation.pipeline_id == pipeline_id,
            PipelineAutomation.trigger_kind == "on_create",
            PipelineAutomation.is_active.is_(True),
        )
        .order_by(PipelineAutomation.id)
    )
    automations = (await session.execute(stmt)).scalars().all()
    # trigger_event_ts = момент создания цели (created_at). Дедуп защищает от
    # повторного дёрга on_create по той же сущности (например, ретрай inbox).
    event_ts = getattr(target, "created_at", None) or datetime.now(UTC)
    runs: list[AutomationRun] = []
    for a in automations:
        try:
            run = await execute_action(
                session, a, target_type, target_id,
                trigger_event_ts=event_ts, defer_network=defer_network,
            )
            runs.append(run)
        except Exception as e:  # noqa: BLE001
            logger.warning(
                "run_on_create_automations: automation %s failed catastrophically: %s",
                a.id, e,
            )
    return runs


async def run_on_enter_stage(
    session: AsyncSession,
    pipeline_id: int,
    stage_id: int,
    target_type: str,
    target_id: int,
    defer_network: bool = True,
) -> list[AutomationRun]:
    """Inline executor: пройти все автоматизации on_enter_stage для (pipeline_id, stage_id).

    POST-AUDIT #4: defer_network=True по умолчанию (этот путь — всегда из
    реквеста /deals|/leads move). Сетевые действия (tg_notify/webhook/email)
    уходят в фоновый таск на свежей сессии, не блокируя HTTP-ответ на drag
    карточки. DB-local действия исполняются синхронно как раньше.

    Включает автоматизации с stage_id IS NULL (на всех этапах воронки).
    Не блокирует caller: catch всех ошибок отдельно, не валит транзакцию.

    Идемпотентность (C3): trigger_event_ts = stage_changed_at цели. Повторный
    вход в тот же этап с тем же stage_changed_at пропускается через
    ux_automation_runs_idem (например, двойной PATCH /deals/{id} без реальной
    смены этапа). Если у цели нет stage_changed_at (lead без лога переходов) —
    fallback на текущее время (дедупа по точному моменту нет, но это безопасно:
    on_enter инициируется явным действием пользователя, не cron'ом).
    """
    stmt = (
        select(PipelineAutomation)
        .where(
            PipelineAutomation.pipeline_id == pipeline_id,
            PipelineAutomation.trigger_kind == "on_enter_stage",
            PipelineAutomation.is_active.is_(True),
            or_(
                PipelineAutomation.stage_id == stage_id,
                PipelineAutomation.stage_id.is_(None),
            ),
        )
        .order_by(PipelineAutomation.id)
    )
    automations = (await session.execute(stmt)).scalars().all()
    if not automations:
        return []

    # trigger_event_ts = момент смены этапа (для дедупа повторного входа).
    target = await _fetch_target(session, target_type, target_id)
    event_ts = getattr(target, "stage_changed_at", None) if target else None
    if event_ts is None:
        event_ts = datetime.now(UTC)

    runs: list[AutomationRun] = []
    for a in automations:
        try:
            run = await execute_action(
                session, a, target_type, target_id,
                trigger_event_ts=event_ts, defer_network=defer_network,
            )
            runs.append(run)
        except Exception as e:  # noqa: BLE001
            logger.warning(
                "run_on_enter_stage: automation %s failed catastrophically: %s",
                a.id, e,
            )
    return runs


async def run_idle_in_stage_scanner(session: AsyncSession) -> list[AutomationRun]:
    """Cron-сканер: автоматизации idle_in_stage_days по deals и leads.

    Алгоритм:
    - Для каждой активной автоматизации с trigger_kind='idle_in_stage_days':
      * читаем окно через parse_idle_window (поддерживает days + idle_in_stage_hours
        Эпик 19 для SLA);
      * выбираем deals/leads в нужной воронке/этапе (или всех этапах если stage_id NULL),
        у которых stage_changed_at (deals) / updated_at (leads) старше окна;
      * если за это окно уже был run по этой автоматизации+target → skipped (dedup);
      * иначе execute_action.

    Эпик 19: для SLA-автоматизаций с шагом часов (`idle_in_stage_hours`) cutoff
    рассчитывается как `now - (days*24 + hours)`. На существующих автоматизациях
    без hours — обратной совместимости полная.

    target_type определяется конфигом: cfg.target_type ('deal' | 'lead', default 'deal').
    """
    stmt = select(PipelineAutomation).where(
        PipelineAutomation.trigger_kind == "idle_in_stage_days",
        PipelineAutomation.is_active.is_(True),
    )
    automations = (await session.execute(stmt)).scalars().all()
    runs: list[AutomationRun] = []
    now = datetime.now(UTC)

    for a in automations:
        cfg = a.trigger_config or {}
        days, hours = parse_idle_window(cfg)
        target_type = cfg.get("target_type", "deal")
        if target_type not in ("deal", "lead"):
            continue  # subscription не поддерживает idle-in-stage в MVP

        # Эпик 19: окно может быть в часах (SLA) — timedelta учитывает оба.
        cutoff = now - timedelta(days=days, hours=hours)
        model = _get_target_model(target_type)
        if model is None:
            continue

        target_stmt = select(model).where(model.pipeline_id == a.pipeline_id)
        if a.stage_id is not None:
            target_stmt = target_stmt.where(model.stage_id == a.stage_id)
        # Для deals — stage_changed_at; для leads — updated_at (нет лога переходов)
        if target_type == "deal":
            target_stmt = target_stmt.where(
                model.stage_changed_at.is_not(None),
                model.stage_changed_at <= cutoff,
            )
        else:
            target_stmt = target_stmt.where(model.updated_at <= cutoff)

        window = timedelta(days=days, hours=hours)
        targets = (await session.execute(target_stmt)).scalars().all()
        for t in targets:
            # trigger_event_ts = момент, когда idle ПЕРВЫЙ раз превысил окно для
            # ЭТОЙ цели (stage_changed_at/updated_at + window), округлённый до
            # часа. Стабилен между тиками cron → ux_automation_runs_idem дедупит
            # повтор внутри одного idle-эпизода и на scale=2. Новый вход в этап
            # (stage_changed_at сбрасывается) даёт новый event_ts → пере-срабатывает.
            base_ts = (
                t.stage_changed_at if target_type == "deal" else t.updated_at
            )
            if base_ts is None:
                base_ts = now
            event_ts = floor_to_hour(base_ts + window)
            try:
                run = await execute_action(
                    session, a, target_type, t.id, trigger_event_ts=event_ts
                )
                runs.append(run)
                # Эпик 19: SLA-breach → in-app notification owner'у (если есть).
                # safe_create_notification catch'ит на ошибку.
                if a.is_sla and run.status == "success":
                    try:
                        await _create_sla_breach_notification(session, a, t)
                    except Exception as e:  # noqa: BLE001
                        logger.warning(
                            "sla breach notification failed for automation %s: %s",
                            a.id, e,
                        )
            except Exception as e:  # noqa: BLE001
                import sentry_sdk
                sentry_sdk.capture_exception(e)
                logger.warning(
                    "idle scanner: automation %s failed for %s#%s: %s",
                    a.id, target_type, t.id, e,
                )
    return runs


async def _create_sla_breach_notification(
    session: AsyncSession, automation: PipelineAutomation, target
) -> None:
    """Эпик 19: создать in-app sla_breach нотификацию для owner target'а.

    Catch на любые ошибки — нотификация не должна валить основную работу.
    Не пишет нотификацию, если у target нет owner.
    """
    owner_id = _get_target_owner_user_id(target)
    if owner_id is None or owner_id <= 0:
        return
    target_type = (
        "deal" if isinstance(target, Deal)
        else "lead" if isinstance(target, Lead)
        else "subscription" if isinstance(target, ClientSubscription)
        else "unknown"
    )
    target_title = (
        getattr(target, "title", None)
        or getattr(target, "name", None)
        or f"{target_type} #{target.id}"
    )
    try:
        from app.services.notifications import (
            build_sla_breach_notification,
            safe_create_notification,
        )
        notif_data = build_sla_breach_notification(
            target_type=target_type,
            target_id=int(getattr(target, "id")),
            target_title=str(target_title),
            owner_user_id=owner_id,
            breach_reason=f"SLA: {automation.name}",
        )
        await safe_create_notification(session, **notif_data)
    except Exception as e:  # noqa: BLE001
        logger.warning("sla notification builder failed: %s", e)


async def _execute_escalation_step(
    session: AsyncSession,
    automation: PipelineAutomation,
    esc: dict[str, Any],
    target_type: str,
    target_id: int,
    trigger_event_ts: datetime,
) -> AutomationRun | None:
    """Выполнить ОДИН шаг escalation_chain (esc.action_kind / esc.action_config)
    против target, с идемпотентной записью AutomationRun.

    Эскалация исполняется тем же набором handler'ов, что и обычное действие, но
    с action_kind/action_config из шага эскалации (а не из самой автоматизации).
    Реализовано через временную подмену action_kind/action_config на объекте
    automation (in-place, восстанавливается в finally) — это позволяет
    переиспользовать execute_action без дублирования логики записи run'а и без
    отдельной FK-сущности.

    Дедуп: trigger_event_ts уникален per (automation, target, escalation-level)
    т.к. включает offset after_hours — ux_automation_runs_idem не даст повторить
    тот же уровень эскалации дважды.

    Возвращает AutomationRun (или None, если action_kind эскалации неизвестен).
    """
    esc_action_kind = esc.get("action_kind")
    if esc_action_kind not in _ACTION_HANDLERS:
        logger.warning(
            "escalation: неизвестный action_kind '%s' в automation %s",
            esc_action_kind, automation.id,
        )
        return None
    esc_action_config = esc.get("action_config") or {}

    orig_kind = automation.action_kind
    orig_config = automation.action_config
    automation.action_kind = esc_action_kind
    automation.action_config = esc_action_config
    try:
        return await execute_action(
            session, automation, target_type, target_id,
            trigger_event_ts=trigger_event_ts,
        )
    finally:
        # Восстанавливаем исходные значения — НЕ flush'им (set обратно в памяти,
        # SQLAlchemy не пометит как изменённое, т.к. значения равны исходным).
        automation.action_kind = orig_kind
        automation.action_config = orig_config


async def run_escalation_scanner(session: AsyncSession) -> list[AutomationRun]:
    """Cron-сканер SLA-эскалаций (HIGH-фикс: раньше escalation_chain был мёртвым
    кодом — хранился/сидился/показывался, но никто не читал after_hours).

    Для каждой active is_sla автоматизации с непустым escalation_chain и
    trigger_kind='idle_in_stage_days':
    - base_window = parse_idle_window(trigger_config) — порог первичного breach;
    - для каждого уровня эскалации (по возрастанию after_hours): порог =
      base_window + after_hours;
    - находим targets (deal/lead), idle past порога;
    - исполняем esc.action_kind/esc.action_config через _execute_escalation_step
      с дедупом по ux_automation_runs_idem.

    MVP: эскалации поддержаны только для idle-based SLA (это всё, что сидится).
    date_field-эскалации — TBD (нужен отдельный расчёт breach-окна).

    Не падает наружу: catch на каждую автоматизацию/уровень/target.
    """
    stmt = select(PipelineAutomation).where(
        PipelineAutomation.trigger_kind == "idle_in_stage_days",
        PipelineAutomation.is_active.is_(True),
        PipelineAutomation.is_sla.is_(True),
    )
    automations = (await session.execute(stmt)).scalars().all()
    runs: list[AutomationRun] = []
    now = datetime.now(UTC)

    for a in automations:
        chain = a.escalation_chain or []
        if not chain:
            continue
        cfg = a.trigger_config or {}
        days, hours = parse_idle_window(cfg)
        target_type = cfg.get("target_type", "deal")
        if target_type not in ("deal", "lead"):
            continue
        model = _get_target_model(target_type)
        if model is None:
            continue
        base_window = timedelta(days=days, hours=hours)

        # Уровни эскалации по возрастанию after_hours (детерминированный порядок).
        levels = sorted(
            (e for e in chain if isinstance(e, dict)),
            key=lambda e: _safe_after_hours(e.get("after_hours")),
        )
        for esc in levels:
            after_hours = _safe_after_hours(esc.get("after_hours"))
            if after_hours <= 0:
                continue
            esc_window = base_window + timedelta(hours=after_hours)
            cutoff = now - esc_window

            target_stmt = select(model).where(model.pipeline_id == a.pipeline_id)
            if a.stage_id is not None:
                target_stmt = target_stmt.where(model.stage_id == a.stage_id)
            if target_type == "deal":
                target_stmt = target_stmt.where(
                    model.stage_changed_at.is_not(None),
                    model.stage_changed_at <= cutoff,
                )
            else:
                target_stmt = target_stmt.where(model.updated_at <= cutoff)

            targets = (await session.execute(target_stmt)).scalars().all()
            for t in targets:
                base_ts = (
                    t.stage_changed_at if target_type == "deal" else t.updated_at
                )
                if base_ts is None:
                    base_ts = now
                # event_ts = момент пересечения порога эскалации (стабилен →
                # дедуп per-level). after_hours входит в offset, так что разные
                # уровни не конфликтуют в индексе.
                event_ts = floor_to_hour(base_ts + esc_window)
                try:
                    run = await _execute_escalation_step(
                        session, a, esc, target_type, t.id, event_ts
                    )
                    if run is not None:
                        runs.append(run)
                except Exception as e:  # noqa: BLE001
                    import sentry_sdk
                    sentry_sdk.capture_exception(e)
                    logger.warning(
                        "escalation scanner: automation %s level +%dh failed "
                        "for %s#%s: %s",
                        a.id, after_hours, target_type, t.id, e,
                    )
    return runs


def _safe_after_hours(v: Any) -> int:
    """Безопасный парс after_hours из escalation-шага (нечитаемое → 0)."""
    try:
        return max(0, int(v))
    except (ValueError, TypeError):
        return 0


def date_field_base_breach_ts(field_date: date, days: int) -> datetime:
    """Pure-функция: момент первичного date_field-breach (UTC-aware).

    Базовый date_field-сканер считает trigger_event_ts как (значение поля − days),
    нормализованное к началу дня. Эта функция выносит ту же арифметику для
    переиспользования в escalation-сканере, чтобы breach-точка совпадала с базовой
    (один и тот же «нулевой» момент, от которого отсчитываются уровни эскалации).

    days < 0 нормализуется к 0.
    """
    safe_days = max(0, days)
    midnight = datetime(
        field_date.year, field_date.month, field_date.day, tzinfo=UTC
    )
    return midnight - timedelta(days=safe_days)


def date_field_escalation_event_ts(
    field_date: date, days: int, after_hours: int
) -> datetime:
    """Pure-функция: стабильный per-level trigger_event_ts для date_field-эскалации.

    Формула breach-окна уровня эскалации:

        base_breach   = midnight(field_date) − days            (как в базовом сканере)
        level_breach  = base_breach + after_hours              (offset уровня)
        event_ts      = floor_to_hour(level_breach)

    Иначе говоря, escalation level «срабатывает», когда
    `now >= (field_date − days) + after_hours`. event_ts стабилен (зависит только
    от field_date/days/after_hours, не от now) → ux_automation_runs_idem дедупит
    повторный прогон cron в окне и на scale=2. after_hours входит в offset, поэтому
    разные уровни не конфликтуют в idem-индексе (как в idle-эскалации).

    floor_to_hour здесь no-op для целых after_hours от полуночного base_breach, но
    оставлен для единообразия с idle-сканером и устойчивости к будущим дробным
    смещениям.
    """
    base_breach = date_field_base_breach_ts(field_date, days)
    safe_after = _safe_after_hours(after_hours)
    return floor_to_hour(base_breach + timedelta(hours=safe_after))


async def run_date_field_scanner(session: AsyncSession) -> list[AutomationRun]:
    """Cron-сканер: автоматизации date_field_approaching по subscriptions (и опционально
    deals/leads).

    Конфиг: {field: 'discount_until', days: 30, target_type: 'subscription'}.
    Окно срабатывания: today + days находится в [today + days - 1, today + days + 1]
    (3-дневное окно сглаживает задержки cron'а).

    Поддерживаемые поля по target_type:
    - subscription: discount_until, impl_start_date, act_signed_date,
      last_fee_increase_at, qa_date
    - deal: closed_at (date_field interpret as date)
    - lead: converted_at
    """
    stmt = select(PipelineAutomation).where(
        PipelineAutomation.trigger_kind == "date_field_approaching",
        PipelineAutomation.is_active.is_(True),
    )
    automations = (await session.execute(stmt)).scalars().all()
    runs: list[AutomationRun] = []
    today = date.today()

    for a in automations:
        cfg = a.trigger_config or {}
        field = cfg.get("field")
        try:
            days = int(cfg.get("days", 7))
        except (ValueError, TypeError):
            days = 7
        if days < 0:
            days = 0
        target_type = cfg.get("target_type", "subscription")
        # HIGH-фикс: date_field теперь работает не только на subscription, но и
        # на deal(closed_at)/lead(converted_at). DATE_FIELDS — module-level
        # whitelist (см. выше), общий с этим сканером.
        allowed_fields = DATE_FIELDS.get(target_type, frozenset())
        if not allowed_fields:
            continue  # неизвестный target_type
        if not field or field not in allowed_fields:
            continue

        # Окно: [today + days - 1, today + days + 1]
        target_date_low = today + timedelta(days=max(0, days - 1))
        target_date_high = today + timedelta(days=days + 1)

        model = _get_target_model(target_type)
        if model is None:
            continue
        col = getattr(model, field, None)
        if col is None:
            continue

        # closed_at/converted_at — DateTime-колонки; discount_until и пр. у
        # subscription — Date. func.date(col) нормализует обе к дате, чтобы
        # окно [low, high] сравнивалось корректно (без отсечения по времени дня).
        col_as_date = func.date(col)
        target_stmt = select(model).where(
            col.is_not(None),
            col_as_date >= target_date_low,
            col_as_date <= target_date_high,
        )
        targets = (await session.execute(target_stmt)).scalars().all()
        for t in targets:
            # trigger_event_ts = (значение поля − days), нормализованное к
            # началу дня. Стабилен → ux_automation_runs_idem дедупит повтор в
            # 3-дневном окне и на scale=2. Если дата сдвинули — новый event_ts.
            field_val = getattr(t, field, None)
            if field_val is None:
                continue
            if isinstance(field_val, datetime):
                field_date = field_val.date()
            else:
                field_date = field_val  # date
            event_ts = datetime(
                field_date.year, field_date.month, field_date.day, tzinfo=UTC
            ) - timedelta(days=days)
            try:
                run = await execute_action(
                    session, a, target_type, t.id, trigger_event_ts=event_ts
                )
                runs.append(run)
                # is_sla-ветка по образцу idle-сканера: SLA-breach → in-app
                # нотификация owner'у (если действие реально отработало).
                if a.is_sla and run.status == "success":
                    try:
                        await _create_sla_breach_notification(session, a, t)
                    except Exception as e:  # noqa: BLE001
                        logger.warning(
                            "date_field sla notification failed for automation %s: %s",
                            a.id, e,
                        )
            except Exception as e:  # noqa: BLE001
                import sentry_sdk
                sentry_sdk.capture_exception(e)
                logger.warning(
                    "date_field scanner: automation %s failed for %s#%s: %s",
                    a.id, target_type, t.id, e,
                )
    return runs


async def run_date_field_escalation_scanner(
    session: AsyncSession,
) -> list[AutomationRun]:
    """Cron-сканер SLA-эскалаций для date_field_approaching (POST-AUDIT #7).

    Закрывает гэп: `run_escalation_scanner` обрабатывал escalation_chain только
    для idle-based SLA; date_field-эскалации были TBD. Этот сканер исполняет те
    же escalation_chain-уровни для автоматизаций с
    trigger_kind='date_field_approaching'.

    Цели: активные is_sla автоматизации с trigger_kind='date_field_approaching' и
    непустым escalation_chain. Поле — из того же DATE_FIELDS whitelist по
    target_type (subscription/deal/lead), что и базовый date_field-сканер.

    Формула breach-окна (см. date_field_escalation_event_ts):

        base_breach  = midnight(field_date) − days     (как в базовом сканере)
        level_breach = base_breach + after_hours       (offset уровня эскалации)

    Уровень эскалации срабатывает, когда `now >= level_breach`. Уровни идут по
    возрастанию after_hours (детерминированный порядок, как в idle-эскалации).

    Дедуп: event_ts = floor_to_hour(level_breach) — стабилен и включает
    after_hours в offset, поэтому разные уровни не конфликтуют в
    ux_automation_runs_idem, а повторный тик cron в окне / вторая реплика (scale=2)
    не исполнят тот же уровень дважды.

    Исполнение шага — через _execute_escalation_step (запись AutomationRun не
    дублируется). Не падает наружу: catch на каждую автоматизацию/уровень/цель +
    sentry.capture_exception.
    """
    stmt = select(PipelineAutomation).where(
        PipelineAutomation.trigger_kind == "date_field_approaching",
        PipelineAutomation.is_active.is_(True),
        PipelineAutomation.is_sla.is_(True),
    )
    automations = (await session.execute(stmt)).scalars().all()
    runs: list[AutomationRun] = []
    now = datetime.now(UTC)

    for a in automations:
        chain = a.escalation_chain or []
        if not chain:
            continue
        cfg = a.trigger_config or {}
        field = cfg.get("field")
        try:
            days = int(cfg.get("days", 7))
        except (ValueError, TypeError):
            days = 7
        if days < 0:
            days = 0
        target_type = cfg.get("target_type", "subscription")
        allowed_fields = DATE_FIELDS.get(target_type, frozenset())
        if not allowed_fields:
            continue  # неизвестный target_type
        if not field or field not in allowed_fields:
            continue

        model = _get_target_model(target_type)
        if model is None:
            continue
        col = getattr(model, field, None)
        if col is None:
            continue

        # Уровни эскалации по возрастанию after_hours (детерминированный порядок,
        # как в idle-эскалации).
        levels = sorted(
            (e for e in chain if isinstance(e, dict)),
            key=lambda e: _safe_after_hours(e.get("after_hours")),
        )
        for esc in levels:
            after_hours = _safe_after_hours(esc.get("after_hours"))
            if after_hours <= 0:
                continue
            try:
                # Цели, у которых level_breach = (field_date − days) + after_hours
                # уже наступил (now >= level_breach). Эквивалентно:
                #   field_date <= now + days − after_hours/24
                # Грубо фильтруем в SQL по верхней границе даты поля (func.date,
                # как в базовом сканере — нормализует Date и DateTime колонки),
                # точную проверку now >= level_breach делаем в Python.
                upper_dt = now + timedelta(days=days) - timedelta(hours=after_hours)
                col_as_date = func.date(col)
                target_stmt = select(model).where(
                    col.is_not(None),
                    col_as_date <= upper_dt.date(),
                )
                targets = (await session.execute(target_stmt)).scalars().all()
            except Exception as e:  # noqa: BLE001
                import sentry_sdk
                sentry_sdk.capture_exception(e)
                logger.warning(
                    "date_field escalation scanner: query failed for "
                    "automation %s level +%dh: %s",
                    a.id, after_hours, e,
                )
                continue

            for t in targets:
                try:
                    field_val = getattr(t, field, None)
                    if field_val is None:
                        continue
                    if isinstance(field_val, datetime):
                        field_date = field_val.date()
                    else:
                        field_date = field_val  # date
                    event_ts = date_field_escalation_event_ts(
                        field_date, days, after_hours
                    )
                    # Точная проверка: уровень эскалации наступил только если
                    # now пересёк level_breach (SQL-фильтр по func.date грубее
                    # из-за округления к дню).
                    if now < event_ts:
                        continue
                    run = await _execute_escalation_step(
                        session, a, esc, target_type, t.id, event_ts
                    )
                    if run is not None:
                        runs.append(run)
                except Exception as e:  # noqa: BLE001
                    import sentry_sdk
                    sentry_sdk.capture_exception(e)
                    logger.warning(
                        "date_field escalation scanner: automation %s level "
                        "+%dh failed for %s#%s: %s",
                        a.id, after_hours, target_type, t.id, e,
                    )
    return runs


async def dry_run_action(
    session: AsyncSession,
    automation: PipelineAutomation,
    target_type: str,
    target_id: int,
) -> dict[str, Any]:
    """Preview для UI: возвращает что бы сделалось БЕЗ side-effects и БЕЗ записи
    AutomationRun. Используется в POST /api/automations/{id}/test."""
    target = await _fetch_target(session, target_type, target_id)
    if target is None:
        return {
            "would_execute": False,
            "reason": f"target {target_type}#{target_id} не найден",
            "automation_id": automation.id,
            "action_kind": automation.action_kind,
        }

    owner_id = _get_target_owner_user_id(target)
    owner: User | None = None
    if owner_id:
        owner = (
            await session.execute(select(User).where(User.id == owner_id))
        ).scalar_one_or_none()

    cfg = automation.action_config or {}
    preview: dict[str, Any] = {
        "would_execute": True,
        "automation_id": automation.id,
        "action_kind": automation.action_kind,
        "target_type": target_type,
        "target_id": target_id,
        "target_owner_user_id": owner_id,
    }

    if automation.action_kind == "tg_notify":
        recipient_spec = cfg.get("recipient", "owner")
        kind, value = _resolve_recipient(recipient_spec, target, owner)
        if kind == "user_id" and value is not None:
            user = (
                await session.execute(select(User).where(User.id == int(value)))
            ).scalar_one_or_none()
            if user and user.telegram_user_id:
                kind, value = ("telegram_user_id", user.telegram_user_id)
        preview["recipient"] = {"kind": kind, "value": value}
        preview["message"] = _format_message(cfg.get("message", ""), target, owner)
        if kind == "none":
            preview["would_execute"] = False
            preview["reason"] = "не задан получатель"
    elif automation.action_kind == "create_task":
        responsible_id = _resolve_user_id(cfg.get("responsible", "owner"), target, owner)
        due_days = cfg.get("due_days")
        try:
            due_days_int = int(due_days) if due_days is not None else None
        except (ValueError, TypeError):
            due_days_int = None
        preview["task"] = {
            "title": _format_message(
                cfg.get("title") or f"Автозадача: {automation.name}", target, owner
            ),
            "body": _format_message(cfg.get("body", ""), target, owner) if cfg.get("body") else None,
            "responsible_id": responsible_id,
            "due_in_days": due_days_int,
        }
    elif automation.action_kind == "set_field":
        field = cfg.get("field")
        target_t = (
            "deal" if isinstance(target, Deal)
            else "lead" if isinstance(target, Lead)
            else "subscription"
        )
        allowed = SET_FIELD_WHITELIST.get(target_t, frozenset())
        if not field or field not in allowed:
            preview["would_execute"] = False
            preview["reason"] = (
                f"поле '{field}' не разрешено для {target_t}"
                if field
                else "не указано поле"
            )
        else:
            preview["set_field"] = {
                "field": field,
                "old_value": str(getattr(target, field, None))
                if getattr(target, field, None) is not None
                else None,
                "new_value": cfg.get("value"),
            }
    elif automation.action_kind == "generate_document":
        preview["generate_document"] = {
            "template_code": cfg.get("template_code"),
            "stub": True,
            "tbd": "real render via contract-specialist",
        }
        if not cfg.get("template_code"):
            preview["would_execute"] = False
            preview["reason"] = "template_code не задан"
    elif automation.action_kind == "change_owner":
        rule = cfg.get("rule", "round_robin")
        if rule not in CHANGE_OWNER_RULES:
            preview["would_execute"] = False
            preview["reason"] = f"неизвестное правило: {rule}"
        else:
            user_pool_filter = cfg.get("user_pool_filter") or {}
            pool = await _resolve_user_pool(session, user_pool_filter) \
                if isinstance(user_pool_filter, dict) else []
            target_t = (
                "deal" if isinstance(target, Deal)
                else "lead" if isinstance(target, Lead)
                else "subscription" if isinstance(target, ClientSubscription)
                else None
            )
            preview["change_owner"] = {
                "rule": rule,
                "user_pool_filter": user_pool_filter,
                "pool_size": len(pool),
                "current_owner": _get_target_owner_user_id(target),
                "target_owner_field": resolve_owner_field_name(target_t) if target_t else None,
            }
            if not pool:
                preview["would_execute"] = False
                preview["reason"] = "пул кандидатов пуст"
    elif automation.action_kind == "webhook":
        url = cfg.get("url")
        preview["webhook"] = {
            "url": url,
            "has_secret": bool(cfg.get("secret")),
            "headers": cfg.get("headers") or {},
        }
        if not url:
            preview["would_execute"] = False
            preview["reason"] = "url не задан"
    elif automation.action_kind == "email":
        from app.config import get_settings
        settings = get_settings()
        preview["email"] = {
            "recipient_role": cfg.get("recipient_role", "owner"),
            "recipient_user_id": cfg.get("recipient_user_id"),
            "subject_preview": _format_message(cfg.get("subject_template", ""), target, owner),
            "smtp_configured": bool(settings.smtp_host),
        }
        if not settings.smtp_host:
            preview["would_execute"] = False
            preview["reason"] = "smtp_not_configured"
    elif automation.action_kind == "start_sequence":
        sequence_id = cfg.get("sequence_id")
        preview["start_sequence"] = {"sequence_id": sequence_id}
        if sequence_id is None:
            preview["would_execute"] = False
            preview["reason"] = "sequence_id не задан"
    elif automation.action_kind == "set_tags":
        # Эпик 23.
        raw_tags = cfg.get("tags") or []
        if not isinstance(raw_tags, list):
            preview["would_execute"] = False
            preview["reason"] = "tags должен быть list"
        else:
            delta_tags = [str(t).strip() for t in raw_tags if str(t).strip()]
            mode = cfg.get("mode", "add")
            target_t = (
                "deal" if isinstance(target, Deal)
                else "lead" if isinstance(target, Lead)
                else "subscription" if isinstance(target, ClientSubscription)
                else "unknown"
            )
            current_tags = getattr(target, "tags", None) or []
            preview["set_tags"] = {
                "mode": mode,
                "delta_tags": delta_tags,
                "current_tags": list(current_tags) if current_tags else [],
                "preview_new_tags": apply_tag_mode(current_tags, delta_tags, mode),
                "target_type": target_t,
                "supported": target_t in SET_TAGS_TARGETS,
            }
            if mode not in SET_TAGS_MODES:
                preview["would_execute"] = False
                preview["reason"] = f"неизвестный mode: {mode}"
            elif target_t not in SET_TAGS_TARGETS:
                preview["would_execute"] = False
                preview["reason"] = f"target_type={target_t} не поддерживает tags"
    elif automation.action_kind == "complete_tasks":
        # Эпик 23.
        filter_mode = cfg.get("filter", "open_only")
        target_t = (
            "deal" if isinstance(target, Deal)
            else "lead" if isinstance(target, Lead)
            else "subscription" if isinstance(target, ClientSubscription)
            else None
        )
        preview["complete_tasks"] = {
            "filter": filter_mode,
            "target_type": target_t,
        }
        if filter_mode not in COMPLETE_TASKS_FILTERS:
            preview["would_execute"] = False
            preview["reason"] = f"неизвестный filter: {filter_mode}"
    elif automation.action_kind == "change_stage":
        # Эпик 23.
        new_stage_id = cfg.get("new_stage_id")
        preview["change_stage"] = {
            "new_stage_id": new_stage_id,
            "reason": cfg.get("reason"),
            "current_stage_id": getattr(target, "stage_id", None),
        }
        if new_stage_id is None:
            preview["would_execute"] = False
            preview["reason"] = "new_stage_id не задан"
        elif not isinstance(target, (Deal, Lead)):
            preview["would_execute"] = False
            preview["reason"] = "change_stage поддерживается только для deal/lead"
    elif automation.action_kind == "create_deal":
        # Эпик 23.
        title_tpl = cfg.get("title_template") or ""
        pipeline_id_cfg = cfg.get("pipeline_id")
        preview["create_deal"] = {
            "title_template": title_tpl,
            "pipeline_id": pipeline_id_cfg,
            "stage_id": cfg.get("stage_id"),
            "amount": cfg.get("amount"),
            "currency": cfg.get("currency"),
            "counterparty_id_from_context": cfg.get(
                "counterparty_id_from_context", True
            ),
            # Превью title — но без полного name lookup из БД (это dry-run)
            "title_preview": render_simple_template(
                str(title_tpl),
                {
                    "target_id": getattr(target, "id", ""),
                    "target_title": (
                        getattr(target, "title", None)
                        or getattr(target, "name", None)
                        or ""
                    ),
                    "owner_name": owner.full_name if owner else "",
                    "counterparty_name": "",
                },
            )[:255],
        }
        if not str(title_tpl).strip():
            preview["would_execute"] = False
            preview["reason"] = "title_template не задан"
        elif pipeline_id_cfg is None:
            preview["would_execute"] = False
            preview["reason"] = "pipeline_id не задан"
    else:
        preview["would_execute"] = False
        preview["reason"] = f"неизвестный action_kind: {automation.action_kind}"

    return preview
