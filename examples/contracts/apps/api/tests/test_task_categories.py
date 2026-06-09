"""Эпик 24 — Tasks v2: тесты категорий задач и применения дефолтов.

Pure-function тесты (без БД). Покрываем:
- Pydantic-схемы TaskCategoryIn/Out/Patch
- render_description_template (pure, из task_category_apply)
- валидацию priority/status
- checklist item схемы
- collaborator role whitelist
"""
from __future__ import annotations

from datetime import datetime

import pytest
from pydantic import ValidationError

from app.routers.activities import (
    ACTIVITY_PRIORITIES,
    ACTIVITY_STATUSES,
    COLLABORATOR_ROLES,
    LINK_TYPES,
    BulkActionIn,
    ChecklistItemIn,
    CollaboratorIn,
    RelatedLinkIn,
)
from app.routers.task_categories import (
    ChecklistItemIn as CatChecklistItemIn,
    ChecklistItemOut as CatChecklistItemOut,
    TaskCategoryIn,
    TaskCategoryOut,
    TaskCategoryPatch,
)
from app.services.task_category_apply import _render_description_template


# ============ Mock Activity helper для pure-function тестов ============


class MockActivity:
    """Простой mock объект Activity без SQLAlchemy instrumentation.

    Используется для тестирования pure-functions из services/task_category_apply.py
    без необходимости поднимать БД или создавать полноценные ORM-объекты.
    """

    def __init__(self, **kwargs):
        self.title = kwargs.get("title", "Test task")
        self.target_type = kwargs.get("target_type", "deal")
        self.target_id = kwargs.get("target_id", 1)
        self.responsible_id = kwargs.get("responsible_id", None)
        self.body = kwargs.get("body", None)


# ============ Whitelist константы ============


def test_activity_priorities_whitelist():
    """Приоритеты зафиксированы — 4 значения."""
    assert set(ACTIVITY_PRIORITIES) == {"low", "normal", "high", "critical"}
    assert len(set(ACTIVITY_PRIORITIES)) == 4


def test_activity_statuses_whitelist():
    """Статусы задачи: 4 значения машины состояний."""
    assert set(ACTIVITY_STATUSES) == {"new", "in_progress", "done", "rejected"}
    assert len(ACTIVITY_STATUSES) == 4


def test_collaborator_roles_whitelist():
    """Роли участников: 3 значения."""
    assert set(COLLABORATOR_ROLES) == {"co_executor", "auditor", "observer"}


def test_link_types_whitelist():
    """Типы связей: 4 значения."""
    assert set(LINK_TYPES) == {"related", "blocks", "blocked_by", "duplicates"}


# ============ Pydantic-схемы TaskCategory ============


def test_task_category_in_defaults():
    """TaskCategoryIn: дефолтные значения корректны."""
    cat = TaskCategoryIn(name="Тест")
    assert cat.sort_order == 0
    assert cat.restrict_close_without_result is False
    assert cat.auto_title_from_category is False
    assert cat.required_file_count == 0
    assert cat.is_active is True
    assert cat.co_executor_user_ids == []
    assert cat.auditor_user_ids == []
    assert cat.observer_user_ids == []
    assert cat.checklist_items == []


def test_task_category_in_name_required():
    """TaskCategoryIn: name обязателен и не пустой."""
    with pytest.raises(ValidationError):
        TaskCategoryIn(name="")  # type: ignore[call-arg]


def test_task_category_in_required_file_count_non_negative():
    """TaskCategoryIn: required_file_count не может быть отрицательным."""
    with pytest.raises(ValidationError):
        TaskCategoryIn(name="Тест", required_file_count=-1)


def test_task_category_in_with_all_fields():
    """TaskCategoryIn: все поля принимаются."""
    cat = TaskCategoryIn(
        name="Подготовка КП",
        sort_order=1,
        default_executor_user_id=42,
        admin_user_id=1,
        description_template="Задача по клиенту {{ target_type }}",
        restrict_close_without_result=True,
        auto_title_from_category=True,
        required_file_count=2,
        co_executor_user_ids=[3, 4],
        auditor_user_ids=[5],
        observer_user_ids=[],
        checklist_items=[
            CatChecklistItemIn(title="Отправить письмо", sort_order=0),
            CatChecklistItemIn(title="Получить подтверждение", sort_order=1),
        ],
    )
    assert cat.name == "Подготовка КП"
    assert len(cat.checklist_items) == 2
    assert cat.restrict_close_without_result is True


def test_task_category_patch_partial():
    """TaskCategoryPatch: все поля Optional."""
    patch = TaskCategoryPatch(name="Новое название")
    data = patch.model_dump(exclude_unset=True)
    assert data == {"name": "Новое название"}


def test_task_category_patch_empty():
    """TaskCategoryPatch: пустой patch допустим."""
    patch = TaskCategoryPatch()
    assert patch.model_dump(exclude_unset=True) == {}


def test_checklist_item_in_validation():
    """CatChecklistItemIn: title обязателен."""
    item = CatChecklistItemIn(title="Пункт 1")
    assert item.sort_order == 0

    with pytest.raises(ValidationError):
        CatChecklistItemIn(title="")


def test_checklist_item_in_max_length():
    """CatChecklistItemIn: title ограничен 256 символами."""
    with pytest.raises(ValidationError):
        CatChecklistItemIn(title="A" * 257)


# ============ Pydantic-схемы Activity (Epic 24 поля) ============


