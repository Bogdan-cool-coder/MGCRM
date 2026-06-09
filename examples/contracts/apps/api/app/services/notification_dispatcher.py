"""Эпик 21.2 — Multi-channel notification dispatcher.

Extension эпика 21: рассылка одной нотификации по нескольким каналам
(in_app, tg, email) с учётом per-user preferences, quiet hours и templates.

Архитектура:
- `dispatch(...)` — public API. Принимает payload + user_id + kind.
  Загружает per-channel preferences. Fan-out:
    in_app — пишет в notifications table (через services/notifications.create_notification);
    tg — рендерит template → отправляет через telegram bot;
    email — рендерит template → SMTP stub.
  Возвращает DispatchResult с per-channel статусами.

- `is_in_quiet_hours(user, now=None)` — pure helper. Проверяет, попадает ли
  текущий момент в [tg_quiet_hours_start, tg_quiet_hours_end] юзера.
  Если start > end — окно перекатывается через полночь (типичный кейс
  «не дёргать 21:00..09:00»).

- `render_notification_template(template, payload, locale)` — pure helper.
  Jinja-рендер subject + body. Без БД, удобно тестировать.

Backward compatibility:
  Существующие вызовы `create_notification` / `safe_create_notification`
  продолжают работать (они пишут только в in_app — старая семантика).
  Новый код использует `dispatch(...)`, который автоматически делает
  fan-out по всем каналам с уважением preferences.

Защита:
- ValueError при неизвестном kind/channel (whitelist check).
- Все сетевые операции (TG, email) обёрнуты в try/except — одна сломанная
  отправка не валит соседние каналы.
- Если preferences для (user, kind, channel) НЕТ в БД — по умолчанию
  считаем `is_enabled=True` (consistent с UX «всё включено для нового user'а»).
"""
from __future__ import annotations

import logging
import re
from dataclasses import dataclass, field
from datetime import datetime, time
from typing import Any

from jinja2 import Environment, StrictUndefined, TemplateError
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    NotificationChannelPreference,
    NotificationTemplate,
    User,
)
from app.services.notifications import (
    NOTIFICATION_KINDS,
    create_notification,
)

logger = logging.getLogger(__name__)

# Whitelist каналов. Расширяемо без миграции; для нового канала добавь
# обработку в _dispatch_<channel> + соответствующий case в dispatch().
NOTIFICATION_CHANNELS: tuple[str, ...] = ("in_app", "tg", "email")

# Jinja environment с StrictUndefined: если в шаблоне есть {{ var }} но var не
# передан в payload — рендер падает (а не молча подставляет пустое значение).
# Это ловит опечатки на раннем этапе.
#
# autoescape=False — ЭТОТ env рендерит ТОЛЬКО plain text (subject/body для
# TG, in_app, plain-part email). НИКОГДА не рендери через `_jinja_env` контент,
# который попадёт в HTML (email html_body) — payload содержит
# пользовательский ввод (title/body рассылки, имена контрагентов), и без
# экранирования это XSS в HTML-письме. Для HTML используй `_jinja_html_env`
# (autoescape=True) через `render_html_notification`.
_jinja_env = Environment(
    undefined=StrictUndefined,
    autoescape=False,
    trim_blocks=True,
    lstrip_blocks=True,
)

# Отдельный env для HTML-шаблонов email: autoescape=True экранирует {{ var }},
# поэтому пользовательский ввод в payload не сможет инжектить разметку/скрипты.
# Готов к будущему HTML-email каналу — см. render_html_notification ниже.
_jinja_html_env = Environment(
    undefined=StrictUndefined,
    autoescape=True,
    trim_blocks=True,
    lstrip_blocks=True,
)


# ============ Dataclasses ============


@dataclass
class ChannelResult:
    """Результат отправки на один канал."""

    channel: str
    # delivered | skipped_disabled | skipped_quiet_hours | skipped_no_template |
    # skipped_no_user_field | failed | smtp_not_configured | tg_not_configured
    status: str
    detail: str | None = None


