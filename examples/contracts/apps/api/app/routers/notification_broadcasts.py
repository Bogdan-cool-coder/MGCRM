"""Эпик 21.2 — Admin broadcast endpoints.

POST /api/admin/notifications/broadcast — создать рассылку:
  body: {
    title, body, link,
    recipient_filter: {role|department_id|user_ids},
    channels: ["in_app", "tg", "email"],  # default ["in_app"]
    kind: "system",  # default
  }
  Сохраняет NotificationBroadcast row (status='pending') + запускает
  background task для dispatch'а. Возвращает broadcast_id сразу
  (synchronous fan-out может быть медленным на 100+ получателях).

GET /api/admin/notifications/broadcasts/{id} — детали + счётчики.
GET /api/admin/notifications/broadcasts — список с фильтрами.

Background task — отдельная asyncio.Task через `asyncio.create_task`.
Состояния: pending → running → completed | failed. На каждый
recipient — `safe_dispatch(...)`; ошибки увеличивают failed_count,
успехи — delivered_count.
"""
from __future__ import annotations

import asyncio
import logging
from datetime import UTC, datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, status
from pydantic import BaseModel, ConfigDict, Field, model_validator
from sqlalchemy import desc
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import SessionLocal, get_session
from app.deps import AdminUser
from app.models import NotificationBroadcast
from app.services.auth_rate_limit import check_broadcast_rate_limit
from app.services.notification_dispatcher import (
    NOTIFICATION_CHANNELS,
    BroadcastFilterError,
    normalize_recipient_filter,
    resolve_broadcast_recipients,
    safe_dispatch,
    sanitize_broadcast_link,
)
from app.services.notifications import NOTIFICATION_KINDS

logger = logging.getLogger(__name__)

router = APIRouter(
    prefix="/admin/notifications",
    tags=["admin-notifications-broadcasts"],
)


# ============ Schemas ============


class BroadcastOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    initiated_by_user_id: int | None
    kind: str
    title: str | None = None
    body: str | None = None
    link: str | None = None
    recipient_filter: dict[str, Any] | None = None
    channels: list[str] | None = None
    recipients_count: int | None = None
    delivered_count: int
    failed_count: int
    status: str
    created_at: datetime
    completed_at: datetime | None = None


class BroadcastIn(BaseModel):
    # populate_by_name=True: принимаем И `recipient_filter` (каноничное имя),
    # И `recipients_filter` (исторический фронтовый plural-ключ) — раньше plural
    # молча игнорировался и рассылка уходила всем (C7 privacy bug).
    model_config = ConfigDict(populate_by_name=True)

    title: str = Field(..., max_length=256)
    body: str | None = None
    link: str | None = None
    # {type: "all"} = всем (явно) | {role} | {department_id} | {user_ids}.
    # Принимаем оба ключа: recipient_filter и алиас recipients_filter (plural).
    recipient_filter: dict[str, Any] | None = Field(
        default=None, alias="recipients_filter",
    )
    # ["in_app"] (default) | ["in_app", "tg", "email"]. Толерантны к легаси
    # объекту {in_app: true, tg: false, email: true} — коэрсим в list.
    channels: list[str] | None = None
    # По дефолту 'system' — но можно подменить на любой kind из whitelist.
    kind: str = "system"
    # Планирование рассылки пока НЕ поддерживается. Если фронт прислал
    # scheduled_at — явно отклоняем (см. validator), чтобы UI не «фейкал»
    # отложенную отправку. Реализация — отдельной задачей.
    scheduled_at: Any | None = None

    @model_validator(mode="before")
    @classmethod
    def _coerce_inputs(cls, data: Any) -> Any:
        """Pre-валидация: коэрсим channels-объект в list; принимаем
        recipient_filter и под обоими ключами (alias делает Pydantic)."""
        if not isinstance(data, dict):
            return data
        out = dict(data)
        ch = out.get("channels")
        # Легаси-объект {in_app: true, tg: false, email: true} → ["in_app", "email"]
        if isinstance(ch, dict):
            out["channels"] = [k for k, v in ch.items() if v]
        return out


# ============ Background dispatch task ============


