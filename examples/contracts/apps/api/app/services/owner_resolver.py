"""Резолвер query-параметра owner/responsible: `"me"` или числовая строка → id.

Используется в эндпоинтах, где фронтенд (Эпик 10 dashboard) передаёт `"me"` как
shortcut «мои сущности», вместо того чтобы заранее запрашивать /me и подставлять
число. Один helper устраняет copy-paste разрешения этой логики в нескольких
роутерах (activities, deals/hot и т.п.).

Контракт:
- None → None (нет фильтра)
- "me" → user_id (если передан, иначе None — невалидная комбинация)
- "<int>" → int(value)
- любое другое (буквы, отрицательное число с минусом не в начале и т.д.) → None.

Без HTTPException: вызывающий сам решает, считать ли «не распарсилось» 400 или
просто отсутствием фильтра. На /activities и /deals/hot — silent ignore.
"""
from __future__ import annotations


def resolve_owner_param(value: str | None, user_id: int | None = None) -> int | None:
    """Превратить query-параметр owner/responsible в numeric id или None.

    >>> resolve_owner_param("me", user_id=42)
    42
    >>> resolve_owner_param("17")
    17
    >>> resolve_owner_param(None)
    >>> resolve_owner_param("")
    >>> resolve_owner_param("me")  # user_id не передан — None (фильтра нет)
    >>> resolve_owner_param("abc")
    """
    if value is None:
        return None
    v = value.strip()
    if not v:
        return None
    if v == "me":
        return user_id  # None если каллер не передал user_id
    # Numeric (положительное целое).
    try:
        n = int(v)
    except ValueError:
        return None
    if n <= 0:
        return None
    return n
