"""CONTACTS 2.0 Ф1 — pure-function helpers для unified-списка, холдингов и файлов.

Логика, не требующая БД, вынесена сюда для unit-тестов (pytest без DB fixture):
- unified_item: маппинг Contact / Company → единый dict с `kind`.
- holding parent-conflict: проверка «у холдинга уже есть parent».
- file validation: размер, sanitize имени, защита пути от traversal.
"""
from __future__ import annotations

import re
import unicodedata
from typing import Any

# ============ Unified list (person | company) ============

# Поля unified-элемента, общие для обоих типов. type-specific поля кладём
# в `extra` (frontend знает kind и берёт нужное).
UNIFIED_KIND_PERSON = "person"
UNIFIED_KIND_COMPANY = "company"


def contact_to_unified(contact: dict[str, Any]) -> dict[str, Any]:
    """Contact (dict-вид) → unified-элемент с kind='person'.

    name ← full_name. Общие поля: id, kind, name, phone, email, owner_id,
    source, tags. Type-specific (position/status/company_id) — в extra.
    """
    return {
        "id": contact.get("id"),
        "kind": UNIFIED_KIND_PERSON,
        "name": contact.get("full_name") or "",
        "phone": contact.get("phone"),
        "email": contact.get("email"),
        "owner_id": contact.get("owner_id"),
        "source": contact.get("source"),
        "tags": list(contact.get("tags") or []),
        "extra": {
            "position": contact.get("position"),
            "status": contact.get("status"),
            "company_id": contact.get("company_id"),
        },
    }


def company_to_unified(company: dict[str, Any]) -> dict[str, Any]:
    """Company (dict-вид) → unified-элемент с kind='company'.

    name ← name (обиходное) или legal_name (fallback). owner_id ← owner_user_id.
    Type-specific (legal_name/tax_id/country/counterparty_id/company_type_id/
    holding_role) — в extra.
    """
    display = (company.get("name") or "").strip() or company.get("legal_name") or ""
    return {
        "id": company.get("id"),
        "kind": UNIFIED_KIND_COMPANY,
        "name": display,
        "phone": company.get("phone"),
        "email": company.get("email"),
        "owner_id": company.get("owner_user_id"),
        "source": company.get("source"),
        "tags": list(company.get("tags") or []),
        "extra": {
            "legal_name": company.get("legal_name"),
            "tax_id": company.get("tax_id"),
            "country": company.get("country"),
            "city": company.get("city"),
            "counterparty_id": company.get("counterparty_id"),
            "company_type_id": company.get("company_type_id"),
            "holding_role": company.get("holding_role"),
            "group_id": company.get("group_id"),
        },
    }


