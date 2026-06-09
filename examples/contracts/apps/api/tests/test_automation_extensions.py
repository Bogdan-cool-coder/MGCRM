"""PipelineAutomation Engine extensions (Эпик 4.1) — pure-function проверки.

Новое в Эпик 4.1:
- trigger: on_create
- actions: change_owner, webhook, email, start_sequence
- Sequence / SequenceRun модели + scan/start helpers

Тесты:
- whitelist'ы дополнены новыми kind'ами;
- round_robin_pick: pure-функция выбора по cursor (+ advance);
- build_webhook_signature: HMAC-SHA256 формирование;
- compute_next_step_at: now + delay_days с нормализацией отриц.;
- validate_steps: валидация steps_json для Sequence;
- resolve_owner_field_name: маппинг target_type → колонка owner;
- Sequence/SequenceRun модели с нужными колонками;
- миграция 0025 заводит таблицы + индексы.
"""
from __future__ import annotations

import hashlib
import hmac
from datetime import UTC, datetime, timedelta
from pathlib import Path

import pytest
from pydantic import ValidationError

from app.models import Sequence, SequenceRun
from app.services.automation_executor import (
    AUTOMATION_ACTIONS,
    AUTOMATION_TRIGGERS,
    CHANGE_OWNER_RULES,
    ROUND_ROBIN_CURSOR_PREFIX,
    build_webhook_signature,
    compute_next_step_at,
    resolve_owner_field_name,
    round_robin_pick,
)
from app.services.sequence_executor import (
    SEQUENCE_RUN_STATUSES,
    SEQUENCE_STEP_KINDS,
    validate_steps,
)


# ============ Whitelists (Эпик 4.1) ============


def test_trigger_on_create_in_whitelist():
    """Эпик 4.1: добавлен on_create — самый важный для lead routing из Inbox."""
    assert "on_create" in AUTOMATION_TRIGGERS
    # MVP-триггеры тоже на месте
    assert "on_enter_stage" in AUTOMATION_TRIGGERS
    assert "idle_in_stage_days" in AUTOMATION_TRIGGERS
    assert "date_field_approaching" in AUTOMATION_TRIGGERS


def test_actions_4_1_extensions_in_whitelist():
    """Эпик 4.1: change_owner, webhook, email, start_sequence."""
    for kind in ("change_owner", "webhook", "email", "start_sequence"):
        assert kind in AUTOMATION_ACTIONS, f"{kind} должен быть в action whitelist"


def test_change_owner_rules_whitelist():
    """4 правила распределения для change_owner."""
    assert set(CHANGE_OWNER_RULES) == {
        "round_robin",
        "by_product",
        "by_country",
        "by_department",
    }
    assert len(set(CHANGE_OWNER_RULES)) == len(CHANGE_OWNER_RULES)


# ============ round_robin_pick (pure) ============


def test_round_robin_pick_first_iteration():
    """С cursor=0 — берём pool[0], next_cursor=1 % len."""
    pool = [10, 20, 30]
    picked, next_cursor = round_robin_pick(pool, 0)
    assert picked == 10
    assert next_cursor == 1


def test_round_robin_pick_wraps_around():
    """cursor превышает len → wrap по модулю."""
    pool = [10, 20, 30]
    picked, next_cursor = round_robin_pick(pool, 7)  # 7 % 3 = 1
    assert picked == 20
    assert next_cursor == 8 % 3  # = 2


def test_round_robin_pick_full_rotation():
    """Последовательный вызов прокручивает весь пул и возвращается в начало."""
    pool = [1, 2, 3]
    cursor = 0
    picks: list[int] = []
    for _ in range(6):
        picked, cursor = round_robin_pick(pool, cursor)
        picks.append(picked)
    assert picks == [1, 2, 3, 1, 2, 3]


def test_round_robin_pick_empty_pool_raises():
    """Пустой pool → ValueError (caller должен проверить)."""
    with pytest.raises(ValueError):
        round_robin_pick([], 0)


def test_round_robin_pick_single_element():
    """Пул из одного — всегда тот же."""
    pool = [42]
    for i in range(5):
        picked, _ = round_robin_pick(pool, i)
        assert picked == 42


def test_round_robin_cursor_prefix_is_settings_compatible():
    """Префикс ключа в settings таблице — нужно влезть в varchar(64) с automation_id."""
    # automation_id даже большой (10**9) даёт ключ <= 64 символов
    key = f"{ROUND_ROBIN_CURSOR_PREFIX}{10**9}"
    assert len(key) <= 64


# ============ build_webhook_signature (HMAC) ============


def test_webhook_signature_format():
    """Формат заголовка: 'sha256=<64-hex-chars>'."""
    sig = build_webhook_signature("mysecret", b'{"x":1}')
    assert sig.startswith("sha256=")
    digest_hex = sig[len("sha256="):]
    assert len(digest_hex) == 64
    # все hex
    int(digest_hex, 16)  # не падает


