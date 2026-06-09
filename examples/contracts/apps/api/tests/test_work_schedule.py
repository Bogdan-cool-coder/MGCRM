"""Epic 14.2 — Work schedule pure-function tests.

Pure-function: compute_slots_for_day + merge_overlapping_intervals.
DB-функции тестируются end-to-end ниже по проекту.
"""
from __future__ import annotations

from datetime import UTC, date, datetime, time

from app.services.work_schedule import (
    Slot,
    TimeInterval,
    compute_slots_for_day,
    merge_overlapping_intervals,
)


def _dt(d: date, h: int, m: int = 0) -> datetime:
    return datetime(d.year, d.month, d.day, h, m, tzinfo=UTC)


# ============ merge_overlapping_intervals ============


def test_merge_empty():
    assert merge_overlapping_intervals([]) == []


def test_merge_single():
    d = date(2026, 6, 1)
    iv = TimeInterval(start=_dt(d, 10), end=_dt(d, 11))
    assert merge_overlapping_intervals([iv]) == [iv]


def test_merge_non_overlapping_sorted():
    d = date(2026, 6, 1)
    iv1 = TimeInterval(_dt(d, 10), _dt(d, 11))
    iv2 = TimeInterval(_dt(d, 13), _dt(d, 14))
    out = merge_overlapping_intervals([iv1, iv2])
    assert out == [iv1, iv2]


def test_merge_overlapping():
    d = date(2026, 6, 1)
    iv1 = TimeInterval(_dt(d, 10), _dt(d, 12))
    iv2 = TimeInterval(_dt(d, 11), _dt(d, 13))
    merged = merge_overlapping_intervals([iv1, iv2])
    assert len(merged) == 1
    assert merged[0] == TimeInterval(_dt(d, 10), _dt(d, 13))


def test_merge_touching():
    d = date(2026, 6, 1)
    iv1 = TimeInterval(_dt(d, 10), _dt(d, 11))
    iv2 = TimeInterval(_dt(d, 11), _dt(d, 12))
    merged = merge_overlapping_intervals([iv1, iv2])
    # Касание — сливаются.
    assert len(merged) == 1
    assert merged[0] == TimeInterval(_dt(d, 10), _dt(d, 12))


def test_merge_unsorted_input():
    d = date(2026, 6, 1)
    iv1 = TimeInterval(_dt(d, 13), _dt(d, 14))
    iv2 = TimeInterval(_dt(d, 10), _dt(d, 11))
    out = merge_overlapping_intervals([iv1, iv2])
    assert out == [iv2, iv1]


# ============ compute_slots_for_day ============


def test_compute_slots_full_day_no_busy():
    # 9-12 → 6 слотов по 30 мин.
    d = date(2026, 6, 1)
    slots = compute_slots_for_day(
        day=d,
        work_start=time(9, 0),
        work_end=time(12, 0),
        slot_minutes=30,
        busy=[],
    )
    assert len(slots) == 6
    assert slots[0].start_at == _dt(d, 9, 0)
    assert slots[0].end_at == _dt(d, 9, 30)
    assert slots[-1].end_at == _dt(d, 12, 0)


def test_compute_slots_with_busy_in_middle():
    # 9-12, busy 10-11 → 4 слота: 9-9:30, 9:30-10, 11-11:30, 11:30-12.
    d = date(2026, 6, 1)
    busy = [TimeInterval(_dt(d, 10), _dt(d, 11))]
    slots = compute_slots_for_day(
        day=d, work_start=time(9, 0), work_end=time(12, 0),
        slot_minutes=30, busy=busy,
    )
    assert len(slots) == 4
    starts = [s.start_at for s in slots]
    assert _dt(d, 10) not in starts
    assert _dt(d, 10, 30) not in starts


def test_compute_slots_60_minutes():
    # 9-12 по 60 мин → 3 слота.
    d = date(2026, 6, 1)
    slots = compute_slots_for_day(
        day=d, work_start=time(9, 0), work_end=time(12, 0),
        slot_minutes=60, busy=[],
    )
    assert len(slots) == 3


def test_compute_slots_busy_at_start():
    # 9-12, busy 9-10 → 4 слота (10-12 по 30 мин).
    d = date(2026, 6, 1)
    busy = [TimeInterval(_dt(d, 9), _dt(d, 10))]
    slots = compute_slots_for_day(
        day=d, work_start=time(9, 0), work_end=time(12, 0),
        slot_minutes=30, busy=busy,
    )
    assert len(slots) == 4
    assert slots[0].start_at == _dt(d, 10, 0)


