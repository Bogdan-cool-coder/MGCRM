"""Эпик 19 — Automation 2: dry-run / execute endpoints (pure-function).

Без БД-фикстуры: проверяем pure-функции построения плана действий
(_build_actions_plan), pydantic-схемы (DryRunIn/Out, ExecuteIn/Out,
MatchedRecord, ActionPlanItem), helper _make_entity_label,
parse_idle_window (расширенная для SLA на hours).
"""
from __future__ import annotations

import pytest
from pydantic import ValidationError

from app.routers.automations import (
    ActionPlanItem,
    AutomationInfo,
    DryRunIn,
    DryRunOut,
    ExecuteIn,
    ExecuteOut,
    MatchedRecord,
    _build_actions_plan,
    _make_entity_label,
)
from app.services.automation_executor import parse_idle_window


# ============ Pydantic-схемы dry-run ============


def test_dry_run_in_defaults():
    """DryRunIn: дефолтный limit=100, target_type/id опциональны."""
    inp = DryRunIn()
    assert inp.limit == 100
    assert inp.target_type is None
    assert inp.target_id is None


def test_dry_run_in_explicit_target():
    """DryRunIn с явным target_type+target_id."""
    inp = DryRunIn(target_type="deal", target_id=42, limit=50)
    assert inp.target_type == "deal"
    assert inp.target_id == 42
    assert inp.limit == 50


def test_dry_run_in_limit_validation():
    """limit ограничен [1, 500]."""
    with pytest.raises(ValidationError):
        DryRunIn(limit=0)
    with pytest.raises(ValidationError):
        DryRunIn(limit=1000)
    # Граничные значения OK
    DryRunIn(limit=1)
    DryRunIn(limit=500)


def test_matched_record_schema():
    """MatchedRecord: 4 поля, matches_at опционально."""
    rec = MatchedRecord(
        entity_type="deal",
        entity_id=1,
        entity_label="Сделка #1: Пример",
    )
    assert rec.matches_at is None
    rec2 = MatchedRecord(
        entity_type="lead",
        entity_id=2,
        entity_label="Лид #2",
        matches_at="2026-05-30T12:00:00",
    )
    assert rec2.matches_at == "2026-05-30T12:00:00"


def test_action_plan_item_schema():
    """ActionPlanItem: 3 обязательных поля."""
    item = ActionPlanItem(
        kind="tg_notify",
        description="TG получатель: owner",
        target="3 матчей",
    )
    assert item.kind == "tg_notify"


def test_dry_run_out_schema():
    """DryRunOut: композиция automation + matched_records + count + plan."""
    out = DryRunOut(
        automation=AutomationInfo(id=1, name="X", trigger_kind="idle_in_stage_days"),
        matched_records=[
            MatchedRecord(entity_type="deal", entity_id=1, entity_label="Deal #1"),
        ],
        match_count=1,
        actions_plan=[
            ActionPlanItem(kind="tg_notify", description="...", target="1 матч"),
        ],
    )
    assert out.match_count == 1
    assert len(out.matched_records) == 1


# ============ ExecuteIn / Out ============


def test_execute_in_defaults():
    """ExecuteIn: дефолтный limit=100, entity_ids=None, target_type=None."""
    inp = ExecuteIn()
    assert inp.limit == 100
    assert inp.entity_ids is None
    assert inp.target_type is None


def test_execute_in_with_entity_ids():
    """ExecuteIn с явным entity_ids."""
    inp = ExecuteIn(entity_ids=[1, 2, 3], target_type="deal")
    assert inp.entity_ids == [1, 2, 3]
    assert inp.target_type == "deal"


def test_execute_in_limit_validation():
    """limit ограничен [1, 500]."""
    with pytest.raises(ValidationError):
        ExecuteIn(limit=0)
    with pytest.raises(ValidationError):
        ExecuteIn(limit=600)


def test_execute_out_schema():
    """ExecuteOut: automation + counts + run_ids."""
    out = ExecuteOut(
        automation=AutomationInfo(id=1, name="X", trigger_kind="on_create"),
        runs_created=3,
        success_count=2,
        failed_count=0,
        skipped_count=1,
        run_ids=[10, 11, 12],
    )
    assert out.runs_created == 3
    assert out.run_ids == [10, 11, 12]


# ============ _build_actions_plan pure-function ============


class _FakeAutomation:
    """Минимальный stub PipelineAutomation для тестов pure-функции."""

    def __init__(self, action_kind: str, action_config: dict, escalation_chain=None):
        self.action_kind = action_kind
        self.action_config = action_config
        self.escalation_chain = escalation_chain


def test_build_actions_plan_tg_notify():
    """tg_notify → описание содержит recipient + текст."""
    a = _FakeAutomation(
        "tg_notify", {"recipient": "owner", "message": "Привет {target_title}"},
    )
    plan = _build_actions_plan(a, 5)  # type: ignore[arg-type]
    assert len(plan) == 1
    assert plan[0].kind == "tg_notify"
    assert "owner" in plan[0].description
    assert "Привет" in plan[0].description
    assert "5 матчей" in plan[0].target


