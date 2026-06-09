"""Эпик 24.2 — Google Calendar 2-way sync orchestration.

Высокоуровневые операции:
- sync_user_calendar(session, user_id) — один проход sync для одного user'а:
    1. Pull: events.list?syncToken=... → создаём/обновляем Activity или
       находим существующий link и патчим.
    2. Push: scan Activity с updated_at > link.last_sync_at, push в Google
       (POST если link нет, PATCH если есть).
    3. Сохраняем new sync_token + last_sync_at.
- sync_all_users(session) — пройтись по всем sync_enabled пользователям
  (cron-задача).
- sync_activity_to_gcal(session, activity_id) — точечный push для одного
  Activity (вызывается hook'ом из routers/activities POST/PATCH).
- delete_gcal_event(session, activity_id) — точечный delete (hook DELETE
  activity). Удаляет только both/to_gcal events; from_gcal лишь деактивирует.

ВАЖНО: все async-функции не падают наружу — ловим httpx-ошибки и
логируем, чтобы один сломанный link не блокировал sync других user'ов.
"""
from __future__ import annotations

import logging
from datetime import UTC, datetime, timedelta
from typing import Any

import httpx
from sqlalchemy import select, text
from sqlalchemy.ext.asyncio import AsyncSession

from app.models import (
    Activity,
    GoogleCalendarEventLink,
    GoogleCalendarLink,
    User,
)
from app.services.google_calendar import (
    INITIAL_PULL_BACK_DAYS,
    INITIAL_PULL_FORWARD_DAYS,
    TERMINAL_STATUSES,
    SyncResult,
    activity_to_event,
    create_event,
    delete_event,
    extract_macro_activity_id,
    list_events,
    merge_sync_results,
    should_sync_activity,
    update_event,
)

logger = logging.getLogger(__name__)

# Направления sync, при которых CRM является мастером и имеет право
# создавать/менять/удалять event в Google. 'from_gcal' сюда НЕ входит —
# это mirror-события чужого календаря, которые CRM трогать не должна.
PUSHABLE_DIRECTIONS: tuple[str, ...] = ("both", "to_gcal")

# Session-level advisory lock для single-flight sync_all_users.
# Берём SESSION-уровневый (pg_advisory_lock / pg_try_advisory_lock), а НЕ
# xact-уровневый: sync_user_calendar коммитит транзакцию внутри цикла
# (см. sync_user_calendar → session.commit()), поэтому xact-lock освободился
# бы после первого же коммита и перестал бы защищать остальную часть прохода.
# Ключ 24202 не пересекается с seed-ключами миграций (epic 24.2 использует
# 57_99x и 100_888) — проверено grep'ом по alembic/versions.
_SYNC_ALL_LOCK_KEY = 24202


# ============ Pure predicates (тестируемы без БД) ============


def is_pushable_direction(sync_direction: str | None) -> bool:
    """True если по этому направлению CRM может пушить в Google.

    'from_gcal' (mirror чужого события) → False, остальные → True.
    """
    return sync_direction in PUSHABLE_DIRECTIONS


def is_mirror_only(event_links: list[Any]) -> bool:
    """True если у Activity есть активный from_gcal link и НЕТ ни одного
    активного both/to_gcal link.

    Такое Activity — зеркало внешнего события: CRM ему не мастер, пушить
    нельзя (иначе создадим дубль/перезапишем чужой календарь).

    event_links — итерируемое объектов с атрибутами .is_active и
    .sync_direction (duck-typed, чтобы тестировать без БД).
    """
    active = [el for el in event_links if getattr(el, "is_active", False)]
    if not active:
        return False
    has_from_gcal = any(
        getattr(el, "sync_direction", None) == "from_gcal" for el in active
    )
    has_pushable = any(
        is_pushable_direction(getattr(el, "sync_direction", None))
        for el in active
    )
    return has_from_gcal and not has_pushable


def should_push_activity(event_links: list[Any]) -> bool:
    """True если Activity разрешено пушить в Google.

    Обратное к is_mirror_only: mirror-Activity (только from_gcal) не пушим.
    Activity без linkов вообще (новое) — пушим (Create-ветка).
    """
    return not is_mirror_only(event_links)


def should_delete_remote_event(sync_direction: str | None) -> bool:
    """True если этот event можно удалять из Google.

    Удаляем только то, чему CRM мастер (both/to_gcal). from_gcal (чужое
    событие) — НЕ удаляем, даже если Activity перестал проходить should_sync.
    """
    return is_pushable_direction(sync_direction)