def test_compute_slots_busy_at_end():
    # 9-12, busy 11-12 → 4 слота.
    d = date(2026, 6, 1)
    busy = [TimeInterval(_dt(d, 11), _dt(d, 12))]
    slots = compute_slots_for_day(
        day=d, work_start=time(9, 0), work_end=time(12, 0),
        slot_minutes=30, busy=busy,
    )
    assert len(slots) == 4
    assert slots[-1].end_at == _dt(d, 11, 0)


def test_compute_slots_busy_covers_all():
    # 9-12, busy 9-12 → 0 слотов.
    d = date(2026, 6, 1)
    busy = [TimeInterval(_dt(d, 9), _dt(d, 12))]
    slots = compute_slots_for_day(
        day=d, work_start=time(9, 0), work_end=time(12, 0),
        slot_minutes=30, busy=busy,
    )
    assert slots == []


def test_compute_slots_empty_window():
    # start == end → 0 слотов.
    d = date(2026, 6, 1)
    slots = compute_slots_for_day(
        day=d, work_start=time(9, 0), work_end=time(9, 0),
        slot_minutes=30, busy=[],
    )
    assert slots == []


def test_compute_slots_invalid_slot_minutes():
    d = date(2026, 6, 1)
    import pytest
    with pytest.raises(ValueError):
        compute_slots_for_day(
            day=d, work_start=time(9, 0), work_end=time(10, 0),
            slot_minutes=0, busy=[],
        )


def test_compute_slots_partial_slot_at_end_excluded():
    # 9:00-10:15, по 30 мин → 2 слота (9-9:30, 9:30-10), 10-10:15 не влезает.
    d = date(2026, 6, 1)
    slots = compute_slots_for_day(
        day=d, work_start=time(9, 0), work_end=time(10, 15),
        slot_minutes=30, busy=[],
    )
    assert len(slots) == 2


def test_compute_slots_multiple_busy_intervals():
    # 9-13, busy 10-10:30 и 11-12 → 5 слотов:
    # 9-9:30, 9:30-10, 10:30-11, 12-12:30, 12:30-13.
    d = date(2026, 6, 1)
    busy = [
        TimeInterval(_dt(d, 10), _dt(d, 10, 30)),
        TimeInterval(_dt(d, 11), _dt(d, 12)),
    ]
    slots = compute_slots_for_day(
        day=d, work_start=time(9, 0), work_end=time(13, 0),
        slot_minutes=30, busy=busy,
    )
    assert len(slots) == 5
    starts = [s.start_at for s in slots]
    assert _dt(d, 10, 30) in starts  # после первого busy
    assert _dt(d, 12, 0) in starts  # после второго busy


def test_compute_slots_busy_intervals_overlapping_each_other():
    # 9-12, busy 10-11 + 10:30-11:30 (overlap) → 1 merged 10-11:30.
    # Слоты: 9-9:30, 9:30-10, 11:30-12 → 3 слота.
    d = date(2026, 6, 1)
    busy = [
        TimeInterval(_dt(d, 10), _dt(d, 11)),
        TimeInterval(_dt(d, 10, 30), _dt(d, 11, 30)),
    ]
    slots = compute_slots_for_day(
        day=d, work_start=time(9, 0), work_end=time(12, 0),
        slot_minutes=30, busy=busy,
    )
    assert len(slots) == 3


def test_compute_slots_naive_datetime_mode():
    # tz_aware_utc=False → возвращает naive datetime.
    d = date(2026, 6, 1)
    slots = compute_slots_for_day(
        day=d, work_start=time(9, 0), work_end=time(10, 0),
        slot_minutes=30, busy=[], tz_aware_utc=False,
    )
    assert len(slots) == 2
    assert slots[0].start_at.tzinfo is None


def test_compute_slots_15_minute_slots():
    # 9-10, по 15 мин → 4 слота.
    d = date(2026, 6, 1)
    slots = compute_slots_for_day(
        day=d, work_start=time(9, 0), work_end=time(10, 0),
        slot_minutes=15, busy=[],
    )
    assert len(slots) == 4
