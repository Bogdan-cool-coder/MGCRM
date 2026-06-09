"""Wave 4 (deal-card rework) — чистые функции (без БД).

Содержит:
- line_amount / deal_total — расчёт суммы позиции и авто-суммы сделки;
- deal-card-config: дефолт, нормализация, валидация (Pipeline.settings →
  deal_card_fields / stage_required_fields);
- required-field валидация перехода по этапам (предикат missing-fields).

Всё — pure-функции, юнит-тестируются напрямую без БД.
"""
from __future__ import annotations

from decimal import Decimal
from typing import Any

# ============ 1. Авто-сумма позиций ============

def line_amount(quantity: Decimal | float | int, unit_price: Decimal | float | int) -> Decimal:
    """Сумма позиции = quantity * unit_price (округление до 2 знаков)."""
    q = quantity if isinstance(quantity, Decimal) else Decimal(str(quantity))
    p = unit_price if isinstance(unit_price, Decimal) else Decimal(str(unit_price))
    return (q * p).quantize(Decimal("0.01"))


def deal_total(
    lines: list[tuple[Decimal | float | int, str]],
    currency: str | None,
) -> Decimal:
    """Авто-сумма сделки = сумма amount по позициям В ВАЛЮТЕ СДЕЛКИ.

    lines — список (amount, line_currency). Если currency сделки задан, в сумму
    идут только позиции в этой валюте (мультивалютные строки не складываем).
    Если currency сделки None — берём валюту первой позиции (caller обязан затем
    проставить deal.currency). Возвращает Decimal с 2 знаками.
    """
    target = currency
    if target is None and lines:
        target = lines[0][1]
    total = Decimal("0")
    for amount, line_cur in lines:
        if target is not None and line_cur != target:
            continue
        a = amount if isinstance(amount, Decimal) else Decimal(str(amount))
        total += a
    return total.quantize(Decimal("0.01"))


# ============ 2. Deal-card-config (Pipeline.settings → deal_card_fields) ============

# Стандартные поля карточки сделки. field — ключ; label — RU-подпись (дефолт).
STANDARD_DEAL_CARD_FIELDS: tuple[tuple[str, str], ...] = (
    ("amount", "Сумма"),
    ("currency", "Валюта"),
    ("owner_user_id", "Ответственный"),
    ("expected_close_date", "Ожидаемое закрытие"),
    ("expected_sign_date", "Ожидаемое подписание"),
    ("expected_payment_date", "Ожидаемая оплата"),
    ("product", "Продукт (текст)"),
    ("tags", "Теги"),
    ("contacts", "Контакты"),
)

STANDARD_FIELD_KEYS: frozenset[str] = frozenset(f for f, _ in STANDARD_DEAL_CARD_FIELDS)

# Ключ в Pipeline.settings, под которым лежит конфиг карточки сделки.
DEAL_CARD_FIELDS_KEY = "deal_card_fields"
STAGE_REQUIRED_FIELDS_KEY = "stage_required_fields"


def default_deal_card_config() -> dict[str, Any]:
    """Дефолтный конфиг карточки: все стандартные поля видимы, по порядку,
    ничего не required. stage_required_fields пуст."""
    return {
        DEAL_CARD_FIELDS_KEY: [
            {"field": f, "label": label, "visible": True, "order": i, "required": False}
            for i, (f, label) in enumerate(STANDARD_DEAL_CARD_FIELDS)
        ],
        STAGE_REQUIRED_FIELDS_KEY: {},
    }


def normalize_deal_card_config(raw: dict[str, Any] | None) -> dict[str, Any]:
    """Привести сырой конфиг к каноничному виду (с дефолтами).

    - deal_card_fields: список {field, label?, visible, order, required}.
      Невалидные элементы (без field-строки) отбрасываются. Дедуп по field.
    - stage_required_fields: dict {stage_id(str): [field,...]}.
    Если raw пуст/None — возвращаем default_deal_card_config().
    """
    raw = raw or {}
    fields_raw = raw.get(DEAL_CARD_FIELDS_KEY)
    if not isinstance(fields_raw, list) or not fields_raw:
        return default_deal_card_config()

    seen: set[str] = set()
    fields: list[dict[str, Any]] = []
    for i, item in enumerate(fields_raw):
        if not isinstance(item, dict):
            continue
        field = item.get("field")
        if not isinstance(field, str) or not field or field in seen:
            continue
        seen.add(field)
        entry: dict[str, Any] = {
            "field": field,
            "visible": bool(item.get("visible", True)),
            "order": int(item.get("order", i)),
            "required": bool(item.get("required", False)),
        }
        label = item.get("label")
        if isinstance(label, str) and label:
            entry["label"] = label
        fields.append(entry)

    if not fields:
        return default_deal_card_config()

    # stage_required_fields: ключи-строки → список field-строк.
    srf_raw = raw.get(STAGE_REQUIRED_FIELDS_KEY)
    stage_required: dict[str, list[str]] = {}
    if isinstance(srf_raw, dict):
        for k, v in srf_raw.items():
            if not isinstance(v, list):
                continue
            flds = [f for f in v if isinstance(f, str) and f]
            if flds:
                stage_required[str(k)] = flds

    return {
        DEAL_CARD_FIELDS_KEY: fields,
        STAGE_REQUIRED_FIELDS_KEY: stage_required,
    }


def validate_deal_card_config(raw: dict[str, Any]) -> list[str]:
    """Проверяет входящий конфиг. Возвращает список ошибок (RU), пусто = ok."""
    errors: list[str] = []
    if not isinstance(raw, dict):
        return ["deal_card_config должен быть объектом"]
    fields = raw.get(DEAL_CARD_FIELDS_KEY)
    if fields is not None:
        if not isinstance(fields, list):
            errors.append(f"{DEAL_CARD_FIELDS_KEY} должно быть списком")
        else:
            for item in fields:
                if not isinstance(item, dict) or not isinstance(item.get("field"), str) or not item.get("field"):
                    errors.append("каждый элемент deal_card_fields должен иметь непустой field (строка)")
                    break
    srf = raw.get(STAGE_REQUIRED_FIELDS_KEY)
    if srf is not None and not isinstance(srf, dict):
        errors.append(f"{STAGE_REQUIRED_FIELDS_KEY} должно быть объектом {{stage_id: [field,...]}}")
    return errors


def required_fields_for_stage(config: dict[str, Any], stage_id: int) -> list[str]:
    """Список обязательных полей для перехода в этап stage_id.

    Приоритет: per-stage override (stage_required_fields[stage_id]) если задан;
    иначе — pipeline-level (deal_card_fields с required=True).
    """
    config = normalize_deal_card_config(config)
    srf = config.get(STAGE_REQUIRED_FIELDS_KEY, {})
    stage_key = str(stage_id)
    if isinstance(srf, dict) and stage_key in srf and srf[stage_key]:
        return list(srf[stage_key])
    return [
        f["field"]
        for f in config.get(DEAL_CARD_FIELDS_KEY, [])
        if f.get("required")
    ]


def _is_empty(value: Any) -> bool:
    """Поле считается пустым, если None / пустая строка / пустой список."""
    if value is None:
        return True
    if isinstance(value, str) and not value.strip():
        return True
    if isinstance(value, (list, tuple)) and len(value) == 0:
        return True
    return False


def missing_required_fields(
    required: list[str],
    field_values: dict[str, Any],
) -> list[str]:
    """Какие из required-полей пусты в field_values.

    field_values — снимок значений полей сделки (включая 'contacts' = кол-во
    привязанных контактов / список). Сохраняет порядок required.
    """
    return [f for f in required if _is_empty(field_values.get(f))]
