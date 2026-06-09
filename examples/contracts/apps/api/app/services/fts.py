"""Эпик 20 — Performance Scale: Postgres FTS helpers.

PostgreSQL full-text search через `tsvector @@ tsquery`. Базовая идея:
search_vector колонка (generated column, см. migration 0039) хранит
weighted tsvector от полей (name/email/phone/...). На каждый поисковый
запрос формируем `plainto_tsquery('russian', :q)` и фильтруем + сортируем
по `ts_rank(search_vector, query) DESC`.

Преимущества vs старого ILIKE:
- индекс GIN на search_vector → ms-уровень даже на 100k+ записях;
- морфология русского ('бухгалтер' матчит 'бухгалтером');
- weighted ranking (имя релевантнее notes);
- normalization (case-insensitive, knows о punctuation).

Ограничения:
- plainto_tsquery дробит query на токены и ставит AND между ними;
- короткие префиксы (1-2 символа), специальные символы — fallback на ILIKE;
- мы используем 'russian' конфиг для большинства полей и 'simple' для
  technical ID'ов (number, tax_id).

Public API:
- build_fts_query(q, lang='russian') → str — sanitize + compress whitespace
  → строка для передачи в plainto_tsquery().
- needs_fallback(q) → bool — короткая query или только спецсимволы → ILIKE.
- apply_fts_filter(stmt, model, q, lang='russian') → stmt — добавляет
  `WHERE model.search_vector @@ plainto_tsquery('russian', :q)` и
  ORDER BY ts_rank(...) DESC.

Все функции pure — БД-сессия не нужна, можно покрывать unit-тестами без БД.
"""
from __future__ import annotations

import re
from typing import Any

from sqlalchemy import Column, desc, func, table
from sqlalchemy.dialects.postgresql import TSVECTOR
from sqlalchemy.sql import Select

# Минимальная длина query — короче этого FTS малоэффективен (1-2 char prefix
# даёт огромный outer join). Caller должен fallback'нуть на ILIKE.
MIN_FTS_QUERY_LENGTH = 3

# Максимальная длина query — защита от слишком больших pattern'ов / DoS.
MAX_FTS_QUERY_LENGTH = 200

# Множественные пробелы → один.
_WS_RE = re.compile(r"\s+")

# Символы, которые могут сломать plainto_tsquery или ts_query parser.
# plainto_tsquery() сам экранирует & | ! ( ) — НО лучше убрать заранее,
# чтобы query из 1 символа `&` не превращалось в пустой ts_query.
_TSQUERY_BAD_CHARS_RE = re.compile(r"[<>{}\[\]^~`*]")

# Допустимые конфиги tsvector (для защиты от SQL injection в lang).
# Если callee передал lang не из этого whitelist — fallback на 'simple'.
_ALLOWED_TS_CONFIGS: frozenset[str] = frozenset(
    {"russian", "english", "simple"}
)


def _sanitize_lang(lang: str) -> str:
    """Нормализовать lang в whitelist; иначе 'simple' (безопасно для tsvector)."""
    if lang in _ALLOWED_TS_CONFIGS:
        return lang
    return "simple"


def build_fts_query(q: str, lang: str = "russian") -> str:
    """Sanitize input для передачи в plainto_tsquery('lang', :q).

    Что делаем:
    - strip (удалить leading/trailing whitespace);
    - убрать опасные tsquery operator символы (< > { } [ ] ^ ~ ` *);
    - сжать множественные пробелы в один;
    - обрезать до MAX_FTS_QUERY_LENGTH.

    Что НЕ делаем:
    - escape & | ! — plainto_tsquery() обрабатывает их сама как plain text
      (это и есть «plain» в названии: 'foo & bar' → 'foo:* & bar:*' что
      эквивалентно AND поиску).

    Возвращает sanitized string — пустую если q невалидно (пусто после
    strip / только бэд-символы). Caller должен проверить needs_fallback()
    перед использованием.

    Note: lang здесь не используется — он для tsvector config в SQL,
    sanitize у нас одинаков для всех языков. Аргумент оставлен для
    симметрии API (callee может валидировать lang отдельно).
    """
    if not q:
        return ""
    s = q.strip()
    if not s:
        return ""
    s = _TSQUERY_BAD_CHARS_RE.sub(" ", s)
    s = _WS_RE.sub(" ", s).strip()
    if not s:
        return ""
    return s[:MAX_FTS_QUERY_LENGTH]