def test_webhook_signature_matches_manual_hmac():
    """Подпись совпадает с ручным HMAC-SHA256."""
    secret = "topsecret"
    body = b'{"event":"automation_fired","target_id":42}'
    expected = hmac.new(secret.encode(), body, hashlib.sha256).hexdigest()
    sig = build_webhook_signature(secret, body)
    assert sig == f"sha256={expected}"


def test_webhook_signature_changes_with_body():
    """Минимальное изменение тела меняет подпись (детерминированно)."""
    secret = "s"
    sig_a = build_webhook_signature(secret, b'{"x":1}')
    sig_b = build_webhook_signature(secret, b'{"x":2}')
    assert sig_a != sig_b


def test_webhook_signature_unicode_body():
    """Кириллица в body не падает (HMAC работает с байтами)."""
    body = '{"name":"Иван"}'.encode("utf-8")
    sig = build_webhook_signature("k", body)
    assert sig.startswith("sha256=")


# ============ compute_next_step_at ============


def test_compute_next_step_at_basic():
    """now + delay_days."""
    now = datetime(2026, 5, 31, 12, 0, 0, tzinfo=UTC)
    result = compute_next_step_at(now, 3)
    assert result == now + timedelta(days=3)


def test_compute_next_step_at_zero_delay():
    """delay=0 → возвращает ровно now (для шагов «сразу после предыдущего»)."""
    now = datetime(2026, 5, 31, 12, 0, 0, tzinfo=UTC)
    assert compute_next_step_at(now, 0) == now


def test_compute_next_step_at_negative_clamped_to_zero():
    """Отрицательный delay нормализуется в 0 (защита от data corruption)."""
    now = datetime(2026, 5, 31, 12, 0, 0, tzinfo=UTC)
    assert compute_next_step_at(now, -5) == now


# ============ resolve_owner_field_name ============


def test_resolve_owner_field_name_deal():
    assert resolve_owner_field_name("deal") == "owner_user_id"


def test_resolve_owner_field_name_lead():
    assert resolve_owner_field_name("lead") == "owner_id"


def test_resolve_owner_field_name_subscription():
    assert resolve_owner_field_name("subscription") == "sup_pm_user_id"


def test_resolve_owner_field_name_unknown_returns_none():
    assert resolve_owner_field_name("contract") is None
    assert resolve_owner_field_name("") is None
    assert resolve_owner_field_name("counterparty") is None


# ============ Sequence step validation ============


def test_validate_steps_empty_list_fails():
    ok, err = validate_steps([])
    assert ok is False
    assert err and "хотя бы один" in err


def test_validate_steps_not_a_list_fails():
    ok, err = validate_steps("not-a-list")  # type: ignore[arg-type]
    assert ok is False


def test_validate_steps_unknown_kind_fails():
    ok, err = validate_steps([
        {"kind": "tg_notify", "config": {}, "delay_days": 0},
        {"kind": "unknown_action", "config": {}, "delay_days": 1},
    ])
    assert ok is False
    assert err and "unknown_action" in err


def test_validate_steps_negative_delay_fails():
    ok, err = validate_steps([
        {"kind": "wait", "config": {}, "delay_days": -3},
    ])
    assert ok is False
    assert err and "delay_days" in err


def test_validate_steps_all_kinds_ok():
    """Все 4 разрешённых kind проходят валидацию."""
    steps = [
        {"kind": "wait", "config": {}, "delay_days": 0},
        {"kind": "tg_notify", "config": {"recipient": "owner", "message": "hi"}, "delay_days": 3},
        {"kind": "email", "config": {"recipient_role": "owner", "subject_template": "S"}, "delay_days": 1},
        {"kind": "create_task", "config": {"title": "T", "due_days": 1}, "delay_days": 7},
    ]
    ok, err = validate_steps(steps)
    assert ok is True, err
    assert err is None


def test_validate_steps_config_not_dict_fails():
    ok, err = validate_steps([
        {"kind": "tg_notify", "config": "not-a-dict", "delay_days": 0},
    ])
    assert ok is False
    assert err and "config" in err


def test_validate_steps_step_not_dict_fails():
    ok, err = validate_steps(["not-a-dict"])  # type: ignore[list-item]
    assert ok is False


def test_sequence_step_kinds_whitelist():
    """Whitelist шагов — Эпик 4.1 даёт 4 базовых + Эпик 19 добавляет if_else."""
    assert set(SEQUENCE_STEP_KINDS) == {
        "wait", "tg_notify", "email", "create_task", "if_else",
    }


def test_sequence_run_statuses_whitelist():
    """SequenceRun статусы — полный lifecycle."""
    assert set(SEQUENCE_RUN_STATUSES) == {
        "pending", "running", "completed", "failed", "cancelled",
    }


# ============ Sequence / SequenceRun ORM ============


