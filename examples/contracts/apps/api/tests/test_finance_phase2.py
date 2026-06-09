"""Pure-function тесты модуля «Финансы» Ф2 (без DB fixture).

Покрывают чистые ядра:
  - выбор сценария согласования (op_type + юрлицо + порог суммы + priority);
  - прохождение этапов (режим any / all, фейл-фаст на reject);
  - переходы статусов заявки (draft→submit→approve→fulfill);
  - переходы/валидация реестра (состав, заморозка, производный payment_status);
  - дефолты прав Ф2 (create_request / approve / manage_registry / fulfill_request /
    manage_approval_scenarios) через can_from_rows.
"""

from __future__ import annotations

from decimal import Decimal

import pytest

from app.services.finance import registry as registry_svc
from app.services.finance import requests as requests_svc
from app.services.finance.access import can_from_rows, role_default
from app.services.finance.fin_approval import (
    ScenarioRow,
    StageDecision,
    active_stage,
    can_decide,
    normalize_stages,
    overall_state,
    pick_scenario,
    stage_state,
    validate_stages,
)

D = Decimal


# ───────────────────────────── выбор сценария ─────────────────────────────


def _sc(
    sid, *, op_type_id=None, le=None, applies="request", mn=None, mx=None,
    prio=0, active=True,
):
    return ScenarioRow(
        id=sid, op_type_id=op_type_id, legal_entity_id=le, applies_to=applies,
        min_amount=mn, max_amount=mx, priority=prio, is_active=active,
    )


def test_pick_scenario_none_when_empty():
    assert pick_scenario(
        [], applies_to="request", op_type_id=1, legal_entity_id=1, amount=D("100")
    ) is None


def test_pick_scenario_filters_by_applies_to():
    s = _sc(1, applies="operation")
    assert pick_scenario(
        [s], applies_to="request", op_type_id=None, legal_entity_id=1, amount=D("10")
    ) is None


def test_pick_scenario_amount_threshold_inclusive():
    s = _sc(1, mn=D("100"), mx=D("1000"))
    # ниже порога — не подходит
    assert pick_scenario(
        [s], applies_to="request", op_type_id=None, legal_entity_id=1, amount=D("99")
    ) is None
    # на нижней границе — подходит
    assert pick_scenario(
        [s], applies_to="request", op_type_id=None, legal_entity_id=1, amount=D("100")
    ).id == 1
    # на верхней границе — подходит
    assert pick_scenario(
        [s], applies_to="request", op_type_id=None, legal_entity_id=1, amount=D("1000")
    ).id == 1
    # выше — не подходит
    assert pick_scenario(
        [s], applies_to="request", op_type_id=None, legal_entity_id=1, amount=D("1001")
    ) is None


def test_pick_scenario_priority_wins():
    low = _sc(1, prio=0)
    high = _sc(2, prio=5)
    chosen = pick_scenario(
        [low, high], applies_to="request", op_type_id=7, legal_entity_id=1, amount=D("50")
    )
    assert chosen.id == 2


def test_pick_scenario_specificity_breaks_priority_tie():
    generic = _sc(1, prio=3)  # для всех типов/юрлиц
    specific = _sc(2, prio=3, op_type_id=7, le=1)
    chosen = pick_scenario(
        [generic, specific], applies_to="request", op_type_id=7, legal_entity_id=1, amount=D("50")
    )
    assert chosen.id == 2  # более специфичный при равном priority


def test_pick_scenario_op_type_must_match_when_set():
    s = _sc(1, op_type_id=7)
    assert pick_scenario(
        [s], applies_to="request", op_type_id=8, legal_entity_id=1, amount=D("10")
    ) is None
    assert pick_scenario(
        [s], applies_to="request", op_type_id=7, legal_entity_id=1, amount=D("10")
    ).id == 1


def test_pick_scenario_inactive_excluded():
    s = _sc(1, active=False)
    assert pick_scenario(
        [s], applies_to="request", op_type_id=None, legal_entity_id=1, amount=D("10")
    ) is None


# ───────────────────────────── этапы (any / all) ─────────────────────────────


def test_normalize_stages_sorts_and_defaults():
    raw = [
        {"order": 1, "name": "B", "user_ids": [2, 3], "mode": "all"},
        {"order": 0, "name": "A", "user_ids": [1]},
    ]
    norm = normalize_stages(raw)
    assert [s["order"] for s in norm] == [0, 1]
    assert norm[0]["mode"] == "any" and norm[0]["min_required"] == 1
    # mode='all' → min_required = число аппруверов
    assert norm[1]["mode"] == "all" and norm[1]["min_required"] == 2