@dataclass
class DispatchResult:
    """Агрегированный результат `dispatch(...)`.

    `channels` — список ChannelResult по каждому каналу из попыток рассылки.
    `any_delivered` — был ли хотя бы один success (для лога / response).
    """

    user_id: int
    kind: str
    channels: list[ChannelResult] = field(default_factory=list)

    @property
    def any_delivered(self) -> bool:
        return any(c.status == "delivered" for c in self.channels)


# ============ Pure helpers (тестируется без БД) ============


def is_in_quiet_hours(
    user: User | Any,  # Any чтобы можно было тестировать SimpleNamespace
    now: datetime | None = None,
) -> bool:
    """True, если текущее время попадает в quiet-window юзера.

    Поведение:
    - Если start или end NULL → quiet hours отключены → False.
    - Если start == end → окно нулевой длины → False (пользователь, видимо,
      сбросил quiet hours, поставив одинаковые значения).
    - Если start < end → обычный интервал в течение суток
      (например 13:00..15:00 → True если now в [13:00, 15:00)).
    - Если start > end → окно перекатывается через полночь
      (например 21:00..09:00 → True если now ≥ 21:00 ИЛИ now < 09:00).

    Сравнение по naive `time` (без TZ) — мы сейчас не знаем локального TZ
    юзера, считаем что quiet hours юзер задаёт в его локальном time. Для
    enhanced TZ-handling нужен User.timezone (TBD).
    """
    start: time | None = getattr(user, "tg_quiet_hours_start", None)
    end: time | None = getattr(user, "tg_quiet_hours_end", None)
    if start is None or end is None:
        return False
    if start == end:
        return False
    if now is None:
        now = datetime.now()
    cur = now.time()
    if start < end:
        # обычный интервал, например 13:00..15:00
        return start <= cur < end
    # окно через полночь, например 21:00..09:00
    return cur >= start or cur < end


def render_notification_template(
    template: NotificationTemplate | Any,
    payload: dict[str, Any],
    locale: str = "ru",
) -> dict[str, str | None]:
    """Рендерит subject + body Jinja-шаблона с переданным payload.

    Возвращает `{"subject": str|None, "body": str|None}`.

    Безопасность: при ошибке рендера (опечатка в шаблоне, отсутствие
    переменной) — НЕ выкидываем — логируем и возвращаем raw template.
    Это защищает dispatch-pipeline от «упавшего одного шаблона ломает
    всё уведомление».

    `locale` сейчас не используется в рендере (templates pre-filtered по
    locale в загрузчике), но оставлен в API для будущего if-rendering'а.
    """
    subject_tpl = getattr(template, "subject", None)
    body_tpl = getattr(template, "body_template", None)

    return {
        "subject": _safe_render(subject_tpl, payload) if subject_tpl else None,
        "body": _safe_render(body_tpl, payload) if body_tpl else None,
    }


def _safe_render(template_str: str, payload: dict[str, Any]) -> str:
    """Render Jinja с graceful fallback на raw на ошибке."""
    if not template_str:
        return ""
    try:
        return _jinja_env.from_string(template_str).render(**payload)
    except TemplateError as e:
        logger.warning(
            "notification template render failed: %s (template=%r)",
            e, template_str[:80],
        )
        return template_str  # raw — лучше чем пусто (для отладки)
    except Exception as e:  # noqa: BLE001
        logger.warning(
            "notification template unexpected error: %s",
            e,
        )
        return template_str


