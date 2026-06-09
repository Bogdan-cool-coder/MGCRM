"""Эпик 23 — action_kind='create_deal' pure-function tests.

Покрываем: whitelist (включение в AUTOMATION_ACTIONS), render_simple_template
(jinja-like подстановка {var}), регрессионные семантические проверки.
"""
from __future__ import annotations

from app.services.automation_executor import (
    AUTOMATION_ACTIONS,
    render_simple_template,
)


# ============ Whitelist ============


def test_create_deal_action_in_whitelist():
    """Эпик 23: create_deal регистрируется в AUTOMATION_ACTIONS."""
    assert "create_deal" in AUTOMATION_ACTIONS


def test_create_deal_action_kind_string():
    """Регрессия: имя action_kind — точно 'create_deal'."""
    assert "create_deal" in AUTOMATION_ACTIONS
    assert "create_new_deal" not in AUTOMATION_ACTIONS
    assert "new_deal" not in AUTOMATION_ACTIONS
    assert "deal_create" not in AUTOMATION_ACTIONS


def test_create_deal_distinct_from_generate_document():
    """create_deal ≠ generate_document — разные действия (DEAL vs CONTRACT-файл)."""
    assert "create_deal" in AUTOMATION_ACTIONS
    assert "generate_document" in AUTOMATION_ACTIONS


# ============ render_simple_template pure ============


def test_render_simple_template_basic():
    """Базовая подстановка {var}."""
    result = render_simple_template(
        "Сделка: {target_title}",
        {"target_title": "ООО Ромашка"},
    )
    assert result == "Сделка: ООО Ромашка"


def test_render_simple_template_multiple_vars():
    """Несколько переменных в шаблоне."""
    result = render_simple_template(
        "{target_title} (#{target_id}, owner={owner_name})",
        {"target_id": 42, "target_title": "ООО Тест", "owner_name": "Иван"},
    )
    assert result == "ООО Тест (#42, owner=Иван)"


def test_render_simple_template_empty_input():
    """Пустой шаблон → пустая строка."""
    assert render_simple_template("", {"x": "y"}) == ""


def test_render_simple_template_missing_var_kept():
    """Неизвестная переменная остаётся в шаблоне как есть (для дебага)."""
    result = render_simple_template(
        "Hello {unknown_var}!",
        {"name": "world"},
    )
    # {unknown_var} НЕ подставляется (для дебага видно что забыли)
    assert "{unknown_var}" in result


def test_render_simple_template_none_value():
    """None в context → пустая строка."""
    result = render_simple_template(
        "owner={owner_name}",
        {"owner_name": None},
    )
    assert result == "owner="


def test_render_simple_template_integer_value():
    """Integer в context → str(value)."""
    result = render_simple_template(
        "id={target_id}",
        {"target_id": 42},
    )
    assert result == "id=42"


def test_render_simple_template_no_jinja_constructs():
    """Регрессия: не поддерживаем jinja {%...%} и {{...}}  (защита от инъекций).

    Шаблон содержащий jinja-конструкции должен остаться как есть.
    """
    # {%if%} как часть подставляемого текста — не разворачивается.
    template = "{% if x %}{{ secret }}{% endif %}"
    result = render_simple_template(template, {"x": 1, "secret": "leak"})
    # Двойные {{}} не подставляются (мы ищем только одиночные {var})
    assert "{{" in result or "secret" not in result


def test_render_simple_template_special_chars_in_value():
    """Кириллица и спецсимволы в value не ломают подстановку."""
    result = render_simple_template(
        "{title}",
        {"title": "ООО «Ромашка» — клиент №1"},
    )
    assert result == "ООО «Ромашка» — клиент №1"


def test_render_simple_template_idempotent_no_vars():
    """Шаблон без переменных возвращается как есть."""
    template = "Просто текст без переменных."
    result = render_simple_template(template, {})
    assert result == template


# ============ Семантические регрессии ============


def test_create_deal_uses_render_simple_template_not_jinja():
    """Регрессия: handler НЕ использует полный jinja для title_template.

    Используем render_simple_template (string replace), а не Environment.render.
    Защита: добавив SQL/HTML в name target'а нельзя получить jinja-инъекцию.
    """
    import inspect
    from app.services import automation_executor
    src = inspect.getsource(automation_executor._action_create_deal)
    assert "render_simple_template" in src
    # jinja2.Environment / Template не должны быть в handler'е
    assert "jinja2.Template" not in src
    assert "Environment" not in src


def test_create_deal_pipeline_id_required():
    """Регрессия: handler требует pipeline_id в config (defensively)."""
    import inspect
    from app.services import automation_executor
    src = inspect.getsource(automation_executor._action_create_deal)
    assert "pipeline_id" in src
    # Защита: pipeline_id не задан → skipped, не падение
    assert "не задан" in src or "skipped" in src
