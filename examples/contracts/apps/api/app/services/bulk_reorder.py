"""Tech Sprint Фаза 0 (задача 5): bulk-reorder helper.

Pure-function валидация payload'а + универсальная функция применения новых
sort_order/order_index на коллекцию ORM-объектов в одной транзакции.

Используется во всех 6 ORM-based bulk-reorder endpoint'ах (8 total — но 2 из них
работают с JSON-массивами, не с FK-связанными моделями: Sequence steps_json и
CourseLesson content_blocks. Они переупорядочиваются через PATCH массива
целиком).

Формат payload (body для PATCH):
    [
        {"id": 11, "sort_order": 0},
        {"id": 12, "sort_order": 1},
        {"id": 13, "sort_order": 2}
    ]

Семантика:
- ID должны принадлежать одной коллекции (вне scope — 400).
- В payload должны быть ВСЕ id коллекции (иначе 400) — это защита от частичного
  reorder, который оставил бы половину объектов с неактуальным sort_order.
- sort_order значения должны быть уникальны (иначе 422).
- Уникальность order_index в БД (UniqueConstraint в CourseModule / CourseLesson)
  требует двухфазного UPDATE: сначала временно сдвигаем все в высокий range,
  потом проставляем целевые значения. Для sort_order (Integer без UniqueConstraint)
  достаточно прямого UPDATE.

Для CourseModule/CourseLesson/LessonQuizQuestion order_index имеет UNIQUE,
используем алгоритм tmp-shift (через offset +10000).
"""
from __future__ import annotations

from typing import Any, Sequence

from fastapi import HTTPException
from sqlalchemy import update
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select


def validate_reorder_payload(
    items: list[dict[str, Any]], order_field: str = "sort_order",
) -> list[tuple[int, int]]:
    """Pure-function валидация payload reorder.

    Возвращает list[(id, order_value)] отсортированный по новому order.

    Бросает HTTPException 422 если:
    - payload пустой;
    - элемент без id или без order_field;
    - id или order не int / отрицательные;
    - дублирующиеся id;
    - дублирующиеся order_value.
    """
    if not items:
        raise HTTPException(422, "Пустой payload reorder")
    seen_ids: set[int] = set()
    seen_orders: set[int] = set()
    pairs: list[tuple[int, int]] = []
    for i, item in enumerate(items):
        if not isinstance(item, dict):
            raise HTTPException(422, f"Элемент #{i} не объект")
        if "id" not in item:
            raise HTTPException(422, f"Элемент #{i} без 'id'")
        if order_field not in item:
            raise HTTPException(422, f"Элемент #{i} без '{order_field}'")
        rid = item["id"]
        ord_val = item[order_field]
        if not isinstance(rid, int) or rid <= 0:
            raise HTTPException(422, f"Элемент #{i}: 'id' должен быть положительным int")
        if not isinstance(ord_val, int) or ord_val < 0:
            raise HTTPException(
                422,
                f"Элемент #{i}: '{order_field}' должен быть неотрицательным int",
            )
        if rid in seen_ids:
            raise HTTPException(422, f"Дублирующий id={rid} в payload")
        if ord_val in seen_orders:
            raise HTTPException(
                422,
                f"Дублирующий {order_field}={ord_val} в payload — порядок неоднозначен",
            )
        seen_ids.add(rid)
        seen_orders.add(ord_val)
        pairs.append((rid, ord_val))
    pairs.sort(key=lambda p: p[1])
    return pairs


async def apply_reorder_simple(
    session: AsyncSession,
    model: type,
    pairs: Sequence[tuple[int, int]],
    *,
    scope_filter: Any = None,
    order_field: str = "sort_order",
) -> int:
    """Применить reorder без UNIQUE constraint на order_field (sort_order).

    pairs: [(id, new_order), ...] — отсортированный по new_order.
    scope_filter: SQLAlchemy expression для дополнительного WHERE (например,
        Model.parent_id == X) — защита что мы не reorderим чужие записи.

    1. Проверяем что ВСЕ id из pairs существуют в БД и удовлетворяют scope_filter.
       Если не все — 400.
    2. UPDATE one-by-one. Для Integer без UNIQUE — это безопасно.

    Возвращает число обновлённых.
    """
    ids = [p[0] for p in pairs]
    stmt = select(model.id).where(model.id.in_(ids))
    if scope_filter is not None:
        stmt = stmt.where(scope_filter)
    found_ids = set(
        (await session.execute(stmt)).scalars().all()
    )
    missing = set(ids) - found_ids
    if missing:
        raise HTTPException(
            400,
            f"Не все объекты найдены или вне scope: {sorted(missing)}",
        )
    # Apply UPDATEs (one query per row — но число id обычно 5-50)
    for rid, ord_val in pairs:
        await session.execute(
            update(model).where(model.id == rid).values({order_field: ord_val})
        )
    return len(pairs)


async def apply_reorder_unique(
    session: AsyncSession,
    model: type,
    pairs: Sequence[tuple[int, int]],
    *,
    scope_filter: Any,
    order_field: str = "order_index",
    tmp_offset: int = 100_000,
) -> int:
    """Применить reorder с UNIQUE constraint на (scope, order_field).

    Двухфазный UPDATE для обхода UNIQUE: сначала шифтим существующие order'а
    в tmp_offset+id (гарантированно уникально и вне target range), потом
    проставляем целевые значения.

    Используется для CourseModule (UNIQUE course_id+order_index), CourseLesson
    (UNIQUE module_id+order_index), LessonQuizQuestion (нет UNIQUE, но
    индекс по lesson_id+order_index — стабильнее с tmp shift).

    scope_filter обязателен — без него можно ненароком пошифтить чужие записи.
    """
    ids = [p[0] for p in pairs]
    stmt = select(model.id).where(model.id.in_(ids))
    stmt = stmt.where(scope_filter)
    found_ids = set(
        (await session.execute(stmt)).scalars().all()
    )
    missing = set(ids) - found_ids
    if missing:
        raise HTTPException(
            400,
            f"Не все объекты найдены или вне scope: {sorted(missing)}",
        )
    # Phase 1: shift all to tmp_offset + id (гарантирует уникальность и нет
    # пересечений с target range, если target < tmp_offset).
    for rid, _ in pairs:
        await session.execute(
            update(model)
            .where(model.id == rid)
            .values({order_field: tmp_offset + rid})
        )
    await session.flush()
    # Phase 2: проставить целевые
    for rid, ord_val in pairs:
        await session.execute(
            update(model).where(model.id == rid).values({order_field: ord_val})
        )
    return len(pairs)
