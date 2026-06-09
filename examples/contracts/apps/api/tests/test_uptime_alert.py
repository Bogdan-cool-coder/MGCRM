"""Тесты UptimeRobot → Telegram alert bridge (pure-function).

Покрываем:
- verify_secret: match / mismatch / unset → fail-CLOSED / None.
- format_uptime_message: DOWN vs UP vs test, HTML-escape, truncation.
"""
from __future__ import annotations

from app.services.uptime_alert import (
    _MAX_MESSAGE_LEN,
    format_uptime_message,
    verify_secret,
)


# ============ verify_secret ============

def test_verify_secret_match():
    assert verify_secret("topsecret", "topsecret") is True


def test_verify_secret_mismatch():
    assert verify_secret("wrong", "topsecret") is False


def test_verify_secret_unset_fails_closed():
    # Секрет не сконфигурен → отклоняем даже если provided пуст.
    assert verify_secret("", "") is False
    assert verify_secret("anything", "") is False
    assert verify_secret("anything", "   ") is False


def test_verify_secret_none_provided():
    assert verify_secret(None, "topsecret") is False


def test_verify_secret_strips_whitespace():
    # query-param может прийти с хвостовым пробелом — strip обе стороны.
    assert verify_secret(" topsecret ", "topsecret") is True


# ============ format_uptime_message — DOWN ============

def test_format_down_basic():
    msg = format_uptime_message(
        alert_type="1",
        monitor_name="MG CRM prod",
        monitor_url="https://contracts.macroglobal.tech/api/health",
        alert_details="HTTP 503",
    )
    assert "MG CRM DOWN" in msg
    assert "\U0001f534" in msg  # 🔴
    assert "MG CRM prod" in msg
    assert "contracts.macroglobal.tech" in msg
    assert "HTTP 503" in msg
    assert "<b>" in msg  # HTML parse_mode


def test_format_down_minimal():
    msg = format_uptime_message(
        alert_type="1",
        monitor_name=None,
        monitor_url=None,
        alert_details=None,
    )
    assert "MG CRM DOWN" in msg
    # Дефолтное имя монитора, без падения на None.
    assert "MG CRM" in msg


# ============ format_uptime_message — UP ============

def test_format_up_with_duration():
    msg = format_uptime_message(
        alert_type="2",
        monitor_name="MG CRM prod",
        monitor_url="https://contracts.macroglobal.tech/api/health",
        alert_details=None,
        alert_duration="5 minutes",
    )
    assert "MG CRM UP" in msg
    assert "✅" in msg
    assert "восстановлен" in msg
    assert "5 minutes" in msg


def test_format_up_no_duration_uses_details():
    msg = format_uptime_message(
        alert_type="2",
        monitor_name="MG CRM",
        monitor_url=None,
        alert_details="back online",
        alert_duration=None,
    )
    assert "MG CRM UP" in msg
    assert "back online" in msg


# ============ format_uptime_message — test / unknown ============

def test_format_test_alert_type():
    msg = format_uptime_message(
        alert_type="0",
        monitor_name="MG CRM",
        monitor_url=None,
        alert_details=None,
    )
    # Не DOWN и не UP — нейтральное тестовое сообщение.
    assert "DOWN" not in msg
    assert "UP" not in msg or "UP</b>" not in msg
    assert "тест" in msg


def test_format_unknown_alert_type():
    msg = format_uptime_message(
        alert_type="99",
        monitor_name="MG CRM",
        monitor_url=None,
        alert_details=None,
    )
    assert "DOWN" not in msg


# ============ HTML escaping ============

def test_format_html_escaped():
    # Имя монитора / детали с HTML-метасимволами не должны ломать parse_mode.
    msg = format_uptime_message(
        alert_type="1",
        monitor_name="<script>alert(1)</script>",
        monitor_url="https://x.test/?a=1&b=2",
        alert_details="<b>injected</b> & stuff",
    )
    assert "<script>" not in msg
    assert "&lt;script&gt;" in msg
    assert "&amp;" in msg
    # Наши собственные <b> теги обёртки заголовка остаются.
    assert "<b>MG CRM DOWN</b>" in msg


# ============ truncation ============

def test_format_truncation():
    long_details = "x" * 10000
    msg = format_uptime_message(
        alert_type="1",
        monitor_name="MG CRM",
        monitor_url=None,
        alert_details=long_details,
    )
    assert len(msg) <= _MAX_MESSAGE_LEN
