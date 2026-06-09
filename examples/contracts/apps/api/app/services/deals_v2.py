"""DEALS 2.0 (Ф0) — слияние Лиды+Сделки.

Чистые функции для:
- канонической структуры sales-воронки «Продажи» (NEW_SALES_STAGES);
- маппинга старых AMO-этапов → новые (remap_old_stage_name);
- маппинга lead.stage.code → новый этап sales-воронки (lead_stage_to_sales_stage);
- маппинга полей Lead → Deal (lead_to_deal_fields);
- сида причин отказа (DEFAULT_LOST_REASONS).

Без БД-зависимостей (input → output) — покрыто unit-тестами и переиспользуется
сидером services/deals.py + миграциями 0074/0075/0076.
"""
from __future__ import annotations

from typing import Any

# ============ Канонические имена этапов sales-воронки ============
# Дублируются в коде как «источник истины» для матча по имени при ремапе.

ST_LOST = "Сделка проиграна"
ST_NEW = "Новые лиды"
ST_QUALIFY = "Квалификация"
ST_SCHEDULE_MEETING = "Назначить встречу"
ST_MEETING = "Встреча"
ST_COLD = "Холодные (заморозка)"
ST_WARM = "Тёплые"
ST_HOT = "Горячие"
ST_WON = "Успешная сделка"
ST_AWAIT_PAYMENT = "Ожидаем оплату"
ST_PAID = "Оплачено"

# Машинные коды (стабильный идентификатор этапа для матча между прогонами и
# для семантической привязки автоматизаций/переходов).
CODE_LOST = "lost"
CODE_NEW = "new"
CODE_QUALIFY = "qualify"
CODE_SCHEDULE_MEETING = "schedule_meeting"
CODE_MEETING = "meeting"
CODE_COLD = "cold"
CODE_WARM = "warm"
CODE_HOT = "hot"
CODE_WON = "won"
CODE_AWAIT_PAYMENT = "await_payment"
CODE_PAID = "paid"

# stage_features whitelist (валидируется на уровне сервиса/роутера Ф1).
FEATURE_SEND_PRESENTATION = "send_presentation"
FEATURE_MEETING_REPORT = "meeting_report"
FEATURE_GENERATE_DOCUMENT = "generate_document"

STAGE_FEATURES_WHITELIST: frozenset[str] = frozenset(
    {FEATURE_SEND_PRESENTATION, FEATURE_MEETING_REPORT, FEATURE_GENERATE_DOCUMENT}
)


# Каноническая структура «Продажи» (слева→направо). Каждый dict — спека этапа.
# parent_code — код родительского этапа (для подстатусов под «Успех»); None —
# верхнеуровневый этап. is_child=True → этап-подстатус (parent_stage_id заполнится
# при сиде/миграции по resolve(parent_code)).
#
# Порядок sort_order: 1=Проиграна (крайняя левая) … далее по воронке.
# Подстатусы «Ожидаем оплату»/«Оплачено» идут sort=10/11, но рендерятся под
# «Успешная сделка» (parent_code=won).
NEW_SALES_STAGES: list[dict[str, Any]] = [
    {
        "name": ST_LOST, "code": CODE_LOST, "sort_order": 1,
        "color": "#6B7280", "is_won": False, "is_lost": True,
        "hidden_by_default": True, "won_gate": False,
        "stage_features": [], "parent_code": None,
    },
    {
        "name": ST_NEW, "code": CODE_NEW, "sort_order": 2,
        "color": "#9AA6BF", "is_won": False, "is_lost": False,
        "hidden_by_default": False, "won_gate": False,
        "stage_features": [], "parent_code": None,
    },
    {
        "name": ST_QUALIFY, "code": CODE_QUALIFY, "sort_order": 3,
        "color": "#E6B800", "is_won": False, "is_lost": False,
        "hidden_by_default": False, "won_gate": False,
        "stage_features": [], "parent_code": None,
    },
    {
        "name": ST_SCHEDULE_MEETING, "code": CODE_SCHEDULE_MEETING, "sort_order": 4,
        "color": "#39A85B", "is_won": False, "is_lost": False,
        "hidden_by_default": False, "won_gate": False,
        "stage_features": [FEATURE_SEND_PRESENTATION], "parent_code": None,
    },
    {
        "name": ST_MEETING, "code": CODE_MEETING, "sort_order": 5,
        "color": "#7C5CBF", "is_won": False, "is_lost": False,
        "hidden_by_default": False, "won_gate": False,
        "stage_features": [FEATURE_MEETING_REPORT], "parent_code": None,
    },
    {
        "name": ST_COLD, "code": CODE_COLD, "sort_order": 6,
        "color": "#3B82C4", "is_won": False, "is_lost": False,
        "hidden_by_default": True, "won_gate": False,
        "stage_features": [], "parent_code": None,
    },
    {
        "name": ST_WARM, "code": CODE_WARM, "sort_order": 7,
        "color": "#E8853A", "is_won": False, "is_lost": False,
        "hidden_by_default": False, "won_gate": False,
        "stage_features": [FEATURE_GENERATE_DOCUMENT], "parent_code": None,
    },
    {
        "name": ST_HOT, "code": CODE_HOT, "sort_order": 8,
        "color": "#D14545", "is_won": False, "is_lost": False,
        "hidden_by_default": False, "won_gate": False,
        "stage_features": [FEATURE_GENERATE_DOCUMENT], "parent_code": None,
    },
    {
        "name": ST_WON, "code": CODE_WON, "sort_order": 9,
        "color": "#1F9D55", "is_won": True, "is_lost": False,
        "hidden_by_default": False, "won_gate": True,
        "stage_features": [], "parent_code": None,
    },
    {
        "name": ST_AWAIT_PAYMENT, "code": CODE_AWAIT_PAYMENT, "sort_order": 10,
        "color": "#1F9D55", "is_won": True, "is_lost": False,
        "hidden_by_default": False, "won_gate": False,
        "stage_features": [], "parent_code": CODE_WON,
    },
    {
        "name": ST_PAID, "code": CODE_PAID, "sort_order": 11,
        "color": "#15803D", "is_won": True, "is_lost": False,
        "hidden_by_default": False, "won_gate": False,
        "stage_features": [], "parent_code": CODE_WON,
    },
]


