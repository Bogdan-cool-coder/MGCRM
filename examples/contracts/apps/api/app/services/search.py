"""Search service (Эпик 8 / Card 2.0 + Эпик 20): cross-entity full-text.

Эпик 20 (Performance Scale): заменили ILIKE на PostgreSQL FTS
(search_vector tsvector + GIN индексы, см. migration 0039).

Стратегия:
- Если query >= 3 символов и содержит буквенно-числовые символы —
  используем FTS (`search_vector @@ plainto_tsquery('russian', :q)`,
  ORDER BY ts_rank). Это даёт ms-уровень на 10k+ записях.
- Если query короче / только спецсимволы — fallback на ILIKE (как раньше).
  Это нужно для UI'шных префиксных поисков (admin начал печатать «aс»).

См. `app/services/fts.py` для деталей FTS-pipeline.

API:
- search_all(session, q, *, entity_types=None, limit=5) →
  {"query": q, "items": [...], "groups": [{entity_type, count, items}, ...]}

q должен быть >= 2 символов — иначе ValueError (caller возвращает 400).
"""
from __future__ import annotations

from typing import Any

from sqlalchemy import or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    Company,
    Contact,
    Contract,
    Counterparty,
    Deal,
    Lead,
    User,
)
from app.services.access_control import scope_query
from app.services.fts import apply_fts_filter, needs_fallback

# Маппинг entity_type → (Model, scope_entity_type). scope_entity_type — ключ в
# visibility_settings (см. ALLOWED_ENTITY_TYPES в access_control). entity_type
# из поиска, не имеющий scope-настройки (contact), сюда не входит → без фильтра.
_SCOPE_ENTITY: dict[str, tuple[type, str]] = {
    "lead": (Lead, "lead"),
    "company": (Company, "company"),
    "counterparty": (Counterparty, "counterparty"),
    "deal": (Deal, "deal"),
    "contract": (Contract, "contract"),
}


# Допустимые entity_types для search. Sync с frontend SearchEntityType.
SEARCH_ENTITY_TYPES: tuple[str, ...] = (
    "lead",
    "contact",
    "company",
    "counterparty",
    "deal",
    "contract",
)

# Минимальная длина query (frontend тоже не отправляет короче).
MIN_QUERY_LENGTH = 2

# Максимальная длина query (защита от слишком больших pattern'ов в ILIKE).
MAX_QUERY_LENGTH = 100


def _sanitize_query(q: str) -> str:
    """Очистить query: trim, обрезать, убрать % и _ (wildcards ILIKE).

    Возвращает пустую строку если q невалидна — caller проверяет длину.
    """
    if not q:
        return ""
    s = q.strip()
    if not s:
        return ""
    s = s[:MAX_QUERY_LENGTH]
    # Экранировать спецсимволы ILIKE — пользователь вводит просто текст,
    # % и _ должны рассматриваться как буквы, а не как wildcards.
    s = s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")
    return s


def validate_query(q: str) -> str:
    """Проверить query и вернуть sanitized. Raises ValueError при невалидности."""
    s = _sanitize_query(q)
    if len(s) < MIN_QUERY_LENGTH:
        raise ValueError(
            f"Длина запроса должна быть >= {MIN_QUERY_LENGTH} символов"
        )
    return s


def _secondary_for_record(*candidates: str | None) -> str | None:
    """Вернуть первое непустое строковое значение из candidates (для DTO.secondary)."""
    for v in candidates:
        if v and str(v).strip():
            return str(v).strip()
    return None