def reuse_existing_link(existing_link: Any) -> bool:
    """True если для pulled-event с macro_activity_id нужно ПЕРЕИСПОЛЬЗОВАТЬ
    существующую строку link (reactivate/update etag), а не делать INSERT.

    Конфликт-фикс (Эпик 24.2, третья проблема): уникальный констрейнт
    uq_gcal_event_activity_cal — на (activity_id, google_calendar_id), БЕЗ
    is_active. Поэтому деактивированная строка (is_active=False) физически
    блокирует INSERT новой активной строки на ту же пару. Если по этой паре
    уже есть ЛЮБАЯ строка (активная или нет) — её надо переиспользовать,
    иначе словим IntegrityError → PendingRollbackError каскадом на всю сессию.

    existing_link — объект-строка link или None (duck-typed для тестов).
    Возвращает True (reuse), если строка существует; False (insert) если None.
    """
    return existing_link is not None


# ============ Helpers ============


async def _get_link_for_user(
    session: AsyncSession, user_id: int,
) -> GoogleCalendarLink | None:
    """Поднять GoogleCalendarLink для user_id или None."""
    return (
        await session.execute(
            select(GoogleCalendarLink).where(
                GoogleCalendarLink.user_id == user_id,
            )
        )
    ).scalar_one_or_none()


async def _get_event_link(
    session: AsyncSession, activity_id: int, calendar_id: str,
) -> GoogleCalendarEventLink | None:
    """Поднять GoogleCalendarEventLink для конкретной пары Activity ↔ calendar."""
    return (
        await session.execute(
            select(GoogleCalendarEventLink).where(
                GoogleCalendarEventLink.activity_id == activity_id,
                GoogleCalendarEventLink.google_calendar_id == calendar_id,
                GoogleCalendarEventLink.is_active.is_(True),
            )
        )
    ).scalar_one_or_none()


async def _get_any_event_link(
    session: AsyncSession, activity_id: int, calendar_id: str,
) -> GoogleCalendarEventLink | None:
    """Поднять GoogleCalendarEventLink по паре (activity_id, calendar_id) БЕЗ
    фильтра is_active.

    Это ровно те колонки, что входят в уникальный констрейнт
    uq_gcal_event_activity_cal. Нужно для conflict-safe pull: прежде чем
    INSERT'ить новую строку, проверяем не существует ли уже строка (в т.ч.
    деактивированная) по тем же ключам — если да, переиспользуем её.
    """
    return (
        await session.execute(
            select(GoogleCalendarEventLink).where(
                GoogleCalendarEventLink.activity_id == activity_id,
                GoogleCalendarEventLink.google_calendar_id == calendar_id,
            )
        )
    ).scalar_one_or_none()


async def _get_pushable_event_link(
    session: AsyncSession, activity_id: int, calendar_id: str,
) -> GoogleCalendarEventLink | None:
    """Как _get_event_link, но только для направлений both/to_gcal.

    from_gcal-linki (mirror чужого события) сюда не попадают — PUSH-сторона
    не должна их находить, иначе мы бы патчили/удаляли чужой календарь.
    """
    return (
        await session.execute(
            select(GoogleCalendarEventLink).where(
                GoogleCalendarEventLink.activity_id == activity_id,
                GoogleCalendarEventLink.google_calendar_id == calendar_id,
                GoogleCalendarEventLink.is_active.is_(True),
                GoogleCalendarEventLink.sync_direction.in_(PUSHABLE_DIRECTIONS),
            )
        )
    ).scalar_one_or_none()


async def _get_active_event_links(
    session: AsyncSession, activity_id: int,
) -> list[GoogleCalendarEventLink]:
    """Все активные event_links для Activity (по всем calendar_id)."""
    return list(
        (
            await session.execute(
                select(GoogleCalendarEventLink).where(
                    GoogleCalendarEventLink.activity_id == activity_id,
                    GoogleCalendarEventLink.is_active.is_(True),
                )
            )
        ).scalars().all()
    )


# ============ Push: Activity → Google ============


