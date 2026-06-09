"""Эпик 21 — UX Upgrade: pure-function тесты для notifications builders + service.

Без сети, без БД (in-memory MagicMock для AsyncSession). Тестируем:
- build_*_notification builders: возвращают правильный shape per kind
- create_notification: bad kind → ValueError; happy → правильный объект
- safe_create_notification: None user_id / negative → возвращает None;
  exception в create → swallow и None
- _target_link / _format_money — pure helpers
"""
from __future__ import annotations

from types import SimpleNamespace
from typing import Any
from unittest.mock import AsyncMock, MagicMock

import pytest

from app.services.notifications import (
    NOTIFICATION_KINDS,
    _format_money,
    _target_link,
    build_approval_needed_notification,
    build_contract_signed_notification,
    build_course_assigned_notification,
    build_deal_won_notification,
    build_sla_breach_notification,
    build_system_notification,
    build_task_assigned_notification,
    create_notification,
    safe_create_notification,
)


# ============ NOTIFICATION_KINDS whitelist ============


def test_notification_kinds_contains_expected_kinds():
    """Все упомянутые в эпике 21 типы должны быть в whitelist."""
    expected = {
        "task_assigned", "deal_won", "approval_needed", "sla_breach",
        "course_assigned", "contract_signed", "mention", "system",
    }
    assert expected.issubset(set(NOTIFICATION_KINDS))


# ============ _target_link ============


def test_target_link_known_types_return_relative_url():
    assert _target_link("deal", 123) == "/deals/123"
    assert _target_link("lead", 1) == "/leads/1"
    assert _target_link("subscription", 99) == "/registry/99"
    assert _target_link("counterparty", 5) == "/clients/5"
    assert _target_link("company", 7) == "/companies/7"
    assert _target_link("contract", 42) == "/contracts/42"
    assert _target_link("course", 3) == "/courses/3/play"


def test_target_link_unknown_type_returns_none():
    assert _target_link("foo", 1) is None
    assert _target_link("", 1) is None


# ============ _format_money ============


def test_format_money_groups_thousands_with_nbsp():
    assert _format_money(1000) == "1 000"
    assert _format_money(1_234_567) == "1 234 567"
    assert _format_money(0) == "0"


def test_format_money_rounds_float():
    assert _format_money(1234.99) == "1 235"


def test_format_money_handles_invalid_input():
    # str → ValueError → возвращает str(amount)
    assert _format_money("abc") == "abc"  # type: ignore[arg-type]


# ============ build_task_assigned_notification ============


def test_build_task_assigned_basic():
    out = build_task_assigned_notification(
        task_id=11,
        task_title="Позвонить клиенту",
        responsible_user_id=7,
        target_type="deal",
        target_id=33,
        due_at_iso="2026-07-01T10:00:00+00:00",
    )
    assert out["user_id"] == 7
    assert out["kind"] == "task_assigned"
    assert "Позвонить клиенту" in out["title"]
    assert out["link"] == "/deals/33"
    assert out["metadata"]["task_id"] == 11
    assert out["metadata"]["target_type"] == "deal"
    assert out["metadata"]["target_id"] == 33


def test_build_task_assigned_without_target_has_no_link():
    out = build_task_assigned_notification(
        task_id=1, task_title="t", responsible_user_id=2,
    )
    assert out["link"] is None
    assert out["metadata"]["target_type"] is None


def test_build_task_assigned_truncates_long_title():
    long_title = "a" * 500
    out = build_task_assigned_notification(
        task_id=1, task_title=long_title, responsible_user_id=2,
    )
    # title и body обрезаются до 200 chars (+ префикс "Назначена задача: ")
    assert len(out["title"]) <= 256
    assert len(out["body"] or "") <= 256


# ============ build_deal_won_notification ============


def test_build_deal_won_with_amount():
    out = build_deal_won_notification(
        deal_id=10, deal_title="Сделка с ООО Ромашка",
        owner_user_id=5, amount=150_000.0, currency="RUB",
    )
    assert out["user_id"] == 5
    assert out["kind"] == "deal_won"
    # Сумма с разделителями + валюта
    assert "150 000" in out["title"]
    assert "RUB" in out["title"]
    assert out["link"] == "/deals/10"


def test_build_deal_won_without_amount():
    out = build_deal_won_notification(
        deal_id=10, deal_title="x", owner_user_id=5,
    )
    assert out["metadata"]["amount"] is None
    assert "(" not in out["title"]  # без суммы — без скобок


# ============ build_approval_needed_notification ============


def test_build_approval_needed_with_stage():
    out = build_approval_needed_notification(
        approval_id=88, contract_id=11,
        contract_title="Договор v2", approver_user_id=4,
        stage_order=2,
    )
    assert out["user_id"] == 4
    assert out["kind"] == "approval_needed"
    assert "Договор v2" in out["title"]
    # stage_order=2 → "Этап 3" (human-friendly +1)
    assert out["body"] == "Этап 3"
    assert out["link"] == "/contracts/11"
    assert out["metadata"]["approval_id"] == 88


def test_build_approval_needed_without_title():
    out = build_approval_needed_notification(
        approval_id=1, contract_id=99, contract_title=None,
        approver_user_id=4,
    )
    assert "договора #99" in out["title"]
    assert out["body"] is None


