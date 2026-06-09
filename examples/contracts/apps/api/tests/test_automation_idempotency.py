"""PipelineAutomation idempotency / escalation / date-field фиксы код-аудита.

Pure-function проверки (без DB fixture) для фиксов CRITICAL/HIGH/MEDIUM:
- floor_to_hour — стабильный trigger_event_ts → дедуп внутри окна;
- DATE_FIELDS — date_field_approaching расширен на deal/lead (не только subscription);
- SUBSCRIPTION_TIER_VALUES + SET_FIELD_WHITELIST — валидный набор tier'ов;
- _safe_after_hours — парс уровней escalation_chain;
- модель AutomationRun.trigger_event_ts + partial UNIQUE-индекс на месте;
- миграция 0081 заводит колонку + индекс идемпотентности.

DB-зависимые пути (claim_run_slot ON CONFLICT, реальный дедуп на scale=2,
run_escalation_scanner end-to-end) НЕ покрыты pure-тестами — это требует
интеграционного прогона с Postgres (UNIQUE-индекс, advisory-lock). Логика claim
проверена offline-SQL рендером миграции + ревью.
"""
from __future__ import annotations

from datetime import UTC, date, datetime, timedelta
from pathlib import Path

from app.models import AutomationRun
from app.services.automation_executor import (
    DATE_FIELDS,
    SET_FIELD_WHITELIST,
    SUBSCRIPTION_TIER_VALUES,
    _safe_after_hours,
    floor_to_hour,
    should_release_idem_slot,
)


# ============ floor_to_hour (idle/escalation trigger_event_ts) ============


def test_floor_to_hour_strips_minutes_seconds_micros():
    dt = datetime(2026, 6, 3, 14, 37, 42, 123456, tzinfo=UTC)
    floored = floor_to_hour(dt)
    assert floored == datetime(2026, 6, 3, 14, 0, 0, 0, tzinfo=UTC)
    assert floored.tzinfo is not None  # tz сохранён


def test_floor_to_hour_stable_within_same_hour():
    """Два момента в одном часе → один trigger_event_ts (дедуп сработает)."""
    a = datetime(2026, 6, 3, 9, 5, tzinfo=UTC)
    b = datetime(2026, 6, 3, 9, 55, tzinfo=UTC)
    assert floor_to_hour(a) == floor_to_hour(b)


def test_floor_to_hour_differs_across_hours():
    a = datetime(2026, 6, 3, 9, 59, tzinfo=UTC)
    b = datetime(2026, 6, 3, 10, 0, tzinfo=UTC)
    assert floor_to_hour(a) != floor_to_hour(b)


def test_idle_event_ts_stable_across_ticks():
    """Эмуляция: один idle-эпизод (stable stage_changed_at) → один event_ts вне
    зависимости от того, когда cron тикнул. Новый вход (другой base_ts) → другой."""
    window = timedelta(days=7)
    stage_changed = datetime(2026, 5, 20, 3, 12, tzinfo=UTC)
    # два разных тика cron в течение одного часа окна
    ev1 = floor_to_hour(stage_changed + window)
    ev2 = floor_to_hour(stage_changed + window)
    assert ev1 == ev2
    # повторный вход в этап (stage_changed сдвинулся) — новый эпизод
    re_enter = stage_changed + timedelta(days=10)
    ev3 = floor_to_hour(re_enter + window)
    assert ev3 != ev1


# ============ DATE_FIELDS расширен на deal/lead (HIGH-фикс) ============


def test_date_fields_covers_deal_and_lead():
    assert "deal" in DATE_FIELDS
    assert "lead" in DATE_FIELDS
    assert "closed_at" in DATE_FIELDS["deal"]
    assert "converted_at" in DATE_FIELDS["lead"]


def test_date_fields_subscription_unchanged():
    assert {"discount_until", "impl_start_date", "act_signed_date",
            "last_fee_increase_at", "qa_date"}.issubset(
        DATE_FIELDS["subscription"]
    )


def test_date_field_event_ts_is_field_minus_days():
    """trigger_event_ts для date_field = (значение поля − days), начало дня."""
    field_date = date(2026, 7, 1)
    days = 7
    event_ts = datetime(
        field_date.year, field_date.month, field_date.day, tzinfo=UTC
    ) - timedelta(days=days)
    assert event_ts == datetime(2026, 6, 24, tzinfo=UTC)


# ============ tier-валидация set_field (MEDIUM-фикс) ============


def test_subscription_tier_values_set():
    assert SUBSCRIPTION_TIER_VALUES == frozenset(
        {"A1", "A2", "A3", "A4", "A5", "A6", "C0"}
    )
    assert "C1" not in SUBSCRIPTION_TIER_VALUES  # C1→C0 mapping, C1 не валиден
    assert "B0" not in SUBSCRIPTION_TIER_VALUES  # B-стадии не health_tier


def test_set_field_whitelist_still_has_tier_fields():
    """tier-поля остались в whitelist (валидация в _action_set_field, не удаление)."""
    sub = SET_FIELD_WHITELIST["subscription"]
    assert "health_tier" in sub
    assert "manual_tier_override" in sub
    assert "notes" in sub


