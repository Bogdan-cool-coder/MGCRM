"""Эпик 16 — Security: 2FA TOTP endpoints.

Flow:
1. POST /api/auth/2fa/setup  (auth required)
   → возвращает {qr_base64, manual_code, otpauth_uri} + ставит cookie
     `temp_totp_secret_token` (10 мин JWT с plain secret). Это позволяет
     verify-setup НЕ принимать secret в body (фронт держит только
     отображаемый manual_code/QR; secret сидит в HTTPOnly cookie до verify).

2. POST /api/auth/2fa/verify-setup  (auth required)
   body: {totp_code} (или legacy {secret, code}).
   - Если в теле есть `secret` (старый контракт) — используем его.
   - Иначе читаем secret из cookie `temp_totp_secret_token`.
   Сверяем код против secret'а. Если OK → encrypt(secret) → DB +
   totp_enabled=true + новые backup codes. Возвращаем {backup_codes:[...]}.
   Стираем temp_totp_secret_token cookie.

3. POST /api/auth/2fa/disable  (auth required)
   body: {totp_code? | backup_code?} (или legacy {code}).
   - totp_code → проверяем TOTP против users.totp_secret_encrypted.
   - backup_code → ищем хэш в массиве, удаляем использованный.
   Защита от disable «угнанной» сессии.

4. POST /api/auth/2fa/validate  (NO auth, но temp_2fa_token cookie required)
   body: {totp_code? | backup_code?} (или legacy {code, backup_code}).
   Финализация login-flow. Rate-limit 5 попыток / 15 мин per (IP, user_id):
   429 при превышении.

5. POST /api/auth/2fa/regenerate-backup-codes  (auth required + TOTP enabled)
   body: {totp_code} (или legacy {code}). Старые становятся невалидны,
   новые показываем один раз.

503 «2FA not configured» — если settings.totp_encryption_key пустой.

Backward-compat:
- Старые поля {secret, code} в verify-setup и {code} в disable/validate/regenerate
  продолжают работать. Новые имена — приоритет.
"""
from __future__ import annotations

from datetime import UTC, datetime
from typing import Annotated

from fastapi import APIRouter, Cookie, Depends, HTTPException, Request, Response, status
from pydantic import BaseModel, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.db import get_session
from app.deps import CurrentUser
from app.models import User
from app.routers.auth import _issue_access_cookie
from app.security import (
    TEMP_2FA_COOKIE_NAME,
    TEMP_TOTP_SECRET_COOKIE_NAME,
    TEMP_TOTP_SECRET_TTL_MINUTES,
    create_temp_totp_secret_token,
    decode_temp_2fa_token,
    decode_temp_totp_secret_token,
)
from app.services.auth_rate_limit import check_2fa_validate_rate_limit
from app.services.totp import (
    DEFAULT_BACKUP_CODE_COUNT,
    build_otpauth_uri,
    decrypt_secret,
    encrypt_secret,
    find_matching_backup_code_index,
    generate_backup_codes,
    generate_secret,
    get_qr_data_url,
    hash_backup_code,
    verify_totp,
)

router = APIRouter(prefix="/auth/2fa", tags=["auth-2fa"])


# ============ Pydantic schemas ============


class SetupOut(BaseModel):
    """Возвращается на POST /api/auth/2fa/setup.

    qr_base64 — base64 PNG (БЕЗ префикса `data:image/png;base64,`),
        фронт сам собирает <img src={`data:image/png;base64,${qr_base64}`}/>.
    manual_code — plain base32 secret (для ручного ввода в аутентификатор).
    otpauth_uri — полный `otpauth://totp/...?secret=...&issuer=MACRO%20CRM` URI
        (для копи-пасты или альтернативного QR-генератора).
    """

    qr_base64: str
    manual_code: str
    otpauth_uri: str


