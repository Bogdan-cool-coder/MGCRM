"""Sentry error-tracking integration (SaaS).

Полный no-op, если SENTRY_DSN не задан (dev + текущий прод работают без изменений).

Главный риск интеграции — утечка секретов/PII в события об ошибках. Система
держит финансовые + auth данные, поэтому скрабинг ОБЯЗАТЕЛЕН:
  * `send_default_pii=False` — не цепляем IP / cookies / user data автоматически.
  * `scrub_sentry_event` (before_send) — вычищает cookies (особенно access_token
    JWT), Authorization / X-*-Token заголовки и любые data-ключи с секретами.
  * `scrub_sentry_breadcrumb` (before_breadcrumb) — то же для хлебных крошек.

Скраб написан как ЧИСТЫЕ функции (event -> event), чтобы их можно было
юнит-тестировать без сети и без инициализированного SDK.
"""

from __future__ import annotations

import logging
from typing import Any

logger = logging.getLogger(__name__)

# Подстроки имён ключей/полей, значения которых нужно вычищать из любых
# вложенных словарей события (request body, extra, contexts, vars кадра и т.п.).
# Сравнение — по нижнему регистру, по вхождению подстроки.
_SENSITIVE_KEY_SUBSTRINGS: tuple[str, ...] = (
    "password",
    "token",
    "secret",
    "api_key",
    "apikey",
    "anthropic",
    "jwt",
    "totp",
    "dsn",
    "smtp_password",
    "client_secret",
    "refresh_token",
    "authorization",
)

# Имена заголовков, которые вырезаем целиком (lower-case).
_SENSITIVE_HEADER_NAMES: frozenset[str] = frozenset(
    {
        "authorization",
        "cookie",
        "set-cookie",
        "x-admin-api-key",
        "proxy-authorization",
    }
)

_REDACTED = "[redacted]"


def _is_sensitive_key(key: str) -> bool:
    k = key.lower()
    if any(sub in k for sub in _SENSITIVE_KEY_SUBSTRINGS):
        return True
    # X-*-Token / X-*-Key / X-*-Secret заголовки и поля.
    if k.startswith("x-") and (
        k.endswith("-token") or k.endswith("-key") or k.endswith("-secret")
    ):
        return True
    return False


def _scrub_mapping(data: dict[str, Any]) -> dict[str, Any]:
    """Рекурсивно редактирует значения чувствительных ключей в словаре."""
    out: dict[str, Any] = {}
    for key, value in data.items():
        if isinstance(key, str) and _is_sensitive_key(key):
            out[key] = _REDACTED
            continue
        out[key] = _scrub_value(value)
    return out


def _scrub_value(value: Any) -> Any:
    if isinstance(value, dict):
        return _scrub_mapping(value)
    if isinstance(value, list):
        return [_scrub_value(v) for v in value]
    if isinstance(value, tuple):
        return tuple(_scrub_value(v) for v in value)
    return value


def _scrub_headers(headers: Any) -> Any:
    """Заголовки приходят как dict {name: val} или list[[name, val]]."""
    if isinstance(headers, dict):
        out: dict[str, Any] = {}
        for name, val in headers.items():
            if isinstance(name, str) and (
                name.lower() in _SENSITIVE_HEADER_NAMES or _is_sensitive_key(name)
            ):
                out[name] = _REDACTED
            else:
                out[name] = val
        return out
    if isinstance(headers, list):
        out_list: list[Any] = []
        for item in headers:
            if (
                isinstance(item, (list, tuple))
                and len(item) == 2
                and isinstance(item[0], str)
                and (
                    item[0].lower() in _SENSITIVE_HEADER_NAMES
                    or _is_sensitive_key(item[0])
                )
            ):
                out_list.append([item[0], _REDACTED])
            else:
                out_list.append(item)
        return out_list
    return headers


def _scrub_request(request: dict[str, Any]) -> dict[str, Any]:
    req = dict(request)
    # Cookies целиком — там JWT access_token.
    if "cookies" in req and req["cookies"] is not None:
        req["cookies"] = _REDACTED
    if "headers" in req and req["headers"] is not None:
        req["headers"] = _scrub_headers(req["headers"])
    # Тело запроса (data) — может содержать password/token/anthropic поля.
    if "data" in req and isinstance(req["data"], dict):
        req["data"] = _scrub_mapping(req["data"])
    # query_string иногда несёт ?token=... — режем целиком, безопаснее.
    if "query_string" in req and req["query_string"]:
        qs = req["query_string"]
        if isinstance(qs, str) and any(
            sub in qs.lower() for sub in _SENSITIVE_KEY_SUBSTRINGS
        ):
            req["query_string"] = _REDACTED
    return req


