"""Эпик 13: Onboarding — student endpoints.

Доступ — любой авторизованный (CurrentUser).

Endpoints:
- GET    /api/onboarding/my-courses                           — список + denormalized prog
- GET    /api/onboarding/courses/{course_id}                  — курс с модулями+уроками
- GET    /api/onboarding/lessons/{lesson_id}                  — урок (quiz без correct)
- POST   /api/onboarding/lessons/{lesson_id}/complete         — для theory/video
- POST   /api/onboarding/lessons/{lesson_id}/quiz/start       — начать попытку
- POST   /api/onboarding/quiz-attempts/{attempt_id}/submit    — сдать ответы
- GET    /api/onboarding/quiz-attempts/{attempt_id}           — результат попытки
- PATCH  /api/users/me/onboarding-wizard                      — wizard state
- GET    /api/onboarding/meta/content-block-kinds             — whitelist для UI
- GET    /api/onboarding/meta/lesson-kinds                    — whitelist для UI

NB: route /users/me/onboarding-wizard живёт в этом файле (а не в users.py),
чтобы держать всю онбордингную логику в одном месте.
"""
from __future__ import annotations

import logging
from datetime import datetime
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import CurrentUser
from app.models import (
    Course,
    CourseLesson,
    CourseModule,
    CourseProgress,
    LessonQuizQuestion,
    QuizAttempt,
    UserCourseAssignment,
)
from app.services.onboarding.courses import (
    CONTENT_BLOCK_KINDS,
    LESSON_KINDS,
    USER_CRM_EXPERIENCE_LEVELS,
)
from app.services.onboarding.progress import (
    compute_assignment_status,
    get_or_create_progress,
    mark_lesson_completed,
)
from app.services.onboarding.quiz import (
    question_with_review,
    shuffle_questions_for_attempt,
    start_quiz_attempt,
    strip_question_for_student,
    submit_quiz_attempt,
)

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/onboarding", tags=["onboarding"])

# Отдельный роутер для /users/me/onboarding-wizard — иначе уйдёт в /onboarding/...
# Регистрируется на префикс /users в main.py.
wizard_router = APIRouter(prefix="/users", tags=["onboarding"])


# ============ Pydantic Schemas ============

class CourseShortOut(BaseModel):
    """Карточка курса в /my-courses."""

    model_config = ConfigDict(from_attributes=True)
    id: int
    title: str
    description: str | None
    cover_image_url: str | None
    target_roles: list[str]
    is_published: bool
    completion_policy: str
    passing_score_pct: int
    deadline_days: int


class MyCourseOut(BaseModel):
    """Курс + прогресс для /my-courses."""

    course: CourseShortOut
    assignment_id: int
    assigned_at: datetime
    deadline_at: datetime | None
    is_mandatory: bool
    progress_status: str  # live status (учитывает overdue)
    progress_percent: int
    started_at: datetime | None
    completed_at: datetime | None


class QuestionForStudent(BaseModel):
    """Question без correct_answers (для GET /lessons/{id})."""

    id: int
    question: str
    kind: str
    options: list[str]
    points: int
    order_index: int


class LessonOut(BaseModel):
    """Полная карточка урока студента (без correct_answers/explanation для quiz)."""

    model_config = ConfigDict(from_attributes=True)
    id: int
    module_id: int
    title: str
    kind: str
    content_blocks: list[dict[str, Any]]
    duration_min: int | None
    order_index: int
    is_required: bool
    # Только для kind='quiz' — список вопросов без правильных ответов.
    questions: list[QuestionForStudent] | None = None


class ModuleOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    course_id: int
    title: str
    order_index: int
    lessons: list[LessonOut] = Field(default_factory=list)


class CourseDetailOut(BaseModel):
    """Курс с модулями+уроками. Для student — без correct_answers в quiz."""

    model_config = ConfigDict(from_attributes=True)
    id: int
    title: str
    description: str | None
    cover_image_url: str | None
    target_roles: list[str]
    is_published: bool
    completion_policy: str
    passing_score_pct: int
    deadline_days: int
    modules: list[ModuleOut] = Field(default_factory=list)
    # Кеш прогресса юзера (если есть — может быть None если не assigned)
    progress_status: str | None = None
    progress_percent: int | None = None
    lesson_states: dict[str, Any] | None = None


class CompleteLessonOut(BaseModel):
    """Response /lessons/{id}/complete."""

    lesson_id: int
    course_id: int
    progress_status: str
    progress_percent: int