class VerifySetupIn(BaseModel):
    """Body для POST /api/auth/2fa/verify-setup.

    Поддерживает оба контракта:
    - Новый: {totp_code} — secret берётся из temp_totp_secret_token cookie.
    - Legacy: {secret, code} — secret и код в одном body.

    Поле `secret` опционально (для backward compat). Если передано — используем
    его; иначе ждём cookie. Если ни того ни другого — 400.
    """

    # Новый: 6-значный код. Опционален в схеме, но один из {totp_code, code}
    # обязателен на уровне логики endpoint'а.
    totp_code: str | None = Field(default=None, min_length=6, max_length=6)
    # Legacy: 6-значный код (старое имя). Принимаем для backward compat.
    code: str | None = Field(default=None, min_length=6, max_length=6)
    # Legacy: plain base32 secret. Если задан — приоритет над cookie.
    secret: str | None = Field(default=None, min_length=16, max_length=64)


class BackupCodesOut(BaseModel):
    """Plaintext backup codes — показываются ОДИН РАЗ при verify-setup
    или regenerate. Дальше API возвращает только метаданные."""

    backup_codes: list[str]


class DisableIn(BaseModel):
    """Body для POST /api/auth/2fa/disable.

    Принимает один из:
    - totp_code (6 цифр TOTP),
    - backup_code (8 hex backup),
    - legacy code (любой из двух — определяется по формату).

    Если передано несколько — приоритет totp_code → backup_code → code.
    """

    totp_code: str | None = Field(default=None, min_length=6, max_length=6)
    backup_code: str | None = Field(default=None, min_length=6, max_length=16)
    # Legacy: единое поле, формат определяется длиной/символами.
    code: str | None = Field(default=None, min_length=6, max_length=16)


class ValidateIn(BaseModel):
    """Body для POST /api/auth/2fa/validate.

    Один из {totp_code, backup_code} обязателен. legacy `code` — alias
    для totp_code; legacy `backup_code` сохраняется.
    """

    totp_code: str | None = Field(default=None, min_length=6, max_length=6)
    backup_code: str | None = Field(default=None, min_length=6, max_length=16)
    # Legacy: 6-значный TOTP — старое имя. Принимаем как алиас totp_code.
    code: str | None = Field(default=None, min_length=6, max_length=6)


class RegenerateIn(BaseModel):
    """Body для POST /api/auth/2fa/regenerate-backup-codes.

    Новый контракт: {totp_code}. Legacy: {code} — оставляем для совместимости.
    """

    totp_code: str | None = Field(default=None, min_length=6, max_length=16)
    code: str | None = Field(default=None, min_length=6, max_length=16)


class StatusOut(BaseModel):
    """Метаданные 2FA для UI «Безопасность аккаунта»."""

    enabled: bool
    enabled_at: datetime | None
    backup_codes_remaining: int


# ============ Helpers ============


def _require_totp_configured() -> None:
    """503 если TOTP_ENCRYPTION_KEY не задан."""
    if not get_settings().totp_encryption_key:
        raise HTTPException(
            status.HTTP_503_SERVICE_UNAVAILABLE,
            "2FA не настроен на сервере (TOTP_ENCRYPTION_KEY)",
        )


def _set_temp_totp_secret_cookie(response: Response, token: str) -> None:
    """Поставить short-lived (10 мин) cookie с plain secret для setup-флоу.

    HTTPOnly, samesite=lax. secure только в проде (dev — без HTTPS).
    """
    settings = get_settings()
    response.set_cookie(
        key=TEMP_TOTP_SECRET_COOKIE_NAME,
        value=token,
        httponly=True,
        secure=settings.app_env != "development",
        samesite="lax",
        max_age=TEMP_TOTP_SECRET_TTL_MINUTES * 60,
        path="/api/auth/2fa",
    )


def _pick_totp_code(*candidates: str | None) -> str | None:
    """Вернуть первый непустой 6-значный numeric кандидат (TOTP)."""
    for c in candidates:
        if c is None:
            continue
        cleaned = c.strip()
        if len(cleaned) == 6 and cleaned.isdigit():
            return cleaned
    return None