def _build_search_stmt(
    model: type, raw_q: str, sanitized_like: str, ilike_columns: list[Any], limit: int
) -> Any:
    """Универсальный builder: FTS если query OK, иначе ILIKE-fallback.

    - raw_q: оригинальный query из запроса (FTS использует его, sanitize внутри).
    - sanitized_like: уже sanitized '%query%' для ILIKE-fallback (через
      `_sanitize_query` + format `%...%`).
    - ilike_columns: список колонок Model.<field> для or_(*ilike).

    Возвращает select-stmt. Caller добавляет .limit() и execute.
    """
    stmt = select(model)
    if needs_fallback(raw_q):
        # Короткая query или мусор — стандартный ILIKE по всем полям.
        stmt = stmt.where(or_(*[c.ilike(sanitized_like) for c in ilike_columns]))
        stmt = stmt.order_by(model.updated_at.desc())
    else:
        # FTS-путь: WHERE ... @@ plainto_tsquery + ORDER BY ts_rank DESC.
        stmt = apply_fts_filter(stmt, model, raw_q, lang="russian")
    return stmt.limit(limit)


async def _apply_search_scope(
    session: AsyncSession, stmt: Any, entity_type: str, user: User | None
) -> Any:
    """Подцепить visibility-scope к search-stmt (если есть user и модель скоупится).

    Без user (например, внутренние вызовы) — stmt без изменений (бэквард-совместимо).
    contact не имеет scope-настройки → не входит в _SCOPE_ENTITY, возвращаем as-is.
    """
    if user is None:
        return stmt
    mapping = _SCOPE_ENTITY.get(entity_type)
    if mapping is None:
        return stmt
    model, scope_type = mapping
    return await scope_query(session, stmt, model, scope_type, user)


async def _search_leads(
    session: AsyncSession, raw_q: str, like: str, limit: int,
    user: User | None = None,
) -> list[dict[str, Any]]:
    """Поиск лидов по name / contact_email / contact_phone / notes (FTS)."""
    stmt = _build_search_stmt(
        Lead, raw_q, like,
        [Lead.name, Lead.contact_email, Lead.contact_phone],
        limit,
    )
    stmt = await _apply_search_scope(session, stmt, "lead", user)
    rows = (await session.execute(stmt)).scalars().all()
    return [
        {
            "entity_type": "lead",
            "id": l.id,
            "display_name": l.name,
            "secondary": _secondary_for_record(l.contact_email, l.contact_phone),
        }
        for l in rows
    ]


async def _search_contacts(
    session: AsyncSession, raw_q: str, like: str, limit: int,
    user: User | None = None,
) -> list[dict[str, Any]]:
    """Поиск контактов (crm_contacts) по full_name / email / phone (FTS).

    contact не входит в visibility_settings scope-матрицу (нет owner/department
    в той же семантике), поэтому scope-фильтр к нему не применяется.
    """
    stmt = _build_search_stmt(
        Contact, raw_q, like,
        [Contact.full_name, Contact.email, Contact.phone],
        limit,
    )
    rows = (await session.execute(stmt)).scalars().all()
    return [
        {
            "entity_type": "contact",
            "id": c.id,
            "display_name": c.full_name,
            "secondary": _secondary_for_record(c.email, c.phone),
        }
        for c in rows
    ]


async def _search_companies(
    session: AsyncSession, raw_q: str, like: str, limit: int,
    user: User | None = None,
) -> list[dict[str, Any]]:
    """Поиск компаний по legal_name / tax_id / email / phone / website (FTS)."""
    stmt = _build_search_stmt(
        Company, raw_q, like,
        [Company.legal_name, Company.tax_id, Company.email, Company.phone],
        limit,
    )
    stmt = await _apply_search_scope(session, stmt, "company", user)
    rows = (await session.execute(stmt)).scalars().all()
    return [
        {
            "entity_type": "company",
            "id": c.id,
            "display_name": c.legal_name,
            "secondary": _secondary_for_record(c.tax_id, c.email, c.phone),
        }
        for c in rows
    ]


async def _search_counterparties(
    session: AsyncSession, raw_q: str, like: str, limit: int,
    user: User | None = None,
) -> list[dict[str, Any]]:
    """Поиск контрагентов по name / email / phone / tax_id / notes (FTS)."""
    stmt = _build_search_stmt(
        Counterparty, raw_q, like,
        [Counterparty.name, Counterparty.email, Counterparty.phone, Counterparty.tax_id],
        limit,
    )
    stmt = await _apply_search_scope(session, stmt, "counterparty", user)
    rows = (await session.execute(stmt)).scalars().all()
    return [
        {
            "entity_type": "counterparty",
            "id": cp.id,
            "display_name": cp.name,
            "secondary": _secondary_for_record(cp.tax_id, cp.email, cp.phone),
        }
        for cp in rows
    ]