class StartQuizOut(BaseModel):
    """Response /lessons/{id}/quiz/start."""

    attempt_id: int
    lesson_id: int
    started_at: datetime
    questions: list[QuestionForStudent]


class QuizAnswerIn(BaseModel):
    question_id: int
    selected_indices: list[int]


class SubmitQuizIn(BaseModel):
    answers: list[QuizAnswerIn] = Field(default_factory=list)


class QuestionResult(BaseModel):
    id: int
    question: str
    kind: str
    options: list[str]
    points: int
    order_index: int
    selected_indices: list[int]
    is_correct: bool
    # Только если попытка passed — иначе скрыты (защита от brute-force)
    correct_answers: list[int] | None = None
    explanation: str | None = None


class SubmitQuizOut(BaseModel):
    """Response /quiz-attempts/{id}/submit + GET /quiz-attempts/{id}."""

    attempt_id: int
    lesson_id: int
    started_at: datetime
    finished_at: datetime | None
    score_pct: int | None
    passed: bool
    passing_score_pct: int
    n_correct: int
    n_total: int
    questions: list[QuestionResult] = Field(default_factory=list)


class WizardPatch(BaseModel):
    crm_experience_level: str | None = None
    dismissed: bool | None = None


class WizardOut(BaseModel):
    crm_experience_level: str | None
    onboarding_dismissed_at: datetime | None


class OnboardingBadgeSummary(BaseModel):
    """Агрегат для Sidebar badge — счётчики по статусам assigned курсов.

    Используется для лёгкого polling каждую минуту (вместо тяжёлого
    /my-courses со всеми объектами курсов).
    """

    overdue_count: int = 0  # курсы со status='overdue' (live, учитывая deadline)
    in_progress_count: int = 0
    not_started_count: int = 0
    total_assigned: int = 0


def summarize_assignments_for_badge(
    statuses: list[str],
) -> OnboardingBadgeSummary:
    """Pure-function: считает summary из списка live-статусов assignments.

    Используется в endpoint и в unit-тестах. statuses — массив значений
    'not_started' | 'in_progress' | 'completed' | 'overdue' (по одному
    на каждый assignment юзера).
    """
    overdue = sum(1 for s in statuses if s == "overdue")
    in_progress = sum(1 for s in statuses if s == "in_progress")
    not_started = sum(1 for s in statuses if s == "not_started")
    return OnboardingBadgeSummary(
        overdue_count=overdue,
        in_progress_count=in_progress,
        not_started_count=not_started,
        total_assigned=len(statuses),
    )


# ============ Helpers ============

async def _user_can_view_course(
    session: AsyncSession, user_id: int, course_id: int,
) -> bool:
    """Проверка: курс назначен юзеру (есть UserCourseAssignment).

    Студенты не могут читать чужие курсы (даже если published) — только
    assigned. Это защита от утечки внутреннего контента до публикации.
    """
    stmt = select(UserCourseAssignment.id).where(
        UserCourseAssignment.user_id == user_id,
        UserCourseAssignment.course_id == course_id,
    )
    return (await session.execute(stmt)).scalar_one_or_none() is not None


async def _build_my_course(
    session: AsyncSession,
    assignment: UserCourseAssignment,
    course: Course,
    progress: CourseProgress | None,
) -> MyCourseOut:
    live_status = await compute_assignment_status(progress, assignment.deadline_at)
    return MyCourseOut(
        course=CourseShortOut.model_validate(course),
        assignment_id=assignment.id,
        assigned_at=assignment.assigned_at,
        deadline_at=assignment.deadline_at,
        is_mandatory=assignment.is_mandatory,
        progress_status=live_status,
        progress_percent=progress.percent if progress else 0,
        started_at=progress.started_at if progress else None,
        completed_at=progress.completed_at if progress else None,
    )


# ============ Endpoints ============

