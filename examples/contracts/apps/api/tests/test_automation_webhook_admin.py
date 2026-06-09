"""C3 WARN — action_kind='webhook' требует Admin (не просто LawyerOrAdmin).

webhook — admin-grade capability (исходящий POST на произвольный URL). SSRF-guard
(P0) блокирует приватные таргеты, но сама конфигурация исходящего HTTP должна
быть под админом. Прочие действия (tg_notify/create_task/...) остаются доступны
lawyer'у.
"""
from __future__ import annotations

import pytest
from fastapi import HTTPException

from app.models import UserRole
from app.routers.automations import _require_admin_for_webhook_action


class _User:
    def __init__(self, role):
        self.role = role


def test_lawyer_blocked_for_webhook():
    with pytest.raises(HTTPException) as ei:
        _require_admin_for_webhook_action("webhook", _User(UserRole.lawyer))
    assert ei.value.status_code == 403


def test_director_blocked_for_webhook():
    with pytest.raises(HTTPException) as ei:
        _require_admin_for_webhook_action("webhook", _User(UserRole.director))
    assert ei.value.status_code == 403


def test_admin_allowed_for_webhook():
    # не бросает
    _require_admin_for_webhook_action("webhook", _User(UserRole.admin))


def test_non_webhook_action_allows_lawyer():
    """Обычные действия остаются доступны lawyer'у — не блокируем."""
    for kind in ("tg_notify", "create_task", "set_field", "change_owner"):
        _require_admin_for_webhook_action(kind, _User(UserRole.lawyer))


def test_none_action_kind_is_noop():
    _require_admin_for_webhook_action(None, _User(UserRole.lawyer))
