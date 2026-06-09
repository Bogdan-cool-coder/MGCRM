"""C7 — поведение send_email при разной конфигурации SMTP.

Без сети: проверяем только ветки «SMTP не сконфигурирован» / «пустой
получатель», где не доходит до реального aiosmtplib. Ключевая регрессия,
которую закрываем: unconfigured-путь раньше возвращал True (фейкал доставку) —
теперь должен возвращать False, чтобы диспетчер не помечал канал delivered.
"""
from __future__ import annotations

from types import SimpleNamespace

import pytest

from app.services import notification_email


def _fake_settings(**over):
    base = dict(
        smtp_host=None,
        smtp_port=587,
        smtp_user=None,
        smtp_pass=None,
        smtp_from=None,
        smtp_use_tls=True,
    )
    base.update(over)
    return SimpleNamespace(**base)


@pytest.mark.asyncio
async def test_send_email_unconfigured_returns_false(monkeypatch):
    """SMTP_HOST не задан → доставки не было → False (не фейкаем успех)."""
    monkeypatch.setattr(
        notification_email, "get_settings", lambda: _fake_settings(smtp_host=None)
    )
    ok = await notification_email.send_email(
        to="user@example.com", subject="Привет", body="Тело"
    )
    assert ok is False


@pytest.mark.asyncio
async def test_send_email_empty_recipient_returns_false(monkeypatch):
    """Пустой получатель → False (даже при сконфигурированном SMTP)."""
    monkeypatch.setattr(
        notification_email,
        "get_settings",
        lambda: _fake_settings(smtp_host="smtp.example.com"),
    )
    ok = await notification_email.send_email(to="", subject="S", body="B")
    assert ok is False
