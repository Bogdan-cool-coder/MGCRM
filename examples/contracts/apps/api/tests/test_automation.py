"""PipelineAutomation (Эпик 4) — pure-function проверки whitelist'ов, dedup-логики и миграции.

Без БД-фикстуры: проверяем что enum'ы триггеров/действий/таргетов зафиксированы,
что set_field whitelist'ы корректные, что pure-функция dedup-логики работает,
что dry-run pydantic-схема возвращает preview без выполнения, что миграция 0022
заводит нужные индексы.

NOTE: интеграционные тесты executor (с реальным AsyncSession) сделаем в следующей
итерации — пока pure-function unit'ы покрывают whitelist'ы и edge cases.
"""
from __future__ import annotations

from datetime import UTC, datetime, timedelta
from pathlib import Path

import pytest
from pydantic import ValidationError

from app.models import AutomationRun, PipelineAutomation
from app.routers.automations import (
    AutomationCreate,
    AutomationOut,
    AutomationTestIn,
    AutomationUpdate,
)
from app.services.automation_executor import (
    AUTOMATION_ACTIONS,
    AUTOMATION_TARGET_TYPES,
    AUTOMATION_TRIGGERS,
    SET_FIELD_WHITELIST,
    is_recently_run_for_window,
)


def test_automation_trigger_whitelist():
    """trigger_kind включает базовые MVP-значения. Эпик 4.1 добавил on_create."""
    # Базовые из MVP — должны остаться
    for kind in ("on_enter_stage", "idle_in_stage_days", "date_field_approaching"):
        assert kind in AUTOMATION_TRIGGERS
    # Никаких дублей
    assert len(set(AUTOMATION_TRIGGERS)) == len(AUTOMATION_TRIGGERS)


def test_automation_action_whitelist():
    """action_kind включает базовые MVP-значения. Эпик 4.1 добавил
    change_owner / webhook / email / start_sequence."""
    for kind in ("tg_notify", "create_task", "set_field", "generate_document"):
        assert kind in AUTOMATION_ACTIONS
    assert len(set(AUTOMATION_ACTIONS)) == len(AUTOMATION_ACTIONS)


def test_automation_target_types_whitelist():
    """target_type ограничен 3 типами — те у кого есть owner+stage в MVP."""
    assert AUTOMATION_TARGET_TYPES == ("deal", "lead", "subscription")
    assert len(set(AUTOMATION_TARGET_TYPES)) == len(AUTOMATION_TARGET_TYPES)


def test_set_field_whitelist_deal():
    """set_field разрешено только на безопасных полях Deal — не stage_id, не amount."""
    assert "deal" in SET_FIELD_WHITELIST
    allowed = SET_FIELD_WHITELIST["deal"]
    # Разрешённые
    assert "notes" in allowed
    assert "title" in allowed
    # Запрещённые (бизнес-критичные / security-чувствительные)
    assert "stage_id" not in allowed, "stage_id должен меняться через /move с валидацией ACL"
    assert "amount" not in allowed, "amount — финансовое поле, нужен audit"
    assert "owner_user_id" not in allowed
    assert "counterparty_id" not in allowed
    assert "contract_id" not in allowed
    assert "pipeline_id" not in allowed


def test_set_field_whitelist_lead():
    """set_field разрешено только на безопасных полях Lead."""
    assert "lead" in SET_FIELD_WHITELIST
    allowed = SET_FIELD_WHITELIST["lead"]
    assert "notes" in allowed
    assert "status" in allowed
    # Запрещённые
    assert "stage_id" not in allowed
    assert "pipeline_id" not in allowed
    assert "owner_id" not in allowed
    assert "converted_to_counterparty_id" not in allowed


def test_set_field_whitelist_subscription():
    """set_field на Subscription — только meta, не финансы и не команда."""
    assert "subscription" in SET_FIELD_WHITELIST
    allowed = SET_FIELD_WHITELIST["subscription"]
    assert "notes" in allowed
    assert "health_tier" in allowed
    assert "manual_tier_override" in allowed
    # Запрещённые
    assert "fee_actual" not in allowed, "финансы — не автоматизировать"
    assert "fee_contract" not in allowed
    assert "lifecycle_stage_id" not in allowed
    assert "sup_pm_user_id" not in allowed


def test_dry_run_pydantic_test_in_optional_fields():
    """AutomationTestIn: оба поля Optional (cron-триггеры могут запросить без target)."""
    empty = AutomationTestIn()
    assert empty.target_type is None
    assert empty.target_id is None

    explicit = AutomationTestIn(target_type="deal", target_id=42)
    assert explicit.target_type == "deal"
    assert explicit.target_id == 42


def test_automation_create_payload_validation():
    """AutomationCreate: name обязателен, trigger_config/action_config дефолтятся в {}."""
    valid = AutomationCreate(
        name="Idle 7d → tg",
        pipeline_id=1,
        trigger_kind="idle_in_stage_days",
        action_kind="tg_notify",
    )
    assert valid.trigger_config == {}
    assert valid.action_config == {}
    assert valid.is_active is True
    assert valid.stage_id is None  # nullable — на всех этапах воронки

    # name обязателен (min_length=1)
    with pytest.raises(ValidationError):
        AutomationCreate(  # type: ignore[call-arg]
            name="",
            pipeline_id=1,
            trigger_kind="on_enter_stage",
            action_kind="tg_notify",
        )

    # pipeline_id обязателен
    with pytest.raises(ValidationError):
        AutomationCreate(  # type: ignore[call-arg]
            name="x",
            trigger_kind="on_enter_stage",
            action_kind="tg_notify",
        )


