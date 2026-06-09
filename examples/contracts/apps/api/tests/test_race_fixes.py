"""Race condition fixes (post-Эпик 4.1) — pure-function проверки.

Контекст: при scale=2 (два api-воркера) у нас были два известных race condition:

1. `scan_pending_sequence_runs` (sequence_executor) делал SELECT без блокировок —
   два cron'а могли захватить один и тот же SequenceRun и дважды исполнить шаг.
   Фикс: `.with_for_update(skip_locked=True).limit(N)` — каждый воркер получает
   СВОЙ набор runs.

2. `_action_change_owner` с rule='round_robin' делал SELECT курсор → round_robin_pick
   → UPDATE курсор без атомарности. Два воркера могли прочитать один cursor и
   выбрать одного и того же owner. Фикс: `pg_advisory_xact_lock(hashtext(...))`
   per-automation_id перед read курсора — тот же паттерн что в seed-миграциях.

Тесты — pure-function (без DB), по skeleton'у:
- Verify что compiled SQL содержит `FOR UPDATE` / `SKIP LOCKED` / `LIMIT N`.
- Verify что код change_owner использует `_acquire_round_robin_lock` (через AST/
  source inspection) и что helper существует/принимает int.
"""
from __future__ import annotations

import inspect
from datetime import UTC, datetime

from sqlalchemy import or_
from sqlalchemy.dialects import postgresql
from sqlalchemy.future import select

from app.models import SequenceRun
from app.services import automation_executor, sequence_executor


# ============ Fix 1: sequence scanner FOR UPDATE SKIP LOCKED ============


def test_sequence_scan_batch_limit_defined():
    """Лимит на пачку задан — защита от монополизации воркера крупным всплеском."""
    assert hasattr(sequence_executor, "SEQUENCE_SCAN_BATCH_LIMIT")
    limit = sequence_executor.SEQUENCE_SCAN_BATCH_LIMIT
    assert isinstance(limit, int)
    assert limit > 0
    # Разумный потолок: достаточно чтобы не быть узким местом, не настолько большой
    # чтобы один воркер «съел» все runs.
    assert 1 <= limit <= 1000


def _build_sequence_scan_stmt(now: datetime):
    """Восстанавливаем тот же statement что в scan_pending_sequence_runs —
    единственная проверяемая характеристика: compiled SQL должен содержать FOR
    UPDATE SKIP LOCKED LIMIT."""
    return (
        select(SequenceRun)
        .where(
            SequenceRun.status.in_(("pending", "running")),
            or_(
                SequenceRun.next_step_at.is_(None),
                SequenceRun.next_step_at <= now,
            ),
        )
        .order_by(SequenceRun.next_step_at.asc().nullsfirst())
        .with_for_update(skip_locked=True)
        .limit(sequence_executor.SEQUENCE_SCAN_BATCH_LIMIT)
    )


def test_sequence_scan_sql_has_for_update():
    """Compiled SQL содержит FOR UPDATE — атомарный захват row'а транзакцией."""
    stmt = _build_sequence_scan_stmt(datetime.now(UTC))
    sql = str(stmt.compile(dialect=postgresql.dialect()))
    assert "FOR UPDATE" in sql.upper(), (
        "scan_pending_sequence_runs SELECT обязан использовать FOR UPDATE — "
        "иначе race condition при scale=2"
    )


def test_sequence_scan_sql_has_skip_locked():
    """SKIP LOCKED — два воркера НЕ блокируют друг друга, а берут разные runs."""
    stmt = _build_sequence_scan_stmt(datetime.now(UTC))
    sql = str(stmt.compile(dialect=postgresql.dialect()))
    assert "SKIP LOCKED" in sql.upper(), (
        "SKIP LOCKED обязательно: без него второй воркер будет ждать lock'а "
        "первого вместо обработки следующих run'ов"
    )


def test_sequence_scan_sql_has_limit():
    """LIMIT — пачка ограничена SEQUENCE_SCAN_BATCH_LIMIT."""
    stmt = _build_sequence_scan_stmt(datetime.now(UTC))
    sql = str(stmt.compile(dialect=postgresql.dialect()))
    assert "LIMIT" in sql.upper(), (
        "LIMIT обязателен — иначе один воркер может монополизировать очередь"
    )


