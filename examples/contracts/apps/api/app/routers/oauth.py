"""Эпик 15 — OAuth 2.0 Provider endpoints (RFC 6749 + RFC 7636 PKCE).

Public OAuth API:
- GET  /api/oauth/authorize — consent screen (или redirect на login если не залогинен)
- POST /api/oauth/token — обмен code → access_token, или refresh
- POST /api/oauth/revoke — revoke access/refresh token
- GET  /api/oauth/userinfo — OIDC-style endpoint (Bearer auth)

Admin endpoints (cookie + AdminUser):
- GET  /api/oauth/clients — список зарегистрированных приложений
- POST /api/oauth/clients — создать приложение (возвращает plaintext secret один раз)
- PATCH /api/oauth/clients/{id} — обновить
- DELETE /api/oauth/clients/{id} — soft delete (is_active=False)

NB: /authorize → return UI consent page — на MVP возвращаем JSON с
параметрами consent'а (фронт сам рисует страницу). На production
консент-страница может стать /api/oauth/authorize?... → redirect на
/auth/consent?... → POST /api/oauth/authorize/grant.
"""
from __future__ import annotations

from datetime import datetime
from typing import Annotated, Any
from urllib.parse import urlencode

from fastapi import (
    APIRouter,
    Cookie,
    Depends,
    Form,
    HTTPException,
    Query,
    Request,
    status,
)
from fastapi.responses import RedirectResponse
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import OAuthClient, User
from app.security import decode_token
from app.services.oauth import (
    CODE_CHALLENGE_METHODS,
    GRANT_TYPES,
    OAUTH_ALLOWED_SCOPES,
    consume_authorization,
    create_authorization,
    find_authorization_by_plain_code,
    find_client_by_client_id,
    generate_client_id,
    generate_client_secret,
    issue_access_token,
    parse_scope_string,
    refresh_access_token,
    revoke_token_by_plain,
    validate_code_verifier,
    validate_redirect_uri,
    validate_requested_scopes,
    verify_access_token,
    verify_client_secret,
    verify_pkce_s256,
)

router = APIRouter(prefix="/oauth", tags=["oauth"])


# ============ Pydantic schemas ============

class OAuthClientOut(BaseModel):
    """Метаданные клиента БЕЗ plaintext secret'а."""

    model_config = ConfigDict(from_attributes=True)
    id: int
    client_id: str
    name: str
    redirect_uris: list[str]
    scopes: list[str]
    is_active: bool
    created_at: datetime


class OAuthClientCreated(OAuthClientOut):
    """Response создания: добавлен plaintext_secret (показывается ОДИН раз!)."""
    plaintext_secret: str


class OAuthClientCreate(BaseModel):
    name: str = Field(min_length=1, max_length=128)
    redirect_uris: list[str] = Field(min_length=1, max_length=20)
    scopes: list[str] = Field(default_factory=list)


class OAuthClientUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=128)
    redirect_uris: list[str] | None = None
    scopes: list[str] | None = None
    is_active: bool | None = None


class ConsentScreenPayload(BaseModel):
    """Метаданные для рендера consent screen на фронте."""
    client_id: str
    client_name: str
    redirect_uri: str
    requested_scopes: list[str]
    state: str | None
    code_challenge: str | None
    code_challenge_method: str | None


class GrantPayload(BaseModel):
    """Body для POST /oauth/authorize/grant — финальное согласие."""
    client_id: str
    redirect_uri: str
    scopes: list[str]
    state: str | None = None
    code_challenge: str | None = None
    code_challenge_method: str = "S256"


# ============ Admin: clients CRUD ============

async def _get_client_or_404(
    session: AsyncSession, client_pk: int
) -> OAuthClient:
    row = (
        await session.execute(
            select(OAuthClient).where(OAuthClient.id == client_pk)
        )
    ).scalar_one_or_none()
    if not row:
        raise HTTPException(404, "Приложение не найдено")
    return row


