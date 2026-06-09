"""Эпик 23 — action_kind='set_tags' pure-function tests.

Покрываем: whitelist константы (SET_TAGS_MODES, SET_TAGS_TARGETS), pure-функцию
apply_tag_mode (add/replace/remove), включение action в AUTOMATION_ACTIONS.
БД-фикстур нет — _action_set_tags тестируется через apply_tag_mode и через
проверку списка action_kind в whitelist.
"""
from __future__ import annotations

from app.services.automation_executor import (
    AUTOMATION_ACTIONS,
    SET_TAGS_MODES,
    SET_TAGS_TARGETS,
    apply_tag_mode,
)


# ============ Whitelist ============


def test_set_tags_action_in_whitelist():
    """Эпик 23: set_tags регистрируется в AUTOMATION_ACTIONS."""
    assert "set_tags" in AUTOMATION_ACTIONS


def test_set_tags_modes_whitelist():
    """3 режима: add / replace / remove."""
    assert set(SET_TAGS_MODES) == {"add", "replace", "remove"}
    # Без дублей
    assert len(set(SET_TAGS_MODES)) == len(SET_TAGS_MODES)


def test_set_tags_targets_whitelist():
    """Сейчас только lead поддерживает tags. Counterparty/Deal в roadmap."""
    assert "lead" in SET_TAGS_TARGETS
    # Deal/Counterparty НЕ поддерживаются (у них нет tags-колонки)
    assert "deal" not in SET_TAGS_TARGETS
    assert "counterparty" not in SET_TAGS_TARGETS
    assert "subscription" not in SET_TAGS_TARGETS


# ============ apply_tag_mode pure ============


def test_apply_tag_mode_add_to_empty():
    """add к пустому списку → копия delta_tags."""
    result = apply_tag_mode([], ["vip", "hot"], "add")
    assert result == ["vip", "hot"]


def test_apply_tag_mode_add_dedup():
    """add не создаёт дубликатов (если тег уже есть — не добавляется)."""
    result = apply_tag_mode(["vip", "hot"], ["hot", "trial"], "add")
    assert result == ["vip", "hot", "trial"]
    # Порядок: существующие сначала, потом новые-уникальные
    assert result.index("vip") < result.index("trial")


def test_apply_tag_mode_replace():
    """replace полностью игнорирует current."""
    result = apply_tag_mode(["vip", "hot", "trial"], ["enterprise"], "replace")
    assert result == ["enterprise"]


def test_apply_tag_mode_replace_dedup():
    """replace убирает дубликаты в delta (input может быть грязным)."""
    result = apply_tag_mode(["x"], ["a", "b", "a"], "replace")
    assert result == ["a", "b"]


def test_apply_tag_mode_remove():
    """remove убирает указанные теги."""
    result = apply_tag_mode(["vip", "hot", "trial"], ["hot"], "remove")
    assert result == ["vip", "trial"]


def test_apply_tag_mode_remove_noop_missing():
    """remove тега которого нет → current без изменений."""
    result = apply_tag_mode(["vip"], ["nonexistent"], "remove")
    assert result == ["vip"]


def test_apply_tag_mode_remove_all():
    """remove всех текущих тегов → пустой список."""
    result = apply_tag_mode(["a", "b"], ["a", "b"], "remove")
    assert result == []


def test_apply_tag_mode_none_current():
    """None как current — нормализуется в []. Add к None → копия delta."""
    result = apply_tag_mode(None, ["vip"], "add")
    assert result == ["vip"]
    result_remove = apply_tag_mode(None, ["x"], "remove")
    assert result_remove == []


def test_apply_tag_mode_unknown_mode_safe_default():
    """Неизвестный mode → current сохраняется (safe default, не падает)."""
    result = apply_tag_mode(["vip"], ["hot"], "invalid_mode")
    assert result == ["vip"]


def test_apply_tag_mode_empty_delta_add_is_noop():
    """add пустого списка → current без изменений."""
    result = apply_tag_mode(["vip"], [], "add")
    assert result == ["vip"]


def test_apply_tag_mode_preserves_current_order():
    """Add сохраняет порядок текущих тегов."""
    result = apply_tag_mode(["c", "a", "b"], ["d"], "add")
    assert result == ["c", "a", "b", "d"]