# ============ build_sla_breach_notification ============


def test_build_sla_breach_basic():
    out = build_sla_breach_notification(
        target_type="deal", target_id=5,
        target_title="Просроченная сделка",
        owner_user_id=1, breach_reason="deadline_passed",
    )
    assert out["user_id"] == 1
    assert out["kind"] == "sla_breach"
    assert out["link"] == "/deals/5"
    assert out["body"] == "deadline_passed"


# ============ build_course_assigned_notification ============


def test_build_course_assigned_with_deadline():
    out = build_course_assigned_notification(
        course_id=4, course_title="CRM Basics",
        user_id=7, deadline_at_iso="2026-08-01T00:00:00+00:00",
    )
    assert out["user_id"] == 7
    assert out["kind"] == "course_assigned"
    assert "CRM Basics" in out["title"]
    assert out["link"] == "/courses/4/play"
    assert "2026-08-01" in (out["body"] or "")


def test_build_course_assigned_without_deadline():
    out = build_course_assigned_notification(
        course_id=4, course_title="x", user_id=7,
    )
    assert "Срок" not in (out["body"] or "")


# ============ build_contract_signed_notification ============


def test_build_contract_signed_with_title():
    out = build_contract_signed_notification(
        contract_id=22, contract_title="Sublicense MACRO CRM",
        author_user_id=3,
    )
    assert out["user_id"] == 3
    assert out["kind"] == "contract_signed"
    assert "Sublicense MACRO CRM" in out["title"]
    assert out["link"] == "/contracts/22"


def test_build_contract_signed_without_title():
    out = build_contract_signed_notification(
        contract_id=22, contract_title=None, author_user_id=3,
    )
    assert "#22" in out["title"]


# ============ build_system_notification ============


def test_build_system_truncates_title():
    long_title = "X" * 500
    out = build_system_notification(user_id=1, title=long_title)
    assert len(out["title"]) <= 256
    assert out["kind"] == "system"
    assert out["metadata"] is None


# ============ create_notification ============


@pytest.mark.asyncio
async def test_create_notification_rejects_bad_kind():
    """Незнакомый kind → ValueError. Это защита от опечаток в caller'е."""
    session = MagicMock()
    session.add = MagicMock()
    session.flush = AsyncMock()
    with pytest.raises(ValueError, match="Unknown notification kind"):
        await create_notification(session, 1, "not_a_real_kind", "t")


@pytest.mark.asyncio
async def test_create_notification_happy_path():
    """create_notification должен add + flush + вернуть объект с правильными
    полями. title обрезается до 256 chars."""
    session = MagicMock()
    session.add = MagicMock()
    session.flush = AsyncMock()
    notif = await create_notification(
        session, user_id=5, kind="system",
        title="A" * 300, body="hello", link="/x", metadata={"k": "v"},
    )
    session.add.assert_called_once()
    session.flush.assert_awaited_once()
    assert notif.user_id == 5
    assert notif.kind == "system"
    assert len(notif.title) == 256
    assert notif.body == "hello"
    assert notif.link == "/x"
    assert notif.meta == {"k": "v"}


@pytest.mark.asyncio
async def test_create_notification_accepts_all_known_kinds():
    """Все NOTIFICATION_KINDS должны проходить create_notification (smoke)."""
    session = MagicMock()
    session.add = MagicMock()
    session.flush = AsyncMock()
    for kind in NOTIFICATION_KINDS:
        notif = await create_notification(session, 1, kind, "t")
        assert notif.kind == kind


# ============ safe_create_notification ============


@pytest.mark.asyncio
async def test_safe_create_notification_skips_none_user():
    """None / 0 / отрицательный user_id → skip, возвращает None."""
    session = MagicMock()
    assert await safe_create_notification(session, None, "system", "t") is None
    assert await safe_create_notification(session, 0, "system", "t") is None
    assert await safe_create_notification(session, -1, "system", "t") is None


@pytest.mark.asyncio
async def test_safe_create_notification_swallows_exception():
    """Любая ошибка в create → swallow, возвращает None. Это integration point
    safety — упавшая нотификация не валит deal won / task assign."""
    session = MagicMock()
    session.add = MagicMock(side_effect=RuntimeError("DB down"))
    result = await safe_create_notification(session, 1, "system", "t")
    assert result is None


@pytest.mark.asyncio
async def test_safe_create_notification_propagates_happy():
    """Happy path — возвращает созданный объект."""
    session = MagicMock()
    session.add = MagicMock()
    session.flush = AsyncMock()
    notif = await safe_create_notification(session, 5, "system", "t")
    assert notif is not None
    assert notif.kind == "system"


# ============ Sanity: builder + create roundtrip ============


@pytest.mark.asyncio
async def test_builder_dict_directly_usable_in_create():
    """Builders возвращают shape совместимый с create_notification(**kwargs)."""
    session = MagicMock()
    session.add = MagicMock()
    session.flush = AsyncMock()
    data = build_deal_won_notification(
        deal_id=1, deal_title="X", owner_user_id=2,
        amount=100.0, currency="USD",
    )
    notif = await create_notification(session, **data)
    assert notif.kind == "deal_won"
    assert notif.user_id == 2
