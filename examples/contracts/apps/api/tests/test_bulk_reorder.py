"""Tech Sprint Фаза 0 (задача 5): pure-function тесты для validate_reorder_payload.

Без БД — apply_reorder_simple/unique тестируются через интеграцию (отдельно).
Здесь покрываем валидатор payload'а: формат, дубликаты, edge cases.
"""
from __future__ import annotations

import pytest
from fastapi import HTTPException

from app.services.bulk_reorder import validate_reorder_payload


def test_validate_simple_payload():
    """3 элемента, корректно — возвращает sorted by order pairs."""
    items = [
        {"id": 3, "sort_order": 2},
        {"id": 1, "sort_order": 0},
        {"id": 2, "sort_order": 1},
    ]
    pairs = validate_reorder_payload(items, order_field="sort_order")
    assert pairs == [(1, 0), (2, 1), (3, 2)]


def test_validate_empty_payload_raises():
    """Пустой payload → 422."""
    with pytest.raises(HTTPException) as exc:
        validate_reorder_payload([], order_field="sort_order")
    assert exc.value.status_code == 422


def test_validate_missing_id_raises():
    """Нет 'id' → 422."""
    with pytest.raises(HTTPException) as exc:
        validate_reorder_payload([{"sort_order": 0}], order_field="sort_order")
    assert exc.value.status_code == 422
    assert "id" in str(exc.value.detail)


def test_validate_missing_order_raises():
    """Нет order_field → 422."""
    with pytest.raises(HTTPException) as exc:
        validate_reorder_payload(
            [{"id": 1, "wrong_field": 0}], order_field="sort_order",
        )
    assert exc.value.status_code == 422
    assert "sort_order" in str(exc.value.detail)


def test_validate_duplicate_ids_raises():
    """Дубль id → 422."""
    with pytest.raises(HTTPException) as exc:
        validate_reorder_payload(
            [{"id": 1, "sort_order": 0}, {"id": 1, "sort_order": 1}],
            order_field="sort_order",
        )
    assert exc.value.status_code == 422
    assert "id" in str(exc.value.detail).lower()


def test_validate_duplicate_orders_raises():
    """Дубль sort_order → 422 (порядок неоднозначен)."""
    with pytest.raises(HTTPException) as exc:
        validate_reorder_payload(
            [{"id": 1, "sort_order": 0}, {"id": 2, "sort_order": 0}],
            order_field="sort_order",
        )
    assert exc.value.status_code == 422


def test_validate_negative_id_raises():
    """id <= 0 → 422."""
    with pytest.raises(HTTPException) as exc:
        validate_reorder_payload(
            [{"id": 0, "sort_order": 0}], order_field="sort_order",
        )
    assert exc.value.status_code == 422
    with pytest.raises(HTTPException):
        validate_reorder_payload(
            [{"id": -1, "sort_order": 0}], order_field="sort_order",
        )


def test_validate_negative_order_raises():
    """sort_order < 0 → 422."""
    with pytest.raises(HTTPException) as exc:
        validate_reorder_payload(
            [{"id": 1, "sort_order": -1}], order_field="sort_order",
        )
    assert exc.value.status_code == 422


def test_validate_non_int_id_raises():
    """id строкой → 422."""
    with pytest.raises(HTTPException):
        validate_reorder_payload(
            [{"id": "abc", "sort_order": 0}], order_field="sort_order",
        )


def test_validate_non_int_order_raises():
    """sort_order строкой → 422."""
    with pytest.raises(HTTPException):
        validate_reorder_payload(
            [{"id": 1, "sort_order": "0"}], order_field="sort_order",
        )


def test_validate_zero_order_ok():
    """sort_order=0 — валидный (boundary)."""
    pairs = validate_reorder_payload(
        [{"id": 1, "sort_order": 0}], order_field="sort_order",
    )
    assert pairs == [(1, 0)]


def test_validate_non_dict_element_raises():
    """Не-dict элемент в массиве → 422."""
    with pytest.raises(HTTPException):
        validate_reorder_payload(
            ["not-a-dict"], order_field="sort_order",  # type: ignore[list-item]
        )


def test_validate_custom_order_field():
    """order_field='order_index' тоже работает."""
    pairs = validate_reorder_payload(
        [
            {"id": 10, "order_index": 5},
            {"id": 11, "order_index": 0},
        ],
        order_field="order_index",
    )
    assert pairs == [(11, 0), (10, 5)]


def test_validate_large_payload():
    """100 элементов — не должно быть проблемы."""
    items = [{"id": i, "sort_order": i - 1} for i in range(1, 101)]
    pairs = validate_reorder_payload(items, order_field="sort_order")
    assert len(pairs) == 100
    # Уже отсортированы по order — первая пара (1, 0)
    assert pairs[0] == (1, 0)
    assert pairs[-1] == (100, 99)


def test_validate_returns_sorted_by_order():
    """Возвращаемый list всегда отсортирован по order_field, не по input."""
    items = [
        {"id": 100, "sort_order": 99},
        {"id": 50, "sort_order": 0},
        {"id": 75, "sort_order": 50},
    ]
    pairs = validate_reorder_payload(items, order_field="sort_order")
    assert pairs == [(50, 0), (75, 50), (100, 99)]
