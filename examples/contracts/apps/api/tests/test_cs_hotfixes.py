"""Pure-function тесты CS hotfixes (Эпик 0 / Блок B, май 2026).

Покрывает:
- renewal dedup ключ: Deal.extra_fields["renewal_subscription_id"] — что pattern
  совпадает с тем, что пишет scan_subscriptions_for_renewal.
- on_premise_attention(...) — pure-function: какие комбинации on_premise +
  manual_tier_override триггерят warning.
- REGION_BY_COUNTRY / PLATFORM_BY_PRODUCT_CODE — таблицы маппингов,
  пограничные кейсы (KZ→ca, UAE→gcc, unknown country → None).

Тесты НЕ касаются БД — все pure-function.
"""
from __future__ import annotations

from app.services.customer_success import (
    PLATFORM_BY_PRODUCT_CODE,
    REGION_BY_COUNTRY,
    _module_matches,
    _tokens,
    on_premise_attention,
)


# ============ Hotfix #1: renewal dedup ключ ============


def test_renewal_dedup_field_name_stable():
    """Контракт между scan_subscriptions_for_renewal и dedup-запросом:
    `Deal.extra_fields["renewal_subscription_id"]` — единое имя ключа.

    Если кто-то переименует ключ в renewal.py, dedup сломается, и cron
    наплодит дубли. Этот тест ловит регрессию.
    """
    # Импортируем сам сервис, чтобы проверить что код использует именно эту
    # строку (читаем исходник).
    import inspect

    from app.services import renewal
    src = inspect.getsource(renewal.scan_subscriptions_for_renewal)
    # Проверка: ключ упоминается ровно как 'renewal_subscription_id' и в
    # WHERE-проверке, и в создании Deal.
    assert '"renewal_subscription_id"' in src or "'renewal_subscription_id'" in src
    # Проверка наличия логирования skip
    assert "already has recent renewal deal" in src


def test_renewal_dedup_window_unchanged():
    """Окно дедупа — 60 дней (фиксированный продакт-параметр)."""
    from app.services.renewal import _RENEWAL_DEDUP_WINDOW_DAYS
    assert _RENEWAL_DEDUP_WINDOW_DAYS == 60


def test_renewal_lookahead_default_unchanged():
    """Lookahead для discount_until — 30 дней по умолчанию."""
    from app.services.renewal import DEFAULT_RENEWAL_LOOKAHEAD_DAYS
    assert DEFAULT_RENEWAL_LOOKAHEAD_DAYS == 30


# ============ Hotfix #2: REGION_BY_COUNTRY mapping ============


def test_region_by_country_central_asia():
    """Страны ЦА мапятся на регион 'ca'."""
    assert REGION_BY_COUNTRY["kz"] == "ca"
    assert REGION_BY_COUNTRY["uz"] == "ca"
    assert REGION_BY_COUNTRY["kg"] == "ca"
    assert REGION_BY_COUNTRY["tj"] == "ca"
    assert REGION_BY_COUNTRY["tm"] == "ca"


def test_region_by_country_gcc():
    """Страны GCC мапятся на 'gcc'."""
    assert REGION_BY_COUNTRY["ae"] == "gcc"
    assert REGION_BY_COUNTRY["sa"] == "gcc"
    assert REGION_BY_COUNTRY["qa"] == "gcc"
    assert REGION_BY_COUNTRY["kw"] == "gcc"
    assert REGION_BY_COUNTRY["bh"] == "gcc"
    assert REGION_BY_COUNTRY["om"] == "gcc"


def test_region_by_country_caucasus():
    """Кавказ — 'caucasus'."""
    assert REGION_BY_COUNTRY["ge"] == "caucasus"
    assert REGION_BY_COUNTRY["am"] == "caucasus"
    assert REGION_BY_COUNTRY["az"] == "caucasus"


def test_region_by_country_unknown_returns_none():
    """Неизвестная страна → нет региона (fallback на legacy-поиск с warning)."""
    assert REGION_BY_COUNTRY.get("us") is None
    assert REGION_BY_COUNTRY.get("") is None
    assert REGION_BY_COUNTRY.get("xx") is None


def test_region_by_country_ru():
    """CS-hotfix (0080): РФ → отдельный регион 'ru' (раньше падал в None)."""
    assert REGION_BY_COUNTRY["ru"] == "ru"


def test_platform_by_product_code():
    """Mapping продукт → платформа: macrocrm и macrosales → macrosales, macroerp → macroerp."""
    assert PLATFORM_BY_PRODUCT_CODE["macrosales"] == "macrosales"
    assert PLATFORM_BY_PRODUCT_CODE["macrocrm"] == "macrosales"  # MacroCRM = линейка Sales
    assert PLATFORM_BY_PRODUCT_CODE["macroerp"] == "macroerp"


def test_platform_by_product_code_unknown_returns_none():
    """Неизвестный продукт → None (ensure_subscription_from_contract вернёт None без ошибки)."""
    assert PLATFORM_BY_PRODUCT_CODE.get("macrounknown") is None
    assert PLATFORM_BY_PRODUCT_CODE.get("") is None


