"""Эпик 16 — Security: 2FA TOTP сервис (RFC 6238).

Что здесь:
- generate_secret() — новый base32 seed для пользователя (через pyotp)
- get_qr_data_url(secret, email) — otpauth URI + base64 PNG в data: URL
  (фронт показывает <img src="data:image/png;base64,..."/>)
- verify_totp(secret, code) — проверка с tolerance ±30 сек (valid_window=1)
- generate_backup_codes(count=8) — 8 одноразовых hex-кодов, plaintext
- hash_backup_code / verify_backup_code — bcrypt хэш одного кода
- encrypt_secret / decrypt_secret — Fernet wrap для хранения в БД

Почему bcrypt для backup кодов: тот же подход, что для password_hash,
не вводим вторую криптографию в стек. Fernet для самого secret'а — он
длиннее (base32, ~32 chars), и его нам нужно потом разрасшифровать для
verify_totp; bcrypt one-way не подходит.

Issuer в otpauth URI — "MACRO CRM" (то, что увидит юзер в Google
Authenticator / Authy / 1Password как «название сервиса»).

Все функции pure (кроме encrypt_secret/decrypt_secret — читают
settings.totp_encryption_key через get_settings()). Тестируются в
tests/test_totp.py без сетевых запросов.
"""
from __future__ import annotations

import base64
import io
import secrets
from typing import Final

import pyotp
import qrcode
from cryptography.fernet import Fernet, InvalidToken
from passlib.context import CryptContext

from app.config import get_settings

# Issuer name в otpauth URI — то, что юзер увидит в аутентификаторе.
TOTP_ISSUER: Final[str] = "MACRO CRM"

# Tolerance ±N step (один step = 30 сек). valid_window=1 = ±1 step =
# принимаем код за предыдущие/следующие 30 секунд. Защищает от
# рассинхрона часов до 30 сек.
TOTP_VALID_WINDOW: Final[int] = 1

# Длина бэкап-кода в байтах. token_hex(4) → 8 hex-символов (32 бита
# энтропии). Достаточно для одноразового кода; brute-force через UI
# с rate-limit нереалистичен.
BACKUP_CODE_BYTES: Final[int] = 4

# Сколько бэкап-кодов выдаём при verify-setup и при regenerate.
DEFAULT_BACKUP_CODE_COUNT: Final[int] = 8

# bcrypt context для backup-кодов. Тот же scheme, что для паролей —
# не вводим вторую криптографию.
_bcrypt = CryptContext(schemes=["bcrypt"], deprecated="auto")


def generate_secret() -> str:
    """Сгенерировать новый base32 seed (32 chars, 160 бит энтропии).

    Совместим со всеми RFC 6238 аутентификаторами (Google Authenticator,
    Authy, 1Password, Yandex Key, Microsoft Authenticator).
    """
    return pyotp.random_base32()


def build_otpauth_uri(secret: str, user_email: str) -> str:
    """Собрать otpauth://totp/... URI для QR.

    Формат RFC: otpauth://totp/<issuer>:<account>?secret=<base32>&issuer=<issuer>.
    Account — email пользователя (то, что видно в аутентификаторе после
    issuer'а).
    """
    return pyotp.TOTP(secret).provisioning_uri(
        name=user_email, issuer_name=TOTP_ISSUER,
    )


def get_qr_data_url(secret: str, user_email: str) -> str:
    """Сгенерировать data:image/png;base64,... для рендера QR-кода в UI.

    Фронт показывает <img src={data_url}/>. PNG генерим через qrcode
    в память — без файла на диске.
    """
    uri = build_otpauth_uri(secret, user_email)
    img = qrcode.make(uri)  # type: ignore[arg-type]
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    b64 = base64.b64encode(buf.getvalue()).decode("ascii")
    return f"data:image/png;base64,{b64}"


def verify_totp(secret: str, code: str) -> bool:
    """Проверить 6-значный TOTP код против secret с tolerance ±30 сек.

    code должен быть 6 цифр; если строка короче/длиннее — False (не
    падаем). secret должен быть base32; на невалидный seed pyotp может
    бросить — ловим в False.
    """
    if not secret or not code:
        return False
    cleaned = code.strip().replace(" ", "")
    # Принимаем только 6-значные числовые коды — никаких токенов длиннее,
    # никаких алфавитных строк. Защита от опечаток backup-кодов в это поле.
    if len(cleaned) != 6 or not cleaned.isdigit():
        return False
    try:
        return pyotp.TOTP(secret).verify(cleaned, valid_window=TOTP_VALID_WINDOW)
    except Exception:  # noqa: BLE001
        # Невалидный base32 в secret'е (теоретически) → не верифицируем.
        return False


def generate_backup_codes(count: int = DEFAULT_BACKUP_CODE_COUNT) -> list[str]:
    """Сгенерировать N одноразовых backup-кодов (plain hex 8 chars).

    Возвращает plaintext — храним только хэши. Юзеру показываем один раз
    при verify-setup или regenerate-backup-codes; дальше API возвращает
    только метаданные «8 кодов осталось».
    """
    return [secrets.token_hex(BACKUP_CODE_BYTES) for _ in range(count)]


def hash_backup_code(plain: str) -> str:
    """bcrypt-хэш одного backup-кода для хранения в users.totp_backup_codes_hashed."""
    return _bcrypt.hash(plain)


def verify_backup_code(plain: str, hashed: str) -> bool:
    """Сравнить plain backup-код с хэшем (bcrypt.checkpw)."""
    if not plain or not hashed:
        return False
    try:
        return _bcrypt.verify(plain, hashed)
    except Exception:  # noqa: BLE001
        return False


def find_matching_backup_code_index(
    plain: str, hashed_codes: list[str] | None,
) -> int | None:
    """Найти индекс хэша, совпадающего с plain backup-кодом.

    Возвращает индекс (для последующего удаления из массива) или None.
    Используется в /api/auth/2fa/validate когда юзер вводит backup-код
    вместо TOTP кода.
    """
    if not plain or not hashed_codes:
        return None
    for idx, h in enumerate(hashed_codes):
        if verify_backup_code(plain, h):
            return idx
    return None


def _get_fernet() -> Fernet:
    """Получить Fernet instance из settings.totp_encryption_key.

    Бросает ValueError если ключ не задан — вызывающая сторона должна
    проверить settings.totp_encryption_key и вернуть 503 «2FA not
    configured» прежде чем дойти до encrypt/decrypt.
    """
    key = get_settings().totp_encryption_key
    if not key:
        raise ValueError(
            "TOTP_ENCRYPTION_KEY не задан в .env — 2FA disabled",
        )
    # Fernet принимает bytes или str; ключ — Fernet.generate_key() output
    # = base64-URL-safe 44 chars.
    return Fernet(key.encode("ascii") if isinstance(key, str) else key)


def encrypt_secret(plain: str) -> str:
    """Зашифровать base32 seed Fernet'ом для хранения в users.totp_secret_encrypted.

    Result — base64-URL-safe строка (Fernet token, ~150 chars).
    """
    token = _get_fernet().encrypt(plain.encode("ascii"))
    return token.decode("ascii")


def decrypt_secret(enc: str) -> str:
    """Расшифровать base32 seed из users.totp_secret_encrypted.

    Бросает ValueError если token подделан (InvalidToken) — это
    индикатор compromise. Вызывающая сторона должна логировать инцидент.
    """
    try:
        plain = _get_fernet().decrypt(enc.encode("ascii"))
    except InvalidToken as e:
        raise ValueError("Invalid TOTP secret ciphertext") from e
    return plain.decode("ascii")
