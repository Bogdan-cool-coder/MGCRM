"""Эпик 24.2 incident remediation — СКРИПТ 2: очистка мусорных mirror-Activity.

Cron импортировал чужие/личные Google-события как Activity (link
sync_direction='from_gcal'). Эти Activity — мусор в CRM (зеркала внешних
событий, которым CRM не мастер). Этот скрипт их soft-архивирует и
деактивирует связанные from_gcal event_links.

  ┌─────────────────────────────────────────────────────────────────────┐
  │ ВАЖНО: запускать ТОЛЬКО ПОСЛЕ gcal_remediate.py (СКРИПТ 1)!           │
  │ Remediation читает Activity.title / Activity.body для восстановления  │
  │ оригиналов в Google. Если архивировать Activity раньше — потеряешь    │
  │ единственный источник оригинальных данных. Сначала восстанови         │
  │ Google (скрипт 1), ПОТОМ чисти CRM (этот скрипт).                     │
  └─────────────────────────────────────────────────────────────────────┘

КАК ОПРЕДЕЛЯЕМ mirror-Activity. Берём Activity, у которых среди АКТИВНЫХ gcal
event_links есть хотя бы один from_gcal И НЕТ ни одного both/to_gcal. Это в
точности `is_mirror_only` из app.services.gcal_sync. Наши собственные события
имеют sync_direction='both' — их НЕ трогаем.

ARCHIVE (soft, НЕ hard delete). У модели Activity нет deleted_at/is_active —
терминальное «архивное» состояние в задачнике: status='rejected', is_closed=True,
rejected_at=now(). Row остаётся для аудита, но уходит из активных выборок.
Связанные from_gcal event_links деактивируются (is_active=False).

ЗАПУСК (внутри api-контейнера на проде):
    # dry-run (ДЕФОЛТ — ничего не пишет):
    docker compose exec api python scripts/gcal_archive_mirror.py
    # применить:
    docker compose exec api python scripts/gcal_archive_mirror.py --apply
    # явный юзер / лимит:
    docker compose exec api python scripts/gcal_archive_mirror.py --apply --user-id 1 --limit 10

Идемпотентно: уже заархивированные Activity (status='rejected' + нет активных
from_gcal linkов) повторно не трогаются.
"""
from __future__ import annotations

import argparse
import asyncio
import sys
from datetime import UTC, datetime
from pathlib import Path

# Добавляем apps/api в sys.path для запуска как standalone-скрипта.
sys.path.insert(0, str(Path(__file__).parent.parent))

from sqlalchemy import select  # noqa: E402
from sqlalchemy.orm import selectinload  # noqa: E402

from app.db import SessionLocal  # noqa: E402
from app.models import (  # noqa: E402
    Activity,
    GoogleCalendarEventLink,
    GoogleCalendarLink,
)
from app.services.gcal_sync import is_mirror_only  # noqa: E402


async def _resolve_user_id(session, user_id: int | None) -> int | None:
    """Определить user_id для фильтра: явный, единственный link, либо None (все)."""
    if user_id is not None:
        return user_id
    links = (await session.execute(select(GoogleCalendarLink))).scalars().all()
    if len(links) == 1:
        return links[0].user_id
    # 0 или >1 links — не фильтруем по юзеру, ищем все mirror-Activity.
    return None