def test_checklist_item_in_activity():
    """ChecklistItemIn для активности: title до 512 символов."""
    item = ChecklistItemIn(title="Проверить документы")
    assert item.sort_order == 0

    # 512 символов — граница
    long_item = ChecklistItemIn(title="A" * 512)
    assert len(long_item.title) == 512

    with pytest.raises(ValidationError):
        ChecklistItemIn(title="A" * 513)


def test_collaborator_in_validation():
    """CollaboratorIn: role должна быть из whitelist (проверяется в роутере, не в Pydantic)."""
    c = CollaboratorIn(user_id=5, role="co_executor")
    assert c.role == "co_executor"
    assert c.user_id == 5


def test_related_link_in_defaults():
    """RelatedLinkIn: link_type по умолчанию 'related'."""
    link = RelatedLinkIn(target_id=10)
    assert link.link_type == "related"


def test_bulk_action_in_validation():
    """BulkActionIn: entity_ids не может быть пустым."""
    with pytest.raises(ValidationError):
        BulkActionIn(action="close", entity_ids=[])

    bulk = BulkActionIn(action="close", entity_ids=[1, 2, 3])
    assert bulk.action == "close"
    assert bulk.params == {}


# ============ Pure-function: render_description_template ============


def _make_activity(**kwargs) -> MockActivity:
    """Создаёт MockActivity с заданными атрибутами (pure helper без ORM)."""
    return MockActivity(**kwargs)


def test_render_description_template_title():
    """{{ title }} заменяется на title активности."""
    activity = _make_activity(title="КП для Рога")
    result = _render_description_template("Задача: {{ title }}", activity)
    assert result == "Задача: КП для Рога"


def test_render_description_template_target_type():
    """{{ target_type }} заменяется на target_type."""
    activity = _make_activity(target_type="deal")
    result = _render_description_template("Тип: {{ target_type }}", activity)
    assert result == "Тип: deal"


def test_render_description_template_target_id():
    """{{ target_id }} заменяется на target_id."""
    activity = _make_activity(target_id=42)
    result = _render_description_template("ID: {{ target_id }}", activity)
    assert result == "ID: 42"


def test_render_description_template_responsible_id_none():
    """{{ responsible_id }} с None → пустая строка."""
    activity = _make_activity(responsible_id=None)
    result = _render_description_template("Исп: {{ responsible_id }}", activity)
    assert result == "Исп: "


def test_render_description_template_responsible_id_set():
    """{{ responsible_id }} с числом → подстановка."""
    activity = _make_activity(responsible_id=7)
    result = _render_description_template("Исп: {{ responsible_id }}", activity)
    assert result == "Исп: 7"


def test_render_description_template_no_placeholders():
    """Шаблон без плейсхолдеров — возвращается как есть."""
    activity = _make_activity()
    result = _render_description_template("Фиксированный текст задачи", activity)
    assert result == "Фиксированный текст задачи"


def test_render_description_template_multiple():
    """Несколько плейсхолдеров одновременно."""
    activity = _make_activity(title="Тест", target_type="lead", target_id=99, responsible_id=3)
    result = _render_description_template(
        "{{ title }} / {{ target_type }}#{{ target_id }} → {{ responsible_id }}",
        activity,
    )
    assert result == "Тест / lead#99 → 3"


def test_render_description_template_empty():
    """Пустой шаблон → пустая строка."""
    activity = _make_activity()
    result = _render_description_template("", activity)
    assert result == ""


# ============ Status Machine Transitions ============


def test_status_machine_new_transitions():
    """Из 'new' можно перейти в in_progress или rejected."""
    from app.routers.activities import _STATUS_TRANSITIONS
    assert "in_progress" in _STATUS_TRANSITIONS["new"]
    assert "rejected" in _STATUS_TRANSITIONS["new"]
    assert "done" not in _STATUS_TRANSITIONS["new"]


def test_status_machine_in_progress_transitions():
    """Из 'in_progress' можно перейти в done, rejected, new."""
    from app.routers.activities import _STATUS_TRANSITIONS
    assert "done" in _STATUS_TRANSITIONS["in_progress"]
    assert "rejected" in _STATUS_TRANSITIONS["in_progress"]
    assert "new" in _STATUS_TRANSITIONS["in_progress"]


def test_status_machine_done_transitions():
    """Из 'done' можно вернуть только в in_progress."""
    from app.routers.activities import _STATUS_TRANSITIONS
    assert "in_progress" in _STATUS_TRANSITIONS["done"]
    assert "rejected" not in _STATUS_TRANSITIONS["done"]
    assert "new" not in _STATUS_TRANSITIONS["done"]


def test_status_machine_rejected_transitions():
    """Из 'rejected' можно вернуть только в new."""
    from app.routers.activities import _STATUS_TRANSITIONS
    assert "new" in _STATUS_TRANSITIONS["rejected"]
    assert "done" not in _STATUS_TRANSITIONS["rejected"]


def test_status_machine_all_statuses_have_transitions():
    """Каждый статус имеет хотя бы один разрешённый переход."""
    from app.routers.activities import _STATUS_TRANSITIONS
    for st in ACTIVITY_STATUSES:
        assert st in _STATUS_TRANSITIONS, f"Статус {st!r} отсутствует в _STATUS_TRANSITIONS"
        assert len(_STATUS_TRANSITIONS[st]) > 0, f"Статус {st!r} имеет пустое множество переходов"