@router.get("/clients", response_model=list[OAuthClientOut])
async def list_oauth_clients(
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """List всех OAuth-приложений (admin only)."""
    rows = (
        await session.execute(
            select(OAuthClient).order_by(OAuthClient.created_at.desc())
        )
    ).scalars().all()
    return rows


@router.post(
    "/clients",
    response_model=OAuthClientCreated,
    status_code=status.HTTP_201_CREATED,
)
async def create_oauth_client(
    payload: OAuthClientCreate,
    current_user: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать OAuth-приложение. Plaintext secret возвращается ОДИН раз."""
    # Валидация redirect_uris
    for uri in payload.redirect_uris:
        if not isinstance(uri, str) or not uri.strip():
            raise HTTPException(400, "redirect_uri должен быть непустой строкой")
        if not (uri.startswith("http://") or uri.startswith("https://")):
            raise HTTPException(400, f"redirect_uri должен быть http(s): {uri!r}")
    # Валидация scopes
    invalid = [s for s in payload.scopes if s not in OAUTH_ALLOWED_SCOPES]
    if invalid:
        raise HTTPException(
            400,
            f"Неизвестные scope'ы: {invalid}. "
            f"Допустимые: {sorted(OAUTH_ALLOWED_SCOPES)}",
        )

    public_client_id = generate_client_id()
    plain_secret, secret_hash = generate_client_secret()
    client = OAuthClient(
        client_id=public_client_id,
        client_secret_hash=secret_hash,
        name=payload.name,
        redirect_uris=list(payload.redirect_uris),
        scopes=list(payload.scopes),
        created_by_user_id=current_user.id,
        is_active=True,
    )
    session.add(client)
    await session.commit()
    await session.refresh(client)
    return OAuthClientCreated(
        id=client.id,
        client_id=client.client_id,
        name=client.name,
        redirect_uris=list(client.redirect_uris or []),
        scopes=list(client.scopes or []),
        is_active=client.is_active,
        created_at=client.created_at,
        plaintext_secret=plain_secret,
    )


@router.patch("/clients/{client_pk}", response_model=OAuthClientOut)
async def update_oauth_client(
    client_pk: int,
    payload: OAuthClientUpdate,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    client = await _get_client_or_404(session, client_pk)
    if payload.name is not None:
        client.name = payload.name
    if payload.redirect_uris is not None:
        for uri in payload.redirect_uris:
            if not isinstance(uri, str) or not uri.strip():
                raise HTTPException(400, "redirect_uri должен быть непустой строкой")
        client.redirect_uris = list(payload.redirect_uris)
    if payload.scopes is not None:
        invalid = [s for s in payload.scopes if s not in OAUTH_ALLOWED_SCOPES]
        if invalid:
            raise HTTPException(
                400, f"Неизвестные scope'ы: {invalid}"
            )
        client.scopes = list(payload.scopes)
    if payload.is_active is not None:
        client.is_active = bool(payload.is_active)
    await session.commit()
    await session.refresh(client)
    return client


@router.delete(
    "/clients/{client_pk}", status_code=status.HTTP_204_NO_CONTENT,
)
async def delete_oauth_client(
    client_pk: int,
    _: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Soft delete: is_active=False (сохраняем для аудита)."""
    client = await _get_client_or_404(session, client_pk)
    client.is_active = False
    await session.commit()


# ============ /authorize endpoint ============

@router.get("/authorize")
async def oauth_authorize(
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    access_token: Annotated[str | None, Cookie()] = None,
    client_id: str = Query(...),
    redirect_uri: str = Query(...),
    response_type: str = Query(default="code"),
    scope: str | None = Query(default=None),
    code_challenge: str | None = Query(default=None),
    code_challenge_method: str | None = Query(default="S256"),
    state: str | None = Query(default=None),
):
    """OAuth 2.0 /authorize entry point.

    Не залогинен → redirect на /login?return=<authorize-url>.
    Залогинен → возвращаем JSON для рендера consent screen фронтом.

    Финальное согласие — POST /oauth/authorize/grant с теми же параметрами.
    """
    if response_type != "code":
        raise HTTPException(400, "Поддерживается только response_type=code")

    client = await find_client_by_client_id(session, client_id)
    if not client:
        raise HTTPException(400, "client_id неизвестен или клиент отключён")

    if not validate_redirect_uri(redirect_uri, list(client.redirect_uris or [])):
        raise HTTPException(400, "redirect_uri не в whitelist клиента")

    if code_challenge_method and code_challenge_method not in CODE_CHALLENGE_METHODS:
        raise HTTPException(
            400, f"code_challenge_method должен быть S256, получено {code_challenge_method!r}"
        )

    requested = parse_scope_string(scope)
    try:
        validated_scopes = validate_requested_scopes(
            requested, list(client.scopes or [])
        )
    except ValueError as e:
        raise HTTPException(400, str(e)) from e

    # Проверка авторизации юзера
    user = await _resolve_user_from_cookie(session, access_token)
    if not user:
        # Редирект на /login со всеми параметрами для return
        params = {
            "client_id": client_id,
            "redirect_uri": redirect_uri,
            "response_type": response_type,
            "scope": " ".join(validated_scopes),
            "state": state or "",
        }
        if code_challenge:
            params["code_challenge"] = code_challenge
            params["code_challenge_method"] = code_challenge_method or "S256"
        return_url = f"/api/oauth/authorize?{urlencode(params)}"
        return RedirectResponse(f"/login?return={return_url}")

    # Залогинен — возвращаем JSON для рендера consent screen.
    return ConsentScreenPayload(
        client_id=client_id,
        client_name=client.name,
        redirect_uri=redirect_uri,
        requested_scopes=validated_scopes,
        state=state,
        code_challenge=code_challenge,
        code_challenge_method=code_challenge_method,
    )


async def _resolve_user_from_cookie(
    session: AsyncSession, access_token: str | None
) -> User | None:
    """Минимальная cookie-проверка без HTTPException."""
    if not access_token:
        return None
    payload = decode_token(access_token)
    if not payload or not payload.get("sub"):
        return None
    try:
        user_id = int(payload["sub"])
    except (TypeError, ValueError):
        return None
    user = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    if not user or not user.is_active:
        return None
    return user


@router.post("/authorize/grant")
async def oauth_authorize_grant(
    payload: GrantPayload,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Финальное согласие — фронт POST'ит с consent screen.

    Создаём OAuthAuthorization (code) и возвращаем redirect URL с ?code=<plain>&state=<>.
    """
    client = await find_client_by_client_id(session, payload.client_id)
    if not client:
        raise HTTPException(400, "client_id неизвестен или клиент отключён")
    if not validate_redirect_uri(payload.redirect_uri, list(client.redirect_uris or [])):
        raise HTTPException(400, "redirect_uri не в whitelist клиента")

    try:
        scopes = validate_requested_scopes(
            payload.scopes, list(client.scopes or [])
        )
    except ValueError as e:
        raise HTTPException(400, str(e)) from e

    if payload.code_challenge_method not in CODE_CHALLENGE_METHODS:
        raise HTTPException(
            400, f"code_challenge_method должен быть S256"
        )

    auth, plain_code = await create_authorization(
        session=session,
        client=client,
        user=current_user,
        redirect_uri=payload.redirect_uri,
        scopes=scopes,
        code_challenge=payload.code_challenge,
    )
    await session.commit()

    params: dict[str, str] = {"code": plain_code}
    if payload.state:
        params["state"] = payload.state
    redirect = f"{payload.redirect_uri}?{urlencode(params)}"
    return {"redirect_uri": redirect, "code": plain_code, "state": payload.state}


# ============ /token endpoint ============

@router.post("/token")
async def oauth_token(
    session: Annotated[AsyncSession, Depends(get_session)],
    grant_type: str = Form(...),
    client_id: str = Form(...),
    client_secret: str = Form(...),
    # authorization_code grant
    code: str | None = Form(default=None),
    redirect_uri: str | None = Form(default=None),
    code_verifier: str | None = Form(default=None),
    # refresh_token grant
    refresh_token: str | None = Form(default=None),
):
    """Обмен authorization_code → access_token, или refresh_token rotation.

    application/x-www-form-urlencoded по спеке RFC 6749 §4.1.3 / §6.

    Возвращает:
      {
        "access_token": "oat_...",
        "refresh_token": "ort_...",
        "token_type": "Bearer",
        "expires_in": 3600,
        "scope": "read:leads write:deals"
      }
    """
    if grant_type not in GRANT_TYPES:
        raise HTTPException(400, f"Unsupported grant_type: {grant_type!r}")

    client = await find_client_by_client_id(session, client_id)
    if not client:
        raise HTTPException(401, "invalid_client")
    if not verify_client_secret(client_secret, client.client_secret_hash):
        raise HTTPException(401, "invalid_client")

    if grant_type == "authorization_code":
        if not code or not redirect_uri:
            raise HTTPException(400, "code и redirect_uri обязательны")
        auth = await find_authorization_by_plain_code(session, client.id, code)
        if not auth:
            raise HTTPException(400, "invalid_grant: code не найден или истёк")
        if auth.redirect_uri != redirect_uri:
            raise HTTPException(400, "invalid_grant: redirect_uri не совпадает")
        # PKCE: если в authorization был code_challenge — обязан быть code_verifier.
        if auth.code_challenge:
            if not validate_code_verifier(code_verifier):
                raise HTTPException(400, "invalid_request: code_verifier невалиден")
            if not verify_pkce_s256(code_verifier, auth.code_challenge):
                raise HTTPException(400, "invalid_grant: PKCE проверка не прошла")

        # Получаем user
        user = (
            await session.execute(select(User).where(User.id == auth.user_id))
        ).scalar_one_or_none()
        if not user or not user.is_active:
            raise HTTPException(400, "invalid_grant: user inactive")

        # Атомарно гасим code ДО выдачи токенов. Conditional UPDATE
        # (used=false → true) сериализуется БД: при double-spend race ровно
        # один вызов получит rowcount==1, остальные → invalid_grant без
        # выдачи токенов. См. consume_authorization().
        consumed = await consume_authorization(session, auth.id)
        if not consumed:
            raise HTTPException(400, "invalid_grant: code уже использован")

        _, result = await issue_access_token(
            session, client, user, list(auth.scopes or [])
        )
        await session.commit()
        return _token_response(result)

    # refresh_token grant
    if not refresh_token:
        raise HTTPException(400, "refresh_token обязателен")
    pair = await refresh_access_token(session, client, refresh_token)
    if pair is None:
        raise HTTPException(400, "invalid_grant: refresh_token невалиден или revoked")
    _, result = pair
    await session.commit()
    return _token_response(result)


def _token_response(result) -> dict[str, Any]:
    return {
        "access_token": result.access_token,
        "refresh_token": result.refresh_token,
        "token_type": result.token_type,
        "expires_in": result.expires_in,
        "scope": result.scope,
    }


# ============ /revoke endpoint ============

@router.post("/revoke", status_code=status.HTTP_200_OK)
async def oauth_revoke(
    session: Annotated[AsyncSession, Depends(get_session)],
    token: str = Form(...),
    client_id: str = Form(...),
    client_secret: str = Form(...),
    token_type_hint: str | None = Form(default=None),
):
    """RFC 7009 — revoke access or refresh token."""
    client = await find_client_by_client_id(session, client_id)
    if not client:
        raise HTTPException(401, "invalid_client")
    if not verify_client_secret(client_secret, client.client_secret_hash):
        raise HTTPException(401, "invalid_client")
    # RFC 7009 §2.1: отзываем только токен, принадлежащий аутентифицированному
    # клиенту — нельзя глушить токены чужих интеграций (A1).
    revoked = await revoke_token_by_plain(session, token, client)
    await session.commit()
    # По RFC 7009 §2.2 — даже если токен не найден, возвращаем 200 OK.
    return {"revoked": bool(revoked)}


# ============ /userinfo endpoint (Bearer auth) ============

@router.get("/userinfo")
async def oauth_userinfo(
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """OIDC-style userinfo. Authorization: Bearer <access_token>.

    Возвращает базовые claims о владельце токена. Scope'ы определяют какие
    поля возвращаем: "openid" / "profile" / "email" — стандарт OIDC.
    """
    # Переиспользуем общий хелпер (нормализует регистр/пробелы) вместо ручного
    # среза префикса — единое поведение с deps.get_current_user_flexible (A1 REFACTORING).
    from app.deps import _extract_bearer

    plain = _extract_bearer(request.headers.get("Authorization"))
    if not plain:
        raise HTTPException(401, "Bearer token обязателен")
    pair = await verify_access_token(session, plain)
    if pair is None:
        raise HTTPException(401, "Bearer token невалиден или revoked/expired")
    token, user = pair
    scopes = set(token.scopes or [])

    claims: dict[str, Any] = {"sub": str(user.id)}
    if "profile" in scopes or "openid" in scopes:
        claims["name"] = user.full_name
        claims["preferred_username"] = user.email
    if "email" in scopes:
        claims["email"] = user.email
        claims["email_verified"] = True
    # Доп. кастомные claims
    claims["role"] = user.role.value
    return claims
