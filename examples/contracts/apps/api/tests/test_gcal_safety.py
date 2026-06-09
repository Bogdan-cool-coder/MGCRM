"""Эпик 24.2 фикс — guard'ы безопасности Google Calendar sync.

Покрываем pure-предикаты, которые гарантируют:
- CRM не пушит mirror-события чужого календаря (A1/B);
- CRM не удаляет чужие события (D);
- pull больше не импортирует внешние events (A1);
- single-flight: занятый advisory-lock пропускает проход (C).

Все тесты pure-function: stub'ы через duck-typing (как в test_gcal_*),
без БД-фикстур и сети.
"""
from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any

import pytest

from app.services.gcal_sync import (
    PUSHABLE_DIRECTIONS,
    TERMINAL_STATUSES,
    _process_pulled_event,
    is_mirror_only,
    is_pushable_direction,
    reuse_existing_link,
    should_delete_remote_event,
    should_push_activity,
    sync_all_users,
)
from app.services.google_calendar import (
    TERMINAL_STATUSES as TERMINAL_STATUSES_GC,
)
from app.services.google_calendar import (
    should_sync_activity,
)


@dataclass
class FakeEventLink:
    sync_direction: str = "both"
    is_active: bool = True


# ============ is_pushable_direction ============


def test_pushable_both():
    assert is_pushable_direction("both") is True


def test_pushable_to_gcal():
    assert is_pushable_direction("to_gcal") is True


def test_pushable_from_gcal_false():
    assert is_pushable_direction("from_gcal") is False


def test_pushable_none_false():
    assert is_pushable_direction(None) is False


def test_pushable_unknown_false():
    assert is_pushable_direction("garbage") is False


def test_pushable_directions_constant():
    """from_gcal не должно входить в pushable — иначе пушим чужой календарь."""
    assert "from_gcal" not in PUSHABLE_DIRECTIONS
    assert set(PUSHABLE_DIRECTIONS) == {"both", "to_gcal"}


# ============ is_mirror_only ============


def test_mirror_only_when_single_from_gcal():
    links = [FakeEventLink(sync_direction="from_gcal", is_active=True)]
    assert is_mirror_only(links) is True


def test_mirror_only_false_when_no_links():
    """Новый Activity без linkов — не mirror, пушим (Create-ветка)."""
    assert is_mirror_only([]) is False


def test_mirror_only_false_when_has_both():
    links = [FakeEventLink(sync_direction="both", is_active=True)]
    assert is_mirror_only(links) is False


def test_mirror_only_false_when_has_to_gcal():
    links = [FakeEventLink(sync_direction="to_gcal", is_active=True)]
    assert is_mirror_only(links) is False


def test_mirror_only_false_when_from_gcal_plus_pushable():
    """Есть и from_gcal и both → не mirror (CRM мастер хотя бы по одному)."""
    links = [
        FakeEventLink(sync_direction="from_gcal", is_active=True),
        FakeEventLink(sync_direction="both", is_active=True),
    ]
    assert is_mirror_only(links) is False


def test_mirror_only_ignores_inactive_from_gcal():
    """Неактивный from_gcal link не делает Activity mirror'ом."""
    links = [FakeEventLink(sync_direction="from_gcal", is_active=False)]
    assert is_mirror_only(links) is False


def test_mirror_only_inactive_pushable_still_mirror():
    """Активный from_gcal + неактивный both → всё ещё mirror (both не в счёт)."""
    links = [
        FakeEventLink(sync_direction="from_gcal", is_active=True),
        FakeEventLink(sync_direction="both", is_active=False),
    ]
    assert is_mirror_only(links) is True


# ============ should_push_activity ============


def test_should_push_true_for_no_links():
    assert should_push_activity([]) is True


def test_should_push_true_for_both():
    links = [FakeEventLink(sync_direction="both", is_active=True)]
    assert should_push_activity(links) is True


def test_should_push_false_for_mirror():
    links = [FakeEventLink(sync_direction="from_gcal", is_active=True)]
    assert should_push_activity(links) is False


def test_should_push_is_inverse_of_mirror():
    links = [FakeEventLink(sync_direction="from_gcal", is_active=True)]
    assert should_push_activity(links) is (not is_mirror_only(links))