async def sync_activity_to_gcal(
    session: AsyncSession, activity_id: int,
) -> bool:
    """Push одного Activity в Google Calendar (POST или PATCH).

    Вызывается hook'ом из routers/activities POST/PATCH. Не падает наружу.

    Возвращает True если что-то отправили в Google (или idempotent skip),
    False если упало / sync не настроен.
    """
    try:
        activity = await session.get(Activity, activity_id)
        if activity is None:
            return False
        if activity.responsible_id is None:
            return False  # некому синкать
        link = await _get_link_for_user(session, activity.responsible_id)
        if link is None:
            return False

        # B-guard: mirror-Activity (есть активный from_gcal link и НЕТ ни
        # одного both/to_gcal) — CRM не мастер, пушить нельзя. Иначе Create-
        # ветка ниже создала бы дубль события в чужом календаре.
        all_links = await _get_active_event_links(session, activity_id)
        if is_mirror_only(all_links):
            return False

        if not should_sync_activity(activity, link):
            # Если sync был, а теперь не должен — удалить event.
            # Только pushable (both/to_gcal): from_gcal (чужое событие) не
            # находим → не удаляем чужой календарь (delete-guard).
            existing = await _get_pushable_event_link(
                session, activity_id, link.calendar_id,
            )
            if existing is not None:
                try:
                    await delete_event(session, link, existing.google_event_id)
                except (httpx.HTTPStatusError, ValueError) as e:
                    logger.warning("gcal delete failed: %s", e)
                existing.is_active = False
                await session.flush()
            return False

        existing = await _get_pushable_event_link(
            session, activity_id, link.calendar_id,
        )
        user = await session.get(User, activity.responsible_id)
        event_body = activity_to_event(activity, user)

        if existing is None:
            # Create-guard (defense-in-depth): НЕ создаём новое событие для
            # терминальной/закрытой задачи. should_sync_activity выше уже
            # отсекает такие (основной фикс), но если будущий рефактор вызовет
            # Create-ветку без re-check — этот guard не даст запостить дубль
            # для done/rejected/is_closed Activity. Update-ветка (existing!=None)
            # не трогаем: для уже связанного события закрытие — легитимный push.
            if getattr(activity, "is_closed", False) or (
                getattr(activity, "status", None) in TERMINAL_STATUSES
            ):
                return False
            # Create
            try:
                created = await create_event(session, link, event_body)
            except (httpx.HTTPStatusError, ValueError) as e:
                logger.warning(
                    "gcal create_event failed for activity %d: %s",
                    activity_id, e,
                )
                return False
            new_link = GoogleCalendarEventLink(
                activity_id=activity_id,
                google_event_id=created.get("id", ""),
                google_calendar_id=link.calendar_id,
                etag=created.get("etag"),
                last_synced_at=datetime.now(UTC),
                sync_direction="both",
                is_active=True,
            )
            session.add(new_link)
            await session.flush()
            return True

        # Update
        try:
            updated = await update_event(
                session,
                link,
                existing.google_event_id,
                event_body,
                etag=existing.etag,
            )
        except httpx.HTTPStatusError as e:
            if e.response.status_code == 412:
                # ETag mismatch — invalidate, full re-pull подхватит.
                existing.etag = None
                existing.last_synced_at = datetime.now(UTC)
                await session.flush()
                return False
            logger.warning(
                "gcal update_event failed for activity %d: %s",
                activity_id, e,
            )
            return False
        except ValueError as e:
            logger.warning(
                "gcal update_event token failure for activity %d: %s",
                activity_id, e,
            )
            return False

        existing.etag = updated.get("etag")
        existing.last_synced_at = datetime.now(UTC)
        await session.flush()
        return True
    except Exception as e:  # noqa: BLE001
        logger.exception(
            "sync_activity_to_gcal unexpected failure for %d: %s",
            activity_id, e,
        )
        return False


