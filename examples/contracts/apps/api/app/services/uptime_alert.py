"""UptimeRobot → Telegram alert bridge.

UptimeRobot (внешний uptime-монитор) пингует наш `/api/health`. Когда сайт
падает (DOWN) или восстанавливается (UP), он дёргает наш webhook
`POST /api/integrations/uptime-webhook?secret=...`. Мы:
  1. Проверяем shared-secret (hmac.compare_digest, fail-CLOSED если не задан).
  2. Форматируем сообщение (DOWN/UP), HTML-escape динамики, truncate.
  3. Шлём в ТОТ ЖЕ alert-бот, что и deploy/scripts/lib-telegram-alert.sh.

Pure-функции (verify_secret / format_uptime_message) тестируются без сети.
Отправка (send_telegram_alert) изолирована и не валит endpoint при сбоях.
"""
from __future__ import annotations

import hmac
import logging
from html import escape as _html_escape

import httpx

logger = logging.getLogger(__name__)

# Telegram ограничивает сообщение 4096 символами; берём с запасом под HTML-теги.
_MAX_MESSAGE_LEN = 3900
_TELEGRAM_TIMEOUT_SECONDS = 10.0


def verify_secret(provided: str | None, expected: str) -> bool:
    """Constant-time проверка shared-secret.

    Fail-CLOSED: если expected пуст (секрет не сконфигурен) → всегда False.
    Никогда не пропускаем unauth-вызов к forwarding'у. Сравнение —
    hmac.compare_digest (защита от timing-атак).
    """
    expected = (expected or "").strip()
    if not expected:
        return False
    if not provided:
        return False
    return hmac.compare_digest(provided.strip(), expected)


def _esc(value: str | None) -> str:
    """HTML-escape динамической части (имя монитора, URL, детали)."""
    return _html_escape(value or "", quote=False)


def format_uptime_message(
    alert_type: str | None,
    monitor_name: str | None,
    monitor_url: str | None,
    alert_details: str | None,
    alert_duration: str | None = None,
) -> str:
    """Собрать HTML-сообщение для Telegram по параметрам UptimeRobot.

    alert_type: "1" = down, "2" = up (recovered), иное (например "0" = test) →
    нейтральное тестовое сообщение. Все динамические части escape'ятся.
    Результат обрезается до _MAX_MESSAGE_LEN.
    """
    at = (alert_type or "").strip()
    name = _esc(monitor_name) or "MG CRM"
    url = _esc(monitor_url)
    details = _esc(alert_details)
    duration = _esc(alert_duration)

    lines: list[str]
    if at == "1":
        lines = [f"\U0001f534 <b>MG CRM DOWN</b>", f"Монитор: {name}"]
        if url:
            lines.append(f"URL: {url}")
        if details:
            lines.append(f"Детали: {details}")
    elif at == "2":
        lines = [f"✅ <b>MG CRM UP</b> (восстановлен)", f"Монитор: {name}"]
        if url:
            lines.append(f"URL: {url}")
        if duration:
            lines.append(f"Был недоступен: {duration}")
        elif details:
            lines.append(f"Детали: {details}")
    else:
        # alertType=0 (тест UptimeRobot) или неизвестный — нейтральное сообщение.
        lines = [
            f"ℹ️ <b>MG CRM uptime-webhook</b> (тест)",
            f"Монитор: {name}",
        ]
        if url:
            lines.append(f"URL: {url}")
        if details:
            lines.append(f"Детали: {details}")

    message = "\n".join(lines)
    if len(message) > _MAX_MESSAGE_LEN:
        message = message[:_MAX_MESSAGE_LEN]
    return message


async def send_telegram_alert(
    bot_token: str,
    chat_id: str,
    message_html: str,
) -> bool:
    """Отправить HTML-сообщение в alert-бот (Telegram sendMessage).

    Если креды не заданы — логируем и возвращаем False (НЕ падаем: endpoint
    должен вернуть 200, UptimeRobot ретраит на non-2xx). Любая сетевая ошибка
    тоже не пробрасывается — best-effort.
    """
    bot_token = (bot_token or "").strip()
    chat_id = (chat_id or "").strip()
    if not bot_token or not chat_id:
        logger.warning(
            "uptime-webhook: alert-бот не сконфигурен "
            "(TELEGRAM_ALERT_BOT_TOKEN / TELEGRAM_ALERT_CHAT_ID пусты) — пропуск отправки"
        )
        return False

    url = f"https://api.telegram.org/bot{bot_token}/sendMessage"
    payload = {
        "chat_id": chat_id,
        "text": message_html,
        "parse_mode": "HTML",
        "disable_web_page_preview": True,
    }
    try:
        async with httpx.AsyncClient(timeout=_TELEGRAM_TIMEOUT_SECONDS) as client:
            resp = await client.post(url, json=payload)
        if resp.status_code != 200:
            # Не логируем тело целиком (может содержать токен в эхо) — только код.
            logger.error(
                "uptime-webhook: Telegram sendMessage вернул %s", resp.status_code
            )
            return False
        return True
    except Exception as e:  # noqa: BLE001
        logger.error("uptime-webhook: ошибка отправки в Telegram: %s", e)
        return False