@router.get("/my-courses", response_model=None)
async def list_my_courses(
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
    summary: bool = False,
) -> list[MyCourseOut] | OnboardingBadgeSummary:
    """Список назначенных юзеру курсов с прогрессом.

    Возвращает только assigned (UserCourseAssignment), не все published.
    Сортировка: по deadline_at (NULL в конце), потом по assigned_at desc.

    Параметр `summary=true` → возвращает OnboardingBadgeSummary (агрегат
    для Sidebar badge): {overdue_count, in_progress_count, not_started_count,
    total_assigned}. Лёгкий вариант для polling каждую минуту.
    """
    # Все assignments юзера
    stmt = (
        select(UserCourseAssignment)
        .where(UserCourseAssignment.user_id == current_user.id)
        .order_by(
            UserCourseAssignment.deadline_at.asc().nullslast(),
            UserCourseAssignment.assigned_at.desc(),
        )
    )
    assignments = (await session.execute(stmt)).scalars().all()
    if not assignments:
        if summary:
            return OnboardingBadgeSummary()
        return []

    course_ids = [a.course_id for a in assignments]

    progress_by_course: dict[int, CourseProgress] = {}
    for prog in (
        await session.execute(
            select(CourseProgress).where(
                CourseProgress.user_id == current_user.id,
                CourseProgress.course_id.in_(course_ids),
            )
        )
    ).scalars().all():
        progress_by_course[prog.course_id] = prog

    if summary:
        # Лёгкий путь: считаем только live-статусы, не грузим курсы целиком.
        statuses: list[str] = []
        for a in assignments:
            progress = progress_by_course.get(a.course_id)
            statuses.append(
                await compute_assignment_status(progress, a.deadline_at)
            )
        return summarize_assignments_for_badge(statuses)

    courses_by_id: dict[int, Course] = {}
    for course in (
        await session.execute(select(Course).where(Course.id.in_(course_ids)))
    ).scalars().all():
        courses_by_id[course.id] = course

    out: list[MyCourseOut] = []
    for a in assignments:
        course = courses_by_id.get(a.course_id)
        if course is None:
            continue  # курс удалён, assignment должен был CASCADE — но защищаемся
        progress = progress_by_course.get(a.course_id)
        out.append(await _build_my_course(session, a, course, progress))
    return out


