"""P1 concurrency (audit S6 B1): idempotency precondition для move_deal.

Чистые unit-тесты ветки «холостого» перевода сделки. Под row-lock'ом в
move_deal перечитывается текущий stage_id; повторный перевод в тот же этап
(вторая реплика api / двойной клик) должен быть no-op — без дублирующей
DealStageHistory и перефайра автоматизаций/вебхуков.
"""
from __future__ import annotations

from app.services.deals import is_redundant_stage_move


def test_same_stage_no_substage_is_redundant():
    # Сделка уже в целевом этапе, substage не запрошен → холостой move.
    assert is_redundant_stage_move(5, 5, None) is True


def test_different_stage_is_not_redundant():
    # Реальный переход между этапами → не холостой.
    assert is_redundant_stage_move(5, 7, None) is False


def test_same_stage_with_substage_is_not_redundant():
    # Этапы равны, но запрошено уточнение substage → нужен второй переход
    # (await_payment / paid), move не холостой.
    assert is_redundant_stage_move(5, 5, 9) is False


def test_different_stage_with_substage_is_not_redundant():
    assert is_redundant_stage_move(5, 7, 9) is False


def test_substage_zero_treated_as_absent():
    # substage_id == 0 не валиден как id, трактуется как «не задан» (falsy) →
    # на равных этапах это холостой move. Документируем поведение явно.
    assert is_redundant_stage_move(5, 5, 0) is True
