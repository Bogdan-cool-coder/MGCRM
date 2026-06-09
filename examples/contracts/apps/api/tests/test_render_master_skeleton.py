"""Регрессионный рендер РЕАЛЬНОГО master_skeleton.docx (баг аудита #1).

До фикса SafeUndefined переопределял только `__str__`, поэтому доступ к атрибуту
ОТСУТСТВУЮЩЕГО top-level namespace ({{ license.number }} при отсутствующем
`license`) бросал jinja2.UndefinedError → 500 на 100% bulk-целей и в preview.

Эти тесты рендерят настоящий шаблон с НАМЕРЕННО урезанным контекстом (без
`license`, без вложенных атрибутов) и проверяют, что рендер не падает. Раньше
такого теста не было, поэтому C1/C2 проскочили.

Pure-function: docxtpl render → save во временный каталог. LibreOffice НЕ нужен
(PDF-конверсию здесь не делаем). Если docx недоступен (минимальный CI без
templates/) — skip.
"""

from __future__ import annotations

from pathlib import Path

import pytest
from docxtpl import DocxTemplate

from app.services.render import SafeUndefined, _build_jinja_env, render_docx


def _master_skeleton_path() -> Path | None:
    """Локатор реального master_skeleton.docx (repo root, не под apps/api)."""
    here = Path(__file__).resolve()
    for n in range(2, 6):
        cand = here.parents[n] / "templates" / "contracts_master" / "master_skeleton.docx"
        if cand.exists():
            return cand
    return None


# ============ SafeUndefined: chainable, не бросает ============


def test_safe_undefined_attribute_access_does_not_raise():
    """Доступ к атрибуту отсутствующего namespace → снова SafeUndefined, не ошибка."""
    env = _build_jinja_env()
    # license отсутствует целиком; раньше это бросало UndefinedError.
    tpl = env.from_string("{{ license.number }} / {{ license.payment_schedule }}")
    assert tpl.render() == "_____ / _____"


def test_safe_undefined_deep_chain_does_not_raise():
    """Глубокая цепочка по отсутствующим namespace тоже безопасна."""
    env = _build_jinja_env()
    tpl = env.from_string("{{ a.b.c.d.e }}")
    assert tpl.render() == "_____"


def test_safe_undefined_iteration_yields_empty():
    """Итерация по отсутствующей коллекции → пусто (цикл не падает)."""
    env = _build_jinja_env()
    tpl = env.from_string("{% for p in license.payment_schedule %}X{% endfor %}DONE")
    assert tpl.render() == "DONE"


def test_safe_undefined_indexing_does_not_raise():
    """Индексация отсутствующего → SafeUndefined."""
    env = _build_jinja_env()
    tpl = env.from_string("{{ items[0].name }}")
    assert tpl.render() == "_____"


def test_safe_undefined_is_instance_returned_on_attr():
    """__getattr__ возвращает SafeUndefined (chainable инвариант)."""
    u = SafeUndefined(name="x")
    assert isinstance(u.anything, SafeUndefined)
    assert isinstance(u["k"], SafeUndefined)
    assert list(u) == []
    assert bool(u) is False
    assert str(u) == "_____"


# ============ Рендер реального master_skeleton.docx с урезанным контекстом ============


def test_render_real_master_skeleton_with_missing_namespaces(tmp_path):
    """РЕАЛЬНЫЙ шаблон рендерится даже когда top-level namespace'ы отсутствуют.

    Передаём ПУСТЫЕ product/country/licensor и contract_data без `license` —
    воспроизводит контекст, на котором bulk/preview падали 500.
    """
    docx = _master_skeleton_path()
    if docx is None:
        pytest.skip("master_skeleton.docx недоступен в этой среде")

    out = tmp_path / "out.docx"
    # Намеренно минимальный контекст: нет license, product/country/licensor пусты.
    render_docx(
        template_path=docx,
        product={},
        country={},
        licensor={},
        contract_data={"contract": {"number": "TEST-1"}},
        output_path=out,
    )
    assert out.exists()
    # Файл валидно открывается как docx.
    DocxTemplate(str(out))


def test_render_real_master_skeleton_with_full_context(tmp_path):
    """РЕАЛЬНЫЙ шаблон рендерится и с заполненным license/графиками."""
    docx = _master_skeleton_path()
    if docx is None:
        pytest.skip("master_skeleton.docx недоступен в этой среде")

    out = tmp_path / "out_full.docx"
    contract_data = {
        "contract": {"number": "TEST-2", "city": "Алматы", "currency": "KZT"},
        "sublicensee": {"name": "ТОО «Тест»"},
        "license": {
            "type": "коробочная",
            "payment_schedule": [
                {"date": "01.07.2026", "amount": "500 000.00"},
            ],
            "act_schedule": [{"date": "01.08.2026", "name": "акт"}],
        },
        "custom": {},
    }
    render_docx(
        template_path=docx,
        product={"name": "MACRO CRM"},
        country={"name_short": "Казахстан", "currency_code": "KZT"},
        licensor={"name": "MACRO Global"},
        contract_data=contract_data,
        output_path=out,
    )
    assert out.exists()
    DocxTemplate(str(out))
