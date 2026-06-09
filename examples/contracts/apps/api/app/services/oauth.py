"""Эпик 15 — OAuth 2.0 Provider сервис (RFC 6749 + RFC 7636 PKCE).

Реализация Authorization Code grant с PKCE S256:
1. /authorize → выдать code (10-min TTL, code_challenge сохраняется)
2. /token (code + verifier) → access_token + refresh_token
3. /token (refresh_token) → новый access_token + refresh_token (rotation)
4. /revoke → пометить токен revoked=True
5. /userinfo → OpenID Connect-style endpoint, отдаёт claims о владельце

Архитектурные решения:
- code_hash = bcrypt(plaintext) — как и password_hash, не вводим новую крипту.
- access_token_hash / refresh_token_hash = SHA256(plaintext) hex —
  быстрый O(1) lookup (как наш APIToken.token_hash).
- code_verifier (PKCE) проверяется через S256: base64url(SHA256(verifier))
  должен совпасть с code_challenge.

Что НЕ реализуем в MVP:
- Implicit / Client Credentials / Password grant — устарели по RFC 6749.
- Plain code_challenge_method — только S256 (RFC 7636 рекомендует).
- Dynamic client registration (RFC 7591) — клиенты заводятся вручную админом.
- JWT-based access tokens (JWS/JWE) — opaque достаточно.

Pure-функции — в test_oauth_flow.py без БД.
"""
from __future__ import annotations

import base64
import hashlib
import secrets
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta

from passlib.context import CryptContext
from sqlalchemy import update
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    OAuthAuthorization,
    OAuthClient,
    OAuthToken,
    User,
)

# bcrypt для code_hash и client_secret_hash. Тот же scheme что для пароля.
_bcrypt = CryptContext(schemes=["bcrypt"], deprecated="auto")

# TTL authorization code — 10 минут (стандарт RFC 6749 §4.1.2 = "short-lived").
AUTH_CODE_TTL_MINUTES = 10

# TTL access_token — 60 минут.
ACCESS_TOKEN_TTL_MINUTES = 60

# TTL refresh_token — 30 дней. После — клиент должен запросить новое
# authorization code (re-consent).
REFRESH_TOKEN_TTL_DAYS = 30

# Префикс OAuth-токенов (визуально отделяем от наших API-токенов "mc_").
ACCESS_TOKEN_PREFIX = "oat_"
REFRESH_TOKEN_PREFIX = "ort_"
AUTH_CODE_PREFIX = "oac_"

# Допустимые scope'ы для OAuth (subset наших API-scopes).
# Жёсткий whitelist — не пускаем "*" admin-scope через OAuth.
OAUTH_ALLOWED_SCOPES: frozenset[str] = frozenset({
    "read:leads", "write:leads",
    "read:deals", "write:deals",
    "read:contacts", "write:contacts",
    "read:companies", "write:companies",
    "read:counterparties", "write:counterparties",
    "read:contracts", "write:contracts",
    "read:subscriptions", "write:subscriptions",
    "openid", "profile", "email",  # OIDC standard
})

# Допустимые grant_type'ы.
GRANT_TYPES: frozenset[str] = frozenset({"authorization_code", "refresh_token"})

# code_challenge_method — только S256 (RFC 7636 §4.2 — plain deprecated).
CODE_CHALLENGE_METHODS: frozenset[str] = frozenset({"S256"})


# ============ Pure helpers ============

def generate_client_id() -> str:
    """URL-safe public client_id (24 bytes ≈ 32 chars)."""
    return secrets.token_urlsafe(24)


def generate_client_secret() -> tuple[str, str]:
    """Сгенерировать client_secret + bcrypt-хэш.

    Returns:
        (plaintext, bcrypt_hash). Plaintext возвращается клиенту ОДИН раз.
    """
    plain = secrets.token_urlsafe(32)
    return plain, _bcrypt.hash(plain)


def verify_client_secret(plain: str, hashed: str) -> bool:
    """Constant-time проверка client_secret через bcrypt."""
    if not plain or not hashed:
        return False
    try:
        return _bcrypt.verify(plain, hashed)
    except (ValueError, TypeError):
        return False


def generate_authorization_code_plain() -> str:
    """Plaintext authorization code (32 byte URL-safe ≈ 43 chars + prefix).

    Возвращается в redirect_uri?code=<plain>. Хэшируется bcrypt'ом для БД.
    """
    return f"{AUTH_CODE_PREFIX}{secrets.token_urlsafe(32)}"


