"""Wave 2a — чистый unit-тест валидации dashboard_config. Без БД fixture."""
from __future__ import annotations

import pytest

from app.routers.me import (
    _DASHBOARD_CONFIG_MAX_BYTES,
    _DASHBOARD_CONFIG_MAX_DEPTH,
    _json_depth,
    validate_dashboard_config,
)


def test_accepts_object():
    validate_dashboard_config({"widgets": [{"id": "deals", "visible": True, "order": 0}]})


def test_accepts_array():
    validate_dashboard_config([{"id": "deals", "visible": True, "order": 0}])


def test_accepts_empty_object_and_array():
    validate_dashboard_config({})
    validate_dashboard_config([])


@pytest.mark.parametrize("bad", ["string", 42, 3.14, True, None])
def test_rejects_primitives(bad):
    with pytest.raises(ValueError):
        validate_dashboard_config(bad)


def test_rejects_oversized():
    # массив из множества длинных id, заведомо > 32КБ
    big = [{"id": "x" * 100, "visible": True, "order": i} for i in range(1000)]
    with pytest.raises(ValueError):
        validate_dashboard_config(big)


def test_size_limit_constant():
    assert _DASHBOARD_CONFIG_MAX_BYTES == 32 * 1024


# ============ B4 — deep-nested JSON-bomb (depth limit) ============


def test_json_depth_flat():
    # _json_depth считает и листовой скаляр как уровень: контейнер на 1, его
    # скалярное значение — на 2. Пустые контейнеры остаются на своём уровне.
    assert _json_depth({"a": 1, "b": [1, 2, 3]}, 32) == 3  # dict→list→скаляр
    assert _json_depth([], 32) == 1
    assert _json_depth({}, 32) == 1
    assert _json_depth([1, 2, 3], 32) == 2  # list→скаляр


def test_json_depth_nested():
    # [[[[]]]] — 4 уровня списков, самый внутренний пустой
    assert _json_depth([[[[]]]], 32) == 4
    # dict→dict→dict→скаляр = 4
    assert _json_depth({"a": {"b": {"c": 1}}}, 32) == 4


def test_json_depth_early_exit_on_hostile_input():
    # Глубоко вложенный список не должен разворачиваться целиком — обход
    # прекращается, как только глубина превысила лимит.
    bomb: object = []
    cur = bomb
    for _ in range(5000):
        nxt: list = []
        cur.append(nxt)  # type: ignore[union-attr]
        cur = nxt
    depth = _json_depth(bomb, _DASHBOARD_CONFIG_MAX_DEPTH)
    assert depth > _DASHBOARD_CONFIG_MAX_DEPTH


def test_rejects_deep_nested_under_size_cap():
    # Глубоко вложенный, но КОМПАКТНЫЙ конфиг — обходит 32КБ-лимит, но должен
    # отлететь по глубине (это и есть JSON-bomb из аудита B4).
    bomb: object = []
    cur = bomb
    for _ in range(200):
        nxt: list = []
        cur.append(nxt)  # type: ignore[union-attr]
        cur = nxt
    import json as _json

    assert len(_json.dumps(bomb).encode()) < _DASHBOARD_CONFIG_MAX_BYTES
    with pytest.raises(ValueError, match="глубоко"):
        validate_dashboard_config(bomb)


def test_accepts_config_at_depth_limit():
    # Конфиг ровно на границе глубины — должен пройти.
    cfg: object = "leaf"
    for _ in range(_DASHBOARD_CONFIG_MAX_DEPTH - 1):
        cfg = [cfg]
    # depth == _DASHBOARD_CONFIG_MAX_DEPTH (граница включительно)
    validate_dashboard_config(cfg)


def test_depth_limit_constant():
    assert _DASHBOARD_CONFIG_MAX_DEPTH == 32
