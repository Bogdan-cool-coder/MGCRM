"""Аналитика онбординга (Эпик 17).

Endpoints:
- GET  /admin/onboarding/analytics/overview           — сводные KPI
- GET  /admin/onboarding/analytics/hard-questions     — топ-5 сложных вопросов
- GET  /admin/onboarding/analytics/funnel/{course_id} — воронка отвала курса
- GET  /admin/onboarding/analytics/funnel/{course_id}/step/{step_key} — drill-down
- GET  /admin/onboarding/analytics/team-progress      — прогресс команды
- POST /admin/onboarding/analytics/export             — Excel-выгрузка

Доступ: DirectorOrAdmin (admin / director).
Manager: /team-progress — видит только пользователей своего department_id.
"""
from __future__ import annotations

from datetime import UTC, datetime, timedelta
from io import BytesIO
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, Response
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field
from sqlalchemy import and_, case, func, or_
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.db import get_session
from app.deps import DirectorOrAdmin
from app.models import (
    Course,
    CourseLesson,
    CourseModule,
    CourseProgress,
    Department,
    LessonQuizQuestion,
    QuizAttempt,
    User,
    UserCourseAssignment,
    UserRole,
)
from app.services.analytics_onboarding import (
    classify_funnel_step,
    compute_avg_completion_hours,
    compute_completion_rate,
    compute_funnel_steps,
    fill_daily_gaps_dict,
    fill_daily_gaps_int,
    sort_hard_questions,
    build_hard_questions_xlsx,
    build_overview_xlsx,
    build_team_progress_xlsx,
)

_XLSX_MEDIA = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"

router = APIRouter(prefix="/admin/onboarding/analytics", tags=["analytics-onboarding"])


# ============ Pydantic response schemas ============


class OverviewTotals(BaseModel):
    courses_active: int
    assignments_total: int
    completions_total: int
    completion_rate_pct: float
    avg_completion_hours: float
    active_learners_30d: int
    overdue_mandatory: int
    assignments_new_30d: int


class CourseCompletionItem(BaseModel):
    course_id: int
    title: str
    completed: int


class StatusDistribution(BaseModel):
    assigned: int
    in_progress: int
    completed: int
    overdue: int


class ActivityPoint(BaseModel):
    date: str
    count: int


class OverviewResponse(BaseModel):
    """Сводные KPI онбординга.

    Содержит ДВА набора полей:
    - `totals` + `sparkline_assignments_per_day` + ... — старый shape,
      используется в Excel-экспорте (`/export`).
    - Плоские дубликаты (`total_courses`, `total_assignments`,
      `completion_rate_pct`, `avg_time_to_complete_hours`, ...) — то, что
      реально читает фронт `OverviewKpiRow.tsx` (ожидает плоский объект,
      без обёртки `.totals`).

    Все numeric поля гарантированно `float`/`int` (без None) — фронт зовёт
    `.toFixed(1)` без null-guard'а, поэтому при отсутствии данных
    возвращаем 0.0 / 0, а не None.

    `avg_time_to_complete_hours` — единственное исключение, `float | None`,
    но фронт обрабатывает null (есть `formatAvgTime(null)` → '—'). На
    backend мы всё равно отдаём 0.0 если данных нет — фронт это покажет
    как '0мин', что приемлемо.
    """

    # === Старый shape (для xlsx-экспорта) ===
    totals: OverviewTotals
    sparkline_assignments_per_day: list[int]
    completions_by_course: list[CourseCompletionItem]
    status_distribution: StatusDistribution
    activity_by_day_90d: list[ActivityPoint]

    # === Плоский shape (для фронта `OverviewKpiRow.tsx`) ===
    total_courses: int = 0
    total_assignments: int = 0
    new_assignments_30d: int = 0
    total_completed: int = 0
    completion_rate_pct: float = 0.0
    avg_time_to_complete_hours: float | None = None
    active_learners_30d: int = 0
    overdue_mandatory: int = 0
    courses_sparkline_30d: list[int] = Field(default_factory=list)


class HardQuestionItem(BaseModel):
    question_id: int
    question_text: str
    course_id: int
    course_name: str
    lesson_id: int
    lesson_name: str
    total_attempts: int
    correct_attempts: int
    success_rate_pct: float


class FunnelStep(BaseModel):
    step_key: str
    step_label: str
    count: int
    pct_of_total: float