def hash_authorization_code(plain: str) -> str:
    """bcrypt-хэш для хранения в oauth_authorizations.code_hash."""
    return _bcrypt.hash(plain)


def verify_authorization_code_hash(plain: str, hashed: str) -> bool:
    """Constant-time проверка кода (bcrypt)."""
    if not plain or not hashed:
        return False
    try:
        return _bcrypt.verify(plain, hashed)
    except (ValueError, TypeError):
        return False


def generate_access_token_plain() -> str:
    """oat_<urlsafe40> — формат как APIToken.mc_<...>."""
    return f"{ACCESS_TOKEN_PREFIX}{secrets.token_urlsafe(40)}"


def generate_refresh_token_plain() -> str:
    """ort_<urlsafe40>."""
    return f"{REFRESH_TOKEN_PREFIX}{secrets.token_urlsafe(40)}"


def hash_token_sha256(plain: str) -> str:
    """SHA256 hex для O(1) lookup access/refresh_token."""
    return hashlib.sha256(plain.encode("utf-8")).hexdigest()


# ============ PKCE ============

def compute_pkce_s256_challenge(verifier: str) -> str:
    """Pure-функция: PKCE S256 challenge из verifier.

    RFC 7636 §4.2: base64url(SHA256(verifier)) без padding.
    """
    if not verifier:
        return ""
    sha = hashlib.sha256(verifier.encode("ascii")).digest()
    return base64.urlsafe_b64encode(sha).decode("ascii").rstrip("=")


def verify_pkce_s256(verifier: str, challenge: str) -> bool:
    """Constant-time: совпадает ли SHA256(verifier) с challenge.

    Pure-функция. Используется на /token при наличии code_challenge в
    соответствующей OAuthAuthorization.
    """
    if not verifier or not challenge:
        return False
    expected = compute_pkce_s256_challenge(verifier)
    return secrets.compare_digest(expected, challenge)


def validate_code_verifier(verifier: str | None) -> bool:
    """RFC 7636 §4.1: code_verifier = [A-Z][a-z][0-9]-._~ длиной 43..128."""
    if not verifier or not isinstance(verifier, str):
        return False
    if not (43 <= len(verifier) <= 128):
        return False
    # Допустимые символы RFC 7636
    allowed = set(
        "ABCDEFGHIJKLMNOPQRSTUVWXYZ"
        "abcdefghijklmnopqrstuvwxyz"
        "0123456789-._~"
    )
    return all(c in allowed for c in verifier)


# ============ Scope helpers ============

def validate_requested_scopes(
    requested: list[str], client_allowed: list[str]
) -> list[str]:
    """Проверить что requested scope'ы есть в client_allowed (subset).

    Raises:
        ValueError если хотя бы один scope не в client_allowed или
        не в OAUTH_ALLOWED_SCOPES.

    Returns:
        Отсортированный список уникальных scope'ов (для записи в
        OAuthAuthorization.scopes).
    """
    if not isinstance(requested, list):
        raise ValueError("scopes должен быть списком строк")
    client_set = set(client_allowed)
    out: set[str] = set()
    for s in requested:
        if not isinstance(s, str):
            raise ValueError(f"scope должен быть строкой: {s!r}")
        if s not in OAUTH_ALLOWED_SCOPES:
            raise ValueError(
                f"Неизвестный OAuth-scope: {s!r}. "
                f"Допустимые: {sorted(OAUTH_ALLOWED_SCOPES)}"
            )
        if s not in client_set:
            raise ValueError(
                f"Scope {s!r} не разрешён клиенту "
                f"(client.scopes={sorted(client_set)})"
            )
        out.add(s)
    return sorted(out)


def parse_scope_string(scope_str: str | None) -> list[str]:
    """RFC 6749 §3.3: scope-параметр — space-separated. Возвращает list."""
    if not scope_str:
        return []
    return [s for s in scope_str.split() if s]


# ============ Result dataclass ============

@dataclass
class TokenIssuedResult:
    """Результат /token endpoint'а."""
    access_token: str
    refresh_token: str
    token_type: str  # "Bearer"
    expires_in: int  # seconds
    scope: str       # space-separated


# ============ Async DB-операции ============

async def find_client_by_client_id(
    session: AsyncSession, client_id: str
) -> OAuthClient | None:
    """Найти активный OAuthClient по publik client_id."""
    if not client_id:
        return None
    return (
        await session.execute(
            select(OAuthClient).where(
                OAuthClient.client_id == client_id,
                OAuthClient.is_active.is_(True),
            )
        )
    ).scalar_one_or_none()