def _pick_backup_code(*candidates: str | None) -> str | None:
    """Вернуть первый непустой backup-кандидат (8 hex, без условия на digit)."""
    for c in candidates:
        if c is None:
            continue
        cleaned = c.strip()
        if not cleaned:
            continue
        # Backup-коды — 8 hex символов (см. services/totp.py:BACKUP_CODE_BYTES=4).
        # Но принимаем шире (6..16) на случай юзерских опечаток с пробелами/etc.
        if 6 <= len(cleaned) <= 16 and not (len(cleaned) == 6 and cleaned.isdigit()):
            # Исключаем 6 цифр — это TOTP, не backup.
            return cleaned
    return None


# ============ Endpoints ============


@router.get("/status", response_model=StatusOut)
async def get_2fa_status(current_user: CurrentUser) -> StatusOut:
    """Текущее состояние 2FA для UI «Безопасность»."""
    remaining = (
        len(current_user.totp_backup_codes_hashed)
        if current_user.totp_backup_codes_hashed
        else 0
    )
    return StatusOut(
        enabled=current_user.totp_enabled,
        enabled_at=current_user.totp_enabled_at,
        backup_codes_remaining=remaining,
    )


@router.post("/setup", response_model=SetupOut)
async def setup_2fa(
    current_user: CurrentUser, response: Response,
) -> SetupOut:
    """Шаг 1: сгенерировать новый secret + QR.

    НЕ сохраняет в БД — secret уходит во временный cookie (10 мин,
    HTTPOnly). Фронт показывает qr_base64 / manual_code / otpauth_uri,
    юзер вводит код в форму /verify-setup; verify-setup читает secret
    из cookie и сохраняет в БД с encryption.
    """
    _require_totp_configured()
    if current_user.totp_enabled:
        # Защита от случайного «лёгкого» reset через повторный setup без
        # disable. UI должен сначала позвать /disable.
        raise HTTPException(409, "2FA уже включён — сначала выключите")
    secret = generate_secret()
    # Готовим возвращаемые поля.
    qr_data_url = get_qr_data_url(secret, current_user.email)
    # qr_data_url имеет формат "data:image/png;base64,XXXX". Фронт ожидает
    # qr_base64 БЕЗ префикса.
    qr_base64 = qr_data_url.removeprefix("data:image/png;base64,")
    otpauth_uri = build_otpauth_uri(secret, current_user.email)

    # Ставим temp cookie с самим secret'ом — verify-setup будет его читать.
    token = create_temp_totp_secret_token(current_user.id, secret)
    _set_temp_totp_secret_cookie(response, token)

    return SetupOut(
        qr_base64=qr_base64,
        manual_code=secret,
        otpauth_uri=otpauth_uri,
    )


