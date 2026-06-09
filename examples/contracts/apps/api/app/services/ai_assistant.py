"""AI Assistant actions — tool-use схемы + чистая логика propose/confirm.

Эпик: AI-ассистент (нижний правый помощник) умеет СОЗДАВАТЬ сделку, задачу
или черновик договора через диалог, собирая обязательные поля у менеджера.

Архитектура безопасности (propose → confirm):
1. `/api/ai/assistant/message` — модель Claude (tool-use) либо задаёт уточняющий
   вопрос, либо предлагает tool-call. Мы НЕ исполняем его сразу — возвращаем
   `proposed_action {type, args, summary}`. Менеджер видит превью.
2. `/api/ai/assistant/confirm` — менеджер подтверждает; только тогда роутер
   вызывает существующий create-flow (deals/activities/contracts), переиспользуя
   их валидацию и бизнес-правила.

Этот модуль — ЧИСТЫЙ (без сети, без БД). Здесь:
- TOOL_SCHEMAS — anthropic tool-use схемы для 3 действий.
- ASSISTANT_SYSTEM_PROMPT — системный промпт ассистента.
- ACTION_REQUIRED_FIELDS — минимально-обязательные поля на каждое действие.
- validate_and_normalize_args() — нормализация/валидация аргументов tool-call.
- build_proposed_action() / missing_required() — propose state-machine.

Роутер (`ai_chat.py`) импортирует это и оборачивает в endpoint'ы. БД-резолв
(company_name → company_id, дефолтная sales-воронка) и собственно создание —
в роутере, т.к. требуют AsyncSession.

Тесты: `tests/test_ai_assistant.py` — pure-function, без сети/БД.
"""
from __future__ import annotations

from typing import Any

# ============ Whitelist'ы ============

ACTION_TYPES: tuple[str, ...] = ("create_task", "create_deal", "create_contract")

# Activity.kind whitelist — зеркалит app.services.activities.ACTIVITY_KINDS.
TASK_KINDS: tuple[str, ...] = ("call", "meeting", "task", "note")

# Contract.product_code whitelist — зеркалит ContractIn.product_code pattern.
CONTRACT_PRODUCT_CODES: tuple[str, ...] = ("macrocrm", "macrosales", "macroerp")

# target_type whitelist для привязки задачи (подмножество — что ассистент
# умеет линковать). Остальные target_type создаются без линка.
TASK_TARGET_TYPES: tuple[str, ...] = ("deal", "company", "contact", "lead")


# ============ Tool-use схемы (anthropic format) ============

TOOL_CREATE_TASK: dict[str, Any] = {
    "name": "create_task",
    "description": (
        "Создать задачу/активность (звонок, встреча, задача, заметка) для "
        "текущего менеджера. Используй, когда пользователь просит поставить "
        "задачу, напомнить, запланировать звонок или встречу."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "title": {
                "type": "string",
                "description": "Краткое название задачи (обязательно).",
            },
            "kind": {
                "type": "string",
                "enum": list(TASK_KINDS),
                "description": "Тип: call|meeting|task|note. По умолчанию task.",
            },
            "due_at": {
                "type": "string",
                "description": "Срок ISO 8601 (YYYY-MM-DDTHH:MM:SS), опционально.",
            },
            "responsible_id": {
                "type": "integer",
                "description": "ID ответственного. По умолчанию — текущий пользователь.",
            },
            "target_type": {
                "type": "string",
                "enum": list(TASK_TARGET_TYPES),
                "description": "Тип привязки (deal|company|contact|lead), опционально.",
            },
            "target_id": {
                "type": "integer",
                "description": "ID привязанной сущности (вместе с target_type).",
            },
            "description": {
                "type": "string",
                "description": "Подробное описание задачи, опционально.",
            },
        },
        "required": ["title"],
    },
}