def test_stage_state_any_needs_min_required():
    st = normalize_stages([{"order": 0, "name": "S", "user_ids": [1, 2], "min_required": 1}])[0]
    assert stage_state(st, []) == "pending"
    assert stage_state(st, [StageDecision(1, 0, "approved")]) == "approved"


def test_stage_state_all_needs_everyone():
    st = normalize_stages([{"order": 0, "name": "S", "user_ids": [1, 2], "mode": "all"}])[0]
    assert stage_state(st, [StageDecision(1, 0, "approved")]) == "pending"
    assert stage_state(
        st, [StageDecision(1, 0, "approved"), StageDecision(2, 0, "approved")]
    ) == "approved"


def test_stage_state_reject_fails_fast():
    st = normalize_stages([{"order": 0, "name": "S", "user_ids": [1, 2], "mode": "all"}])[0]
    assert stage_state(
        st, [StageDecision(1, 0, "approved"), StageDecision(2, 0, "rejected")]
    ) == "rejected"


def test_overall_state_empty_stages_auto_approved():
    assert overall_state([], []) == ("approved", 0)


def test_overall_state_sequential_stages():
    stages = [
        {"order": 0, "name": "A", "user_ids": [1]},
        {"order": 1, "name": "B", "user_ids": [2]},
    ]
    # ничего → pending на этапе 0
    assert overall_state(stages, []) == ("pending", 0)
    # этап 0 пройден → pending на этапе 1
    d1 = [StageDecision(1, 0, "approved")]
    assert overall_state(stages, d1) == ("pending", 1)
    # оба пройдены → approved
    d2 = d1 + [StageDecision(2, 1, "approved")]
    assert overall_state(stages, d2) == ("approved", 2)


def test_overall_state_reject_anywhere():
    stages = [{"order": 0, "name": "A", "user_ids": [1, 2]}]
    assert overall_state(stages, [StageDecision(2, 0, "rejected")]) == ("rejected", 0)


def test_active_stage_returns_first_pending_or_none():
    stages = [
        {"order": 0, "name": "A", "user_ids": [1]},
        {"order": 1, "name": "B", "user_ids": [2]},
    ]
    assert active_stage(stages, [])["order"] == 0
    assert active_stage(stages, [StageDecision(1, 0, "approved")])["order"] == 1
    done = [StageDecision(1, 0, "approved"), StageDecision(2, 1, "approved")]
    assert active_stage(stages, done) is None


# ───────────────────────────── флоу заявки ─────────────────────────────


def test_request_assert_editable_only_draft():
    requests_svc.assert_editable("draft")  # ok
    for s in ("submitted", "approved", "rejected", "paid", "cancelled"):
        with pytest.raises(requests_svc.RequestImmutable):
            requests_svc.assert_editable(s)


def test_request_assert_submittable_only_draft():
    requests_svc.assert_submittable("draft")
    with pytest.raises(requests_svc.RequestImmutable):
        requests_svc.assert_submittable("submitted")


def test_request_assert_fulfillable_requires_approved_no_op():
    requests_svc.assert_fulfillable("approved", None)  # ok
    with pytest.raises(requests_svc.RequestImmutable):
        requests_svc.assert_fulfillable("submitted", None)
    # уже конвертирована → повтор запрещён
    with pytest.raises(requests_svc.RequestImmutable):
        requests_svc.assert_fulfillable("approved", 42)


def test_request_assert_cancellable():
    for s in ("draft", "submitted", "approved"):
        requests_svc.assert_cancellable(s)
    for s in ("paid", "cancelled", "rejected"):
        with pytest.raises(requests_svc.RequestImmutable):
            requests_svc.assert_cancellable(s)


# ───────────────────────────── реестр ─────────────────────────────


def _member(oid, *, le=1, direction="out", acc=10, status="planned", reg=None, amount="100"):
    return registry_svc.MemberRow(
        id=oid, legal_entity_id=le, direction=direction, account_from_id=acc,
        status=status, registry_id=reg, amount=D(amount),
    )


def test_registry_compose_only_in_draft():
    registry_svc.assert_draft_composable("draft")
    for s in ("on_review", "approved", "rejected"):
        with pytest.raises(registry_svc.RegistryFrozen):
            registry_svc.assert_draft_composable(s)


def test_registry_validate_member_happy():
    registry_svc.validate_member(
        _member(1), registry_id=5, legal_entity_id=1, source_account_id=10
    )


