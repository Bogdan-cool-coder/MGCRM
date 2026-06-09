"""Поиск дублей (Эпик 8 / Card 2.0): нормализация + scan + scoring + dismiss.

MVP — простой алгоритм:
- normalize_email/phone/name → канонические формы.
- группировка записей по нормализованной форме.
- внутри группы вычисляется similarity_score (0-100) от количества совпавших
  полей и сильности совпадения (email — самый сильный сигнал).

Без fuzzy-matching (Levenshtein, trigram): MVP — точный matching по
нормализованным значениям + score-bucket. Расширение под trigram_search /
pg_trgm — отдельный тикет.

Scan возвращает list[DuplicateGroup] где каждая группа — две и более записи
с пересечением хотя бы по одному ключевому полю. Уже dismissed пары
исключаются.
"""
from __future__ import annotations

import re
from collections import defaultdict
from dataclasses import dataclass, field
from typing import Any, Iterable

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    Company,
    Contact,
    Counterparty,
    DismissedDuplicate,
    Lead,
)


# Допустимые сущности для дублескана
DUPLICATE_ENTITY_TYPES: tuple[str, ...] = ("counterparty", "contact", "company", "lead")


# ---------- Нормализация ----------

_LEGAL_FORM_TOKENS = (
    "ооо", "зао", "оао", "пао", "ао", "ип",
    "too", "тоо",
    "llc", "inc", "ltd", "co", "corp", "gmbh",
    'l.l.c.', 'l.l.c', 'inc.',
)
_LEGAL_FORM_RE = re.compile(
    r"\b(?:" + "|".join(re.escape(t) for t in _LEGAL_FORM_TOKENS) + r")\.?\b",
    re.IGNORECASE,
)
_NON_DIGIT_RE = re.compile(r"\D+")
_MULTI_SPACE_RE = re.compile(r"\s+")
_QUOTES_RE = re.compile(r'["\'«»“”„‘’]')


def normalize_email(s: str | None) -> str:
    """email → lower + strip. None / пустое → пустая строка."""
    if not s:
        return ""
    return str(s).strip().lower()


def normalize_phone(s: str | None) -> str:
    """phone → только цифры, последние 10 (для match'а форматов с/без +7).

    Примеры:
    - "+7 (701) 234 56 78" → "7012345678"
    - "8-701-234-56-78"    → "7012345678"
    - ""                   → ""
    """
    if not s:
        return ""
    digits = _NON_DIGIT_RE.sub("", str(s))
    if not digits:
        return ""
    return digits[-10:] if len(digits) >= 10 else digits


def normalize_name(s: str | None) -> str:
    """name → lower, strip, убираем юр-формы и кавычки, сжимаем пробелы.

    Примеры:
    - "ООО Ромашка"           → "ромашка"
    - 'ООО "Ромашка"'         → "ромашка"
    - "Macroglobal Tech Ltd." → "macroglobal tech"
    """
    if not s:
        return ""
    text = str(s).strip().lower()
    # убираем кавычки
    text = _QUOTES_RE.sub(" ", text)
    # убираем юр-формы (с возможной точкой)
    text = _LEGAL_FORM_RE.sub(" ", text)
    # убираем висящие точки/запятые/слэши (трейлинг punctuation после удаления юрформ)
    text = re.sub(r"[.,;/]+", " ", text)
    # сжимаем пробелы
    text = _MULTI_SPACE_RE.sub(" ", text).strip()
    return text


# ---------- Scoring ----------


def compute_similarity(a: dict[str, str | None], b: dict[str, str | None]) -> int:
    """Вычислить similarity_score (0-100) между двумя нормализованными записями.

    Веса:
    - email exact match → 50
    - phone exact match → 30
    - tax_id exact match → 40
    - name exact match → 25
    - name partial match (один — substring другого) → 10

    Суммируется, обрезается до 100. Пустые значения (== "") не считаются совпадением.
    """
    score = 0
    if a.get("email") and a["email"] == b.get("email"):
        score += 50
    if a.get("phone") and a["phone"] == b.get("phone"):
        score += 30
    if a.get("tax_id") and a["tax_id"] == b.get("tax_id"):
        score += 40
    if a.get("name") and a["name"] == b.get("name"):
        score += 25
    else:
        an = a.get("name") or ""
        bn = b.get("name") or ""
        if an and bn and len(an) >= 4 and len(bn) >= 4:
            if an in bn or bn in an:
                score += 10
    return min(100, score)


# ---------- Group container ----------


@dataclass
class DuplicateRecord:
    """Карточка записи внутри группы дублей (для frontend)."""
    id: int
    display_name: str
    fields: dict[str, str | None] = field(default_factory=dict)


