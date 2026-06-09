"""Эпик 13: Admin onboarding — CRUD курсов / модулей / уроков / вопросов,
назначение, прогресс-матрица, сброс попыток.

Все endpoints — DirectorOrAdmin (admin или director).

Endpoints:
- Courses CRUD
- Modules CRUD
- Lessons CRUD
- Questions CRUD
- Publish/unpublish + auto-assign-existing
- Manual assign к user_ids
- Progress matrix (user × course)
- User progress detail
- Reset quiz attempts
"""
from __future__ import annotations

import logging
from datetime import UTC, datetime, timedelta
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, status
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import and_, delete, func
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import DirectorOrAdmin
from app.models import (
    Course,
    CourseLesson,
    CourseModule,
    CourseProgress,
    LessonQuizQuestion,
    QuizAttempt,
    User,
    UserCourseAssignment,
)
from app.services.bulk_reorder import (
    apply_reorder_unique,
    validate_reorder_payload,
)
from app.services.onboarding.auto_assign import (
    _course_matches_role,
    assign_default_courses,
)
from app.services.onboarding.courses import (
    COURSE_COMPLETION_POLICIES,
    LESSON_KINDS,
    QUESTION_KINDS,
    validate_completion_policy,
    validate_content_blocks,
    validate_correct_answers,
    validate_lesson_kind,
    validate_question_kind,
    validate_target_roles,
)

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/admin/onboarding", tags=["admin-onboarding"])


# ============ Pydantic Schemas ============

class CourseCreate(BaseModel):
    title: str = Field(min_length=1, max_length=255)
    description: str | None = None
    cover_image_url: str | None = None
    target_roles: list[str] = Field(default_factory=list)
    passing_score_pct: int = Field(default=80, ge=0, le=100)
    completion_policy: str = "soft_gate"
    deadline_days: int = Field(default=5, ge=0, le=365)


class CourseUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=255)
    description: str | None = None
    cover_image_url: str | None = None
    target_roles: list[str] | None = None
    passing_score_pct: int | None = Field(default=None, ge=0, le=100)
    completion_policy: str | None = None
    deadline_days: int | None = Field(default=None, ge=0, le=365)


class CourseOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    title: str
    description: str | None
    cover_image_url: str | None
    target_roles: list[str]
    is_published: bool
    passing_score_pct: int
    completion_policy: str
    deadline_days: int
    created_by_user_id: int | None
    created_at: datetime
    updated_at: datetime


class ModuleCreate(BaseModel):
    title: str = Field(min_length=1, max_length=255)
    order_index: int = Field(ge=0, le=10000)


class ModuleUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=255)
    order_index: int | None = Field(default=None, ge=0, le=10000)


class ModuleOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    course_id: int
    title: str
    order_index: int
    created_at: datetime


class LessonCreate(BaseModel):
    title: str = Field(min_length=1, max_length=255)
    kind: str  # theory|video|quiz
    content_blocks: list[dict[str, Any]] = Field(default_factory=list)
    duration_min: int | None = Field(default=None, ge=0, le=10000)
    order_index: int = Field(ge=0, le=10000)
    is_required: bool = True
    # Tech Sprint Фаза 0: для quiz-уроков — перемешивать вопросы при каждой попытке
    randomize_questions: bool = False


class LessonUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=1, max_length=255)
    kind: str | None = None
    content_blocks: list[dict[str, Any]] | None = None
    duration_min: int | None = Field(default=None, ge=0, le=10000)
    order_index: int | None = Field(default=None, ge=0, le=10000)
    is_required: bool | None = None
    randomize_questions: bool | None = None