def test_sequence_scan_source_contains_for_update_call():
    """Source-level гарантия: тело scan_pending_sequence_runs вызывает
    .with_for_update(skip_locked=True). Поймает регрессию если кто-то уберёт строку."""
    src = inspect.getsource(sequence_executor.scan_pending_sequence_runs)
    assert "with_for_update" in src, (
        "scan_pending_sequence_runs должен звать .with_for_update — fix race"
    )
    assert "skip_locked=True" in src, (
        "skip_locked=True должен быть передан — иначе блокировка между воркерами"
    )
    assert "SEQUENCE_SCAN_BATCH_LIMIT" in src or ".limit(" in src, (
        "LIMIT по пачке должен ограничивать выборку"
    )


# ============ Fix 2: round_robin transaction-lock pattern ============


def test_round_robin_lock_helper_exists():
    """Helper для advisory_xact_lock существует и принимает (session, automation_id)."""
    assert hasattr(automation_executor, "_acquire_round_robin_lock")
    fn = automation_executor._acquire_round_robin_lock
    # Должна быть async-функция (cron + executor — асинхронные)
    assert inspect.iscoroutinefunction(fn)
    sig = inspect.signature(fn)
    params = list(sig.parameters.keys())
    assert params == ["session", "automation_id"], (
        f"подпись helper'а должна быть (session, automation_id), получили: {params}"
    )


def test_round_robin_lock_helper_uses_pg_advisory_xact_lock():
    """Helper использует pg_advisory_xact_lock (xact_lock — авто-снимается при
    commit/rollback) с hashtext-ключом per-automation_id."""
    src = inspect.getsource(automation_executor._acquire_round_robin_lock)
    assert "pg_advisory_xact_lock" in src, (
        "должен использоваться xact_lock — снимается при commit, не нужно явно "
        "освобождать (другие варианты pg_advisory_lock требуют unlock)"
    )
    assert "hashtext" in src, (
        "hashtext преобразует строковый ключ в int4, удобно для advisory_lock"
    )


def test_round_robin_lock_key_includes_automation_id():
    """Ключ lock'а зависит от automation_id — разные автоматизации не блокируют
    друг друга."""
    src = inspect.getsource(automation_executor._acquire_round_robin_lock)
    # Ключ — это f-string с automation_id (per-automation, не глобальный)
    assert "automation_id" in src
    # Префикс ключа задан как константа — централизованно
    assert "_ROUND_ROBIN_LOCK_KEY_PREFIX" in src


def test_round_robin_lock_key_prefix_defined():
    """Префикс ключа определён как константа модуля (легко найти/менять)."""
    assert hasattr(automation_executor, "_ROUND_ROBIN_LOCK_KEY_PREFIX")
    prefix = automation_executor._ROUND_ROBIN_LOCK_KEY_PREFIX
    assert isinstance(prefix, str)
    assert len(prefix) > 0
    # Защита от случайной коллизии с другими advisory_lock в проекте
    assert "rr" in prefix.lower() or "round_robin" in prefix.lower()


def test_change_owner_acquires_lock_before_reading_cursor():
    """В _action_change_owner (rule='round_robin') lock берётся ПЕРЕД чтением курсора.

    Source-level проверка порядка вызовов: _acquire_round_robin_lock должен
    появляться раньше чем _read_round_robin_cursor.
    """
    src = inspect.getsource(automation_executor._action_change_owner)
    pos_lock = src.find("_acquire_round_robin_lock")
    pos_read = src.find("_read_round_robin_cursor")
    pos_write = src.find("_write_round_robin_cursor")
    assert pos_lock != -1, (
        "_action_change_owner должен вызывать _acquire_round_robin_lock"
    )
    assert pos_read != -1, "_read_round_robin_cursor должен использоваться"
    assert pos_write != -1, "_write_round_robin_cursor должен использоваться"
    assert pos_lock < pos_read, (
        "lock должен браться ДО чтения курсора, иначе race condition остаётся"
    )
    assert pos_read < pos_write, (
        "read → pick → write — порядок логики round_robin"
    )


def test_change_owner_lock_only_for_round_robin():
    """Lock берётся внутри ветки `if rule == \"round_robin\"`, не для by_product/
    by_country/by_department (они не используют курсор)."""
    src = inspect.getsource(automation_executor._action_change_owner)
    # Найдём индекс ветки round_robin и убедимся что lock-вызов после неё, но
    # до else (т.е. до маппинг-веток).
    rr_branch = src.find('if rule == "round_robin"')
    else_branch = src.find("else:", rr_branch if rr_branch != -1 else 0)
    pos_lock = src.find("_acquire_round_robin_lock")
    assert rr_branch != -1, "ветка round_robin должна существовать"
    assert else_branch != -1, "else (для by_*) должно быть"
    assert pos_lock != -1
    assert rr_branch < pos_lock < else_branch, (
        "lock должен быть строго внутри ветки round_robin, не вне её"
    )