@router.post("/verify-setup", response_model=BackupCodesOut)
async def verify_setup_2fa(
    payload: VerifySetupIn,
    current_user: CurrentUser,
    response: Response,
    session: Annotated[AsyncSession, Depends(get_session)],
    temp_totp_secret_token: Annotated[str | None, Cookie()] = None,
) -> BackupCodesOut:
    """Шаг 2: проверить код + сохранить secret в БД + сгенерировать backup codes.

    Принимаем secret либо из body.secret (legacy), либо из temp_totp_secret_token
    cookie. Код — из totp_code (новый) ИЛИ code (legacy).

    Если код не сходится → 400, secret НЕ сохраняется (юзер пробует setup заново).
    Если 2FA уже включён → 409 (сначала disable).
    """
    _require_totp_configured()
    if current_user.totp_enabled:
        raise HTTPException(409, "2FA уже включён — сначала выключите")

    # Выбираем secret: priority body.secret > cookie.
    secret: str | None = None
    if payload.secret:
        secret = payload.secret
    elif temp_totp_secret_token:
        decoded = decode_temp_totp_secret_token(temp_totp_secret_token)
        if decoded is not None:
            uid, sec = decoded
            if uid != current_user.id:
                # Cookie выписана другому юзеру — отвергаем (защита от
                # cookie hijack на shared машине).
                raise HTTPException(
                    400,
                    "temp_totp_secret_token cookie не соответствует текущему "
                    "пользователю — начните setup заново",
                )
            secret = sec
    if not secret:
        raise HTTPException(
            400,
            "Нет secret: передайте {totp_code} после /setup (cookie temp_totp_secret_token"
            " истекла), либо legacy {secret, code}",
        )

    code = _pick_totp_code(payload.totp_code, payload.code)
    if not code:
        raise HTTPException(400, "Укажите totp_code (6 цифр)")

    if not verify_totp(secret, code):
        raise HTTPException(400, "Неверный код TOTP. Попробуйте ещё раз")

    # Сохраняем encrypted secret + новый набор backup кодов
    enc = encrypt_secret(secret)
    backup_codes = generate_backup_codes(DEFAULT_BACKUP_CODE_COUNT)

    current_user.totp_secret_encrypted = enc
    current_user.totp_enabled = True
    current_user.totp_enabled_at = datetime.now(UTC)
    current_user.totp_backup_codes_hashed = [
        hash_backup_code(c) for c in backup_codes
    ]
    await session.commit()

    # Стираем cookie с plain secret — больше не нужна.
    response.delete_cookie(TEMP_TOTP_SECRET_COOKIE_NAME, path="/api/auth/2fa")

    return BackupCodesOut(backup_codes=backup_codes)