# ============ should_delete_remote_event ============


def test_delete_allowed_both():
    assert should_delete_remote_event("both") is True


def test_delete_allowed_to_gcal():
    assert should_delete_remote_event("to_gcal") is True


def test_delete_forbidden_from_gcal():
    """Чужое событие (from_gcal) удалять нельзя."""
    assert should_delete_remote_event("from_gcal") is False


def test_delete_forbidden_none():
    assert should_delete_remote_event(None) is False


# ===== Critical #1: delete_gcal_event guard wired (from_gcal не трогаем) =====


@dataclass
class _DelEventLink:
    sync_direction: str
    google_event_id: str = "evt-x"
    is_active: bool = True


@dataclass
class _DelActivity:
    responsible_id: int | None = 1


class _DeleteSession:
    """Stub-сессия для delete_gcal_event.

    execute(select event_links) → возвращает заданные linki.
    execute(select GoogleCalendarLink) → user_link (через _get_link_for_user).
    get(Activity, ...) → activity.
    """

    def __init__(self, links: list[Any]) -> None:
        self._links = links
        self.flushed = False

    async def execute(self, stmt: Any) -> Any:
        sql = str(stmt).lower()
        if "google_calendar_event_links" in sql or "google_calendar_event_link" in sql:
            return _ScalarsResult(self._links)
        # _get_link_for_user → scalar_one_or_none
        return _OneOrNoneResult(object())  # любой не-None user_link

    async def get(self, model: Any, pk: Any) -> Any:
        return _DelActivity()

    async def flush(self) -> None:
        self.flushed = True


class _OneOrNoneResult:
    def __init__(self, value: Any) -> None:
        self._value = value

    def scalar_one_or_none(self) -> Any:
        return self._value


@pytest.mark.asyncio
async def test_delete_gcal_event_skips_from_gcal(monkeypatch):
    """from_gcal link: delete_event НЕ вызывается, link деактивируется."""
    from app.services import gcal_sync as svc

    called: list[str] = []

    async def fake_delete_event(session, ul, event_id):
        called.append(event_id)

    monkeypatch.setattr(svc, "delete_event", fake_delete_event)

    link = _DelEventLink(sync_direction="from_gcal", google_event_id="foreign")
    session = _DeleteSession([link])
    deleted = await svc.delete_gcal_event(session, 42)  # type: ignore[arg-type]

    assert called == []           # чужой календарь не трогали
    assert deleted == 0
    assert link.is_active is False  # link локально деактивирован


@pytest.mark.asyncio
async def test_delete_gcal_event_deletes_pushable(monkeypatch):
    """both/to_gcal link: delete_event вызывается, считаем deleted."""
    from app.services import gcal_sync as svc

    called: list[str] = []

    async def fake_delete_event(session, ul, event_id):
        called.append(event_id)

    monkeypatch.setattr(svc, "delete_event", fake_delete_event)

    link = _DelEventLink(sync_direction="both", google_event_id="ours")
    session = _DeleteSession([link])
    deleted = await svc.delete_gcal_event(session, 7)  # type: ignore[arg-type]

    assert called == ["ours"]
    assert deleted == 1
    assert link.is_active is False


# ============ A1: pull больше не импортирует внешние events ============


@dataclass
class FakeLink:
    user_id: int = 7
    calendar_id: str = "primary"


class _SessionShouldNotTouch:
    """Сессия, которая фейлит на любой вызов — гарантирует, что внешний
    event не приводит к созданию Activity/link (никаких add/flush/execute)."""

    async def execute(self, *args: Any, **kwargs: Any) -> Any:
        raise AssertionError("pull external event must not query the DB")

    def add(self, *args: Any, **kwargs: Any) -> None:
        raise AssertionError("pull external event must not add objects")

    async def flush(self) -> None:
        raise AssertionError("pull external event must not flush")

    async def get(self, *args: Any, **kwargs: Any) -> Any:
        raise AssertionError("pull external event must not load objects")


