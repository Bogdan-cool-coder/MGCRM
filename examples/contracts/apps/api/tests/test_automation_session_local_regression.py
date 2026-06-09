"""Регрессия live-бага: `SessionLocal` не был импортирован в automation_executor.

История: `_run_deferred_network_action` (фоновый исполнитель отложенных сетевых
действий tg_notify/webhook/email) делал `async with SessionLocal() as session:`,
но имя `SessionLocal` не было ни импортировано, ни определено в модуле. Под
широким `except Exception` это давало молчаливый `NameError` → ВСЕ inline-
сетевые автоматизации не доставлялись, а AutomationRun застревал в 'queued'
(держит idem-слот → cron тоже не переретраивал).

Эти тесты чисто статические (без БД, без event-loop):
  1. `SessionLocal` резолвится в namespace модуля и это именно async_sessionmaker
     из app.db (а не случайная заглушка).
  2. Recovery-sweep `recover_stuck_automation_runs` импортируется, это корутина,
     и её сигнатура корректна (stale_minutes: int = 15).
"""
from __future__ import annotations

import inspect

import app.services.automation_executor as executor_mod


# ============ Регрессия: SessionLocal импортируем в модуле ============


def test_session_local_resolves_in_module_namespace():
    """`SessionLocal` доступен в namespace automation_executor (имя резолвится)."""
    # Прямой импорт по имени — упадёт ImportError, если регрессия вернётся.
    from app.services.automation_executor import SessionLocal  # noqa: F401

    assert hasattr(executor_mod, "SessionLocal")
    assert executor_mod.SessionLocal is not None


def test_session_local_is_the_app_db_sessionmaker():
    """Это ровно тот же объект, что app.db.SessionLocal (а не подмена)."""
    from app.db import SessionLocal as db_session_local

    assert executor_mod.SessionLocal is db_session_local


def test_session_local_is_callable_factory():
    """SessionLocal — фабрика сессий (вызывается без аргументов в `async with`)."""
    # async_sessionmaker — callable; реальный вызов требует движок/loop, поэтому
    # проверяем только контракт «это вызываемая фабрика».
    assert callable(executor_mod.SessionLocal)


# ============ Recovery-sweep: импорт + сигнатура ============


def test_recover_stuck_automation_runs_importable():
    """Startup-sweep импортируется из модуля (вызов в main.py lifespan не падёт)."""
    from app.services.automation_executor import (  # noqa: F401
        recover_stuck_automation_runs,
    )

    assert hasattr(executor_mod, "recover_stuck_automation_runs")


def test_recover_stuck_automation_runs_is_coroutine_function():
    """Sweep — async def (await-абельна в lifespan)."""
    assert inspect.iscoroutinefunction(
        executor_mod.recover_stuck_automation_runs
    )


def test_recover_stuck_automation_runs_signature():
    """Сигнатура: единственный параметр stale_minutes: int = 15."""
    sig = inspect.signature(executor_mod.recover_stuck_automation_runs)
    params = sig.parameters
    assert list(params) == ["stale_minutes"]
    assert params["stale_minutes"].default == 15
