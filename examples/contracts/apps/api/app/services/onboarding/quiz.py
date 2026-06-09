"""Эпик 13: quiz — start/submit attempt + scoring + randomize.

Pure-функции (тестируются без БД):
- compute_quiz_score(questions, answers) -> (score_pct, passed)
- randomize_questions(questions, seed) — стабильный shuffle для одной попытки

С БД (тестируются через mocks / interview):
- start_quiz_attempt — создаёт QuizAttempt с rate-limit
- submit_quiz_attempt — обновляет QuizAttempt + lesson_states в CourseProgress
"""
from __future__ import annotations

import random
from datetime import UTC, datetime, timedelta
from typing import Any

from fastapi import HTTPException
from sqlalchemy import and_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import (
    Course,
    CourseLesson,
    CourseProgress,
    LessonQuizQuestion,
    QuizAttempt,
    User,
    UserCourseAssignment,
)

# Минимальный интервал между попытками одного user × lesson (защита от brute-force).
RATE_LIMIT_MINUTES = 5


def compute_quiz_score(
    questions: list[dict[str, Any]] | list[LessonQuizQuestion],
    answers: list[dict[str, Any]],
) -> tuple[int, bool, int]:
    """Pure-function: посчитать score_pct + passed + сколько вопросов правильно.

    questions: либо ORM-объекты LessonQuizQuestion, либо dict с ключами
        {id, correct_answers, points}. Поддерживается оба варианта чтобы
        тесты могли работать без БД.

    answers: [{"question_id": int, "selected_indices": [int, ...]}, ...]

    Логика:
    - Для каждого вопроса: правильный = set(selected_indices) == set(correct_answers).
    - score = sum(points where correct) / sum(all points) * 100.
    - passed=True если score >= 80 (passing_score_pct курса — caller сравнивает сам).

    Возвращает (score_pct, passed_at_80, n_correct).

    NB: passed возвращается по дефолтному порогу 80%; финальный pass/fail
    зависит от Course.passing_score_pct. Caller (submit_quiz_attempt)
    пересчитывает passed по passing_score_pct курса.
    """
    if not questions:
        return (0, False, 0)

    # Нормализуем questions в dict-формат
    normalized: list[dict[str, Any]] = []
    for q in questions:
        if isinstance(q, dict):
            normalized.append({
                "id": q.get("id"),
                "correct_answers": q.get("correct_answers") or [],
                "points": q.get("points") or 1,
            })
        else:
            normalized.append({
                "id": q.id,
                "correct_answers": q.correct_answers or [],
                "points": q.points or 1,
            })

    # answers → dict by question_id
    answer_map: dict[int, set[int]] = {}
    for a in answers or []:
        if not isinstance(a, dict):
            continue
        qid = a.get("question_id")
        sel = a.get("selected_indices") or []
        if isinstance(qid, int) and isinstance(sel, list):
            try:
                answer_map[qid] = {int(x) for x in sel}
            except (TypeError, ValueError):
                answer_map[qid] = set()

    total_points = sum(q["points"] for q in normalized) or 1
    earned = 0
    n_correct = 0
    for q in normalized:
        correct = set(int(x) for x in q["correct_answers"])
        selected = answer_map.get(q["id"], set())
        if correct == selected:
            earned += q["points"]
            n_correct += 1

    score_pct = int(round(earned * 100 / total_points))
    # Default threshold — caller'у нужно пересчитать с реальным Course.passing_score_pct
    passed_default = score_pct >= 80
    return (score_pct, passed_default, n_correct)


