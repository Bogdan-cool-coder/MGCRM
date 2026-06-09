"""Эпик 24.2 incident remediation — СКРИПТ 1: восстановление Google-событий.

ИНЦИДЕНТ. Cron импортировал внешние (личные) события юзера из Google primary-
календаря как Activity (link sync_direction='from_gcal'), затем push-сторона
перезаписала ОРИГИНАЛЫ в Google: summary стал "[Встреча] <title>", description —
CRM-текстом, start/end сброшены. Sync сейчас выключен, код пофикшен.

КЛЮЧЕВОЙ ИНСАЙТ. При ПЕРВОМ pull'е mirror-Activity захватила ОРИГИНАЛ до
перезаписи:
    Activity.title  = оригинальный заголовок (чистый, без префикса)
    Activity.body   = оригинальное описание
    Activity.due_at = оригинальное время начала
Последующие pull-циклы видели macro_activity_id в событии и шли в etag-only
ветку `_process_pulled_event` (macro_id branch), которая НЕ трогает Activity —
поэтому оригинальные значения сохранились. (Проверено по коду на 2026-06-02.)

ЧТО ДЕЛАЕТ. Для каждого from_gcal event_link юзера (НЕЗАВИСИМО от is_active —
см. FIX #3 ниже) патчит Google-событие обратно к оригиналу:
{summary: Activity.title, description: Activity.body}.
НЕ трогает start/end (время начала уже корректно = оригинал в Activity.due_at, но
мы его не пишем чтобы не рисковать; время КОНЦА восстановить нельзя — оно
потеряно). НИКОГДА не удаляет события. Только patch summary+description.

FIX #2 (2026-06-02). Дополнительно СНИМАЕТ наш отпечаток
extendedProperties.private.macro_activity_id с восстановленных событий.
ПРОБЛЕМА: 410 восстановленных from_gcal событий всё ещё несут этот отпечаток
(проставлен при push-overwrite). Pull видит их как «наши» (macro_id-ветка
_process_pulled_event) и вечно реактивирует линки терминальных/архивных
Activity. Снятие отпечатка → pull увидит события как внешние → по правилу A1
проигнорирует (return 0,0). Снятие выполняется в ТОМ ЖЕ events.patch, что и
восстановление контента, и происходит ДАЖЕ если контент уже совпадает (после
прошлого apply контент в SKIP, но отпечаток ещё на месте).

Как снимаем: Google Calendar API в events.patch УДАЛЯЕТ ключ из private-карты,
если передать его со значением null (private/shared — merge-карты). httpx
сериализует json= через json.dumps, который НЕ дропает None → передаём
{"extendedProperties":{"private":{"macro_activity_id": None}}}. update_event
(services/google_calendar.py) отдаёт тело в json= БЕЗ фильтрации/exclude_none —
поэтому используем его напрямую, отдельный httpx.patch не нужен.

ЗАПУСК (внутри api-контейнера на проде):
    # dry-run (ДЕФОЛТ — ничего не пишет в Google, только отчёт):
    docker compose exec api python scripts/gcal_remediate.py
    # применить:
    docker compose exec api python scripts/gcal_remediate.py --apply
    # явный юзер / лимит:
    docker compose exec api python scripts/gcal_remediate.py --apply --user-id 1 --limit 10

FIX #3 (2026-06-03). Выборка БОЛЬШЕ НЕ фильтрует по is_active. Скрипт 2
(gcal_archive_mirror.py) при архивации деактивировал все 410 from_gcal-линков
(is_active=False), из-за чего прежняя выборка `AND is_active=True` возвращала 0
и dry-run ничего не находил. Но события в Google живы и всё ещё несут отпечаток
macro_activity_id, а линки в БД присутствуют (просто неактивны) — это ровно наша
цель. Теперь критерий выборки строго sync_direction='from_gcal' + календарь
юзера. both/to_gcal НЕ затрагиваются (фильтр по sync_direction строгий).

Идемпотентно: повторный --apply на восстановленном событии (контент совпадает +
отпечатка уже нет) → SKIP. 404/410 (юзер удалил событие) — graceful skip.
"""
from __future__ import annotations

