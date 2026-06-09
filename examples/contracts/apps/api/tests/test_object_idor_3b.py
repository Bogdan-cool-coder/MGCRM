"""P0 security (Unit 3b) — закрытие object-level IDOR на оставшихся роутерах.

Покрывает ensure_object_visible для новых entity_type, добавленных в 3b-зону:
contact / counterparty / subscription / contract. Для каждого — три кейса:
- scoped manager (чужой объект) → 404;
- admin → проходит всегда (без DB-lookup);
- owner → проходит (personal scope).

Плюс pure-предикат _object_in_personal_scope для contact/counterparty/subscription
и валидатор lifecycle-этапа registry._validate_lifecycle_stage.

Тесты НЕ касаются реальной БД: scope резолвится через AsyncMock-session, как в
test_object_idor.py (зеркалим 3a-паттерн _scope_session).
"""
from __future__ import annotations

from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest
from fastapi import HTTPException

from app.models import (
    ClientSubscription,
    Company,
    Contact,
    Contract,
    Counterparty,
    User,
    UserRole,
)
from app.services.access_control import (
    _object_in_personal_scope,
    ensure_object_visible,
)


def _user(role: UserRole, uid: int = 1, dept_id: int | None = None) -> User:
    return User(id=uid, role=role, department_id=dept_id)


def _scope_session(rows: list):
    """AsyncMock session: execute().scalar_one_or_none() возвращает rows.pop(0)."""
    sess = AsyncMock()
    seq = list(rows)

    async def _execute(*_a, **_k):
        result = SimpleNamespace()
        val = seq.pop(0) if seq else None
        result.scalar_one_or_none = lambda: val
        return result

    sess.execute = _execute
    return sess


# ============ pure-предикат personal-scope для новых entity_type ============


def test_personal_scope_contact_owner_id():
    owner = _user(UserRole.manager, uid=11)
    foreign = _user(UserRole.manager, uid=22)
    contact = Contact(id=1, owner_id=11)
    assert _object_in_personal_scope(contact, "contact", owner) is True
    assert _object_in_personal_scope(contact, "contact", foreign) is False


def test_personal_scope_counterparty_owner_user_id():
    owner = _user(UserRole.manager, uid=3)
    foreign = _user(UserRole.manager, uid=4)
    cp = Counterparty(id=1, owner_user_id=3)
    assert _object_in_personal_scope(cp, "counterparty", owner) is True
    assert _object_in_personal_scope(cp, "counterparty", foreign) is False


def test_personal_scope_subscription_owner_user_id():
    owner = _user(UserRole.manager, uid=7)
    foreign = _user(UserRole.manager, uid=8)
    sub = ClientSubscription(id=1, owner_user_id=7)
    assert _object_in_personal_scope(sub, "subscription", owner) is True
    assert _object_in_personal_scope(sub, "subscription", foreign) is False


# ============ contact: manager 404 / admin pass / owner pass ============


@pytest.mark.asyncio
async def test_contact_foreign_manager_404():
    sess = _scope_session([None, None])  # resolve_scope → personal
    foreign = Contact(id=1, owner_id=999)
    with pytest.raises(HTTPException) as exc:
        await ensure_object_visible(sess, foreign, "contact", _user(UserRole.manager, uid=1))
    assert exc.value.status_code == 404


@pytest.mark.asyncio
async def test_contact_admin_passes():
    sess = _scope_session([])  # admin не ходит в БД
    foreign = Contact(id=1, owner_id=999)
    await ensure_object_visible(sess, foreign, "contact", _user(UserRole.admin, uid=1))


@pytest.mark.asyncio
async def test_contact_owner_passes():
    sess = _scope_session([None, None])
    own = Contact(id=1, owner_id=42)
    await ensure_object_visible(sess, own, "contact", _user(UserRole.manager, uid=42))


# ============ counterparty: manager 404 / admin pass / owner pass ============


@pytest.mark.asyncio
async def test_counterparty_foreign_manager_404():
    sess = _scope_session([None, None])
    foreign = Counterparty(id=1, owner_user_id=999)
    with pytest.raises(HTTPException) as exc:
        await ensure_object_visible(sess, foreign, "counterparty", _user(UserRole.manager, uid=1))
    assert exc.value.status_code == 404


@pytest.mark.asyncio
async def test_counterparty_admin_passes():
    sess = _scope_session([])
    foreign = Counterparty(id=1, owner_user_id=999)
    await ensure_object_visible(sess, foreign, "counterparty", _user(UserRole.admin, uid=1))