def scrub_sentry_event(event: Any, hint: Any = None) -> Any:
    """PURE: вычистить секреты/PII из Sentry-события перед отправкой.

    Возвращает то же событие (модифицированную копию верхнего уровня). Не бросает —
    если структура неожиданна, отдаём как есть, но никогда не падаем в before_send
    (иначе SDK проглотит исключение и потеряет событие).
    """
    if not isinstance(event, dict):
        return event
    try:
        ev = dict(event)

        # request: cookies / headers / body.
        if isinstance(ev.get("request"), dict):
            ev["request"] = _scrub_request(ev["request"])

        # extra / contexts — произвольные словари с потенциальными секретами.
        if isinstance(ev.get("extra"), dict):
            ev["extra"] = _scrub_mapping(ev["extra"])
        if isinstance(ev.get("contexts"), dict):
            ev["contexts"] = _scrub_mapping(ev["contexts"])

        # user: оставляем только числовой id (non-PII), вычищаем email/ip/имя.
        user = ev.get("user")
        if isinstance(user, dict):
            uid = user.get("id")
            ev["user"] = {"id": uid} if uid is not None else {}

        # stacktrace locals (vars) в каждом фрейме могут содержать пароли.
        if isinstance(ev.get("exception"), dict):
            values = ev["exception"].get("values")
            if isinstance(values, list):
                for val in values:
                    if isinstance(val, dict):
                        st = val.get("stacktrace")
                        if isinstance(st, dict):
                            frames = st.get("frames")
                            if isinstance(frames, list):
                                for frame in frames:
                                    if isinstance(frame, dict) and isinstance(
                                        frame.get("vars"), dict
                                    ):
                                        frame["vars"] = _scrub_mapping(frame["vars"])
        return ev
    except Exception:  # noqa: BLE001 — скраб не должен ронять отправку события
        return event


def scrub_sentry_breadcrumb(crumb: Any, hint: Any = None) -> Any:
    """PURE: вычистить секреты из data хлебной крошки."""
    if not isinstance(crumb, dict):
        return crumb
    try:
        c = dict(crumb)
        if isinstance(c.get("data"), dict):
            c["data"] = _scrub_mapping(c["data"])
        return c
    except Exception:  # noqa: BLE001
        return crumb


def init_sentry(settings: Any) -> bool:
    """Инициализировать Sentry, если SENTRY_DSN задан. Возвращает True если включён.

    No-op при пустом DSN: логируем одну info-строку и выходим. capture_exception
    в местах вызова безопасен даже без init (SDK сам делает no-op).
    """
    dsn = (getattr(settings, "sentry_dsn", "") or "").strip()
    if not dsn:
        logger.info("Sentry disabled (no DSN)")
        return False

    import sentry_sdk
    from sentry_sdk.integrations.fastapi import FastApiIntegration
    from sentry_sdk.integrations.logging import LoggingIntegration
    from sentry_sdk.integrations.starlette import StarletteIntegration

    # event_level=ERROR → каждый logger.error/logger.exception становится Sentry-
    # событием. Это автоматически покрывает cron-сканеры и executor'ы, которые уже
    # пишут logger.exception(...). level=INFO — крошки из логов для контекста.
    logging_integration = LoggingIntegration(
        level=logging.INFO,
        event_level=logging.ERROR,
    )

    release = (getattr(settings, "sentry_release", "") or "").strip() or None

    sentry_sdk.init(
        dsn=dsn,
        environment=getattr(settings, "sentry_environment", "production"),
        release=release,
        traces_sample_rate=getattr(settings, "sentry_traces_sample_rate", 0.0),
        send_default_pii=False,
        integrations=[
            FastApiIntegration(),
            StarletteIntegration(),
            logging_integration,
        ],
        before_send=scrub_sentry_event,
        before_breadcrumb=scrub_sentry_breadcrumb,
    )
    logger.info(
        "Sentry enabled (environment=%s, release=%s, traces_sample_rate=%.3f)",
        getattr(settings, "sentry_environment", "production"),
        release or "-",
        getattr(settings, "sentry_traces_sample_rate", 0.0),
    )
    return True