async def delete_gcal_event(
    session: AsyncSession, activity_id: int,
) -> int:
    """Удалить связанные events из Google Calendar.

    Вызывается hook'ом DELETE activity. Не падает наружу.

    Возвращает число events, реально удалённых из Google (для deleted_out).
    from_gcal-linki (mirror чужого события) НЕ удаляются из Google — мы лишь
    деактивируем link локально. CRM таким событиям не мастер.
    """
    deleted = 0
    try:
        # Найдём все активные linki для этой Activity (по разным calendar_id).
        links = (
            await session.execute(
                select(GoogleCalendarEventLink).where(
                    GoogleCalendarEventLink.activity_id == activity_id,
                    GoogleCalendarEventLink.is_active.is_(True),
                )
            )
        ).scalars().all()
        if not links:
            return 0  # idempotent

        # Для каждого link находим user_link и удаляем event.
        for el in links:
            # delete-guard: from_gcal (чужое событие) — не трогаем Google,
            # лишь деактивируем link локально. Удаляем только both/to_gcal.
            if not should_delete_remote_event(el.sync_direction):
                el.is_active = False
                continue
            # Через activity → user → user_link
            activity = await session.get(Activity, activity_id)
            if activity is None or activity.responsible_id is None:
                el.is_active = False
                continue
            ul = await _get_link_for_user(session, activity.responsible_id)
            if ul is None:
                el.is_active = False
                continue
            try:
                await delete_event(session, ul, el.google_event_id)
                deleted += 1
            except (httpx.HTTPStatusError, ValueError) as e:
                logger.warning("gcal delete failed: %s", e)
            el.is_active = False
        await session.flush()
        return deleted
    except Exception as e:  # noqa: BLE001
        logger.exception(
            "delete_gcal_event unexpected failure for %d: %s",
            activity_id, e,
        )
        return deleted


# ============ Pull: Google → Activity ============


async def _process_pulled_event(
    session: AsyncSession,
    link: GoogleCalendarLink,
    event: dict[str, Any],
) -> tuple[int, int]:
    """Обработать один pulled event.

    Возвращает (created_or_updated_count, error_count).

    Logic:
    - cancelled event → soft-delete event_link, не трогаем Activity (юзер
      сам решит удалить или просто рассинкать).
    - event с macro_activity_id (наш PUT) → ищем link, обновляем etag.
      Не трогаем Activity (push-сторона мастер).
    - event без macro_activity_id (внешний/чужой) → НЕ импортируем (см.
      ниже). CRM — push-master only.
    """
    if event.get("status") == "cancelled":
        evt_id = event.get("id")
        if evt_id:
            existing = (
                await session.execute(
                    select(GoogleCalendarEventLink).where(
                        GoogleCalendarEventLink.google_event_id == evt_id,
                        GoogleCalendarEventLink.is_active.is_(True),
                    )
                )
            ).scalar_one_or_none()
            if existing is not None:
                existing.is_active = False
                await session.flush()
        return 0, 0

    macro_id = extract_macro_activity_id(event)
    if macro_id is not None:
        # Это наш push (или наш отпечаток на событии) — обновляем etag, не
        # трогаем Activity (push-сторона мастер).
        #
        # Conflict-safe (Эпик 24.2, третья проблема): берём ЛЮБУЮ строку link
        # по паре (activity_id, calendar_id) — БЕЗ фильтра is_active. Это те же
        # колонки, что в uq_gcal_event_activity_cal. Если строка есть (даже
        # деактивированная — кейс архивированных from_gcal-линков на событиях,
        # на которые при инциденте проставился macro_activity_id) —
        # ПЕРЕИСПОЛЬЗУЕМ её (reactivate + refresh etag), НЕ делаем INSERT.
        # Слепой INSERT раньше конфликтовал с деактивированной строкой по
        # unique-констрейнту → IntegrityError → PendingRollbackError каскадом
        # на всю сессию (роняло весь pull-цикл).
        existing = await _get_any_event_link(session, macro_id, link.calendar_id)
        if reuse_existing_link(existing):
            assert existing is not None  # для типов; reuse_existing_link это и проверяет
            existing.etag = event.get("etag")
            existing.last_synced_at = datetime.now(UTC)
            evt_id = event.get("id")
            if evt_id:
                # Событие могло пересоздаться в Google с новым id — синхронизируем.
                existing.google_event_id = evt_id
            if not existing.is_active:
                # Реактивируем ранее деактивированную строку вместо INSERT.
                existing.is_active = True
            await session.flush()
            return 0, 0
        # Реально нет ни одной строки по этой паре — пересоздаём
        # (например, ручной DELETE строки из БД).
        evt_id = event.get("id")
        if evt_id:
            new_link = GoogleCalendarEventLink(
                activity_id=macro_id,
                google_event_id=evt_id,
                google_calendar_id=link.calendar_id,
                etag=event.get("etag"),
                last_synced_at=datetime.now(UTC),
                sync_direction="both",
                is_active=True,
            )
            session.add(new_link)
            await session.flush()
            return 1, 0
        return 0, 0

    # Внешний event (без macro_activity_id) — НЕ импортируем.
    # CRM работает push-master only: мы не создаём и не обновляем Activity из
    # чужих событий Google. Импорт чужого календаря вызывал деструктивную
    # перезапись/появление mirror-Activity, которые затем пушились обратно и
    # били реальные данные (инцидент Эпик 24.2). Вариант A1: убираем импорт
    # полностью, без миграции — старых from_gcal-linkов в проде нет.
    return 0, 0


