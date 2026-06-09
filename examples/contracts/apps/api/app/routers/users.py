import secrets
import uuid
from datetime import UTC, datetime, timedelta
from pathlib import Path
from typing import Annotated

from fastapi import APIRouter, Depends, File, HTTPException, UploadFile, status
from fastapi.responses import FileResponse
from sqlalchemy import func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.config import get_settings
from app.db import get_session
from app.deps import AdminUser, CurrentUser
from app.models import TelegramLinkToken, User, UserRole
from app.schemas import UpdateMeIn, UserIn, UserOut, UserPickerOut
from app.security import hash_password, verify_password

router = APIRouter(prefix="/users", tags=["users"])
settings = get_settings()

TELEGRAM_BOT_USERNAME = "Contract_generator_MACRO_bot"
TELEGRAM_LINK_TTL_MIN = 10

ALLOWED_AVATAR_TYPES = {"image/jpeg", "image/png", "image/webp"}
MAX_AVATAR_SIZE = 2 * 1024 * 1024  # 2 MB

# Эпик 21 — UX Profile 2.0: подпись менеджера (PNG/JPG для вставки в шаблоны).
ALLOWED_SIGNATURE_TYPES = {"image/jpeg", "image/png"}
MAX_SIGNATURE_SIZE = 1 * 1024 * 1024  # 1 MB — подпись это маленькое PNG

# Whitelists для PATCH /me — защита от опечаток. Если фронт прислал что-то
# не из списка, возвращаем 400 (не молча игнорируем — чтобы баг был виден).
ALLOWED_THEME_PREFERENCES = {"system", "light", "dark"}
ALLOWED_LOCALES = {"ru", "en"}


def _avatar_dir() -> Path:
    p = settings.storage_dir / "avatars"
    p.mkdir(parents=True, exist_ok=True)
    return p


def _signature_dir() -> Path:
    """Эпик 21: директория для подписей. Создаётся lazily."""
    p = settings.storage_dir / "signatures"
    p.mkdir(parents=True, exist_ok=True)
    return p