def render_html_notification(template_str: str, payload: dict[str, Any]) -> str:
    """Безопасный рендер HTML-шаблона email (autoescape=True).

    Любой пользовательский ввод в payload (title/body рассылки, имена) будет
    HTML-экранирован — XSS в письме невозможен через подстановку переменных.
    Использовать ТОЛЬКО для HTML-частей email; для plain text — _safe_render.

    На ошибке рендера возвращает экранированный raw-шаблон (не пусто), чтобы
    одно битое письмо не валило весь pipeline.
    """
    if not template_str:
        return ""
    try:
        return _jinja_html_env.from_string(template_str).render(**payload)
    except TemplateError as e:
        logger.warning(
            "notification HTML template render failed: %s (template=%r)",
            e, template_str[:80],
        )
        from markupsafe import escape

        return str(escape(template_str))
    except Exception as e:  # noqa: BLE001
        logger.warning("notification HTML template unexpected error: %s", e)
        from markupsafe import escape

        return str(escape(template_str))


# ============ DB helpers ============


async def load_preferences(
    session: AsyncSession,
    user_id: int,
    kind: str,
) -> dict[str, bool]:
    """Загружает per-channel is_enabled для (user, kind).

    Возвращает {"in_app": bool, "tg": bool, "email": bool, ...}. Каналы,
    отсутствующие в БД, считаются включёнными (default-on UX). Это даёт
    backward-compat: если seed preferences ещё не отработал — рассылка
    всё равно идёт.
    """
    rows = (
        await session.execute(
            select(NotificationChannelPreference).where(
                NotificationChannelPreference.user_id == user_id,
                NotificationChannelPreference.kind == kind,
            )
        )
    ).scalars().all()
    prefs: dict[str, bool] = {ch: True for ch in NOTIFICATION_CHANNELS}
    for r in rows:
        prefs[r.channel] = bool(r.is_enabled)
    return prefs


async def load_template(
    session: AsyncSession,
    kind: str,
    channel: str,
    locale: str = "ru",
) -> NotificationTemplate | None:
    """Загружает активный шаблон для (kind, channel, locale) или None."""
    return (
        await session.execute(
            select(NotificationTemplate).where(
                NotificationTemplate.kind == kind,
                NotificationTemplate.channel == channel,
                NotificationTemplate.locale == locale,
                NotificationTemplate.is_active.is_(True),
            )
        )
    ).scalar_one_or_none()


# ============ Main dispatcher ============


