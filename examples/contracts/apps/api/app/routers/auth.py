from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Request, Response, status
from pydantic import BaseModel, ConfigDict, EmailStr
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.db import get_session
from app.deps import CurrentUser
from app.models import User, UserRole
from app.schemas import LoginIn, LoginOut
from app.services.auth_rate_limit import check_login_rate_limit
from app.security import (
    TEMP_2FA_COOKIE_NAME,
    create_access_token,
    create_temp_2fa_token,
    has_password,
    verify_password,
)

router = APIRouter(prefix="/auth", tags=["auth"])
settings = get_settings()


class Login2FARequired(BaseModel):
    """Ответ на POST /api/auth/login если у юзера включён TOTP.

    Frontend получает requires_2fa=True → редиректит на /auth/2fa где
    отдельной формой просим TOTP/backup-код.
    """

    model_config = ConfigDict(from_attributes=True)
    requires_2fa: bool = True
    # user_id для UI (показать «как X»). Безопасно — temp_2fa_token cookie
    # уже выдан с этим sub, узнать кто пытается войти можно и без поля.
    user_id: int


def _issue_access_cookie(response: Response, user: User) -> None:
    """Выдать access_token cookie. Извлечено в helper для повторного
    использования из /auth/2fa/validate и /auth/sso/*/callback."""
    token = create_access_token(user.id, user.role.value)
    response.set_cookie(
        key="access_token",
        value=token,
        httponly=True,
        secure=settings.app_env != "development",
        samesite="lax",
        max_age=settings.jwt_expire_hours * 3600,
    )


def _issue_temp_2fa_cookie(response: Response, user_id: int) -> None:
    """Выдать temp_2fa_token cookie (5 мин TTL)."""
    token = create_temp_2fa_token(user_id)
    response.set_cookie(
        key=TEMP_2FA_COOKIE_NAME,
        value=token,
        httponly=True,
        secure=settings.app_env != "development",
        samesite="lax",
        max_age=settings.totp_temp_token_ttl_minutes * 60,
    )


@router.post("/login")
async def login(
    payload: LoginIn,
    request: Request,
    response: Response,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> LoginOut | Login2FARequired:
    """Логин по email+password.

    Если у юзера НЕ включён 2FA — выдаём access_token cookie и возвращаем
    LoginOut (как раньше, бэквард-совместимо).

    Если включён — выдаём temp_2fa_token cookie (5 мин) и возвращаем
    Login2FARequired с requires_2fa=True. UI должен редиректнуть на
    /auth/2fa где POST /api/auth/2fa/validate завершит login.
    """
    # P0 Security: брутфорс-защита (per-IP + per-account, 10/мин). fail-CLOSED
    # на in-process fallback при сбое Redis (см. check_login_rate_limit).
    client_ip = request.client.host if request.client else "0.0.0.0"
    rl_ok, rl_retry = await check_login_rate_limit(client_ip, payload.email.lower())
    if not rl_ok:
        raise HTTPException(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            detail="Слишком много попыток входа. Попробуйте через минуту.",
            headers={"Retry-After": str(rl_retry)},
        )

    user = (
        await session.execute(select(User).where(User.email == payload.email.lower()))
    ).scalar_one_or_none()
    if not user or not user.is_active or not verify_password(payload.password, user.password_hash):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Неверный email или пароль")

    # Epic 16 — Security: если включён 2FA, не выдаём access_token до validate.
    if user.totp_enabled and user.totp_secret_encrypted:
        _issue_temp_2fa_cookie(response, user.id)
        return Login2FARequired(requires_2fa=True, user_id=user.id)

    _issue_access_cookie(response, user)
    return LoginOut(
        id=user.id, email=user.email, full_name=user.full_name, role=user.role,
        avatar_path=user.avatar_path, telegram_user_id=user.telegram_user_id,
        crm_experience_level=user.crm_experience_level,
        onboarding_dismissed_at=user.onboarding_dismissed_at,
        # Эпик 16: статус 2FA + наличие пароля.
        totp_enabled=user.totp_enabled,
        totp_enabled_at=user.totp_enabled_at,
        has_password=has_password(user),
        # Эпик 21: profile 2.0 — фронт применит тему/локаль сразу из логин-ответа.
        theme_preference=user.theme_preference,
        locale=user.locale,
        job_title=user.job_title,
        signature_url=user.signature_url,
    )


@router.post("/logout")
async def logout(response: Response):
    response.delete_cookie("access_token")
    response.delete_cookie(TEMP_2FA_COOKIE_NAME)
    return {"ok": True}


@router.get("/me", response_model=LoginOut)
async def me(current_user: CurrentUser):
    return LoginOut(
        id=current_user.id,
        email=current_user.email,
        full_name=current_user.full_name,
        role=current_user.role,
        avatar_path=current_user.avatar_path,
        telegram_user_id=current_user.telegram_user_id,
        crm_experience_level=current_user.crm_experience_level,
        onboarding_dismissed_at=current_user.onboarding_dismissed_at,
        # Эпик 16: статус 2FA + наличие пароля. Фронт читает это для:
        # 1) показа/скрытия секции «Пароль» в /profile/security (has_password=false → SSO-only);
        # 2) кнопки «Включить/Выключить 2FA» (totp_enabled);
        # 3) даты «Включён с …» (totp_enabled_at).
        totp_enabled=current_user.totp_enabled,
        totp_enabled_at=current_user.totp_enabled_at,
        has_password=has_password(current_user),
        # Эпик 21: profile 2.0 — тема/локаль/должность/подпись для профиля.
        theme_preference=current_user.theme_preference,
        locale=current_user.locale,
        job_title=current_user.job_title,
        signature_url=current_user.signature_url,
    )
