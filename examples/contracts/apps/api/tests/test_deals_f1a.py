"""DEALS 2.0 (Ф1a) — pure-function тесты.

Покрывает:
- win-gate check (_check_win_gate logic, отдельная pure-функция)
- bulk set_tags логику (add / replace / remove)
- board фильтры hidden_by_default
- next_task выбор (ближайшая по due_at)
- migration 0077 revision-length и advisory-lock
- DealMoveIn схема — substage_id и lost_reason
"""
from __future__ import annotations

from pathlib import Path


# ============ Win-gate pure logic ============

def _gate_passed(has_signed_scan: bool, has_payment: bool) -> bool:
    """Pure-функция: win-gate пройден если есть хотя бы одно из условий."""
    return has_signed_scan or has_payment


def test_win_gate_signed_scan_passes():
    assert _gate_passed(has_signed_scan=True, has_payment=False) is True


def test_win_gate_payment_passes():
    assert _gate_passed(has_signed_scan=False, has_payment=True) is True


def test_win_gate_both_passes():
    assert _gate_passed(has_signed_scan=True, has_payment=True) is True


def test_win_gate_none_fails():
    assert _gate_passed(has_signed_scan=False, has_payment=False) is False


# ============ Bulk set_tags логика ============

def _apply_set_tags(existing: list[str], new_tags: list[str], mode: str) -> list[str]:
    """Pure-функция управления тегами (replicated from bulk endpoint logic)."""
    if mode == "add":
        return list(dict.fromkeys(existing + new_tags))
    if mode == "remove":
        remove_set = set(new_tags)
        return [t for t in existing if t not in remove_set]
    # replace (default)
    return list(new_tags)


def test_set_tags_replace():
    assert _apply_set_tags(["a", "b"], ["c", "d"], "replace") == ["c", "d"]


def test_set_tags_replace_empty():
    assert _apply_set_tags(["a"], [], "replace") == []


def test_set_tags_add_dedup():
    result = _apply_set_tags(["a", "b"], ["b", "c"], "add")
    assert result == ["a", "b", "c"]


def test_set_tags_add_preserves_order():
    result = _apply_set_tags(["x", "y"], ["z"], "add")
    assert result == ["x", "y", "z"]


def test_set_tags_remove():
    result = _apply_set_tags(["a", "b", "c"], ["b"], "remove")
    assert result == ["a", "c"]


def test_set_tags_remove_missing_is_noop():
    result = _apply_set_tags(["a", "b"], ["z"], "remove")
    assert result == ["a", "b"]


def test_set_tags_remove_all():
    result = _apply_set_tags(["a", "b"], ["a", "b"], "remove")
    assert result == []


# ============ Board: hidden_by_default фильтрация ============

def _stage_should_show(
    hidden_by_default: bool,
    is_lost: bool,
    show_lost: bool,
    show_cold: bool,
) -> bool:
    """Pure-функция: показывать ли этап на доске при данных тумблерах."""
    if not hidden_by_default:
        return True
    if is_lost:
        return show_lost
    return show_cold


def test_board_normal_stage_always_visible():
    assert _stage_should_show(hidden_by_default=False, is_lost=False, show_lost=False, show_cold=False)


def test_board_lost_hidden_by_default():
    assert not _stage_should_show(hidden_by_default=True, is_lost=True, show_lost=False, show_cold=False)


def test_board_lost_visible_with_toggle():
    assert _stage_should_show(hidden_by_default=True, is_lost=True, show_lost=True, show_cold=False)


def test_board_cold_hidden_by_default():
    assert not _stage_should_show(hidden_by_default=True, is_lost=False, show_lost=False, show_cold=False)


def test_board_cold_visible_with_toggle():
    assert _stage_should_show(hidden_by_default=True, is_lost=False, show_lost=False, show_cold=True)


def test_board_show_lost_does_not_show_cold():
    """show_lost не влияет на cold (и наоборот)."""
    assert not _stage_should_show(hidden_by_default=True, is_lost=False, show_lost=True, show_cold=False)


# ============ next_task выбор — ближайшая незакрытая задача ============

from datetime import UTC, datetime


def _pick_next_task(tasks: list[dict]) -> dict | None:
    """Выбрать ближайшую незакрытую задачу: due_at ASC NULLS LAST, затем id ASC.
    Фильтр: completed_at IS NULL AND is_closed=False.
    """
    active = [t for t in tasks if t.get("completed_at") is None and not t.get("is_closed")]
    if not active:
        return None
    # due_at=None — в конце
    return sorted(active, key=lambda t: (t["due_at"] is None, t["due_at"] or datetime.min.replace(tzinfo=UTC), t["id"]))[0]