def needs_fallback(q: str) -> bool:
    """True если query слишком короткая / искажённая → лучше ILIKE а не FTS.

    Критерии fallback:
    - q пустая или whitespace-only;
    - после sanitize длина < MIN_FTS_QUERY_LENGTH;
    - q состоит только из неалфанумерических символов
      (например, '@@', '!!!'). В этом случае plainto_tsquery вернёт
      пустой tsquery, который никогда не матчит — лучше ILIKE.
    """
    sanitized = build_fts_query(q)
    if not sanitized:
        return True
    if len(sanitized) < MIN_FTS_QUERY_LENGTH:
        return True
    # Проверим что есть хотя бы один буквенно-числовой символ (включая
    # русские буквы — \w в Python включает Unicode letters/digits).
    if not re.search(r"\w", sanitized):
        return True
    return False


def apply_fts_filter(
    stmt: Select[Any],
    model: type,
    q: str,
    *,
    lang: str = "russian",
    rank_order: bool = True,
) -> Select[Any]:
    """Добавить FTS WHERE + (опц.) ORDER BY ts_rank к existing select.

    Применяет:
      WHERE model.search_vector @@ plainto_tsquery('russian', :q)
      ORDER BY ts_rank(model.search_vector, plainto_tsquery('russian', :q)) DESC

    Параметры:
    - stmt: уже построенный select (with from_clause, prior filters, etc).
    - model: SQLAlchemy ORM-модель (Counterparty, Lead, ...). Должен иметь
      `search_vector` атрибут (т.е. колонка обязана быть в БД — миграция 0039).
    - q: search query (raw, до sanitize).
    - lang: tsvector config ('russian' default; 'simple' для technical IDs).
    - rank_order: если True — добавляет ORDER BY ts_rank DESC. Если False —
      caller сам выбирает сортировку (например, по updated_at DESC).

    Возвращает модифицированный stmt. Если q пустая / fallback нужен —
    возвращает stmt БЕЗ модификаций (caller сам решит что делать —
    может вызвать ILIKE отдельно).

    NB: Не проверяет наличие search_vector у модели в runtime — это
    AttributeError если попытаться вызвать с моделью без FTS, и это норма
    (быстро fail на старте, а не silent return стат пустых).
    """
    sanitized = build_fts_query(q, lang=lang)
    if needs_fallback(q):
        return stmt

    safe_lang = _sanitize_lang(lang)
    # plainto_tsquery — параметризованный, SQL injection невозможен через :q.
    # lang — whitelisted (см. _sanitize_lang); если callee передал мусор,
    # уже превращён в 'simple'.
    search_col = _resolve_search_vector_column(model)
    tsquery = func.plainto_tsquery(safe_lang, sanitized)
    stmt = stmt.where(search_col.op("@@")(tsquery))
    if rank_order:
        stmt = stmt.order_by(desc(func.ts_rank(search_col, tsquery)))
    return stmt


def _resolve_search_vector_column(model: type) -> Any:
    """Достать ссылку на search_vector колонку модели.

    Колонка search_vector создаётся миграцией 0039 как GENERATED ALWAYS AS
    ... STORED. Мы НЕ описываем её в ORM-моделях (нельзя писать в неё
    напрямую, генерация на стороне БД). Здесь конструируем reference через
    table.column-construct, привязанный к __tablename__ модели.

    Это позволяет использовать FTS, не модифицируя models.py для каждой
    из 7 таблиц.
    """
    tablename = getattr(model, "__tablename__", None)
    if not tablename:
        raise ValueError(
            f"Model {model!r} has no __tablename__ — cannot reference search_vector"
        )
    # literal_column с явным типом TSVECTOR — Postgres-dialect.
    # Используем table().c.search_vector чтобы привязать к from-clause
    # модели (иначе SQLAlchemy не сможет правильно квалифицировать
    # `table.search_vector` в SQL).
    sv_table = table(tablename, Column("search_vector", TSVECTOR))
    return sv_table.c.search_vector


def build_ilike_pattern(q: str) -> str:
    """Backward-compat: построить '%escaped%' для ILIKE fallback.

    Используется когда needs_fallback(q) == True (query слишком короткая,
    только символы и т.п.). Экранирует % и _ (wildcards ILIKE), \\ и
    оборачивает в %...%.
    """
    if not q:
        return "%"
    s = q.strip()
    if not s:
        return "%"
    # Обрезаем до разумного лимита, чтобы избежать DoS.
    s = s[:MAX_FTS_QUERY_LENGTH]
    s = s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")
    return f"%{s}%"
