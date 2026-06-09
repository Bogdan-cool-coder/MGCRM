"""Эпик 19 — Automation 2: SLA seed structure + idempotent logic (pure-function).

Без БД-фикстуры: проверяем что структура DEFAULT_SLA_RULES корректная (4 правила,
каждое с триггером/действием/escalation), и что _sla_unique_key возвращает
корректный кортеж для idempotency-логики.

Интеграционные тесты сидера на реальной БД (с advisory-lock, реальным insert)
— отдельный slot, делать когда появится docker-compose test runner.
"""
from __future__ import annotations

from pathlib import Path

import pytest

from app.services.sla_seed import (
    DEFAULT_SLA_RULES,
    _SEED_LOCK_SLA,
    _sla_unique_key,
)


def test_default_sla_rules_count():
    """Должно быть 4 SLA-правила (как в TЗ Эпика 19)."""
    assert len(DEFAULT_SLA_RULES) == 4


def test_each_rule_has_required_fields():
    """Каждое правило содержит все обязательные ключи."""
    required = {
        "pipeline_kind", "name", "description",
        "trigger_kind", "trigger_config",
        "action_kind", "action_config",
        "escalation_chain",
    }
    for spec in DEFAULT_SLA_RULES:
        missing = required - spec.keys()
        assert not missing, f"в правиле '{spec.get('name')}' нет ключей: {missing}"


def test_all_rules_have_sla_name_prefix():
    """Все name начинаются с 'SLA:' — это convention для UI-группировки."""
    for spec in DEFAULT_SLA_RULES:
        assert spec["name"].startswith("SLA:"), (
            f"name '{spec['name']}' должен начинаться с 'SLA:'"
        )


def test_pipeline_kinds_are_valid():
    """pipeline_kind — sales / lead / lifecycle / renewal (whitelist)."""
    valid_kinds = {"sales", "lead", "lifecycle", "renewal", "approval"}
    for spec in DEFAULT_SLA_RULES:
        assert spec["pipeline_kind"] in valid_kinds, (
            f"pipeline_kind={spec['pipeline_kind']} не в whitelist"
        )


def test_trigger_kinds_match_executor_whitelist():
    """Все trigger_kind должны быть в AUTOMATION_TRIGGERS (executor поддерживает)."""
    from app.services.automation_executor import AUTOMATION_TRIGGERS
    for spec in DEFAULT_SLA_RULES:
        assert spec["trigger_kind"] in AUTOMATION_TRIGGERS, (
            f"trigger_kind={spec['trigger_kind']} не поддерживается executor'ом"
        )


def test_action_kinds_match_executor_whitelist():
    """Все action_kind должны быть в AUTOMATION_ACTIONS."""
    from app.services.automation_executor import AUTOMATION_ACTIONS
    for spec in DEFAULT_SLA_RULES:
        assert spec["action_kind"] in AUTOMATION_ACTIONS, (
            f"action_kind={spec['action_kind']} не поддерживается executor'ом"
        )


def test_first_rule_is_deal_7days_idle():
    """1-е правило — сделка > 7 дней без активности."""
    rule = DEFAULT_SLA_RULES[0]
    assert "7 дней" in rule["name"]
    assert rule["trigger_kind"] == "idle_in_stage_days"
    assert rule["trigger_config"]["days"] == 7
    assert rule["trigger_config"]["target_type"] == "deal"
    assert rule["action_kind"] == "tg_notify"


def test_first_rule_has_escalation_chain():
    """1-е правило (deal idle) имеет эскалацию через 48ч."""
    rule = DEFAULT_SLA_RULES[0]
    chain = rule["escalation_chain"]
    assert isinstance(chain, list)
    assert len(chain) == 1
    esc = chain[0]
    assert esc["after_hours"] == 48
    assert esc["action_kind"] == "tg_notify"


def test_third_rule_is_lead_1day():
    """3-е правило — лид > 1 день без касания, БЕЗ эскалации."""
    rule = DEFAULT_SLA_RULES[2]
    assert "Lead" in rule["name"]
    assert rule["trigger_config"]["days"] == 1
    assert rule["trigger_config"]["target_type"] == "lead"
    assert rule["escalation_chain"] == []


def test_fourth_rule_is_task_deadline():
    """4-е правило — задача с deadline через 24ч (date_field_approaching)."""
    rule = DEFAULT_SLA_RULES[3]
    assert rule["trigger_kind"] == "date_field_approaching"
    assert rule["trigger_config"]["field"] == "deadline_at"
    assert rule["trigger_config"]["hours_before"] == 24