async def dispatch(
    session: AsyncSession,
    user_id: int,
    kind: str,
    payload: dict[str, Any] | None = None,
    *,
    title: str | None = None,
    body: str | None = None,
    link: str | None = None,
    metadata: dict[str, Any] | None = None,
    locale: str = "ru",
    now: datetime | None = None,
) -> DispatchResult:
    """Multi-channel рассылка одной нотификации.

    payload — словарь переменных для render_notification_template (например
    {"task": {"title": "..."}, "creator": {"full_name": "..."}, ...}).
    Если payload пуст и не указаны title/body — fallback на kind.

    title/body/link/metadata — fallback для in_app канала (если template не
    найден). Дает обратную совместимость с existing builders.

    Никогда не падает: все channel-уровневые ошибки catch'атся и возвращаются
    как ChannelResult(status='failed'). Так одна сломанная рассылка по
    email не валит TG + in_app.
    """
    if kind not in NOTIFICATION_KINDS:
        raise ValueError(
            f"Unknown notification kind: {kind!r}. "
            f"Add to NOTIFICATION_KINDS or use 'system'."
        )

    payload = payload or {}
    result = DispatchResult(user_id=user_id, kind=kind)

    # Загрузка юзера (нужен email, telegram_user_id, quiet hours, master switch).
    user = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    if user is None:
        # Юзер удалён — нет смысла рассылать. Возвращаем пустой результат.
        return result

    prefs = await load_preferences(session, user_id, kind)

    # ============ in_app ============
    if prefs.get("in_app", True):
        try:
            tpl = await load_template(session, kind, "in_app", locale)
            in_app_title = title
            in_app_body = body
            if tpl:
                rendered = render_notification_template(tpl, payload, locale)
                in_app_title = rendered.get("subject") or in_app_title or kind
                in_app_body = rendered.get("body") or in_app_body
            if in_app_title is None:
                in_app_title = kind  # last resort fallback
            await create_notification(
                session, user_id, kind,
                title=in_app_title,
                body=in_app_body,
                link=link,
                metadata=metadata,
            )
            result.channels.append(ChannelResult(
                channel="in_app", status="delivered",
            ))
        except Exception as e:  # noqa: BLE001
            logger.warning(
                "dispatch[in_app] failed: user=%s kind=%s err=%s",
                user_id, kind, e,
            )
            result.channels.append(ChannelResult(
                channel="in_app", status="failed", detail=str(e)[:200],
            ))
    else:
        result.channels.append(ChannelResult(
            channel="in_app", status="skipped_disabled",
        ))

    # ============ tg ============
    if prefs.get("tg", True):
        if not user.telegram_user_id:
            result.channels.append(ChannelResult(
                channel="tg", status="skipped_no_user_field",
                detail="user has no telegram_user_id",
            ))
        elif is_in_quiet_hours(user, now):
            result.channels.append(ChannelResult(
                channel="tg", status="skipped_quiet_hours",
            ))
        else:
            try:
                tpl = await load_template(session, kind, "tg", locale)
                tg_text = body or title or kind
                if tpl:
                    rendered = render_notification_template(tpl, payload, locale)
                    tg_text = rendered.get("body") or rendered.get("subject") or tg_text
                ok = await _send_tg(user.telegram_user_id, tg_text)
                if ok:
                    result.channels.append(ChannelResult(
                        channel="tg", status="delivered",
                    ))
                else:
                    result.channels.append(ChannelResult(
                        channel="tg", status="tg_not_configured",
                    ))
            except Exception as e:  # noqa: BLE001
                logger.warning(
                    "dispatch[tg] failed: user=%s kind=%s err=%s",
                    user_id, kind, e,
                )
                result.channels.append(ChannelResult(
                    channel="tg", status="failed", detail=str(e)[:200],
                ))
    else:
        result.channels.append(ChannelResult(
            channel="tg", status="skipped_disabled",
        ))

    # ============ email ============
    if prefs.get("email", True):
        if not getattr(user, "email_notifications_enabled", True):
            result.channels.append(ChannelResult(
                channel="email", status="skipped_disabled",
                detail="master switch off (email_notifications_enabled=false)",
            ))
        elif not user.email:
            result.channels.append(ChannelResult(
                channel="email", status="skipped_no_user_field",
                detail="user has no email",
            ))
        else:
            try:
                tpl = await load_template(session, kind, "email", locale)
                em_subject = title or kind
                em_body = body or ""
                if tpl:
                    rendered = render_notification_template(tpl, payload, locale)
                    em_subject = rendered.get("subject") or em_subject
                    em_body = rendered.get("body") or em_body
                from app.services.notification_email import send_email
                ok = await send_email(
                    to=user.email,
                    subject=em_subject,
                    body=em_body,
                )
                if ok:
                    result.channels.append(ChannelResult(
                        channel="email", status="delivered",
                    ))
                else:
                    # send_email уже залогировал детали
                    result.channels.append(ChannelResult(
                        channel="email", status="smtp_not_configured",
                    ))
            except Exception as e:  # noqa: BLE001
                logger.warning(
                    "dispatch[email] failed: user=%s kind=%s err=%s",
                    user_id, kind, e,
                )
                result.channels.append(ChannelResult(
                    channel="email", status="failed", detail=str(e)[:200],
                ))
    else:
        result.channels.append(ChannelResult(
            channel="email", status="skipped_disabled",
        ))

    return result


# ============ TG send wrapper ============


async def _send_tg(chat_id: int, text: str) -> bool:
    """Отправить TG-сообщение через aiogram bot.

    Возвращает True если отправлено, False если bot не сконфигурирован
    (для consistency с send_email). На ошибке отправки бросает.
    """
    from app.config import get_settings
    settings = get_settings()
    if not settings.telegram_bot_token:
        return False
    try:
        from app.services.telegram import get_bot
        bot = get_bot()
        await bot.send_message(chat_id=chat_id, text=text)
        return True
    except Exception:  # pragma: no cover
        raise


