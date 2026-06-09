"""Regression-тест: все литералы sync_direction в коде влезают в объявленную
длину колонки GoogleCalendarEventLink.sync_direction.

Прод-инцидент (июнь 2026, Эпик 24.2): колонка была VARCHAR(8), а cron вставлял
'from_gcal' (9 символов) → StringDataRightTruncationError, GCal 2-way sync падал.
Hotfix 0071 расширил до VARCHAR(20).

Тест ловит проблему ДО деплоя: если кто-то добавит значение sync_direction
длиннее объявленной длины колонки — pytest упадёт локально и в CI. Чистая
функция, без DB fixture — статически парсит исходники.
"""

from __future__ import annotations

import ast
from pathlib import Path

from app.models import GoogleCalendarEventLink

API_ROOT = Path(__file__).parent.parent / "app"


def _declared_length() -> int:
    """Длина VARCHAR из модели — single source of truth для лимита."""
    col = GoogleCalendarEventLink.__table__.c.sync_direction
    length = col.type.length
    assert length is not None, "sync_direction должна быть String с явной длиной"
    return length


def _collect_sync_direction_literals() -> list[tuple[str, str]]:
    """Все строковые литералы, присваиваемые ключевому аргументу или атрибуту
    sync_direction в app/. Возвращает list[(file, value)].
    """
    found: list[tuple[str, str]] = []
    for py in API_ROOT.rglob("*.py"):
        tree = ast.parse(py.read_text(encoding="utf-8"), filename=str(py))
        for node in ast.walk(tree):
            # keyword: sync_direction="from_gcal"
            if isinstance(node, ast.Call):
                for kw in node.keywords:
                    if kw.arg == "sync_direction" and isinstance(
                        kw.value, ast.Constant
                    ) and isinstance(kw.value.value, str):
                        found.append((py.name, kw.value.value))
            # assignment: obj.sync_direction = "both"
            if isinstance(node, ast.Assign):
                for tgt in node.targets:
                    if (
                        isinstance(tgt, ast.Attribute)
                        and tgt.attr == "sync_direction"
                        and isinstance(node.value, ast.Constant)
                        and isinstance(node.value.value, str)
                    ):
                        found.append((py.name, node.value.value))
    return found


def test_all_sync_direction_literals_fit_column() -> None:
    limit = _declared_length()
    literals = _collect_sync_direction_literals()
    assert literals, "Не найдено ни одного литерала sync_direction — путь сломан?"

    violations = [
        (filename, value, len(value))
        for filename, value in literals
        if len(value) > limit
    ]
    assert not violations, (
        f"Литералы sync_direction превышают VARCHAR({limit}) "
        f"(GoogleCalendarEventLink.sync_direction):\n"
        + "\n".join(
            f"  {filename}: '{value}' = {length} символов (max {limit})"
            for filename, value, length in violations
        )
    )


def test_sync_direction_column_fits_known_values() -> None:
    """Документированные направления синхронизации помещаются в колонку."""
    limit = _declared_length()
    for value in ("both", "to_gcal", "from_gcal"):
        assert len(value) <= limit, (
            f"'{value}' ({len(value)}) не влезает в VARCHAR({limit})"
        )