# ============ Ремап старых AMO-этапов → новые ============
# Ключ — точное старое имя этапа (см. AMO_STAGES в services/deals.py до Ф0),
# значение — code нового этапа. Все 12 старых имён покрыты.
OLD_STAGE_NAME_TO_NEW_CODE: dict[str, str] = {
    "Входящие лиды": CODE_NEW,
    "Исходящие лиды": CODE_NEW,
    "Квалификация": CODE_QUALIFY,
    "Назначить встречу": CODE_SCHEDULE_MEETING,
    "Выезд": CODE_MEETING,
    "Встреча": CODE_MEETING,
    "Холодные (заморозка)": CODE_COLD,
    "Холодные": CODE_COLD,
    "Тёплые": CODE_WARM,
    "Trial": CODE_WARM,
    "Горячие": CODE_HOT,
    "Успех": CODE_WON,
    "Успешная сделка": CODE_WON,
    "Проигрыш": CODE_LOST,
    "Проиграно": CODE_LOST,
    "Сделка проиграна": CODE_LOST,
}


def remap_old_stage_name(old_name: str | None) -> str:
    """Старое имя этапа → code нового этапа sales-воронки.

    Неизвестные/пустые имена попадают в «Новые лиды» (безопасный дефолт: НЕ
    won/lost, не теряем сделку). Это гарантирует, что НИ ОДНА сделка не
    останется на удалённом этапе после ремапа.

    >>> remap_old_stage_name("Успех")
    'won'
    >>> remap_old_stage_name("Trial")
    'warm'
    >>> remap_old_stage_name("Выезд")
    'meeting'
    >>> remap_old_stage_name("неизвестный этап")
    'new'
    >>> remap_old_stage_name(None)
    'new'
    """
    if not old_name:
        return CODE_NEW
    return OLD_STAGE_NAME_TO_NEW_CODE.get(old_name.strip(), CODE_NEW)


# ============ Ремап lead.stage.code → этап sales-воронки ============
# Коды этапов lead-воронки (см. services/leads.py LEAD_STAGES):
# new / processing / qualified / in_work / archived.
LEAD_STAGE_CODE_TO_SALES_CODE: dict[str, str] = {
    "new": CODE_NEW,
    "processing": CODE_NEW,
    "qualified": CODE_QUALIFY,
    "in_work": CODE_QUALIFY,
    "archived": CODE_LOST,
}

# Доп. ремап по lead.status (если stage недоступен — fallback на статус).
LEAD_STATUS_TO_SALES_CODE: dict[str, str] = {
    "active": CODE_NEW,
    "converted": CODE_QUALIFY,
    "archived": CODE_LOST,
    "lost": CODE_LOST,
}


# Множество валидных кодов sales-воронки (для passthrough в lead_stage_to_sales_code).
_SALES_STAGE_CODES: frozenset[str] = frozenset(s["code"] for s in NEW_SALES_STAGES)


