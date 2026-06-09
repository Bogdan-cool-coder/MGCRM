"""P0 security (B2) — object-level IDOR на item-эндпоинтах лида.

Покрывает _require_lead_visible (load-or-404 + ensure_object_visible) и точечный
ensure_object_visible в convert/ai-prefill: scoped manager → 404 на чужой лид,
admin → pass, владелец → pass.

Тесты pure-function (AsyncMock session, без DB fixture) — в стиле test_object_idor.py.
"""
from __future__ import annotations

from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest
from fastapi import HTTPException

from app.models import Lead, User, UserRole


def _user(role: UserRole, uid: int = 1, dept_id: int | None = None) -> User:
    return User(id=uid, role=role, department_id=dept_id)


def _session(rows: list):
    """AsyncMock session: каждый execute().scalar_one_or_none() возвращает rows.pop(0)."""
    sess = AsyncMock()
    seq = list(rows)

    async def _execute(*_a, **_k):
        result = SimpleNamespace()
        val = seq.pop(0) if seq else None
        result.scalar_one_or_none = lambda: val
        return result

    sess.execute = _execute
    return sess


# ============ _require_lead_visible (get / patch) ============


@pytest.mark.asyncio
async def test_require_lead_visible_foreign_404_for_manager():
    """Scoped manager → 404 на чужой лид (owner_id != user.id)."""
    from app.routers.leads import _require_lead_visible

    foreign = Lead(id=1, owner_id=999)
    # rows: [lead] для _get_lead_or_404; [None, None] для resolve_scope → personal.
    sess = _session([foreign, None, None])
    with pytest.raises(HTTPException) as exc:
        await _require_lead_visible(sess, 1, _user(UserRole.manager, uid=1))
    assert exc.value.status_code == 404


@pytest.mark.asyncio
async def test_require_lead_visible_owner_passes():
    """Владелец лида проходит (scope='personal')."""
    from app.routers.leads import _require_lead_visible

    own = Lead(id=1, owner_id=42)
    sess = _session([own, None, None])
    out = await _require_lead_visible(sess, 1, _user(UserRole.manager, uid=42))
    assert out is own


@pytest.mark.asyncio
async def test_require_lead_visible_admin_passes_foreign():
    """admin → scope='all' (без DB-lookup scope) → проходит на чужой лид."""
    from app.routers.leads import _require_lead_visible

    foreign = Lead(id=1, owner_id=999)
    # admin не ходит в visibility_settings — только [lead] для load.
    sess = _session([foreign])
    out = await _require_lead_visible(sess, 1, _user(UserRole.admin, uid=1))
    assert out is foreign


@pytest.mark.asyncio
async def test_require_lead_visible_missing_404():
    """Отсутствующий лид → 404 (до проверки scope)."""
    from app.routers.leads import _require_lead_visible

    sess = _session([None])
    with pytest.raises(HTTPException) as exc:
        await _require_lead_visible(sess, 1, _user(UserRole.manager, uid=1))
    assert exc.value.status_code == 404


# ============ convert / ai-prefill используют ensure_object_visible напрямую ============


@pytest.mark.asyncio
async def test_ensure_object_visible_foreign_lead_404():
    """convert/ai-prefill: чужой лид → 404 для scoped manager."""
    from app.services.access_control import ensure_object_visible

    sess = _session([None, None])  # resolve_scope → personal
    foreign = Lead(id=1, owner_id=999)
    with pytest.raises(HTTPException) as exc:
        await ensure_object_visible(sess, foreign, "lead", _user(UserRole.manager, uid=1))
    assert exc.value.status_code == 404


@pytest.mark.asyncio
async def test_ensure_object_visible_own_lead_passes():
    """convert/ai-prefill: владелец проходит."""
    from app.services.access_control import ensure_object_visible

    sess = _session([None, None])
    own = Lead(id=1, owner_id=7)
    await ensure_object_visible(sess, own, "lead", _user(UserRole.manager, uid=7))


@pytest.mark.asyncio
async def test_ensure_object_visible_admin_lead_passes():
    """admin → 'all' → конвертирует/префиллит любой лид."""
    from app.services.access_control import ensure_object_visible

    sess = _session([])  # admin не ходит в БД за scope
    foreign = Lead(id=1, owner_id=999)
    await ensure_object_visible(sess, foreign, "lead", _user(UserRole.admin, uid=1))
