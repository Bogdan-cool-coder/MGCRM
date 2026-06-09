"""Хеширование паролей и работа с JWT."""

from datetime import UTC, datetime, timedelta

from jose import JWTError, jwt
from passlib.context import CryptContext

from app.config import get_settings

settings = get_settings()

pwd_context = CryptContext(schemes=["bcrypt"], deprecated="auto")


# ============ Placeholder password hash для SSO-only пользователей (Эпик 16) ============
# Эпик 16: когда юзер заводится через SSO (auto-create в callback'е), у него
# нет пароля. Раньше мы ставили рандомный bcrypt-хэш — но это делало
# невозможной точную проверку «есть ли у юзера пароль» (для UI «Безопасность»
# и для блокировки последнего unlink). Теперь ставим строковую константу-маркер
# (она НЕ валидна для bcrypt — verify_password всегда False).
#
# Логика has_password:
#   has_password = (user.password_hash != PLACEHOLDER_PASSWORD_HASH)
#
# Существующие SSO-юзеры с рандомным bcrypt-хэшем (созданные до этой константы)
# будут has_password=True. Это устраивает по постановке («Существующих users
# считай has_password=true»). Постепенно при следующих unlink/смене пароля
# поле переедет в актуальное состояние.
PLACEHOLDER_PASSWORD_HASH: str = "$2b$12$ssoOnlyPlaceholderXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX"


def has_password(user) -> bool:
    """True если у юзера есть реальный пароль (не placeholder).

    Используется в /auth/me для отдачи `has_password` фронту и в /sso/unlink
    для решения «можно ли снять последнюю SSO-привязку».
    """
    return bool(user.password_hash) and user.password_hash != PLACEHOLDER_PASSWORD_HASH


def hash_password(password: str) -> str:
    return pwd_context.hash(password)


def verify_password(password: str, hashed: str) -> bool:
    return pwd_context.verify(password, hashed)


def create_access_token(user_id: int, role: str) -> str:
    expire = datetime.now(UTC) + timedelta(hours=settings.jwt_expire_hours)
    payload = {"sub": str(user_id), "role": role, "exp": expire}
    return jwt.encode(payload, settings.jwt_secret, algorithm=settings.jwt_algorithm)


def decode_token(token: str) -> dict | None:
    try:
        return jwt.decode(token, settings.jwt_secret, algorithms=[settings.jwt_algorithm])
    except JWTError:
        return None


# ============ OnlyOffice Document Server (общий с DS секрет, отдельный от auth) ============

def onlyoffice_sign(payload: dict) -> str:
    """Подписать payload секретом, общим с Document Server (HS256).
    Используется для подписи editor-config и проверки callback."""
    return jwt.encode(payload, settings.onlyoffice_jwt_secret, algorithm="HS256")


def onlyoffice_verify(token: str) -> dict | None:
    try:
        return jwt.decode(token, settings.onlyoffice_jwt_secret, algorithms=["HS256"])
    except JWTError:
        return None


def create_doc_download_token(code: str, ttl_minutes: int = 15) -> str:
    """Короткоживущий токен для server-to-server скачивания шаблона Document Server'ом
    (у DS нет cookie-сессии). Подписан тем же секретом OnlyOffice."""
    expire = datetime.now(UTC) + timedelta(minutes=ttl_minutes)
    return jwt.encode(
        {"code": code, "scope": "oo-download", "exp": expire},
        settings.onlyoffice_jwt_secret,
        algorithm="HS256",
    )


# ============ 2FA temp token (Эпик 16) ============
# После проверки пароля если user.totp_enabled — выдаём короткоживущий
# temp_2fa_token cookie (5 мин). UI редиректит на /auth/2fa где юзер
# вводит TOTP/backup-код, мы валидируем — и тогда уже выдаём полный
# access_token + чистим temp_2fa_token. Scope в payload — защита от
# подмены обычного access_token как temp (и наоборот).

