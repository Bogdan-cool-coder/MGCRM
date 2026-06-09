"""Эпик 21.2 — Email channel stub для notifications.

Использует aiosmtplib (тот же стек, что и _action_email в automation_executor).
Если SMTP_HOST не задан в Settings — НЕ падает, а возвращает False+log warning.
Это держит pipeline нотификаций живым в dev/staging без real SMTP, НО честно
сообщает, что письмо не доставлено (caller помечает канал как
`smtp_not_configured`, а не `delivered`).

Архитектура:
- `send_email(to, subject, body, html_body=None)` — public API.
- SMTP не сконфигурирован / aiosmtplib не установлен → log warning, возвращает
  False (доставки не было — не выдаём её за успешную).
- При successful send → True. При SMTP error → False
  (caller сам решает, что делать). Бросает только при unexpected error.

C7-fix: раньше unconfigured-путь возвращал True и фейкал доставку — диспетчер
помечал канал `delivered`, хотя письмо никуда не ушло. Теперь возвращаем False,
что соответствует уже корректной обработке в notification_dispatcher.

NB: Полноценную email queue / retry / unsubscribe pipeline отложили —
в MVP это log-only fallback + best-effort send. Когда выйдет Эпик с
email-marketing — заменим на full queue с aiosmtplib pool.
"""
from __future__ import annotations

import logging
from typing import Any

from app.config import get_settings

logger = logging.getLogger(__name__)


async def send_email(
    to: str,
    subject: str,
    body: str,
    html_body: str | None = None,
) -> bool:
    """Послать email через SMTP (если сконфигурирован).

    Поведение:
    - SMTP_HOST не задан → log warning «email skipped», возвращает False
      (доставки не было — pipeline продолжается, но канал НЕ считается
      delivered; в dev/staging без SMTP это ожидаемо).
    - aiosmtplib не установлен → log warning, возвращает False
      (тот же honest-fallback — письмо не ушло).
    - Real SMTP error (timeout, auth, etc) → log error, возвращает False.
    - Other unexpected error → re-raise.

    `to` — один получатель. Multi-recipient — TODO когда понадобится.
    `body` — plain text. `html_body` — опциональный HTML alternative.
    """
    settings = get_settings()

    if not settings.smtp_host:
        logger.info(
            "send_email: SMTP_HOST not configured, not delivered (to=%s, subject=%r)",
            to, subject[:80],
        )
        return False  # доставки не было — НЕ выдаём за успех

    if not to:
        logger.warning("send_email: empty recipient, skipped")
        return False

    from_email = (
        settings.smtp_from
        or settings.smtp_user
        or "noreply@example.com"
    )

    try:
        import aiosmtplib  # type: ignore[import-not-found]
        from email.message import EmailMessage
    except ImportError:
        logger.warning(
            "send_email: aiosmtplib not installed, not delivered (to=%s)",
            to,
        )
        return False  # письмо не ушло — НЕ выдаём за успех

    msg = EmailMessage()
    msg["From"] = from_email
    msg["To"] = to
    msg["Subject"] = (subject or "(без темы)")[:255]
    msg.set_content(body or "")
    if html_body:
        # Multipart alternative: plain (set_content выше) + html.
        msg.add_alternative(html_body, subtype="html")

    try:
        await aiosmtplib.send(
            msg,
            hostname=settings.smtp_host,
            port=settings.smtp_port,
            username=settings.smtp_user,
            password=settings.smtp_pass,
            start_tls=settings.smtp_use_tls,
            timeout=30,
        )
        logger.info(
            "send_email: sent to=%s subject=%r",
            to, subject[:80],
        )
        return True
    except Exception as e:  # noqa: BLE001
        # SMTP errors (auth, timeout, connection) — log + return False.
        # НЕ пробрасываем — caller (dispatch) обрабатывает в общем catch
        # и продолжает с другими каналами.
        logger.warning(
            "send_email: failed to=%s subject=%r err=%s",
            to, subject[:80], e,
        )
        return False


# ============ Helper: HTML wrapper для default-шаблонов ============


def wrap_html_with_brand_layout(body_html: str, title: str = "") -> str:
    """Оборачивает body в брендированный HTML-layout (MACRO Global colors).

    Используется default-шаблонами email — даёт минимальный consistent
    visual style. Для кастомных шаблонов админ может прислать свой HTML.

    Цвета: primary #172747 (header), primary-light #2B4987 (accents),
    body на #FFFFFF, footer на #F5F7FA.
    """
    safe_title = title or "MACRO CRM"
    return f"""<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{safe_title}</title>
</head>
<body style="margin:0;padding:0;background:#F5F7FA;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#172747">
<div style="max-width:600px;margin:0 auto;background:#FFFFFF">
<div style="background:#172747;color:#FFFFFF;padding:24px 32px;font-weight:600;font-size:18px">MACRO CRM</div>
<div style="padding:32px;font-size:15px;line-height:1.5">
{body_html}
</div>
<div style="background:#F5F7FA;padding:16px 32px;font-size:12px;color:#6B7A99;text-align:center">
Это автоматическое уведомление от MACRO CRM. Если письмо не для вас — игнорируйте его.
</div>
</div>
</body>
</html>"""