# ============ Safety wrapper ============


async def safe_dispatch(
    session: AsyncSession,
    user_id: int | None,
    kind: str,
    payload: dict[str, Any] | None = None,
    **kwargs: Any,
) -> DispatchResult | None:
    """Catch-all обёртка для integration points.

    Аналог safe_create_notification. Skip без exception если user_id None/<=0.
    Любая ошибка catch'ится и логируется → возвращает None. Это защищает
    бизнес-операцию (создание задачи, переход этапа) от сломанной рассылки.
    """
    if user_id is None or user_id <= 0:
        return None
    try:
        return await dispatch(session, user_id, kind, payload, **kwargs)
    except Exception as e:  # noqa: BLE001
        logger.warning(
            "safe_dispatch failed (user=%s, kind=%s): %s",
            user_id, kind, e,
        )
        return None


# ============ Broadcast support ============


@dataclass
class BroadcastRecipients:
    """Резолвленные получатели рассылки + meta для логов."""

    user_ids: list[int]
    filter_explanation: str


async def resolve_broadcast_recipients(
    session: AsyncSession,
    recipient_filter: dict[str, Any] | None,
) -> BroadcastRecipients:
    """Резолвит recipient_filter в список user_ids.

    Поддерживаемые фильтры (комбинируемые AND'ом):
    - role: 'manager' | 'admin' | 'director' | 'lawyer' (User.role)
    - department_id: int (User.department_id)
    - user_ids: list[int] (прямой whitelist)

    Если filter пуст / None / пустой dict → все активные юзеры.

    Возвращает unique user_ids + текстовое объяснение фильтра (для логов).
    """
    explanations: list[str] = []
    stmt = select(User.id).where(User.is_active.is_(True))

    if recipient_filter:
        role = recipient_filter.get("role")
        if role:
            from app.models import UserRole
            try:
                # Если приходит строка — проверим что она в whitelist
                role_enum = UserRole(role) if not isinstance(role, UserRole) else role
                stmt = stmt.where(User.role == role_enum)
                explanations.append(f"role={role}")
            except (ValueError, KeyError):
                explanations.append(f"role={role}(invalid,ignored)")

        dept_id = recipient_filter.get("department_id")
        if dept_id is not None:
            try:
                dept_int = int(dept_id)
                stmt = stmt.where(User.department_id == dept_int)
                explanations.append(f"department_id={dept_int}")
            except (ValueError, TypeError):
                explanations.append(f"department_id={dept_id}(invalid,ignored)")

        user_ids_raw = recipient_filter.get("user_ids")
        if user_ids_raw:
            try:
                user_ids_list = [int(x) for x in user_ids_raw]
                if user_ids_list:
                    stmt = stmt.where(User.id.in_(user_ids_list))
                    explanations.append(f"user_ids={user_ids_list[:5]}...")
            except (ValueError, TypeError):
                explanations.append("user_ids(invalid,ignored)")

    if not explanations:
        explanations.append("all_active_users")

    rows = (await session.execute(stmt)).scalars().all()
    user_ids = sorted(set(int(x) for x in rows))
    return BroadcastRecipients(
        user_ids=user_ids,
        filter_explanation=", ".join(explanations),
    )


# ============ Broadcast input normalization (pure, fail-safe) ============
#
# C7 CRITICAL (privacy): фронт исторически слал `recipients_filter` (plural),
# а схема ждала `recipient_filter` (singular) → Pydantic игнорировал лишний
# ключ → filter оставался None → рассылка уходила ВСЕМ. Делаем fail-SAFE:
# «всем» — это ТОЛЬКО явный {type: "all"}. Любой targeting-intent без
# валидного фильтра → ошибка (не «по умолчанию всем»).

