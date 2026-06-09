"""Эпик 21.2 — Per-user notification preferences + quiet hours endpoints.

UI «Настройки → Уведомления» позволяет менеджеру:
- посмотреть / включить-выключить per-kind per-channel галочки;
- настроить тихий час для TG-канала (например 21:00..09:00);
- включить / выключить email-канал целиком (master switch).

Все эндпоинты под cookie auth — юзер видит ТОЛЬКО свои настройки.
Админ может смотреть/менять чужие через отдельный admin endpoint (TBD).

Endpoints:
- GET    /api/me/notifications/preferences   → матрица [{kind, channel, is_enabled}]
- PATCH  /api/me/notifications/preferences   → bulk upsert
- GET    /api/me/notifications/quiet-hours   → {start, end, enabled}
- PATCH  /api/me/notifications/quiet-hours   → апдейт quiet hours + email master
"""
from __future__ import annotations

from datetime import UTC, datetime, time
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser
from app.models import NotificationChannelPreference, User
from app.services.notification_dispatcher import NOTIFICATION_CHANNELS
from app.services.notifications import NOTIFICATION_KINDS

router = APIRouter(prefix="/me/notifications", tags=["me-notifications"])


# ============ Schemas ============


class PreferenceItem(BaseModel):
    """Одна ячейка матрицы kind × channel."""

    model_config = ConfigDict(from_attributes=True)
    kind: str
    channel: str
    is_enabled: bool


class PreferencesPatch(BaseModel):
    """Bulk upsert preferences. body — список ячеек, которые меняем.

    Неупомянутые ячейки НЕ трогаются — фронт может прислать только diff,
    не всю матрицу.
    """

    items: list[PreferenceItem] = Field(default_factory=list)


class QuietHoursOut(BaseModel):
    """Тихий час TG-канала + master switch email."""

    # HH:MM формат (например "21:00"). None = quiet hours отключены.
    start: str | None = None
    end: str | None = None
    enabled: bool = False
    # Master switch для email-канала (см. User.email_notifications_enabled).
    email_enabled: bool = True
    # Резерв под SMS (Эпик 21.3+).
    notification_phone: str | None = None


class QuietHoursPatch(BaseModel):
    """Апдейт quiet hours. Поля опциональные:
    - если start / end передан null → стираем quiet hours (выключаем).
    - если поля не переданы — не трогаем.
    """

    start: str | None = Field(default=None, description="HH:MM, например '21:00' или null для сброса")
    end: str | None = Field(default=None, description="HH:MM")
    # explicit отдельные флажки чтобы можно было сбросить через {start: null}
    clear: bool = Field(default=False, description="Если true — стираем quiet hours")
    email_enabled: bool | None = None
    notification_phone: str | None = None


# ============ Helpers ============


def _parse_hhmm(s: str | None) -> time | None:
    """Парсит 'HH:MM' → time. None / пустая строка → None.

    Бросает HTTPException 400 на невалидный формат.
    """
    if s is None or s == "":
        return None
    try:
        parts = s.split(":")
        if len(parts) != 2:
            raise ValueError(f"expected HH:MM, got {s!r}")
        h = int(parts[0])
        m = int(parts[1])
        if not (0 <= h < 24 and 0 <= m < 60):
            raise ValueError(f"out of range: {s!r}")
        return time(hour=h, minute=m)
    except (ValueError, TypeError) as e:
        raise HTTPException(
            status.HTTP_400_BAD_REQUEST,
            f"Невалидный формат времени (нужен HH:MM): {e}",
        ) from e


def _fmt_hhmm(t: time | None) -> str | None:
    if t is None:
        return None
    return f"{t.hour:02d}:{t.minute:02d}"


# ============ Endpoints ============