@pytest.mark.asyncio
async def test_pull_does_not_import_external_event():
    """Внешний event (без macro_activity_id, status active) → (0, 0) и
    никаких обращений к БД."""
    event = {
        "id": "ext-123",
        "status": "confirmed",
        "summary": "Чужая встреча",
        "start": {"dateTime": "2026-06-05T14:30:00Z"},
        "end": {"dateTime": "2026-06-05T15:00:00Z"},
        # нет extendedProperties.private.macro_activity_id
    }
    created, errors = await _process_pulled_event(
        _SessionShouldNotTouch(), FakeLink(), event,
    )
    assert (created, errors) == (0, 0)


@pytest.mark.asyncio
async def test_pull_external_event_without_id_noop():
    event = {"status": "confirmed", "summary": "no id"}
    created, errors = await _process_pulled_event(
        _SessionShouldNotTouch(), FakeLink(), event,
    )
    assert (created, errors) == (0, 0)


# ============ C: single-flight skip когда lock занят ============


class _LockBusySession:
    """Сессия, у которой pg_try_advisory_lock возвращает False (lock занят).

    Любой другой execute (выборка linkов, проход по user'ам) — провал теста:
    при занятом lock мы должны выйти ДО цикла."""

    def __init__(self) -> None:
        self.calls: list[str] = []

    async def execute(self, stmt: Any, params: Any = None) -> Any:
        sql = str(stmt)
        self.calls.append(sql)
        if "pg_try_advisory_lock" in sql:
            return _ScalarResult(False)
        if "pg_advisory_unlock" in sql:
            return _ScalarResult(True)
        raise AssertionError(
            f"lock busy → must skip before any other query, got: {sql}"
        )


class _ScalarResult:
    def __init__(self, value: Any) -> None:
        self._value = value

    def scalar(self) -> Any:
        return self._value


@pytest.mark.asyncio
async def test_sync_all_users_skips_when_lock_busy():
    """pg_try_advisory_lock → False → возвращаем пустой SyncResult без цикла."""
    session = _LockBusySession()
    result = await sync_all_users(session)  # type: ignore[arg-type]

    # Ни одного user'а не обработали.
    assert result.pulled_in == 0
    assert result.pushed_out == 0
    assert result.errors == 0
    # try-lock дёрнули; цикл (select sync_enabled links) — нет.
    assert any("pg_try_advisory_lock" in c for c in session.calls)
    assert not any("google_calendar_links" in c.lower() for c in session.calls)
    # Lock не получили → unlock НЕ зовём (иначе сняли бы чужой lock).
    assert not any("pg_advisory_unlock" in c for c in session.calls)


class _LockGrantedEmptySession:
    """Сессия, которая выдаёт advisory-lock и возвращает пустой список linkов.

    Цикл по user'ам не выполняется (linkов нет), но finally ДОЛЖЕН снять lock.
    """

    def __init__(self) -> None:
        self.calls: list[str] = []

    async def execute(self, stmt: Any, params: Any = None) -> Any:
        sql = str(stmt)
        self.calls.append(sql)
        if "pg_try_advisory_lock" in sql:
            return _ScalarResult(True)
        if "pg_advisory_unlock" in sql:
            return _ScalarResult(True)
        # select sync_enabled links → пусто
        return _ScalarsResult([])


class _ScalarsResult:
    def __init__(self, values: list[Any]) -> None:
        self._values = values

    def scalars(self) -> Any:
        return self

    def all(self) -> list[Any]:
        return self._values


@pytest.mark.asyncio
async def test_sync_all_users_unlocks_in_finally_when_lock_acquired():
    """Lock получен → в finally обязательно pg_advisory_unlock (W4)."""
    session = _LockGrantedEmptySession()
    result = await sync_all_users(session)  # type: ignore[arg-type]

    assert result.pushed_out == 0
    assert result.deleted_out == 0
    # try-lock дёрнули И unlock в finally дёрнули.
    assert any("pg_try_advisory_lock" in c for c in session.calls)
    assert any("pg_advisory_unlock" in c for c in session.calls)
    # unlock — последним (в finally, после select linkов).
    assert "pg_advisory_unlock" in session.calls[-1]


def test_should_sync_datetime_sanity():
    """Sanity: tz-aware datetime используется в helper'ах (защита от naive)."""
    assert datetime.now(UTC).tzinfo is not None