@pytest.mark.asyncio
async def test_counterparty_owner_passes():
    sess = _scope_session([None, None])
    own = Counterparty(id=1, owner_user_id=5)
    await ensure_object_visible(sess, own, "counterparty", _user(UserRole.manager, uid=5))


# ============ subscription: manager 404 / admin pass / owner pass ============


@pytest.mark.asyncio
async def test_subscription_foreign_manager_404():
    sess = _scope_session([None, None])
    foreign = ClientSubscription(id=1, owner_user_id=999)
    with pytest.raises(HTTPException) as exc:
        await ensure_object_visible(sess, foreign, "subscription", _user(UserRole.manager, uid=1))
    assert exc.value.status_code == 404


@pytest.mark.asyncio
async def test_subscription_admin_passes():
    sess = _scope_session([])
    foreign = ClientSubscription(id=1, owner_user_id=999)
    await ensure_object_visible(sess, foreign, "subscription", _user(UserRole.admin, uid=1))


@pytest.mark.asyncio
async def test_subscription_owner_passes():
    sess = _scope_session([None, None])
    own = ClientSubscription(id=1, owner_user_id=70)
    await ensure_object_visible(sess, own, "subscription", _user(UserRole.manager, uid=70))


# ============ contract approval-summary gate: manager 404 / admin pass / author pass ============


@pytest.mark.asyncio
async def test_contract_foreign_manager_404():
    """approval-summary read-gate: чужой договор → 404 (палить summary нельзя)."""
    sess = _scope_session([None, None])
    foreign = Contract(id=1, author_user_id=999)
    with pytest.raises(HTTPException) as exc:
        await ensure_object_visible(sess, foreign, "contract", _user(UserRole.manager, uid=1))
    assert exc.value.status_code == 404


@pytest.mark.asyncio
async def test_contract_admin_passes():
    sess = _scope_session([])
    foreign = Contract(id=1, author_user_id=999)
    await ensure_object_visible(sess, foreign, "contract", _user(UserRole.admin, uid=1))


@pytest.mark.asyncio
async def test_contract_author_passes():
    sess = _scope_session([None, None])
    own = Contract(id=1, author_user_id=5)
    await ensure_object_visible(sess, own, "contract", _user(UserRole.manager, uid=5))


# ============ crm-file owner-access: gate проходит через company-visibility ============


@pytest.mark.asyncio
async def test_crm_file_upload_owner_access_foreign_company_404():
    """_require_owner_access('company'): чужая невидимая компания → 404 (write-IDOR)."""
    from app.routers.contacts_v2 import _require_owner_access

    foreign_company = Company(id=1, owner_user_id=999)
    sess = _scope_session([foreign_company, None, None])  # company load, затем resolve_scope=personal
    with pytest.raises(HTTPException) as exc:
        await _require_owner_access(sess, "company", 1, _user(UserRole.manager, uid=1))
    assert exc.value.status_code == 404


@pytest.mark.asyncio
async def test_crm_file_upload_owner_access_admin_passes():
    from app.routers.contacts_v2 import _require_owner_access

    foreign_company = Company(id=1, owner_user_id=999)
    sess = _scope_session([foreign_company])  # admin: company load + ensure_object_visible='all' (без lookup)
    await _require_owner_access(sess, "company", 1, _user(UserRole.admin, uid=1))


@pytest.mark.asyncio
async def test_crm_file_upload_owner_access_owner_passes():
    from app.routers.contacts_v2 import _require_owner_access

    own_company = Company(id=1, owner_user_id=42)
    sess = _scope_session([own_company, None, None])
    await _require_owner_access(sess, "company", 1, _user(UserRole.manager, uid=42))


# ============ lifecycle_stage validation (C2 CRIT-3) ============


@pytest.mark.asyncio
async def test_validate_lifecycle_stage_none_ok():
    """None (сброс этапа) допустим — без DB-lookup."""
    from app.routers.registry import _validate_lifecycle_stage

    sess = _scope_session([])
    await _validate_lifecycle_stage(sess, None)


@pytest.mark.asyncio
async def test_validate_lifecycle_stage_foreign_pipeline_422():
    """stage_id из не-lifecycle воронки → 422."""
    from app.routers.registry import _validate_lifecycle_stage

    sess = _scope_session([None])  # join не нашёл lifecycle-этап
    with pytest.raises(HTTPException) as exc:
        await _validate_lifecycle_stage(sess, 555)
    assert exc.value.status_code == 422


@pytest.mark.asyncio
async def test_validate_lifecycle_stage_valid_ok():
    """stage_id принадлежит lifecycle-воронке → проходит."""
    from app.routers.registry import _validate_lifecycle_stage

    sess = _scope_session([12])  # join вернул id этапа
    await _validate_lifecycle_stage(sess, 12)