class FunnelResponse(BaseModel):
    course_id: int
    course_name: str
    steps: list[FunnelStep]


class DrillDownUser(BaseModel):
    user_id: int
    full_name: str
    email: str
    department_name: str | None
    last_activity_at: datetime | None


class TeamProgressItem(BaseModel):
    user_id: int
    full_name: str
    email: str
    department_name: str | None
    assignments_total: int
    completed_count: int
    in_progress_count: int
    overdue_count: int
    progress_pct: float
    last_activity_at: datetime | None


class TeamProgressResponse(BaseModel):
    items: list[TeamProgressItem]
    total: int


class ExportRequest(BaseModel):
    report_type: str  # 'overview' | 'team_progress' | 'hard_questions'
    filters: dict[str, Any] = {}


# ============ Helpers ============


def _live_status(
    status_from_db: str | None,
    deadline_at: datetime | None,
    now: datetime,
) -> str:
    """Пересчитать live-статус с учётом дедлайна (аналогично admin_onboarding)."""
    if status_from_db == "completed":
        return "completed"
    if deadline_at is not None and deadline_at < now:
        return "overdue"
    return status_from_db or "not_started"


def _department_scope(current_user: User) -> int | None | bool:
    """Скоуп отдела для аналитики онбординга по роли.

    Возвращает:
      • None  — admin: видит все отделы (без ограничения);
      • int   — director/manager: только свой department_id;
      • False — director/manager без department_id: доступ к именным данным
                других отделов запрещён → вернуть пусто.

    Зачем: эндпоинты с ИМЕННЫМИ success-данными (funnel-step-users, team-progress)
    и срезы (funnel, hard-questions) не должны раскрывать сотрудников чужих отделов
    директору. Admin — кросс-отдельный по дизайну.
    """
    if current_user.role == UserRole.admin:
        return None
    # director / manager (и любая иная не-admin роль, прошедшая гейт): свой отдел.
    if current_user.department_id is None:
        return False
    return current_user.department_id


# ============ GET /overview ============