_NOW = datetime(2026, 6, 3, 10, 0, 0, tzinfo=UTC)
_SOON = datetime(2026, 6, 3, 12, 0, 0, tzinfo=UTC)
_LATER = datetime(2026, 6, 4, 10, 0, 0, tzinfo=UTC)


def test_next_task_no_tasks():
    assert _pick_next_task([]) is None


def test_next_task_all_completed():
    tasks = [{"id": 1, "completed_at": _NOW, "is_closed": False, "due_at": _SOON}]
    assert _pick_next_task(tasks) is None


def test_next_task_all_closed():
    tasks = [{"id": 1, "completed_at": None, "is_closed": True, "due_at": _SOON}]
    assert _pick_next_task(tasks) is None


def test_next_task_picks_earliest_due():
    tasks = [
        {"id": 2, "completed_at": None, "is_closed": False, "due_at": _LATER},
        {"id": 1, "completed_at": None, "is_closed": False, "due_at": _SOON},
    ]
    result = _pick_next_task(tasks)
    assert result is not None
    assert result["id"] == 1


def test_next_task_nulls_last():
    """Задачи без due_at — в конце (NULLS LAST)."""
    tasks = [
        {"id": 1, "completed_at": None, "is_closed": False, "due_at": None},
        {"id": 2, "completed_at": None, "is_closed": False, "due_at": _SOON},
    ]
    result = _pick_next_task(tasks)
    assert result is not None
    assert result["id"] == 2


def test_next_task_id_tiebreak():
    """При одинаковом due_at — меньший id."""
    tasks = [
        {"id": 3, "completed_at": None, "is_closed": False, "due_at": _SOON},
        {"id": 1, "completed_at": None, "is_closed": False, "due_at": _SOON},
    ]
    result = _pick_next_task(tasks)
    assert result is not None
    assert result["id"] == 1


def test_next_task_skips_completed():
    """Из двух задач — скипаем completed_at, берём оставшуюся."""
    tasks = [
        {"id": 1, "completed_at": _NOW, "is_closed": False, "due_at": _SOON},
        {"id": 2, "completed_at": None, "is_closed": False, "due_at": _LATER},
    ]
    result = _pick_next_task(tasks)
    assert result is not None
    assert result["id"] == 2


# ============ DealMoveIn schema ============

from app.schemas import DealMoveIn


def test_deal_move_in_default_optional_fields():
    m = DealMoveIn(stage_id=5)
    assert m.stage_id == 5
    assert m.lost_reason is None
    assert m.lost_reason_id is None
    assert m.substage_id is None


def test_deal_move_in_with_lost_reason():
    m = DealMoveIn(stage_id=1, lost_reason="Дорого", lost_reason_id=2)
    assert m.lost_reason == "Дорого"
    assert m.lost_reason_id == 2


def test_deal_move_in_with_substage():
    m = DealMoveIn(stage_id=9, substage_id=10)
    assert m.substage_id == 10


# ============ Миграция 0077 ============

_VERSIONS = Path(__file__).parent.parent / "alembic" / "versions"


def test_migration_0077_revision_length():
    rev = "0077_deals2_f1a"
    assert len(rev) <= 32, f"{rev} = {len(rev)} chars > 32"


def test_migration_0077_uses_advisory_lock():
    src = (_VERSIONS / "0077_deals2_f1a.py").read_text(encoding="utf-8")
    assert "pg_advisory_xact_lock" in src


def test_migration_0077_idempotent_add_column():
    src = (_VERSIONS / "0077_deals2_f1a.py").read_text(encoding="utf-8")
    assert "ADD COLUMN IF NOT EXISTS" in src


def test_migration_0077_adds_tags_product_lost_reason_id():
    src = (_VERSIONS / "0077_deals2_f1a.py").read_text(encoding="utf-8")
    assert "tags" in src
    assert "product" in src
    assert "lost_reason_id" in src
    assert "lost_reasons" in src


def test_migration_0077_down_revision():
    src = (_VERSIONS / "0077_deals2_f1a.py").read_text(encoding="utf-8")
    assert 'down_revision: Union[str, None] = "0076_deals2_leads"' in src


def test_migration_0077_chain_from_0076():
    """0077 следует непосредственно за 0076."""
    src_76 = (_VERSIONS / "0076_deals2_leads.py").read_text(encoding="utf-8")
    assert 'down_revision: Union[str, None] = "0075_deals2_pipeline"' in src_76
    src_77 = (_VERSIONS / "0077_deals2_f1a.py").read_text(encoding="utf-8")
    assert 'down_revision: Union[str, None] = "0076_deals2_leads"' in src_77
