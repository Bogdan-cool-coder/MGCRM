"""Эпик 3 «Документооборот 2.0»: категории шаблонов, привязки, jinja-фильтры.

Pure-function без DB fixture. Тестируем:
- whitelist TEMPLATE_CATEGORIES;
- money_in_words / num_in_words filters (RU + EN + edge cases);
- default-значения новых JSON-полей Template (привязки) — `[]`.
"""

from __future__ import annotations

from decimal import Decimal

import pytest

from app.models import ApprovalRoute, Template
from app.services.render import (
    _money_in_words,
    _num_in_words,
    _build_jinja_env,
)
from app.services.templates import TEMPLATE_CATEGORIES, TEMPLATE_CATEGORY_LABELS


# ============ Whitelist категорий ============


def test_template_categories_whitelist_complete():
    """5 категорий точно соответствуют плану Эпика 3."""
    assert set(TEMPLATE_CATEGORIES) == {
        "sublicense_main",
        "addendum",
        "notice",
        "act",
        "cancellation",
    }


def test_template_category_labels_cover_all_codes():
    """Каждой категории соответствует человекочитаемое название (RU)."""
    for code in TEMPLATE_CATEGORIES:
        assert code in TEMPLATE_CATEGORY_LABELS
        assert TEMPLATE_CATEGORY_LABELS[code], f"Пустая метка для {code}"


# ============ money_in_words (RU) ============


def test_money_in_words_ru_round_thousand():
    """1000.00 → «одна тысяча рублей 00 копеек»."""
    assert _money_in_words(1000, lang="ru") == "одна тысяча рублей 00 копеек"


def test_money_in_words_ru_with_kopecks():
    """1234.56 → «одна тысяча двести тридцать четыре рублей 56 копеек».

    num2words(1234, ru) даёт «одна тысяча двести тридцать четыре» — суффикс ставим
    «рублей» (упрощённая форма без склонения, согласована в ТЗ Эпика 3).
    """
    result = _money_in_words(Decimal("1234.56"), lang="ru")
    assert result == "одна тысяча двести тридцать четыре рублей 56 копеек"


def test_money_in_words_ru_zero():
    """0 → «ноль рублей 00 копеек» (а не пустая строка — 0 это валидная сумма)."""
    result = _money_in_words(0, lang="ru")
    assert "рублей" in result
    assert "00 копеек" in result


def test_money_in_words_string_value_parsed():
    """Строка с числом тоже работает — практически контракт передаёт Decimal как строку."""
    assert _money_in_words("500.00", lang="ru") == "пятьсот рублей 00 копеек"


# ============ money_in_words edge cases ============


def test_money_in_words_empty_returns_empty_string():
    """None и пустая строка → пустая строка (черновики без суммы)."""
    assert _money_in_words(None) == ""
    assert _money_in_words("") == ""


def test_money_in_words_invalid_value_graceful():
    """Невалидный input не валит рендер — возвращает str(value)."""
    assert _money_in_words("not-a-number") == "not-a-number"
    assert _money_in_words("abc") == "abc"


def test_money_in_words_unknown_lang_falls_back_to_ru():
    """Неподдерживаемый язык → fallback на ru (graceful, не падаем)."""
    # 'xx' — заведомо отсутствующий код. num2words кинет NotImplementedError/LookupError.
    result = _money_in_words(100, lang="xx")
    # Не падает, и в результате есть валидное русское представление.
    assert "сто" in result.lower()
    assert "рублей" in result


# ============ money_in_words: округление копеек (баг аудита #4) ============


def test_money_in_words_no_100_kopecks_on_rounding():
    """Округление НЕ должно давать «100 копеек» — перенос в рубли обязателен.

    99.999 округляется до 100.00 → «сто рублей 00 копеек», а НЕ «99 рублей 100 копеек».
    """
    result = _money_in_words(Decimal("99.999"), lang="ru")
    assert "100 копеек" not in result
    assert result == "сто рублей 00 копеек"


def test_money_in_words_kopecks_always_in_range():
    """Дробная часть строго 0..99 для набора граничных значений (#4)."""
    for v in ["0.999", "1.005", "12.995", "100.999", "9999.999", "0.001"]:
        result = _money_in_words(Decimal(v), lang="ru")
        assert "100 копеек" not in result, f"перенос не сработал для {v}: {result}"


def test_money_in_words_half_up_rounding():
    """0.565 → 57 копеек (HALF_UP), 0.564 → 56 копеек."""
    assert _money_in_words(Decimal("0.565"), lang="ru") == "ноль рублей 57 копеек"
    assert _money_in_words(Decimal("0.564"), lang="ru") == "ноль рублей 56 копеек"


# ============ money_in_words: валюта (баг аудита #5) ============


def test_money_in_words_currency_kzt_overrides_lang():
    """currency=KZT даёт «тенге», даже когда lang=ru (валюта приоритетнее языка)."""
    result = _money_in_words(Decimal("1000.00"), lang="ru", currency="KZT")
    assert "тенге" in result
    assert "тиын" in result