@router.get("/overview", response_model=OverviewResponse)
async def analytics_overview(
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    department_id: int | None = Query(None),
    manager_id: int | None = Query(None),
    period_days: int = Query(30, ge=1, le=365),
):
    """Сводные KPI онбординга + sparkline назначений + доп. срезы.

    ACL (fail-CLOSED): _department_scope ограничивает директора его отделом.
    admin видит все отделы (department_id из запроса работает как фильтр).
    director/другие не-admin — только свой department_id, параметр из запроса игнорируется.
    """
    now = datetime.now(UTC)
    period_start = now - timedelta(days=period_days)
    start_30d = now - timedelta(days=30)

    # ACL: применяем department scope аналогично sibling-эндпоинтам (hard_questions, funnel).
    dept_scope = _department_scope(current_user)
    if dept_scope is False:
        # Нет отдела у non-admin → пустой результат (fail-CLOSED).
        return OverviewResponse(
            totals=OverviewTotals(
                courses_active=0, assignments_total=0, completions_total=0,
                completion_rate_pct=0.0, avg_completion_hours=0.0,
                active_learners_30d=0, overdue_mandatory=0, assignments_new_30d=0,
            ),
            sparkline_assignments_per_day=[],
            completions_by_course=[],
            status_distribution=StatusDistribution(assigned=0, in_progress=0, completed=0, overdue=0),
            activity_by_day_90d=[],
        )
    if dept_scope is not None:
        # Non-admin: форсируем свой department_id, игнорируем параметр из запроса.
        department_id = dept_scope

    # Базовый запрос assignments (опционально с department/manager фильтром по user)
    assign_stmt = (
        select(
            UserCourseAssignment,
            User,
            CourseProgress,
        )
        .join(User, User.id == UserCourseAssignment.user_id)
        .outerjoin(
            CourseProgress,
            and_(
                CourseProgress.user_id == UserCourseAssignment.user_id,
                CourseProgress.course_id == UserCourseAssignment.course_id,
            ),
        )
    )
    if department_id is not None:
        assign_stmt = assign_stmt.where(User.department_id == department_id)
    if manager_id is not None:
        assign_stmt = assign_stmt.where(User.manager_id == manager_id)

    rows = (await session.execute(assign_stmt)).all()

    # --- Базовые счётчики ---
    assignments_total = len(rows)
    now_ts = now

    completions_total = 0
    overdue_mandatory = 0
    assignments_new_30d = 0
    completion_pairs: list[tuple[datetime | None, datetime | None]] = []
    status_counts = {"assigned": 0, "in_progress": 0, "completed": 0, "overdue": 0}

    for assignment, user, progress in rows:
        live = _live_status(
            progress.status if progress else None,
            assignment.deadline_at,
            now_ts,
        )

        if live == "completed":
            completions_total += 1
            completion_pairs.append((assignment.assigned_at, progress.completed_at if progress else None))

        if live == "overdue" and assignment.is_mandatory:
            overdue_mandatory += 1

        if assignment.assigned_at and assignment.assigned_at >= start_30d:
            assignments_new_30d += 1

        # status distribution
        if live in status_counts:
            status_counts[live] += 1
        else:
            status_counts["assigned"] += 1

    completion_rate = compute_completion_rate(assignments_total, completions_total)
    avg_hours = compute_avg_completion_hours(completion_pairs)

    # --- Активные курсы ---
    courses_active = (
        await session.execute(
            select(func.count(Course.id)).where(Course.is_published.is_(True))
        )
    ).scalar_one() or 0

    # --- Active learners 30d: уникальные user_id с QuizAttempt за 30д ---
    al_stmt = (
        select(func.count(func.distinct(QuizAttempt.user_id)))
        .where(QuizAttempt.started_at >= start_30d)
    )
    if department_id is not None or manager_id is not None:
        # Нужен JOIN на users для фильтра
        user_subq = select(User.id)
        if department_id is not None:
            user_subq = user_subq.where(User.department_id == department_id)
        if manager_id is not None:
            user_subq = user_subq.where(User.manager_id == manager_id)
        al_stmt = al_stmt.where(QuizAttempt.user_id.in_(user_subq))
    active_learners_30d = (await session.execute(al_stmt)).scalar_one() or 0

    # --- Sparkline: назначений по дням за period_days ---
    sparkline_stmt = (
        select(
            func.date_trunc("day", UserCourseAssignment.assigned_at).label("day"),
            func.count(UserCourseAssignment.id).label("cnt"),
        )
        .where(UserCourseAssignment.assigned_at >= period_start)
        .group_by("day")
        .order_by("day")
    )
    if department_id is not None or manager_id is not None:
        sparkline_stmt = sparkline_stmt.join(
            User, User.id == UserCourseAssignment.user_id
        )
        if department_id is not None:
            sparkline_stmt = sparkline_stmt.where(User.department_id == department_id)
        if manager_id is not None:
            sparkline_stmt = sparkline_stmt.where(User.manager_id == manager_id)

    spark_rows = (await session.execute(sparkline_stmt)).all()
    spark_pairs = [
        (r.day.date() if hasattr(r.day, "date") else r.day, r.cnt)
        for r in spark_rows
    ]
    sparkline = fill_daily_gaps_int(spark_pairs, period_start.date(), now.date())

    # --- Activity by day (90d): QuizAttempt ---
    activity_start = now - timedelta(days=90)
    act_stmt = (
        select(
            func.date_trunc("day", QuizAttempt.started_at).label("day"),
            func.count(func.distinct(QuizAttempt.user_id)).label("cnt"),
        )
        .where(QuizAttempt.started_at >= activity_start)
        .group_by("day")
        .order_by("day")
    )
    if department_id is not None or manager_id is not None:
        user_subq2 = select(User.id)
        if department_id is not None:
            user_subq2 = user_subq2.where(User.department_id == department_id)
        if manager_id is not None:
            user_subq2 = user_subq2.where(User.manager_id == manager_id)
        act_stmt = act_stmt.where(QuizAttempt.user_id.in_(user_subq2))

    act_rows = (await session.execute(act_stmt)).all()
    act_pairs = [
        (r.day.date() if hasattr(r.day, "date") else r.day, r.cnt)
        for r in act_rows
    ]
    activity_90d_raw = fill_daily_gaps_dict(act_pairs, activity_start.date(), now.date())
    activity_90d = [ActivityPoint(**p) for p in activity_90d_raw]

    # --- Completions by course (top 10) ---
    comp_by_course_stmt = (
        select(
            Course.id,
            Course.title,
            func.count(CourseProgress.id).label("completed_cnt"),
        )
        .join(CourseProgress, CourseProgress.course_id == Course.id)
        .where(CourseProgress.status == "completed")
        .group_by(Course.id, Course.title)
        .order_by(func.count(CourseProgress.id).desc())
        .limit(10)
    )
    if department_id is not None or manager_id is not None:
        user_subq3 = select(User.id)
        if department_id is not None:
            user_subq3 = user_subq3.where(User.department_id == department_id)
        if manager_id is not None:
            user_subq3 = user_subq3.where(User.manager_id == manager_id)
        comp_by_course_stmt = comp_by_course_stmt.where(
            CourseProgress.user_id.in_(user_subq3)
        )
    comp_rows = (await session.execute(comp_by_course_stmt)).all()
    completions_by_course = [
        CourseCompletionItem(course_id=r.id, title=r.title, completed=r.completed_cnt)
        for r in comp_rows
    ]

    # ВАЖНО: cast numeric → правильный тип, чтобы фронт не падал на .toFixed(...)
    # - completion_rate / avg_hours: гарантированно float (см. сервис).
    # - courses_active / *_count: SQL может вернуть None → fallback 0 уже сделан.
    # - avg_time_to_complete_hours на фронте — `number | null`. У нас сервис
    #   возвращает 0.0 для пустого набора; мы отдаём 0.0 (не None), фронт
    #   нарисует "0мин" — это лучше чем падать в null.
    flat_completion_rate = float(completion_rate or 0.0)
    flat_avg_hours = float(avg_hours or 0.0)

    return OverviewResponse(
        totals=OverviewTotals(
            courses_active=int(courses_active or 0),
            assignments_total=int(assignments_total or 0),
            completions_total=int(completions_total or 0),
            completion_rate_pct=flat_completion_rate,
            avg_completion_hours=flat_avg_hours,
            active_learners_30d=int(active_learners_30d or 0),
            overdue_mandatory=int(overdue_mandatory or 0),
            assignments_new_30d=int(assignments_new_30d or 0),
        ),
        sparkline_assignments_per_day=sparkline,
        completions_by_course=completions_by_course,
        status_distribution=StatusDistribution(**status_counts),
        activity_by_day_90d=activity_90d,
        # Плоский shape (frontend OverviewKpiRow.tsx)
        total_courses=int(courses_active or 0),
        total_assignments=int(assignments_total or 0),
        new_assignments_30d=int(assignments_new_30d or 0),
        total_completed=int(completions_total or 0),
        completion_rate_pct=flat_completion_rate,
        avg_time_to_complete_hours=flat_avg_hours,
        active_learners_30d=int(active_learners_30d or 0),
        overdue_mandatory=int(overdue_mandatory or 0),
        courses_sparkline_30d=sparkline,
    )


