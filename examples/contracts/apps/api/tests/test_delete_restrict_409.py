"""P2 follow-up — graceful 409 on RESTRICT'd deletes.

Миграция 0106 поставила ON DELETE RESTRICT на ряд FK (deals.stage_id,
contracts.counterparty_id, ...). Без обработки удаление родителя со связанными
строками отдавало бы 500. Эти тесты проверяют, что delete-эндпоинты:

  - при наличии зависимых строк → HTTPException 409 (pre-check),
  - при гонке (delete/commit бросает IntegrityError) → 409 (try/except),
  - при отсутствии зависимостей → удаляют чисто (delete+commit вызваны).

Тесты — без реальной БД: подменяем AsyncSession лёгкими фейками, которые
возвращают нужные счётчики и при необходимости бросают IntegrityError.
"""
from __future__ import annotations

import pytest
from fastapi import HTTPException, status
from sqlalchemy.exc import IntegrityError

from app.routers.counterparties import delete_counterparty
from app.routers.pipelines import delete_stage


class _Result:
    def __init__(self, value):
        self._value = value

    def scalar_one(self):
        return self._value

    def scalar_one_or_none(self):
        return self._value


class _FakeSession:
    """Возвращает заранее заданные результаты execute() по порядку.

    delete()/commit() считаются; commit может быть настроен бросить IntegrityError
    для эмуляции срабатывания RESTRICT при гонке.
    """

    def __init__(self, results: list, *, commit_raises: bool = False):
        self._results = list(results)
        self.commit_raises = commit_raises
        self.deleted: list = []
        self.committed = False
        self.rolled_back = False

    async def execute(self, *_a, **_k):
        return self._results.pop(0)

    async def delete(self, obj):
        self.deleted.append(obj)

    async def commit(self):
        if self.commit_raises:
            raise IntegrityError("stmt", {}, Exception("RESTRICT"))
        self.committed = True

    async def rollback(self):
        self.rolled_back = True


# ---------------------------------------------------------------- stage delete


async def test_delete_stage_with_deals_returns_409():
    # 1) этап найден, 2) child_count=0, 3) deals used=5
    session = _FakeSession([_Result(object()), _Result(0), _Result(5)])
    with pytest.raises(HTTPException) as exc:
        await delete_stage(pid=1, sid=2, current_user=None, session=session)
    assert exc.value.status_code == status.HTTP_409_CONFLICT
    assert "связанные сделки" in exc.value.detail
    assert session.deleted == []  # ничего не удалили


async def test_delete_stage_with_substages_returns_409():
    # 1) этап найден, 2) child_count=3 → стоп до подсчёта сделок
    session = _FakeSession([_Result(object()), _Result(3)])
    with pytest.raises(HTTPException) as exc:
        await delete_stage(pid=1, sid=2, current_user=None, session=session)
    assert exc.value.status_code == status.HTTP_409_CONFLICT
    assert "подстатус" in exc.value.detail


async def test_delete_stage_not_found_returns_404():
    session = _FakeSession([_Result(None)])
    with pytest.raises(HTTPException) as exc:
        await delete_stage(pid=1, sid=2, current_user=None, session=session)
    assert exc.value.status_code == 404


async def test_delete_stage_clean_when_no_dependents():
    stage = object()
    session = _FakeSession([_Result(stage), _Result(0), _Result(0)])
    result = await delete_stage(pid=1, sid=2, current_user=None, session=session)
    assert result is None
    assert session.deleted == [stage]
    assert session.committed is True


async def test_delete_stage_race_integrity_error_returns_409():
    # Pre-check проходит (0 сделок), но commit падает на RESTRICT (гонка).
    stage = object()
    session = _FakeSession(
        [_Result(stage), _Result(0), _Result(0)], commit_raises=True
    )
    with pytest.raises(HTTPException) as exc:
        await delete_stage(pid=1, sid=2, current_user=None, session=session)
    assert exc.value.status_code == status.HTTP_409_CONFLICT
    assert "связанные сделки" in exc.value.detail
    assert session.rolled_back is True


# --------------------------------------------------------- counterparty delete


async def test_delete_counterparty_with_contracts_returns_409():
    session = _FakeSession([_Result(object()), _Result(4)])
    with pytest.raises(HTTPException) as exc:
        await delete_counterparty(cp_id=1, current_user=None, session=session)
    assert exc.value.status_code == status.HTTP_409_CONFLICT
    assert "договоры" in exc.value.detail
    assert "(4)" in exc.value.detail


async def test_delete_counterparty_not_found_returns_404():
    session = _FakeSession([_Result(None)])
    with pytest.raises(HTTPException) as exc:
        await delete_counterparty(cp_id=1, current_user=None, session=session)
    assert exc.value.status_code == 404
