"""Чистая логика многоэтапного согласования (без БД)."""

from app.models import Approval, ApprovalDecision, ApprovalRoute
from app.services.approval_engine import (
    active_stage_index,
    can_decide_contract,
    is_stage_completed,
    normalize_stages,
)


# ── B3 WARN-5: no-self-approval on contracts ──


def test_can_decide_contract_blocks_author():
    # автор НЕ может согласовать собственный договор
    assert can_decide_contract(actor_id=7, author_id=7) is False


def test_can_decide_contract_allows_other_user():
    assert can_decide_contract(actor_id=7, author_id=9) is True


def test_can_decide_contract_no_author_no_guard():
    # автор неизвестен (SET NULL после удаления юзера) → запрета нет
    assert can_decide_contract(actor_id=7, author_id=None) is True
    assert can_decide_contract(actor_id=None, author_id=7) is True


def _appr(user_id, stage_order, decision=ApprovalDecision.approved):
    return Approval(user_id=user_id, stage_order=stage_order, decision=decision)


def test_normalize_stages_sorted_by_order():
    route = ApprovalRoute(
        stages=[
            {"order": 1, "name": "B", "user_ids": [2], "min_required": 1},
            {"order": 0, "name": "A", "user_ids": [1], "min_required": 1},
        ],
        approver_user_ids=[],
        min_required=1,
    )
    assert [s["order"] for s in normalize_stages(route)] == [0, 1]


def test_normalize_stages_legacy_single():
    route = ApprovalRoute(stages=[], approver_user_ids=[1, 2], min_required=2)
    stages = normalize_stages(route)
    assert len(stages) == 1
    assert stages[0]["user_ids"] == [1, 2]
    assert stages[0]["min_required"] == 2


def test_stage_completed_needs_min_required_unique_users():
    stage = {"order": 0, "user_ids": [1, 2], "min_required": 2}
    assert is_stage_completed(stage, [_appr(1, 0), _appr(2, 0)]) is True
    assert is_stage_completed(stage, [_appr(1, 0)]) is False
    # один и тот же пользователь дважды не закрывает min_required=2
    assert is_stage_completed(stage, [_appr(1, 0), _appr(1, 0)]) is False


def test_needs_rework_enum_value_exists():
    """P2 (audit A3): approval_decision получил отдельное значение needs_rework,
    чтобы возврат-на-доработку отличался в аналитике от жёсткого rejected."""
    assert ApprovalDecision.needs_rework.value == "needs_rework"
    assert ApprovalDecision.needs_rework != ApprovalDecision.rejected


def test_needs_rework_does_not_complete_stage():
    """needs_rework НЕ засчитывается как «согласовано» — прогресс этапа не ломается.

    Стадии считают только approved-голоса (is_stage_completed), поэтому замена
    rejected→needs_rework на пути return_for_rework безопасна для воронки.
    """
    stage = {"order": 0, "user_ids": [1, 2], "min_required": 1}
    only_rework = [_appr(1, 0, decision=ApprovalDecision.needs_rework)]
    assert is_stage_completed(stage, only_rework) is False
    # один approved уже закрывает min_required=1
    assert is_stage_completed(
        stage, only_rework + [_appr(2, 0, decision=ApprovalDecision.approved)]
    ) is True


def test_active_stage_index_advances_then_completes():
    stages = [
        {"order": 0, "user_ids": [1], "min_required": 1},
        {"order": 1, "user_ids": [2], "min_required": 1},
    ]
    assert active_stage_index(stages, [_appr(1, 0)]) == 1
    assert active_stage_index(stages, [_appr(1, 0), _appr(2, 1)]) == 2


def test_active_stage_index_with_same_user_in_multiple_stages():
    """Баг аудита #6: один user в нескольких этапах.

    User 1 — согласователь и на этапе 0, и на этапе 1. Пока этап 0 не пройден,
    активным считается именно он (а не более поздний). Это инвариант, на котором
    `decide` выбирает корректную Approval-строку для текущего этапа.
    """
    stages = [
        {"order": 0, "user_ids": [1], "min_required": 1},
        {"order": 1, "user_ids": [1, 2], "min_required": 2},
    ]
    # Ничего не согласовано → активен этап 0.
    assert active_stage_index(stages, []) == 0
    # User 1 закрыл этап 0 (pending на этапе 1 ещё открыт) → активен этап 1.
    appr_stage0 = _appr(1, 0)
    appr_stage1_u1 = _appr(1, 1, decision=ApprovalDecision.pending)
    appr_stage1_u2 = _appr(2, 1, decision=ApprovalDecision.pending)
    assert active_stage_index(
        stages, [appr_stage0, appr_stage1_u1, appr_stage1_u2]
    ) == 1


def test_resolve_active_approval_for_repeated_user():
    """Моделирует выбор approval в `decide` для user'а из нескольких этапов.

    При активном этапе 0 у user 1 (pending в этапах 0 и 1) должна выбираться
    строка этапа 0, а не «последняя по id». Воспроизводим селектор из роутера.
    """
    from app.models import ApprovalDecision as AD

    stages = [
        {"order": 0, "user_ids": [1], "min_required": 1},
        {"order": 1, "user_ids": [1], "min_required": 1},
    ]
    a0 = Approval(user_id=1, stage_order=0, decision=AD.pending)
    a0.id = 10
    a1 = Approval(user_id=1, stage_order=1, decision=AD.pending)
    a1.id = 20  # больший id — «последняя», именно её брал старый баговый код
    approvals = [a0, a1]

    active_idx = active_stage_index(stages, approvals)
    active_order = stages[active_idx]["order"]
    assert active_order == 0

    my_pending = [a for a in approvals if a.user_id == 1 and a.decision == AD.pending]
    chosen = next((a for a in my_pending if a.stage_order == active_order), None)
    assert chosen is a0  # этап 0, а НЕ a1 (этап 1, больший id)