async def _dispatch_broadcast_task(broadcast_id: int) -> None:
    """Фоновая задача рассылки. Не использует session из caller'а —
    создаёт свою (caller-сессия может закрыться раньше).

    Логика:
    1. Загружает broadcast + резолвит получателей.
    2. Status → 'running'.
    3. Для каждого user_id × channel вызывает safe_dispatch.
    4. Апдейтит delivered_count / failed_count.
    5. Status → 'completed' | 'failed' (если 100% получателей failed).
    """
    async with SessionLocal() as session:
        broadcast = (
            await session.execute(
                select(NotificationBroadcast).where(
                    NotificationBroadcast.id == broadcast_id,
                )
            )
        ).scalar_one_or_none()
        if broadcast is None:
            logger.warning(
                "dispatch_broadcast_task: broadcast %s not found", broadcast_id,
            )
            return

        try:
            recipients = await resolve_broadcast_recipients(
                session, broadcast.recipient_filter,
            )
            broadcast.recipients_count = len(recipients.user_ids)
            broadcast.status = "running"
            await session.commit()
        except Exception as e:  # noqa: BLE001
            import sentry_sdk
            sentry_sdk.capture_exception(e)
            logger.warning(
                "dispatch_broadcast_task: resolve recipients failed: %s", e,
            )
            broadcast.status = "failed"
            broadcast.completed_at = datetime.now(UTC)
            await session.commit()
            return

        channels = broadcast.channels or ["in_app"]
        # Note: dispatch() сейчас всегда делает fan-out на ВСЕ каналы по
        # preferences пользователя. channels-фильтр на уровне broadcast —
        # это «явное намерение какие каналы максимум использовать», но
        # фактическая отправка пройдёт через preferences. Если в будущем
        # понадобится «forced channels even with disabled preferences» —
        # передадим override-флаг в dispatch.
        payload = {
            "title": broadcast.title or "",
            "body": broadcast.body or "",
            "link": broadcast.link,
        }

        delivered = 0
        failed = 0
        for user_id in recipients.user_ids:
            try:
                res = await safe_dispatch(
                    session,
                    user_id=user_id,
                    kind=broadcast.kind,
                    payload=payload,
                    title=broadcast.title,
                    body=broadcast.body,
                    link=broadcast.link,
                    metadata={
                        "broadcast_id": broadcast.id,
                    },
                )
                if res is not None and res.any_delivered:
                    delivered += 1
                else:
                    failed += 1
            except Exception as e:  # noqa: BLE001
                import sentry_sdk
                sentry_sdk.capture_exception(e)
                logger.warning(
                    "dispatch_broadcast_task: user=%s dispatch error: %s",
                    user_id, e,
                )
                failed += 1

        broadcast.delivered_count = delivered
        broadcast.failed_count = failed
        broadcast.completed_at = datetime.now(UTC)
        # 'completed' если хоть один доставлен ИЛИ нет получателей.
        # 'failed' если есть получатели, но все упали (например, у всех нет email).
        if delivered > 0 or len(recipients.user_ids) == 0:
            broadcast.status = "completed"
        else:
            broadcast.status = "failed"
        await session.commit()
        logger.info(
            "broadcast %s done: delivered=%s failed=%s filter=%r",
            broadcast.id, delivered, failed, recipients.filter_explanation,
        )


async def recover_stuck_broadcasts(stale_minutes: int = 30) -> int:
    """C7: восстановление «зависших» рассылок после rolling-restart.

    Рассылка диспатчится в fire-and-forget `asyncio.create_task`. Если реплику
    перезапустили (rolling-restart, scale 2→4→2), задача обрывается, а row
    остаётся в `pending`/`running` навсегда — рассылка молча не доедет и не
    восстановится. Балансировщик при этом считает реплику healthy.

    Этот sweep вызывается на старте каждой реплики (idempotent): любая рассылка
    в `pending`/`running`, которая старше `stale_minutes`, помечается `failed`
    с проставленным completed_at — она перестаёт «висеть», видна в списке как
    failed, и админ может пересоздать её осознанно (мы НЕ авто-ретраим вслепую,
    чтобы не дублировать письма уже доставленным получателям).

    Свежие (< stale_minutes) running-рассылки не трогаем — их, скорее всего,
    диспатчит живая задача прямо сейчас. Возвращает число помеченных строк.
    """
    from datetime import timedelta

    cutoff = datetime.now(UTC) - timedelta(minutes=stale_minutes)
    async with SessionLocal() as session:
        rows = list(
            (
                await session.execute(
                    select(NotificationBroadcast).where(
                        NotificationBroadcast.status.in_(("pending", "running")),
                        NotificationBroadcast.created_at < cutoff,
                    )
                )
            ).scalars().all()
        )
        if not rows:
            return 0
        now = datetime.now(UTC)
        for b in rows:
            b.status = "failed"
            if b.completed_at is None:
                b.completed_at = now
        await session.commit()
        logger.warning(
            "recover_stuck_broadcasts: marked %d stuck broadcast(s) as failed "
            "(ids=%s); likely orphaned by a restart",
            len(rows), [b.id for b in rows],
        )
        return len(rows)