import argparse
import asyncio
import sys
from pathlib import Path

# Добавляем apps/api в sys.path для запуска как standalone-скрипта.
sys.path.insert(0, str(Path(__file__).parent.parent))

import httpx  # noqa: E402
from sqlalchemy import select  # noqa: E402
from sqlalchemy.orm import selectinload  # noqa: E402

from app.db import SessionLocal  # noqa: E402
from app.models import (  # noqa: E402
    Activity,
    GoogleCalendarEventLink,
    GoogleCalendarLink,
)
from app.services.google_calendar import (  # noqa: E402
    GOOGLE_CALENDAR_API_BASE,
    _ensure_access_token,
    extract_macro_activity_id,
    update_event,
)

# Дружелюбный rate-limit между запросами к Google, чтобы не словить 429.
SLEEP_BETWEEN_CALLS = 0.2


async def _resolve_link(
    session, user_id: int | None,
) -> GoogleCalendarLink:
    """Найти GoogleCalendarLink: по --user-id или единственный, если он один."""
    if user_id is not None:
        link = (
            await session.execute(
                select(GoogleCalendarLink).where(
                    GoogleCalendarLink.user_id == user_id,
                )
            )
        ).scalar_one_or_none()
        if link is None:
            raise SystemExit(f"GoogleCalendarLink для user_id={user_id} не найден.")
        return link

    links = (
        await session.execute(select(GoogleCalendarLink))
    ).scalars().all()
    if len(links) == 0:
        raise SystemExit("Нет ни одного GoogleCalendarLink в БД.")
    if len(links) > 1:
        ids = ", ".join(f"user_id={link.user_id}(link_id={link.id})" for link in links)
        raise SystemExit(
            f"Найдено несколько links: {ids}. Укажи явно --user-id.",
        )
    return links[0]


async def _fetch_current_event(
    session, link: GoogleCalendarLink, event_id: str,
) -> dict | None:
    """events.get — текущее состояние события в Google (для dry-run diff).

    Возвращает dict события, либо None если 404/410 (удалено юзером).
    """
    access_token = await _ensure_access_token(session, link)
    url = (
        f"{GOOGLE_CALENDAR_API_BASE}/calendars/{link.calendar_id}"
        f"/events/{event_id}"
    )
    async with httpx.AsyncClient(timeout=15.0) as client:
        resp = await client.get(
            url, headers={"Authorization": f"Bearer {access_token}"},
        )
        if resp.status_code in (404, 410):
            return None
        resp.raise_for_status()
        return resp.json()


def _short(text: str | None, n: int = 80) -> str:
    """Однострочный обрезанный кусок текста для отчёта."""
    if not text:
        return "<пусто>"
    flat = " ".join(text.split())
    return flat[:n] + ("…" if len(flat) > n else "")


