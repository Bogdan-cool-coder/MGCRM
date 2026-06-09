"""Epic 14.2 — Work schedule + available slots service.

Чистые функции для unit-тестов:
- compute_slots_for_day(schedule, busy_intervals, slot_minutes) → list[Slot]
- merge_overlapping_intervals(intervals) → list[Interval]

DB-функции:
- get_schedule_for_user(session, user_id, day_of_week) → WorkSchedule | None
- is_user_on_vacation(session, user_id, on_date) → bool
- get_active_substitute(session, user_id, on_date) → User | None
- compute_available_slots(session, user_id, date_from, date_to, ...) → list[Slot]
"""
from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, date, datetime, time, timedelta
from typing import Iterable

from sqlalchemy import or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import Activity, User, UserVacation, WorkSchedule
from app.services.production_calendar import get_holidays_set, is_working_day

# ============ Data types ============


@dataclass(frozen=True)
class TimeInterval:
    """Полузакрытый интервал [start, end). Полезен для занятых слотов."""

    start: datetime
    end: datetime


@dataclass(frozen=True)
class Slot:
    """Свободный слот для встречи. JSON: {start_at, end_at}."""

    start_at: datetime
    end_at: datetime

    def to_dict(self) -> dict:
        return {
            "start_at": self.start_at.isoformat(),
            "end_at": self.end_at.isoformat(),
        }


# ============ Pure functions ============


def merge_overlapping_intervals(
    intervals: Iterable[TimeInterval],
) -> list[TimeInterval]:
    """Сливает пересекающиеся / соприкасающиеся интервалы.

    Возвращает отсортированный по start список без пересечений.
    """
    sorted_iv = sorted(intervals, key=lambda iv: iv.start)
    if not sorted_iv:
        return []
    merged: list[TimeInterval] = [sorted_iv[0]]
    for iv in sorted_iv[1:]:
        last = merged[-1]
        if iv.start <= last.end:
            # Перекрытие/касание — расширяем последний.
            new_end = max(last.end, iv.end)
            merged[-1] = TimeInterval(last.start, new_end)
        else:
            merged.append(iv)
    return merged


def compute_slots_for_day(
    day: date,
    work_start: time,
    work_end: time,
    slot_minutes: int,
    busy: Iterable[TimeInterval],
    *,
    tz_aware_utc: bool = True,
) -> list[Slot]:
    """Разбивает рабочее окно [work_start; work_end] на слоты slot_minutes.

    Затем вычитает busy-интервалы (объединённые). Возвращает только полные
    слоты, не пересекающиеся с busy.

    day — какой именно день. Слоты выдаются в naive datetime (без TZ) если
    tz_aware_utc=False, иначе в UTC.
    """
    if slot_minutes <= 0:
        raise ValueError("slot_minutes must be > 0")

    # Собираем границы окна в datetime.
    tz = UTC if tz_aware_utc else None
    window_start = datetime.combine(day, work_start, tzinfo=tz)
    window_end = datetime.combine(day, work_end, tzinfo=tz)
    if window_start >= window_end:
        return []

    busy_merged = merge_overlapping_intervals(busy)

    slots: list[Slot] = []
    cur = window_start
    delta = timedelta(minutes=slot_minutes)

    def _overlaps_any_busy(s: datetime, e: datetime) -> bool:
        # [s, e) пересекается c b=[bs, be) если s < be и bs < e.
        for b in busy_merged:
            if s < b.end and b.start < e:
                return True
        return False

    while cur + delta <= window_end:
        slot_end = cur + delta
        if not _overlaps_any_busy(cur, slot_end):
            slots.append(Slot(start_at=cur, end_at=slot_end))
        cur = slot_end
    return slots


# ============ DB functions ============


async def get_schedule_for_user(
    session: AsyncSession,
    user_id: int,
    day_of_week: int,
) -> WorkSchedule | None:
    """Расписание для юзера на конкретный день недели.

    Сначала ищет персональное (scope_type='user', scope_id=user_id),
    затем — отдельское (через User.department_id).
    """
    # 1) Персональное расписание
    personal = (
        await session.execute(
            select(WorkSchedule).where(
                WorkSchedule.scope_type == "user",
                WorkSchedule.scope_id == user_id,
                WorkSchedule.day_of_week == day_of_week,
            )
        )
    ).scalar_one_or_none()
    if personal is not None:
        return personal

    # 2) Отдельское — нужно подтянуть department_id юзера
    user = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    if user is None or user.department_id is None:
        return None
    return (
        await session.execute(
            select(WorkSchedule).where(
                WorkSchedule.scope_type == "department",
                WorkSchedule.scope_id == user.department_id,
                WorkSchedule.day_of_week == day_of_week,
            )
        )
    ).scalar_one_or_none()


async def is_user_on_vacation(
    session: AsyncSession,
    user_id: int,
    on_date: date,
) -> bool:
    """В отпуске/больничном/командировке на конкретную дату.

    Учитывает только approved (approved_at IS NOT NULL).
    """
    res = (
        await session.execute(
            select(UserVacation.id).where(
                UserVacation.user_id == user_id,
                UserVacation.start_date <= on_date,
                UserVacation.end_date >= on_date,
                UserVacation.approved_at.is_not(None),
            ).limit(1)
        )
    ).scalar_one_or_none()
    return res is not None