async def create_authorization(
    session: AsyncSession,
    client: OAuthClient,
    user: User,
    redirect_uri: str,
    scopes: list[str],
    code_challenge: str | None,
) -> tuple[OAuthAuthorization, str]:
    """Создать OAuthAuthorization + plaintext code.

    Возвращает (auth, plaintext_code). plaintext_code улетает в
    redirect_uri?code=<>; в БД лежит bcrypt-хэш.
    """
    plain_code = generate_authorization_code_plain()
    code_hash = hash_authorization_code(plain_code)
    expires_at = datetime.now(UTC) + timedelta(minutes=AUTH_CODE_TTL_MINUTES)
    auth = OAuthAuthorization(
        client_id=client.id,
        user_id=user.id,
        code_hash=code_hash,
        code_challenge=code_challenge,
        redirect_uri=redirect_uri,
        scopes=scopes or [],
        expires_at=expires_at,
        used=False,
    )
    session.add(auth)
    await session.flush()
    return auth, plain_code


async def find_authorization_by_plain_code(
    session: AsyncSession, client_id: int, plain_code: str
) -> OAuthAuthorization | None:
    """Найти не-использованный неистёкший OAuthAuthorization по plain code.

    Поскольку код хранится как bcrypt-хэш, нет O(1) lookup'а — берём все
    свежие записи клиента и сверяем bcrypt'ом. На MVP это OK: TTL 10 мин,
    активных кодов на клиента в пике <100. При росте — переходим на SHA256
    как у access_token (потеряем bcrypt-stretching для очень короткоживущего
    значения, что приемлемо).
    """
    if not plain_code or not plain_code.startswith(AUTH_CODE_PREFIX):
        return None
    now = datetime.now(UTC)
    candidates = (
        await session.execute(
            select(OAuthAuthorization).where(
                OAuthAuthorization.client_id == client_id,
                OAuthAuthorization.used.is_(False),
                OAuthAuthorization.expires_at > now,
            ).order_by(OAuthAuthorization.created_at.desc()).limit(50)
        )
    ).scalars().all()
    for auth in candidates:
        if auth.code_hash and verify_authorization_code_hash(plain_code, auth.code_hash):
            return auth
    return None


async def consume_authorization(
    session: AsyncSession, authorization_id: int
) -> bool:
    """Атомарно погасить authorization code (single-use guard).

    `UPDATE oauth_authorizations SET used=true WHERE id=:id AND used=false`.
    Возвращает True ровно одному вызову (rowcount == 1), всем последующим —
    False. Это закрывает double-spend race: даже если две параллельные token-
    request'а одновременно прошли find_authorization_by_plain_code (обе видели
    used=False), сериализованный conditional UPDATE на уровне БД пропустит
    только одну. Caller обязан выдавать токены ТОЛЬКО при True, иначе
    invalid_grant.

    NB: ORM-инстанс auth в сессии caller'а после этого может иметь stale
    `used` — мы намеренно не трогаем его, чтобы не плодить рассинхрон; гейтом
    служит возвращаемый bool, а не атрибут объекта.
    """
    result = await session.execute(
        update(OAuthAuthorization)
        .where(
            OAuthAuthorization.id == authorization_id,
            OAuthAuthorization.used.is_(False),
        )
        .values(used=True)
    )
    return (result.rowcount or 0) == 1


async def issue_access_token(
    session: AsyncSession,
    client: OAuthClient,
    user: User,
    scopes: list[str],
) -> tuple[OAuthToken, TokenIssuedResult]:
    """Создать OAuthToken + вернуть plaintext'ы access/refresh.

    Caller обновляет authorization.used = True и await commit.
    """
    access_plain = generate_access_token_plain()
    refresh_plain = generate_refresh_token_plain()
    access_hash = hash_token_sha256(access_plain)
    refresh_hash = hash_token_sha256(refresh_plain)
    expires_at = datetime.now(UTC) + timedelta(minutes=ACCESS_TOKEN_TTL_MINUTES)

    token = OAuthToken(
        client_id=client.id,
        user_id=user.id,
        access_token_hash=access_hash,
        refresh_token_hash=refresh_hash,
        scopes=scopes or [],
        expires_at=expires_at,
        revoked=False,
    )
    session.add(token)
    await session.flush()

    result = TokenIssuedResult(
        access_token=access_plain,
        refresh_token=refresh_plain,
        token_type="Bearer",
        expires_in=ACCESS_TOKEN_TTL_MINUTES * 60,
        scope=" ".join(sorted(scopes or [])),
    )
    return token, result