# ===== Critical: терминальные/закрытые Activity не синкаются (анти-дубль) =====
#
# После recovery 410 импортированных Activity заархивированы (status='rejected',
# is_closed=True, updated_at=now). При повторном включении sync_enabled они
# попадают в _push_modified_activities (kind=meeting, due_at задан, updated_at>=
# since). from_gcal event_links у них деактивированы → is_mirror_only=False →
# B-guard не блокирует → без guard'а Create-ветка запостила бы 410 дублей.
# Фикс: should_sync_activity возвращает False для done/rejected/is_closed.


@dataclass
class _SyncLink:
    sync_enabled: bool = True
    sync_meeting: bool = True
    sync_call: bool = True
    sync_only_with_time: bool = False


@dataclass
class _TaskActivity:
    """Activity-stub с полями status/is_closed (как реальная модель)."""
    kind: str = "meeting"
    due_at: datetime | None = datetime(2026, 6, 5, 14, 30, tzinfo=UTC)
    status: str = "new"
    is_closed: bool = False


def test_terminal_statuses_constant_matches_model():
    """done/rejected — единственные терминальные status (модель: new|in_progress
    |done|rejected). 'cancelled'/'completed' в Activity НЕТ."""
    assert TERMINAL_STATUSES == ("done", "rejected")
    # gcal_sync импортирует тот же кортеж из google_calendar.
    assert TERMINAL_STATUSES is TERMINAL_STATUSES_GC


def test_should_sync_false_for_rejected_meeting():
    """rejected meeting с реальным due_at → НЕ синкать (это и есть кейс 410)."""
    link = _SyncLink()
    activity = _TaskActivity(status="rejected")
    assert should_sync_activity(activity, link) is False


def test_should_sync_false_for_done_meeting():
    """done (выполненная) задача → НЕ синкать."""
    link = _SyncLink()
    activity = _TaskActivity(status="done")
    assert should_sync_activity(activity, link) is False


def test_should_sync_false_when_is_closed():
    """is_closed=True (финально закрыта постановщиком) → НЕ синкать,
    даже если status ещё активный (status='new')."""
    link = _SyncLink()
    activity = _TaskActivity(status="new", is_closed=True)
    assert should_sync_activity(activity, link) is False


def test_should_sync_false_rejected_and_closed():
    """Реальный кейс recovery: status='rejected' И is_closed=True → False."""
    link = _SyncLink()
    activity = _TaskActivity(status="rejected", is_closed=True)
    assert should_sync_activity(activity, link) is False


def test_should_sync_true_for_active_new_meeting():
    """Активная meeting (status='new', не закрыта) → синкать (True)."""
    link = _SyncLink()
    activity = _TaskActivity(status="new", is_closed=False)
    assert should_sync_activity(activity, link) is True


def test_should_sync_true_for_in_progress_meeting():
    """in_progress meeting → синкать (статус не терминальный)."""
    link = _SyncLink()
    activity = _TaskActivity(status="in_progress")
    assert should_sync_activity(activity, link) is True


def test_should_sync_true_for_active_call():
    """Активный call с временем → синкать."""
    link = _SyncLink(sync_call=True)
    activity = _TaskActivity(kind="call", status="new")
    assert should_sync_activity(activity, link) is True


def test_should_sync_terminal_overrides_otherwise_valid():
    """Терминальный статус перекрывает все прочие валидные условия
    (sync_enabled, kind, due_at, sync_meeting) — задача не синкается."""
    link = _SyncLink(sync_meeting=True, sync_only_with_time=True)
    activity = _TaskActivity(
        kind="meeting",
        due_at=datetime(2026, 6, 5, 14, 30, tzinfo=UTC),
        status="done",
    )
    assert should_sync_activity(activity, link) is False


# ===== Третья проблема (conflict-safe pull): reactivate vs insert =====
#
# uq_gcal_event_activity_cal — на (activity_id, google_calendar_id), БЕЗ
# is_active. После recovery 410 событий в Google несут наш macro_activity_id
# (проставлен при overwrite-инциденте), а их from_gcal-линки заархивированы
# (is_active=False, строка физически осталась). На pull они идут в ветку
# macro_id. Раньше _get_event_link фильтровал is_active=True → None → слепой
# INSERT → IntegrityError по unique-констрейнту → PendingRollbackError каскадом.
# Фикс: брать ЛЮБУЮ строку (без фильтра is_active) и переиспользовать её.