_KNOWN_FILTER_KEYS: frozenset[str] = frozenset({"role", "department_id", "user_ids"})


class BroadcastFilterError(ValueError):
    """Невалидный/неоднозначный recipient_filter. Роутер мапит в HTTP 422."""


def normalize_recipient_filter(raw: dict[str, Any] | None) -> dict[str, Any] | None:
    """Fail-safe нормализация recipient_filter рассылки.

    Возвращает «канонический» фильтр для resolve_broadcast_recipients, либо
    бросает BroadcastFilterError на неоднозначном вводе.

    Правила (privacy-first):
    - None  → BroadcastFilterError. «Не указано» НИКОГДА не значит «всем» —
      это и был баг: пропавший plural-ключ молча рассылал всем.
    - {type: "all"} (или {"all": true}) → {} (явное «всем активным»). Это
      ЕДИНСТВЕННЫЙ способ разослать всем — намеренно, не случайно.
    - {role}/{department_id}/{user_ids} (хотя бы один непустой) → каноничный
      словарь с этими ключами.
    - targeting-intent присутствует (есть один из known-ключей), но ВСЕ
      значения пусты/None → BroadcastFilterError (иначе пустой фильтр
      провалился бы в «всем»).
    - словарь без единого осмысленного ключа → BroadcastFilterError.

    Pure-функция: без БД, тестируется напрямую.
    """
    if raw is None:
        raise BroadcastFilterError(
            "recipient_filter обязателен. Для рассылки всем активным укажите "
            'явно {"type": "all"}.'
        )
    if not isinstance(raw, dict):
        raise BroadcastFilterError("recipient_filter должен быть объектом")

    # Явное «всем активным» — единственный безопасный путь к broadcast-to-all.
    type_val = raw.get("type")
    if type_val == "all" or raw.get("all") is True:
        return {}

    out: dict[str, Any] = {}

    role = raw.get("role")
    if role not in (None, ""):
        out["role"] = role

    dept = raw.get("department_id")
    if dept not in (None, ""):
        out["department_id"] = dept

    user_ids = raw.get("user_ids")
    if user_ids:  # непустой список
        if not isinstance(user_ids, (list, tuple)):
            raise BroadcastFilterError("user_ids должен быть списком id")
        out["user_ids"] = list(user_ids)

    if out:
        return out

    # Сюда попадаем, если был targeting-intent (known-ключ присутствовал, но
    # значение пустое), ИЛИ словарь вовсе без known-ключей. И то и другое —
    # неоднозначно: НЕ дефолтим в «всем».
    raise BroadcastFilterError(
        "Пустой или неоднозначный recipient_filter. Укажите role / "
        'department_id / user_ids, либо {"type": "all"} для рассылки всем.'
    )


# Только относительные внутренние пути. Блокируем javascript:, http(s)://,
# protocol-relative //evil.com, и любые схемы — open-redirect / XSS guard.
_SAFE_LINK_RE = re.compile(r"^/[A-Za-z0-9/_\-?=&.%#]*$")


def sanitize_broadcast_link(link: str | None) -> str | None:
    """Разрешает только внутренние относительные пути ('/foo/bar').

    Возвращает очищенный link или None. Бросает BroadcastFilterError на:
    - protocol-relative '//host' (open-redirect),
    - абсолютные http(s):// / любые scheme: (javascript:, data:, ...),
    - пути не начинающиеся с одиночного '/'.

    Pure-функция. Пустая строка / None → None (ссылки нет — это валидно).
    """
    if link is None:
        return None
    s = link.strip()
    if s == "":
        return None
    if s.startswith("//"):
        raise BroadcastFilterError("Недопустимая ссылка (protocol-relative)")
    if not _SAFE_LINK_RE.match(s):
        raise BroadcastFilterError(
            "Ссылка должна быть внутренним относительным путём, начинающимся с '/'"
        )
    return s