# Cookie-имя temp-токена (отдельное от access_token, чтобы можно было
# держать оба или ни одного в transition).
TEMP_2FA_COOKIE_NAME = "temp_2fa_token"
# Scope в JWT payload — обозначаем что это именно «pending 2FA».
TEMP_2FA_SCOPE = "2fa-pending"


def create_temp_2fa_token(user_id: int, ttl_minutes: int | None = None) -> str:
    """Сгенерировать короткоживущий JWT для 2FA challenge между login и validate.

    Подписан jwt_secret'ом (тот же что access_token), но со scope='2fa-pending'
    — это явно отличает temp от полного. decode_token() на temp вернёт
    payload, но в обычных require_user он не пройдёт (scope не тот).
    """
    if ttl_minutes is None:
        ttl_minutes = settings.totp_temp_token_ttl_minutes
    expire = datetime.now(UTC) + timedelta(minutes=ttl_minutes)
    return jwt.encode(
        {"sub": str(user_id), "scope": TEMP_2FA_SCOPE, "exp": expire},
        settings.jwt_secret,
        algorithm=settings.jwt_algorithm,
    )


def decode_temp_2fa_token(token: str) -> int | None:
    """Декодировать temp_2fa_token и вернуть user_id, если payload валиден.

    Возвращает None если:
    - Токен expired или подделан (JWTError)
    - scope ≠ '2fa-pending' (это обычный access_token, а не temp)
    - sub отсутствует/нечисловой
    """
    try:
        payload = jwt.decode(
            token, settings.jwt_secret, algorithms=[settings.jwt_algorithm],
        )
    except JWTError:
        return None
    if payload.get("scope") != TEMP_2FA_SCOPE:
        return None
    sub = payload.get("sub")
    if not sub:
        return None
    try:
        return int(sub)
    except (TypeError, ValueError):
        return None


# ============ Temp TOTP setup secret cookie (Эпик 16) ============
# При POST /api/auth/2fa/setup мы возвращаем фронту QR/manual_code, но самый
# секрет не сохраняем в БД до verify-setup. Однако следующий POST
# /api/auth/2fa/verify-setup из соображений frontend-UX не хочет
# гонять plain secret через JS состояние — мы кладём secret в
# короткоживущий JWT cookie `temp_totp_secret_token` (10 минут), и
# verify-setup читает его оттуда.
#
# scope='2fa-setup' — отличает от прочих JWT (access, 2fa-pending). НЕ
# совместим с access_token (decode_token примет, но скоп проверим явно).

TEMP_TOTP_SECRET_COOKIE_NAME = "temp_totp_secret_token"
TEMP_TOTP_SECRET_SCOPE = "2fa-setup"
TEMP_TOTP_SECRET_TTL_MINUTES = 10


def create_temp_totp_secret_token(user_id: int, secret: str) -> str:
    """Создать короткоживущий JWT с plain base32 secret для setup-флоу.

    Cookie живёт 10 минут — достаточно, чтобы юзер открыл аутентификатор,
    отсканил QR и ввёл код в форму. По истечении — UI заставит setup заново.
    """
    expire = datetime.now(UTC) + timedelta(minutes=TEMP_TOTP_SECRET_TTL_MINUTES)
    return jwt.encode(
        {
            "sub": str(user_id),
            "scope": TEMP_TOTP_SECRET_SCOPE,
            "secret": secret,
            "exp": expire,
        },
        settings.jwt_secret,
        algorithm=settings.jwt_algorithm,
    )


def decode_temp_totp_secret_token(token: str) -> tuple[int, str] | None:
    """Декодировать temp_totp_secret_token и вернуть (user_id, secret).

    None если expired/подделан/scope невалидный/нет нужных полей.
    """
    try:
        payload = jwt.decode(
            token, settings.jwt_secret, algorithms=[settings.jwt_algorithm],
        )
    except JWTError:
        return None
    if payload.get("scope") != TEMP_TOTP_SECRET_SCOPE:
        return None
    sub = payload.get("sub")
    secret = payload.get("secret")
    if not sub or not secret:
        return None
    try:
        return int(sub), str(secret)
    except (TypeError, ValueError):
        return None
