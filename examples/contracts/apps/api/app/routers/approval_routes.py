from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import ApprovalRoute
from app.schemas import ApprovalRouteIn, ApprovalRouteOut
from app.services.templates import TEMPLATE_CATEGORIES

router = APIRouter(prefix="/approval-routes", tags=["approval-routes"])


def _route_to_dict(r: ApprovalRoute) -> dict:
    return {
        "id": r.id,
        "name": r.name,
        "product_codes": r.product_codes or [],
        "country_codes": r.country_codes or [],
        "approver_user_ids": r.approver_user_ids or [],
        "min_required": r.min_required,
        "stages": r.stages or [],
        "is_active": r.is_active,
        "template_category": r.template_category,
    }


def _validate_payload(payload: ApprovalRouteIn) -> None:
    if not payload.product_codes:
        raise HTTPException(400, "Выберите хотя бы один продукт")
    if not payload.country_codes:
        raise HTTPException(400, "Выберите хотя бы одну страну")
    has_stages = bool(payload.stages)
    has_legacy = bool(payload.approver_user_ids)
    if not has_stages and not has_legacy:
        raise HTTPException(400, "Назначьте согласователей: либо один этап (approver_user_ids), либо несколько (stages)")
    if has_stages:
        for st in payload.stages:
            if not st.user_ids:
                raise HTTPException(400, f"Этап «{st.name}»: назначьте хотя бы одного согласователя")
            if st.min_required < 1 or st.min_required > len(st.user_ids):
                raise HTTPException(400, f"Этап «{st.name}»: min_required должен быть от 1 до количества согласователей")
    if payload.template_category is not None and payload.template_category not in TEMPLATE_CATEGORIES:
        raise HTTPException(
            400,
            f"Недопустимая категория шаблона: {payload.template_category}. "
            f"Допустимы: {', '.join(TEMPLATE_CATEGORIES)}",
        )


@router.get("", response_model=list[ApprovalRouteOut])
async def list_routes(
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    rows = (await session.execute(select(ApprovalRoute).order_by(ApprovalRoute.id))).scalars().all()
    return [_route_to_dict(r) for r in rows]


@router.post("", response_model=ApprovalRouteOut, status_code=status.HTTP_201_CREATED)
async def create_route(
    payload: ApprovalRouteIn,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    _validate_payload(payload)
    stages_data = [s.model_dump() for s in payload.stages]
    # Если используются stages — derive legacy approver_user_ids из всех этапов (для отображения)
    legacy_ids = payload.approver_user_ids or [uid for st in payload.stages for uid in st.user_ids]
    route = ApprovalRoute(
        name=payload.name,
        product_codes=payload.product_codes,
        country_codes=payload.country_codes,
        approver_user_ids=legacy_ids,
        min_required=payload.min_required,
        stages=stages_data,
        product_code=payload.product_codes[0],
        country_code=payload.country_codes[0] if payload.country_codes else None,
        template_category=payload.template_category,
    )
    session.add(route)
    await session.commit()
    await session.refresh(route)
    return _route_to_dict(route)


@router.patch("/{route_id}", response_model=ApprovalRouteOut)
async def update_route(
    route_id: int,
    payload: ApprovalRouteIn,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    route = (await session.execute(select(ApprovalRoute).where(ApprovalRoute.id == route_id))).scalar_one_or_none()
    if not route:
        raise HTTPException(404, "Не найден")
    _validate_payload(payload)
    stages_data = [s.model_dump() for s in payload.stages]
    legacy_ids = payload.approver_user_ids or [uid for st in payload.stages for uid in st.user_ids]
    route.name = payload.name
    route.product_codes = payload.product_codes
    route.country_codes = payload.country_codes
    route.approver_user_ids = legacy_ids
    route.min_required = payload.min_required
    route.stages = stages_data
    route.product_code = payload.product_codes[0]
    route.country_code = payload.country_codes[0] if payload.country_codes else None
    route.template_category = payload.template_category
    await session.commit()
    await session.refresh(route)
    return _route_to_dict(route)


@router.delete("/{route_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_route(
    route_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    route = (await session.execute(select(ApprovalRoute).where(ApprovalRoute.id == route_id))).scalar_one_or_none()
    if not route:
        raise HTTPException(404, "Не найден")
    route.is_active = False
    await session.commit()


def match_route(routes: list[ApprovalRoute], product_code: str, country_code: str) -> ApprovalRoute | None:
    """Подбор маршрута: точное совпадение продукт+страна > * + страна > продукт + * > * + *."""
    active_routes = [r for r in routes if r.is_active]
    candidates = []
    for r in active_routes:
        pc = r.product_codes or []
        cc = r.country_codes or []
        product_match = product_code in pc or "*" in pc
        country_match = country_code in cc or "*" in cc
        if not (product_match and country_match):
            continue
        # Score: точные матчи дают высокий score
        score = 0
        if product_code in pc:
            score += 10
        if country_code in cc:
            score += 5
        candidates.append((score, r))
    if not candidates:
        return None
    candidates.sort(key=lambda x: -x[0])
    return candidates[0][1]