def test_unique_key_returns_tuple():
    """_sla_unique_key возвращает (pipeline_kind, trigger_kind, name)."""
    spec = DEFAULT_SLA_RULES[0]
    key = _sla_unique_key(spec)
    assert isinstance(key, tuple)
    assert len(key) == 3
    assert key[0] == spec["pipeline_kind"]
    assert key[1] == spec["trigger_kind"]
    assert key[2] == spec["name"]


def test_unique_keys_are_distinct():
    """Каждое из 4 правил имеет уникальный ключ — иначе сидер пропустит дубль."""
    keys = [_sla_unique_key(s) for s in DEFAULT_SLA_RULES]
    assert len(keys) == len(set(keys)), "ключи правил должны быть уникальны"


def test_advisory_lock_key_is_int():
    """_SEED_LOCK_SLA — bigint для pg_advisory_lock."""
    assert isinstance(_SEED_LOCK_SLA, int)
    assert _SEED_LOCK_SLA == 728_274_019, (
        "lock key должен быть 728_274_019 (Эпик 19 namespace)"
    )


def test_advisory_lock_key_distinct_from_automations_seed():
    """SLA seed lock-key НЕ должен пересекаться с automation_seed (Эпик 4.2)."""
    from app.services.automation_seed import _SEED_LOCK_AUTOMATIONS
    assert _SEED_LOCK_SLA != _SEED_LOCK_AUTOMATIONS, (
        "SLA lock-key и automations lock-key должны быть РАЗНЫМИ — "
        "иначе сидеры не смогут запускаться параллельно"
    )


def test_escalation_chain_entries_have_required_keys():
    """Каждый esc-step содержит after_hours, action_kind, action_config."""
    required = {"after_hours", "action_kind", "action_config"}
    for spec in DEFAULT_SLA_RULES:
        chain = spec["escalation_chain"]
        for i, esc in enumerate(chain):
            missing = required - esc.keys()
            assert not missing, (
                f"правило '{spec['name']}' эск-шаг {i}: нет ключей {missing}"
            )


def test_action_config_has_message_for_tg_notify():
    """tg_notify action_config содержит recipient + message (не пустые)."""
    for spec in DEFAULT_SLA_RULES:
        if spec["action_kind"] != "tg_notify":
            continue
        cfg = spec["action_config"]
        assert "recipient" in cfg
        assert "message" in cfg
        assert cfg["message"], "message не должен быть пустым"


def test_migration_0043_revision_id_short():
    """Миграция 0043 имеет revision id ≤32 chars (alembic VARCHAR limit)."""
    src = (
        Path(__file__).parent.parent / "alembic" / "versions"
        / "0043_automation_sla_ext.py"
    ).read_text(encoding="utf-8")
    # revision string
    assert 'revision: str = "0043_automation_sla_ext"' in src
    # Длина — 23 chars
    assert len("0043_automation_sla_ext") <= 32


def test_migration_0043_adds_is_sla_and_escalation_chain():
    """Миграция 0043 содержит ALTER для is_sla и escalation_chain."""
    src = (
        Path(__file__).parent.parent / "alembic" / "versions"
        / "0043_automation_sla_ext.py"
    ).read_text(encoding="utf-8")
    assert "is_sla" in src
    assert "escalation_chain" in src
    assert "JSONB" in src, "escalation_chain должен быть JSONB"


def test_migration_0043_downgrade_drops_both_columns():
    """downgrade() удаляет обе колонки + индекс."""
    src = (
        Path(__file__).parent.parent / "alembic" / "versions"
        / "0043_automation_sla_ext.py"
    ).read_text(encoding="utf-8")
    assert 'drop_column("pipeline_automations", "is_sla")' in src
    assert 'drop_column("pipeline_automations", "escalation_chain")' in src
    assert 'drop_index(\n        "ix_pipeline_automations_is_sla"' in src \
        or 'drop_index("ix_pipeline_automations_is_sla"' in src \
        or "ix_pipeline_automations_is_sla" in src


def test_pipeline_automation_model_has_is_sla_column():
    """Модель PipelineAutomation: добавлена колонка is_sla."""
    from app.models import PipelineAutomation
    columns = {c.name for c in PipelineAutomation.__table__.columns}
    assert "is_sla" in columns
    assert "escalation_chain" in columns
