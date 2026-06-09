"""Custom fields (Эпик 8 / Card 2.0): whitelist + validate + normalize + patch helper.

Универсальный helper для PATCH /<entity>/<id>/extra-fields эндпоинтов: единая
точка валидации + merge'а extra_fields независимо от entity.

Whitelist'ы строгие — расширение требует sync с фронтом (типы в @/lib/types.ts).
"""
from __future__ import annotations

from datetime import date, datetime
from typing import Any

from fastapi import HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    Company,
    Contact,
    Contract,
    Counterparty,
    CustomFieldDef,
    Deal,
    Lead,
    ClientSubscription,
)

# ---------- Whitelists ----------

# Допустимые scope'ы (entity_type) для custom fields. Sync с _SCOPE_TO_MODEL ниже.
CUSTOM_FIELD_SCOPES: tuple[str, ...] = (
    "lead",
    "contact",
    "company",
    "counterparty",
    "deal",
    "contract",
    "subscription",
)

# Допустимые kind'ы (тип поля). Sync с UI CustomFieldInput.
CUSTOM_FIELD_KINDS: tuple[str, ...] = (
    "text",
    "textarea",
    "number",
    "date",
    "select",
    "multiselect",
    "url",
    "checkbox",
)

# Лимиты длины значений (DoS / JSONB-bloat guard).
MAX_VALUE_LEN = 10_000          # одно строковое значение (text/textarea/url/select)
MAX_MULTISELECT_ITEMS = 200     # элементов в multiselect

# Маппинг scope → SQLAlchemy модель. Используется в patch_extra_fields.
_SCOPE_TO_MODEL: dict[str, type[Any]] = {
    "lead": Lead,
    "contact": Contact,
    "company": Company,
    "counterparty": Counterparty,
    "deal": Deal,
    "contract": Contract,
    "subscription": ClientSubscription,
}


def validate_scope(scope: str) -> None:
    """Проверка scope, иначе 400."""
    if scope not in CUSTOM_FIELD_SCOPES:
        raise HTTPException(
            400,
            f"Недопустимый scope: {scope}. Ожидается одно из {list(CUSTOM_FIELD_SCOPES)}",
        )


def validate_kind(kind: str) -> None:
    """Проверка kind, иначе 400."""
    if kind not in CUSTOM_FIELD_KINDS:
        raise HTTPException(
            400,
            f"Недопустимый kind: {kind}. Ожидается одно из {list(CUSTOM_FIELD_KINDS)}",
        )


# ---------- Normalization ----------


def normalize_value(kind: str, raw: Any) -> Any:
    """Привести значение к JSON-friendly виду перед сохранением.

    - text / textarea / url  → str (или None если пусто/None)
    - number → float (или None)
    - date   → ISO-строка "YYYY-MM-DD" (или None)
    - select → str (или None)
    - multiselect → list[str]
    - checkbox → bool

    Любое исключение при конвертации → HTTPException 422.
    """
    # None / пустое — для опциональных полей возвращаем None одинаково.
    if raw is None:
        return None
    if isinstance(raw, str) and raw.strip() == "" and kind not in ("checkbox", "multiselect"):
        return None

    try:
        if kind in ("text", "textarea", "url", "select"):
            s = str(raw)
            if len(s) > MAX_VALUE_LEN:
                raise HTTPException(
                    422,
                    f"Значение для kind={kind} длиннее {MAX_VALUE_LEN} символов",
                )
            return s
        if kind == "number":
            # Принимаем как int/float/str ("12.5") — храним как float
            return float(raw)
        if kind == "date":
            if isinstance(raw, date):
                return raw.isoformat()
            # "YYYY-MM-DD" или "DD.MM.YYYY"
            s = str(raw).strip()
            if not s:
                return None
            # Базовый парсинг — fail-soft если уже ISO
            try:
                return datetime.strptime(s, "%Y-%m-%d").date().isoformat()
            except ValueError:
                try:
                    return datetime.strptime(s, "%d.%m.%Y").date().isoformat()
                except ValueError as ex:
                    raise HTTPException(
                        422, f"Невалидная дата: {s} (ожидается YYYY-MM-DD)"
                    ) from ex
        if kind == "multiselect":
            if not isinstance(raw, (list, tuple)):
                raise HTTPException(
                    422, "multiselect: ожидается массив строк"
                )
            if len(raw) > MAX_MULTISELECT_ITEMS:
                raise HTTPException(
                    422,
                    f"multiselect: не более {MAX_MULTISELECT_ITEMS} элементов",
                )
            items = [str(x) for x in raw]
            for it in items:
                if len(it) > MAX_VALUE_LEN:
                    raise HTTPException(
                        422,
                        f"multiselect: элемент длиннее {MAX_VALUE_LEN} символов",
                    )
            return items
        if kind == "checkbox":
            if isinstance(raw, bool):
                return raw
            if isinstance(raw, str):
                return raw.strip().lower() in ("true", "1", "yes", "да")
            return bool(raw)
    except HTTPException:
        raise
    except (ValueError, TypeError) as ex:
        raise HTTPException(422, f"Ошибка конвертации значения для kind={kind}: {ex}") from ex
    return raw