# ============ GET /hard-questions ============


@router.get("/hard-questions", response_model=list[HardQuestionItem])
async def hard_questions(
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    limit: int = Query(5, ge=1, le=20),
    course_id: int | None = Query(None),
):
    """Топ сложных вопросов (наименьший % правильных из quiz_attempts.answers).

    Фильтр: total_attempts >= 5 (статистически значимые).
    Answers JSONB: [{"question_id": int, "selected_indices": [int, ...], "is_correct": bool}]
    """
    # Разворачиваем JSONB answers через jsonb_array_elements
    # SQLAlchemy не имеет встроенного jsonb_array_elements — используем text() с subquery
    # Подход: загружаем завершённые QuizAttempt с их answers, группируем в Python
    # (приемлемо: quiz_attempts — меньше 10k записей обычно)
    # ACL: director — статистика только по сотрудникам своего отдела; admin — все.
    dept_scope = _department_scope(current_user)
    if dept_scope is False:
        return []

    stmt = (
        select(
            QuizAttempt.answers,
            QuizAttempt.lesson_id,
        )
        .where(
            QuizAttempt.finished_at.isnot(None),
            QuizAttempt.answers.isnot(None),
        )
    )
    if dept_scope is not None:
        stmt = stmt.join(User, User.id == QuizAttempt.user_id).where(
            User.department_id == dept_scope
        )
    if course_id is not None:
        # JOIN через lesson → module → course
        stmt = (
            stmt
            .join(CourseLesson, CourseLesson.id == QuizAttempt.lesson_id)
            .join(CourseModule, CourseModule.id == CourseLesson.module_id)
            .where(CourseModule.course_id == course_id)
        )

    attempt_rows = (await session.execute(stmt)).all()

    # Агрегация в Python: {question_id: {total, correct}}
    q_stats: dict[int, dict[str, int]] = {}
    for answers, lesson_id in attempt_rows:
        if not isinstance(answers, list):
            continue
        for ans in answers:
            if not isinstance(ans, dict):
                continue
            qid = ans.get("question_id")
            if qid is None:
                continue
            is_correct = bool(ans.get("is_correct", False))
            stats = q_stats.setdefault(qid, {"total": 0, "correct": 0, "lesson_id": lesson_id})
            stats["total"] += 1
            if is_correct:
                stats["correct"] += 1

    if not q_stats:
        return []

    # Загружаем данные вопросов
    qids = list(q_stats.keys())
    questions = (
        await session.execute(
            select(LessonQuizQuestion, CourseLesson, CourseModule, Course)
            .join(CourseLesson, CourseLesson.id == LessonQuizQuestion.lesson_id)
            .join(CourseModule, CourseModule.id == CourseLesson.module_id)
            .join(Course, Course.id == CourseModule.course_id)
            .where(LessonQuizQuestion.id.in_(qids))
        )
    ).all()

    raw_rows: list[dict[str, Any]] = []
    for q, lesson, module, course in questions:
        stats = q_stats.get(q.id, {})
        raw_rows.append({
            "question_id": q.id,
            "text": q.question,
            "course_id": course.id,
            "course_name": course.title,
            "lesson_id": lesson.id,
            "lesson_name": lesson.title,
            "total_attempts": stats.get("total", 0),
            "correct_attempts": stats.get("correct", 0),
        })

    sorted_rows = sort_hard_questions(raw_rows, limit=limit)
    return [
        HardQuestionItem(
            question_id=r["question_id"],
            question_text=r["text"],
            course_id=r["course_id"],
            course_name=r["course_name"],
            lesson_id=r["lesson_id"],
            lesson_name=r["lesson_name"],
            total_attempts=r["total_attempts"],
            correct_attempts=r["correct_attempts"],
            success_rate_pct=r["success_rate_pct"],
        )
        for r in sorted_rows
    ]


