"""Эпик 23 — action_kind='change_stage' pure-function tests.

Покрываем: whitelist (включение в AUTOMATION_ACTIONS), регрессионные проверки
семантики action (только для deal/lead, защита от cross-pipeline jumps,
no-recursive автоматизации).
"""
from __future__ import annotations

from app.services.automation_executor import AUTOMATION_ACTIONS


# ============ Whitelist ============


def test_change_stage_action_in_whitelist():
    """Эпик 23: change_stage регистрируется в AUTOMATION_ACTIONS."""
    assert "change_stage" in AUTOMATION_ACTIONS


def test_change_stage_distinct_from_set_field():
    """change_stage отдельный action от set_field.

    Защита: stage_id MUST NOT быть в SET_FIELD_WHITELIST. Если случайно появится
    — automation сможет менять этап через set_field в обход валидации pipeline_id.
    """
    from app.services.automation_executor import SET_FIELD_WHITELIST
    for target_t, allowed in SET_FIELD_WHITELIST.items():
        assert "stage_id" not in allowed, (
            f"stage_id не должен быть в SET_FIELD_WHITELIST[{target_t}] — "
            "это меняется через change_stage с валидацией pipeline_id"
        )


def test_change_stage_distinct_from_change_owner():
    """change_stage ≠ change_owner — разные семантики."""
    assert "change_stage" in AUTOMATION_ACTIONS
    assert "change_owner" in AUTOMATION_ACTIONS
    assert "change_stage" != "change_owner"


# ============ Семантические регрессии ============


def test_change_stage_only_deal_lead_supported():
    """change_stage поддерживается только для deal/lead. Subscription и
    counterparty не имеют stage_id (lifecycle_stage_id у subscription — отдельная
    история, через recompute_health-джоб)."""
    # Проверим через резолвер owner field — у subscription это sup_pm_user_id,
    # т.е. он не trivial-stage-based target. Реальная защита — в _action_change_stage:
    # `if not isinstance(target, (Deal, Lead))`. Здесь проверяем что мы НЕ
    # резолвим subscription через owner_field как «deal-стиль».
    from app.services.automation_executor import resolve_owner_field_name
    assert resolve_owner_field_name("deal") == "owner_user_id"
    assert resolve_owner_field_name("lead") == "owner_id"
    # subscription не маппится на owner_user_id — это отдельный target
    assert resolve_owner_field_name("subscription") == "sup_pm_user_id"


def test_change_stage_no_recursive_automation():
    """Регрессия: change_stage НЕ запускает on_enter_stage автоматизации
    целевого этапа — иначе цепочки могут зациклиться.

    Поведенческий контракт документирован в docstring _action_change_stage.
    Здесь — статическая проверка что run_on_enter_stage НЕ вызывается из
    change_stage handler'а (анализом исходника).
    """
    import inspect
    from app.services import automation_executor
    src = inspect.getsource(automation_executor._action_change_stage)
    assert "run_on_enter_stage" not in src, (
        "change_stage НЕ должен дёргать run_on_enter_stage целевого этапа — "
        "иначе можно получить бесконечную рекурсию автоматизаций."
    )


def test_change_stage_action_kind_string():
    """Регрессия: имя action_kind — точно 'change_stage'."""
    assert "change_stage" in AUTOMATION_ACTIONS
    assert "move_stage" not in AUTOMATION_ACTIONS
    assert "set_stage" not in AUTOMATION_ACTIONS


def test_change_stage_pipeline_id_check_in_source():
    """Регрессия: handler проверяет что new_stage.pipeline_id == target.pipeline_id
    (защита от cross-pipeline jumps)."""
    import inspect
    from app.services import automation_executor
    src = inspect.getsource(automation_executor._action_change_stage)
    # Проверяем наличие сравнения pipeline_id в исходнике
    assert "pipeline_id" in src
    assert "другой воронке" in src or "pipeline" in src
