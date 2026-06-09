"""Custom Fields (Эпик 8 / Card 2.0): CRUD дефиниций + per-entity PATCH extra-fields.

Эндпоинты:
- GET /custom-field-defs?scope=lead&is_active=true   — список дефиниций (CurrentUser).
- POST /custom-field-defs                            — создать (AdminUser).
- PATCH /custom-field-defs/{id}                      — обновить (AdminUser).
- DELETE /custom-field-defs/{id}                     — удалить (AdminUser).
- PATCH /leads/{id}/extra-fields                      — обновить extra_fields (CurrentUser).
- PATCH /contacts/{id}/extra-fields
- PATCH /companies/{id}/extra-fields
- PATCH /counterparties/{id}/extra-fields
- PATCH /deals/{id}/extra-fields
- PATCH /contracts/{id}/extra-fields
- PATCH /subscriptions/{id}/extra-fields

Все per-entity эндпоинты — обёртки над `app.services.custom_fields.patch_extra_fields`.
"""
from __future__ import annotations

from datetime import datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field, field_validator
from sqlalchemy.exc import IntegrityError
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import CustomFieldDef
from app.services.bulk_reorder import (
    apply_reorder_simple,
    validate_reorder_payload,
)
from app.services.custom_fields import (
    CUSTOM_FIELD_KINDS,
    CUSTOM_FIELD_SCOPES,
    patch_extra_fields,
    validate_kind,
    validate_scope,
)


router = APIRouter(prefix="/custom-field-defs", tags=["custom-fields"])


# ============ Pydantic-схемы ============


class CustomFieldDefOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    entity_scope: str
    code: str
    label_ru: str
    kind: str
    is_required: bool
    default_value: Any | None
    options_json: list[Any]
    sort_order: int
    is_active: bool
    created_at: datetime
    updated_at: datetime


class CustomFieldDefCreate(BaseModel):
    entity_scope: str
    code: str = Field(min_length=1, max_length=64)
    label_ru: str = Field(min_length=1, max_length=255)
    kind: str
    is_required: bool = False
    default_value: Any | None = None
    options_json: list[Any] = Field(default_factory=list)
    sort_order: int = 0
    is_active: bool = True

    @field_validator("code")
    @classmethod
    def _check_code(cls, v: str) -> str:
        # snake_case: [a-z0-9_], начинается с буквы
        import re
        if not re.fullmatch(r"[a-z][a-z0-9_]*", v):
            raise ValueError(
                "Код должен содержать только латиницу, цифры и _, начинаться с буквы (snake_case)"
            )
        return v


class CustomFieldDefUpdate(BaseModel):
    # entity_scope и code менять нельзя (нарушит data integrity в extra_fields)
    label_ru: str | None = Field(default=None, min_length=1, max_length=255)
    kind: str | None = None
    is_required: bool | None = None
    default_value: Any | None = None
    options_json: list[Any] | None = None
    sort_order: int | None = None
    is_active: bool | None = None


class ExtraFieldsPatch(BaseModel):
    """body для PATCH /<entity>/<id>/extra-fields."""
    extra_fields: dict[str, Any] = Field(default_factory=dict)


class ExtraFieldsOut(BaseModel):
    extra_fields: dict[str, Any]


# ============ Helpers ============


async def _get_def_or_404(session: AsyncSession, def_id: int) -> CustomFieldDef:
    d = (
        await session.execute(select(CustomFieldDef).where(CustomFieldDef.id == def_id))
    ).scalar_one_or_none()
    if d is None:
        raise HTTPException(404, "Определение поля не найдено")
    return d


# ============ Endpoints — CRUD дефиниций ============


@router.get("", response_model=list[CustomFieldDefOut])
async def list_custom_field_defs(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    scope: str | None = None,
    entity_scope: str | None = None,  # alias, frontend может слать любой
    is_active: bool | None = None,
):
    """Список дефиниций кастомных полей.

    Параметр scope (или entity_scope) — фильтр по сущности. is_active — фильтр.
    Сортировка: sort_order ASC, label_ru ASC.
    """
    effective_scope = scope or entity_scope
    if effective_scope is not None:
        validate_scope(effective_scope)

    stmt = select(CustomFieldDef).order_by(
        CustomFieldDef.sort_order.asc(), CustomFieldDef.label_ru.asc()
    )
    if effective_scope is not None:
        stmt = stmt.where(CustomFieldDef.entity_scope == effective_scope)
    if is_active is not None:
        stmt = stmt.where(CustomFieldDef.is_active.is_(is_active))
    return (await session.execute(stmt)).scalars().all()