@router.get("/preferences", response_model=list[PreferenceItem])
async def list_preferences(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> list[PreferenceItem]:
    """Возвращает полную матрицу preferences для текущего юзера.

    Если в БД для какой-то пары (kind, channel) нет row'a — возвращаем
    is_enabled=True (default-on UX, consistent с dispatcher).

    Размер ответа: len(KINDS) × len(CHANNELS) = 8 × 3 = 24 элемента.
    """
    rows = (
        await session.execute(
            select(NotificationChannelPreference).where(
                NotificationChannelPreference.user_id == current_user.id,
            )
        )
    ).scalars().all()
    existing: dict[tuple[str, str], bool] = {
        (r.kind, r.channel): bool(r.is_enabled) for r in rows
    }
    out: list[PreferenceItem] = []
    for kind in NOTIFICATION_KINDS:
        for channel in NOTIFICATION_CHANNELS:
            out.append(PreferenceItem(
                kind=kind,
                channel=channel,
                is_enabled=existing.get((kind, channel), True),
            ))
    return out


@router.patch("/preferences", response_model=list[PreferenceItem])
async def update_preferences(
    body: PreferencesPatch,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> list[PreferenceItem]:
    """Bulk upsert. Валидирует kind/channel по whitelist. Создаёт row'ы для
    отсутствующих пар; апдейтит existing. Возвращает обновлённую матрицу.
    """
    if not body.items:
        return await list_preferences(current_user, session)

    # Валидация items
    for item in body.items:
        if item.kind not in NOTIFICATION_KINDS:
            raise HTTPException(
                status.HTTP_400_BAD_REQUEST,
                f"Неизвестный kind: {item.kind!r}",
            )
        if item.channel not in NOTIFICATION_CHANNELS:
            raise HTTPException(
                status.HTTP_400_BAD_REQUEST,
                f"Неизвестный channel: {item.channel!r}",
            )

    # Загружаем все existing записи юзера за один запрос
    rows = (
        await session.execute(
            select(NotificationChannelPreference).where(
                NotificationChannelPreference.user_id == current_user.id,
            )
        )
    ).scalars().all()
    by_key: dict[tuple[str, str], NotificationChannelPreference] = {
        (r.kind, r.channel): r for r in rows
    }

    now = datetime.now(UTC)
    for item in body.items:
        key = (item.kind, item.channel)
        existing = by_key.get(key)
        if existing is not None:
            existing.is_enabled = item.is_enabled
            existing.updated_at = now
        else:
            session.add(NotificationChannelPreference(
                user_id=current_user.id,
                kind=item.kind,
                channel=item.channel,
                is_enabled=item.is_enabled,
            ))

    await session.commit()
    return await list_preferences(current_user, session)


@router.get("/quiet-hours", response_model=QuietHoursOut)
async def get_quiet_hours(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> QuietHoursOut:
    """Возвращает текущие quiet hours юзера + email master switch +
    резервный телефон (для SMS будущего)."""
    # current_user — это уже свежий объект из БД (CurrentUser зависимость
    # подтягивает его на каждый запрос).
    return QuietHoursOut(
        start=_fmt_hhmm(current_user.tg_quiet_hours_start),
        end=_fmt_hhmm(current_user.tg_quiet_hours_end),
        enabled=bool(
            current_user.tg_quiet_hours_start is not None
            and current_user.tg_quiet_hours_end is not None
        ),
        email_enabled=bool(getattr(current_user, "email_notifications_enabled", True)),
        notification_phone=getattr(current_user, "notification_phone", None),
    )


@router.patch("/quiet-hours", response_model=QuietHoursOut)
async def update_quiet_hours(
    body: QuietHoursPatch,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> QuietHoursOut:
    """Апдейт quiet hours / email master / phone.

    Семантика:
    - body.clear=True → стираем quiet hours (оба поля → null).
    - body.start/end='' или null + clear=False → не меняем (партиальный апдейт).
    - body.start, body.end оба заданы → апдейтим окно.
    - body.email_enabled is not None → меняем master switch.
    - body.notification_phone is not None → меняем резерв (можно '' для очистки).
    """
    # Перезагружаем user для безопасного апдейта (current_user может быть
    # detached если deps закрыл сессию).
    user = (
        await session.execute(select(User).where(User.id == current_user.id))
    ).scalar_one_or_none()
    if user is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "User not found")

    if body.clear:
        user.tg_quiet_hours_start = None
        user.tg_quiet_hours_end = None
    elif body.start is not None or body.end is not None:
        # Партиальный апдейт допустим, но обычно фронт шлёт оба.
        new_start = _parse_hhmm(body.start) if body.start is not None else user.tg_quiet_hours_start
        new_end = _parse_hhmm(body.end) if body.end is not None else user.tg_quiet_hours_end
        # Если оба обнулены через clear-семантику — обнуляем оба.
        if new_start is None and new_end is None:
            user.tg_quiet_hours_start = None
            user.tg_quiet_hours_end = None
        elif new_start is None or new_end is None:
            raise HTTPException(
                status.HTTP_400_BAD_REQUEST,
                "Нужно указать оба времени (start и end) или сбросить через clear=true",
            )
        else:
            user.tg_quiet_hours_start = new_start
            user.tg_quiet_hours_end = new_end

    if body.email_enabled is not None:
        user.email_notifications_enabled = bool(body.email_enabled)

    if body.notification_phone is not None:
        # '' трактуем как очистку.
        user.notification_phone = body.notification_phone or None

    await session.commit()
    await session.refresh(user)

    return QuietHoursOut(
        start=_fmt_hhmm(user.tg_quiet_hours_start),
        end=_fmt_hhmm(user.tg_quiet_hours_end),
        enabled=bool(
            user.tg_quiet_hours_start is not None
            and user.tg_quiet_hours_end is not None
        ),
        email_enabled=bool(getattr(user, "email_notifications_enabled", True)),
        notification_phone=getattr(user, "notification_phone", None),
    )