@dataclass
class DuplicateGroup:
    """Группа дублей — N >= 2 записей с похожестью."""
    id: str  # детерминированный (по min(id)) — для idempotent dismiss
    entity: str  # 'counterparty' | 'contact' | 'company' | 'lead'
    records: list[DuplicateRecord]
    similarity_score: int  # 0-100, для всей группы

    def to_dict(self) -> dict[str, Any]:
        return {
            "id": self.id,
            "entity": self.entity,
            "records": [
                {"id": r.id, "display_name": r.display_name, "fields": r.fields}
                for r in self.records
            ],
            "similarity_score": self.similarity_score,
        }


# ---------- Group building ----------


def _build_groups(
    entity_type: str,
    rows: list[dict[str, Any]],
    dismissed_pairs: set[tuple[int, int]],
) -> list[DuplicateGroup]:
    """Универсальный групповщик: bucket'ит rows по ключам, считает score.

    rows: list of {"id": int, "display_name": str, "fields": {email,phone,tax_id,name},
                   "norm": {email,phone,tax_id,name}}.

    Алгоритм:
    1. Buckets: для каждой записи добавляем её в бакеты по email, phone, tax_id, name —
       если значение непустое.
    2. Любые две записи в одном бакете — кандидаты.
    3. Для каждой пары — compute_similarity; если >= 50 — это группа.
    4. Если запись попала в несколько групп (через разные ключи) — мерджим transitively.
    5. Исключаем пары из dismissed_pairs.
    """
    buckets: dict[tuple[str, str], list[int]] = defaultdict(list)
    by_id: dict[int, dict[str, Any]] = {}

    for r in rows:
        rid = r["id"]
        by_id[rid] = r
        norm = r["norm"]
        for key in ("email", "phone", "tax_id", "name"):
            v = norm.get(key)
            if v:
                buckets[(key, v)].append(rid)

    # Кандидаты пар
    candidate_pairs: set[tuple[int, int]] = set()
    for ids in buckets.values():
        if len(ids) < 2:
            continue
        sorted_ids = sorted(set(ids))
        for i in range(len(sorted_ids)):
            for j in range(i + 1, len(sorted_ids)):
                candidate_pairs.add((sorted_ids[i], sorted_ids[j]))

    # Фильтруем dismissed
    candidate_pairs -= dismissed_pairs

    # Считаем score и оставляем только сильные совпадения
    strong_pairs: list[tuple[int, int, int]] = []  # (a, b, score)
    for a, b in candidate_pairs:
        s = compute_similarity(by_id[a]["norm"], by_id[b]["norm"])
        if s >= 50:
            strong_pairs.append((a, b, s))

    if not strong_pairs:
        return []

    # Union-find для transitive merging
    parent: dict[int, int] = {}

    def find(x: int) -> int:
        while parent.get(x, x) != x:
            parent[x] = parent.get(parent.get(x, x), parent.get(x, x))
            x = parent[x]
        return x

    def union(a: int, b: int) -> None:
        ra, rb = find(a), find(b)
        if ra != rb:
            parent[ra] = rb

    for a, b, _s in strong_pairs:
        parent.setdefault(a, a)
        parent.setdefault(b, b)
        union(a, b)

    # Группируем по root
    groups_by_root: dict[int, list[int]] = defaultdict(list)
    pair_score_max: dict[int, int] = defaultdict(int)  # root → макс. score в группе
    for a, b, s in strong_pairs:
        ra = find(a)
        rb = find(b)
        # после union одинаковые
        root = ra
        if a not in groups_by_root[root]:
            groups_by_root[root].append(a)
        if b not in groups_by_root[root]:
            groups_by_root[root].append(b)
        pair_score_max[root] = max(pair_score_max[root], s)

    groups: list[DuplicateGroup] = []
    for root, ids in groups_by_root.items():
        records = [
            DuplicateRecord(
                id=by_id[i]["id"],
                display_name=by_id[i]["display_name"],
                fields=by_id[i]["fields"],
            )
            for i in sorted(ids)
        ]
        gid = f"{entity_type}:{min(ids)}"
        groups.append(
            DuplicateGroup(
                id=gid,
                entity=entity_type,
                records=records,
                similarity_score=pair_score_max[root],
            )
        )
    # Сортируем — самые «дублёвые» сверху
    groups.sort(key=lambda g: (-g.similarity_score, g.id))
    return groups


# ---------- Dismissed pairs ----------


async def _get_dismissed_pairs(
    session: AsyncSession, entity_type: str
) -> set[tuple[int, int]]:
    """Все пары (a, b), для которых entity_type помечен «не дубль». a < b."""
    rows = (
        await session.execute(
            select(DismissedDuplicate).where(
                DismissedDuplicate.entity_type == entity_type
            )
        )
    ).scalars().all()
    out: set[tuple[int, int]] = set()
    for r in rows:
        a, b = sorted((int(r.entity_a_id), int(r.entity_b_id)))
        out.add((a, b))
    return out


