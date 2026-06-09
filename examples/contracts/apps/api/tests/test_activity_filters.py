"""Эпик 24 — Tasks v2: тесты фильтров активностей.

Pure-function тесты (без БД). Покрываем:
- Whitelists: priority, status, collaborator_roles, link_types
- ActivityCreate schema с Epic 24 полями
- ActivityUpdate schema с Epic 24 полями
- presets whitelist (статическая проверка)
"""
from __future__ import annotations

from datetime import date, datetime, timezone
from decimal import Decimal

import pytest
from pydantic import ValidationError

from app.routers.activities import (
    ACTIVITY_PRIORITIES,
    ACTIVITY_STATUSES,
    COLLABORATOR_ROLES,
    LINK_TYPES,
    ActivityCreate,
    ActivityUpdate,
    CollaboratorIn,
    RelatedLinkIn,
    resolve_related_targets,
)


# ============ Wave 5: resolve_related_targets (двунаправл. видимость) ============


def test_resolve_related_company_expands_to_its_deals():
    """company → сама компания + флаг расширения на все её сделки."""
    pairs, expand = resolve_related_targets("company", 42)
    assert pairs == [("company", 42)]
    assert expand is True


def test_resolve_related_deal_with_company():
    """deal с company_id → сама сделка + её компания, без расширения."""
    pairs, expand = resolve_related_targets("deal", 7, deal_company_id=42)
    assert pairs == [("deal", 7), ("company", 42)]
    assert expand is False


def test_resolve_related_deal_without_company():
    """deal без company_id → только сама сделка."""
    pairs, expand = resolve_related_targets("deal", 7, deal_company_id=None)
    assert pairs == [("deal", 7)]
    assert expand is False


def test_resolve_related_other_type_is_isolated():
    """Прочие типы (contact и т.п.) → связанных нет, только сама цель."""
    pairs, expand = resolve_related_targets("contact", 5)
    assert pairs == [("contact", 5)]
    assert expand is False


# ============ ActivityCreate: Epic 24 поля ============


def test_activity_create_defaults():
    """ActivityCreate: дефолты для Epic 24 полей."""
    a = ActivityCreate(
        kind="task",
        target_type="deal",
        target_id=1,
        title="Тест",
    )
    assert a.priority == "normal"
    assert a.tags == []
    assert a.category_id is None
    assert a.parent_activity_id is None
    assert a.recurrence_rule is None
    assert a.recurrence_until is None


def test_activity_create_with_category():
    """ActivityCreate: принимает category_id."""
    a = ActivityCreate(
        kind="task",
        target_type="deal",
        target_id=1,
        title="Подготовить КП",
        category_id=5,
        priority="high",
    )
    assert a.category_id == 5
    assert a.priority == "high"


def test_activity_create_with_tags():
    """ActivityCreate: tags — список строк."""
    a = ActivityCreate(
        kind="task",
        target_type="counterparty",
        target_id=10,
        title="Тег задача",
        tags=["срочно", "КП", "2026"],
    )
    assert a.tags == ["срочно", "КП", "2026"]


def test_activity_create_with_recurrence():
    """ActivityCreate: recurrence_rule + recurrence_until."""
    a = ActivityCreate(
        kind="task",
        target_type="deal",
        target_id=1,
        title="Еженедельный отчёт",
        recurrence_rule="weekly",
        recurrence_until=date(2026, 12, 31),
    )
    assert a.recurrence_rule == "weekly"
    assert a.recurrence_until == date(2026, 12, 31)


def test_activity_create_with_planned_hours():
    """ActivityCreate: planned_hours — Decimal."""
    a = ActivityCreate(
        kind="task",
        target_type="deal",
        target_id=1,
        title="Долгая задача",
        planned_hours=Decimal("3.5"),
    )
    assert a.planned_hours == Decimal("3.5")


def test_activity_create_with_parent():
    """ActivityCreate: parent_activity_id для подзадач."""
    a = ActivityCreate(
        kind="task",
        target_type="deal",
        target_id=1,
        title="Подзадача",
        parent_activity_id=42,
    )
    assert a.parent_activity_id == 42


def test_activity_create_title_empty_fails():
    """ActivityCreate: title не может быть пустым."""
    with pytest.raises(ValidationError):
        ActivityCreate(
            kind="task",
            target_type="deal",
            target_id=1,
            title="",
        )


def test_activity_create_color_label():
    """ActivityCreate: color_label принимается."""
    a = ActivityCreate(
        kind="task",
        target_type="deal",
        target_id=1,
        title="Цветная задача",
        color_label="#FF5733",
    )
    assert a.color_label == "#FF5733"