async def _pull_for_link(
    session: AsyncSession, link: GoogleCalendarLink,
) -> tuple[int, int, str | None, bool]:
    """Сделать pull через events.list (incremental или full).

    Возвращает (created_or_updated, errors, new_sync_token, full_resync).
    """
    created = 0
    errors = 0
    sync_token: str | None = link.last_sync_token
    full_resync = False

    if sync_token is None:
        # Полный pull
        now = datetime.now(UTC)
        time_min = now - timedelta(days=INITIAL_PULL_BACK_DAYS)
        time_max = now + timedelta(days=INITIAL_PULL_FORWARD_DAYS)
        full_resync = True
    else:
        time_min = None
        time_max = None

    page_token: str | None = None
    new_sync_token: str | None = None
    try:
        while True:
            try:
                resp = await list_events(
                    session,
                    link,
                    sync_token=sync_token,
                    time_min=time_min,
                    time_max=time_max,
                    page_token=page_token,
                )
            except httpx.HTTPStatusError as e:
                if e.response.status_code == 410:
                    # sync_token устарел — full re-pull.
                    logger.info(
                        "gcal sync_token gone for user %d, doing full re-pull",
                        link.user_id,
                    )
                    sync_token = None
                    page_token = None
                    full_resync = True
                    now = datetime.now(UTC)
                    time_min = now - timedelta(days=INITIAL_PULL_BACK_DAYS)
                    time_max = now + timedelta(days=INITIAL_PULL_FORWARD_DAYS)
                    continue
                raise

            for event in resp.get("items") or []:
                # Savepoint на каждый event (Эпик 24.2, третья проблема): если
                # обработка одного события всё-таки бросит (IntegrityError или
                # любая другая ошибка после частичного flush), begin_nested()
                # откатит ТОЛЬКО этот savepoint, а внешняя транзакция останется
                # пригодной. Без savepoint первый же провалившийся flush
                # переводил всю сессию в PendingRollback, и каждый последующий
                # event падал каскадом, роняя весь pull-цикл. Conflict-safe
                # ветка macro_id выше должна была убрать саму причину
                # IntegrityError; savepoint — defense-in-depth на остальные
                # неожиданные сбои.
                try:
                    async with session.begin_nested():
                        c, e = await _process_pulled_event(
                            session, link, event,
                        )
                    created += c
                    errors += e
                except Exception as ex:  # noqa: BLE001
                    # begin_nested() уже откатил savepoint — сессия чистая,
                    # следующий event обработается нормально.
                    logger.exception(
                        "gcal _process_pulled_event failed: %s", ex,
                    )
                    errors += 1

            page_token = resp.get("nextPageToken")
            new_sync_token = resp.get("nextSyncToken") or new_sync_token
            if not page_token:
                break
    except (httpx.HTTPStatusError, ValueError) as e:
        logger.warning("gcal pull failed for user %d: %s", link.user_id, e)
        errors += 1

    return created, errors, new_sync_token, full_resync


# ============ Push: scan modified activities → Google ============


async def _push_modified_activities(
    session: AsyncSession, link: GoogleCalendarLink,
) -> tuple[int, int, int]:
    """Сканируем Activity где user=responsible, updated_at >= last_sync_at,
    делаем push (POST / PATCH) или delete (если Activity больше не sync-able).

    Возвращает (pushed_count, deleted_count, errors).
    """
    since = link.last_sync_at or (datetime.now(UTC) - timedelta(days=1))
    if since.tzinfo is None:
        since = since.replace(tzinfo=UTC)

    activities = (
        await session.execute(
            select(Activity).where(
                Activity.responsible_id == link.user_id,
                Activity.updated_at >= since,
                Activity.kind.in_(("meeting", "call")),
            )
        )
    ).scalars().all()

    pushed = 0
    deleted = 0
    errors = 0
    for activity in activities:
        if not should_sync_activity(activity, link):
            # Activity перестал быть sync-able: sync_activity_to_gcal удалит
            # уже существующий pushable event. Считаем delete отдельно, чтобы
            # не учитывать его как ошибку (он возвращает False = «не запушено»).
            existing = await _get_pushable_event_link(
                session, activity.id, link.calendar_id,
            )
            if existing is not None:
                await sync_activity_to_gcal(session, activity.id)
                deleted += 1
            continue
        # B-scan (defense-in-depth): пропускаем mirror-Activity (только
        # from_gcal linki). B-guard в sync_activity_to_gcal — обязателен,
        # этот скан лишь экономит лишний вызов.
        all_links = await _get_active_event_links(session, activity.id)
        if is_mirror_only(all_links):
            continue
        ok = await sync_activity_to_gcal(session, activity.id)
        if ok:
            pushed += 1
        else:
            errors += 1
    return pushed, deleted, errors


