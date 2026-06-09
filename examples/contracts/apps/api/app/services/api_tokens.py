"""API token: генерация, хэширование, lookup (Эпик 11.1).

Plaintext-формат: "mc_<urlsafe40>" — префикс "mc_" нужен чтобы можно было
визуально и грепом отличить наш токен в логах от чужих. Тело 40 байт URL-safe
≈ 54 символа (`secrets.token_urlsafe(40)` — 54 base64url-символа без `=`).

Хэш — SHA256(plaintext) hex (64 символа). Plaintext не хранится в БД — даже
если БД утечёт, токены не воссоздаются обратно. Owner видит plaintext один
раз при create (POST /api/api-tokens возвращает его в response.plaintext_token).

Lookup: при Bearer-запросе берём header value, считаем SHA256(value),
ищем `APIToken WHERE token_hash=hash AND is_active AND not revoked AND
(expires_at IS NULL OR expires_at > now())`. Это O(1) через UNIQUE-index
на token_hash.
"""
from __future__ import annotations

import hashlib
import secrets
from datetime import UTC, datetime

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import APIToken, User

# Префикс plaintext'а — для визуальной идентификации (как ghp_ у GitHub).
TOKEN_PREFIX = "mc_"
# Число байт энтропии для secrets.token_urlsafe (плотность ≈ 1.33 chars per byte).
# 40 байт = 320 бит — выше recommended 256 бит для opaque-токенов.
TOKEN_ENTROPY_BYTES = 40


def generate_token() -> tuple[str, str]:
    """Сгенерировать новый API token.

    Returns:
        (plaintext, token_hash) — plaintext "mc_<54chars>", hash SHA256 hex (64).
    """
    body = secrets.token_urlsafe(TOKEN_ENTROPY_BYTES)
    plaintext = f"{TOKEN_PREFIX}{body}"
    token_hash = hash_token(plaintext)
    return plaintext, token_hash


def hash_token(plaintext: str) -> str:
    """SHA256 hex от plaintext — для записи в APIToken.token_hash."""
    return hashlib.sha256(plaintext.encode("utf-8")).hexdigest()


def looks_like_macro_token(plaintext: str) -> bool:
    """Эвристика: похож ли на наш токен (для раннего отказа при invalid Bearer).

    Используется чтобы не дёргать БД на каждое чужое значение Bearer'а — если
    в проекте параллельно используется JWT в Bearer для каких-то внешних API
    (например, OnlyOffice), мы их не путаем.
    """
    return (
        isinstance(plaintext, str)
        and plaintext.startswith(TOKEN_PREFIX)
        and len(plaintext) >= len(TOKEN_PREFIX) + 40
    )


async def resolve_token(
    session: AsyncSession, plaintext: str
) -> tuple[APIToken, User] | None:
    """Найти валидный APIToken + User по plaintext Bearer'у.

    Возвращает None, если:
    - plaintext не похож на наш токен (early-return без БД)
    - token не найден
    - is_active=False / revoked_at не NULL
    - expires_at прошёл
    - user не активен

    Сюда стоит звать ВНУТРИ deps.OptionalAPITokenUser. Update last_used_at —
    отдельным вызовом `touch_token` после успешного запроса (чтобы не нагружать
    каждый Bearer-call записью в БД, можно дебаунсить — пока пишем каждый раз).
    """
    if not looks_like_macro_token(plaintext):
        return None
    th = hash_token(plaintext)
    now = datetime.now(UTC)
    row = (
        await session.execute(
            select(APIToken, User)
            .join(User, User.id == APIToken.user_id)
            .where(
                APIToken.token_hash == th,
                APIToken.is_active.is_(True),
                APIToken.revoked_at.is_(None),
                User.is_active.is_(True),
            )
        )
    ).first()
    if row is None:
        return None
    token, user = row
    if token.expires_at is not None and token.expires_at <= now:
        return None
    return token, user


async def touch_token(
    session: AsyncSession, token: APIToken, ip: str | None
) -> None:
    """Обновить last_used_at / last_used_ip. Не падает наружу — best-effort.

    Делаем UPDATE без блокировки (нам не критична точность до миллисекунды).
    Коммит — на вызывающей стороне (внутри request lifecycle SQLAlchemy сама
    закоммитит).
    """
    token.last_used_at = datetime.now(UTC)
    if ip:
        token.last_used_ip = ip[:45]
