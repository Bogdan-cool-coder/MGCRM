"""Эпик 20 — Performance Scale: pure-function тесты для app/services/fts.py.

Покрывают:
- build_fts_query (sanitize, whitespace, спецсимволы, edge cases);
- needs_fallback (короткие query, спецсимволы only, пустые);
- _sanitize_lang (whitelist для tsvector config — anti-SQL injection);
- build_ilike_pattern (escape % _ \\, разумный cap).

apply_fts_filter — тестируем только что НЕ модифицирует stmt при fallback;
полноценный SQL-test требует БД (smoke-тестируется ручно).
"""
from __future__ import annotations

import pytest

from app.services.fts import (
    MAX_FTS_QUERY_LENGTH,
    MIN_FTS_QUERY_LENGTH,
    _sanitize_lang,
    apply_fts_filter,
    build_fts_query,
    build_ilike_pattern,
    needs_fallback,
)


# ============ build_fts_query ============


def test_build_fts_query_simple():
    """Простой текст возвращается as-is после strip."""
    assert build_fts_query("ромашка") == "ромашка"


def test_build_fts_query_strip():
    """Leading/trailing whitespace удаляется."""
    assert build_fts_query("  ромашка   ") == "ромашка"


def test_build_fts_query_empty():
    """Пустая строка → пустая."""
    assert build_fts_query("") == ""


def test_build_fts_query_whitespace_only():
    """Только пробелы → пустая."""
    assert build_fts_query("   ") == ""
    assert build_fts_query("\t\n") == ""


def test_build_fts_query_none_safe():
    """None не должен крашить (defensive)."""
    # type: ignore - намеренно проверяем что None не падает
    assert build_fts_query(None) == ""  # type: ignore[arg-type]


def test_build_fts_query_multiple_spaces_compressed():
    """Множественные пробелы сжимаются в один."""
    assert build_fts_query("ромашка     ООО") == "ромашка ООО"
    assert build_fts_query("a  b   c") == "a b c"


def test_build_fts_query_removes_tsquery_bad_chars():
    """Опасные tsquery символы убираются."""
    # < > { } [ ] ^ ~ ` *
    assert build_fts_query("ромашка<test>") == "ромашка test"
    assert build_fts_query("a^b") == "a b"
    assert build_fts_query("a*b") == "a b"
    assert build_fts_query("a~b") == "a b"


def test_build_fts_query_preserves_safe_chars():
    """Дефис, точка, @ остаются — это нормально для plainto_tsquery."""
    assert build_fts_query("e@example.com") == "e@example.com"
    assert build_fts_query("ИП-123") == "ИП-123"
    # plainto_tsquery сама экранирует & | ! — не убираем заранее
    assert build_fts_query("a&b") == "a&b"


def test_build_fts_query_max_length_cap():
    """Сверхдлинная query обрезается до MAX_FTS_QUERY_LENGTH."""
    long = "x" * 500
    result = build_fts_query(long)
    assert len(result) == MAX_FTS_QUERY_LENGTH


def test_build_fts_query_only_special_chars_returns_empty():
    """Строка из одних спецсимволов сэнитизируется в пустоту."""
    assert build_fts_query("<<<>>>") == ""
    assert build_fts_query("^^^***") == ""


def test_build_fts_query_russian_morphology_chars():
    """Русский текст без потерь."""
    assert build_fts_query("Бухгалтер ООО Ромашка") == "Бухгалтер ООО Ромашка"


def test_build_fts_query_mixed_lang():
    """Mixed RU+EN+digits."""
    assert build_fts_query("ИП Vasily-2024") == "ИП Vasily-2024"


# ============ needs_fallback ============


def test_needs_fallback_empty():
    """Пустая query → fallback нужен."""
    assert needs_fallback("") is True
    assert needs_fallback("   ") is True


def test_needs_fallback_too_short():
    """Query короче MIN_FTS_QUERY_LENGTH (3) → fallback."""
    assert needs_fallback("a") is True
    assert needs_fallback("ab") is True
    # Граничное значение — 3 символа OK для FTS
    assert needs_fallback("abc") is False


def test_needs_fallback_only_special_chars():
    """Только спецсимволы (без \\w) → fallback."""
    # Спецсимволы убираются до пустоты build_fts_query'м.
    assert needs_fallback("<<<>>>") is True
    # Точки/тире сами по себе без алфанума → fallback
    assert needs_fallback("---") is True
    assert needs_fallback("...") is True


def test_needs_fallback_normal_query():
    """Нормальная query → НЕ fallback (FTS подойдёт)."""
    assert needs_fallback("ромашка") is False
    assert needs_fallback("a@b.com") is False
    assert needs_fallback("ИП Иванов") is False


def test_needs_fallback_3_char_threshold():
    """Ровно 3 символа — порог: FTS OK."""
    assert needs_fallback("abc") is False
    assert needs_fallback("123") is False
    assert needs_fallback("абв") is False


# ============ _sanitize_lang ============


def test_sanitize_lang_whitelist():
    """Допустимые lang возвращаются as-is."""
    assert _sanitize_lang("russian") == "russian"
    assert _sanitize_lang("english") == "english"
    assert _sanitize_lang("simple") == "simple"


def test_sanitize_lang_unknown_falls_to_simple():
    """Любой другой lang → simple (защита от injection)."""
    assert _sanitize_lang("french") == "simple"
    assert _sanitize_lang("'; DROP TABLE users; --") == "simple"
    assert _sanitize_lang("") == "simple"
    assert _sanitize_lang("RUSSIAN") == "simple"  # case-sensitive whitelist