def test_money_in_words_currency_usd():
    """currency=USD → доллары/центы независимо от lang."""
    result = _money_in_words(Decimal("1234.56"), lang="ru", currency="USD")
    assert "долларов США" in result
    assert "56 центов" in result


def test_money_in_words_currency_case_insensitive():
    """Код валюты регистронезависим."""
    assert "тенге" in _money_in_words(1000, lang="ru", currency="kzt")


def test_money_in_words_unknown_currency_falls_back_to_lang_suffix():
    """Неизвестный код валюты → суффикс по языку (graceful)."""
    result = _money_in_words(1000, lang="ru", currency="XXX")
    assert "рублей" in result


# ============ render_docx: дефолтная валюта в money_in_words фильтре (#5) ============


def test_build_jinja_env_default_currency_applied():
    """default_currency прокидывается в money_in_words, когда шаблон не задал валюту."""
    env = _build_jinja_env(default_currency="KZT")
    tpl = env.from_string("{{ amount | money_in_words }}")
    result = tpl.render(amount=Decimal("1000.00"))
    assert "тенге" in result


def test_build_jinja_env_explicit_currency_overrides_default():
    """Явный currency в шаблоне переопределяет default_currency."""
    env = _build_jinja_env(default_currency="KZT")
    tpl = env.from_string("{{ amount | money_in_words(currency='USD') }}")
    result = tpl.render(amount=Decimal("1000.00"))
    assert "долларов" in result


# ============ money_in_words EN ============


def test_money_in_words_en_uses_dollars():
    """EN: 1234.56 → «one thousand, two hundred and thirty-four dollars 56 cents»."""
    result = _money_in_words(Decimal("1234.56"), lang="en")
    assert "thousand" in result
    assert "dollars" in result
    assert "56 cents" in result


# ============ num_in_words ============


def test_num_in_words_basic_ru():
    """42 → «сорок два» (RU)."""
    assert _num_in_words(42, lang="ru") == "сорок два"


def test_num_in_words_basic_en():
    """42 → «forty-two» (EN)."""
    assert _num_in_words(42, lang="en") == "forty-two"


def test_num_in_words_empty():
    """Пустой / None → пустая строка."""
    assert _num_in_words(None) == ""
    assert _num_in_words("") == ""


def test_num_in_words_invalid_graceful():
    """Невалидный input → str(value)."""
    assert _num_in_words("abc") == "abc"


def test_num_in_words_decimal_rounds_to_int():
    """Decimal округляется к ближайшему целому (num_in_words — для целых чисел)."""
    # 1.7 → 2 (банковское округление по умолчанию у Decimal.to_integral_value).
    assert _num_in_words(Decimal("1.7"), lang="ru") == "два"


# ============ Jinja Environment ============


def test_jinja_env_has_both_filters_registered():
    """_build_jinja_env регистрирует оба фильтра — это контракт с docxtpl."""
    env = _build_jinja_env()
    assert "money_in_words" in env.filters
    assert "num_in_words" in env.filters


def test_jinja_filter_money_in_words_works_in_template():
    """E2E проверка: фильтр работает в jinja-шаблоне (не только как функция)."""
    env = _build_jinja_env()
    tpl = env.from_string("{{ amount | money_in_words }}")
    result = tpl.render(amount=Decimal("1000.00"))
    assert result == "одна тысяча рублей 00 копеек"


def test_jinja_filter_num_in_words_works_in_template():
    """E2E проверка: num_in_words фильтр работает в jinja-шаблоне."""
    env = _build_jinja_env()
    tpl = env.from_string("{{ n | num_in_words }}")
    assert tpl.render(n=5) == "пять"


# ============ Template bindings defaults ============


def test_template_to_dict_normalizes_none_bindings_to_empty_lists():
    """Сериализатор `_template_to_dict` приводит None-привязки к [] (для UI).

    SQLAlchemy default=list работает только после INSERT — до flush attribute=None.
    Сериализатор обязан вернуть [] чтобы фронт не падал на `tpl.product_codes.map(...)`.
    """
    from app.routers.templates import _template_to_dict

    t = Template(code="x", kind="docx", title="X", content="", version=1)
    # Имитируем «свежий» объект до flush — все JSON-поля None.
    t.product_codes = None  # type: ignore[assignment]
    t.country_codes = None  # type: ignore[assignment]
    t.client_category_codes = None  # type: ignore[assignment]
    t.department_ids = None  # type: ignore[assignment]
    t.category = None
    t.updated_at = None  # type: ignore[assignment]

    out = _template_to_dict(t)
    assert out["product_codes"] == []
    assert out["country_codes"] == []
    assert out["client_category_codes"] == []
    assert out["department_ids"] == []
    assert out["category"] is None


def test_approval_route_template_category_default_none():
    """Новое поле ApprovalRoute.template_category по умолчанию None (общий маршрут)."""
    r = ApprovalRoute(
        name="test",
        product_codes=["macrocrm"],
        country_codes=["kz"],
        approver_user_ids=[1],
    )
    assert r.template_category is None
