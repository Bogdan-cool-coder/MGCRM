"""P2 (C8 CRITICAL): отчёт «самые сложные вопросы» больше не фабрикует данные.

Раньше submit_quiz_attempt сохранял raw client payload
[{question_id, selected_indices}] БЕЗ is_correct, а аналитика
(analytics_onboarding) читала ans.get("is_correct") → всегда False → каждый
вопрос показывал 0% правильных → весь top-5 + его Excel-экспорт были выдумкой.

Фикс: is_correct вычисляется серверно на submit (annotate_answers_correctness),
тем же сравнением что и scoring. Эти тесты доказывают:
1. Верный ответ → is_correct=True, неверный → is_correct=False.
2. Прогнав эти аннотированные answers через ту же агрегацию, что в роутере,
   неверный ответ попадает в incorrect (correct < total), а не «фабрикует» 0%.
"""
from __future__ import annotations

from dataclasses import dataclass

from app.services.onboarding.quiz import annotate_answers_correctness


@dataclass
class FakeQ:
    """Минимальный mock LessonQuizQuestion: id + correct_answers (+ points)."""

    id: int
    correct_answers: list[int]
    points: int = 1


def _aggregate_correctness(annotated_answers: list[dict]) -> dict[int, dict[str, int]]:
    """Копия Python-агрегации из analytics_onboarding.hard_questions:
    {question_id: {total, correct}} по полю is_correct."""
    q_stats: dict[int, dict[str, int]] = {}
    for ans in annotated_answers:
        qid = ans.get("question_id")
        if qid is None:
            continue
        is_correct = bool(ans.get("is_correct", False))
        stats = q_stats.setdefault(qid, {"total": 0, "correct": 0})
        stats["total"] += 1
        if is_correct:
            stats["correct"] += 1
    return q_stats


def test_correct_answer_marked_true():
    questions = [FakeQ(id=1, correct_answers=[0, 2])]
    answers = [{"question_id": 1, "selected_indices": [2, 0]}]  # порядок не важен
    out = annotate_answers_correctness(questions, answers)
    assert out[0]["is_correct"] is True


def test_wrong_answer_marked_false():
    questions = [FakeQ(id=1, correct_answers=[0, 2])]
    answers = [{"question_id": 1, "selected_indices": [1]}]
    out = annotate_answers_correctness(questions, answers)
    assert out[0]["is_correct"] is False


def test_partial_answer_is_incorrect():
    """Частично верный (подмножество) — НЕ засчитывается (как и в scoring)."""
    questions = [FakeQ(id=1, correct_answers=[0, 2])]
    answers = [{"question_id": 1, "selected_indices": [0]}]
    out = annotate_answers_correctness(questions, answers)
    assert out[0]["is_correct"] is False


def test_unknown_question_is_incorrect():
    """Ответ на вопрос, которого нет в questions → is_correct=False (не падаем)."""
    questions = [FakeQ(id=1, correct_answers=[0])]
    answers = [{"question_id": 999, "selected_indices": [0]}]
    out = annotate_answers_correctness(questions, answers)
    assert out[0]["is_correct"] is False


def test_wrong_answer_counted_incorrect_in_difficulty_aggregate():
    """Сквозной: аннотированные answers → агрегат показывает РЕАЛЬНУЮ статистику.

    Один верный + один неверный ответ на вопрос id=1 → total=2, correct=1.
    Раньше (без is_correct) было бы correct=0 для ОБОИХ — фабрикация.
    """
    questions = [FakeQ(id=1, correct_answers=[3])]
    attempt_a = annotate_answers_correctness(
        questions, [{"question_id": 1, "selected_indices": [3]}]
    )  # верный
    attempt_b = annotate_answers_correctness(
        questions, [{"question_id": 1, "selected_indices": [0]}]
    )  # неверный

    stats = _aggregate_correctness([*attempt_a, *attempt_b])
    assert stats[1]["total"] == 2
    assert stats[1]["correct"] == 1, "неверный ответ должен считаться incorrect, а не 0%"


def test_non_dict_answers_skipped():
    questions = [FakeQ(id=1, correct_answers=[0])]
    out = annotate_answers_correctness(questions, [None, "x", {"question_id": 1, "selected_indices": [0]}])
    assert len(out) == 1
    assert out[0]["is_correct"] is True