# ============ ActivityUpdate: Epic 24 поля ============


def test_activity_update_defaults():
    """ActivityUpdate: без полей — пустой patch."""
    u = ActivityUpdate()
    assert u.model_dump(exclude_unset=True) == {}


def test_activity_update_progress_pct():
    """ActivityUpdate: progress_pct 0..100."""
    u = ActivityUpdate(progress_pct=75)
    assert u.progress_pct == 75

    with pytest.raises(ValidationError):
        ActivityUpdate(progress_pct=101)

    with pytest.raises(ValidationError):
        ActivityUpdate(progress_pct=-1)


def test_activity_update_result_text():
    """ActivityUpdate: result_text принимается."""
    u = ActivityUpdate(result_text="КП отправлено клиенту")
    data = u.model_dump(exclude_unset=True)
    assert data == {"result_text": "КП отправлено клиенту"}


def test_activity_update_tags():
    """ActivityUpdate: tags — список."""
    u = ActivityUpdate(tags=["важно", "дедлайн"])
    data = u.model_dump(exclude_unset=True)
    assert data["tags"] == ["важно", "дедлайн"]


def test_activity_update_planned_and_actual_hours():
    """ActivityUpdate: обновляем плановые и фактические часы."""
    u = ActivityUpdate(planned_hours=Decimal("2.0"), actual_hours=Decimal("1.5"))
    data = u.model_dump(exclude_unset=True)
    assert data["planned_hours"] == Decimal("2.0")
    assert data["actual_hours"] == Decimal("1.5")


# ============ CollaboratorIn / RelatedLinkIn ============


def test_collaborator_in_all_valid_roles():
    """CollaboratorIn: все допустимые роли принимаются."""
    for role in COLLABORATOR_ROLES:
        c = CollaboratorIn(user_id=1, role=role)
        assert c.role == role


def test_related_link_in_all_valid_types():
    """RelatedLinkIn: все допустимые link_type принимаются."""
    for lt in LINK_TYPES:
        r = RelatedLinkIn(target_id=5, link_type=lt)
        assert r.link_type == lt


def test_related_link_in_default_type():
    """RelatedLinkIn: default link_type == 'related'."""
    r = RelatedLinkIn(target_id=10)
    assert r.link_type == "related"


# ============ Priority whitelist ============


def test_priority_whitelist_completeness():
    """Все 4 приоритета присутствуют."""
    assert "low" in ACTIVITY_PRIORITIES
    assert "normal" in ACTIVITY_PRIORITIES
    assert "high" in ACTIVITY_PRIORITIES
    assert "critical" in ACTIVITY_PRIORITIES
    assert len(ACTIVITY_PRIORITIES) == 4


# ============ Migrations: проверяем файлы ============


def test_migration_0057_creates_task_categories():
    """Миграция 0057 создаёт таблицы task_categories."""
    from pathlib import Path
    path = (
        Path(__file__).resolve().parents[1]
        / "alembic/versions/0057_task_categories.py"
    )
    src = path.read_text(encoding="utf-8")
    assert "task_categories" in src
    assert "task_category_co_executors" in src
    assert "task_category_auditors" in src
    assert "task_category_observers" in src
    assert "task_category_checklist_items" in src
    assert "fk_pipeline_stage_task_category" in src
    assert "def downgrade" in src


def test_migration_0058_extends_activities():
    """Миграция 0058 добавляет Epic 24 поля в activities."""
    from pathlib import Path
    path = (
        Path(__file__).resolve().parents[1]
        / "alembic/versions/0058_activity_v2.py"
    )
    src = path.read_text(encoding="utf-8")
    assert "category_id" in src
    assert "priority" in src
    assert "status" in src
    assert "is_closed" in src
    assert "progress_pct" in src
    assert "result_text" in src
    assert "tags" in src
    assert "recurrence_rule" in src
    assert "activity_collaborators" in src
    assert "activity_checklist_items" in src
    assert "idx_activities_status_due" in src
    assert "idx_activities_tags_gin" in src
    assert "def downgrade" in src


def test_migration_0059_creates_files_links():
    """Миграция 0059 создаёт activity_attachments и activity_related_links."""
    from pathlib import Path
    path = (
        Path(__file__).resolve().parents[1]
        / "alembic/versions/0059_activity_files_links.py"
    )
    src = path.read_text(encoding="utf-8")
    assert "activity_attachments" in src
    assert "activity_related_links" in src
    assert "uq_related_link_pair" in src
    assert "def downgrade" in src
