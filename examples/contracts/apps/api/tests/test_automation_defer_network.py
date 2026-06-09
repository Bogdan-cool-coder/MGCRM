"""POST-AUDIT #4: fire-and-forget диспетчеризация сетевых действий (pure, без DB).

Покрывает РЕШЕНИЕ о диспетчеризации (что уходит в фон vs inline) — вынесено в
чистую функцию `should_defer_action` + множество `NETWORK_ACTIONS`. Реальная
фоновая отправка (httpx/SMTP/aiogram) и работа со свежей сессией — DB/IO-путь,
здесь НЕ проверяется (требует Postgres + event-loop интеграцию).

Контракт:
- defer_network=True (inline-путь move/create) + action ∈ NETWORK_ACTIONS → фон.
- defer_network=False (cron) → НИКОГДА не фон (классификация не меняется).
- DB-local действия → НИКОГДА не фон (важна семантика «применилось сразу»).
- 'queued' держит idem-слот (не освобождается дедупом, пока фон в полёте).
"""
from __future__ import annotations

from datetime import UTC, datetime

from app.services.automation_executor import (
    AUTOMATION_ACTIONS,
    NETWORK_ACTIONS,
    _DEDUP_HOLDING_STATUSES,
    _QUEUED_STATUS,
    should_defer_action,
    should_release_idem_slot,
)


# ============ NETWORK_ACTIONS — корректный набор ============


def test_network_actions_are_exactly_outbound_io():
    """Только tg_notify/webhook/email делают блокирующий outbound IO."""
    assert NETWORK_ACTIONS == frozenset({"tg_notify", "webhook", "email"})


def test_network_actions_subset_of_all_actions():
    """Каждое сетевое действие — реально существующий action_kind."""
    assert NETWORK_ACTIONS.issubset(set(AUTOMATION_ACTIONS))


def test_db_local_actions_not_in_network_set():
    """DB-local действия НЕ сетевые — остаются inline-синхронными."""
    db_local = {
        "create_task",
        "set_field",
        "generate_document",
        "change_owner",
        "start_sequence",
        "set_tags",
        "complete_tasks",
        "change_stage",
        "create_deal",
    }
    assert db_local.isdisjoint(NETWORK_ACTIONS)


# ============ should_defer_action — диспетчеризация ============


def test_network_action_inline_path_defers():
    """tg_notify/webhook/email из inline-пути (defer_network=True) → фон."""
    for kind in ("tg_notify", "webhook", "email"):
        assert should_defer_action(kind, defer_network=True) is True


def test_network_action_cron_path_does_not_defer():
    """КЛЮЧЕВОЕ: cron-путь (defer_network=False) НЕ меняет классификацию —
    сетевые действия исполняются синхронно как раньше."""
    for kind in ("tg_notify", "webhook", "email"):
        assert should_defer_action(kind, defer_network=False) is False


def test_db_local_action_never_defers_even_inline():
    """DB-local действия не откладываются даже в inline-пути — их семантика
    «применилось сразу» важна (set_field/change_stage и т.п.)."""
    for kind in (
        "set_field",
        "create_task",
        "change_owner",
        "generate_document",
        "start_sequence",
        "set_tags",
        "complete_tasks",
        "change_stage",
        "create_deal",
    ):
        assert should_defer_action(kind, defer_network=True) is False
        assert should_defer_action(kind, defer_network=False) is False


def test_unknown_action_never_defers():
    """Неизвестный action_kind не попадает в фон (нет в NETWORK_ACTIONS)."""
    assert should_defer_action("frobnicate", defer_network=True) is False
    assert should_defer_action("", defer_network=True) is False


def test_cron_classification_invariant_across_all_actions():
    """Инвариант: при defer_network=False НИ ОДНО действие не уходит в фон —
    cron-путь полностью совпадает с прежним поведением."""
    for kind in AUTOMATION_ACTIONS:
        assert should_defer_action(kind, defer_network=False) is False


# ============ 'queued' держит idem-слот ============


def test_queued_status_holds_idem_slot():
    """Пока фоновый таск в полёте (queued) — слот держится: конкурентный
    cron-тик / реплика scale=2 при том же event_ts получат ON CONFLICT и не
    продублируют доставку."""
    assert _QUEUED_STATUS in _DEDUP_HOLDING_STATUSES


def test_queued_status_does_not_release_slot():
    """should_release_idem_slot('queued', ts) is False — слот НЕ освобождается,
    пока действие не доставлено фоновым таском."""
    event_ts = datetime(2026, 6, 6, 9, 0, tzinfo=UTC)
    assert should_release_idem_slot(_QUEUED_STATUS, event_ts) is False


def test_deferred_failure_still_releases_slot_for_retry():
    """Когда фоновый таск падает (failed), слот освобождается — cron сможет
    переретраить (контракт совпадает с inline-путём execute_action)."""
    event_ts = datetime(2026, 6, 6, 9, 0, tzinfo=UTC)
    assert should_release_idem_slot("failed", event_ts) is True


def test_queued_status_value_is_stable_string():
    """_QUEUED_STATUS укладывается в String(16) колонки status (без миграции)."""
    assert _QUEUED_STATUS == "queued"
    assert len(_QUEUED_STATUS) <= 16


# --- POST-AUDIT #4 doraborka: graceful-shutdown cancellation path -----------
# Контракт: при отмене фонового таска (uvicorn graceful shutdown на
# rolling-restart) idem-слот ОСВОБОЖДАЕТСЯ тем же путём, что и failed, чтобы cron
# переретраил действие после рестарта. Сам async-round-trip отмены требует
# event-loop + Postgres (интеграционный прогон), здесь фиксируем чисто-функционально
# инвариант release-логики, на который опирается except asyncio.CancelledError.


def test_cancelled_status_releases_slot_for_retry():
    """should_release_idem_slot('cancelled', ts) is True — та же ветка, что и
    failed: при graceful-shutdown отмене слот освобождается, cron переретраит."""
    event_ts = datetime(2026, 6, 6, 9, 0, tzinfo=UTC)
    assert should_release_idem_slot("cancelled", event_ts) is True


def test_cancelled_not_in_dedup_holding_statuses():
    """'cancelled' НЕ держит дедуп-слот (в отличие от queued/success/skipped) —
    иначе отменённый при рестарте run завис бы и заблокировал ретрай навсегда."""
    assert "cancelled" not in _DEDUP_HOLDING_STATUSES


def test_failed_and_cancelled_release_identically():
    """Инвариант: ветки failed и cancelled освобождают слот одинаково — обе
    проходят через should_release_idem_slot → True (release), для любого
    непустого event_ts и → False при event_ts=None (дедупа нет, освобождать
    нечего)."""
    event_ts = datetime(2026, 6, 6, 9, 0, tzinfo=UTC)
    assert (
        should_release_idem_slot("failed", event_ts)
        == should_release_idem_slot("cancelled", event_ts)
        is True
    )
    assert (
        should_release_idem_slot("failed", None)
        == should_release_idem_slot("cancelled", None)
        is False
    )