async def remediate(
    *, user_id: int | None, apply: bool, limit: int | None,
) -> dict[str, int]:
    """Восстановить summary/description Google-событий из mirror-Activity.

    Возвращает счётчики для итогового отчёта.
    """
    stats = {
        "total_links": 0,
        "empty_title_skipped": 0,
        "missing_in_google_skipped": 0,
        "restored_content": 0,   # событий, где патчили summary/description
        "stamp_removed": 0,      # событий, где снимали отпечаток macro_activity_id
        "patched": 0,            # событий, реально отправленных в events.patch
        "fully_skipped": 0,      # контент совпал И отпечатка нет → не трогали
        "errors": 0,
    }

    async with SessionLocal() as session:
        link = await _resolve_link(session, user_id)
        print(
            f"Юзер: user_id={link.user_id}, google_email={link.google_email}, "
            f"calendar_id={link.calendar_id}, sync_enabled={link.sync_enabled}",
        )
        if link.sync_enabled:
            print(
                "WARNING: sync_enabled=True. Ожидался выключенный sync на время "
                "remediation. Продолжаем, но проверь, что cron не гоняется.",
                file=sys.stderr,
            )

        # from_gcal event_links этого юзера + их Activity.
        # ВАЖНО (FIX #3, 2026-06-03): НЕ фильтруем по is_active. Скрипт 2
        # (gcal_archive_mirror.py) деактивировал все 410 from_gcal-линков
        # (is_active=False) при архивации. Но события в Google живы и всё ещё
        # несут отпечаток macro_activity_id — именно с НИХ его и надо снять.
        # Линки в БД присутствуют (просто неактивны), Activity у них есть
        # (архивная, status=rejected — title/body читаемы). update_event
        # патчит Google по google_event_id и от флага is_active не зависит.
        # Наша цель — sync_direction='from_gcal' НЕЗАВИСИМО от is_active.
        stmt = (
            select(GoogleCalendarEventLink)
            .join(Activity, Activity.id == GoogleCalendarEventLink.activity_id)
            .where(
                GoogleCalendarEventLink.sync_direction == "from_gcal",
            )
            .options(selectinload(GoogleCalendarEventLink.activity))
            .order_by(GoogleCalendarEventLink.id)
        )
        event_links = (await session.execute(stmt)).scalars().all()

        # Фильтруем по календарю юзера (на случай orphaned linkов на чужой cal).
        event_links = [
            el for el in event_links
            if el.google_calendar_id == link.calendar_id
        ]
        if limit is not None:
            event_links = event_links[:limit]

        stats["total_links"] = len(event_links)
        mode = "APPLY" if apply else "DRY-RUN"
        print(f"\n[{mode}] from_gcal event_links к обработке: {len(event_links)}\n")

        for el in event_links:
            activity = el.activity
            if activity is None:
                # Битый link без Activity — пропускаем (нечего восстанавливать).
                stats["errors"] += 1
                print(f"  link_id={el.id}: нет Activity — SKIP (error)")
                continue

            proposed_summary = (activity.title or "").strip()
            proposed_description = activity.body or ""

            if not proposed_summary:
                # Пустой title — НЕ затираем Google (нечем восстанавливать).
                stats["empty_title_skipped"] += 1
                print(
                    f"  event={el.google_event_id} activity_id={activity.id}: "
                    f"пустой title — SKIP",
                )
                continue

            try:
                current = await _fetch_current_event(
                    session, link, el.google_event_id,
                )
            except httpx.HTTPStatusError as exc:
                stats["errors"] += 1
                print(
                    f"  event={el.google_event_id} activity_id={activity.id}: "
                    f"ошибка GET {exc.response.status_code} — SKIP",
                )
                await asyncio.sleep(SLEEP_BETWEEN_CALLS)
                continue

            if current is None:
                stats["missing_in_google_skipped"] += 1
                print(
                    f"  event={el.google_event_id} activity_id={activity.id}: "
                    f"404/410 в Google (удалено юзером) — SKIP",
                )
                await asyncio.sleep(SLEEP_BETWEEN_CALLS)
                continue

            cur_summary = current.get("summary") or ""
            cur_description = current.get("description") or ""

            # Две НЕЗАВИСИМЫЕ операции:
            #  (1) контент: вернуть summary/description к оригиналу (Activity).
            #  (2) отпечаток: снять extendedProperties.private.macro_activity_id,
            #      чтобы pull перестал видеть событие как «наше» (FIX #2).
            content_differs = (
                cur_summary != proposed_summary
                or cur_description != proposed_description
            )
            has_stamp = extract_macro_activity_id(current) is not None

            # SKIP ТОЛЬКО если И контент совпадает, И отпечатка уже нет.
            if not content_differs and not has_stamp:
                stats["fully_skipped"] += 1
                print(
                    f"  event={el.google_event_id} activity_id={activity.id}: "
                    f"контент совпадает + отпечатка нет — SKIP",
                )
                await asyncio.sleep(SLEEP_BETWEEN_CALLS)
                continue

            # Собираем ОДИН patch-body из нужных частей.
            patch_body: dict = {}
            if content_differs:
                patch_body["summary"] = proposed_summary
                patch_body["description"] = proposed_description
            if has_stamp:
                # null в private-карте events.patch УДАЛЯЕТ ключ (merge-семантика).
                patch_body["extendedProperties"] = {
                    "private": {"macro_activity_id": None},
                }

            # Отчёт по diff (и для dry-run, и для apply).
            print(f"  event={el.google_event_id} activity_id={activity.id}:")
            if content_differs:
                print(
                    f"    summary:     '{_short(cur_summary)}'  →  '{_short(proposed_summary)}'\n"
                    f"    description: '{_short(cur_description)}'  →  '{_short(proposed_description)}'",
                )
            else:
                print("    контент: уже совпадает (не трогаем)")
            if has_stamp:
                print("    отпечаток macro_activity_id: présent → будет удалён")
            else:
                print("    отпечаток macro_activity_id: уже отсутствует")

            if not apply:
                # В dry-run считаем «что было бы сделано».
                if content_differs:
                    stats["restored_content"] += 1
                if has_stamp:
                    stats["stamp_removed"] += 1
                await asyncio.sleep(SLEEP_BETWEEN_CALLS)
                continue

            # Apply: ОДИН events.patch со всеми нужными полями. start/end НЕ трогаем.
            try:
                await update_event(
                    session,
                    link,
                    el.google_event_id,
                    patch_body,
                )
                stats["patched"] += 1
                if content_differs:
                    stats["restored_content"] += 1
                if has_stamp:
                    stats["stamp_removed"] += 1
                parts = []
                if content_differs:
                    parts.append("контент")
                if has_stamp:
                    parts.append("отпечаток снят")
                print(
                    f"    -> PATCHED event={el.google_event_id} "
                    f"({', '.join(parts)})",
                )
            except httpx.HTTPStatusError as exc:
                code = exc.response.status_code
                if code in (404, 410):
                    stats["missing_in_google_skipped"] += 1
                    print(f"    -> {code} при PATCH (удалено) — SKIP")
                else:
                    stats["errors"] += 1
                    print(f"    -> ошибка PATCH {code} — SKIP: {exc.response.text[:160]}")
            except Exception as exc:  # noqa: BLE001
                stats["errors"] += 1
                print(f"    -> непредвиденная ошибка PATCH — SKIP: {exc}")

            await asyncio.sleep(SLEEP_BETWEEN_CALLS)

        # В --apply фиксируем сессию: _ensure_access_token мог обновить
        # access_token на link — сохраняем, чтобы при повторном запуске не было
        # лишнего refresh. В dry-run ничего не меняем — commit не нужен.
        if apply:
            await session.commit()

    # Итоговый отчёт.
    print("\n===== ИТОГО =====")
    print(f"  Всего from_gcal event_links:        {stats['total_links']}")
    print(f"  Пропущено (пустой title):           {stats['empty_title_skipped']}")
    print(f"  Пропущено (нет в Google 404/410):   {stats['missing_in_google_skipped']}")
    print(f"  Полностью пропущено (контент+отпечаток ок): {stats['fully_skipped']}")
    verb = "Восстановлен" if apply else "Будет восстановлен"
    verb2 = "Снят" if apply else "Будет снят"
    print(f"  {verb} контент (summary/description): {stats['restored_content']}")
    print(f"  {verb2} отпечаток macro_activity_id:   {stats['stamp_removed']}")
    if apply:
        print(f"  Всего events.patch отправлено:      {stats['patched']}")
    else:
        print("  (dry-run — НИЧЕГО не записано в Google; запусти с --apply)")
    print(f"  Ошибок:                             {stats['errors']}")
    return stats


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--apply",
        action="store_true",
        help="реально патчить Google-события (без флага — dry-run по умолчанию)",
    )
    parser.add_argument(
        "--user-id",
        type=int,
        default=None,
        help="user_id владельца календаря; по умолчанию единственный link в БД",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=None,
        help="ограничить число обрабатываемых событий (для пробного прогона)",
    )
    args = parser.parse_args()
    asyncio.run(
        remediate(user_id=args.user_id, apply=args.apply, limit=args.limit),
    )


if __name__ == "__main__":
    main()
