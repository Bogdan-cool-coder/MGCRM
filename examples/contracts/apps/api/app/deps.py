"""Зависимости FastAPI: аутентификация и проверка ролей.

Содержит:
- get_current_user / CurrentUser / AdminUser / LawyerOrAdmin / DirectorOrAdmin —
  cookie-auth по `access_token` (JWT HS256). PRIMARY для UI.
- get_current_user_flexible / CurrentUserFlexible — cookie ИЛИ Bearer (Эпик 11.1).
  Bearer берётся как APIToken (opaque, SHA256 lookup); cookie — как JWT.
  Используется на opt-in endpoint'ах публичного API (см. Эпик 11.1).
- require_scope(scope) — фабрика: проверяет, что у Bearer-токена есть scope.
  Для cookie-юзера scope всегда True (UI работает через RBAC).
- require_owner_or_role(...) — фабрика, заменяющая ~30 копипастных проверок
  `if user.role == manager and obj.<owner_field> != user.id`. Возвращает обогащённую
  зависимость, которая 401/403/404 и кладёт объект в эндпоинт.
- scope_to_user(stmt, model, user, owner_field=...) — добавляет WHERE для list-эндпоинтов,
  если роль = manager (admin/director видят всё).

Зачем: ранее inline-проверки в каждом endpoint'е приводили к рассинхрону (одни
проверяли author_user_id, другие owner_user_id, третьи забывали проверку вовсе),
и list-эндпоинты протекали — manager видел чужих лидов/контактов/компаний.
"""

from __future__ import annotations

from collections.abc import Awaitable, Callable
from typing import Annotated, Any

from fastapi import Cookie, Depends, Header, HTTPException, Request, status
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.models import APIToken, BulkTask, Contract, User, UserRole
from app.security import decode_token
from app.services.api_scopes import scope_satisfies
from app.services.api_tokens import resolve_token, touch_token
from app.services.last_used_debounce import should_update_last_used
from app.services.rate_limit import check_rate_limit


async def get_current_user(
    session: Annotated[AsyncSession, Depends(get_session)],
    access_token: Annotated[str | None, Cookie()] = None,
) -> User:
    if not access_token:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Not authenticated")

    payload = decode_token(access_token)
    if not payload or not payload.get("sub"):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid token")

    user_id = int(payload["sub"])
    user = (await session.execute(select(User).where(User.id == user_id))).scalar_one_or_none()
    if not user or not user.is_active:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="User not found or inactive")
    return user


# ============ Bearer Auth (Эпик 11.1) — opt-in поверх cookie ============

# `request.state.api_token` устанавливается get_current_user_flexible, если
# юзер пришёл по Bearer. Используется в require_scope для проверки прав.
# Для cookie-юзера атрибут отсутствует (или None) — scope-check тогда пропускается.


def _extract_bearer(authorization: str | None) -> str | None:
    """Извлечь plaintext token из header `Authorization: Bearer <...>`.

    Возвращает None, если header отсутствует или не начинается с `Bearer `.
    Пробельный токен (`Bearer `) → None.
    """
    if not authorization:
        return None
    parts = authorization.split(None, 1)
    if len(parts) != 2 or parts[0].lower() != "bearer":
        return None
    plaintext = parts[1].strip()
    return plaintext or None


async def get_current_user_flexible(
    request: Request,
    session: Annotated[AsyncSession, Depends(get_session)],
    access_token: Annotated[str | None, Cookie()] = None,
    authorization: Annotated[str | None, Header()] = None,
) -> User:
    """Auth-зависимость с поддержкой cookie ИЛИ Bearer (Эпик 11.1).

    Порядок проверки:
    1. Cookie `access_token` (JWT) — primary для UI. Если valid → возвращаем юзера.
    2. Bearer `Authorization` — opaque APIToken. Если valid → возвращаем владельца,
       проставляем request.state.api_token (для require_scope).
    3. Иначе 401.

    Cookie приоритетнее Bearer: если оба переданы — берём cookie (UI юзер
    с консольным токеном для отладки не теряет UI-сессию).
    """
    # 1) Cookie path — точно как get_current_user, но без 401 при отсутствии
    if access_token:
        payload = decode_token(access_token)
        if payload and payload.get("sub"):
            try:
                user_id = int(payload["sub"])
            except (TypeError, ValueError):
                user_id = None
            if user_id is not None:
                user = (
                    await session.execute(
                        select(User).where(User.id == user_id)
                    )
                ).scalar_one_or_none()
                if user and user.is_active:
                    return user

    # 2) Bearer path — opaque APIToken
    plaintext = _extract_bearer(authorization)
    if plaintext:
        resolved = await resolve_token(session, plaintext)
        if resolved is not None:
            token, user = resolved
            # Прокинем токен в request.state, чтобы require_scope мог проверить
            request.state.api_token = token

            # Epic 16 — Security: Bearer rate-limit (token-bucket в Redis).
            # token_hash хранится в БД (sha256 plain'а) — используем как
            # ключ rate-limit.
            allowed, rl_info = await check_rate_limit(
                token.token_hash, token.rate_limit_per_hour,
            )
            # Прокидываем info в request.state, чтобы middleware/response
            # мог добавить X-RateLimit-* headers (сделаем в middleware
            # позже; пока хотя бы метаданные доступны).
            request.state.rate_limit_info = rl_info
            if not allowed:
                raise HTTPException(
                    status_code=status.HTTP_429_TOO_MANY_REQUESTS,
                    detail=(
                        f"Rate limit exceeded: {rl_info.limit}/hour. "
                        f"Retry after {rl_info.retry_after_seconds}s"
                    ),
                    headers=rl_info.to_headers(),
                )

            # Best-effort обновление last_used_at/last_used_ip с debounce.
            # Точность ±10 мин — приемлемо для UI «когда последний раз ходил».
            if await should_update_last_used(token.token_hash):
                ip = None
                if request.client:
                    ip = request.client.host
                await touch_token(session, token, ip)
            return user

    raise HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Не авторизован: нужен cookie access_token или Bearer-токен",
    )


