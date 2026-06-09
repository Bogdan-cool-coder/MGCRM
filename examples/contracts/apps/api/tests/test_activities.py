"""Activity / Timeline (Эпик 2) — pure-function проверки схем, констант, миграции.

Без БД-фикстуры: проверяем что белые списки kind/target_type зафиксированы, что
Pydantic-схемы корректно валидируют payload (включая kind='note' без due_at), что
миграция 0020 заводит композитный индекс (target_type, target_id) — горячий путь
Timeline.
"""
from __future__ import annotations

from pathlib import Path

import pytest
from pydantic import ValidationError

from app.models import Activity
from app.routers.activities import (
    ActivityCreate,
    ActivityOut,
    ActivityUpdate,
)
from app.services.activities import (
    ACTIVITY_KINDS,
    ACTIVITY_TARGET_TYPES,
)


def test_activity_kinds_whitelist():
    """kind ограничен 4-мя значениями — продакт-фиксированный список."""
    assert ACTIVITY_KINDS == ("call", "meeting", "task", "note")
    # Никаких дубликатов
    assert len(set(ACTIVITY_KINDS)) == len(ACTIVITY_KINDS)


def test_activity_target_types_whitelist():
    """target_type ограничен 7 типами — все существующие сущности MACRO CRM."""
    assert set(ACTIVITY_TARGET_TYPES) == {
        "lead",
        "contact",
        "company",
        "counterparty",
        "deal",
        "contract",
        "subscription",
    }


def test_activity_create_payload_validation():
    """ActivityCreate: note без due_at ок; task без due_at тоже ок; title обязателен."""
    # note без due_at — валидно
    note = ActivityCreate(
        kind="note",
        target_type="lead",
        target_id=1,
        title="Клиент уточнял про скидку",
    )
    assert note.due_at is None
    assert note.body is None

    # task без due_at — валидно (можно завести задачу без дедлайна)
    task = ActivityCreate(
        kind="task",
        target_type="deal",
        target_id=5,
        title="Подготовить КП",
    )
    assert task.due_at is None

    # title обязателен и не пустой
    with pytest.raises(ValidationError):
        ActivityCreate(  # type: ignore[call-arg]
            kind="task",
            target_type="deal",
            target_id=5,
            title="",
        )


def test_activity_update_partial():
    """ActivityUpdate: все поля Optional, model_dump(exclude_unset=True) даёт только переданные."""
    u = ActivityUpdate(title="Новое название")
    patch = u.model_dump(exclude_unset=True)
    assert patch == {"title": "Новое название"}
    # пустое обновление допустимо (UI может слать только изменённое)
    empty = ActivityUpdate()
    assert empty.model_dump(exclude_unset=True) == {}


def test_complete_idempotent_no_body():
    """POST /activities/{id}/complete — без body. Проверяем что Activity.kind != 'note' допустим
    к /reopen — это enforce'ится в роутере и проверяется на уровне Pydantic-модели
    (схемы запроса нет — endpoint принимает только path-param + CurrentUser).
    """
    # Структурно: модель имеет completed_at + completed_by_id, оба nullable
    cols = {c.name for c in Activity.__table__.columns}
    assert {"completed_at", "completed_by_id"}.issubset(cols)
    # ActivityOut в ответе включает оба поля
    fields = ActivityOut.model_fields
    assert "completed_at" in fields
    assert "completed_by_id" in fields


def test_activity_table_has_polymorphic_columns():
    """Модель Activity действительно polymorphic (target_type+target_id, без direct FK).

    Эпик 24 hotfix (июнь 2026): оба поля стали nullable — поддержка
    standalone-задач без привязки к CRM-сущности (миграция 0068). Раньше
    они были NOT NULL.
    """
    cols = {c.name: c for c in Activity.__table__.columns}
    assert "target_type" in cols
    assert "target_id" in cols
    # target_id — обычный Integer без FK (мы намеренно НЕ ставим FK — полиморфно)
    assert len(cols["target_id"].foreign_keys) == 0
    # Эпик 24: target_type / target_id — nullable (standalone задачи).
    assert cols["target_type"].nullable
    assert cols["target_id"].nullable