def lead_stage_to_sales_code(
    lead_stage_code: str | None, lead_status: str | None = None
) -> str:
    """Этап лида (по code) → code этапа sales-воронки.

    Приоритет:
    1. lead_stage_code, если это уже валидный sales-code (passthrough). Нужно для
       legacy-лидов, которые жили прямо в sales-воронке: после ремапа 0075 их
       stage уже имеет sales-code ('qualify'/'lost'/...), а не lead-code.
    2. lead_stage_code в lead-маппинге (new/processing/qualified/in_work/archived).
    3. lead_status (active/converted/archived/lost).
    4. «Новые лиды».

    >>> lead_stage_to_sales_code("qualified")
    'qualify'
    >>> lead_stage_to_sales_code("qualify")     # уже sales-code (passthrough)
    'qualify'
    >>> lead_stage_to_sales_code("archived")
    'lost'
    >>> lead_stage_to_sales_code(None, "lost")
    'lost'
    >>> lead_stage_to_sales_code(None, None)
    'new'
    """
    if lead_stage_code and lead_stage_code in _SALES_STAGE_CODES:
        return lead_stage_code
    if lead_stage_code and lead_stage_code in LEAD_STAGE_CODE_TO_SALES_CODE:
        return LEAD_STAGE_CODE_TO_SALES_CODE[lead_stage_code]
    if lead_status and lead_status in LEAD_STATUS_TO_SALES_CODE:
        return LEAD_STATUS_TO_SALES_CODE[lead_status]
    return CODE_NEW


def lead_to_deal_fields(lead: dict[str, Any]) -> dict[str, Any]:
    """Lead (dict-вид) → поля для создания Deal.

    Чистая функция (input dict → output dict) — для unit-теста и переиспользования
    в миграции 0076 (Lead→Company+Deal). НЕ резолвит company_id/stage_id (это
    делает миграция, ей нужен доступ к БД); возвращает только переносимые скаляры.

    - title ← lead.name (NOT NULL у Deal.title);
    - owner_user_id ← lead.owner_id;
    - department_id ← lead.department_id;
    - sales_stage_code ← lead_stage_to_sales_code(stage_code, status) — миграция
      резолвит конкретный stage_id sales-воронки по этому коду.

    >>> f = lead_to_deal_fields({"name": "ООО Ромашка", "owner_id": 5, \
        "stage_code": "qualified", "status": "active", "department_id": 2})
    >>> f["title"]
    'ООО Ромашка'
    >>> f["owner_user_id"]
    5
    >>> f["sales_stage_code"]
    'qualify'
    >>> f["department_id"]
    2
    """
    name = (lead.get("name") or "").strip() or "Лид без названия"
    return {
        "title": name,
        "owner_user_id": lead.get("owner_id"),
        "department_id": lead.get("department_id"),
        "sales_stage_code": lead_stage_to_sales_code(
            lead.get("stage_code"), lead.get("status")
        ),
    }


def lead_to_company_fields(lead: dict[str, Any]) -> dict[str, Any]:
    """Lead (dict-вид) → поля для создания Company (если зеркала ещё нет).

    Только если lead.converted_to_company_id IS NULL. name берётся из lead.name;
    email/phone/source/tags переносятся 1:1. country НЕ задаётся (Counterparty-
    зеркало проставит дефолт KZ через company_to_counterparty_fields).

    >>> c = lead_to_company_fields({"name": "ООО Ромашка", \
        "contact_email": "a@b.c", "contact_phone": "+7700", \
        "source": "form", "tags": ["vip"]})
    >>> c["name"], c["email"], c["phone"], c["source"], c["tags"]
    ('ООО Ромашка', 'a@b.c', '+7700', 'form', ['vip'])
    """
    name = (lead.get("name") or "").strip() or "Лид без названия"
    return {
        "name": name,
        "legal_name": name,
        "email": lead.get("contact_email"),
        "phone": lead.get("contact_phone"),
        "source": lead.get("source") or "lead",
        "tags": list(lead.get("tags") or []),
    }


# ============ Реестр причин отказа (LostReason seed) ============
# (name, sort_order). is_active=True по умолчанию.
DEFAULT_LOST_REASONS: list[tuple[str, int]] = [
    ("Дорого", 1),
    ("Используют другую систему", 2),
    ("Закрываются", 3),
    ("Не вышли на ЛПР", 4),
    ("Нет бюджета", 5),
]


# ============ DEALS 2.0 (Ф1b) — валидация переходов и настроек воронки ============