@router.patch("/me", response_model=UserOut)
async def update_me(
    payload: UpdateMeIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Обновить свой профиль: имя, email, пароль + Эпик 21 (тема, локаль, должность).

    Смена пароля требует current_password.
    theme_preference / locale валидируются по whitelist (см. константы выше) —
    при невалидном значении 400 с понятным сообщением, чтобы баг был виден.
    """
    if payload.email and payload.email.lower() != current_user.email:
        existing = (
            await session.execute(select(User).where(User.email == payload.email.lower()))
        ).scalar_one_or_none()
        if existing and existing.id != current_user.id:
            raise HTTPException(400, "Email уже занят другим пользователем")
        current_user.email = payload.email.lower()

    if payload.full_name is not None and payload.full_name.strip():
        current_user.full_name = payload.full_name.strip()

    if payload.new_password:
        if not payload.current_password:
            raise HTTPException(400, "Для смены пароля укажите текущий пароль")
        if not verify_password(payload.current_password, current_user.password_hash):
            raise HTTPException(400, "Текущий пароль неверен")
        if len(payload.new_password) < 6:
            raise HTTPException(400, "Новый пароль слишком короткий (минимум 6 символов)")
        current_user.password_hash = hash_password(payload.new_password)

    # Эпик 21 — UX Profile 2.0.
    if payload.theme_preference is not None:
        if payload.theme_preference not in ALLOWED_THEME_PREFERENCES:
            raise HTTPException(
                400,
                f"theme_preference должно быть одним из "
                f"{sorted(ALLOWED_THEME_PREFERENCES)}",
            )
        current_user.theme_preference = payload.theme_preference

    if payload.locale is not None:
        if payload.locale not in ALLOWED_LOCALES:
            raise HTTPException(
                400, f"locale должно быть одним из {sorted(ALLOWED_LOCALES)}",
            )
        current_user.locale = payload.locale

    if payload.job_title is not None:
        # пустая строка → стираем (None). Иначе сохраняем (с trim).
        trimmed = payload.job_title.strip()
        current_user.job_title = trimmed or None

    await session.commit()
    await session.refresh(current_user)
    return current_user


@router.post("/me/avatar", response_model=UserOut)
async def upload_avatar(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    file: UploadFile = File(...),
):
    if file.content_type not in ALLOWED_AVATAR_TYPES:
        raise HTTPException(400, f"Допустимы только {', '.join(ALLOWED_AVATAR_TYPES)}")

    data = await file.read()
    if len(data) > MAX_AVATAR_SIZE:
        raise HTTPException(400, "Файл больше 2 МБ")

    # Сохраняем во временный + переименовываем для атомарности
    ext = {"image/jpeg": "jpg", "image/png": "png", "image/webp": "webp"}[file.content_type]
    avatar_dir = _avatar_dir()

    # Удалим старый файл если был
    if current_user.avatar_path:
        old = Path(current_user.avatar_path)
        if old.exists() and old.is_relative_to(avatar_dir):
            old.unlink(missing_ok=True)

    filename = f"user_{current_user.id}_{uuid.uuid4().hex[:8]}.{ext}"
    out_path = avatar_dir / filename
    out_path.write_bytes(data)

    current_user.avatar_path = str(out_path)
    await session.commit()
    await session.refresh(current_user)
    return current_user


@router.delete("/me/avatar", response_model=UserOut)
async def delete_avatar(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    if current_user.avatar_path:
        old = Path(current_user.avatar_path)
        old.unlink(missing_ok=True)
        current_user.avatar_path = None
        await session.commit()
        await session.refresh(current_user)
    return current_user


# ============ Эпик 21 — Подпись менеджера ============
# Подпись сохраняется в /uploads/signatures/{user_id}_{uuid}.{ext}, ссылка
# хранится в users.signature_url. Используется в шаблонах договоров для
# подстановки изображения подписи менеджера.


@router.post("/me/signature", response_model=UserOut)
async def upload_signature(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    file: UploadFile = File(...),
):
    """Загрузить подпись менеджера (PNG/JPG, ≤1 МБ)."""
    if file.content_type not in ALLOWED_SIGNATURE_TYPES:
        raise HTTPException(
            400, f"Допустимы только {', '.join(ALLOWED_SIGNATURE_TYPES)}",
        )

    data = await file.read()
    if len(data) > MAX_SIGNATURE_SIZE:
        raise HTTPException(400, "Файл больше 1 МБ")

    ext = {"image/jpeg": "jpg", "image/png": "png"}[file.content_type]
    sig_dir = _signature_dir()

    # Удалим старый файл подписи если был (и он в нашей директории).
    if current_user.signature_url:
        old = Path(current_user.signature_url)
        if old.exists() and old.is_relative_to(sig_dir):
            old.unlink(missing_ok=True)

    filename = f"user_{current_user.id}_{uuid.uuid4().hex[:8]}.{ext}"
    out_path = sig_dir / filename
    out_path.write_bytes(data)

    current_user.signature_url = str(out_path)
    await session.commit()
    await session.refresh(current_user)
    return current_user


@router.delete("/me/signature", response_model=UserOut)
async def delete_signature(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить подпись менеджера."""
    if current_user.signature_url:
        old = Path(current_user.signature_url)
        old.unlink(missing_ok=True)
        current_user.signature_url = None
        await session.commit()
        await session.refresh(current_user)
    return current_user


@router.get("/{user_id}/signature")
async def get_signature(
    user_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Отдать файл подписи (для preview и для рендера документов)."""
    user = (await session.execute(select(User).where(User.id == user_id))).scalar_one_or_none()
    if not user or not user.signature_url:
        raise HTTPException(404, "Подпись не найдена")
    path = Path(user.signature_url)
    if not path.exists():
        raise HTTPException(404, "Файл подписи отсутствует")
    return FileResponse(path)


@router.post("/me/telegram-link")
async def create_telegram_link(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создаёт одноразовый токен и возвращает deep-link на бота."""
    token = secrets.token_urlsafe(24)
    expires_at = datetime.now(UTC) + timedelta(minutes=TELEGRAM_LINK_TTL_MIN)
    session.add(TelegramLinkToken(user_id=current_user.id, token=token, expires_at=expires_at))
    await session.commit()
    return {
        "url": f"https://t.me/{TELEGRAM_BOT_USERNAME}?start=link_{token}",
        "expires_in_minutes": TELEGRAM_LINK_TTL_MIN,
    }


@router.delete("/me/telegram", response_model=UserOut)
async def unlink_telegram(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    current_user.telegram_user_id = None
    await session.commit()
    await session.refresh(current_user)
    return current_user


@router.get("/{user_id}/avatar")
async def get_avatar(
    user_id: int,
    _: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    user = (await session.execute(select(User).where(User.id == user_id))).scalar_one_or_none()
    if not user or not user.avatar_path:
        raise HTTPException(404, "Аватар не найден")
    path = Path(user.avatar_path)
    if not path.exists():
        raise HTTPException(404, "Файл аватара отсутствует")
    return FileResponse(path)


@router.get("/{user_id}/profile")
async def get_user_profile(
    user_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Расширенный профиль другого пользователя (для просмотра карточки руководителем).

    Доступ: сам / admin / director — любой; manager — только своих прямых
    подчинённых (User.manager_id == current_user.id).
    Единый scope с /api/me/dashboard (`_resolve_target_user`): раз менеджер уже
    видит доход подчинённого через KPI-дашборд, отказывать ему в более «лёгком»
    профиле было непоследовательно (доход чувствительнее профиля).
    Использует тот же MeProfileOut shape что и /api/me/profile, чтобы
    фронт `MePageHeader` мог переиспользовать тип.
    """
    # Импорт здесь, чтобы не плодить циклические импорты на модуль-уровне
    from app.routers.me import MeProfileOut, _build_profile, _resolve_target_user

    # Единая политика scope (сам / admin / director / прямой руководитель).
    # _resolve_target_user сам бросает 404 (нет юзера) и 403 (нет доступа).
    user = await _resolve_target_user(session, current_user, user_id)

    profile: MeProfileOut = await _build_profile(session, user)
    return profile


@router.get("", response_model=list[UserOut] | list[UserPickerOut])
async def list_users(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Список пользователей (нужен для назначения approver/owner/substitute).

    A2 WARNING (PII leak): рядовым ролям отдаём минимальный shape (UserPickerOut)
    без telegram_user_id / оргструктуры (manager_id, department_id) / signature_url,
    чтобы компрометированный аккаунт менеджера не выгружал корпоративный справочник.
    Полный UserOut — только admin/director.
    """
    result = await session.execute(select(User).order_by(User.full_name))
    users = result.scalars().all()
    if current_user.role in (UserRole.admin, UserRole.director):
        return [UserOut.model_validate(u) for u in users]
    return [UserPickerOut.model_validate(u) for u in users]


@router.post("", response_model=UserOut, status_code=status.HTTP_201_CREATED)
async def create_user(
    payload: UserIn,
    current_admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    existing = (
        await session.execute(select(User).where(User.email == payload.email.lower()))
    ).scalar_one_or_none()
    if existing:
        raise HTTPException(status_code=400, detail="Пользователь с таким email уже существует")

    user = User(
        email=payload.email.lower(),
        password_hash=hash_password(payload.password),
        full_name=payload.full_name,
        role=payload.role,
        telegram_user_id=payload.telegram_user_id,
    )
    session.add(user)
    await session.commit()
    await session.refresh(user)

    # Эпик 13: auto-assign онбординг-курсов по role. Не блокирует ответ — если
    # упадёт, юзер всё равно создан, дозабьём вручную через /admin/onboarding/.
    try:
        from app.services.onboarding.auto_assign import assign_default_courses
        await assign_default_courses(
            session, user, assigned_by_user_id=current_admin.id,
        )
        await session.commit()
    except Exception as e:  # noqa: BLE001
        # Откатываем частичную запись онбординга, чтобы не оставить сессию
        # в «грязном» состоянии (A2 WARNING). Сам user уже закоммичен выше.
        await session.rollback()
        import logging
        logging.getLogger(__name__).warning(
            "Failed to auto-assign onboarding courses to user %s: %s", user.id, e,
        )

    return user


@router.patch("/{user_id}", response_model=UserOut)
async def update_user(
    user_id: int,
    payload: UserIn,
    current_admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    user = (await session.execute(select(User).where(User.id == user_id))).scalar_one_or_none()
    if not user:
        raise HTTPException(status_code=404, detail="Не найден")

    # Защита от admin lock-out (A2): нельзя снять admin-роль с последнего активного
    # администратора и нельзя понизить собственную учётную запись.
    demoting_admin = user.role == UserRole.admin and payload.role != UserRole.admin
    if demoting_admin:
        if user.id == current_admin.id:
            raise HTTPException(
                status_code=400,
                detail="Нельзя понизить собственную учётную запись",
            )
        admin_count = (
            await session.execute(
                select(func.count())
                .select_from(User)
                .where(User.role == UserRole.admin, User.is_active.is_(True))
            )
        ).scalar() or 0
        if admin_count <= 1:
            raise HTTPException(
                status_code=400,
                detail="Нельзя снять роль с последнего администратора",
            )

    user.email = payload.email.lower()
    user.full_name = payload.full_name
    user.role = payload.role
    user.telegram_user_id = payload.telegram_user_id
    if payload.password:
        user.password_hash = hash_password(payload.password)

    await session.commit()
    await session.refresh(user)
    return user


@router.delete("/{user_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_user(
    user_id: int,
    current_admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    if user_id == current_admin.id:
        raise HTTPException(status_code=400, detail="Нельзя удалить себя")
    user = (await session.execute(select(User).where(User.id == user_id))).scalar_one_or_none()
    if not user:
        raise HTTPException(status_code=404, detail="Не найден")
    user.is_active = False  # soft delete
    await session.commit()