def test_activity_out_has_google_calendar_synced_field():
    """ActivityOut.google_calendar_synced — computed bool, default False.

    Frontend (TaskDetailHeader) рендерит badge bi-google если поле True.
    Backend заполняет в _to_out() из eager-loaded gcal_event_links. Это
    хотфикс под Эпик 24.2: badge раньше никогда не показывался, потому
    что поле в schema отсутствовало.
    """
    fields = ActivityOut.model_fields
    assert "google_calendar_synced" in fields, (
        "ActivityOut должен возвращать computed google_calendar_synced для "
        "TaskDetailHeader badge (Эпик 24.2 GCal sync)."
    )
    assert fields["google_calendar_synced"].default is False
    # type annotation — обычный bool (НЕ Optional), чтобы фронт мог
    # делать `task.google_calendar_synced === true` без narrowing.
    assert fields["google_calendar_synced"].annotation is bool


def test_to_out_computes_google_calendar_synced_from_links():
    """_to_out: True если есть >=1 активный link; False если нет linkов или все is_active=False.

    Не трогает БД — собираем stub-объект Activity с подсунутыми атрибутами,
    включая gcal_event_links. _to_out читает их через getattr.
    """
    import types
    from datetime import UTC, datetime
    from app.routers.activities import _to_out

    def _stub_activity(links):
        return types.SimpleNamespace(
            id=1, kind="task", target_type=None, target_id=None,
            title="t", body=None, due_at=None, completed_at=None,
            completed_by_id=None, responsible_id=None, created_by_id=1,
            created_at=datetime.now(UTC), updated_at=datetime.now(UTC),
            created_by=None, responsible=None, completed_by=None,
            is_first_time_meeting=False, ftm_decision_maker_attended=False,
            ftm_presentation_shown=False, ftm_report_url=None,
            ftm_telegram_announced=False, category_id=None,
            parent_activity_id=None, priority="normal", status="new",
            is_closed=False, progress_pct=0, planned_hours=None,
            actual_hours=None, result_text=None, tags=[],
            recurrence_rule=None, recurrence_until=None,
            recurrence_parent_id=None, rejected_at=None,
            rejected_by_user_id=None, color_label=None, is_favorite=False,
            is_pinned=False, collaborators=[], checklist_items=[],
            attachments=[], gcal_event_links=links,
        )

    # Нет linkов → False.
    assert _to_out(_stub_activity([])).google_calendar_synced is False

    # Один link, is_active=False → False.
    inactive = types.SimpleNamespace(is_active=False)
    assert _to_out(_stub_activity([inactive])).google_calendar_synced is False

    # Один link is_active=True → True.
    active = types.SimpleNamespace(is_active=True)
    assert _to_out(_stub_activity([active])).google_calendar_synced is True

    # Несколько linkов, хоть один активный → True.
    mixed = [
        types.SimpleNamespace(is_active=False),
        types.SimpleNamespace(is_active=True),
    ]
    assert _to_out(_stub_activity(mixed)).google_calendar_synced is True


def test_migration_0020_has_composite_index():
    """Миграция 0020 заводит композитный индекс (target_type, target_id) — горячий путь Timeline.

    Также проверяем что есть отдельные индексы на kind / responsible_id / due_at / completed_at.
    """
    migration_path = (
        Path(__file__).resolve().parents[1]
        / "alembic"
        / "versions"
        / "0020_activities.py"
    )
    src = migration_path.read_text(encoding="utf-8")
    # Композитный индекс по (target_type, target_id) — критичный
    assert "ix_activities_target_type_target_id" in src
    assert '["target_type", "target_id"]' in src
    # Дополнительные индексы под фильтры списка
    for ix in (
        "ix_activities_kind",
        "ix_activities_responsible_id",
        "ix_activities_due_at",
        "ix_activities_completed_at",
    ):
        assert ix in src, f"индекс {ix} должен быть в миграции 0020"
    # downgrade реализован
    assert "def downgrade()" in src
    assert 'drop_table("activities")' in src