# ============ Top-level sync ============


async def sync_user_calendar(
    session: AsyncSession, user_id: int,
) -> SyncResult:
    """Полный sync одного user'а: pull → push → save sync_token.

    Не падает наружу. Все ошибки логируются и возвращаются в SyncResult.errors.
    """
    link = await _get_link_for_user(session, user_id)
    if link is None:
        logger.info("gcal sync: no link for user %d, skipping", user_id)
        return SyncResult(errors=0)
    if not link.sync_enabled:
        return SyncResult(errors=0)

    # Pull
    pulled, pull_errors, new_token, full_resync = await _pull_for_link(
        session, link,
    )

    # Push (modified activities) + delete (Activity больше не sync-able)
    pushed, deleted, push_errors = await _push_modified_activities(
        session, link,
    )

    # Save sync state
    link.last_sync_at = datetime.now(UTC)
    if new_token:
        link.last_sync_token = new_token
    await session.flush()
    await session.commit()

    return SyncResult(
        pulled_in=pulled,
        pushed_out=pushed,
        deleted_out=deleted,
        errors=pull_errors + push_errors,
        full_resync=full_resync,
    )


async def sync_all_users(session: AsyncSession) -> SyncResult:
    """Cron-задача: пройтись по всем sync_enabled пользователям.

    Не падает наружу. Один сломанный link не блокирует остальные.

    Single-flight: берём SESSION-level advisory lock через pg_try_advisory_lock.
    Если lock уже занят (другая реплика/предыдущий запуск ещё идёт) — логируем
    и выходим, чтобы два конкурентных прохода не пушили одни и те же Activity
    дважды. SESSION-level (а не xact) — потому что sync_user_calendar коммитит
    транзакцию внутри цикла, и xact-lock освободился бы после первого коммита,
    перестав защищать остаток прохода. В finally обязательно unlock.

    КОРРЕКТНОСТЬ unlock (Эпик 24.2 PM-review Critical #2):
    SESSION-level advisory lock привязан к конкретному backend-connection.
    sync_user_calendar коммитит внутри цикла — важно, чтобы commit НЕ менял
    connection, иначе unlock в finally снял бы lock на другом connection,
    а оригинальный завис бы до закрытия пула. AsyncSession (async_sessionmaker,
    expire_on_commit=False) держит ОДИН checked-out connection всё время своей
    жизни: commit лишь завершает транзакцию и сразу autobegin-ит новую на ТОМ
    ЖЕ connection (connection возвращается в пул только при session.close()).
    Проверено эмпирически: pg_backend_pid() стабилен через несколько commit,
    lock остаётся held на этом pid, unlock корректно его снимает. Поэтому
    lock + unlock здесь, на сессии sync_all_users — безопасно.
    """
    got_lock = (
        await session.execute(
            text("SELECT pg_try_advisory_lock(:k)"),
            {"k": _SYNC_ALL_LOCK_KEY},
        )
    ).scalar()
    if not got_lock:
        logger.info(
            "gcal sync_all_users: another run holds the lock, skipping",
        )
        return SyncResult()

    try:
        links = (
            await session.execute(
                select(GoogleCalendarLink).where(
                    GoogleCalendarLink.sync_enabled.is_(True),
                )
            )
        ).scalars().all()

        results: list[SyncResult] = []
        for link in links:
            try:
                r = await sync_user_calendar(session, link.user_id)
                results.append(r)
            except Exception as e:  # noqa: BLE001
                logger.exception(
                    "gcal sync_user_calendar uncaught for user %d: %s",
                    link.user_id, e,
                )
                results.append(SyncResult(errors=1))
        if not results:
            return SyncResult()
        return merge_sync_results(*results)
    finally:
        await session.execute(
            text("SELECT pg_advisory_unlock(:k)"),
            {"k": _SYNC_ALL_LOCK_KEY},
        )