def validate_extra_fields(
    scope: str,
    extra_fields: dict[str, Any],
    defs: list[CustomFieldDef],
) -> dict[str, Any]:
    """Валидация словаря extra_fields против active дефиниций scope.

    Правила:
    - все ключи extra_fields должны быть в defs (active) — иначе 422.
    - все required defs должны иметь непустое значение в extra_fields — иначе 422.
    - значения нормализуются по kind дефиниции.
    - для select/multiselect значение должно входить в options_json (если непусто).

    Возвращает нормализованный dict (готовый к merge'у). Не мутирует вход.

    ВАЖНО: эта функция валидирует ВЕСЬ результирующий extra_fields (merge'нутый),
    не только diff. Required-проверка работает корректно только в этом режиме.
    """
    validate_scope(scope)
    active = [d for d in defs if d.is_active and d.entity_scope == scope]
    by_code = {d.code: d for d in active}

    # Проверка required
    field_errors: dict[str, str] = {}
    for d in active:
        if d.is_required:
            v = extra_fields.get(d.code)
            if v is None or (isinstance(v, str) and v.strip() == "") or (
                isinstance(v, list) and len(v) == 0
            ):
                field_errors[d.code] = f"Поле «{d.label_ru}» обязательно"

    if field_errors:
        raise HTTPException(422, {"field_errors": field_errors})

    # Нормализация + проверка options
    out: dict[str, Any] = {}
    type_errors: dict[str, str] = {}
    for code, raw in extra_fields.items():
        d = by_code.get(code)
        if d is None:
            type_errors[code] = f"Неизвестное поле: {code}"
            continue
        try:
            v = normalize_value(d.kind, raw)
        except HTTPException as ex:
            type_errors[code] = str(ex.detail)
            continue

        # Проверка options для select/multiselect
        if v is not None and d.kind in ("select", "multiselect"):
            allowed = [str(x) for x in (d.options_json or [])]
            if allowed:
                if d.kind == "select":
                    if str(v) not in allowed:
                        type_errors[code] = (
                            f"Недопустимое значение «{v}». Допустимы: {allowed}"
                        )
                        continue
                else:  # multiselect
                    bad = [x for x in v if x not in allowed]
                    if bad:
                        type_errors[code] = (
                            f"Недопустимые значения {bad}. Допустимы: {allowed}"
                        )
                        continue
        out[code] = v

    if type_errors:
        raise HTTPException(422, {"field_errors": type_errors})

    return out


# ---------- Patch helper ----------


async def _get_active_defs_for_scope(
    session: AsyncSession, scope: str
) -> list[CustomFieldDef]:
    """Загрузить все определения по scope (включая inactive — required-проверка
    делается только по active в validate_extra_fields)."""
    return (
        await session.execute(
            select(CustomFieldDef).where(CustomFieldDef.entity_scope == scope)
        )
    ).scalars().all()


async def patch_extra_fields(
    session: AsyncSession,
    scope: str,
    entity_id: int,
    payload: dict[str, Any],
    *,
    actor_id: int | None = None,
    entity: Any | None = None,
) -> dict[str, Any]:
    """Единая точка обновления extra_fields на любой entity.

    Шаги:
    1. Найти entity по scope + id (404 если нет) — или принять уже загруженный
       (caller сделал owner-guard, чтобы не грузить дважды).
    2. Загрузить active CustomFieldDef'ы по scope.
    3. Замёрджить payload в текущие extra_fields.
    4. Провалидировать merged dict (типы, options, required).
    5. Записать обратно + commit + AUDIT (C9 CRIT-3).

    Возвращает финальный extra_fields. Бросает HTTPException 404/422 при ошибках.

    actor_id — кто меняет (для audit-лога action='extra_fields_change'). Раньше
    изменения custom-полей НЕ аудитились (слепая зона).

    ВАЖНО: эта функция выполняет commit. Если нужно вызывать в большой
    транзакции — рефакторь под flush+caller_commit.
    """
    validate_scope(scope)
    model = _SCOPE_TO_MODEL[scope]

    if entity is None:
        entity = (
            await session.execute(select(model).where(model.id == entity_id))
        ).scalar_one_or_none()
    if entity is None:
        raise HTTPException(404, f"{scope} {entity_id} не найден")

    defs = await _get_active_defs_for_scope(session, scope)

    # Snapshot до изменений (для audit-diff).
    before = dict(entity.extra_fields or {})

    # Merge: payload поверх существующих
    current = dict(before)
    current.update(payload or {})

    # Валидация всего результирующего dict'а (на required)
    normalized = validate_extra_fields(scope, current, defs)

    entity.extra_fields = normalized

    # C9 CRIT-3: аудитим изменение custom-полей (action 'extra_fields_change',
    # который был заведён в audit.py, но нигде не вызывался).
    if before != normalized:
        from app.services.audit import log_change
        await log_change(
            session,
            entity_type=scope,
            entity_id=entity_id,
            user_id=actor_id,
            action="extra_fields_change",
            before=before,
            after=normalized,
        )

    await session.commit()
    await session.refresh(entity)

    return dict(entity.extra_fields)