async def archive_mirrors(
    *, user_id: int | None, apply: bool, limit: int | None,
) -> dict[str, int]:
    """Soft-архивировать mirror-only Activity и деактивировать их from_gcal linki.

    Возвращает счётчики для итогового отчёта.
    """
    stats = {
        "mirror_activities": 0,
        "already_archived_skipped": 0,
        "archived": 0,
        "links_deactivated": 0,
        "errors": 0,
    }

    async with SessionLocal() as session:
        resolved_user = await _resolve_user_id(session, user_id)

        # Кандидаты: Activity, имеющие хотя бы один активный from_gcal event_link.
        # Подгружаем ВСЕ event_links (не только активные/from_gcal), чтобы
        # is_mirror_only корректно увидел наличие both/to_gcal.
        stmt = (
            select(Activity)
            .join(
                GoogleCalendarEventLink,
                GoogleCalendarEventLink.activity_id == Activity.id,
            )
            .where(
                GoogleCalendarEventLink.sync_direction == "from_gcal",
                GoogleCalendarEventLink.is_active.is_(True),
            )
            .options(selectinload(Activity.gcal_event_links))
            .distinct()
            .order_by(Activity.id)
        )
        if resolved_user is not None:
            stmt = stmt.where(Activity.created_by_id == resolved_user)

        candidates = (await session.execute(stmt)).scalars().all()

        # Фильтр mirror-only по реальной логике сервиса.
        mirrors = [a for a in candidates if is_mirror_only(a.gcal_event_links)]
        if limit is not None:
            mirrors = mirrors[:limit]

        stats["mirror_activities"] = len(mirrors)
        mode = "APPLY" if apply else "DRY-RUN"
        print(
            f"[{mode}] user_id фильтр: {resolved_user if resolved_user else 'все'}",
        )
        print(f"[{mode}] mirror-only Activity к обработке: {len(mirrors)}\n")

        for activity in mirrors:
            active_from_gcal = [
                el for el in activity.gcal_event_links
                if el.is_active and el.sync_direction == "from_gcal"
            ]
            already = activity.status == "rejected" and not active_from_gcal
            if already:
                stats["already_archived_skipped"] += 1
                continue

            print(
                f"  activity_id={activity.id} kind={activity.kind} "
                f"status={activity.status} created={activity.created_at:%Y-%m-%d} "
                f"title='{(activity.title or '')[:60]}' "
                f"from_gcal_links={len(active_from_gcal)}",
            )

            if not apply:
                continue

            try:
                # Soft-archive: терминальное rejected-состояние.
                activity.status = "rejected"
                activity.is_closed = True
                if activity.rejected_at is None:
                    activity.rejected_at = datetime.now(UTC)
                # Деактивировать связанные from_gcal event_links.
                for el in active_from_gcal:
                    el.is_active = False
                    stats["links_deactivated"] += 1
                stats["archived"] += 1
            except Exception as exc:  # noqa: BLE001
                stats["errors"] += 1
                print(f"    -> ошибка архивации activity_id={activity.id}: {exc}")

        if apply:
            await session.commit()

    # Итоговый отчёт.
    print("\n===== ИТОГО =====")
    print(f"  Mirror-only Activity найдено:       {stats['mirror_activities']}")
    print(f"  Пропущено (уже заархивировано):     {stats['already_archived_skipped']}")
    if apply:
        print(f"  Заархивировано (status=rejected):   {stats['archived']}")
        print(f"  Деактивировано from_gcal linkов:    {stats['links_deactivated']}")
    else:
        candidates_n = (
            stats["mirror_activities"] - stats["already_archived_skipped"]
        )
        print(f"  Кандидатов на архивацию (dry-run):  {candidates_n}")
        print("  (dry-run — НИЧЕГО не записано; запусти с --apply)")
    print(f"  Ошибок:                             {stats['errors']}")
    return stats


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--apply",
        action="store_true",
        help="реально архивировать Activity (без флага — dry-run по умолчанию)",
    )
    parser.add_argument(
        "--user-id",
        type=int,
        default=None,
        help="фильтр по created_by_id; по умолчанию единственный link в БД, "
        "иначе все mirror-Activity",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=None,
        help="ограничить число обрабатываемых Activity (для пробного прогона)",
    )
    args = parser.parse_args()
    print(
        "ВНИМАНИЕ: запускать ТОЛЬКО ПОСЛЕ gcal_remediate.py (скрипт 1). "
        "Иначе потеряешь оригиналы title/body для восстановления Google.\n",
        file=sys.stderr,
    )
    asyncio.run(
        archive_mirrors(user_id=args.user_id, apply=args.apply, limit=args.limit),
    )


if __name__ == "__main__":
    main()