# ============ Endpoints ============


@router.post(
    "/broadcast",
    response_model=BroadcastOut,
    status_code=status.HTTP_201_CREATED,
)
async def create_broadcast(
    body: BroadcastIn,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> BroadcastOut:
    """Создать рассылку. Возвращает row сразу (status='pending'); фон.
    задача переключит статус в running → completed.

    Валидация:
    - title обязателен;
    - channels — только из whitelist NOTIFICATION_CHANNELS;
    - kind — из whitelist NOTIFICATION_KINDS;
    - recipient_filter — словарь или null (содержание валидируется в resolve_broadcast_recipients).
    """
    # P0 Security: per-user кап на рассылки (mass-send abuse). 20/час.
    rl_ok, rl_retry = await check_broadcast_rate_limit(admin.id)
    if not rl_ok:
        raise HTTPException(
            status_code=status.HTTP_429_TOO_MANY_REQUESTS,
            detail="Слишком много рассылок. Попробуйте позже.",
            headers={"Retry-After": str(rl_retry)},
        )
    # Планирование пока не реализовано — отклоняем явно (не «молча игнорим»),
    # чтобы UI не показывал отложенную отправку, которой не будет.
    if body.scheduled_at is not None:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="Отложенная рассылка (scheduled_at) пока не поддерживается.",
        )
    if not body.title.strip():
        raise HTTPException(400, "Заголовок обязателен")
    if body.kind not in NOTIFICATION_KINDS:
        raise HTTPException(400, f"Неизвестный kind: {body.kind!r}")
    channels = body.channels or ["in_app"]
    for ch in channels:
        if ch not in NOTIFICATION_CHANNELS:
            raise HTTPException(
                400, f"Неизвестный канал: {ch!r}",
            )

    # C7 CRITICAL fail-safe: «всем» только при явном {type:"all"}. None/пустой/
    # неоднозначный filter → 422 (НЕ дефолт «всем»). Ссылка — только внутренний
    # относительный путь (open-redirect / XSS guard).
    try:
        normalized_filter = normalize_recipient_filter(body.recipient_filter)
        safe_link = sanitize_broadcast_link(body.link)
    except BroadcastFilterError as e:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY, detail=str(e),
        )

    broadcast = NotificationBroadcast(
        initiated_by_user_id=admin.id,
        kind=body.kind,
        title=body.title,
        body=body.body,
        link=safe_link,
        recipient_filter=normalized_filter,
        channels=channels,
        status="pending",
    )
    session.add(broadcast)
    await session.commit()
    await session.refresh(broadcast)

    # Fire-and-forget background task. Если упадёт — _dispatch_broadcast_task
    # сам переведёт status='failed'. asyncio.create_task возвращает Task —
    # держать ссылку на неё необязательно (loop сам её довыполнит).
    asyncio.create_task(_dispatch_broadcast_task(broadcast.id))

    return BroadcastOut.model_validate(broadcast)


@router.get("/broadcasts", response_model=list[BroadcastOut])
async def list_broadcasts(
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    status_filter: Annotated[str | None, Query(alias="status")] = None,
    limit: Annotated[int, Query(ge=1, le=100)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
) -> list[BroadcastOut]:
    """List с фильтром по status. Sort — DESC по created_at."""
    stmt = select(NotificationBroadcast)
    if status_filter:
        stmt = stmt.where(NotificationBroadcast.status == status_filter)
    stmt = stmt.order_by(desc(NotificationBroadcast.created_at)).limit(limit).offset(offset)
    rows = (await session.execute(stmt)).scalars().all()
    return [BroadcastOut.model_validate(r) for r in rows]


@router.get("/broadcasts/{broadcast_id}", response_model=BroadcastOut)
async def get_broadcast(
    broadcast_id: int,
    admin: AdminUser,
    session: Annotated[AsyncSession, Depends(get_session)],
) -> BroadcastOut:
    row = (
        await session.execute(
            select(NotificationBroadcast).where(
                NotificationBroadcast.id == broadcast_id,
            )
        )
    ).scalar_one_or_none()
    if row is None:
        raise HTTPException(404, "Рассылка не найдена")
    return BroadcastOut.model_validate(row)