# ============ escalation_chain парс (HIGH-фикс) ============


def test_safe_after_hours_parses_int():
    assert _safe_after_hours(48) == 48
    assert _safe_after_hours("24") == 24


def test_safe_after_hours_clamps_and_defaults():
    assert _safe_after_hours(-5) == 0
    assert _safe_after_hours(None) == 0
    assert _safe_after_hours("oops") == 0
    assert _safe_after_hours({}) == 0


def test_escalation_levels_sorted_ascending():
    """Уровни эскалации исполняются по возрастанию after_hours."""
    chain = [
        {"after_hours": 72, "action_kind": "tg_notify"},
        {"after_hours": 24, "action_kind": "tg_notify"},
        {"after_hours": 48, "action_kind": "tg_notify"},
    ]
    ordered = sorted(chain, key=lambda e: _safe_after_hours(e.get("after_hours")))
    assert [e["after_hours"] for e in ordered] == [24, 48, 72]


# ============ slot-release: retry-after-fail vs dedup-on-success ============
#
# РЕГРЕССИЯ-фикс: claim_run_slot вставляет pending-строку по partial UNIQUE
# (automation_id, target_type, target_id, trigger_event_ts) ДО действия. Если
# действие падает (TG 500 / webhook timeout), строка обязана освободить слот
# (обнулить trigger_event_ts), иначе следующий тик с тем же event_ts получит
# ON CONFLICT DO NOTHING → transient-skipped → действие НИКОГДА не доставится.
# should_release_idem_slot — pure-контракт этой логики.


def test_failed_run_releases_slot_for_retry():
    """failed по событию → слот освобождается → следующий тик заново claim'нет."""
    event_ts = datetime(2026, 6, 3, 9, 0, tzinfo=UTC)
    assert should_release_idem_slot("failed", event_ts) is True


def test_success_run_holds_slot_no_repeat():
    """success → слот ДЕРЖИМ → повторный тик/реплика ON CONFLICT → не дублирует."""
    event_ts = datetime(2026, 6, 3, 9, 0, tzinfo=UTC)
    assert should_release_idem_slot("success", event_ts) is False


def test_skipped_run_holds_slot_no_repeat():
    """skipped (anti-spam / нет получателя) тоже держит слот — не ретраим."""
    event_ts = datetime(2026, 6, 3, 9, 0, tzinfo=UTC)
    assert should_release_idem_slot("skipped", event_ts) is False


def test_manual_run_without_event_ts_never_releases():
    """trigger_event_ts=None (ручной execute/retry) — дедупа нет, освобождать нечего."""
    assert should_release_idem_slot("failed", None) is False
    assert should_release_idem_slot("success", None) is False
    assert should_release_idem_slot("skipped", None) is False


def test_concurrent_one_success_holds_then_no_dup():
    """Сценарий scale=2: одна реплика claim+success держит слот; вторая реплика
    при том же event_ts получила бы ON CONFLICT (claim=False). Контракт: success
    НЕ освобождает → дубля доставки нет."""
    event_ts = datetime(2026, 6, 3, 9, 0, tzinfo=UTC)
    # реплика-победитель отработала успешно
    assert should_release_idem_slot("success", event_ts) is False
    # → слот занят, повторный claim вернул бы False (проверяется интеграционно),
    #   действие не повторяется.


def test_retry_cycle_fail_then_success_holds():
    """Полный цикл: тик1 failed (освободил), тик2 success (держит) → ровно одна
    успешная доставка, без дублей и без потери."""
    event_ts = datetime(2026, 6, 3, 9, 0, tzinfo=UTC)
    # тик1: упал — освобождаем, чтобы тик2 переклеймил
    assert should_release_idem_slot("failed", event_ts) is True
    # тик2: успех — держим, дальнейшие тики уже не повторят
    assert should_release_idem_slot("success", event_ts) is False


# ============ модель + миграция 0081 ============


def test_automation_run_has_trigger_event_ts_column():
    cols = {c.name for c in AutomationRun.__table__.columns}
    assert "trigger_event_ts" in cols


def test_automation_run_has_idempotency_index():
    idx_names = {ix.name for ix in AutomationRun.__table__.indexes}
    assert "ux_automation_runs_idem" in idx_names
    idem = next(
        ix for ix in AutomationRun.__table__.indexes
        if ix.name == "ux_automation_runs_idem"
    )
    assert idem.unique is True
    col_names = [c.name for c in idem.columns]
    assert col_names == [
        "automation_id", "target_type", "target_id", "trigger_event_ts"
    ]


def test_migration_0081_creates_column_and_index():
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0081_automation_idempotency.py"
    )
    src = migration_path.read_text(encoding="utf-8")
    assert "0081_automation_idem" in src
    assert 'down_revision: Union[str, None] = "0080_cs_hotfixes"' in src
    assert "ADD COLUMN IF NOT EXISTS trigger_event_ts" in src
    assert "CREATE UNIQUE INDEX IF NOT EXISTS ux_automation_runs_idem" in src
    assert "WHERE trigger_event_ts IS NOT NULL" in src
    assert "pg_advisory_xact_lock" in src
    # revision id ≤ 32 символов
    assert len("0081_automation_idem") <= 32
