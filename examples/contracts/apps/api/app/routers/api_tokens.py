"""APIToken CRUD (Эпик 11.1).

ACL:
- list — CurrentUser видит свои; admin видит все (фильтр user_id для admin).
- create — CurrentUser создаёт от своего имени (scopes валидируются по whitelist).
- revoke — owner или admin.
- delete — admin only (hard-delete оставляем за админом, поскольку токены —
  audit-чувствительная сущность).

Plaintext token возвращается ТОЛЬКО в response на POST /api/api-tokens
(один раз). Дальше API возвращает только last4 (визуальная подсказка вроде
"…AbCd") и метаданные.
"""
from __future__ import annotations

from datetime import UTC, datetime
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import APIToken, UserRole
from app.services.api_scopes import ALLOWED_SCOPES, validate_scopes
from app.services.api_tokens import generate_token

router = APIRouter(prefix="/api-tokens", tags=["api-tokens"])


# ============ Pydantic-схемы ============

class APITokenOut(BaseModel):
    """Метаданные токена БЕЗ plaintext (только last4 для визуальной идентификации)."""

    model_config = ConfigDict(from_attributes=True)
    id: int
    user_id: int
    name: str
    scopes: list[str]
    expires_at: datetime | None
    last_used_at: datetime | None
    last_used_ip: str | None
    is_active: bool
    # Epic 16 — Security: per-token rate-limit (запросов/час). Default 1000.
    rate_limit_per_hour: int = 1000
    created_at: datetime
    revoked_at: datetime | None


class APITokenCreate(BaseModel):
    name: str = Field(min_length=1, max_length=128)
    scopes: list[str] = Field(default_factory=list)
    expires_at: datetime | None = None
    # Epic 16 — Security: лимит на час. По умолчанию 1000, можно бамп'нуть
    # до 100000 для batch-интеграций. Минимум 1 (0 = «токен заблокирован»,
    # это надо делать через revoke, не через лимит).
    rate_limit_per_hour: int = Field(default=1000, ge=1, le=1_000_000)


class APITokenUpdate(BaseModel):
    """Epic 16 — Security: PATCH-обновление metadata токена.

    Поля name/rate_limit_per_hour — можно менять без revoke. scopes
    менять нельзя (создавайте новый токен, чтобы избежать silent privilege
    creep).
    """

    name: str | None = Field(default=None, min_length=1, max_length=128)
    rate_limit_per_hour: int | None = Field(default=None, ge=1, le=1_000_000)


class APITokenCreated(APITokenOut):
    """Response создания: добавлено plaintext_token (показывается ОДИН раз!)."""

    plaintext_token: str


class APITokenScopesOut(BaseModel):
    """Whitelist scopes для UI-конструктора (admin admin-страница «Токены»)."""

    scopes: list[str]


# ============ Helpers ============

async def _get_token_or_404(
    session: AsyncSession, token_id: int
) -> APIToken:
    t = (
        await session.execute(select(APIToken).where(APIToken.id == token_id))
    ).scalar_one_or_none()
    if not t:
        raise HTTPException(404, "Токен не найден")
    return t


# ============ Endpoints ============

@router.get("/scopes", response_model=APITokenScopesOut)
async def list_allowed_scopes(_: CurrentUser):
    """Доступные scope'ы для UI-конструктора. Любой авторизованный."""
    return APITokenScopesOut(scopes=sorted(ALLOWED_SCOPES))


@router.get("", response_model=list[APITokenOut])
async def list_tokens(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    user_id: int | None = None,
    is_active: bool | None = None,
):
    """Список токенов. Не-admin видит ТОЛЬКО свои; admin — все либо по user_id."""
    stmt = select(APIToken).order_by(APIToken.created_at.desc())
    if current_user.role != UserRole.admin:
        # Не-admin: жёстко свои + игнорируем user_id-фильтр (защита от
        # request'а с чужим id).
        stmt = stmt.where(APIToken.user_id == current_user.id)
    elif user_id is not None:
        stmt = stmt.where(APIToken.user_id == user_id)
    if is_active is not None:
        stmt = stmt.where(APIToken.is_active.is_(is_active))
    return (await session.execute(stmt)).scalars().all()


@router.post(
    "", response_model=APITokenCreated, status_code=status.HTTP_201_CREATED,
)
async def create_token(
    data: APITokenCreate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать новый APIToken. Plaintext возвращается ТОЛЬКО здесь, один раз."""
    try:
        normalized_scopes = validate_scopes(data.scopes)
    except ValueError as e:
        raise HTTPException(400, str(e)) from e

    # "*" scope только admin может выдать (даже самому себе): защита от того,
    # чтобы менеджер не выдал себе admin-уровневый токен и не ходил мимо UI.
    if "*" in normalized_scopes and current_user.role != UserRole.admin:
        raise HTTPException(
            403,
            "Scope '*' может выдать только администратор",
        )

    plaintext, token_hash = generate_token()
    token = APIToken(
        user_id=current_user.id,
        name=data.name,
        token_hash=token_hash,
        scopes=normalized_scopes,
        expires_at=data.expires_at,
        rate_limit_per_hour=data.rate_limit_per_hour,
        is_active=True,
    )
    session.add(token)
    await session.commit()
    await session.refresh(token)
    return APITokenCreated(
        **APITokenOut.model_validate(token).model_dump(),
        plaintext_token=plaintext,
    )


@router.patch("/{token_id}", response_model=APITokenOut)
async def update_token(
    token_id: int,
    data: APITokenUpdate,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Обновить metadata (name, rate_limit_per_hour). Owner или admin.

    Scopes менять НЕЛЬЗЯ — для расширения прав создавайте новый токен.
    """
    token = await _get_token_or_404(session, token_id)
    if current_user.role != UserRole.admin and token.user_id != current_user.id:
        raise HTTPException(403, "Можно изменить только свой токен")
    changed = False
    if data.name is not None:
        token.name = data.name
        changed = True
    if data.rate_limit_per_hour is not None:
        token.rate_limit_per_hour = data.rate_limit_per_hour
        changed = True
    if changed:
        await session.commit()
        await session.refresh(token)
    return token


@router.patch("/{token_id}/revoke", response_model=APITokenOut)
async def revoke_token(
    token_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Отозвать токен (is_active=False + revoked_at). Owner или admin."""
    token = await _get_token_or_404(session, token_id)
    if current_user.role != UserRole.admin and token.user_id != current_user.id:
        raise HTTPException(403, "Можно отозвать только свой токен")
    if not token.is_active:
        # Идемпотентно — повторный revoke не делает ничего.
        return token
    token.is_active = False
    token.revoked_at = datetime.now(UTC)
    await session.commit()
    await session.refresh(token)
    return token


@router.delete(
    "/{token_id}", status_code=status.HTTP_204_NO_CONTENT,
)
async def delete_token(
    token_id: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Hard-delete токена. Admin only (audit-чувствительно)."""
    token = await _get_token_or_404(session, token_id)
    await session.delete(token)
    await session.commit()