# ============ build_ilike_pattern ============


def test_build_ilike_pattern_basic():
    """Простая строка оборачивается в %...%."""
    assert build_ilike_pattern("ромашка") == "%ромашка%"


def test_build_ilike_pattern_escape_percent():
    """% → \\% в pattern."""
    assert build_ilike_pattern("100%") == "%100\\%%"


def test_build_ilike_pattern_escape_underscore():
    """_ → \\_ (иначе ILIKE считает single char)."""
    assert build_ilike_pattern("user_name") == "%user\\_name%"


def test_build_ilike_pattern_escape_backslash():
    """Backslash escape."""
    assert build_ilike_pattern("a\\b") == "%a\\\\b%"


def test_build_ilike_pattern_empty():
    """Пустая query → '%' (матчит всё; caller сам решает что делать)."""
    assert build_ilike_pattern("") == "%"
    assert build_ilike_pattern("   ") == "%"


def test_build_ilike_pattern_max_length():
    """Длинная query обрезается до MAX_FTS_QUERY_LENGTH."""
    long = "a" * 500
    result = build_ilike_pattern(long)
    # +2 за %...%, escape не задействован для plain 'a'
    assert len(result) == MAX_FTS_QUERY_LENGTH + 2


# ============ apply_fts_filter (smoke) ============


def _compile_pg(stmt) -> str:
    """Compile с postgres-dialect (без literal_binds — у regconfig нет renderer'а).

    Возвращает SQL-текст с :param плейсхолдерами. Этого достаточно для
    smoke-проверок наличия фрагментов (search_vector / @@ / plainto_tsquery).
    """
    from sqlalchemy.dialects import postgresql
    return str(stmt.compile(dialect=postgresql.dialect()))


def test_apply_fts_filter_skip_on_short_query():
    """Если query <3 chars → stmt НЕ модифицируется (caller сам делает ILIKE)."""
    from sqlalchemy import select
    from app.models import Counterparty
    base_stmt = select(Counterparty)
    result = apply_fts_filter(base_stmt, Counterparty, "ab")
    sql_before = _compile_pg(base_stmt)
    sql_after = _compile_pg(result)
    assert sql_before == sql_after


def test_apply_fts_filter_skip_on_empty_query():
    """Пустая query → stmt без изменений."""
    from sqlalchemy import select
    from app.models import Lead
    base = select(Lead)
    result = apply_fts_filter(base, Lead, "")
    sql_before = _compile_pg(base)
    sql_after = _compile_pg(result)
    assert sql_before == sql_after


def test_apply_fts_filter_modifies_stmt_on_valid_query():
    """Валидная query → stmt получает WHERE и ORDER BY."""
    from sqlalchemy import select
    from app.models import Counterparty
    base = select(Counterparty)
    result = apply_fts_filter(base, Counterparty, "ромашка")
    sql = _compile_pg(result).lower()
    assert "search_vector" in sql
    assert "@@" in sql
    assert "plainto_tsquery" in sql
    assert "ts_rank" in sql  # default rank_order=True


def test_apply_fts_filter_skip_rank_order():
    """rank_order=False → нет ORDER BY ts_rank, только WHERE."""
    from sqlalchemy import select
    from app.models import Lead
    base = select(Lead)
    result = apply_fts_filter(base, Lead, "тестовая компания", rank_order=False)
    sql = _compile_pg(result).lower()
    assert "search_vector" in sql
    assert "ts_rank" not in sql


def test_apply_fts_filter_sanitize_lang_unsafe_input():
    """Если в lang передан мусор — sanitize в 'simple' (anti-injection).

    Проверяем через params в bound query (не SQL текст — regconfig — это
    bound param, не литерал, поэтому сам по себе SQL containing 'simple'
    не покажет).
    """
    from sqlalchemy import select
    from app.models import Counterparty
    base = select(Counterparty)
    result = apply_fts_filter(
        base, Counterparty, "ромашка", lang="'; DROP TABLE users; --",
    )
    # Compile → проверим, что bound params содержат 'simple', НЕ DROP TABLE.
    compiled = result.compile(
        dialect=__import__("sqlalchemy.dialects.postgresql",
                           fromlist=["dialect"]).dialect()
    )
    params = compiled.params or {}
    # Ни в одном параметре не должно быть 'DROP TABLE'.
    for v in params.values():
        assert "DROP TABLE" not in str(v), f"injection leaked: {v!r}"
    # И хотя бы один param == 'simple' (lang param).
    assert any(v == "simple" for v in params.values()), (
        f"Expected lang='simple' in params; got {params}"
    )


# ============ Boundary integration: build_fts_query → needs_fallback ============


def test_build_then_fallback_coherence():
    """Если build_fts_query вернула пусто — needs_fallback должна вернуть True."""
    for q in ("", "   ", "<<<", "***", "^^^"):
        assert needs_fallback(q) is True, f"failed for q={q!r}"


def test_min_query_length_constant_sanity():
    """Sanity: константа имеет смысл (>=2 чтобы хоть какая-то польза)."""
    assert MIN_FTS_QUERY_LENGTH >= 2


def test_max_query_length_reasonable():
    """Sanity: max length не безумный (anti-DoS)."""
    assert 50 <= MAX_FTS_QUERY_LENGTH <= 1000