# Whitelist предикатов для PipelineTransition.conditions. Executor (Ф1+) умеет
# проверять только эти ключи; неизвестные ключи отклоняем на этапе сохранения,
# чтобы в БД не оседали «мёртвые» условия, которые никогда не сработают.
TRANSITION_CONDITION_KEYS: frozenset[str] = frozenset(
    {
        "require_signed_scan",  # bool — требовать подписанный скан договора
        "require_paid",         # bool — требовать зафиксированную оплату
        "require_field",        # str | list[str] — обяз. заполненные поля сделки
    }
)


def validate_transition_conditions(conditions: dict[str, Any]) -> list[str]:
    """Проверяет conditions PipelineTransition. Возвращает список ошибок (RU),
    пусто = валидно.

    Bool-ключи (require_signed_scan/require_paid) — должны быть bool.
    require_field — str (одно поле) ИЛИ list[str] (несколько). Неизвестные ключи
    запрещены (см. TRANSITION_CONDITION_KEYS).
    """
    errors: list[str] = []
    if not isinstance(conditions, dict):
        return ["conditions должен быть объектом"]
    for key in conditions:
        if key not in TRANSITION_CONDITION_KEYS:
            errors.append(f"неизвестное условие перехода: {key}")
    for bkey in ("require_signed_scan", "require_paid"):
        if bkey in conditions and not isinstance(conditions[bkey], bool):
            errors.append(f"{bkey} должно быть true/false")
    if "require_field" in conditions:
        rf = conditions["require_field"]
        ok = isinstance(rf, str) and rf.strip() or (
            isinstance(rf, list) and rf and all(
                isinstance(x, str) and x.strip() for x in rf
            )
        )
        if not ok:
            errors.append("require_field должно быть непустой строкой или списком строк")
    return errors


# Whitelist полей для duplicate_check_fields воронки (по каким полям компании
# искать дубль входящей сделки). Совпадает с матчинг-логикой services/duplicates.
DUPLICATE_CHECK_FIELD_WHITELIST: frozenset[str] = frozenset(
    {"email", "phone", "name", "inn", "website"}
)

# Дефолтные настройки воронки (используются если Pipeline.settings пуст/частичен).
DEFAULT_PIPELINE_SETTINGS: dict[str, Any] = {
    "auto_assign": False,          # авто-распределение из «Неразобранное»
    "duplicate_check_enabled": False,
    "duplicate_check_fields": ["email", "phone"],
}


def normalize_pipeline_settings(raw: dict[str, Any] | None) -> dict[str, Any]:
    """Сводит произвольный settings-словарь к каноничному набору ключей с
    дефолтами. Лишние ключи отбрасываются, типы приводятся. Чистая функция —
    переиспользуется роутером PATCH /pipelines/{id}/settings и GET.
    """
    raw = raw or {}
    auto_assign = bool(raw.get("auto_assign", DEFAULT_PIPELINE_SETTINGS["auto_assign"]))
    dup_enabled = bool(
        raw.get("duplicate_check_enabled", DEFAULT_PIPELINE_SETTINGS["duplicate_check_enabled"])
    )
    fields_raw = raw.get("duplicate_check_fields", DEFAULT_PIPELINE_SETTINGS["duplicate_check_fields"])
    if not isinstance(fields_raw, list):
        fields_raw = []
    # Только whitelist-поля, дедуп с сохранением порядка.
    seen: set[str] = set()
    fields: list[str] = []
    for f in fields_raw:
        if isinstance(f, str) and f in DUPLICATE_CHECK_FIELD_WHITELIST and f not in seen:
            seen.add(f)
            fields.append(f)
    return {
        "auto_assign": auto_assign,
        "duplicate_check_enabled": dup_enabled,
        "duplicate_check_fields": fields,
    }


def validate_pipeline_settings(raw: dict[str, Any]) -> list[str]:
    """Проверяет входящий settings PATCH. Возвращает список ошибок (RU), пусто =
    валидно. Используется до normalize, чтобы вернуть 400 при невалидных полях
    дубль-чека (а не молча отбросить)."""
    errors: list[str] = []
    if not isinstance(raw, dict):
        return ["settings должен быть объектом"]
    if "duplicate_check_fields" in raw:
        fields = raw["duplicate_check_fields"]
        if not isinstance(fields, list):
            errors.append("duplicate_check_fields должно быть списком")
        else:
            bad = [
                f for f in fields
                if not (isinstance(f, str) and f in DUPLICATE_CHECK_FIELD_WHITELIST)
            ]
            if bad:
                errors.append(f"недопустимые поля дубль-чека: {bad}")
    return errors