def sort_unified(items: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Сортировка unified-списка по name (case-insensitive), пустые — в конец."""
    return sorted(
        items,
        key=lambda it: ((it.get("name") or "").strip() == "", (it.get("name") or "").lower()),
    )


# ============ Mirror-инвариант: Company → Counterparty-зеркало ============

# Дефолт country_code для зеркала, если у компании не задана ни country, ни
# country_code. Counterparty.country_code — NOT NULL VARCHAR(2). Большинство
# клиентов реестра — KZ, поэтому это безопасный нейтральный дефолт. Источник
# истины остаётся за company; зеркало просто не должно падать на NOT NULL.
MIRROR_DEFAULT_COUNTRY_CODE = "KZ"

# Ownership / scope-поля зеркала — отдельная зона CS. После того как CS-команда
# назначила контрагенту owner/department/group (registry-сторона), любой PATCH
# компании (sales-сторона) НЕ должен перетирать их. Поэтому при СИНХРОНИЗАЦИИ
# существующего зеркала эти поля исключаются; при ПЕРВИЧНОМ создании зеркала
# (CS-стороны ещё нет) — копируются как стартовое значение.
_MIRROR_OWNERSHIP_FIELDS: tuple[str, ...] = (
    "owner_user_id", "responsible_user_id", "department_id", "group_id",
)

# Реквизиты/display-поля Company, копируемые 1:1 в одноимённые колонки
# Counterparty при ЛЮБОЙ синхронизации (create + update).
_MIRROR_DISPLAY_FIELDS: tuple[str, ...] = (
    "legal_form", "full_legal_form", "gender_ending_oe",
    "director_position", "director_genitive", "director_short", "acts_basis",
    "tax_id", "tax_id_label", "address",
    "bank", "bank_code_label", "bank_code", "account",
    "phone", "email", "website",
    "category_code",
    "city",
)

# Полный набор direct-полей при СОЗДАНИИ зеркала (display + ownership).
_MIRROR_DIRECT_FIELDS: tuple[str, ...] = _MIRROR_DISPLAY_FIELDS + _MIRROR_OWNERSHIP_FIELDS


def company_to_counterparty_fields(
    company: dict[str, Any], *, for_sync: bool = False
) -> dict[str, Any]:
    """Company (dict-вид) → поля Counterparty-зеркала (faithful mirror).

    Гарантирует заполнение NOT NULL колонок Counterparty:
    - name ← company.name (обиходное) || company.legal_name (fallback)
    - country_code ← company.country_code || company.country || дефолт (KZ)

    Остальные реквизиты копируются 1:1 из одноимённых полей company (nullable).

    `for_sync=False` (создание зеркала): копирует display-поля + ownership/scope
    (owner_user_id, responsible_user_id, department_id, group_id) как стартовые.
    `for_sync=True` (синхронизация существующего зеркала из PATCH компании):
    копирует ТОЛЬКО display/реквизиты — ownership/scope остаются за CS-стороной
    и НЕ перетираются (иначе ломается CS visibility scope, KPI, salary-атрибуция).

    Чистая функция (input dict → output dict) для unit-тестов и переиспользования
    в роутере create_company + sync_company_mirror + миграции 0073-backfill.
    """
    direct = _MIRROR_DISPLAY_FIELDS if for_sync else _MIRROR_DIRECT_FIELDS
    out: dict[str, Any] = {}
    for f in direct:
        out[f] = company.get(f)

    name = (company.get("name") or "").strip() or (company.get("legal_name") or "").strip()
    out["name"] = name or "—"

    country = (company.get("country_code") or "").strip() or (company.get("country") or "").strip()
    out["country_code"] = (country or MIRROR_DEFAULT_COUNTRY_CODE).upper()[:2]
    return out


async def sync_company_mirror(session: Any, company: Any) -> None:
    """Записать пересекающиеся поля Company обратно в Counterparty-зеркало.

    Реестр CS / зарплата / аналитика на counterparty-fallback читают реквизиты
    из Counterparty. Без синхронизации PATCH компании оставляет зеркало с
    устаревшими name / реквизитами / city. Эта функция переносит актуальные
    значения company → mirror (company.counterparty_id).

    Idempotent: если зеркала нет (counterparty_id is None) — no-op. Поля
    name/country_code пересчитываются через тот же faithful-маппер, что и при
    создании, чтобы NOT NULL колонки гарантированно были заполнены.

    ВАЖНО: синхронизирует только display/реквизиты (for_sync=True). Ownership/
    scope-поля зеркала (owner_user_id, responsible_user_id, department_id,
    group_id) — отдельная зона CS и НЕ перетираются при PATCH компании, иначе
    ломается CS visibility scope, KPI и salary-атрибуция, читающие
    Counterparty.owner/department.

    Caller отвечает за commit (мы только мутируем mirror в текущей транзакции).
    """
    if getattr(company, "counterparty_id", None) is None:
        return
    from sqlalchemy.future import select

    from app.models import Counterparty

    mirror = (await session.execute(
        select(Counterparty).where(Counterparty.id == company.counterparty_id)
    )).scalar_one_or_none()
    if mirror is None:
        return
    fields = company_to_counterparty_fields(_company_mirror_source(company), for_sync=True)
    for k, v in fields.items():
        setattr(mirror, k, v)


def _company_mirror_source(company: Any) -> dict[str, Any]:
    """Company ORM → dict полей-источников для company_to_counterparty_fields.

    Дублирует список из companies.py:_MIRROR_SOURCE_FIELDS, но локально, чтобы
    избежать кругового импорта роутера в сервис.
    """
    fields = (
        "name", "legal_name", "country", "country_code", "city",
        "legal_form", "full_legal_form", "gender_ending_oe",
        "director_position", "director_genitive", "director_short", "acts_basis",
        "tax_id", "tax_id_label", "address",
        "bank", "bank_code_label", "bank_code", "account",
        "phone", "email", "website",
        "group_id", "category_code",
        "owner_user_id", "responsible_user_id", "department_id",
    )
    return {f: getattr(company, f, None) for f in fields}


# ============ Холдинги: parent-conflict ============


def detect_parent_conflict(
    existing_roles: list[tuple[int, str | None]],
    company_id: int,
    new_role: str,
) -> int | None:
    """Есть ли в холдинге другой parent при попытке назначить company_id=parent.

    existing_roles — список (company_id, holding_role) всех компаний в холдинге
    (включая редактируемую). Возвращает id уже существующего parent (не равного
    company_id), если new_role='parent' и такой parent есть; иначе None.

    Используется в POST/PATCH holding-links: если конфликт и confirm=false →
    409; если confirm=true → caller перепишет старого parent в subsidiary.
    """
    if new_role != "parent":
        return None
    for cid, role in existing_roles:
        if cid != company_id and role == "parent":
            return cid
    return None


HOLDING_ROLES: tuple[str, ...] = ("parent", "subsidiary")


def validate_holding_role(role: str) -> None:
    """Проверка допустимости роли в холдинге. Raises ValueError."""
    if role not in HOLDING_ROLES:
        raise ValueError(f"Недопустимая роль холдинга: {role!r}. Ожидается одно из {list(HOLDING_ROLES)}")


# ============ Файлы: валидация и безопасность ============

MAX_CRM_FILE_SIZE = 20 * 1024 * 1024  # 20 МБ
CRM_ENTITY_TYPES: tuple[str, ...] = ("contact", "company", "deal")

# Дефолтные системные/пользовательские папки, создаваемые при первом обращении.
DEFAULT_FOLDERS: tuple[tuple[str, bool], ...] = (
    ("Системная", True),   # is_system — нельзя удалять/переименовывать
    ("Документы", False),
)


def validate_entity_type(entity_type: str) -> None:
    """Проверка owner_entity_type ∈ {contact, company, deal}. Raises ValueError."""
    if entity_type not in CRM_ENTITY_TYPES:
        raise ValueError(
            f"Недопустимый entity_type: {entity_type!r}. Ожидается одно из {list(CRM_ENTITY_TYPES)}"
        )


def validate_file_size(size: int) -> None:
    """Проверка размера файла ≤ 20 МБ. Raises ValueError."""
    if size > MAX_CRM_FILE_SIZE:
        raise ValueError(
            f"Файл больше {MAX_CRM_FILE_SIZE // (1024 * 1024)} МБ "
            f"({size} > {MAX_CRM_FILE_SIZE} байт)"
        )


def sanitize_filename(name: str | None) -> str:
    """Безопасное имя файла: убирает path-сепараторы и опасные символы.

    - Берёт только basename (отрезает любые '/' и '\\').
    - Нормализует unicode, оставляет буквы/цифры/.-_() и пробелы.
    - Защищает от '', '.', '..', скрытых файлов (ведущая точка).
    Возвращает гарантированно непустую безопасную строку.
    """
    raw = (name or "").strip()
    # Берём basename — отрезаем всё до последнего разделителя пути.
    raw = re.split(r"[\\/]", raw)[-1].strip()
    raw = unicodedata.normalize("NFKC", raw)
    # Разрешённые символы: буквы (любой алфавит), цифры, пробел, .-_()
    cleaned = re.sub(r"[^\w\s.\-()]", "_", raw, flags=re.UNICODE)
    cleaned = cleaned.strip(" .")  # убираем ведущие/хвостовые точки и пробелы
    if cleaned in ("", ".", ".."):
        return "file"
    return cleaned[:255]


def is_path_within(base_dir: str, candidate: str) -> bool:
    """True, если candidate-путь лежит внутри base_dir (после нормализации).

    Защита от path traversal: '../../etc/passwd'. Чистая функция на строках —
    использует posix-style нормализацию через os.path (caller передаёт уже
    абсолютные пути).
    """
    import os

    base = os.path.normpath(os.path.abspath(base_dir))
    target = os.path.normpath(os.path.abspath(candidate))
    return target == base or target.startswith(base + os.sep)