def normalize_pair(a: int, b: int) -> tuple[int, int]:
    """Упорядочить пару (a, b) так, чтобы a < b. Для unique-constraint'а."""
    if a == b:
        raise ValueError("Дубль самого с собой не имеет смысла")
    return (min(a, b), max(a, b))


# ---------- Scanners per entity ----------


async def scan_counterparty_duplicates(
    session: AsyncSession,
) -> list[DuplicateGroup]:
    """Скан дублей Counterparty по email / phone / tax_id / name."""
    rows = (
        await session.execute(select(Counterparty))
    ).scalars().all()
    items = [
        {
            "id": cp.id,
            "display_name": cp.name or f"#{cp.id}",
            "fields": {
                "email": cp.email,
                "phone": cp.phone,
                "tax_id": cp.tax_id,
                "name": cp.name,
            },
            "norm": {
                "email": normalize_email(cp.email),
                "phone": normalize_phone(cp.phone),
                "tax_id": (cp.tax_id or "").strip(),
                "name": normalize_name(cp.name),
            },
        }
        for cp in rows
    ]
    dismissed = await _get_dismissed_pairs(session, "counterparty")
    return _build_groups("counterparty", items, dismissed)


async def scan_contact_duplicates(session: AsyncSession) -> list[DuplicateGroup]:
    """Скан дублей Contact (crm_contacts) по email / phone / name."""
    rows = (await session.execute(select(Contact))).scalars().all()
    items = [
        {
            "id": c.id,
            "display_name": c.full_name or f"#{c.id}",
            "fields": {
                "email": c.email,
                "phone": c.phone,
                "tax_id": None,
                "name": c.full_name,
            },
            "norm": {
                "email": normalize_email(c.email),
                "phone": normalize_phone(c.phone),
                "tax_id": "",
                "name": normalize_name(c.full_name),
            },
        }
        for c in rows
    ]
    dismissed = await _get_dismissed_pairs(session, "contact")
    return _build_groups("contact", items, dismissed)


async def scan_company_duplicates(session: AsyncSession) -> list[DuplicateGroup]:
    """Скан дублей Company (crm_companies) по tax_id + name."""
    rows = (await session.execute(select(Company))).scalars().all()
    items = [
        {
            "id": c.id,
            "display_name": c.legal_name or f"#{c.id}",
            "fields": {
                "email": c.email,
                "phone": c.phone,
                "tax_id": c.tax_id,
                "name": c.legal_name,
            },
            "norm": {
                "email": normalize_email(c.email),
                "phone": normalize_phone(c.phone),
                "tax_id": (c.tax_id or "").strip(),
                "name": normalize_name(c.legal_name),
            },
        }
        for c in rows
    ]
    dismissed = await _get_dismissed_pairs(session, "company")
    return _build_groups("company", items, dismissed)


async def scan_lead_duplicates(session: AsyncSession) -> list[DuplicateGroup]:
    """Скан дублей Lead по email / phone / name."""
    rows = (await session.execute(select(Lead))).scalars().all()
    items = [
        {
            "id": l.id,
            "display_name": l.name or f"#{l.id}",
            "fields": {
                "email": l.contact_email,
                "phone": l.contact_phone,
                "tax_id": None,
                "name": l.name,
            },
            "norm": {
                "email": normalize_email(l.contact_email),
                "phone": normalize_phone(l.contact_phone),
                "tax_id": "",
                "name": normalize_name(l.name),
            },
        }
        for l in rows
    ]
    dismissed = await _get_dismissed_pairs(session, "lead")
    return _build_groups("lead", items, dismissed)


SCANNERS = {
    "counterparty": scan_counterparty_duplicates,
    "contact": scan_contact_duplicates,
    "company": scan_company_duplicates,
    "lead": scan_lead_duplicates,
}


async def scan_for_entity(
    session: AsyncSession, entity_type: str
) -> list[DuplicateGroup]:
    """Универсальный диспетчер по entity_type."""
    if entity_type not in SCANNERS:
        raise ValueError(
            f"Недопустимый entity_type: {entity_type}. Ожидается одно из {list(SCANNERS)}"
        )
    return await SCANNERS[entity_type](session)


# ============ Realtime check (Tech Sprint Фаза 0) ============