TOOL_CREATE_DEAL: dict[str, Any] = {
    "name": "create_deal",
    "description": (
        "Создать сделку в воронке продаж для текущего менеджера. Используй, "
        "когда пользователь просит завести/создать сделку по клиенту."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "title": {
                "type": "string",
                "description": "Название сделки (обязательно).",
            },
            "company_name": {
                "type": "string",
                "description": "Название компании-клиента (если company_id неизвестен).",
            },
            "company_id": {
                "type": "integer",
                "description": "ID компании, если известен.",
            },
            "pipeline_id": {
                "type": "integer",
                "description": "ID воронки. По умолчанию — воронка продаж.",
            },
            "product": {
                "type": "string",
                "description": "Продукт/услуга по сделке, опционально.",
            },
            "amount": {
                "type": "number",
                "description": "Сумма сделки, опционально.",
            },
            "currency": {
                "type": "string",
                "description": "Валюта (RUB|USD|EUR|KZT...), опционально.",
            },
            "owner_user_id": {
                "type": "integer",
                "description": "ID владельца. По умолчанию — текущий пользователь.",
            },
        },
        "required": ["title"],
    },
}

TOOL_CREATE_CONTRACT: dict[str, Any] = {
    "name": "create_contract",
    "description": (
        "Создать ЧЕРНОВИК сублицензионного договора (status=draft) для "
        "текущего менеджера. Используй, когда пользователь просит подготовить/"
        "создать договор по клиенту."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "company_name": {
                "type": "string",
                "description": "Название компании-стороны (если company_id неизвестен).",
            },
            "company_id": {
                "type": "integer",
                "description": "ID компании-стороны, если известен.",
            },
            "product_code": {
                "type": "string",
                "enum": list(CONTRACT_PRODUCT_CODES),
                "description": "Продукт: macrocrm|macrosales|macroerp (обязательно).",
            },
            "country_code": {
                "type": "string",
                "description": "Код страны ISO alpha-2, 2 буквы (обязательно), напр. KZ.",
            },
            "city": {
                "type": "string",
                "description": "Город (обязательно).",
            },
        },
        "required": ["product_code", "country_code", "city"],
    },
}

TOOL_SCHEMAS: list[dict[str, Any]] = [
    TOOL_CREATE_TASK,
    TOOL_CREATE_DEAL,
    TOOL_CREATE_CONTRACT,
]


# ============ Минимально-обязательные поля для propose ============
#
# Это поля, БЕЗ которых ассистент не должен предлагать действие (должен
# продолжать спрашивать). Поля с дефолтами (owner, pipeline, kind) сюда НЕ
# входят — они подставляются автоматически. Для задачи также допускаем привязку
# через target — но она не обязательна (standalone-задача валидна).

ACTION_REQUIRED_FIELDS: dict[str, tuple[str, ...]] = {
    "create_task": ("title",),
    # для сделки — нужны title И хоть какая-то компания (id или name)
    "create_deal": ("title", "company"),
    # для договора — продукт, страна, город И компания
    "create_contract": ("product_code", "country_code", "city", "company"),
}


# ============ System prompt ============

ASSISTANT_SYSTEM_PROMPT = """Ты — AI-ассистент менеджера MACRO CRM. \
Помимо ответов на вопросы ты умеешь СОЗДАВАТЬ сущности через инструменты:
- create_task — поставить задачу/звонок/встречу/заметку;
- create_deal — завести сделку в воронке продаж;
- create_contract — подготовить ЧЕРНОВИК сублицензионного договора.

Правила:
- Все действия выполняются ОТ ИМЕНИ текущего менеджера (владелец/ответственный — он).
- Прежде чем вызвать инструмент, убедись, что собрал все обязательные поля. \
Если чего-то не хватает — задай короткий уточняющий вопрос на русском, НЕ вызывай \
инструмент с пустыми обязательными полями.
- Для сделки нужны: название и компания (название или ID).
- Для договора нужны: продукт (macrocrm|macrosales|macroerp), страна (2 буквы), \
город и компания.
- Для задачи достаточно названия; тип по умолчанию — task.
- Когда всех данных хватает — вызови соответствующий инструмент с собранными \
аргументами. Система НЕ создаст ничего сразу: менеджер увидит превью и подтвердит. \
Не выдумывай данные, которых пользователь не дал.
- Отвечай кратко, на русском языке."""