def annotate_answers_correctness(
    questions: list[dict[str, Any]] | list[LessonQuizQuestion],
    answers: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Pure-function: вернуть копию answers, где у каждого ответа проставлен
    серверно-вычисленный `is_correct`.

    Нужно для аналитики (top-5 сложных вопросов): submit раньше сохранял только
    raw client payload [{question_id, selected_indices}] БЕЗ is_correct, поэтому
    аналитика читала ans.get("is_correct") → всегда False → фабриковала 0% по
    всем вопросам. Теперь is_correct пишется на submit (источник истины — те же
    correct_answers, что и в compute_quiz_score).

    is_correct = set(selected_indices) == set(correct_answers).
    """
    # correct_answers по question_id
    correct_map: dict[int, set[int]] = {}
    for q in questions:
        if isinstance(q, dict):
            qid = q.get("id")
            ca = q.get("correct_answers") or []
        else:
            qid = q.id
            ca = q.correct_answers or []
        if isinstance(qid, int):
            try:
                correct_map[qid] = {int(x) for x in ca}
            except (TypeError, ValueError):
                correct_map[qid] = set()

    out: list[dict[str, Any]] = []
    for a in answers or []:
        if not isinstance(a, dict):
            continue
        new_a = dict(a)
        qid = a.get("question_id")
        sel_raw = a.get("selected_indices") or []
        try:
            selected = {int(x) for x in sel_raw} if isinstance(sel_raw, list) else set()
        except (TypeError, ValueError):
            selected = set()
        # Если вопрос неизвестен — is_correct=False (не учитываем как правильный).
        correct = correct_map.get(qid, set()) if isinstance(qid, int) else set()
        new_a["is_correct"] = bool(qid in correct_map and selected == correct)
        out.append(new_a)
    return out


def randomize_questions(
    questions: list[Any], seed: int,
) -> list[Any]:
    """Стабильный shuffle: одна попытка = один порядок (seed = attempt.id).

    Возвращает новый список, не модифицирует входной.
    """
    if not questions:
        return []
    rng = random.Random(seed)
    out = list(questions)
    rng.shuffle(out)
    return out


# ============ DB helpers ============

async def get_recent_open_attempt(
    session: AsyncSession, user_id: int, lesson_id: int,
) -> QuizAttempt | None:
    """Открытая попытка (finished_at IS NULL) для (user, lesson)."""
    stmt = (
        select(QuizAttempt)
        .where(
            QuizAttempt.user_id == user_id,
            QuizAttempt.lesson_id == lesson_id,
            QuizAttempt.finished_at.is_(None),
        )
        .order_by(QuizAttempt.started_at.desc())
        .limit(1)
    )
    return (await session.execute(stmt)).scalar_one_or_none()


async def check_rate_limit(
    session: AsyncSession, user_id: int, lesson_id: int,
) -> None:
    """Защита от brute-force: не чаще 1 попытки на (user, lesson) в RATE_LIMIT_MINUTES.

    Бросает HTTPException 429 если в окне есть начатая (или только что финишировавшая) попытка.
    """
    cutoff = datetime.now(UTC) - timedelta(minutes=RATE_LIMIT_MINUTES)
    stmt = (
        select(QuizAttempt)
        .where(
            QuizAttempt.user_id == user_id,
            QuizAttempt.lesson_id == lesson_id,
            QuizAttempt.started_at >= cutoff,
        )
        .order_by(QuizAttempt.started_at.desc())
        .limit(1)
    )
    recent = (await session.execute(stmt)).scalar_one_or_none()
    if recent is not None and recent.finished_at is not None:
        # Свежий финиш — не даём заново начинать спам-mode
        raise HTTPException(
            status_code=429,
            detail=(
                f"Попытки чаще одной в {RATE_LIMIT_MINUTES} мин запрещены. "
                f"Подожди немного и попробуй снова."
            ),
        )


async def start_quiz_attempt(
    session: AsyncSession, user: User, lesson: CourseLesson,
) -> QuizAttempt:
    """Создать (или вернуть) попытку quiz'а.

    Если есть открытая попытка (finished_at IS NULL) — возвращаем её
    (idempotent при повторном клике "Начать").

    Иначе проверяем rate-limit и создаём новую.

    NB: shuffle вопросов делается caller'ом — этот сервис возвращает только
    attempt, а роутер уже подмешивает порядок через randomize_questions
    (см. apps/api/app/routers/onboarding.py POST /lessons/{id}/quiz/start).
    seed = attempt.id даёт стабильный порядок в рамках одной попытки.
    """
    if lesson.kind != "quiz":
        raise HTTPException(400, "Урок не является квизом")

    existing = await get_recent_open_attempt(session, user.id, lesson.id)
    if existing is not None:
        return existing

    await check_rate_limit(session, user.id, lesson.id)

    attempt = QuizAttempt(
        user_id=user.id,
        lesson_id=lesson.id,
    )
    session.add(attempt)
    await session.flush()  # получить id
    return attempt


def shuffle_questions_for_attempt(
    questions: list[Any], lesson: CourseLesson, attempt_id: int,
) -> list[Any]:
    """Если lesson.randomize_questions=True — стабильный shuffle (seed=attempt_id).

    Иначе возвращаем как есть. Pure-функция — caller передаёт questions в
    желаемом исходном порядке (обычно order_index ASC).
    """
    if not getattr(lesson, "randomize_questions", False):
        return list(questions)
    return randomize_questions(questions, seed=attempt_id)


async def submit_quiz_attempt(
    session: AsyncSession,
    attempt: QuizAttempt,
    answers: list[dict[str, Any]],
) -> tuple[QuizAttempt, list[LessonQuizQuestion]]:
    """Финализировать попытку: посчитать score, проставить passed, finished_at.

    Возвращает (attempt, questions) — questions нужен caller'у для построения
    ответа (без correct_answers/explanation если failed).

    Также обновляет lesson_states в CourseProgress (если есть прогресс — иначе
    студент жмёт submit на нечего, но мы не падаем).

    SAFETY: если attempt.finished_at не None — это попытка повторного submit'а,
    бросаем 409 (защита от double-click на submit).
    """
    if attempt.finished_at is not None:
        raise HTTPException(409, "Попытка уже завершена")

    # Грузим lesson + course через lesson_id
    lesson = (
        await session.execute(
            select(CourseLesson).where(CourseLesson.id == attempt.lesson_id)
        )
    ).scalar_one_or_none()
    if lesson is None:
        raise HTTPException(404, "Урок не найден")

    # Грузим вопросы для scoring
    questions = (
        await session.execute(
            select(LessonQuizQuestion)
            .where(LessonQuizQuestion.lesson_id == lesson.id)
            .order_by(LessonQuizQuestion.order_index)
        )
    ).scalars().all()

    # Грузим course через module → используем CourseProgress.passing_score_pct
    course = (
        await session.execute(
            select(Course)
            .join(Course.modules)
            .where(Course.modules.any(id=lesson.module_id))
        )
    ).scalar_one_or_none()

    score_pct, _passed_default, _ = compute_quiz_score(list(questions), answers or [])
    passing = (course.passing_score_pct if course else 80) or 80
    passed = score_pct >= passing

    # Сохраняем answers с серверно-вычисленным is_correct по каждому ответу —
    # это источник правды для аналитики «сложных вопросов» (раньше is_correct
    # никогда не писался → отчёт фабриковал 0% по всем вопросам).
    attempt.answers = annotate_answers_correctness(list(questions), answers or [])
    attempt.score_pct = score_pct
    attempt.passed = passed
    attempt.finished_at = datetime.now(UTC)

    # Обновляем lesson_states в CourseProgress (если есть)
    if course:
        progress = (
            await session.execute(
                select(CourseProgress).where(
                    and_(
                        CourseProgress.user_id == attempt.user_id,
                        CourseProgress.course_id == course.id,
                    )
                )
            )
        ).scalar_one_or_none()
        if progress is not None:
            states = dict(progress.lesson_states or {})
            key = str(lesson.id)
            prev = states.get(key) or {}
            new_state = dict(prev) if isinstance(prev, dict) else {}
            new_state["attempts_count"] = int(new_state.get("attempts_count") or 0) + 1
            best = int(new_state.get("best_score_pct") or 0)
            if score_pct > best:
                new_state["best_score_pct"] = score_pct
            if passed:
                new_state["completed_at"] = attempt.finished_at.isoformat()
            states[key] = new_state
            progress.lesson_states = states

    await session.flush()
    return attempt, list(questions)


# ============ Sanitizers for student API ============

def strip_question_for_student(q: LessonQuizQuestion) -> dict[str, Any]:
    """Вернуть question без correct_answers и explanation (security).

    Used in student GET /api/onboarding/lessons/{id}.
    """
    return {
        "id": q.id,
        "question": q.question,
        "kind": q.kind,
        "options": list(q.options or []),
        "points": q.points,
        "order_index": q.order_index,
    }


def question_with_review(
    q: LessonQuizQuestion, *, selected_indices: list[int] | None, passed: bool,
) -> dict[str, Any]:
    """Question с ревью для финиша попытки.

    Если passed=True — возвращаем всё (correct_answers + explanation для review).
    Если passed=False — НЕ возвращаем правильные ответы (защита от brute-force).
        Возвращаем только: были ли выбранные правильны (is_correct True/False)
        и общий счёт (без explanation).
    """
    base: dict[str, Any] = {
        "id": q.id,
        "question": q.question,
        "kind": q.kind,
        "options": list(q.options or []),
        "points": q.points,
        "order_index": q.order_index,
        "selected_indices": list(selected_indices or []),
    }
    correct = set(int(x) for x in (q.correct_answers or []))
    selected = set(int(x) for x in (selected_indices or []))
    is_correct = correct == selected
    base["is_correct"] = is_correct
    if passed:
        # Только при успешном прохождении показываем правильные + объяснение
        base["correct_answers"] = sorted(correct)
        if q.explanation:
            base["explanation"] = q.explanation
    return base
