"""build_custom_context — сборка namespace {{ custom.* }} из значений формы."""

from app.models import TemplateVariable, TemplateVariableType
from app.services.templates import build_custom_context


def _var(key, var_type=TemplateVariableType.text, default_value=None):
    return TemplateVariable(key=key, var_type=var_type, default_value=default_value)


def test_checkbox_maps_to_da_net():
    variables = [_var("flag", TemplateVariableType.checkbox)]
    assert build_custom_context(variables, {"flag": True})["flag"] == "Да"
    assert build_custom_context(variables, {"flag": "да"})["flag"] == "Да"
    assert build_custom_context(variables, {"flag": False})["flag"] == "Нет"
    assert build_custom_context(variables, {})["flag"] == "Нет"


def test_checkbox_default_applies_when_empty():
    variables = [_var("flag", TemplateVariableType.checkbox, default_value="true")]
    assert build_custom_context(variables, {})["flag"] == "Да"


def test_text_default_applies_when_blank():
    variables = [_var("term", TemplateVariableType.text, default_value="12 мес")]
    assert build_custom_context(variables, {"term": ""})["term"] == "12 мес"
    assert build_custom_context(variables, {"term": "24 мес"})["term"] == "24 мес"


def test_text_blank_without_default_is_empty():
    variables = [_var("term", TemplateVariableType.text)]
    assert build_custom_context(variables, {})["term"] == ""


def test_only_defined_variables_kept():
    variables = [_var("a")]
    out = build_custom_context(variables, {"a": "x", "ghost": "drop me"})
    assert out == {"a": "x"}