# ============ Нормализация / валидация ============

class AssistantArgError(ValueError):
    """Аргументы tool-call невалидны (после нормализации)."""


def _clean_str(v: Any) -> str | None:
    if v is None:
        return None
    s = str(v).strip()
    return s or None


def _clean_int(v: Any) -> int | None:
    if v is None or v == "":
        return None
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def validate_and_normalize_args(action_type: str, args: dict[str, Any]) -> dict[str, Any]:
    """Нормализовать + (мягко) провалидировать аргументы tool-call.

    Возвращает очищенный dict аргументов. НЕ требует обязательных полей —
    это делает `missing_required` (для propose-логики). Бросает
    AssistantArgError на грубо-невалидные значения (неизвестный enum, кривой
    country_code), чтобы не дать модели создать мусор.
    """
    if action_type not in ACTION_TYPES:
        raise AssistantArgError(f"Неизвестное действие: {action_type}")
    raw = dict(args or {})

    if action_type == "create_task":
        return _normalize_task_args(raw)
    if action_type == "create_deal":
        return _normalize_deal_args(raw)
    return _normalize_contract_args(raw)


def _normalize_task_args(raw: dict[str, Any]) -> dict[str, Any]:
    out: dict[str, Any] = {}
    out["title"] = _clean_str(raw.get("title"))
    kind = _clean_str(raw.get("kind")) or "task"
    if kind not in TASK_KINDS:
        raise AssistantArgError(
            f"Недопустимый тип задачи: {kind}. Ожидается {list(TASK_KINDS)}"
        )
    out["kind"] = kind
    out["due_at"] = _clean_str(raw.get("due_at"))
    out["responsible_id"] = _clean_int(raw.get("responsible_id"))
    out["description"] = _clean_str(raw.get("description"))
    tt = _clean_str(raw.get("target_type"))
    tid = _clean_int(raw.get("target_id"))
    # target_type и target_id — только вместе.
    if (tt is None) != (tid is None):
        raise AssistantArgError(
            "target_type и target_id указываются вместе либо оба пусты"
        )
    if tt is not None and tt not in TASK_TARGET_TYPES:
        raise AssistantArgError(
            f"Недопустимый target_type: {tt}. Ожидается {list(TASK_TARGET_TYPES)}"
        )
    out["target_type"] = tt
    out["target_id"] = tid
    return out


def _normalize_deal_args(raw: dict[str, Any]) -> dict[str, Any]:
    out: dict[str, Any] = {}
    out["title"] = _clean_str(raw.get("title"))
    out["company_name"] = _clean_str(raw.get("company_name"))
    out["company_id"] = _clean_int(raw.get("company_id"))
    out["pipeline_id"] = _clean_int(raw.get("pipeline_id"))
    out["product"] = _clean_str(raw.get("product"))
    out["owner_user_id"] = _clean_int(raw.get("owner_user_id"))
    amount = raw.get("amount")
    if amount is not None and amount != "":
        try:
            out["amount"] = float(amount)
        except (TypeError, ValueError):
            raise AssistantArgError(f"Сумма должна быть числом, получено: {amount!r}")
    else:
        out["amount"] = None
    cur = _clean_str(raw.get("currency"))
    out["currency"] = cur.upper() if cur else None
    return out


def _normalize_contract_args(raw: dict[str, Any]) -> dict[str, Any]:
    out: dict[str, Any] = {}
    out["company_name"] = _clean_str(raw.get("company_name"))
    out["company_id"] = _clean_int(raw.get("company_id"))
    pc = _clean_str(raw.get("product_code"))
    if pc is not None:
        pc = pc.lower()
        if pc not in CONTRACT_PRODUCT_CODES:
            raise AssistantArgError(
                f"Недопустимый продукт: {pc}. Ожидается {list(CONTRACT_PRODUCT_CODES)}"
            )
    out["product_code"] = pc
    cc = _clean_str(raw.get("country_code"))
    if cc is not None:
        cc = cc.upper()
        if len(cc) != 2 or not cc.isalpha():
            raise AssistantArgError(
                f"country_code должен быть 2 буквы ISO alpha-2, получено: {cc!r}"
            )
    out["country_code"] = cc
    out["city"] = _clean_str(raw.get("city"))
    return out