# ============ Hotfix #3: on_premise warning ============


def test_on_premise_attention_no_premise():
    """Cloud client (on_premise=False) — без attention независимо от override."""
    assert on_premise_attention(on_premise=False, manual_tier_override=None) is False
    assert on_premise_attention(on_premise=False, manual_tier_override="A3") is False


def test_on_premise_attention_with_override():
    """on_premise + manual_override → НЕ требует внимания (ТП явно зафиксировал тир)."""
    assert on_premise_attention(on_premise=True, manual_tier_override="A2") is False
    assert on_premise_attention(on_premise=True, manual_tier_override="A6") is False
    # Любой непустой override снимает флаг
    assert on_premise_attention(on_premise=True, manual_tier_override="anything") is False


def test_on_premise_attention_no_override_triggers():
    """on_premise + нет manual_override → требует внимания (ключевой кейс hotfix)."""
    assert on_premise_attention(on_premise=True, manual_tier_override=None) is True
    assert on_premise_attention(on_premise=True, manual_tier_override="") is True


def test_on_premise_attention_handles_truthy_false_pairs():
    """Проверка edge-кейсов с типами (bool=False vs None vs '')."""
    # Все варианты «no premise» дают False
    assert on_premise_attention(False, None) is False
    assert on_premise_attention(False, "") is False
    assert on_premise_attention(False, "A1") is False
    # Все варианты «premise + override» дают False
    assert on_premise_attention(True, "X") is False
    # Только premise + пустой override дают True
    assert on_premise_attention(True, None) is True


# ============ Verification: on_premise_no_override included in reasons ============


def test_on_premise_reason_constant_for_frontend():
    """Имя константы reason'а зафиксировано для frontend rendering.

    Frontend читает health_reasons[] и рендерит локализованную надпись по
    каждому коду. Если поменяем 'on_premise_no_override' — нужно синхронизировать
    с frontend (apps/web/lib/api/types.ts + UI dictionary).
    """
    # Проверяем что строка живёт в исходнике recompute_subscription_health
    import inspect

    from app.services import customer_success
    src = inspect.getsource(customer_success.recompute_subscription_health)
    assert '"on_premise_no_override"' in src


# ============ ensure_subscription_from_contract — поиск с region_id ============


def test_ensure_sub_searches_with_region_when_available():
    """Контракт: ensure_subscription_from_contract фильтрует по тройке
    (cp, platform, region) если region определён.

    Это исходник-чек (не behavioural), потому что нет БД. Проверяем что в
    исходнике явно есть `region_id ==` в success-ветке.
    """
    import inspect

    from app.services import customer_success
    src = inspect.getsource(customer_success.ensure_subscription_from_contract)
    # Должно быть упоминание region_id в WHERE с проверкой == region.id
    assert "region_id == region.id" in src
    # И fallback-ветка с логом warning (CONTACTS 2.0 Ф3-B: текст изменён)
    assert "falling back to" in src


# ============ #7: эвристика модулей без ложных включений ============


def _itm(text: str) -> tuple[set[str], str]:
    """Хелпер: (токены, норм-строка) позиции договора, как в _enable_modules_from_contract."""
    from app.services.customer_success import _norm
    return _tokens(text), _norm(text)


def test_module_code_matches_only_as_token():
    """Короткий code ('1c'/'crm') матчится ТОЛЬКО как отдельный токен, не подстрокой."""
    toks, nrm = _itm("Интеграция с 1C и выгрузка")  # 1C латиницей, как в коде модуля
    assert _module_matches("1C", "1c", toks, nrm) is True
    # 'crm' внутри слитного слова не должен ловиться
    toks2, nrm2 = _itm("microcrmexport бандл")
    assert _module_matches("CRM", "crm", toks2, nrm2) is False
    # а как отдельное слово — ловится
    toks3, nrm3 = _itm("Модуль CRM для отдела продаж")
    assert _module_matches("CRM", "crm", toks3, nrm3) is True


def test_module_short_erp_no_false_positive():
    """'erp' не матчится внутри 'superpower' и подобного шума."""
    toks, nrm = _itm("Лицензия superpower analytics")
    assert _module_matches("ERP", "erp", toks, nrm) is False


def test_module_name_matches_by_long_substring():
    """Длинное имя модуля (>=4 символа) матчится подстрокой норм-текста."""
    toks, nrm = _itm("Подключение MacroDATA к контуру")
    assert _module_matches("MacroDATA", "macrodata", toks, nrm) is True


def test_module_name_multiword_token_match():
    """Многословное имя матчится, если все слова присутствуют отдельными токенами."""
    toks, nrm = _itm("Кабинет агента — настройка доступа")
    assert _module_matches("Кабинет агента", "agent_cabinet", toks, nrm) is True
    # частичное вхождение (только одно слово) — не матч по token-subset,
    # и norm 'кабинетагента' не входит в 'кабинетклиента' → False
    toks2, nrm2 = _itm("Кабинет клиента")
    assert _module_matches("Кабинет агента", "agent_cabinet", toks2, nrm2) is False