def test_automation_update_partial():
    """AutomationUpdate: все поля Optional, exclude_unset работает."""
    u = AutomationUpdate(is_active=False)
    patch = u.model_dump(exclude_unset=True)
    assert patch == {"is_active": False}

    empty = AutomationUpdate()
    assert empty.model_dump(exclude_unset=True) == {}


def test_is_recently_run_dedup_logic():
    """Pure-function: окно dedup'а. Если last_run был внутри окна → True (skip),
    иначе False (run).

    Эпик 19: добавлен опциональный window_hours для SLA-сценариев. Минимум окна
    — 1 час (защита от деления на 0 и от слишком частых тиков).
    """
    now = datetime(2026, 5, 30, 12, 0, 0, tzinfo=UTC)

    # Никогда не запускалась → не recently
    assert is_recently_run_for_window(None, now, 7) is False

    # Запуск час назад при окне 7 дней → recently
    one_hour_ago = now - timedelta(hours=1)
    assert is_recently_run_for_window(one_hour_ago, now, 7) is True

    # Запуск 8 дней назад при окне 7 дней → НЕ recently (окно истекло)
    eight_days_ago = now - timedelta(days=8)
    assert is_recently_run_for_window(eight_days_ago, now, 7) is False

    # Запуск ровно 7 дней назад — внутри (cutoff = now - 7 days, last >= cutoff)
    seven_days_ago = now - timedelta(days=7)
    assert is_recently_run_for_window(seven_days_ago, now, 7) is True

    # Эпик 19: window_days=0 и нет hours → минимум 1 час. 12h назад — ЗА окном.
    assert is_recently_run_for_window(now - timedelta(hours=12), now, 0) is False
    # 30 минут назад при окне 0 дней + минимальный 1 час → recently
    assert is_recently_run_for_window(now - timedelta(minutes=30), now, 0) is True

    # Эпик 19: hours-окно для SLA. 12h назад при окне 24 hours → recently
    assert is_recently_run_for_window(
        now - timedelta(hours=12), now, 0, window_hours=24,
    ) is True
    # 25h назад при окне 24 hours → НЕ recently
    assert is_recently_run_for_window(
        now - timedelta(hours=25), now, 0, window_hours=24,
    ) is False


def test_automation_out_has_denormalized_fields():
    """AutomationOut в ответе включает pipeline_name/stage_name/runs_count (UI без N+1)."""
    fields = AutomationOut.model_fields
    assert "pipeline_name" in fields
    assert "stage_name" in fields
    assert "runs_count" in fields
    # Эти 3 поля — Optional/default, чтобы не валить ответ если pipeline/stage удалён
    # (CASCADE'нется сама автоматизация, но в гонке мы возвращаем None)


def test_automation_models_have_required_columns():
    """Проверка моделей PipelineAutomation / AutomationRun: ключевые колонки на месте."""
    pa_cols = {c.name for c in PipelineAutomation.__table__.columns}
    assert {
        "id", "name", "description", "pipeline_id", "stage_id",
        "trigger_kind", "trigger_config", "action_kind", "action_config",
        "is_active", "created_by_user_id", "last_run_at",
        "created_at", "updated_at",
    }.issubset(pa_cols)

    ar_cols = {c.name for c in AutomationRun.__table__.columns}
    assert {
        "id", "automation_id", "target_type", "target_id", "status",
        "started_at", "finished_at", "error_text", "result_json",
    }.issubset(ar_cols)


def test_migration_0022_has_indexes():
    """Миграция 0022 заводит ключевые индексы для horizonталей фильтров."""
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0022_pipeline_automation.py"
    )
    src = migration_path.read_text(encoding="utf-8")
    # pipeline_automations
    for ix in (
        "ix_pipeline_automations_pipeline_id",
        "ix_pipeline_automations_stage_id",
        "ix_pipeline_automations_trigger_kind",
        "ix_pipeline_automations_is_active",
    ):
        assert ix in src, f"индекс {ix} должен быть в миграции 0022"
    # automation_runs — композитный (target_type, target_id) + status + started_at
    assert "ix_automation_runs_target" in src
    assert '["target_type", "target_id"]' in src
    assert "ix_automation_runs_status" in src
    assert "ix_automation_runs_started_at" in src
    assert "ix_automation_runs_automation_id" in src
    # downgrade — реализован, обе таблицы дропаются
    assert "def downgrade()" in src
    assert 'drop_table("automation_runs")' in src
    assert 'drop_table("pipeline_automations")' in src
    # FK CASCADE / SET NULL — проверяем что задан правильный ondelete
    assert 'ondelete="CASCADE"' in src
    assert 'ondelete="SET NULL"' in src