# ---- pure-предикат reuse_existing_link ----


@dataclass
class _AnyLinkRow:
    is_active: bool = False
    etag: str | None = None
    google_event_id: str = "old-evt"
    last_synced_at: datetime | None = None


def test_reuse_existing_link_true_when_row_present_inactive():
    """Деактивированная строка существует → переиспользуем (НЕ insert)."""
    assert reuse_existing_link(_AnyLinkRow(is_active=False)) is True


def test_reuse_existing_link_true_when_row_present_active():
    """Активная строка существует → тоже переиспользуем (refresh etag)."""
    assert reuse_existing_link(_AnyLinkRow(is_active=True)) is True


def test_reuse_existing_link_false_when_none():
    """Ни одной строки по паре → insert (None)."""
    assert reuse_existing_link(None) is False


# ---- интеграция: ветка macro_id с существующей неактивной строкой ----


@dataclass
class _PullLink:
    user_id: int = 1
    calendar_id: str = "primary"


class _MacroBranchSession:
    """Stub-сессия для _process_pulled_event (ветка macro_id).

    execute(select GoogleCalendarEventLink) → возвращает заранее заданную
    строку (или None). НЕ фильтрует по SQL — отдаём то, что положили, чтобы
    проверить поведение «нашли строку → reactivate, не insert».

    add(...) — фиксируем факт INSERT (его быть НЕ должно при реактивации).
    """

    def __init__(self, row: Any) -> None:
        self._row = row
        self.added: list[Any] = []
        self.flushed = False

    async def execute(self, stmt: Any) -> Any:
        return _OneOrNoneResult(self._row)

    def add(self, obj: Any) -> None:
        self.added.append(obj)

    async def flush(self) -> None:
        self.flushed = True


def _macro_event(macro_id: int, evt_id: str = "evt-new", etag: str = "etag-2"):
    return {
        "id": evt_id,
        "status": "confirmed",
        "etag": etag,
        "extendedProperties": {"private": {"macro_activity_id": str(macro_id)}},
    }


@pytest.mark.asyncio
async def test_pull_macro_id_reactivates_inactive_row_no_insert():
    """Кейс 410: событие с нашим macro_id, существует НЕактивная строка link
    по той же паре → реактивируем + обновляем etag, НЕ делаем INSERT.
    Это и убирает IntegrityError по uq_gcal_event_activity_cal."""
    row = _AnyLinkRow(
        is_active=False, etag="old-etag", google_event_id="evt-old",
    )
    session = _MacroBranchSession(row)
    created, errors = await _process_pulled_event(
        session, _PullLink(), _macro_event(42, evt_id="evt-new", etag="etag-2"),
    )

    # Реактивация — не создание: created=0, никаких add().
    assert (created, errors) == (0, 0)
    assert session.added == []          # INSERT НЕ было
    assert row.is_active is True        # строка реактивирована
    assert row.etag == "etag-2"         # etag обновлён из события
    assert row.google_event_id == "evt-new"  # id синхронизирован
    assert session.flushed is True


@pytest.mark.asyncio
async def test_pull_macro_id_updates_active_row_no_insert():
    """Активная строка существует → обновляем etag, без INSERT и без смены
    is_active (остаётся True)."""
    row = _AnyLinkRow(is_active=True, etag="old", google_event_id="evt-old")
    session = _MacroBranchSession(row)
    created, errors = await _process_pulled_event(
        session, _PullLink(), _macro_event(7, evt_id="evt-7", etag="fresh"),
    )

    assert (created, errors) == (0, 0)
    assert session.added == []
    assert row.is_active is True
    assert row.etag == "fresh"


@pytest.mark.asyncio
async def test_pull_macro_id_inserts_when_no_row():
    """Нет ни одной строки по паре → создаём новую (legit recreate)."""
    session = _MacroBranchSession(None)
    created, errors = await _process_pulled_event(
        session, _PullLink(), _macro_event(99, evt_id="evt-99", etag="e"),
    )

    assert (created, errors) == (1, 0)
    assert len(session.added) == 1          # ровно один INSERT
    new_link = session.added[0]
    assert new_link.activity_id == 99
    assert new_link.google_event_id == "evt-99"
    assert new_link.is_active is True
    assert session.flushed is True