# ============ GET /funnel/{course_id} ============


@router.get("/funnel/{course_id}", response_model=FunnelResponse)
async def course_funnel(
    course_id: int,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Воронка отвала курса: 5 шагов (назначено → завершил)."""
    course = (
        await session.execute(select(Course).where(Course.id == course_id))
    ).scalar_one_or_none()
    if course is None:
        raise HTTPException(404, "Курс не найден")

    # ACL: director — воронка только по своему отделу; admin — все.
    dept_scope = _department_scope(current_user)
    if dept_scope is False:
        return FunnelResponse(
            course_id=course.id,
            course_name=course.title,
            steps=[FunnelStep(**s) for s in compute_funnel_steps([])],
        )

    now = datetime.now(UTC)

    # Загружаем assignments + progress
    funnel_stmt = (
        select(UserCourseAssignment, CourseProgress)
        .where(UserCourseAssignment.course_id == course_id)
        .outerjoin(
            CourseProgress,
            and_(
                CourseProgress.user_id == UserCourseAssignment.user_id,
                CourseProgress.course_id == course_id,
            ),
        )
    )
    if dept_scope is not None:
        funnel_stmt = funnel_stmt.join(
            User, User.id == UserCourseAssignment.user_id
        ).where(User.department_id == dept_scope)
    rows = (await session.execute(funnel_stmt)).all()

    assignments_data: list[dict[str, Any]] = []
    for assignment, progress in rows:
        live = _live_status(
            progress.status if progress else None,
            assignment.deadline_at,
            now,
        )
        assignments_data.append({
            "user_id": assignment.user_id,
            "percent": progress.percent if progress else 0,
            "status": live,
        })

    steps = compute_funnel_steps(assignments_data)
    return FunnelResponse(
        course_id=course.id,
        course_name=course.title,
        steps=[FunnelStep(**s) for s in steps],
    )


# ============ GET /funnel/{course_id}/step/{step_key} ============


@router.get(
    "/funnel/{course_id}/step/{step_key}",
    response_model=list[DrillDownUser],
)
async def funnel_step_users(
    course_id: int,
    step_key: str,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    limit: int = Query(50, ge=1, le=200),
):
    """Drill-down: пользователи, застрявшие на конкретном шаге воронки.

    step_key: assigned | started | half_done | near_done | completed
    """
    valid_steps = {"assigned", "started", "half_done", "near_done", "completed"}
    if step_key not in valid_steps:
        raise HTTPException(400, f"step_key должен быть одним из: {sorted(valid_steps)}")

    course = (
        await session.execute(select(Course).where(Course.id == course_id))
    ).scalar_one_or_none()
    if course is None:
        raise HTTPException(404, "Курс не найден")

    # ACL: director видит именные данные только своего отдела; admin — все.
    dept_scope = _department_scope(current_user)
    if dept_scope is False:
        return []

    now = datetime.now(UTC)

    drill_stmt = (
        select(UserCourseAssignment, CourseProgress, User, Department)
        .where(UserCourseAssignment.course_id == course_id)
        .join(User, User.id == UserCourseAssignment.user_id)
        .outerjoin(
            CourseProgress,
            and_(
                CourseProgress.user_id == UserCourseAssignment.user_id,
                CourseProgress.course_id == course_id,
            ),
        )
        .outerjoin(Department, Department.id == User.department_id)
    )
    if dept_scope is not None:
        drill_stmt = drill_stmt.where(User.department_id == dept_scope)
    drill_stmt = drill_stmt.limit(limit * 5)  # фильтруем в Python после live-статуса
    rows = (await session.execute(drill_stmt)).all()

    out: list[DrillDownUser] = []
    for assignment, progress, user, department in rows:
        live = _live_status(
            progress.status if progress else None,
            assignment.deadline_at,
            now,
        )
        pct = progress.percent if progress else 0
        user_step = classify_funnel_step(pct, live)
        if user_step != step_key:
            continue

        # last_activity: берём максимальный started_at из QuizAttempt
        last_act_row = (
            await session.execute(
                select(func.max(QuizAttempt.started_at))
                .where(
                    QuizAttempt.user_id == user.id,
                    QuizAttempt.lesson_id.in_(
                        select(CourseLesson.id)
                        .join(CourseModule, CourseModule.id == CourseLesson.module_id)
                        .where(CourseModule.course_id == course_id)
                    ),
                )
            )
        ).scalar_one_or_none()

        out.append(DrillDownUser(
            user_id=user.id,
            full_name=user.full_name,
            email=user.email,
            department_name=department.name if department else None,
            last_activity_at=last_act_row,
        ))
        if len(out) >= limit:
            break

    return out


# ============ GET /team-progress ============


@router.get("/team-progress", response_model=TeamProgressResponse)
async def team_progress(
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
    department_id: int | None = Query(None),
    manager_id: int | None = Query(None),
    course_id: int | None = Query(None),
    period_days: int | None = Query(None, ge=1, le=3650),
    offset: int = Query(0, ge=0),
    limit: int = Query(50, ge=1, le=200),
):
    """Прогресс команды по обучению.

    ACL (fail-CLOSED):
    - admin: видит все отделы (department_id из запроса применяется как фильтр).
    - director/manager: видит только пользователей своего department_id.
      Параметр department_id из запроса игнорируется — форсируется свой.
      Если department_id не задан — возвращает пустой список.

    Зависимость DirectorOrAdmin: lawyer/accountant/cfo не получают PII-данные сотрудников.

    Возвращает агрегат на user_id: всего назначений / завершено / в процессе / просрочено.
    """
    now = datetime.now(UTC)

    # ACL: _department_scope возвращает None (admin→все), int (свой отдел), False (нет отдела→пусто).
    dept_scope = _department_scope(current_user)
    if dept_scope is False:
        return TeamProgressResponse(items=[], total=0)
    if dept_scope is not None:
        # Принудительно ограничиваем своим отделом, игнорируем параметр из запроса.
        department_id = dept_scope

    # Загружаем все assignments в нужном scope
    stmt = (
        select(
            UserCourseAssignment,
            User,
            CourseProgress,
            Department,
        )
        .join(User, User.id == UserCourseAssignment.user_id)
        .outerjoin(
            CourseProgress,
            and_(
                CourseProgress.user_id == UserCourseAssignment.user_id,
                CourseProgress.course_id == UserCourseAssignment.course_id,
            ),
        )
        .outerjoin(Department, Department.id == User.department_id)
    )

    if department_id is not None:
        stmt = stmt.where(User.department_id == department_id)
    if manager_id is not None:
        stmt = stmt.where(User.manager_id == manager_id)
    if course_id is not None:
        stmt = stmt.where(UserCourseAssignment.course_id == course_id)
    if period_days is not None:
        period_start = now - timedelta(days=period_days)
        stmt = stmt.where(UserCourseAssignment.assigned_at >= period_start)

    all_rows = (await session.execute(stmt)).all()

    # Группировка по user_id
    by_user: dict[int, dict[str, Any]] = {}
    for assignment, user, progress, department in all_rows:
        uid = user.id
        if uid not in by_user:
            by_user[uid] = {
                "user_id": uid,
                "full_name": user.full_name,
                "email": user.email,
                "department_name": department.name if department else None,
                "total": 0,
                "completed": 0,
                "in_progress": 0,
                "overdue": 0,
                "sum_pct": 0,
                "last_activity_at": None,
            }

        live = _live_status(
            progress.status if progress else None,
            assignment.deadline_at,
            now,
        )
        pct = progress.percent if progress else 0

        entry = by_user[uid]
        entry["total"] += 1
        entry["sum_pct"] += pct
        if live == "completed":
            entry["completed"] += 1
        elif live == "in_progress":
            entry["in_progress"] += 1
        elif live == "overdue":
            entry["overdue"] += 1

        # last_activity: updated_at прогресса как прокси
        if progress and progress.updated_at:
            if entry["last_activity_at"] is None or progress.updated_at > entry["last_activity_at"]:
                entry["last_activity_at"] = progress.updated_at

    # Сортировка по full_name
    sorted_users = sorted(by_user.values(), key=lambda x: x["full_name"])
    total = len(sorted_users)
    paginated = sorted_users[offset: offset + limit]

    items = [
        TeamProgressItem(
            user_id=u["user_id"],
            full_name=u["full_name"],
            email=u["email"],
            department_name=u["department_name"],
            assignments_total=u["total"],
            completed_count=u["completed"],
            in_progress_count=u["in_progress"],
            overdue_count=u["overdue"],
            progress_pct=round(u["sum_pct"] / u["total"], 1) if u["total"] else 0.0,
            last_activity_at=u["last_activity_at"],
        )
        for u in paginated
    ]

    return TeamProgressResponse(items=items, total=total)


# ============ POST /export ============


@router.post("/export")
async def export_analytics(
    payload: ExportRequest,
    current_user: DirectorOrAdmin,
    session: Annotated[AsyncSession, Depends(get_session)],
):
    """Excel-экспорт аналитики онбординга.

    report_type: 'overview' | 'team_progress' | 'hard_questions'
    filters: {department_id?, manager_id?, course_id?, period_days?}
    """
    filters = payload.filters or {}
    department_id: int | None = filters.get("department_id")
    manager_id: int | None = filters.get("manager_id")
    course_id: int | None = filters.get("course_id")
    period_days: int = int(filters.get("period_days") or 30)

    if payload.report_type == "overview":
        # Переиспользуем логику overview-эндпоинта
        overview = await analytics_overview(
            current_user=current_user,
            session=session,
            department_id=department_id,
            manager_id=manager_id,
            period_days=period_days,
        )
        content = build_overview_xlsx(
            totals=overview.totals.model_dump(),
            sparkline=overview.sparkline_assignments_per_day,
            course_completions=[c.model_dump() for c in overview.completions_by_course],
        )
        filename = "onboarding_overview.xlsx"

    elif payload.report_type == "team_progress":
        progress_resp = await team_progress(
            current_user=current_user,
            session=session,
            department_id=department_id,
            manager_id=manager_id,
            course_id=course_id,
            period_days=period_days,
            offset=0,
            limit=10000,  # выгружаем всё
        )
        content = build_team_progress_xlsx(
            [item.model_dump() for item in progress_resp.items]
        )
        filename = "onboarding_team_progress.xlsx"

    elif payload.report_type == "hard_questions":
        hq = await hard_questions(
            current_user=current_user,
            session=session,
            limit=20,
            course_id=course_id,
        )
        content = build_hard_questions_xlsx([h.model_dump() for h in hq])
        filename = "onboarding_hard_questions.xlsx"

    else:
        raise HTTPException(
            400,
            f"Неизвестный report_type: {payload.report_type!r}. "
            "Допустимые: 'overview', 'team_progress', 'hard_questions'",
        )

    return Response(
        content=content,
        media_type=_XLSX_MEDIA,
        headers={"Content-Disposition": f'attachment; filename="{filename}"'},
    )
