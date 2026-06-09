"""C3 — sequence if_else: статус шага выводится из вложенных результатов.

Раньше _execute_if_else_step ВСЕГДА возвращал status='success', даже когда
вложенный sub_step падал — сбой прятался внутри sub_results, run помечался
running/completed, ошибка была невидима и неретраибельна.

derive_if_else_status — pure-контракт: failed в любом sub_step → failed.
"""
from __future__ import annotations

from app.services.sequence_executor import derive_if_else_status


def test_all_success_is_success():
    subs = [{"status": "success"}, {"status": "success"}]
    assert derive_if_else_status(subs) == "success"


def test_one_failed_propagates_failed():
    subs = [{"status": "success"}, {"status": "failed", "error": "TG 500"}]
    assert derive_if_else_status(subs) == "failed"


def test_skipped_only_is_success():
    """skipped — не ошибка (ветка отработала без side-effect'а)."""
    subs = [{"status": "skipped", "reason": "нет получателя"}]
    assert derive_if_else_status(subs) == "success"


def test_mixed_success_skipped_is_success():
    subs = [{"status": "success"}, {"status": "skipped"}]
    assert derive_if_else_status(subs) == "success"


def test_failed_wins_over_skipped_and_success():
    subs = [
        {"status": "skipped"},
        {"status": "success"},
        {"status": "failed"},
    ]
    assert derive_if_else_status(subs) == "failed"


def test_empty_branch_is_success():
    """Пустая ветка (true_steps=[]) → no-op success."""
    assert derive_if_else_status([]) == "success"


def test_non_dict_entries_ignored():
    """Защита от мусора в sub_results — нестандартные элементы игнорируются."""
    subs = [{"status": "success"}, "junk", None, {"status": "failed"}]
    assert derive_if_else_status(subs) == "failed"