CurrentUserFlexible = Annotated[User, Depends(get_current_user_flexible)]


def require_scope(scope: str):
    """Фабрика: проверка scope для Bearer-юзеров (Эпик 11.1).

    Если юзер пришёл по cookie (UI) — scope не проверяется (UI/RBAC).
    Если по Bearer — проверяем `scope_satisfies(token.scopes, scope)`.

    Пример использования:
        @router.get("/leads", response_model=list[LeadOut])
        async def list_leads(
            current_user: CurrentUserFlexible,
            _: Annotated[None, Depends(require_scope("read:leads"))],
            ...
        ):
            ...

    На non-flexible endpoint'ах (только cookie) require_scope не имеет смысла —
    cookie-юзеры всегда пройдут проверку.
    """
    async def _checker(
        request: Request,
        session: Annotated[AsyncSession, Depends(get_session)],
        current_user: Annotated[User, Depends(get_current_user_flexible)],
        authorization: Annotated[str | None, Header()] = None,
    ) -> None:
        token: APIToken | None = getattr(request.state, "api_token", None)

        # Баг #7 (security): если в заголовке есть Bearer-токен, его scope ДОЛЖЕН
        # проверяться, даже когда юзер аутентифицировался по cookie. Иначе токен
        # с узким scope (read:leads) получал бы полные права cookie-сессии при
        # одновременной передаче cookie + Bearer. get_current_user_flexible при
        # наличии валидной cookie возвращает cookie-юзера и НЕ кладёт api_token в
        # request.state — поэтому резолвим Bearer здесь независимо.
        if token is None:
            plaintext = _extract_bearer(authorization)
            if plaintext:
                resolved = await resolve_token(session, plaintext)
                if resolved is not None:
                    token, _owner = resolved
            if token is None:
                # Чистый cookie path (Bearer отсутствует) — scope не проверяем (RBAC).
                return

        if not scope_satisfies(token.scopes or [], scope):
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail=(
                    f"У токена недостаточно прав. Нужен scope: {scope!r}; "
                    f"есть: {sorted(token.scopes or [])}"
                ),
            )

    return _checker


def require_roles(*roles: UserRole):
    """Фабрика зависимостей: проверяет что роль пользователя входит в список."""

    async def _checker(current_user: Annotated[User, Depends(get_current_user)]) -> User:
        if current_user.role not in roles:
            raise HTTPException(status_code=status.HTTP_403_FORBIDDEN, detail="Forbidden")
        return current_user

    return _checker


CurrentUser = Annotated[User, Depends(get_current_user)]
AdminUser = Annotated[User, Depends(require_roles(UserRole.admin))]
LawyerOrAdmin = Annotated[User, Depends(require_roles(UserRole.admin, UserRole.lawyer))]
DirectorOrAdmin = Annotated[User, Depends(require_roles(UserRole.admin, UserRole.director))]
AnyAuthenticated = Annotated[User, Depends(get_current_user)]


# ============ RBAC централизация (Блок A аудита) ============

# Роли, которым разрешён доступ к любому объекту независимо от owner_field.
# По умолчанию — admin + director. Lawyer может дочитываться отдельно у каждого
# require_owner_or_role(..., elevated=(...,)) если нужно.
_DEFAULT_ELEVATED: tuple[UserRole, ...] = (UserRole.admin, UserRole.director)