@router.post("", response_model=CustomFieldDefOut, status_code=status.HTTP_201_CREATED)
async def create_custom_field_def(
    data: CustomFieldDefCreate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать дефиницию. Уникальность (entity_scope, code). AdminUser."""
    validate_scope(data.entity_scope)
    validate_kind(data.kind)

    d = CustomFieldDef(
        entity_scope=data.entity_scope,
        code=data.code,
        label_ru=data.label_ru,
        kind=data.kind,
        is_required=data.is_required,
        default_value=data.default_value,
        options_json=data.options_json,
        sort_order=data.sort_order,
        is_active=data.is_active,
    )
    session.add(d)
    try:
        await session.commit()
    except IntegrityError:
        await session.rollback()
        raise HTTPException(
            422,
            {"field_errors": {"code": "Поле с таким кодом уже существует для этой сущности"}},
        ) from None
    await session.refresh(d)
    return d


@router.get("/{def_id}", response_model=CustomFieldDefOut)
async def get_custom_field_def(
    def_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    return await _get_def_or_404(session, def_id)


@router.patch("/{def_id}", response_model=CustomFieldDefOut)
async def update_custom_field_def(
    def_id: int,
    data: CustomFieldDefUpdate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Обновить (AdminUser). entity_scope/code менять нельзя."""
    d = await _get_def_or_404(session, def_id)
    patch = data.model_dump(exclude_unset=True)
    if "kind" in patch and patch["kind"] is not None:
        validate_kind(patch["kind"])
    for k, v in patch.items():
        setattr(d, k, v)
    await session.commit()
    await session.refresh(d)
    return d


@router.delete("/{def_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_custom_field_def(
    def_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить дефиницию (AdminUser). Данные в extra_fields НЕ затираются
    (просто перестают рендериться)."""
    d = await _get_def_or_404(session, def_id)
    await session.delete(d)
    await session.commit()


# ============ Bulk-reorder (Tech Sprint Фаза 0) ============


class _ReorderItem(BaseModel):
    id: int
    sort_order: int


class _ReorderOut(BaseModel):
    updated: int


@router.patch("/reorder", response_model=_ReorderOut)
async def reorder_custom_field_defs(
    entity_scope: str,
    payload: list[_ReorderItem],
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Bulk-обновить sort_order дефиниций для одного entity_scope (AdminUser).

    entity_scope передаётся query-параметром, чтобы случайно не отreorderить
    дефиниции lead вместе с deal (у них общая таблица, разный scope).
    """
    validate_scope(entity_scope)
    items_dict = [it.model_dump() for it in payload]
    pairs = validate_reorder_payload(items_dict, order_field="sort_order")
    count = await apply_reorder_simple(
        session,
        CustomFieldDef,
        pairs,
        scope_filter=(CustomFieldDef.entity_scope == entity_scope),
        order_field="sort_order",
    )
    await session.commit()
    return _ReorderOut(updated=count)


# ============ Endpoints — extra-fields per entity ============
# Отдельный роутер для маршрутов /<entity>/<id>/extra-fields, потому что
# их prefix не /custom-field-defs.

extra_fields_router = APIRouter(tags=["custom-fields"])


def _make_extra_fields_endpoint(scope: str, path_prefix: str):
    """Фабрика endpoint'а PATCH /<path_prefix>/{id}/extra-fields для scope."""
    async def _handler(
        item_id: int,
        data: ExtraFieldsPatch,
        current_user: CurrentUser,
        session: Annotated[AsyncSession, Depends(get_session)],
    ):
        # P0 security (C9 CRIT-1): owner-guard ПЕРЕД записью. Раньше любой юзер
        # писал в extra_fields любой записи по id (mass-assignment в JSONB).
        # Грузим entity, проверяем видимость (404 на чужую), затем патчим тот же
        # объект (без повторного select) + аудитим (actor_id).
        from app.services.access_control import ensure_object_visible
        from app.services.custom_fields import _SCOPE_TO_MODEL

        model = _SCOPE_TO_MODEL[scope]
        entity = (
            await session.execute(select(model).where(model.id == item_id))
        ).scalar_one_or_none()
        if entity is None:
            raise HTTPException(404, f"{scope} {item_id} не найден")
        await ensure_object_visible(session, entity, scope, current_user)

        result = await patch_extra_fields(
            session, scope, item_id, data.extra_fields,
            actor_id=current_user.id, entity=entity,
        )
        return ExtraFieldsOut(extra_fields=result)

    return _handler


# Прямая регистрация — по одному эндпоинту на scope.
_SCOPE_TO_PATH = {
    "lead": "leads",
    "contact": "contacts",
    "company": "companies",
    "counterparty": "counterparties",
    "deal": "deals",
    "contract": "contracts",
    "subscription": "subscriptions",
}

for _scope, _path in _SCOPE_TO_PATH.items():
    extra_fields_router.add_api_route(
        f"/{_path}/{{item_id}}/extra-fields",
        _make_extra_fields_endpoint(_scope, _path),
        methods=["PATCH"],
        response_model=ExtraFieldsOut,
        name=f"patch_{_scope}_extra_fields",
    )


# Экспорт списков для тестов
__all__ = [
    "router",
    "extra_fields_router",
    "CUSTOM_FIELD_SCOPES",
    "CUSTOM_FIELD_KINDS",
]
