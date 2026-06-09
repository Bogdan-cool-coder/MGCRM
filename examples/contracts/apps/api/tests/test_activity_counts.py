"""P0 hotfix — счётчики задач для sidebar badges + preset sidebar.

Покрываем:
- Регистрация endpoints `GET /activities/my-open-count` и
  `GET /activities/counts-by-preset` (порядок ДО `/{activity_id}` —
  иначе FastAPI парсит как int и возвращает 422).
- Pydantic-схемы `OpenCountOut` и `PresetCountsOut`: дефолты, типы, ключи
  синхронизированы с фронтом (apps/web/src/components/Tasks/TaskPresetSidebar.tsx
  PresetCounts interface).
- Все query params endpoints — optional (фронт не передаёт ничего).

Без БД-фикстуры: SQL-логика проверяется только на «компилируется» / route
registration. Реальные счётчики покрываются на интеграционном уровне в QA.
"""
from __future__ import annotations

import pytest

from app.routers.activities import (
    OpenCountOut,
    PresetCountsOut,
    router,
)


# ============ Schemas ============


def test_open_count_out_minimal():
    """OpenCountOut: только count, тип int."""
    out = OpenCountOut(count=0)
    assert out.count == 0


def test_open_count_out_positive():
    out = OpenCountOut(count=42)
    assert out.count == 42


def test_open_count_out_requires_count():
    """count — обязательное поле (Pydantic должен fail без него)."""
    with pytest.raises(Exception):
        OpenCountOut()  # type: ignore[call-arg]


def test_preset_counts_out_defaults_all_zero():
    """PresetCountsOut: все поля имеют дефолт 0, можно создать без аргументов."""
    out = PresetCountsOut()
    assert out.pinned == 0
    assert out.overdue == 0
    assert out.done_unclosed == 0
    assert out.today == 0
    assert out.this_week == 0
    assert out.next_week == 0
    assert out.future == 0
    assert out.mine == 0
    assert out.my_orders == 0


def test_preset_counts_out_fields_match_frontend():
    """Ключи PresetCountsOut должны 1:1 совпадать с PresetCounts на фронте.

    См. apps/web/src/components/Tasks/TaskPresetSidebar.tsx (PresetCounts).
    Фронт ожидает ровно эти 9 ключей; если backend вернёт лишний — TS не
    упадёт, но любой missing key → undefined в badge → бесшумная регрессия.
    """
    expected_keys = {
        "pinned",
        "overdue",
        "done_unclosed",
        "today",
        "this_week",
        "next_week",
        "future",
        "mine",
        "my_orders",
    }
    assert set(PresetCountsOut.model_fields.keys()) == expected_keys


def test_preset_counts_out_accepts_all_values():
    """Можно передать все 9 значений explicitly."""
    out = PresetCountsOut(
        pinned=1, overdue=2, done_unclosed=3, today=4, this_week=5,
        next_week=6, future=7, mine=8, my_orders=9,
    )
    assert out.pinned == 1
    assert out.my_orders == 9


# ============ Route registration ============


def _route_paths_methods() -> list[tuple[str, set[str]]]:
    """[(path, methods)] — для проверки порядка/наличия маршрутов."""
    return [
        (r.path, r.methods if hasattr(r, "methods") else set())
        for r in router.routes
    ]


def test_my_open_count_endpoint_registered():
    """GET /activities/my-open-count существует.

    router.routes хранит ПОЛНЫЙ путь с учётом prefix='/activities'.
    """
    routes = _route_paths_methods()
    paths = [p for p, _ in routes]
    assert "/activities/my-open-count" in paths, (
        f"/activities/my-open-count не зарегистрирован. routes: {paths}"
    )
    # И это GET.
    for p, methods in routes:
        if p == "/activities/my-open-count":
            assert "GET" in methods


def test_counts_by_preset_endpoint_registered():
    """GET /activities/counts-by-preset существует."""
    routes = _route_paths_methods()
    paths = [p for p, _ in routes]
    assert "/activities/counts-by-preset" in paths, (
        f"/activities/counts-by-preset не зарегистрирован. routes: {paths}"
    )
    for p, methods in routes:
        if p == "/activities/counts-by-preset":
            assert "GET" in methods


def test_count_endpoints_declared_before_activity_id_route():
    """КРИТИЧНО: /my-open-count и /counts-by-preset должны идти ДО /{activity_id}.

    FastAPI матчит routes в порядке регистрации. Если /{activity_id} объявлен
    раньше — он перехватит запрос и попытается парсить 'my-open-count' как
    int → 422 Unprocessable Entity. Этот баг был в QA-аудите P0.
    """
    paths_in_order = [r.path for r in router.routes]

    try:
        # Первое появление /{activity_id} (это GET по id, до PATCH/DELETE).
        i_activity_id = paths_in_order.index("/activities/{activity_id}")
    except ValueError:
        pytest.fail(
            f"/activities/{{activity_id}} GET не найден. routes: {paths_in_order}"
        )

    try:
        i_my_open = paths_in_order.index("/activities/my-open-count")
        i_counts = paths_in_order.index("/activities/counts-by-preset")
    except ValueError as e:
        pytest.fail(f"Counter endpoints missing: {e}. routes: {paths_in_order}")

    assert i_my_open < i_activity_id, (
        "my-open-count must be registered BEFORE /{activity_id} or it'll 422"
    )
    assert i_counts < i_activity_id, (
        "counts-by-preset must be registered BEFORE /{activity_id} or it'll 422"
    )


def test_count_endpoints_have_no_required_query_params():
    """Endpoints не должны требовать query params — frontend вызывает без них.

    Проверяем что у route нет required `query` parameters (только path/header/
    body/dependency-injected). current_user и session — Depends-инъекции, они
    не query.
    """
    from fastapi.routing import APIRoute

    counter_paths = {
        "/activities/my-open-count",
        "/activities/counts-by-preset",
    }
    found = 0
    for r in router.routes:
        if not isinstance(r, APIRoute):
            continue
        if r.path not in counter_paths:
            continue
        found += 1
        for dep in r.dependant.query_params:
            # Если в endpoint есть required query — это будущая регрессия.
            # Pydantic v2 ModelField: используем field_info.is_required().
            assert not dep.field_info.is_required(), (
                f"{r.path} has required query param {dep.name!r} — "
                f"frontend вызывает endpoint без params, должно быть optional"
            )
    assert found == len(counter_paths), (
        f"Не найдены оба counter-endpoint. found={found}, expected={len(counter_paths)}"
    )