class LessonOutAdmin(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    module_id: int
    title: str
    kind: str
    content_blocks: list[dict[str, Any]]
    duration_min: int | None
    order_index: int
    is_required: bool
    randomize_questions: bool = False
    created_at: datetime
    updated_at: datetime


class QuestionCreate(BaseModel):
    question: str = Field(min_length=1)
    kind: str  # single|multi
    options: list[str] = Field(min_length=2, max_length=10)
    correct_answers: list[int]
    points: int = Field(default=1, ge=1, le=100)
    order_index: int = Field(ge=0, le=10000)
    explanation: str | None = None


class QuestionUpdate(BaseModel):
    question: str | None = Field(default=None, min_length=1)
    kind: str | None = None
    options: list[str] | None = Field(default=None, min_length=2, max_length=10)
    correct_answers: list[int] | None = None
    points: int | None = Field(default=None, ge=1, le=100)
    order_index: int | None = Field(default=None, ge=0, le=10000)
    explanation: str | None = None


class QuestionOutAdmin(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    lesson_id: int
    question: str
    kind: str
    options: list[str]
    correct_answers: list[int]
    points: int
    order_index: int
    explanation: str | None
    created_at: datetime


class CourseFullOut(CourseOut):
    """Курс + полная иерархия модулей/уроков/вопросов (для admin builder)."""

    modules: list[dict[str, Any]] = Field(default_factory=list)


class AssignRequest(BaseModel):
    user_ids: list[int] = Field(min_length=1, max_length=1000)
    course_id: int
    is_mandatory: bool = True
    deadline_days: int | None = Field(default=None, ge=0, le=365)


class AssignmentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: int
    user_id: int
    course_id: int
    assigned_at: datetime
    assigned_by_user_id: int | None
    deadline_at: datetime | None
    is_mandatory: bool


class ProgressMatrixRow(BaseModel):
    """Строка матрицы прогресса (course × user)."""

    user_id: int
    user_full_name: str
    user_email: str
    user_role: str
    course_id: int
    course_title: str
    assignment_id: int | None
    deadline_at: datetime | None
    is_mandatory: bool | None
    progress_status: str  # not_started/in_progress/completed/overdue
    progress_percent: int
    started_at: datetime | None
    completed_at: datetime | None


class UserProgressDetail(BaseModel):
    user_id: int
    user_full_name: str
    courses: list[ProgressMatrixRow] = Field(default_factory=list)


class UserCourseAssignmentOut(BaseModel):
    """Полная карточка assignment'а (для detailed progress)."""

    model_config = ConfigDict(from_attributes=True)
    id: int
    user_id: int
    course_id: int
    assigned_at: datetime
    assigned_by_user_id: int | None
    deadline_at: datetime | None
    is_mandatory: bool


class LessonProgressOut(BaseModel):
    """Прогресс по одному уроку."""

    lesson_id: int
    title: str
    kind: str  # theory|video|quiz
    order_index: int
    is_required: bool
    completed_at: datetime | None
    attempts_count: int  # для quiz, иначе 0
    best_score_pct: int | None  # для quiz, иначе None


class ModuleProgressOut(BaseModel):
    """Прогресс по модулю (с вложенными уроками)."""

    module_id: int
    title: str
    order_index: int
    lessons: list[LessonProgressOut] = Field(default_factory=list)


class UserCourseDetailedProgress(BaseModel):
    """Полный прогресс одного юзера по одному курсу.

    Возвращается из GET /users/{user_id}/progress?course_id=N — детальный
    срез для admin (модули → уроки + попытки квизов + best score).
    """

    user_id: int
    course_id: int
    course_title: str
    assignment: UserCourseAssignmentOut | None  # None если курс удалён? — нет, тут assigned
    status: str
    percent: int
    started_at: datetime | None
    completed_at: datetime | None
    modules: list[ModuleProgressOut] = Field(default_factory=list)


class ResetAttemptsOut(BaseModel):
    user_id: int
    lesson_id: int
    deleted: int


class AutoAssignExistingOut(BaseModel):
    created: int
    skipped: int  # уже были assigned
    course_id: int


# ============ Helpers ============

async def _get_course(session: AsyncSession, course_id: int) -> Course:
    course = (
        await session.execute(select(Course).where(Course.id == course_id))
    ).scalar_one_or_none()
    if course is None:
        raise HTTPException(404, "Курс не найден")
    return course


async def _get_module(session: AsyncSession, module_id: int) -> CourseModule:
    module = (
        await session.execute(
            select(CourseModule).where(CourseModule.id == module_id)
        )
    ).scalar_one_or_none()
    if module is None:
        raise HTTPException(404, "Модуль не найден")
    return module


async def _get_lesson(session: AsyncSession, lesson_id: int) -> CourseLesson:
    lesson = (
        await session.execute(
            select(CourseLesson).where(CourseLesson.id == lesson_id)
        )
    ).scalar_one_or_none()
    if lesson is None:
        raise HTTPException(404, "Урок не найден")
    return lesson


async def _get_question(
    session: AsyncSession, question_id: int,
) -> LessonQuizQuestion:
    q = (
        await session.execute(
            select(LessonQuizQuestion).where(LessonQuizQuestion.id == question_id)
        )
    ).scalar_one_or_none()
    if q is None:
        raise HTTPException(404, "Вопрос не найден")
    return q


def _vex(exc: ValueError) -> HTTPException:
    """ValueError → HTTPException(400) с RU-сообщением."""
    return HTTPException(400, str(exc))


def _lesson_state_to_progress(
    lesson: CourseLesson,
    lesson_state: dict[str, Any] | None,
    quiz_attempts_stats: dict[int, dict[str, int | None]] | None = None,
) -> LessonProgressOut:
    """Pure-function: собирает LessonProgressOut из lesson + lesson_state + quiz stats.

    lesson_state — dict из CourseProgress.lesson_states (по ключу str(lesson.id)).
    quiz_attempts_stats — dict {lesson_id: {"attempts_count": N, "best_score_pct": X}}
    (берётся из агрегата QuizAttempt; для не-quiz уроков — пусто/0).

    Извлекается отдельной функцией чтобы тестировать без БД.
    """
    completed_at: datetime | None = None
    state_attempts = 0
    state_best: int | None = None
    if isinstance(lesson_state, dict):
        raw_completed = lesson_state.get("completed_at")
        if isinstance(raw_completed, str):
            try:
                completed_at = datetime.fromisoformat(raw_completed)
            except ValueError:
                completed_at = None
        elif isinstance(raw_completed, datetime):
            completed_at = raw_completed
        raw_attempts = lesson_state.get("attempts_count")
        if isinstance(raw_attempts, int):
            state_attempts = raw_attempts
        raw_best = lesson_state.get("best_score_pct")
        if isinstance(raw_best, int):
            state_best = raw_best

    # Override from quiz_attempts_stats (если QuizAttempt-агрегат свежее lesson_states)
    quiz_attempts = 0
    quiz_best: int | None = None
    if lesson.kind == "quiz" and quiz_attempts_stats:
        stat = quiz_attempts_stats.get(lesson.id)
        if stat is not None:
            quiz_attempts = int(stat.get("attempts_count") or 0)
            quiz_best = stat.get("best_score_pct")  # может быть None если ни одной finished

    final_attempts = max(quiz_attempts, state_attempts) if lesson.kind == "quiz" else 0
    if lesson.kind == "quiz":
        if quiz_best is not None and state_best is not None:
            final_best: int | None = max(quiz_best, state_best)
        else:
            final_best = quiz_best if quiz_best is not None else state_best
    else:
        final_best = None

    return LessonProgressOut(
        lesson_id=lesson.id,
        title=lesson.title,
        kind=lesson.kind,
        order_index=lesson.order_index,
        is_required=lesson.is_required,
        completed_at=completed_at,
        attempts_count=final_attempts,
        best_score_pct=final_best,
    )


def assemble_user_course_detailed_progress(
    *,
    user_id: int,
    course: Course,
    assignment: UserCourseAssignment,
    progress: CourseProgress | None,
    modules: list[CourseModule],
    lessons_by_module: dict[int, list[CourseLesson]],
    quiz_attempts_stats: dict[int, dict[str, int | None]] | None = None,
) -> UserCourseDetailedProgress:
    """Pure-function: собирает UserCourseDetailedProgress из загруженных объектов.

    Принимает уже подгруженные modules/lessons/attempts — без походов в БД.
    Используется в endpoint и в unit-тестах (мокаем входы).
    """
    lesson_states = (
        dict(progress.lesson_states or {}) if progress is not None else {}
    )

    module_outs: list[ModuleProgressOut] = []
    for m in modules:
        lesson_outs: list[LessonProgressOut] = []
        for lesson in lessons_by_module.get(m.id, []):
            key = str(lesson.id)
            state = lesson_states.get(key) or lesson_states.get(lesson.id)
            lesson_outs.append(_lesson_state_to_progress(
                lesson,
                state if isinstance(state, dict) else None,
                quiz_attempts_stats,
            ))
        module_outs.append(ModuleProgressOut(
            module_id=m.id,
            title=m.title,
            order_index=m.order_index,
            lessons=lesson_outs,
        ))

    return UserCourseDetailedProgress(
        user_id=user_id,
        course_id=course.id,
        course_title=course.title,
        assignment=UserCourseAssignmentOut.model_validate(assignment),
        status=progress.status if progress else "not_started",
        percent=progress.percent if progress else 0,
        started_at=progress.started_at if progress else None,
        completed_at=progress.completed_at if progress else None,
        modules=module_outs,
    )


# ============ Course CRUD ============

@router.get("/courses", response_model=list[CourseOut])
async def list_courses(
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    is_published: bool | None = None,
):
    """Список всех курсов (опц. фильтр is_published)."""
    stmt = select(Course).order_by(Course.created_at.desc())
    if is_published is not None:
        stmt = stmt.where(Course.is_published.is_(is_published))
    rows = (await session.execute(stmt)).scalars().all()
    return list(rows)


@router.post("/courses", response_model=CourseOut, status_code=status.HTTP_201_CREATED)
async def create_course(
    payload: CourseCreate,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Создать курс. is_published=False по умолчанию (нельзя сразу опубликовать
    пустой курс — сначала добавь модули/уроки)."""
    try:
        target_roles = validate_target_roles(payload.target_roles)
        validate_completion_policy(payload.completion_policy)
    except ValueError as e:
        raise _vex(e) from e

    course = Course(
        title=payload.title,
        description=payload.description,
        cover_image_url=payload.cover_image_url,
        target_roles=target_roles,
        is_published=False,
        passing_score_pct=payload.passing_score_pct,
        completion_policy=payload.completion_policy,
        deadline_days=payload.deadline_days,
        created_by_user_id=current_user.id,
    )
    session.add(course)
    await session.commit()
    await session.refresh(course)
    return course


@router.get("/courses/{course_id}", response_model=CourseFullOut)
async def get_course(
    course_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Полный курс: модули + уроки + вопросы (с правильными ответами для admin)."""
    course = await _get_course(session, course_id)

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

    quiz_lesson_ids = [
        lesson.id for ls in lessons_by_module.values() for lesson in ls
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

    modules_out: list[dict[str, Any]] = []
    for m in modules:
        lessons_out = []
        for lesson in lessons_by_module.get(m.id, []):
            qs = [
                {
                    "id": q.id,
                    "lesson_id": q.lesson_id,
                    "question": q.question,
                    "kind": q.kind,
                    "options": list(q.options or []),
                    "correct_answers": list(q.correct_answers or []),
                    "points": q.points,
                    "order_index": q.order_index,
                    "explanation": q.explanation,
                }
                for q in questions_by_lesson.get(lesson.id, [])
            ] if lesson.kind == "quiz" else []
            lessons_out.append({
                "id": lesson.id,
                "module_id": lesson.module_id,
                "title": lesson.title,
                "kind": lesson.kind,
                "content_blocks": list(lesson.content_blocks or []),
                "duration_min": lesson.duration_min,
                "order_index": lesson.order_index,
                "is_required": lesson.is_required,
                "questions": qs,
            })
        modules_out.append({
            "id": m.id,
            "course_id": m.course_id,
            "title": m.title,
            "order_index": m.order_index,
            "lessons": lessons_out,
        })

    return CourseFullOut(
        id=course.id,
        title=course.title,
        description=course.description,
        cover_image_url=course.cover_image_url,
        target_roles=list(course.target_roles or []),
        is_published=course.is_published,
        passing_score_pct=course.passing_score_pct,
        completion_policy=course.completion_policy,
        deadline_days=course.deadline_days,
        created_by_user_id=course.created_by_user_id,
        created_at=course.created_at,
        updated_at=course.updated_at,
        modules=modules_out,
    )


@router.patch("/courses/{course_id}", response_model=CourseOut)
async def update_course(
    course_id: int,
    payload: CourseUpdate,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    course = await _get_course(session, course_id)
    if payload.title is not None:
        course.title = payload.title
    if payload.description is not None:
        course.description = payload.description
    if payload.cover_image_url is not None:
        course.cover_image_url = payload.cover_image_url
    if payload.target_roles is not None:
        try:
            course.target_roles = validate_target_roles(payload.target_roles)
        except ValueError as e:
            raise _vex(e) from e
    if payload.passing_score_pct is not None:
        course.passing_score_pct = payload.passing_score_pct
    if payload.completion_policy is not None:
        try:
            validate_completion_policy(payload.completion_policy)
        except ValueError as e:
            raise _vex(e) from e
        course.completion_policy = payload.completion_policy
    if payload.deadline_days is not None:
        course.deadline_days = payload.deadline_days
    await session.commit()
    await session.refresh(course)
    return course


@router.delete("/courses/{course_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_course(
    course_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Удалить курс. Запрещено если is_published=True ИЛИ есть assignments."""
    course = await _get_course(session, course_id)
    if course.is_published:
        raise HTTPException(
            409,
            "Нельзя удалить опубликованный курс. Сначала сними публикацию.",
        )
    # Проверка assignments
    cnt = (
        await session.execute(
            select(func.count(UserCourseAssignment.id))
            .where(UserCourseAssignment.course_id == course_id)
        )
    ).scalar_one()
    if cnt and cnt > 0:
        raise HTTPException(
            409,
            f"Курс уже назначен {cnt} пользователям. Сначала отзови назначения.",
        )
    await session.delete(course)
    await session.commit()


@router.post("/courses/{course_id}/publish", response_model=CourseOut)
async def publish_course(
    course_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Опубликовать курс.

    Валидация перед публикацией:
    - есть хотя бы 1 модуль
    - в каждом модуле есть хотя бы 1 урок
    - quiz-уроки имеют ≥ 1 вопрос
    - target_roles не пустой (иначе курс на всех — допустимо но warn)
    """
    course = await _get_course(session, course_id)
    if course.is_published:
        return course

    # Модули
    modules = (
        await session.execute(
            select(CourseModule).where(CourseModule.course_id == course_id)
        )
    ).scalars().all()
    if not modules:
        raise HTTPException(
            409, "Нельзя опубликовать пустой курс: добавь хотя бы 1 модуль",
        )
    module_ids = [m.id for m in modules]

    # В каждом модуле ≥1 урок
    lessons = (
        await session.execute(
            select(CourseLesson).where(CourseLesson.module_id.in_(module_ids))
        )
    ).scalars().all()
    lessons_by_module: dict[int, list[CourseLesson]] = {}
    for lesson in lessons:
        lessons_by_module.setdefault(lesson.module_id, []).append(lesson)
    for m in modules:
        if not lessons_by_module.get(m.id):
            raise HTTPException(
                409,
                f"В модуле '{m.title}' нет уроков. Добавь хотя бы 1 урок.",
            )

    # Quiz-уроки ≥1 вопрос
    quiz_lesson_ids = [lesson.id for lesson in lessons if lesson.kind == "quiz"]
    if quiz_lesson_ids:
        q_counts = (
            await session.execute(
                select(LessonQuizQuestion.lesson_id, func.count(LessonQuizQuestion.id))
                .where(LessonQuizQuestion.lesson_id.in_(quiz_lesson_ids))
                .group_by(LessonQuizQuestion.lesson_id)
            )
        ).all()
        counts_map = {lid: cnt for lid, cnt in q_counts}
        for lesson in lessons:
            if lesson.kind == "quiz" and counts_map.get(lesson.id, 0) == 0:
                raise HTTPException(
                    409,
                    f"В quiz-уроке '{lesson.title}' нет вопросов. "
                    f"Добавь хотя бы 1 вопрос.",
                )

    course.is_published = True
    await session.commit()
    await session.refresh(course)
    return course


@router.post("/courses/{course_id}/unpublish", response_model=CourseOut)
async def unpublish_course(
    course_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Снять курс с публикации. Существующие assignments остаются."""
    course = await _get_course(session, course_id)
    course.is_published = False
    await session.commit()
    await session.refresh(course)
    return course


@router.post(
    "/courses/{course_id}/auto-assign-existing",
    response_model=AutoAssignExistingOut,
)
async def auto_assign_existing(
    course_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Назначить курс всем существующим юзерам с matching role.

    Курс должен быть is_published=True. Идемпотентно: уже-назначенные пропускает.
    """
    course = await _get_course(session, course_id)
    if not course.is_published:
        raise HTTPException(
            409, "Сначала опубликуй курс (POST /publish), потом auto-assign.",
        )

    # Все active users с matching role
    target_roles = course.target_roles or []
    users = (
        await session.execute(select(User).where(User.is_active.is_(True)))
    ).scalars().all()
    matching_users = [
        u for u in users if _course_matches_role(target_roles, u.role.value)
    ]
    if not matching_users:
        return AutoAssignExistingOut(created=0, skipped=0, course_id=course_id)

    # Существующие assignments на этот курс
    existing = (
        await session.execute(
            select(UserCourseAssignment.user_id)
            .where(UserCourseAssignment.course_id == course_id)
        )
    ).scalars().all()
    existing_set = set(existing)

    now = datetime.now(UTC)
    deadline_at = None
    if course.deadline_days and course.deadline_days > 0:
        deadline_at = now + timedelta(days=int(course.deadline_days))

    created = 0
    skipped = 0
    for user in matching_users:
        if user.id in existing_set:
            skipped += 1
            continue
        assignment = UserCourseAssignment(
            user_id=user.id,
            course_id=course_id,
            assigned_at=now,
            assigned_by_user_id=current_user.id,
            deadline_at=deadline_at,
            is_mandatory=True,
        )
        session.add(assignment)
        created += 1
        # Emit webhook на каждое новое назначение
        try:
            from app.services.webhook_dispatcher import safe_dispatch_event
            await session.flush()  # получить id assignment
            await safe_dispatch_event(
                session, "course.assigned", "course", course_id,
                {
                    "course_id": course_id,
                    "course_title": course.title,
                    "user_id": user.id,
                    "user_email": user.email,
                    "assigned_at": assignment.assigned_at.isoformat(),
                    "deadline_at": (
                        assignment.deadline_at.isoformat()
                        if assignment.deadline_at else None
                    ),
                    "assigned_by_user_id": current_user.id,
                    "is_mandatory": assignment.is_mandatory,
                },
            )
        except Exception as e:  # noqa: BLE001
            logger.warning("dispatch course.assigned failed: %s", e)

    await session.commit()
    return AutoAssignExistingOut(
        created=created, skipped=skipped, course_id=course_id,
    )


# ============ Module CRUD ============

@router.post(
    "/courses/{course_id}/modules",
    response_model=ModuleOut,
    status_code=status.HTTP_201_CREATED,
)
async def create_module(
    course_id: int,
    payload: ModuleCreate,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    await _get_course(session, course_id)
    # Проверка уникальности order_index
    existing = (
        await session.execute(
            select(CourseModule.id).where(
                CourseModule.course_id == course_id,
                CourseModule.order_index == payload.order_index,
            )
        )
    ).scalar_one_or_none()
    if existing is not None:
        raise HTTPException(
            409, f"order_index={payload.order_index} уже занят в этом курсе",
        )
    module = CourseModule(
        course_id=course_id, title=payload.title, order_index=payload.order_index,
    )
    session.add(module)
    await session.commit()
    await session.refresh(module)
    return module


@router.patch("/modules/{module_id}", response_model=ModuleOut)
async def update_module(
    module_id: int,
    payload: ModuleUpdate,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    module = await _get_module(session, module_id)
    if payload.title is not None:
        module.title = payload.title
    if payload.order_index is not None and payload.order_index != module.order_index:
        # Проверка уникальности нового order_index в курсе
        clash = (
            await session.execute(
                select(CourseModule.id).where(
                    CourseModule.course_id == module.course_id,
                    CourseModule.order_index == payload.order_index,
                    CourseModule.id != module.id,
                )
            )
        ).scalar_one_or_none()
        if clash is not None:
            raise HTTPException(
                409,
                f"order_index={payload.order_index} уже занят в этом курсе",
            )
        module.order_index = payload.order_index
    await session.commit()
    await session.refresh(module)
    return module


@router.delete("/modules/{module_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_module(
    module_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    module = await _get_module(session, module_id)
    await session.delete(module)  # CASCADE → lessons → questions
    await session.commit()


# ============ Lesson CRUD ============

@router.post(
    "/modules/{module_id}/lessons",
    response_model=LessonOutAdmin,
    status_code=status.HTTP_201_CREATED,
)
async def create_lesson(
    module_id: int,
    payload: LessonCreate,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    await _get_module(session, module_id)
    try:
        validate_lesson_kind(payload.kind)
        validate_content_blocks(payload.content_blocks)
    except ValueError as e:
        raise _vex(e) from e

    # Проверка уникальности order_index
    existing = (
        await session.execute(
            select(CourseLesson.id).where(
                CourseLesson.module_id == module_id,
                CourseLesson.order_index == payload.order_index,
            )
        )
    ).scalar_one_or_none()
    if existing is not None:
        raise HTTPException(
            409, f"order_index={payload.order_index} уже занят в этом модуле",
        )

    lesson = CourseLesson(
        module_id=module_id,
        title=payload.title,
        kind=payload.kind,
        content_blocks=payload.content_blocks,
        duration_min=payload.duration_min,
        order_index=payload.order_index,
        is_required=payload.is_required,
        randomize_questions=payload.randomize_questions,
    )
    session.add(lesson)
    await session.commit()
    await session.refresh(lesson)
    return lesson


@router.patch("/lessons/{lesson_id}", response_model=LessonOutAdmin)
async def update_lesson(
    lesson_id: int,
    payload: LessonUpdate,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    lesson = await _get_lesson(session, lesson_id)
    if payload.title is not None:
        lesson.title = payload.title
    if payload.kind is not None:
        try:
            validate_lesson_kind(payload.kind)
        except ValueError as e:
            raise _vex(e) from e
        # Защита: смена с quiz на theory не удаляет вопросы — admin должен сделать это явно
        lesson.kind = payload.kind
    if payload.content_blocks is not None:
        try:
            validate_content_blocks(payload.content_blocks)
        except ValueError as e:
            raise _vex(e) from e
        lesson.content_blocks = payload.content_blocks
    if payload.duration_min is not None:
        lesson.duration_min = payload.duration_min
    if payload.order_index is not None and payload.order_index != lesson.order_index:
        clash = (
            await session.execute(
                select(CourseLesson.id).where(
                    CourseLesson.module_id == lesson.module_id,
                    CourseLesson.order_index == payload.order_index,
                    CourseLesson.id != lesson.id,
                )
            )
        ).scalar_one_or_none()
        if clash is not None:
            raise HTTPException(
                409, f"order_index={payload.order_index} уже занят в этом модуле",
            )
        lesson.order_index = payload.order_index
    if payload.is_required is not None:
        lesson.is_required = payload.is_required
    if payload.randomize_questions is not None:
        lesson.randomize_questions = payload.randomize_questions
    await session.commit()
    await session.refresh(lesson)
    return lesson


@router.delete("/lessons/{lesson_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_lesson(
    lesson_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    lesson = await _get_lesson(session, lesson_id)
    await session.delete(lesson)
    await session.commit()


# ============ Question CRUD ============

@router.post(
    "/lessons/{lesson_id}/questions",
    response_model=QuestionOutAdmin,
    status_code=status.HTTP_201_CREATED,
)
async def create_question(
    lesson_id: int,
    payload: QuestionCreate,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    lesson = await _get_lesson(session, lesson_id)
    if lesson.kind != "quiz":
        raise HTTPException(
            400,
            f"Урок имеет kind={lesson.kind!r}, вопросы доступны только для kind='quiz'",
        )
    try:
        validate_question_kind(payload.kind)
        correct = validate_correct_answers(
            payload.correct_answers, payload.options, payload.kind,
        )
    except ValueError as e:
        raise _vex(e) from e

    q = LessonQuizQuestion(
        lesson_id=lesson_id,
        question=payload.question,
        kind=payload.kind,
        options=payload.options,
        correct_answers=correct,
        points=payload.points,
        order_index=payload.order_index,
        explanation=payload.explanation,
    )
    session.add(q)
    await session.commit()
    await session.refresh(q)
    return q


@router.patch("/questions/{question_id}", response_model=QuestionOutAdmin)
async def update_question(
    question_id: int,
    payload: QuestionUpdate,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    q = await _get_question(session, question_id)
    new_options = payload.options if payload.options is not None else q.options
    new_kind = payload.kind if payload.kind is not None else q.kind
    new_correct = (
        payload.correct_answers if payload.correct_answers is not None
        else q.correct_answers
    )
    try:
        if payload.kind is not None:
            validate_question_kind(payload.kind)
        if (
            payload.kind is not None
            or payload.options is not None
            or payload.correct_answers is not None
        ):
            new_correct = validate_correct_answers(
                new_correct, list(new_options or []), new_kind,
            )
    except ValueError as e:
        raise _vex(e) from e

    if payload.question is not None:
        q.question = payload.question
    if payload.kind is not None:
        q.kind = payload.kind
    if payload.options is not None:
        q.options = payload.options
    if payload.correct_answers is not None or payload.kind is not None or payload.options is not None:
        q.correct_answers = new_correct
    if payload.points is not None:
        q.points = payload.points
    if payload.order_index is not None:
        q.order_index = payload.order_index
    if payload.explanation is not None:
        q.explanation = payload.explanation
    await session.commit()
    await session.refresh(q)
    return q


@router.delete("/questions/{question_id}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_question(
    question_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    q = await _get_question(session, question_id)
    await session.delete(q)
    await session.commit()


# ============ Manual assignment ============

@router.post("/assign", response_model=list[AssignmentOut])
async def manual_assign(
    payload: AssignRequest,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Ручное назначение курса пользователям (admin override).

    Идемпотентно: уже-назначенные пропускает.
    """
    course = await _get_course(session, payload.course_id)
    user_ids = sorted({int(x) for x in payload.user_ids if int(x) > 0})

    # Проверим что юзеры существуют
    users = (
        await session.execute(select(User).where(User.id.in_(user_ids)))
    ).scalars().all()
    found_ids = {u.id for u in users}
    if found_ids != set(user_ids):
        missing = set(user_ids) - found_ids
        raise HTTPException(400, f"Юзеры не найдены: {sorted(missing)}")

    # Существующие assignments на этот курс
    existing = (
        await session.execute(
            select(UserCourseAssignment).where(
                UserCourseAssignment.course_id == payload.course_id,
                UserCourseAssignment.user_id.in_(user_ids),
            )
        )
    ).scalars().all()
    existing_by_user = {a.user_id: a for a in existing}

    now = datetime.now(UTC)
    deadline_days = payload.deadline_days if payload.deadline_days is not None else course.deadline_days
    deadline_at = None
    if deadline_days and deadline_days > 0:
        deadline_at = now + timedelta(days=int(deadline_days))

    out: list[AssignmentOut] = []
    for user in users:
        if user.id in existing_by_user:
            out.append(AssignmentOut.model_validate(existing_by_user[user.id]))
            continue
        a = UserCourseAssignment(
            user_id=user.id,
            course_id=payload.course_id,
            assigned_at=now,
            assigned_by_user_id=current_user.id,
            deadline_at=deadline_at,
            is_mandatory=payload.is_mandatory,
        )
        session.add(a)
        await session.flush()
        out.append(AssignmentOut.model_validate(a))

        try:
            from app.services.webhook_dispatcher import safe_dispatch_event
            await safe_dispatch_event(
                session, "course.assigned", "course", payload.course_id,
                {
                    "course_id": payload.course_id,
                    "course_title": course.title,
                    "user_id": user.id,
                    "user_email": user.email,
                    "assigned_at": a.assigned_at.isoformat(),
                    "deadline_at": a.deadline_at.isoformat() if a.deadline_at else None,
                    "assigned_by_user_id": current_user.id,
                    "is_mandatory": a.is_mandatory,
                },
            )
        except Exception as e:  # noqa: BLE001
            logger.warning("dispatch course.assigned failed: %s", e)

    await session.commit()
    return out


@router.delete(
    "/assignments/{assignment_id}",
    status_code=status.HTTP_204_NO_CONTENT,
)
async def revoke_assignment(
    assignment_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Снять назначение курса юзеру (admin override).

    Удаляет ТОЛЬКО UserCourseAssignment запись.

    НЕ удаляет CourseProgress и QuizAttempt — прогресс сохраняется в БД
    для аудита и для случая повторного назначения (через unique constraint
    user_id+course_id старый прогресс автоматически подхватится).

    404 если assignment не найден. Логируем `course.unassigned` в logger
    (audit_log сейчас не поддерживает entity_type='course', см. AUDIT_ENTITY_TYPES).
    """
    assignment = (
        await session.execute(
            select(UserCourseAssignment).where(
                UserCourseAssignment.id == assignment_id
            )
        )
    ).scalar_one_or_none()
    if assignment is None:
        raise HTTPException(404, "Назначение не найдено")

    user_id = assignment.user_id
    course_id = assignment.course_id
    await session.delete(assignment)
    await session.commit()
    logger.info(
        "course.unassigned: assignment_id=%s user_id=%s course_id=%s by_user_id=%s",
        assignment_id, user_id, course_id, current_user.id,
    )


# ============ Progress matrix ============

@router.get("/progress", response_model=list[ProgressMatrixRow])
async def progress_matrix(
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    course_id: int | None = None,
    user_id: int | None = None,
):
    """Матрица user × course с прогрессом.

    Без фильтров — все assignments всех юзеров.
    С course_id — только этот курс.
    С user_id — только этот юзер.

    Денормализовано — для таблицы admin UI. Сортировка: по user_id, потом course_id.
    """
    stmt = (
        select(UserCourseAssignment, User, Course, CourseProgress)
        .join(User, User.id == UserCourseAssignment.user_id)
        .join(Course, Course.id == UserCourseAssignment.course_id)
        .outerjoin(
            CourseProgress,
            and_(
                CourseProgress.user_id == UserCourseAssignment.user_id,
                CourseProgress.course_id == UserCourseAssignment.course_id,
            ),
        )
        .order_by(UserCourseAssignment.user_id, UserCourseAssignment.course_id)
    )
    if course_id is not None:
        stmt = stmt.where(UserCourseAssignment.course_id == course_id)
    if user_id is not None:
        stmt = stmt.where(UserCourseAssignment.user_id == user_id)

    rows = (await session.execute(stmt)).all()
    out: list[ProgressMatrixRow] = []
    now = datetime.now(UTC)
    for assignment, user, course, progress in rows:
        live_status = "not_started"
        if progress is not None and progress.status == "completed":
            live_status = "completed"
        elif assignment.deadline_at is not None and assignment.deadline_at < now:
            live_status = "overdue"
        elif progress is not None:
            live_status = progress.status or "not_started"

        out.append(ProgressMatrixRow(
            user_id=user.id,
            user_full_name=user.full_name,
            user_email=user.email,
            user_role=user.role.value,
            course_id=course.id,
            course_title=course.title,
            assignment_id=assignment.id,
            deadline_at=assignment.deadline_at,
            is_mandatory=assignment.is_mandatory,
            progress_status=live_status,
            progress_percent=progress.percent if progress else 0,
            started_at=progress.started_at if progress else None,
            completed_at=progress.completed_at if progress else None,
        ))
    return out


@router.get("/users/{user_id}/progress", response_model=None)
async def user_progress(
    user_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    course_id: int | None = None,
) -> UserProgressDetail | UserCourseDetailedProgress:
    """Прогресс одного юзера.

    Без `course_id` → общий обзор по всем назначенным курсам (UserProgressDetail).
    С `course_id` → детальный прогресс по одному курсу: модули + уроки +
    попытки квизов + best score (UserCourseDetailedProgress).

    404 если user не найден; 404 если course_id задан но assignment не существует.
    """
    user = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    if user is None:
        raise HTTPException(404, "Пользователь не найден")

    if course_id is None:
        rows = await progress_matrix(
            current_user, session, course_id=None, user_id=user_id,
        )
        return UserProgressDetail(
            user_id=user.id,
            user_full_name=user.full_name,
            courses=rows,
        )

    # Detailed path: грузим course + assignment + modules + lessons + quiz_attempts
    course = (
        await session.execute(select(Course).where(Course.id == course_id))
    ).scalar_one_or_none()
    if course is None:
        raise HTTPException(404, "Курс не найден")

    assignment = (
        await session.execute(
            select(UserCourseAssignment).where(
                UserCourseAssignment.user_id == user_id,
                UserCourseAssignment.course_id == course_id,
            )
        )
    ).scalar_one_or_none()
    if assignment is None:
        raise HTTPException(404, "Курс не назначен этому юзеру")

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

    # Quiz attempts stats: для каждого quiz-урока — кол-во попыток и best score.
    quiz_lesson_ids = [
        lesson.id
        for lessons in lessons_by_module.values()
        for lesson in lessons
        if lesson.kind == "quiz"
    ]
    quiz_attempts_stats: dict[int, dict[str, int | None]] = {}
    if quiz_lesson_ids:
        rows = (
            await session.execute(
                select(
                    QuizAttempt.lesson_id,
                    func.count(QuizAttempt.id).label("attempts_count"),
                    func.max(QuizAttempt.score_pct).label("best_score_pct"),
                )
                .where(
                    QuizAttempt.user_id == user_id,
                    QuizAttempt.lesson_id.in_(quiz_lesson_ids),
                )
                .group_by(QuizAttempt.lesson_id)
            )
        ).all()
        for lid, attempts_count, best_score_pct in rows:
            quiz_attempts_stats[lid] = {
                "attempts_count": int(attempts_count or 0),
                "best_score_pct": (
                    int(best_score_pct) if best_score_pct is not None else None
                ),
            }

    progress = (
        await session.execute(
            select(CourseProgress).where(
                CourseProgress.user_id == user_id,
                CourseProgress.course_id == course_id,
            )
        )
    ).scalar_one_or_none()

    return assemble_user_course_detailed_progress(
        user_id=user_id,
        course=course,
        assignment=assignment,
        progress=progress,
        modules=list(modules),
        lessons_by_module=lessons_by_module,
        quiz_attempts_stats=quiz_attempts_stats,
    )


# ============ Reset attempts ============

@router.post(
    "/quiz-attempts/reset",
    response_model=ResetAttemptsOut,
)
async def reset_quiz_attempts(
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    user_id: int,
    lesson_id: int,
):
    """Сбросить все попытки квиза для (user, lesson).

    Удаляет ВСЕ QuizAttempt'ы (и открытые, и финишированные). Также сбрасывает
    lesson_state в CourseProgress (если был).

    Используется когда admin хочет дать второй шанс юзеру (после фидбэка).
    """
    user = (
        await session.execute(select(User).where(User.id == user_id))
    ).scalar_one_or_none()
    if user is None:
        raise HTTPException(404, "Пользователь не найден")
    lesson = await _get_lesson(session, lesson_id)

    # Удаляем attempts
    result = await session.execute(
        delete(QuizAttempt).where(
            QuizAttempt.user_id == user_id,
            QuizAttempt.lesson_id == lesson_id,
        )
    )
    deleted = result.rowcount or 0

    # Сбрасываем lesson_state в CourseProgress (если есть)
    module = (
        await session.execute(
            select(CourseModule).where(CourseModule.id == lesson.module_id)
        )
    ).scalar_one_or_none()
    if module is not None:
        progress = (
            await session.execute(
                select(CourseProgress).where(
                    CourseProgress.user_id == user_id,
                    CourseProgress.course_id == module.course_id,
                )
            )
        ).scalar_one_or_none()
        if progress is not None and progress.lesson_states:
            states = dict(progress.lesson_states)
            states.pop(str(lesson_id), None)
            states.pop(lesson_id, None)  # на случай если ключ — int
            progress.lesson_states = states
            # Пересчитаем percent + status
            from app.services.onboarding.progress import recompute_course_status
            course = (
                await session.execute(
                    select(Course).where(Course.id == module.course_id)
                )
            ).scalar_one_or_none()
            if course is not None:
                await recompute_course_status(session, progress, course)

    await session.commit()
    return ResetAttemptsOut(
        user_id=user_id, lesson_id=lesson_id, deleted=deleted,
    )


# ============ Bulk-reorder (Tech Sprint Фаза 0) ============


class _ReorderOrderIndexItem(BaseModel):
    id: int
    order_index: int


class _ReorderOut(BaseModel):
    updated: int


@router.patch(
    "/courses/{course_id}/modules/reorder", response_model=_ReorderOut,
)
async def reorder_course_modules(
    course_id: int,
    payload: list[_ReorderOrderIndexItem],
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Bulk-reorder модулей курса (DirectorOrAdmin).

    UNIQUE(course_id, order_index) → используем apply_reorder_unique с tmp-shift.
    """
    await _get_course(session, course_id)
    items_dict = [it.model_dump() for it in payload]
    pairs = validate_reorder_payload(items_dict, order_field="order_index")
    count = await apply_reorder_unique(
        session,
        CourseModule,
        pairs,
        scope_filter=(CourseModule.course_id == course_id),
        order_field="order_index",
    )
    await session.commit()
    return _ReorderOut(updated=count)


@router.patch(
    "/courses/{course_id}/modules/{module_id}/lessons/reorder",
    response_model=_ReorderOut,
)
async def reorder_module_lessons(
    course_id: int,
    module_id: int,
    payload: list[_ReorderOrderIndexItem],
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Bulk-reorder уроков модуля (DirectorOrAdmin).

    course_id из URL — для валидации что module.course_id совпадает.
    UNIQUE(module_id, order_index) → tmp-shift алгоритм.
    """
    module = await _get_module(session, module_id)
    if module.course_id != course_id:
        raise HTTPException(
            400, f"Модуль {module_id} принадлежит курсу {module.course_id}, не {course_id}",
        )
    items_dict = [it.model_dump() for it in payload]
    pairs = validate_reorder_payload(items_dict, order_field="order_index")
    count = await apply_reorder_unique(
        session,
        CourseLesson,
        pairs,
        scope_filter=(CourseLesson.module_id == module_id),
        order_field="order_index",
    )
    await session.commit()
    return _ReorderOut(updated=count)


@router.patch(
    "/lessons/{lesson_id}/quiz-questions/reorder", response_model=_ReorderOut,
)
async def reorder_quiz_questions(
    lesson_id: int,
    payload: list[_ReorderOrderIndexItem],
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Bulk-reorder quiz-вопросов урока (DirectorOrAdmin).

    Order_index не имеет UNIQUE на question, но есть индекс
    ix_lesson_quiz_questions_lesson_id_order — используем simple apply.
    """
    lesson = await _get_lesson(session, lesson_id)
    if lesson.kind != "quiz":
        raise HTTPException(400, "Урок не quiz — нет вопросов для reorder")
    items_dict = [it.model_dump() for it in payload]
    pairs = validate_reorder_payload(items_dict, order_field="order_index")
    from app.services.bulk_reorder import apply_reorder_simple
    count = await apply_reorder_simple(
        session,
        LessonQuizQuestion,
        pairs,
        scope_filter=(LessonQuizQuestion.lesson_id == lesson_id),
        order_field="order_index",
    )
    await session.commit()
    return _ReorderOut(updated=count)


class _ContentBlocksReorderIn(BaseModel):
    """body для content_blocks reorder — массив целиком в желаемом порядке."""

    content_blocks: list[dict[str, Any]]


@router.patch(
    "/lessons/{lesson_id}/content-blocks/reorder", response_model=LessonOutAdmin,
)
async def reorder_lesson_content_blocks(
    lesson_id: int,
    payload: _ContentBlocksReorderIn,
    _: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Bulk-reorder content_blocks урока через PATCH массива (DirectorOrAdmin).

    content_blocks хранится JSONB-массивом — порядок = индекс в массиве.
    Передаём новый массив целиком (frontend отправляет блоки в желаемом
    порядке). Валидация через validate_content_blocks.
    """
    lesson = await _get_lesson(session, lesson_id)
    if lesson.kind == "quiz":
        raise HTTPException(
            400, "Quiz-уроки не имеют content_blocks; используй reorder quiz-questions",
        )
    try:
        validate_content_blocks(payload.content_blocks)
    except ValueError as e:
        raise _vex(e) from e
    lesson.content_blocks = payload.content_blocks
    await session.commit()
    await session.refresh(lesson)
    return lesson