def test_build_actions_plan_create_task():
    """create_task → описание содержит title."""
    a = _FakeAutomation(
        "create_task", {"title": "Перезвонить клиенту"},
    )
    plan = _build_actions_plan(a, 1)  # type: ignore[arg-type]
    assert plan[0].kind == "create_task"
    assert "Перезвонить" in plan[0].description


def test_build_actions_plan_change_owner():
    """change_owner → описание содержит правило."""
    a = _FakeAutomation(
        "change_owner", {"rule": "round_robin"},
    )
    plan = _build_actions_plan(a, 10)  # type: ignore[arg-type]
    assert plan[0].kind == "change_owner"
    assert "round_robin" in plan[0].description


def test_build_actions_plan_webhook():
    """webhook → описание содержит URL."""
    a = _FakeAutomation(
        "webhook", {"url": "https://example.com/hook"},
    )
    plan = _build_actions_plan(a, 1)  # type: ignore[arg-type]
    assert plan[0].kind == "webhook"
    assert "example.com" in plan[0].description


def test_build_actions_plan_with_escalation():
    """escalation_chain → добавляются доп. items в plan с правильным after_hours."""
    a = _FakeAutomation(
        "tg_notify", {"recipient": "owner", "message": "..."},
        escalation_chain=[
            {"after_hours": 48, "action_kind": "tg_notify", "action_config": {}},
            {"after_hours": 168, "action_kind": "tg_notify", "action_config": {}},
        ],
    )
    plan = _build_actions_plan(a, 3)  # type: ignore[arg-type]
    # 1 основное + 2 эскалации
    assert len(plan) == 3
    assert "Через 48ч" in plan[1].description
    assert "Через 168ч" in plan[2].description
    assert plan[1].kind.startswith("escalation_")
    assert plan[2].kind.startswith("escalation_")


def test_build_actions_plan_no_escalation():
    """Без escalation_chain (None или []) → только 1 основной item."""
    a1 = _FakeAutomation("tg_notify", {"message": "..."}, escalation_chain=None)
    plan1 = _build_actions_plan(a1, 1)  # type: ignore[arg-type]
    assert len(plan1) == 1

    a2 = _FakeAutomation("tg_notify", {"message": "..."}, escalation_chain=[])
    plan2 = _build_actions_plan(a2, 1)  # type: ignore[arg-type]
    assert len(plan2) == 1


def test_build_actions_plan_set_field():
    """set_field → описание формата 'Установить X=Y'."""
    a = _FakeAutomation(
        "set_field", {"field": "notes", "value": "Автообработано"},
    )
    plan = _build_actions_plan(a, 1)  # type: ignore[arg-type]
    assert "notes" in plan[0].description
    assert "Автообработано" in plan[0].description


def test_build_actions_plan_generate_document():
    """generate_document → описание содержит template_code."""
    a = _FakeAutomation(
        "generate_document", {"template_code": "renewal_v1"},
    )
    plan = _build_actions_plan(a, 1)  # type: ignore[arg-type]
    assert "renewal_v1" in plan[0].description


def test_build_actions_plan_email():
    """email → описание содержит subject."""
    a = _FakeAutomation(
        "email", {"subject_template": "Срочно: реакция"},
    )
    plan = _build_actions_plan(a, 1)  # type: ignore[arg-type]
    assert "Срочно" in plan[0].description


def test_build_actions_plan_start_sequence():
    """start_sequence → описание содержит sequence_id."""
    a = _FakeAutomation("start_sequence", {"sequence_id": 7})
    plan = _build_actions_plan(a, 1)  # type: ignore[arg-type]
    assert "7" in plan[0].description


def test_build_actions_plan_unknown_action_kind():
    """Неизвестный action_kind → graceful fallback с (описание недоступно)."""
    a = _FakeAutomation("crazy_new_kind", {})
    plan = _build_actions_plan(a, 1)  # type: ignore[arg-type]
    assert len(plan) == 1
    assert plan[0].kind == "crazy_new_kind"


def test_build_actions_plan_target_count_pluralization():
    """target говорит 'матч' для 1 и 'матчей' для >1."""
    a = _FakeAutomation("tg_notify", {"message": "..."})
    plan_one = _build_actions_plan(a, 1)  # type: ignore[arg-type]
    plan_many = _build_actions_plan(a, 5)  # type: ignore[arg-type]
    assert "1 матч" in plan_one[0].target
    # Для 5 — "матчей"
    assert "5 матчей" in plan_many[0].target


# ============ _make_entity_label ============


class _FakeDeal:
    def __init__(self, id_: int, title: str | None):
        self.id = id_
        self.title = title


class _FakeLead:
    def __init__(self, id_: int, name: str | None):
        self.id = id_
        self.name = name


class _FakeSubscription:
    def __init__(self, id_: int):
        self.id = id_


def test_make_entity_label_deal():
    """Deal label содержит title."""
    label = _make_entity_label("deal", _FakeDeal(42, "Большая сделка"))
    assert "42" in label
    assert "Большая сделка" in label