@router.post("/disable")
async def disable_2fa(
    payload: DisableIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> dict[str, bool]:
    """Выключить 2FA. Требует TOTP-код ИЛИ backup-код для подтверждения.

    Защита от того, что злоумышленник с открытой сессией просто выключит
    2FA. После disable стираем secret + backup_codes — следующий setup
    стартует с чистого листа.
    """
    _require_totp_configured()
    if not current_user.totp_enabled or not current_user.totp_secret_encrypted:
        raise HTTPException(409, "2FA не включён")

    totp = _pick_totp_code(payload.totp_code, payload.code)
    backup = _pick_backup_code(payload.backup_code, payload.code)
    if not totp and not backup:
        raise HTTPException(400, "Укажите totp_code или backup_code")

    secret = decrypt_secret(current_user.totp_secret_encrypted)
    ok = False
    if totp and verify_totp(secret, totp):
        ok = True
    elif backup:
        idx = find_matching_backup_code_index(
            backup, current_user.totp_backup_codes_hashed,
        )
        ok = idx is not None

    if not ok:
        raise HTTPException(400, "Неверный код TOTP или backup-код")

    current_user.totp_secret_encrypted = None
    current_user.totp_enabled = False
    current_user.totp_enabled_at = None
    current_user.totp_backup_codes_hashed = None
    await session.commit()
    return {"ok": True}


@router.post("/validate")
async def validate_2fa(
    payload: ValidateIn,
    request: Request,
    response: Response,
    session: Annotated[AsyncSession, Depends(get_session)],
    temp_2fa_token: Annotated[str | None, Cookie()] = None,
) -> dict[str, bool]:
    """Финализация login-flow: проверить TOTP/backup и выдать access_token.

    БЕЗ обычной auth, но требует temp_2fa_token cookie (5-мин JWT,
    выданный POST /api/auth/login если у юзера totp_enabled).

    Rate limit: 5 попыток / 15 минут per (IP, user_id) — защита от
    brute-force TOTP-кода. 429 при превышении.

    Если backup_code передан — стирает использованный код из массива
    (одноразовость). access_token cookie ставится, temp_2fa_token
    удаляется.
    """
    _require_totp_configured()
    if not temp_2fa_token:
        raise HTTPException(
            401, "Нет temp_2fa_token — начните логин заново",
        )
    user_id = decode_temp_2fa_token(temp_2fa_token)
    if user_id is None:
        raise HTTPException(
            401, "temp_2fa_token истёк или недействителен — повторите вход",
        )

    # Rate limit per (IP, user_id). 5 попыток / 15 мин. Noop без Redis.
    ip = (request.client.host if request.client else "0.0.0.0")
    allowed, retry_after = await check_2fa_validate_rate_limit(ip, user_id)
    if not allowed:
        raise HTTPException(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            detail=(
                f"Слишком много попыток ввода 2FA. Попробуйте через "
                f"{retry_after} сек."
            ),
            headers={"Retry-After": str(retry_after)},
        )

    user = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    if not user or not user.is_active:
        raise HTTPException(401, "Пользователь не найден или неактивен")
    if not user.totp_enabled or not user.totp_secret_encrypted:
        # Юзер выключил 2FA между login и validate (race). Считаем флоу
        # сломанным — пусть логинится заново.
        raise HTTPException(409, "2FA выключен — повторите вход")

    totp = _pick_totp_code(payload.totp_code, payload.code)
    backup = _pick_backup_code(payload.backup_code)
    if not totp and not backup:
        raise HTTPException(400, "Укажите totp_code или backup_code")

    secret = decrypt_secret(user.totp_secret_encrypted)

    # Сначала пробуем TOTP, потом backup. Это исключает «коллизию»
    # 6-значного TOTP с какой-то частью backup-кода.
    if totp and verify_totp(secret, totp):
        pass  # OK
    elif backup:
        idx = find_matching_backup_code_index(
            backup, user.totp_backup_codes_hashed,
        )
        if idx is None:
            # invalid backup → 401 (для UI это «неверный код»; rate-limit
            # уже учёл попытку через INCR).
            raise HTTPException(401, "Неверный backup-код")
        # Одноразовость: стереть использованный код.
        if user.totp_backup_codes_hashed:
            user.totp_backup_codes_hashed = [
                h for i, h in enumerate(user.totp_backup_codes_hashed) if i != idx
            ]
    else:
        raise HTTPException(401, "Неверный код TOTP")

    await session.commit()

    # Выдаём полный access_token + чистим temp.
    _issue_access_cookie(response, user)
    response.delete_cookie(TEMP_2FA_COOKIE_NAME)
    return {"ok": True}


@router.post("/regenerate-backup-codes", response_model=BackupCodesOut)
async def regenerate_backup_codes(
    payload: RegenerateIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> BackupCodesOut:
    """Перевыпустить набор backup-кодов. Старые становятся недействительны.

    Требует TOTP-код (или один из ещё валидных backup) — защита от
    «угнал сессию → перевыпустил коды → имеешь 8 новых паролей».
    """
    _require_totp_configured()
    if not current_user.totp_enabled or not current_user.totp_secret_encrypted:
        raise HTTPException(409, "2FA не включён")

    totp = _pick_totp_code(payload.totp_code, payload.code)
    backup = _pick_backup_code(payload.totp_code, payload.code)
    if not totp and not backup:
        raise HTTPException(400, "Укажите totp_code")

    secret = decrypt_secret(current_user.totp_secret_encrypted)
    ok = False
    if totp and verify_totp(secret, totp):
        ok = True
    elif backup:
        idx = find_matching_backup_code_index(
            backup, current_user.totp_backup_codes_hashed,
        )
        ok = idx is not None
    if not ok:
        raise HTTPException(400, "Неверный код TOTP или backup-код")

    new_codes = generate_backup_codes(DEFAULT_BACKUP_CODE_COUNT)
    current_user.totp_backup_codes_hashed = [
        hash_backup_code(c) for c in new_codes
    ]
    await session.commit()
    return BackupCodesOut(backup_codes=new_codes)