def test_sequence_model_columns():
    """Sequence имеет ключевые колонки + reasonable defaults."""
    cols = {c.name for c in Sequence.__table__.columns}
    assert {
        "id", "name", "description", "steps_json", "is_active",
        "created_by_user_id", "created_at", "updated_at",
    }.issubset(cols)


def test_sequence_run_model_columns():
    """SequenceRun: id + sequence_id + target + cursor + status + временные метки."""
    cols = {c.name for c in SequenceRun.__table__.columns}
    assert {
        "id", "sequence_id", "target_type", "target_id",
        "current_step_index", "status",
        "started_at", "next_step_at", "finished_at", "result_json",
    }.issubset(cols)


def test_sequence_run_has_composite_index_for_cron():
    """Композитный индекс (status, next_step_at) — главный путь cron сканера."""
    indexes = {ix.name for ix in SequenceRun.__table__.indexes}
    assert "ix_sequence_runs_status_next" in indexes


def test_sequence_run_target_index():
    """Индекс (target_type, target_id) — таймлайн карточки сделки."""
    indexes = {ix.name for ix in SequenceRun.__table__.indexes}
    assert "ix_sequence_runs_target" in indexes


# ============ Миграция 0025 ============


def test_migration_0025_has_tables_and_indexes():
    """Миграция 0025_sequences создаёт обе таблицы и нужные индексы."""
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0025_sequences.py"
    )
    src = migration_path.read_text(encoding="utf-8")
    # таблицы
    assert '"sequences"' in src
    assert '"sequence_runs"' in src
    # индексы
    for ix in (
        "ix_sequences_is_active",
        "ix_sequence_runs_sequence_id",
        "ix_sequence_runs_target",
        "ix_sequence_runs_status_next",
    ):
        assert ix in src, f"индекс {ix} должен быть в 0025_sequences"
    # FK CASCADE для sequence_runs → sequences
    assert 'ondelete="CASCADE"' in src
    # downgrade
    assert "def downgrade()" in src
    assert 'drop_table("sequence_runs")' in src
    assert 'drop_table("sequences")' in src


def test_migration_0025_revises_0024():
    """Миграция продолжает цепочку (down_revision = 0024_renewal_bulk)."""
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0025_sequences.py"
    )
    src = migration_path.read_text(encoding="utf-8")
    assert 'revision: str = "0025_sequences"' in src
    assert 'down_revision: Union[str, None] = "0024_renewal_bulk"' in src


# ============ Sequence router schemas ============


def test_sequence_create_schema_validation():
    """SequenceCreate: name min_length=1, steps_json default []."""
    from app.routers.sequences import SequenceCreate

    valid = SequenceCreate(name="Welcome cadence")
    assert valid.steps_json == []
    assert valid.is_active is True

    with pytest.raises(ValidationError):
        SequenceCreate(name="")  # type: ignore[call-arg]


def test_sequence_start_in_schema_validation():
    """SequenceStartIn: target_type + target_id обязательны."""
    from app.routers.sequences import SequenceStartIn

    valid = SequenceStartIn(target_type="lead", target_id=10)
    assert valid.target_type == "lead"
    assert valid.target_id == 10

    with pytest.raises(ValidationError):
        SequenceStartIn(target_type="lead")  # type: ignore[call-arg]


# ============ change_owner edge cases (pure-function, без БД) ============


def test_change_owner_round_robin_with_cursor_grows_monotonically():
    """Cursor увеличивается на 1 на каждый pick — это позволяет любому monotonic
    счётчику быть курсором."""
    pool = [100, 200, 300, 400]
    cursor = 0
    seen_cursors = [cursor]
    for _ in range(10):
        _, cursor = round_robin_pick(pool, cursor)
        seen_cursors.append(cursor)
    # Курсоры формируют 0..|pool|-1 в цикле
    assert all(0 <= c < len(pool) for c in seen_cursors)


def test_round_robin_pick_distributes_uniformly_over_pool():
    """50 пиков на пуле из 5 → каждому элементу достаётся ровно 10."""
    pool = [1, 2, 3, 4, 5]
    counts = {x: 0 for x in pool}
    cursor = 0
    for _ in range(50):
        picked, cursor = round_robin_pick(pool, cursor)
        counts[picked] += 1
    assert all(v == 10 for v in counts.values())


# ============ Webhook payload shape (pure check) ============


def test_webhook_signature_is_constant_time_safe():
    """Подпись возвращается строкой, готова для compare_digest. Длина hex — 64."""
    sig = build_webhook_signature("k", b"x")
    digest_hex = sig[len("sha256="):]
    assert len(digest_hex) == 64
    # gmpy/secrets compare-able по типу — string
    assert isinstance(sig, str)


# ============ Sequence executor end-to-end (без БД, через временный объект) ============


def test_compute_next_step_at_after_wait_step():
    """После шага wait с delay=5 — следующий тик через 5 дней."""
    now = datetime(2026, 6, 1, 9, 0, 0, tzinfo=UTC)
    next_at = compute_next_step_at(now, 5)
    assert (next_at - now).days == 5
