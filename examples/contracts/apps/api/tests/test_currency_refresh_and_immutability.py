"""TASK 36 — auto-fetch error contract + historical immutability guard (без DB fixture).

Покрыто:
  1. FetchResult / UpdateResult / reason_message — авто-обновление курсов возвращает
     ЯВНУЮ машинную причину (no_api_key / api_error / empty_response / ok) вместо
     тихого нуля. RU-сообщение для UI.
  2. fetch_rates_from_api без ключа → ok=False, reason='no_api_key' (graceful, без сети).
  3. ИММУТАБЕЛЬНОСТЬ ИСТОРИИ (КРИТИЧНО): смена курса валют НЕ должна пересчитывать
     прошедшие финансовые операции. Структурно-инвариантный тест:
       • CRUD-эндпоинты currency_rates (create/patch/delete/refresh) НЕ вызывают
         пересчёт base_amounts — единственный путь, трогающий amount_in_base, это
         явная смена базовой валюты (base_currency.recompute_base_amounts).
       • recompute_base_amounts НЕ присваивает FinLedgerLine.amount (amount_func) —
         меняет ТОЛЬКО проекцию amount_in_base/base_currency. amount_func заморожен
         на проводке (инвариант №2).
"""

from __future__ import annotations

import ast
import inspect
from pathlib import Path

import pytest

from app.services import currency as currency_svc
from app.services.currency import (
    FETCH_REASON_API_ERROR,
    FETCH_REASON_EMPTY,
    FETCH_REASON_NO_KEY,
    FetchResult,
    UpdateResult,
    reason_message,
)
from app.services.finance import base_currency as base_currency_svc

# ───────────────────────────── 1. error contract (pure) ─────────────────────────────


def test_reason_message_known_reasons_have_ru_text():
    for reason in (FETCH_REASON_NO_KEY, FETCH_REASON_API_ERROR, FETCH_REASON_EMPTY):
        msg = reason_message(reason)
        assert isinstance(msg, str) and msg
        # RU-текст (кириллица присутствует)
        assert any("а" <= ch.lower() <= "я" for ch in msg)


def test_reason_message_no_key_mentions_key():
    assert "EXCHANGE_RATE_API_KEY" in reason_message(FETCH_REASON_NO_KEY)


def test_reason_message_unknown_has_fallback():
    assert reason_message("totally_unknown_reason")


def test_fetch_result_shape():
    fr = FetchResult({}, ok=False, reason=FETCH_REASON_NO_KEY)
    assert fr.ok is False
    assert fr.reason == FETCH_REASON_NO_KEY
    assert fr.rates_map == {}


def test_update_result_shape():
    ur = UpdateResult(updated=0, ok=False, reason=FETCH_REASON_API_ERROR)
    assert ur.updated == 0 and ur.ok is False
    assert ur.reason == FETCH_REASON_API_ERROR


@pytest.mark.asyncio
async def test_fetch_without_key_returns_no_key_reason(monkeypatch):
    """Нет ключа → ok=False + reason='no_api_key', БЕЗ обращения к сети (graceful)."""

    class _FakeSettings:
        exchange_rate_api_key = ""

    monkeypatch.setattr(currency_svc, "get_settings", lambda: _FakeSettings())
    res = await currency_svc.fetch_rates_from_api()
    assert isinstance(res, FetchResult)
    assert res.ok is False
    assert res.reason == FETCH_REASON_NO_KEY
    assert res.rates_map == {}


# ───────────────────────────── 2. immutability guard (структурный) ─────────────────────────────


def _currency_router_source() -> str:
    import app.routers.currency_rates as cr_router

    return Path(inspect.getfile(cr_router)).read_text(encoding="utf-8")


def test_currency_crud_does_not_recompute_base_amounts():
    """CRUD/refresh курсов НЕ вызывает recompute_base_amounts (история не пересчитывается).

    Единственная точка, где допустим вызов recompute_base_amounts из этого роутера —
    эндпоинт ЯВНОЙ смены базовой валюты (set_base_currency). Создание/правка/удаление/
    refresh обычного курса валюты НЕ трогают amount_in_base прошлых операций.
    """
    src = _currency_router_source()
    tree = ast.parse(src)

    # Функции, которым РАЗРЕШЕНО вызывать recompute (явная смена базы).
    allowed = {"set_base_currency"}

    offenders: list[str] = []
    for node in ast.walk(tree):
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
            if node.name in allowed:
                continue
            for sub in ast.walk(node):
                if isinstance(sub, ast.Call):
                    func = sub.func
                    if isinstance(func, ast.Attribute) and func.attr == "recompute_base_amounts":
                        offenders.append(node.name)
    assert not offenders, (
        f"Эти эндпоинты курсов вызывают recompute_base_amounts (пересчёт истории!): {offenders}"
    )


def test_create_update_endpoints_do_not_touch_ledger_amount_func():
    """Create/patch/delete/refresh курса НЕ присваивают FinLedgerLine.amount_func/amount_in_base."""
    src = _currency_router_source()
    tree = ast.parse(src)
    crud_names = {
        "create_currency_rate",
        "create_currency_rate_admin_alias",
        "update_currency_rate",
        "delete_currency_rate",
        "refresh_currency_rates",
    }
    forbidden_attrs = {"amount_func", "amount_in_base", "amount"}
    offenders: list[tuple[str, str]] = []
    for node in ast.walk(tree):
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)) and node.name in crud_names:
            for sub in ast.walk(node):
                # Присваивание чему-то .amount_func/.amount_in_base
                if isinstance(sub, ast.Assign):
                    for tgt in sub.targets:
                        if isinstance(tgt, ast.Attribute) and tgt.attr in forbidden_attrs:
                            offenders.append((node.name, tgt.attr))
    assert not offenders, f"CRUD курсов мутирует ledger-суммы: {offenders}"


def test_recompute_base_amounts_does_not_assign_amount_func():
    """recompute_base_amounts меняет ТОЛЬКО amount_in_base/base_currency/fx_missing —
    НЕ amount (amount_func). Гарантия инварианта №2 (база — проекция, func заморожен)."""
    src = inspect.getsource(base_currency_svc.recompute_base_amounts)
    tree = ast.parse(src)
    assigned_attrs: set[str] = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.Assign):
            for tgt in node.targets:
                if isinstance(tgt, ast.Attribute):
                    assigned_attrs.add(tgt.attr)
    # amount (функциональная сумма) НЕ должна присваиваться на ledger-строке.
    assert "amount" not in assigned_attrs
    assert "amount_func" not in assigned_attrs
    # А вот проекция — присваивается (sanity: тест реально смотрит на тело функции).
    assert "amount_in_base" in assigned_attrs