def test_registry_validate_member_rejects_income():
    with pytest.raises(registry_svc.RegistryMemberInvalid):
        registry_svc.validate_member(
            _member(1, direction="in"), registry_id=5, legal_entity_id=1, source_account_id=10
        )


def test_registry_validate_member_rejects_wrong_account():
    with pytest.raises(registry_svc.RegistryMemberInvalid):
        registry_svc.validate_member(
            _member(1, acc=99), registry_id=5, legal_entity_id=1, source_account_id=10
        )


def test_registry_validate_member_rejects_wrong_entity():
    with pytest.raises(registry_svc.RegistryMemberInvalid):
        registry_svc.validate_member(
            _member(1, le=2), registry_id=5, legal_entity_id=1, source_account_id=10
        )


def test_registry_validate_member_rejects_posted():
    with pytest.raises(registry_svc.RegistryMemberInvalid):
        registry_svc.validate_member(
            _member(1, status="posted"), registry_id=5, legal_entity_id=1, source_account_id=10
        )


def test_registry_validate_member_rejects_other_registry():
    with pytest.raises(registry_svc.RegistryMemberInvalid):
        registry_svc.validate_member(
            _member(1, reg=99), registry_id=5, legal_entity_id=1, source_account_id=10
        )
    # уже в ЭТОМ реестре — допустимо (идемпотентность добавления)
    registry_svc.validate_member(
        _member(1, reg=5), registry_id=5, legal_entity_id=1, source_account_id=10
    )


def test_registry_derive_payment_status():
    assert registry_svc.derive_payment_status([]) == "new"
    assert registry_svc.derive_payment_status(["planned", "planned"]) == "new"
    assert registry_svc.derive_payment_status(["posted", "planned"]) == "partial"
    assert registry_svc.derive_payment_status(["posted", "posted"]) == "paid"


def test_registry_assert_submittable():
    registry_svc.assert_submittable("draft", 2)
    with pytest.raises(registry_svc.RegistryError):
        registry_svc.assert_submittable("draft", 0)  # пустой
    with pytest.raises(registry_svc.RegistryError):
        registry_svc.assert_submittable("on_review", 2)  # не из draft


def test_registry_assert_postable_only_approved():
    registry_svc.assert_postable("approved")
    for s in ("draft", "on_review", "rejected"):
        with pytest.raises(registry_svc.RegistryError):
            registry_svc.assert_postable(s)


# ───────────────────────────── права Ф2 ─────────────────────────────


def test_phase2_role_defaults():
    assert role_default("manager", "create_request") is True
    assert role_default("manager", "approve") is False
    assert role_default("accountant", "fulfill_request") is True
    assert role_default("accountant", "manage_registry") is True
    assert role_default("accountant", "approve") is False
    assert role_default("cfo", "approve") is True
    assert role_default("cfo", "manage_approval_scenarios") is True
    assert role_default("director", "approve") is True
    assert role_default("director", "create_request") is False
    # admin — всё (через ROLE_PERMISSIONS comprehension)
    assert role_default("admin", "manage_approval_scenarios") is True


def test_phase2_user_override_beats_role():
    from app.services.finance.access import PermissionRow

    # manager без approve по дефолту, но точечный override разрешает
    rows = [PermissionRow(role=None, user_id=7, legal_entity_id=None,
                          capability="approve", allowed=True)]
    assert can_from_rows(
        role="manager", user_id=7, capability="approve",
        legal_entity_id=1, rows=rows,
    ) is True
    # другой пользователь — дефолт роли (False)
    assert can_from_rows(
        role="manager", user_id=8, capability="approve",
        legal_entity_id=1, rows=rows,
    ) is False


# ───────────────────────────── CRITICAL #2: self-approval ─────────────────────────────


def test_can_decide_forbids_self_approval():
    # автор == согласант → нельзя
    assert can_decide(5, 5) is False


def test_can_decide_allows_other_user():
    assert can_decide(5, 7) is True


def test_can_decide_allows_when_creator_unknown():
    # creator None (SET NULL после удаления) или actor None — некого защищать
    assert can_decide(5, None) is True
    assert can_decide(None, 7) is True


# ───────────────────────────── WARNING #4: валидация stages ─────────────────────────────


def _stage(order, user_ids, *, min_required=1, mode="any", name="S"):
    return {"order": order, "name": name, "user_ids": user_ids,
            "min_required": min_required, "mode": mode}


def test_validate_stages_rejects_empty_list():
    assert validate_stages([]) != []
    assert validate_stages(None) != []


def test_validate_stages_rejects_empty_user_ids():
    errs = validate_stages([_stage(0, [])])
    assert any("согласант" in e for e in errs)


