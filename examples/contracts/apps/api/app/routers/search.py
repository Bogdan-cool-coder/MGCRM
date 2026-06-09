"""Search (Эпик 8 / Card 2.0): cross-entity full-text поиск.

GET /search?q=...&entity_types=...  → {query, items, groups}.
"""
from __future__ import annotations

from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from app.db import get_session
from app.deps import CurrentUser
from app.services.search import (
    MIN_QUERY_LENGTH,
    SEARCH_ENTITY_TYPES,
    search_all,
)


router = APIRouter(prefix="/search", tags=["search"])


# ============ Pydantic-схемы ============


class SearchResultItem(BaseModel):
    entity_type: str
    id: int
    display_name: str
    secondary: str | None = None


class SearchGroupOut(BaseModel):
    entity_type: str
    count: int
    items: list[SearchResultItem]


class SearchResponse(BaseModel):
    query: str
    items: list[SearchResultItem]
    groups: list[SearchGroupOut]


# ============ Endpoint ============


@router.get("", response_model=SearchResponse)
async def search(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    q: str,
    entity_types: str | None = None,
    limit: int = 5,
):
    """Cross-entity full-text search (MVP — ILIKE).

    - q (required) — query >= 2 символов.
    - entity_types (optional, comma-sep) — фильтр по типам. Default — все.
    - limit — записей на entity (default 5, max 50).

    Невалидные entity_types игнорируются. Невалидный q → 400.
    """
    parsed_types: list[str] | None = None
    if entity_types:
        parsed_types = [t.strip() for t in entity_types.split(",") if t.strip()]
        # Невалидные молча отбросятся в service.search_all (whitelist filter).

    try:
        result = await search_all(
            session, q, entity_types=parsed_types, limit=limit, user=current_user
        )
    except ValueError as ex:
        raise HTTPException(400, str(ex)) from None

    return SearchResponse(
        query=result["query"],
        items=[SearchResultItem(**it) for it in result["items"]],
        groups=[
            SearchGroupOut(
                entity_type=g["entity_type"],
                count=g["count"],
                items=[SearchResultItem(**it) for it in g["items"]],
            )
            for g in result["groups"]
        ],
    )


@router.get("/whitelist")
async def get_search_whitelist(_: CurrentUser):
    """Whitelist допустимых entity_types и min query length для frontend."""
    return {
        "entity_types": list(SEARCH_ENTITY_TYPES),
        "min_query_length": MIN_QUERY_LENGTH,
    }
