"""Tech Sprint Фаза 0 (задача 3): pure-function тесты для shuffle вопросов quiz'а.

Проверяем:
1. shuffle стабилен в рамках одной attempt (seed = attempt.id) — двойной вызов
   возвращает одинаковый порядок.
2. seed разный → результаты могут отличаться (на >=2 элементах вероятность
   несовпадения почти 1.0, но статистически проверим хотя бы для одной пары).
3. randomize_questions=False → возвращает list(questions) как есть.
4. randomize_questions=True → меняет порядок (или оставляет тот же, если
   shuffle случайно вернул тот же — проверяем что хотя бы один из 5 разных
   seed'ов даёт другой порядок).
5. Пустой список → пустой ответ.
6. Не модифицирует входной list (immutable).
"""
from __future__ import annotations

from dataclasses import dataclass

from app.services.onboarding.quiz import (
    randomize_questions,
    shuffle_questions_for_attempt,
)


@dataclass
class FakeLesson:
    """Минимальный mock для CourseLesson — у нас shuffle проверяет только флаг."""

    randomize_questions: bool


def test_randomize_questions_stable_with_same_seed():
    """Один и тот же seed → один и тот же порядок (повторный вызов идемпотентен)."""
    items = ["a", "b", "c", "d", "e", "f", "g"]
    out1 = randomize_questions(items, seed=42)
    out2 = randomize_questions(items, seed=42)
    assert out1 == out2


def test_randomize_questions_different_seeds_can_differ():
    """На 7 элементах хотя бы один из 5 разных seed'ов даёт другой порядок."""
    items = ["a", "b", "c", "d", "e", "f", "g"]
    base = randomize_questions(items, seed=1)
    # Перебираем несколько seed'ов — хотя бы один должен дать отличный порядок.
    different = False
    for s in (2, 3, 5, 100, 9999):
        if randomize_questions(items, seed=s) != base:
            different = True
            break
    assert different, "Все seeds дали одинаковый порядок — это статистически невероятно"


def test_randomize_questions_keeps_all_elements():
    """Shuffle не теряет и не дублирует элементы."""
    items = list(range(20))
    out = randomize_questions(items, seed=7)
    assert sorted(out) == items
    assert len(out) == len(items)


def test_randomize_questions_does_not_mutate_input():
    """shuffle возвращает НОВЫЙ список, входной не меняется."""
    items = ["a", "b", "c", "d"]
    items_copy = list(items)
    _ = randomize_questions(items, seed=1)
    assert items == items_copy, "randomize_questions должен быть pure — input не меняется"


def test_randomize_questions_empty_input():
    """Пустой список → пустой результат."""
    assert randomize_questions([], seed=1) == []


def test_randomize_questions_single_element():
    """Один элемент → возвращается как есть."""
    assert randomize_questions(["only"], seed=1) == ["only"]


# ============ shuffle_questions_for_attempt ============


def test_shuffle_skip_when_lesson_flag_false():
    """Если lesson.randomize_questions=False — возвращаем список как есть."""
    lesson = FakeLesson(randomize_questions=False)
    items = ["a", "b", "c", "d", "e"]
    out = shuffle_questions_for_attempt(items, lesson, attempt_id=42)
    assert out == items


def test_shuffle_apply_when_lesson_flag_true():
    """Если флаг True — применяется shuffle (seed=attempt_id)."""
    lesson = FakeLesson(randomize_questions=True)
    items = ["a", "b", "c", "d", "e", "f", "g"]
    # На 7 элементах вероятность что shuffle вернёт идентичный порядок = 1/7! ≈ 0.0002
    # Прверяем что хотя бы один из 5 attempt'ов меняет порядок.
    different = False
    for attempt_id in (1, 2, 5, 100, 9999):
        out = shuffle_questions_for_attempt(items, lesson, attempt_id=attempt_id)
        assert sorted(out) == sorted(items), "shuffle не теряет элементы"
        if out != items:
            different = True
    assert different, "Shuffle не сменил порядок ни для одного attempt_id — невероятно"


def test_shuffle_stable_per_attempt():
    """Один и тот же attempt_id → один порядок (для F5 в браузере)."""
    lesson = FakeLesson(randomize_questions=True)
    items = list(range(10))
    out1 = shuffle_questions_for_attempt(items, lesson, attempt_id=777)
    out2 = shuffle_questions_for_attempt(items, lesson, attempt_id=777)
    assert out1 == out2


def test_shuffle_different_attempts_can_differ():
    """Разные attempt_id → возможно разный порядок."""
    lesson = FakeLesson(randomize_questions=True)
    items = list(range(10))
    out1 = shuffle_questions_for_attempt(items, lesson, attempt_id=1)
    different = False
    for attempt_id in (2, 5, 100, 9999):
        if shuffle_questions_for_attempt(items, lesson, attempt_id=attempt_id) != out1:
            different = True
            break
    assert different


def test_shuffle_does_not_mutate_input():
    """shuffle_questions_for_attempt не меняет входной список."""
    lesson = FakeLesson(randomize_questions=True)
    items = list(range(5))
    items_copy = list(items)
    _ = shuffle_questions_for_attempt(items, lesson, attempt_id=42)
    assert items == items_copy


def test_shuffle_with_missing_attribute():
    """Если у lesson НЕТ атрибута randomize_questions — считаем False (back-compat)."""

    class MinimalLesson:
        pass

    lesson = MinimalLesson()
    items = ["a", "b", "c"]
    # Не должно упасть и должно вернуть исходный порядок
    out = shuffle_questions_for_attempt(items, lesson, attempt_id=1)  # type: ignore[arg-type]
    assert out == items