def test_validate_stages_rejects_min_required_zero():
    errs = validate_stages([_stage(0, [1, 2], min_required=0)])
    assert any("min_required" in e for e in errs)


def test_validate_stages_rejects_min_required_over_members():
    errs = validate_stages([_stage(0, [1, 2], min_required=3)])
    assert any("min_required" in e for e in errs)


def test_validate_stages_rejects_nonsequential_order():
    errs = validate_stages([_stage(0, [1]), _stage(2, [2])])
    assert any("последовательн" in e for e in errs)


def test_validate_stages_rejects_duplicate_order():
    errs = validate_stages([_stage(0, [1]), _stage(0, [2])])
    assert any("уникальн" in e for e in errs)


def test_validate_stages_accepts_valid_chain():
    assert validate_stages([_stage(0, [1, 2]), _stage(1, [3], mode="all")]) == []


def test_validate_stages_mode_all_min_required_is_member_count():
    # mode='all' игнорирует переданный min_required (берёт len(user_ids)) — валидно
    assert validate_stages([_stage(0, [1, 2], min_required=99, mode="all")]) == []


# ── normalize_stages защитно (WARNING #4) ──


def test_normalize_stages_drops_empty_user_ids_stage():
    norm = normalize_stages([_stage(0, []), _stage(1, [3])])
    assert len(norm) == 1
    assert norm[0]["user_ids"] == [3]


def test_normalize_stages_clamps_min_required_to_member_count():
    norm = normalize_stages([_stage(0, [1, 2], min_required=99)])
    assert norm[0]["min_required"] == 2


def test_normalize_stages_clamps_min_required_floor_to_one():
    norm = normalize_stages([_stage(0, [1, 2], min_required=0)])
    assert norm[0]["min_required"] == 1


# ───────────────────────────── WARNING #3: paid-переход ─────────────────────────────


def test_request_mark_paid_sets_status_and_operation():
    from types import SimpleNamespace

    req = SimpleNamespace(status="approved", resulting_operation_id=None)
    requests_svc.mark_paid(req, 42)
    assert req.status == "paid"
    assert req.resulting_operation_id == 42


# ───────── B7 CRIT-2: канал-независимый порог согласования (прямые операции) ─────────


def test_b7_operation_scenario_picked_same_as_request():
    """Сценарий applies_to='operation' выбирается тем же pick_scenario, что и заявки.

    Это ядро фикса B7 CRIT-2: прямая операция теперь проверяется против operation-
    сценариев — порог суммы больше нельзя обойти выбором прямого канала вместо заявки.
    """
    s = _sc(1, applies="operation", op_type_id=7, le=1, mn=D("50000"))
    # крупный cash_out (≥ порога) → сценарий найден (операция уйдёт в согласование)
    assert pick_scenario(
        [s], applies_to="operation", op_type_id=7, legal_entity_id=1, amount=D("50000")
    ).id == 1
    # мелкий (ниже порога) → None → safe-by-default, постится мгновенно
    assert pick_scenario(
        [s], applies_to="operation", op_type_id=7, legal_entity_id=1, amount=D("49999")
    ) is None


def test_b7_scenario_requires_signoff_true_when_stages():
    from app.services.finance.fin_approval import scenario_requires_signoff

    # есть валидный этап → требуется подпись → операция гейтится (не постится сразу)
    assert scenario_requires_signoff([_stage(0, [1, 2], min_required=1)]) is True


def test_b7_scenario_requires_signoff_false_when_no_stages():
    from app.services.finance.fin_approval import scenario_requires_signoff

    # пустые/невалидные этапы = авто-апрув → НЕ гейтим (safe-by-default, постим сразу)
    assert scenario_requires_signoff(None) is False
    assert scenario_requires_signoff([]) is False
    assert scenario_requires_signoff([_stage(0, [])]) is False  # этап без согласантов


def test_b7_journal_gross_debit_sums_dt_side():
    """Порог журнала = Σ дебетовых строк (для баланса = величина проводки)."""
    from types import SimpleNamespace

    from app.services.finance.fin_approval import journal_gross_debit

    lines = [
        SimpleNamespace(side="dt", amount=D("70000")),
        SimpleNamespace(side="dt", amount=D("30000")),
        SimpleNamespace(side="kt", amount=D("100000")),  # кредит не считаем
    ]
    assert journal_gross_debit(lines) == D("100000")


def test_b7_journal_gross_debit_empty_is_zero():
    from app.services.finance.fin_approval import journal_gross_debit

    assert journal_gross_debit([]) == D("0")