async def verify_access_token(
    session: AsyncSession, plaintext: str
) -> tuple[OAuthToken, User] | None:
    """Найти не-revoked, не-expired OAuthToken + его user по plaintext.

    O(1) через SHA256 hash lookup + partial index idx_oauth_tokens_access.
    Возвращает None если:
      - не похож на oat_<...>
      - не найден / revoked / expired
      - user не активен
    """
    if not plaintext or not plaintext.startswith(ACCESS_TOKEN_PREFIX):
        return None
    th = hash_token_sha256(plaintext)
    now = datetime.now(UTC)
    row = (
        await session.execute(
            select(OAuthToken, User)
            .join(User, User.id == OAuthToken.user_id)
            .where(
                OAuthToken.access_token_hash == th,
                OAuthToken.revoked.is_(False),
                OAuthToken.expires_at > now,
                User.is_active.is_(True),
            )
        )
    ).first()
    if row is None:
        return None
    token, user = row
    return token, user


async def refresh_access_token(
    session: AsyncSession,
    client: OAuthClient,
    refresh_plain: str,
) -> tuple[OAuthToken, TokenIssuedResult] | None:
    """Обмен refresh_token → новый access_token (с rotation refresh).

    Старый OAuthToken помечается revoked=True, новый создаётся со
    свежей парой access/refresh. Защита от replay: использование старого
    refresh после rotation вернёт None.
    """
    if not refresh_plain or not refresh_plain.startswith(REFRESH_TOKEN_PREFIX):
        return None
    rh = hash_token_sha256(refresh_plain)
    # Refresh не имеет своего TTL в БД (наследует expires_at от access).
    # Но проверим что не revoked и не старше REFRESH_TOKEN_TTL_DAYS.
    cutoff = datetime.now(UTC) - timedelta(days=REFRESH_TOKEN_TTL_DAYS)
    old = (
        await session.execute(
            select(OAuthToken).where(
                OAuthToken.refresh_token_hash == rh,
                OAuthToken.client_id == client.id,
                OAuthToken.revoked.is_(False),
                OAuthToken.created_at > cutoff,
            )
        )
    ).scalar_one_or_none()
    if old is None:
        return None
    user = (
        await session.execute(select(User).where(User.id == old.user_id))
    ).scalar_one_or_none()
    if not user or not user.is_active:
        return None

    # Rotation: помечаем старый revoked
    old.revoked = True

    return await issue_access_token(
        session, client, user, old.scopes or []
    )


async def revoke_token_by_plain(
    session: AsyncSession, plaintext: str, client: OAuthClient | None = None
) -> bool:
    """Пометить access или refresh token как revoked.

    RFC 7009 — принимаем любой из двух (по prefix отличаем). Возвращает
    True если что-то помечено.

    A1 (RFC 7009 §2.1): если передан `client`, отзыв скоупится к токенам
    ЭТОГО клиента — клиент не может отозвать токен чужой интеграции, зная
    только его строку (DoS-вектор). `client=None` оставлен для внутренних
    вызовов (logout/смена пароля), где скоуп клиента не применим.
    """
    if not plaintext:
        return False
    th = hash_token_sha256(plaintext)
    if plaintext.startswith(ACCESS_TOKEN_PREFIX):
        col = OAuthToken.access_token_hash
    elif plaintext.startswith(REFRESH_TOKEN_PREFIX):
        col = OAuthToken.refresh_token_hash
    else:
        return False
    conditions = [col == th, OAuthToken.revoked.is_(False)]
    if client is not None:
        conditions.append(OAuthToken.client_id == client.id)
    token = (
        await session.execute(
            select(OAuthToken).where(*conditions)
        )
    ).scalar_one_or_none()
    if token is None:
        return False
    token.revoked = True
    return True


# ============ Validate redirect_uri ============

def validate_redirect_uri(
    redirect_uri: str, client_redirect_uris: list[str]
) -> bool:
    """RFC 6749 §3.1.2: точное совпадение redirect_uri с whitelist клиента.

    Pure-функция. Никаких wildcards / scheme normalization.
    """
    if not redirect_uri or not client_redirect_uris:
        return False
    return redirect_uri in client_redirect_uris