def missing_required(action_type: str, normalized_args: dict[str, Any]) -> list[str]:
    """Какие обязательные «логические» поля ещё не заполнены.

    Особый случай 'company' — заполнено, если есть company_id ИЛИ company_name.
    Пустой список = можно предлагать действие (propose).
    """
    required = ACTION_REQUIRED_FIELDS.get(action_type, ())
    missing: list[str] = []
    for field in required:
        if field == "company":
            if not normalized_args.get("company_id") and not normalized_args.get(
                "company_name"
            ):
                missing.append("company")
            continue
        if not normalized_args.get(field):
            missing.append(field)
    return missing


# ============ Summary / propose ============

_ACTION_TITLES_RU: dict[str, str] = {
    "create_task": "Создать задачу",
    "create_deal": "Создать сделку",
    "create_contract": "Создать черновик договора",
}

_KIND_TITLES_RU: dict[str, str] = {
    "call": "Звонок",
    "meeting": "Встреча",
    "task": "Задача",
    "note": "Заметка",
}


def build_action_summary(action_type: str, normalized_args: dict[str, Any]) -> str:
    """Человеко-читаемое RU-резюме предлагаемого действия (для превью)."""
    if action_type == "create_task":
        kind_ru = _KIND_TITLES_RU.get(normalized_args.get("kind") or "task", "Задача")
        title = normalized_args.get("title") or "(без названия)"
        parts = [f"{kind_ru}: «{title}»"]
        if normalized_args.get("due_at"):
            parts.append(f"срок {normalized_args['due_at']}")
        if normalized_args.get("target_type") and normalized_args.get("target_id"):
            parts.append(
                f"привязка к {normalized_args['target_type']}#{normalized_args['target_id']}"
            )
        return ", ".join(parts)

    if action_type == "create_deal":
        title = normalized_args.get("title") or "(без названия)"
        company = normalized_args.get("company_name") or (
            f"компания #{normalized_args['company_id']}"
            if normalized_args.get("company_id")
            else "клиент не указан"
        )
        parts = [f"Сделка «{title}» — {company}"]
        if normalized_args.get("amount") is not None:
            cur = normalized_args.get("currency") or ""
            parts.append(f"{normalized_args['amount']:g} {cur}".strip())
        if normalized_args.get("product"):
            parts.append(f"продукт: {normalized_args['product']}")
        return ", ".join(parts)

    # create_contract
    company = normalized_args.get("company_name") or (
        f"компания #{normalized_args['company_id']}"
        if normalized_args.get("company_id")
        else "сторона не указана"
    )
    return (
        f"Черновик договора: {normalized_args.get('product_code')} / "
        f"{normalized_args.get('country_code')} / {normalized_args.get('city')} "
        f"— {company}"
    )


def build_proposed_action(
    action_type: str, normalized_args: dict[str, Any]
) -> dict[str, Any]:
    """Собрать proposed_action {type, args, summary} для ответа клиенту.

    Caller гарантирует, что missing_required вернул [] (иначе нечего предлагать).
    """
    return {
        "type": action_type,
        "args": normalized_args,
        "summary": build_action_summary(action_type, normalized_args),
        "title": _ACTION_TITLES_RU.get(action_type, action_type),
    }


__all__ = [
    "ACTION_TYPES",
    "TASK_KINDS",
    "CONTRACT_PRODUCT_CODES",
    "TASK_TARGET_TYPES",
    "TOOL_SCHEMAS",
    "TOOL_CREATE_TASK",
    "TOOL_CREATE_DEAL",
    "TOOL_CREATE_CONTRACT",
    "ACTION_REQUIRED_FIELDS",
    "ASSISTANT_SYSTEM_PROMPT",
    "AssistantArgError",
    "validate_and_normalize_args",
    "missing_required",
    "build_action_summary",
    "build_proposed_action",
]