def test_make_entity_label_deal_no_title():
    """Deal без title → fallback на '(без названия)'."""
    label = _make_entity_label("deal", _FakeDeal(42, None))
    assert "без названия" in label


def test_make_entity_label_lead():
    """Lead label содержит name."""
    label = _make_entity_label("lead", _FakeLead(7, "Иван Петров"))
    assert "Иван Петров" in label


def test_make_entity_label_subscription():
    """Subscription label — просто 'Подписка #N'."""
    label = _make_entity_label("subscription", _FakeSubscription(15))
    assert "Подписка" in label
    assert "15" in label


# ============ parse_idle_window (расширение Эпика 19) ============


def test_parse_idle_window_days_only():
    """{'days': 7} → (7, 0)."""
    assert parse_idle_window({"days": 7}) == (7, 0)


def test_parse_idle_window_hours_only():
    """{'idle_in_stage_hours': 48} → (0, 48). Эпик 19 SLA-формат."""
    assert parse_idle_window({"idle_in_stage_hours": 48}) == (0, 48)


def test_parse_idle_window_combined():
    """{'days': 1, 'idle_in_stage_hours': 12} → (1, 12)."""
    assert parse_idle_window({"days": 1, "idle_in_stage_hours": 12}) == (1, 12)


def test_parse_idle_window_default():
    """Пустой config → (7, 0) дефолт."""
    assert parse_idle_window({}) == (7, 0)


def test_parse_idle_window_invalid_values_normalized():
    """Нечитаемые значения → 0; обнуляется до минимума (1, 0)."""
    assert parse_idle_window({"days": "abc"}) == (1, 0)
    assert parse_idle_window({"days": -5}) == (1, 0)
    # Только идиотский ввод — нормализуется до 1 (минимум)
    assert parse_idle_window({"idle_in_stage_hours": -10}) == (1, 0)


def test_parse_idle_window_zero_both_clamped():
    """{'days': 0, 'hours': 0} → (1, 0) (минимум, иначе infinite loop)."""
    assert parse_idle_window(
        {"days": 0, "idle_in_stage_hours": 0}
    ) == (1, 0)


def test_parse_idle_window_string_int():
    """Стрингованные int конвертируются: '5' → 5."""
    assert parse_idle_window({"days": "5"}) == (5, 0)
    assert parse_idle_window({"idle_in_stage_hours": "24"}) == (0, 24)


def test_parse_idle_window_returns_tuple():
    """Возвращает именно tuple, не list — для устойчивости в callers."""
    result = parse_idle_window({"days": 3})
    assert isinstance(result, tuple)
    assert len(result) == 2


# ============ Routers — existence / signature checks ============


def test_dry_run_endpoint_registered():
    """POST /automations/{id}/dry-run зарегистрирован в роутере."""
    from app.routers.automations import router
    routes = [(r.path, r.methods if hasattr(r, "methods") else set()) for r in router.routes]
    paths = [p for p, _ in routes]
    assert any("/dry-run" in p for p in paths), (
        f"/dry-run endpoint должен быть, но routes: {paths}"
    )


def test_execute_endpoint_registered():
    """POST /automations/{id}/execute зарегистрирован в роутере."""
    from app.routers.automations import router
    routes = [(r.path, r.methods if hasattr(r, "methods") else set()) for r in router.routes]
    paths = [p for p, _ in routes]
    assert any("/execute" in p for p in paths), (
        f"/execute endpoint должен быть, но routes: {paths}"
    )


# ============ is_sla фильтр в list_automations ============


def test_automation_create_supports_is_sla():
    """AutomationCreate теперь принимает is_sla и escalation_chain."""
    from app.routers.automations import AutomationCreate
    create = AutomationCreate(
        name="X", pipeline_id=1,
        trigger_kind="on_create", action_kind="tg_notify",
        is_sla=True, escalation_chain=[{"after_hours": 24}],
    )
    assert create.is_sla is True
    assert create.escalation_chain == [{"after_hours": 24}]


def test_automation_create_is_sla_defaults_false():
    """По умолчанию is_sla=False (бэквард-совместимо)."""
    from app.routers.automations import AutomationCreate
    create = AutomationCreate(
        name="X", pipeline_id=1,
        trigger_kind="on_create", action_kind="tg_notify",
    )
    assert create.is_sla is False
    assert create.escalation_chain is None


def test_automation_update_supports_is_sla():
    """AutomationUpdate патчит is_sla и escalation_chain."""
    from app.routers.automations import AutomationUpdate
    update = AutomationUpdate(is_sla=True)
    patch = update.model_dump(exclude_unset=True)
    assert patch == {"is_sla": True}


def test_automation_out_has_sla_fields():
    """AutomationOut включает is_sla и escalation_chain в схему ответа."""
    from app.routers.automations import AutomationOut
    fields = AutomationOut.model_fields
    assert "is_sla" in fields
    assert "escalation_chain" in fields
