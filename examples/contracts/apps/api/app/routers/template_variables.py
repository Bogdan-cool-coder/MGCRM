"""Кастомные переменные шаблона (TemplateVariable).

Юрист/админ задаёт произвольные переменные, которые:
1. вставляются в master_skeleton.docx как {{ custom.<key> }};
2. появляются динамической формой в карточке договора;
3. сохраняются в Contract.context['custom'][key] и подставляются при генерации.
"""

import re
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser, LawyerOrAdmin
from app.models import TemplateVariable, TemplateVariableType
from app.schemas import TemplateVariableIn, TemplateVariableOut, TemplateVariablePatch
from app.services.templates import load_active_variables

router = APIRouter(prefix="/template-variables", tags=["template-variables"])

# Ключ: латиница в нижнем регистре, цифры, _; начинается с буквы.
KEY_RE = re.compile(r"^[a-z][a-z0-9_]*$")
# Зарезервированные namespace'ы контекста — нельзя использовать как ключ.
RESERVED_KEYS = {"contract", "license", "sublicensee", "licensor", "product", "country", "custom"}


def _validate_key(key: str) -> str:
    key = (key or "").strip().lower()
    if not KEY_RE.match(key):
        raise HTTPException(400, "Ключ: только латиница в нижнем регистре, цифры и _, начинается с буквы. Пример: warranty_period")
    if key in RESERVED_KEYS:
        raise HTTPException(400, f"Ключ «{key}» зарезервирован, выберите другой")
    return key


def _validate_for_type(var_type: TemplateVariableType, options: list[str]) -> None:
    if var_type == TemplateVariableType.select and not options:
        raise HTTPException(400, "Для выпадающего списка укажите хотя бы один вариант")


@router.get("", response_model=list[TemplateVariableOut])
async def list_variables(
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Все переменные (для админ-страницы управления)."""
    return (await session.execute(
        select(TemplateVariable).order_by(TemplateVariable.sort_order, TemplateVariable.id)
    )).scalars().all()


@router.get("/for-form", response_model=list[TemplateVariableOut])
async def variables_for_form(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    product: str | None = None,
    country: str | None = None,
):
    """Активные переменные для динамической формы карточки договора."""
    return await load_active_variables(session, product, country)


@router.post("", response_model=TemplateVariableOut, status_code=status.HTTP_201_CREATED)
async def create_variable(
    payload: TemplateVariableIn,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    key = _validate_key(payload.key)
    _validate_for_type(payload.var_type, payload.options)

    exists = (await session.execute(
        select(TemplateVariable).where(TemplateVariable.key == key)
    )).scalar_one_or_none()
    if exists:
        raise HTTPException(400, f"Переменная с ключом «{key}» уже существует")

    v = TemplateVariable(
        key=key,
        label=payload.label.strip(),
        help_text=payload.help_text,
        var_type=payload.var_type,
        options=payload.options,
        default_value=payload.default_value,
        required=payload.required,
        group=(payload.group or None),
        sort_order=payload.sort_order,
        product_codes=payload.product_codes,
        country_codes=payload.country_codes,
        is_active=payload.is_active,
    )
    session.add(v)
    await session.commit()
    await session.refresh(v)
    return v


@router.patch("/{var_id}", response_model=TemplateVariableOut)
async def update_variable(
    var_id: int,
    payload: TemplateVariablePatch,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    v = (await session.execute(
        select(TemplateVariable).where(TemplateVariable.id == var_id)
    )).scalar_one_or_none()
    if not v:
        raise HTTPException(404, "Переменная не найдена")

    data = payload.model_dump(exclude_unset=True)
    # Проверка типа+опций с учётом новых значений
    new_type = data.get("var_type", v.var_type)
    new_options = data.get("options", v.options)
    _validate_for_type(new_type, new_options)

    for field, value in data.items():
        if field == "group":
            value = value or None
        setattr(v, field, value)

    await session.commit()
    await session.refresh(v)
    return v


@router.delete("/{var_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_variable(
    var_id: int,
    _: LawyerOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    v = (await session.execute(
        select(TemplateVariable).where(TemplateVariable.id == var_id)
    )).scalar_one_or_none()
    if not v:
        raise HTTPException(404, "Переменная не найдена")
    await session.delete(v)
    await session.commit()