def require_owner_or_role(
    obj_loader: Callable[[AsyncSession, int], Awaitable[Any]],
    owner_field: str = "owner_user_id",
    elevated: tuple[UserRole, ...] = _DEFAULT_ELEVATED,
):
    """Заменяет копипастные `if user.role == manager and obj.<X> != user.id` блоки.

    Возвращает FastAPI-зависимость, которая:
    - 401 если не авторизован (через get_current_user)
    - 400 если в URL не оказалось ни одного *_id (это ошибка регистрации эндпоинта)
    - 404 если объект не найден
    - 403 если роль не входит в `elevated` И owner_field != current_user.id
    - иначе возвращает объект (Contract / BulkTask / ...).

    Пример:
        @router.patch("/contracts/{contract_id}")
        async def update_contract(
            contract: Annotated[Contract, Depends(
                require_owner_or_role(load_contract, "author_user_id")
            )],
            ...
        ):
            # Без inline-проверки доступа — гард уже отработал!
            contract.title = "..."

    Принимает любой *_id Path-параметр (contract_id, lead_id, deal_id, bulk_task_id,
    subscription_id, attachment_id и т.п.) — берём первый ненулевой. Если у эндпоинта
    несколько Path-параметров (например /contracts/{contract_id}/attachments/{attachment_id})
    и нужен id родителя, то именно contract_id будет первым и попадёт в loader.
    Для дочерних объектов нужен отдельный loader (см. `load_contract_attachment`).
    """
    async def dep(
        session: Annotated[AsyncSession, Depends(get_session)],
        user: Annotated[User, Depends(get_current_user)],
        contract_id: int | None = None,
        lead_id: int | None = None,
        deal_id: int | None = None,
        bulk_task_id: int | None = None,
        subscription_id: int | None = None,
        cp_id: int | None = None,
        company_id: int | None = None,
        contact_id: int | None = None,
        attachment_id: int | None = None,
        revision_id: int | None = None,
        remark_id: int | None = None,
    ) -> Any:
        # Берём первый ненулевой id в порядке вероятности (родительские сущности первыми)
        obj_id = next(
            (x for x in [
                contract_id, lead_id, deal_id, bulk_task_id,
                subscription_id, cp_id, company_id, contact_id,
                attachment_id, revision_id, remark_id,
            ] if x is not None),
            None,
        )
        if obj_id is None:
            raise HTTPException(
                status.HTTP_400_BAD_REQUEST,
                "Missing entity id in URL path",
            )
        obj = await obj_loader(session, obj_id)
        if obj is None:
            raise HTTPException(status.HTTP_404_NOT_FOUND, "Объект не найден")
        if user.role in elevated:
            return obj
        if getattr(obj, owner_field, None) == user.id:
            return obj
        raise HTTPException(
            status.HTTP_403_FORBIDDEN,
            "Доступ только владельцу или администратору",
        )

    return dep


# P0 security (Unit 3a): роли, которым scope_to_user НЕ ограничивает выборку
# (видят всё). Явный whitelist «кому НЕ ограничивать», fail-CLOSED для всех
# остальных. Lawyer оставлен elevated по бизнес-причине (юрист видит все
# контракты/лиды для согласования) — это осознанное решение, а не дефолт.
# Раньше ограничивался ТОЛЬКО manager → accountant/cfo/новые роли видели всё.
_SCOPE_ELEVATED_ROLES: frozenset[UserRole] = frozenset(
    {UserRole.admin, UserRole.director, UserRole.lawyer}
)


def scope_to_user(stmt, model, user: User, owner_field: str = "owner_id"):
    """Owner-scope для list endpoints: ограничивает выборку владельцу.

    P0 security (Unit 3a): fail-CLOSED. Видят всё только роли из явного whitelist
    `_SCOPE_ELEVATED_ROLES` (admin/director/lawyer). ЛЮБАЯ другая роль (manager,
    accountant, cfo, будущая роль) — только свои записи (owner_field == user.id).
    Раньше ограничивался лишь manager, из-за чего accountant/cfo/новые роли
    получали god-mode на списках.

    Использование:
        @router.get("")
        async def list_leads(current_user: CurrentUser, ...):
            stmt = select(Lead).order_by(...)
            stmt = scope_to_user(stmt, Lead, current_user, "owner_id")
            return (await session.execute(stmt)).scalars().all()

    Поле owner_field должно существовать в model (иначе AttributeError на etape
    компиляции SQL). Передавай явно, чтобы исключить путаницу owner_id vs
    owner_user_id (которое использует Deal/Contract).
    """
    if user.role in _SCOPE_ELEVATED_ROLES:
        return stmt
    col = getattr(model, owner_field)
    return stmt.where(col == user.id)


# ============ Loader-функции для часто используемых сущностей ============

async def load_contract(session: AsyncSession, contract_id: int) -> Contract | None:
    """Используется require_owner_or_role(load_contract, 'author_user_id') в роутерах контрактов."""
    return (await session.execute(select(Contract).where(Contract.id == contract_id))).scalar_one_or_none()


async def load_bulk_task(session: AsyncSession, bulk_task_id: int) -> BulkTask | None:
    """Используется require_owner_or_role(load_bulk_task, 'created_by_user_id') в bulk-tasks роутере."""
    return (await session.execute(select(BulkTask).where(BulkTask.id == bulk_task_id))).scalar_one_or_none()