async def _search_deals(
    session: AsyncSession, raw_q: str, like: str, limit: int,
    user: User | None = None,
) -> list[dict[str, Any]]:
    """Поиск сделок по title + lost_reason (FTS)."""
    stmt = _build_search_stmt(
        Deal, raw_q, like,
        [Deal.title],
        limit,
    )
    stmt = await _apply_search_scope(session, stmt, "deal", user)
    rows = (await session.execute(stmt)).scalars().all()
    return [
        {
            "entity_type": "deal",
            "id": d.id,
            "display_name": d.title,
            "secondary": (
                f"{d.amount} {d.currency}" if d.amount and d.currency else None
            ),
        }
        for d in rows
    ]


async def _search_contracts(
    session: AsyncSession, raw_q: str, like: str, limit: int,
    user: User | None = None,
) -> list[dict[str, Any]]:
    """Поиск договоров по number / title (FTS).

    Contract в visibility-матрице есть, но если у модели нет owner/department
    колонок — scope_query/apply_scope_filter graceful no-op (см. access_control).
    """
    stmt = _build_search_stmt(
        Contract, raw_q, like,
        [Contract.number, Contract.title],
        limit,
    )
    stmt = await _apply_search_scope(session, stmt, "contract", user)
    rows = (await session.execute(stmt)).scalars().all()
    return [
        {
            "entity_type": "contract",
            "id": c.id,
            "display_name": c.number or c.title or f"#{c.id}",
            "secondary": c.title if c.number and c.title else None,
        }
        for c in rows
    ]


_SEARCHERS = {
    "lead": _search_leads,
    "contact": _search_contacts,
    "company": _search_companies,
    "counterparty": _search_counterparties,
    "deal": _search_deals,
    "contract": _search_contracts,
}


async def search_all(
    session: AsyncSession,
    q: str,
    *,
    entity_types: list[str] | None = None,
    limit: int = 5,
    user: User | None = None,
) -> dict[str, Any]:
    """Универсальный full-text search по выбранным entity_types.

    - q: query (>= 2 символов после sanitize). Иначе ValueError.
    - entity_types: list или None (== все). Невалидные типы — игнорируются.
    - limit: сколько записей на entity (default 5).
    - user: текущий пользователь для visibility-scope. None → без scope-фильтра
      (бэквард-совместимо). Утечка чужих lead/deal/company/counterparty/contract
      закрывается прокидыванием user из роутера.

    Возвращает:
    {
      "query": q,
      "items": [...все записи в плоском виде, отсортированы по entity_type и id...],
      "groups": [
        {"entity_type": "lead", "count": 3, "items": [...]},
        ...
      ]
    }
    """
    sanitized = validate_query(q)
    like = f"%{sanitized}%"

    types = entity_types if entity_types else list(SEARCH_ENTITY_TYPES)
    types = [t for t in types if t in SEARCH_ENTITY_TYPES]

    limit = max(1, min(50, int(limit)))

    groups: list[dict[str, Any]] = []
    all_items: list[dict[str, Any]] = []

    # Передаём ORIGINAL q в searcher (для FTS — он сам sanitize), и sanitized
    # like-pattern (для fallback ILIKE). Раздельно — потому что FTS sanitize
    # удаляет ts_query operator символы, а ILIKE escape экранирует другие
    # вещи (% _ \).
    for et in types:
        searcher = _SEARCHERS[et]
        items = await searcher(session, q, like, limit, user)
        groups.append({"entity_type": et, "count": len(items), "items": items})
        all_items.extend(items)

    return {"query": q.strip()[:MAX_QUERY_LENGTH], "items": all_items, "groups": groups}