async def get_active_substitute(
    session: AsyncSession,
    user_id: int,
    on_date: date,
) -> User | None:
    """Возвращает активного заместителя на конкретную дату.

    Приоритет:
    1) UserVacation.substitute_user_id на этот period (approved)
    2) User.substitute_user_id (постоянный)

    Возвращает None если ни один не задан.
    """
    # 1) Приоритет — заместитель из отпуска
    vacation_sub = (
        await session.execute(
            select(UserVacation.substitute_user_id).where(
                UserVacation.user_id == user_id,
                UserVacation.start_date <= on_date,
                UserVacation.end_date >= on_date,
                UserVacation.approved_at.is_not(None),
                UserVacation.substitute_user_id.is_not(None),
            ).limit(1)
        )
    ).scalar_one_or_none()
    if vacation_sub:
        return (
            await session.execute(
                select(User).where(User.id == vacation_sub)
            )
        ).scalar_one_or_none()

    # 2) Постоянный заместитель из юзера
    user = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    if user is None or user.substitute_user_id is None:
        return None
    return (
        await session.execute(
            select(User).where(User.id == user.substitute_user_id)
        )
    ).scalar_one_or_none()


async def _busy_intervals_for_user_on_day(
    session: AsyncSession,
    user_id: int,
    day: date,
) -> list[TimeInterval]:
    """Занятые интервалы (meeting/call) за день.

    Берёт Activity kind∈('meeting','call') с responsible_id=user_id и
    due_at в [day; day+1). Длительность пока не модели — используем
    fixed 30 мин для call/60 мин для meeting (грубая эвристика на MVP).
    """
    start_dt = datetime.combine(day, time(0, 0), tzinfo=UTC)
    end_dt = start_dt + timedelta(days=1)

    rows = (
        await session.execute(
            select(Activity.kind, Activity.due_at).where(
                Activity.responsible_id == user_id,
                Activity.kind.in_(("meeting", "call")),
                Activity.due_at.is_not(None),
                Activity.due_at >= start_dt,
                Activity.due_at < end_dt,
                Activity.completed_at.is_(None),
            )
        )
    ).all()

    out: list[TimeInterval] = []
    for kind, due_at in rows:
        # Грубая эвристика длительности.
        dur_min = 60 if kind == "meeting" else 30
        # Нормализуем TZ для надёжного сравнения.
        if due_at.tzinfo is None:
            due_at = due_at.replace(tzinfo=UTC)
        out.append(
            TimeInterval(
                start=due_at,
                end=due_at + timedelta(minutes=dur_min),
            )
        )
    return out


async def compute_available_slots(
    session: AsyncSession,
    user_id: int,
    date_from: date,
    date_to: date,
    *,
    country_code: str = "RU",
    slot_minutes_override: int | None = None,
) -> list[Slot]:
    """Свободные слоты юзера на [date_from; date_to] (включительно).

    Учитывает:
    - work_schedules (per user или department)
    - user_vacations (skip dates)
    - production_calendar (skip holidays для country_code)
    - existing activities kind∈('meeting','call') с responsible_id=user_id

    Возвращает плоский список Slot, отсортированный по start_at.
    """
    if date_to < date_from:
        return []

    years = {date_from.year, date_to.year}
    holidays = await get_holidays_set(session, country_code, years)

    out: list[Slot] = []
    cur = date_from
    while cur <= date_to:
        # Skip выходные и праздники
        if not is_working_day(cur, holidays):
            cur += timedelta(days=1)
            continue
        # Skip отпуск
        if await is_user_on_vacation(session, user_id, cur):
            cur += timedelta(days=1)
            continue
        # Расписание дня
        schedule = await get_schedule_for_user(session, user_id, cur.weekday())
        if (
            schedule is None
            or not schedule.is_working
            or schedule.start_time is None
            or schedule.end_time is None
        ):
            cur += timedelta(days=1)
            continue
        # Занятые интервалы
        busy = await _busy_intervals_for_user_on_day(session, user_id, cur)
        slot_min = (
            slot_minutes_override
            if slot_minutes_override is not None
            else schedule.meeting_slot_minutes
        )
        slots = compute_slots_for_day(
            day=cur,
            work_start=schedule.start_time,
            work_end=schedule.end_time,
            slot_minutes=slot_min,
            busy=busy,
        )
        out.extend(slots)
        cur += timedelta(days=1)
    return out


async def compute_team_available_slots(
    session: AsyncSession,
    user_ids: list[int],
    date_from: date,
    date_to: date,
    *,
    country_code: str = "RU",
    slot_minutes: int = 30,
) -> dict[int, list[Slot]]:
    """Слоты для нескольких юзеров — для динамического распределения задач.

    Возвращает dict {user_id: [Slot, ...]}.
    """
    out: dict[int, list[Slot]] = {}
    for uid in user_ids:
        out[uid] = await compute_available_slots(
            session, uid, date_from, date_to,
            country_code=country_code,
            slot_minutes_override=slot_minutes,
        )
    return out