# Маппинг: (entity_type, db_field) → (Model, model_field, display_name_attr)
_REALTIME_FIELD_MAP: dict[tuple[str, str], tuple[type, str, str]] = {
    ("counterparty", "email"):  (Counterparty, "email", "name"),
    ("counterparty", "phone"):  (Counterparty, "phone", "name"),
    ("counterparty", "tax_id"): (Counterparty, "tax_id", "name"),
    ("counterparty", "name"):   (Counterparty, "name", "name"),
    ("contact", "email"):       (Contact, "email", "full_name"),
    ("contact", "phone"):       (Contact, "phone", "full_name"),
    ("contact", "name"):        (Contact, "full_name", "full_name"),
    ("company", "email"):       (Company, "email", "legal_name"),
    ("company", "phone"):       (Company, "phone", "legal_name"),
    ("company", "tax_id"):      (Company, "tax_id", "legal_name"),
    ("company", "name"):        (Company, "legal_name", "legal_name"),
    ("lead", "email"):          (Lead, "contact_email", "name"),
    ("lead", "phone"):          (Lead, "contact_phone", "name"),
    ("lead", "name"):           (Lead, "name", "name"),
}


def build_realtime_check_query_spec(
    entity_type: str, field: str, value: str,
) -> tuple[type, str, str, str, str]:
    """Pure-function: вернуть (Model, model_field, display_attr, normalized_value, match_kind).

    match_kind:
    - 'exact' — для email/phone/tax_id (после normalize точное совпадение).
    - 'ilike' — для name (без pg_trgm).

    Бросает ValueError если поле не поддержано для этого entity_type.
    """
    spec = _REALTIME_FIELD_MAP.get((entity_type, field))
    if spec is None:
        raise ValueError(
            f"Поле {field!r} не поддержано для {entity_type!r}. "
            f"Допустимые: {sorted(k[1] for k in _REALTIME_FIELD_MAP if k[0] == entity_type)}"
        )
    model, model_field, display_attr = spec
    if field == "email":
        normalized = normalize_email(value)
        match_kind = "exact"
    elif field == "phone":
        normalized = normalize_phone(value)
        match_kind = "exact"
    elif field == "tax_id":
        normalized = (value or "").strip()
        match_kind = "exact"
    elif field == "name":
        normalized = normalize_name(value)
        match_kind = "ilike"
    else:
        normalized = value
        match_kind = "exact"
    return model, model_field, display_attr, normalized, match_kind


async def find_realtime_duplicates(
    session: AsyncSession,
    *,
    entity_type: str,
    field: str,
    value: str,
    limit: int = 5,
) -> tuple[list[dict[str, Any]], str]:
    """Realtime-поиск кандидатов-дублей по полю.

    Возвращает ([{id, display_name, similarity}, ...], normalized_value).
    similarity ∈ [0,1]: 1.0 — точное совпадение, 0.7 — substring (ilike).

    Используется в GET /api/duplicates/check для UI-debounced проверки.
    """
    try:
        model, model_field, display_attr, normalized, match_kind = (
            build_realtime_check_query_spec(entity_type, field, value)
        )
    except ValueError:
        return [], ""

    if not normalized:
        return [], ""

    col = getattr(model, model_field)
    if match_kind == "exact":
        # Для email/phone — нужно сравнить НОРМАЛИЗОВАННУЮ форму. Но БД хранит
        # неноромализованную. Делаем разумный compromise: для email — lower(col)
        # = normalized; для phone — substring по последним цифрам (бывает с/без +7).
        if field == "email":
            from sqlalchemy import func as _f
            stmt = select(model).where(_f.lower(col) == normalized).limit(limit)
        elif field == "phone":
            # Берём последние 10 цифр value, ищем по LIKE %последние_10%
            from sqlalchemy import func as _f
            # ВСТРОЕНО: не у всех СУБД есть regexp_replace; используем LIKE
            tail = normalized
            stmt = select(model).where(col.ilike(f"%{tail}%")).limit(limit)
        else:  # tax_id
            stmt = select(model).where(col == normalized).limit(limit)
        rows = (await session.execute(stmt)).scalars().all()
        out = [
            {
                "id": r.id,
                "display_name": getattr(r, display_attr, None) or f"#{r.id}",
                "similarity": 1.0,
            }
            for r in rows
        ]
        return out, normalized

    # match_kind == 'ilike' (name)
    if len(normalized) < 2:
        return [], normalized
    stmt = select(model).where(col.ilike(f"%{normalized}%")).limit(limit)
    rows = (await session.execute(stmt)).scalars().all()
    out = []
    for r in rows:
        raw_name = getattr(r, display_attr, None) or ""
        norm_name = normalize_name(raw_name)
        if not norm_name:
            sim = 0.5
        elif norm_name == normalized:
            sim = 1.0
        elif normalized in norm_name or norm_name in normalized:
            sim = 0.7
        else:
            sim = 0.5
        out.append({
            "id": r.id,
            "display_name": raw_name or f"#{r.id}",
            "similarity": sim,
        })
    # Сортируем по similarity DESC
    out.sort(key=lambda x: -x["similarity"])
    return out, normalized