@router.get("/courses/{course_id}", response_model=CourseDetailOut)
async def get_course_detail(
    course_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Полный курс: модули + уроки. Quiz без правильных ответов.

    Student должен иметь assignment на курс (не показываем чужие).
    """
    course = (
        await session.execute(select(Course).where(Course.id == course_id))
    ).scalar_one_or_none()
    if course is None:
        raise HTTPException(404, "Курс не найден")

    if not await _user_can_view_course(session, current_user.id, course_id):
        raise HTTPException(403, "Курс не назначен — попроси администратора назначить")

    # Грузим модули
    modules = (
        await session.execute(
            select(CourseModule)
            .where(CourseModule.course_id == course_id)
            .order_by(CourseModule.order_index)
        )
    ).scalars().all()

    module_ids = [m.id for m in modules]
    lessons_by_module: dict[int, list[CourseLesson]] = {mid: [] for mid in module_ids}
    if module_ids:
        for lesson in (
            await session.execute(
                select(CourseLesson)
                .where(CourseLesson.module_id.in_(module_ids))
                .order_by(CourseLesson.order_index)
            )
        ).scalars().all():
            lessons_by_module.setdefault(lesson.module_id, []).append(lesson)

    # Quiz-вопросы (для kind='quiz' уроков) — без correct_answers
    quiz_lesson_ids = [
        lesson.id
        for ls in lessons_by_module.values()
        for lesson in ls
        if lesson.kind == "quiz"
    ]
    questions_by_lesson: dict[int, list[LessonQuizQuestion]] = {
        lid: [] for lid in quiz_lesson_ids
    }
    if quiz_lesson_ids:
        for q in (
            await session.execute(
                select(LessonQuizQuestion)
                .where(LessonQuizQuestion.lesson_id.in_(quiz_lesson_ids))
                .order_by(LessonQuizQuestion.order_index)
            )
        ).scalars().all():
            questions_by_lesson.setdefault(q.lesson_id, []).append(q)

    # Progress
    progress = (
        await session.execute(
            select(CourseProgress).where(
                CourseProgress.user_id == current_user.id,
                CourseProgress.course_id == course_id,
            )
        )
    ).scalar_one_or_none()

    # Build output
    module_outs: list[ModuleOut] = []
    for m in modules:
        lesson_outs: list[LessonOut] = []
        for lesson in lessons_by_module.get(m.id, []):
            questions_list: list[QuestionForStudent] | None = None
            if lesson.kind == "quiz":
                questions_list = [
                    QuestionForStudent(**strip_question_for_student(q))
                    for q in questions_by_lesson.get(lesson.id, [])
                ]
            lesson_outs.append(LessonOut(
                id=lesson.id,
                module_id=lesson.module_id,
                title=lesson.title,
                kind=lesson.kind,
                content_blocks=list(lesson.content_blocks or []),
                duration_min=lesson.duration_min,
                order_index=lesson.order_index,
                is_required=lesson.is_required,
                questions=questions_list,
            ))
        module_outs.append(ModuleOut(
            id=m.id,
            course_id=m.course_id,
            title=m.title,
            order_index=m.order_index,
            lessons=lesson_outs,
        ))

    return CourseDetailOut(
        id=course.id,
        title=course.title,
        description=course.description,
        cover_image_url=course.cover_image_url,
        target_roles=list(course.target_roles or []),
        is_published=course.is_published,
        completion_policy=course.completion_policy,
        passing_score_pct=course.passing_score_pct,
        deadline_days=course.deadline_days,
        modules=module_outs,
        progress_status=progress.status if progress else None,
        progress_percent=progress.percent if progress else None,
        lesson_states=dict(progress.lesson_states or {}) if progress else None,
    )


@router.get("/lessons/{lesson_id}", response_model=LessonOut)
async def get_lesson(
    lesson_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Урок с контентом. Quiz без правильных ответов."""
    lesson = (
        await session.execute(select(CourseLesson).where(CourseLesson.id == lesson_id))
    ).scalar_one_or_none()
    if lesson is None:
        raise HTTPException(404, "Урок не найден")

    # Проверка assignment через module → course
    module = (
        await session.execute(
            select(CourseModule).where(CourseModule.id == lesson.module_id)
        )
    ).scalar_one_or_none()
    if module is None:
        raise HTTPException(404, "Модуль урока не найден")

    if not await _user_can_view_course(session, current_user.id, module.course_id):
        raise HTTPException(403, "Курс не назначен")

    questions_list: list[QuestionForStudent] | None = None
    if lesson.kind == "quiz":
        questions = (
            await session.execute(
                select(LessonQuizQuestion)
                .where(LessonQuizQuestion.lesson_id == lesson_id)
                .order_by(LessonQuizQuestion.order_index)
            )
        ).scalars().all()
        questions_list = [
            QuestionForStudent(**strip_question_for_student(q)) for q in questions
        ]

    return LessonOut(
        id=lesson.id,
        module_id=lesson.module_id,
        title=lesson.title,
        kind=lesson.kind,
        content_blocks=list(lesson.content_blocks or []),
        duration_min=lesson.duration_min,
        order_index=lesson.order_index,
        is_required=lesson.is_required,
        questions=questions_list,
    )


@router.post("/lessons/{lesson_id}/complete", response_model=CompleteLessonOut)
async def complete_lesson(
    lesson_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Отметить урок как пройденный (для theory/video).

    Для quiz-уроков использовать /lessons/{id}/quiz/start + submit.
    Идемпотентно: повторный вызов не сбивает completed_at.
    """
    lesson = (
        await session.execute(select(CourseLesson).where(CourseLesson.id == lesson_id))
    ).scalar_one_or_none()
    if lesson is None:
        raise HTTPException(404, "Урок не найден")

    if lesson.kind == "quiz":
        raise HTTPException(
            400,
            "Quiz-уроки нельзя пометить через /complete — используй /quiz/start + submit",
        )

    module = (
        await session.execute(
            select(CourseModule).where(CourseModule.id == lesson.module_id)
        )
    ).scalar_one_or_none()
    if module is None:
        raise HTTPException(404, "Модуль урока не найден")

    if not await _user_can_view_course(session, current_user.id, module.course_id):
        raise HTTPException(403, "Курс не назначен")

    progress = await mark_lesson_completed(session, current_user, lesson)
    await session.commit()
    await session.refresh(progress)
    return CompleteLessonOut(
        lesson_id=lesson.id,
        course_id=module.course_id,
        progress_status=progress.status,
        progress_percent=progress.percent,
    )


@router.post("/lessons/{lesson_id}/quiz/start", response_model=StartQuizOut)
async def start_quiz(
    lesson_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Начать (или вернуть открытую) попытку quiz-урока.

    Если есть открытая попытка (finished_at IS NULL) — возвращаем её
    (идемпотентно при повторе).

    Rate-limit: не чаще 1 попытки в RATE_LIMIT_MINUTES на (user, lesson).
    """
    lesson = (
        await session.execute(select(CourseLesson).where(CourseLesson.id == lesson_id))
    ).scalar_one_or_none()
    if lesson is None:
        raise HTTPException(404, "Урок не найден")

    module = (
        await session.execute(
            select(CourseModule).where(CourseModule.id == lesson.module_id)
        )
    ).scalar_one_or_none()
    if module is None:
        raise HTTPException(404, "Модуль урока не найден")

    if not await _user_can_view_course(session, current_user.id, module.course_id):
        raise HTTPException(403, "Курс не назначен")

    attempt = await start_quiz_attempt(session, current_user, lesson)
    await session.commit()
    await session.refresh(attempt)

    # Вопросы для UI — без correct_answers
    questions = (
        await session.execute(
            select(LessonQuizQuestion)
            .where(LessonQuizQuestion.lesson_id == lesson.id)
            .order_by(LessonQuizQuestion.order_index)
        )
    ).scalars().all()

    # Tech Sprint Фаза 0: если lesson.randomize_questions=True, перемешиваем
    # порядок (seed = attempt.id даёт стабильный порядок при F5 в этой попытке).
    questions = shuffle_questions_for_attempt(list(questions), lesson, attempt.id)

    return StartQuizOut(
        attempt_id=attempt.id,
        lesson_id=lesson.id,
        started_at=attempt.started_at,
        questions=[
            QuestionForStudent(**strip_question_for_student(q)) for q in questions
        ],
    )


@router.post("/quiz-attempts/{attempt_id}/submit", response_model=SubmitQuizOut)
async def submit_quiz(
    attempt_id: int,
    payload: SubmitQuizIn,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Сдать ответы и получить результат.

    Если passed=True — возвращаем correct_answers + explanation для review.
    Если passed=False — НЕ возвращаем правильные (защита от brute-force).
    """
    attempt = (
        await session.execute(select(QuizAttempt).where(QuizAttempt.id == attempt_id))
    ).scalar_one_or_none()
    if attempt is None:
        raise HTTPException(404, "Попытка не найдена")
    if attempt.user_id != current_user.id:
        raise HTTPException(403, "Это чужая попытка")
    if attempt.finished_at is not None:
        raise HTTPException(409, "Попытка уже завершена")

    answers_dicts = [a.model_dump() for a in payload.answers]
    attempt_updated, questions = await submit_quiz_attempt(
        session, attempt, answers_dicts
    )
    await session.commit()
    await session.refresh(attempt_updated)

    # Найдём passing_score_pct курса для response
    lesson = (
        await session.execute(
            select(CourseLesson).where(CourseLesson.id == attempt_updated.lesson_id)
        )
    ).scalar_one_or_none()
    module = (
        await session.execute(
            select(CourseModule).where(CourseModule.id == lesson.module_id)
        )
    ).scalar_one_or_none() if lesson else None
    course = (
        await session.execute(select(Course).where(Course.id == module.course_id))
    ).scalar_one_or_none() if module else None
    passing = (course.passing_score_pct if course else 80) or 80

    # Если passed — recompute course status для completion проверки
    if attempt_updated.passed and course:
        from app.services.onboarding.progress import recompute_course_status
        progress = await get_or_create_progress(
            session, current_user.id, course.id,
        )
        await recompute_course_status(session, progress, course)
        await session.commit()

    # Сборка результата
    sel_by_qid: dict[int, list[int]] = {}
    for a in payload.answers:
        sel_by_qid[a.question_id] = list(a.selected_indices)

    n_correct = 0
    question_results: list[QuestionResult] = []
    for q in questions:
        selected = sel_by_qid.get(q.id, [])
        review = question_with_review(q, selected_indices=selected, passed=attempt_updated.passed)
        if review.get("is_correct"):
            n_correct += 1
        question_results.append(QuestionResult(
            id=review["id"],
            question=review["question"],
            kind=review["kind"],
            options=review["options"],
            points=review["points"],
            order_index=review["order_index"],
            selected_indices=review["selected_indices"],
            is_correct=review["is_correct"],
            correct_answers=review.get("correct_answers"),
            explanation=review.get("explanation"),
        ))

    return SubmitQuizOut(
        attempt_id=attempt_updated.id,
        lesson_id=attempt_updated.lesson_id,
        started_at=attempt_updated.started_at,
        finished_at=attempt_updated.finished_at,
        score_pct=attempt_updated.score_pct,
        passed=attempt_updated.passed,
        passing_score_pct=passing,
        n_correct=n_correct,
        n_total=len(questions),
        questions=question_results,
    )


@router.get("/quiz-attempts/{attempt_id}", response_model=SubmitQuizOut)
async def get_quiz_attempt(
    attempt_id: int,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Карточка попытки (только своей). Включает результат если попытка finished."""
    attempt = (
        await session.execute(select(QuizAttempt).where(QuizAttempt.id == attempt_id))
    ).scalar_one_or_none()
    if attempt is None:
        raise HTTPException(404, "Попытка не найдена")
    if attempt.user_id != current_user.id:
        raise HTTPException(403, "Это чужая попытка")

    questions = (
        await session.execute(
            select(LessonQuizQuestion)
            .where(LessonQuizQuestion.lesson_id == attempt.lesson_id)
            .order_by(LessonQuizQuestion.order_index)
        )
    ).scalars().all()

    lesson = (
        await session.execute(
            select(CourseLesson).where(CourseLesson.id == attempt.lesson_id)
        )
    ).scalar_one_or_none()
    module = (
        await session.execute(
            select(CourseModule).where(CourseModule.id == lesson.module_id)
        )
    ).scalar_one_or_none() if lesson else None
    course = (
        await session.execute(select(Course).where(Course.id == module.course_id))
    ).scalar_one_or_none() if module else None
    passing = (course.passing_score_pct if course else 80) or 80

    # answers → sel map
    sel_by_qid: dict[int, list[int]] = {}
    for a in attempt.answers or []:
        if isinstance(a, dict):
            qid = a.get("question_id")
            sel = a.get("selected_indices") or []
            if isinstance(qid, int):
                sel_by_qid[qid] = list(sel)

    n_correct = 0
    question_results: list[QuestionResult] = []
    for q in questions:
        selected = sel_by_qid.get(q.id, [])
        review = question_with_review(
            q, selected_indices=selected, passed=attempt.passed,
        )
        if review.get("is_correct"):
            n_correct += 1
        question_results.append(QuestionResult(
            id=review["id"],
            question=review["question"],
            kind=review["kind"],
            options=review["options"],
            points=review["points"],
            order_index=review["order_index"],
            selected_indices=review["selected_indices"],
            is_correct=review["is_correct"],
            correct_answers=review.get("correct_answers"),
            explanation=review.get("explanation"),
        ))

    return SubmitQuizOut(
        attempt_id=attempt.id,
        lesson_id=attempt.lesson_id,
        started_at=attempt.started_at,
        finished_at=attempt.finished_at,
        score_pct=attempt.score_pct,
        passed=attempt.passed,
        passing_score_pct=passing,
        n_correct=n_correct,
        n_total=len(questions),
        questions=question_results,
    )


# ============ Wizard state ============

@wizard_router.patch("/me/onboarding-wizard", response_model=WizardOut)
async def patch_my_onboarding_wizard(
    payload: WizardPatch,
    current_user: CurrentUser,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Обновить wizard state юзера.

    crm_experience_level — sets/clears (None = clear).
    dismissed:
    - True → onboarding_dismissed_at = now()
    - False → onboarding_dismissed_at = None (заново показать wizard)
    """
    if payload.crm_experience_level is not None:
        if payload.crm_experience_level not in USER_CRM_EXPERIENCE_LEVELS:
            raise HTTPException(
                400,
                f"crm_experience_level должно быть одним из: "
                f"{sorted(USER_CRM_EXPERIENCE_LEVELS)}",
            )
        current_user.crm_experience_level = payload.crm_experience_level

    if payload.dismissed is not None:
        if payload.dismissed:
            from datetime import UTC, datetime
            current_user.onboarding_dismissed_at = datetime.now(UTC)
        else:
            current_user.onboarding_dismissed_at = None

    await session.commit()
    await session.refresh(current_user)
    return WizardOut(
        crm_experience_level=current_user.crm_experience_level,
        onboarding_dismissed_at=current_user.onboarding_dismissed_at,
    )


# ============ Meta endpoints (для UI) ============

@router.get("/meta/content-block-kinds")
async def get_content_block_kinds(_: CurrentUser):
    """Whitelist content_blocks.kind для admin builder dropdown."""
    labels = {
        "markdown": "Текст (Markdown)",
        "image": "Изображение",
        "drive_video": "Видео из Google Drive",
        "loom_video": "Видео из Loom",
        "youtube_video": "Видео из YouTube",
        "callout": "Заметка / совет",
    }
    return [
        {"value": k, "label": labels.get(k, k)} for k in sorted(CONTENT_BLOCK_KINDS)
    ]


@router.get("/meta/lesson-kinds")
async def get_lesson_kinds(_: CurrentUser):
    """Whitelist lesson kinds для admin builder."""
    labels = {
        "theory": "Теория",
        "video": "Видео",
        "quiz": "Квиз / проверка знаний",
    }
    return [{"value": k, "label": labels.get(k, k)} for k in sorted(LESSON_KINDS)]
