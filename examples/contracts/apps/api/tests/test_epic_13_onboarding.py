"""Эпик 13 (Онбординг): pure-function тесты + миграция 0031 + Pydantic-схемы.

Без DB-фикстуры: проверяем валидаторы, scoring, percent calculation, randomize,
soft-gate logic, структуру миграции, типы блоков контента и URL-whitelist.
"""
from __future__ import annotations

import re
from datetime import UTC, datetime, timedelta
from pathlib import Path

import pytest

from app.models import (
    Course,
    CourseLesson,
    CourseModule,
    CourseProgress,
    LessonQuizQuestion,
    QuizAttempt,
    UserCourseAssignment,
)
from app.routers.admin_onboarding import (
    AssignmentOut,
    AssignRequest,
    CourseCreate,
    CourseFullOut,
    CourseOut,
    CourseUpdate,
    LessonCreate,
    LessonOutAdmin,
    LessonUpdate,
    ModuleCreate,
    ModuleOut,
    ProgressMatrixRow,
    QuestionCreate,
    QuestionOutAdmin,
    QuestionUpdate,
    ResetAttemptsOut,
    UserProgressDetail,
)
from app.routers.onboarding import (
    CompleteLessonOut,
    CourseDetailOut,
    CourseShortOut,
    LessonOut,
    ModuleOut as StudentModuleOut,
    MyCourseOut,
    QuestionForStudent,
    QuestionResult,
    QuizAnswerIn,
    StartQuizOut,
    SubmitQuizIn,
    SubmitQuizOut,
    WizardOut,
    WizardPatch,
)
from app.services.onboarding.auto_assign import _course_matches_role
from app.services.onboarding.courses import (
    CALLOUT_STYLES,
    CONTENT_BLOCK_KINDS,
    COURSE_COMPLETION_POLICIES,
    LESSON_KINDS,
    PROGRESS_STATUSES,
    QUESTION_KINDS,
    USER_CRM_EXPERIENCE_LEVELS,
    VIDEO_URL_WHITELIST,
    compute_course_percent,
    is_course_completed,
    validate_completion_policy,
    validate_content_blocks,
    validate_correct_answers,
    validate_lesson_kind,
    validate_question_kind,
    validate_target_roles,
)
from app.services.onboarding.progress import (
    BULK_GATED_ACTIONS,
    compute_assignment_status,
)
from app.services.onboarding.quiz import (
    RATE_LIMIT_MINUTES,
    compute_quiz_score,
    question_with_review,
    randomize_questions,
    strip_question_for_student,
)
from app.services.webhook_events import WEBHOOK_EVENTS


# ============ Whitelists ============

def test_content_block_kinds_complete():
    """Все известные виды блоков заведены и неизменны."""
    assert CONTENT_BLOCK_KINDS == frozenset({
        "markdown", "image", "drive_video", "loom_video",
        "youtube_video", "callout",
    })


def test_video_url_whitelist_has_safe_hosts():
    """Whitelist содержит безопасные домены и НЕ содержит подозрительных."""
    safe = {"drive.google.com", "loom.com", "youtube.com", "youtu.be", "vimeo.com"}
    assert safe.issubset(VIDEO_URL_WHITELIST)
    # Не должно быть произвольных или известно-небезопасных
    assert "evil.com" not in VIDEO_URL_WHITELIST
    assert "*.com" not in VIDEO_URL_WHITELIST


def test_lesson_kinds_complete():
    assert LESSON_KINDS == frozenset({"theory", "video", "quiz"})


def test_question_kinds_complete():
    assert QUESTION_KINDS == frozenset({"single", "multi"})


def test_callout_styles_complete():
    assert CALLOUT_STYLES == frozenset({"info", "warning", "success", "danger"})


def test_progress_statuses_complete():
    assert PROGRESS_STATUSES == frozenset({
        "not_started", "in_progress", "completed", "overdue",
    })


def test_completion_policies_complete():
    assert COURSE_COMPLETION_POLICIES == frozenset({"informational", "soft_gate"})


def test_user_crm_experience_levels_complete():
    assert USER_CRM_EXPERIENCE_LEVELS == frozenset({"none", "basic", "advanced"})


def test_bulk_gated_actions_include_documents():
    """bulk_generate_documents должен быть в gated actions для soft-gate."""
    assert "bulk_generate_documents" in BULK_GATED_ACTIONS


# ============ validate_target_roles ============

def test_validate_target_roles_empty_ok():
    assert validate_target_roles([]) == []


def test_validate_target_roles_sorts_dedups():
    assert validate_target_roles(["manager", "admin", "manager"]) == ["admin", "manager"]


def test_validate_target_roles_rejects_unknown():
    with pytest.raises(ValueError, match="недопустим"):
        validate_target_roles(["manager", "ceo"])


def test_validate_target_roles_rejects_non_string():
    with pytest.raises(ValueError, match="не-строку"):
        validate_target_roles(["manager", 42])


def test_validate_target_roles_rejects_non_list():
    with pytest.raises(ValueError, match="списком"):
        validate_target_roles("manager")


# ============ validate_lesson_kind / completion_policy / question_kind ============

def test_validate_lesson_kind_passes():
    validate_lesson_kind("theory")
    validate_lesson_kind("video")
    validate_lesson_kind("quiz")


def test_validate_lesson_kind_rejects():
    with pytest.raises(ValueError):
        validate_lesson_kind("unknown")


def test_validate_completion_policy_passes():
    validate_completion_policy("informational")
    validate_completion_policy("soft_gate")


def test_validate_completion_policy_rejects():
    with pytest.raises(ValueError):
        validate_completion_policy("hard_gate")


def test_validate_question_kind_passes():
    validate_question_kind("single")
    validate_question_kind("multi")


def test_validate_question_kind_rejects():
    with pytest.raises(ValueError):
        validate_question_kind("dropdown")


# ============ validate_content_blocks ============

def test_validate_content_blocks_empty_list_ok():
    validate_content_blocks([])


def test_validate_content_blocks_markdown_ok():
    validate_content_blocks([{"kind": "markdown", "text": "Hello"}])


def test_validate_content_blocks_markdown_empty_text_fails():
    with pytest.raises(ValueError, match="markdown"):
        validate_content_blocks([{"kind": "markdown", "text": "   "}])


def test_validate_content_blocks_markdown_missing_text_fails():
    with pytest.raises(ValueError, match="markdown"):
        validate_content_blocks([{"kind": "markdown"}])


def test_validate_content_blocks_image_ok():
    validate_content_blocks([{"kind": "image", "url": "https://x.com/a.jpg"}])


def test_validate_content_blocks_image_with_caption_ok():
    validate_content_blocks([{
        "kind": "image",
        "url": "https://x.com/a.jpg",
        "caption": "Подпись",
    }])


def test_validate_content_blocks_image_caption_must_be_str():
    with pytest.raises(ValueError, match="caption"):
        validate_content_blocks([{
            "kind": "image",
            "url": "https://x.com/a.jpg",
            "caption": 42,
        }])


def test_validate_content_blocks_image_javascript_url_fails():
    # stored-XSS guard: javascript:-схема в image url отбивается.
    with pytest.raises(ValueError, match="http"):
        validate_content_blocks([{"kind": "image", "url": "javascript:alert(1)"}])


def test_validate_content_blocks_image_data_url_fails():
    with pytest.raises(ValueError, match="http"):
        validate_content_blocks([
            {"kind": "image", "url": "data:text/html;base64,PHNjcmlwdD4="}
        ])


def test_validate_content_blocks_image_no_host_fails():
    with pytest.raises(ValueError, match="хост"):
        validate_content_blocks([{"kind": "image", "url": "https:///a.jpg"}])


def test_validate_content_blocks_image_url_too_long_fails():
    long_url = "https://x.com/" + "a" * 3000
    with pytest.raises(ValueError, match="длина"):
        validate_content_blocks([{"kind": "image", "url": long_url}])


def test_validate_content_blocks_markdown_too_long_fails():
    with pytest.raises(ValueError, match="длиннее"):
        validate_content_blocks([{"kind": "markdown", "text": "x" * 50_001}])


def test_validate_content_blocks_drive_video_ok():
    validate_content_blocks([{
        "kind": "drive_video",
        "drive_url": "https://drive.google.com/file/d/ABC/preview",
    }])


def test_validate_content_blocks_drive_video_wrong_host_fails():
    with pytest.raises(ValueError, match="whitelist"):
        validate_content_blocks([{
            "kind": "drive_video",
            "drive_url": "https://evil.com/file.mp4",
        }])


def test_validate_content_blocks_drive_video_non_http_fails():
    with pytest.raises(ValueError, match="http"):
        validate_content_blocks([{
            "kind": "drive_video",
            "drive_url": "javascript:alert(1)",
        }])


def test_validate_content_blocks_loom_video_ok():
    validate_content_blocks([{
        "kind": "loom_video",
        "loom_url": "https://www.loom.com/share/ABC",
    }])


def test_validate_content_blocks_youtube_video_ok():
    validate_content_blocks([{
        "kind": "youtube_video",
        "youtube_id": "dQw4w9WgXcQ",
    }])


def test_validate_content_blocks_youtube_video_bad_id_fails():
    """ID с спецсимволами должен отвергаться (защита от XSS через embed)."""
    with pytest.raises(ValueError, match="недопустим"):
        validate_content_blocks([{
            "kind": "youtube_video",
            "youtube_id": '<script>alert(1)</script>',
        }])


def test_validate_content_blocks_youtube_video_short_id_fails():
    with pytest.raises(ValueError, match="недопустим"):
        validate_content_blocks([{
            "kind": "youtube_video",
            "youtube_id": "abc",  # 3 chars < 5
        }])


def test_validate_content_blocks_callout_ok():
    for style in ("info", "warning", "success", "danger"):
        validate_content_blocks([{
            "kind": "callout", "style": style, "text": "Совет",
        }])


def test_validate_content_blocks_callout_bad_style_fails():
    with pytest.raises(ValueError, match="callout"):
        validate_content_blocks([{
            "kind": "callout", "style": "rainbow", "text": "Совет",
        }])


def test_validate_content_blocks_unknown_kind_fails():
    with pytest.raises(ValueError, match="kind"):
        validate_content_blocks([{"kind": "audio", "url": "..."}])


def test_validate_content_blocks_non_list_fails():
    with pytest.raises(ValueError, match="спис"):
        validate_content_blocks({"kind": "markdown"})


def test_validate_content_blocks_non_dict_item_fails():
    with pytest.raises(ValueError, match="не dict"):
        validate_content_blocks(["just a string"])


def test_validate_content_blocks_multiple_ok():
    """Несколько блоков подряд проходят."""
    validate_content_blocks([
        {"kind": "markdown", "text": "Введение"},
        {"kind": "youtube_video", "youtube_id": "abcde12345"},
        {"kind": "callout", "style": "info", "text": "Подсказка"},
    ])


# ============ validate_correct_answers ============

def test_validate_correct_answers_single_ok():
    assert validate_correct_answers([1], ["a", "b", "c"], "single") == [1]


def test_validate_correct_answers_multi_ok():
    assert validate_correct_answers([0, 2], ["a", "b", "c"], "multi") == [0, 2]


def test_validate_correct_answers_dedups_and_sorts():
    assert validate_correct_answers([2, 0, 0, 2], ["a", "b", "c"], "multi") == [0, 2]


def test_validate_correct_answers_out_of_range_fails():
    with pytest.raises(ValueError, match="range"):
        validate_correct_answers([5], ["a", "b"], "single")


def test_validate_correct_answers_negative_fails():
    with pytest.raises(ValueError, match="range"):
        validate_correct_answers([-1], ["a", "b"], "single")


def test_validate_correct_answers_single_must_be_one():
    with pytest.raises(ValueError, match="ровно 1"):
        validate_correct_answers([0, 1], ["a", "b"], "single")


def test_validate_correct_answers_empty_fails():
    with pytest.raises(ValueError, match="не должен быть пустым"):
        validate_correct_answers([], ["a", "b"], "single")


def test_validate_correct_answers_non_int_fails():
    with pytest.raises(ValueError, match="не-int"):
        validate_correct_answers(["0"], ["a", "b"], "single")


def test_validate_correct_answers_bool_not_int():
    """bool — подкласс int в Python, но в correct_answers это ошибка."""
    with pytest.raises(ValueError, match="не-int"):
        validate_correct_answers([True], ["a", "b"], "single")


# ============ compute_course_percent / is_course_completed ============

def test_compute_course_percent_no_lessons():
    assert compute_course_percent({}, []) == 0


def test_compute_course_percent_no_states():
    assert compute_course_percent(None, [1, 2, 3]) == 0


def test_compute_course_percent_half_done():
    states = {
        "1": {"completed_at": "2026-01-01T00:00:00+00:00"},
        "2": {"completed_at": "2026-01-02T00:00:00+00:00"},
    }
    assert compute_course_percent(states, [1, 2, 3, 4]) == 50


def test_compute_course_percent_all_done():
    states = {str(i): {"completed_at": "2026-01-01T00:00:00+00:00"} for i in range(1, 4)}
    assert compute_course_percent(states, [1, 2, 3]) == 100


def test_compute_course_percent_with_int_keys():
    """JSONB иногда возвращает int-ключи — функция должна работать."""
    states = {1: {"completed_at": "2026-01-01T00:00:00+00:00"}}
    assert compute_course_percent(states, [1]) == 100


def test_compute_course_percent_ignores_incomplete_state():
    """attempts_count без completed_at → не засчитано."""
    states = {"1": {"attempts_count": 3}}
    assert compute_course_percent(states, [1]) == 0


def test_is_course_completed_all_done():
    states = {"1": {"completed_at": "x"}, "2": {"completed_at": "y"}}
    assert is_course_completed(states, [1, 2]) is True


def test_is_course_completed_partial():
    states = {"1": {"completed_at": "x"}}
    assert is_course_completed(states, [1, 2]) is False


def test_is_course_completed_empty_required():
    """Курс без required-уроков нельзя «пройти»."""
    assert is_course_completed({}, []) is False


# ============ compute_quiz_score ============

def _q(qid: int, correct: list[int], points: int = 1) -> dict:
    """Хелпер: вопрос-словарь для теста (без БД)."""
    return {"id": qid, "correct_answers": correct, "points": points}


def test_compute_quiz_score_all_correct():
    questions = [_q(1, [0]), _q(2, [1, 2])]
    answers = [
        {"question_id": 1, "selected_indices": [0]},
        {"question_id": 2, "selected_indices": [1, 2]},
    ]
    score, passed, n = compute_quiz_score(questions, answers)
    assert score == 100
    assert passed is True
    assert n == 2


def test_compute_quiz_score_half_correct():
    questions = [_q(1, [0]), _q(2, [1])]
    answers = [
        {"question_id": 1, "selected_indices": [0]},
        {"question_id": 2, "selected_indices": [0]},  # wrong
    ]
    score, passed, n = compute_quiz_score(questions, answers)
    assert score == 50
    assert passed is False  # < 80
    assert n == 1


def test_compute_quiz_score_no_answers():
    questions = [_q(1, [0])]
    score, passed, n = compute_quiz_score(questions, [])
    assert score == 0
    assert passed is False
    assert n == 0


def test_compute_quiz_score_no_questions():
    score, passed, n = compute_quiz_score([], [{"question_id": 1, "selected_indices": [0]}])
    assert score == 0
    assert passed is False
    assert n == 0


def test_compute_quiz_score_weighted_points():
    """Вопросы с разным points правильно агрегируют score."""
    questions = [_q(1, [0], points=1), _q(2, [0], points=4)]
    answers = [
        {"question_id": 1, "selected_indices": [9]},  # wrong, 1 pt
        {"question_id": 2, "selected_indices": [0]},  # right, 4 pt
    ]
    score, passed, n = compute_quiz_score(questions, answers)
    # 4/5 = 80%
    assert score == 80
    assert passed is True


def test_compute_quiz_score_multi_partial_wrong():
    """Multi-choice: частично правильно = не засчитано (set comparison)."""
    questions = [_q(1, [0, 1, 2])]
    answers = [{"question_id": 1, "selected_indices": [0, 1]}]
    score, _, n = compute_quiz_score(questions, answers)
    assert score == 0
    assert n == 0


def test_compute_quiz_score_multi_extra_wrong():
    """Multi-choice: лишний выбранный (правильные + лишний) = не засчитано."""
    questions = [_q(1, [0, 1])]
    answers = [{"question_id": 1, "selected_indices": [0, 1, 2]}]
    score, _, _ = compute_quiz_score(questions, answers)
    assert score == 0


def test_compute_quiz_score_invalid_answer_shape_ignored():
    """answers с битым shape (selected_indices не list) → 0 для этого вопроса, не падаем."""
    questions = [_q(1, [0])]
    # selected_indices не list — функция нормализует в пустое
    answers = [{"question_id": 1, "selected_indices": "0"}]
    score, _, _ = compute_quiz_score(questions, answers)
    assert score == 0


# ============ randomize_questions ============

def test_randomize_questions_stable_seed():
    """Одинаковый seed → одинаковый порядок."""
    qs = [1, 2, 3, 4, 5]
    a = randomize_questions(qs, seed=42)
    b = randomize_questions(qs, seed=42)
    assert a == b


def test_randomize_questions_different_seeds_differ():
    """Разные seed → (с высокой вероятностью) разный порядок."""
    qs = list(range(20))  # достаточно элементов для anti-collision
    a = randomize_questions(qs, seed=1)
    b = randomize_questions(qs, seed=2)
    assert a != b


def test_randomize_questions_does_not_mutate_input():
    qs = [1, 2, 3]
    original = list(qs)
    randomize_questions(qs, seed=1)
    assert qs == original


def test_randomize_questions_empty():
    assert randomize_questions([], seed=1) == []


def test_randomize_questions_preserves_all():
    """Shuffle сохраняет все элементы (perm), не теряет/дублирует."""
    qs = list(range(10))
    out = randomize_questions(qs, seed=99)
    assert sorted(out) == sorted(qs)


# ============ Sanitizers ============

def test_strip_question_for_student_removes_correct_and_explanation():
    """strip_question_for_student НЕ должен возвращать correct_answers и explanation."""
    class FakeQ:
        id = 1
        question = "Что такое X?"
        kind = "single"
        options = ["a", "b"]
        correct_answers = [0]
        points = 1
        order_index = 0
        explanation = "Это X потому что..."

    out = strip_question_for_student(FakeQ())
    assert "correct_answers" not in out
    assert "explanation" not in out
    assert out["question"] == "Что такое X?"
    assert out["options"] == ["a", "b"]


def test_question_with_review_passed_shows_correct():
    """Если passed=True — возвращаем correct_answers + explanation."""
    class FakeQ:
        id = 1
        question = "Q?"
        kind = "single"
        options = ["a", "b"]
        correct_answers = [0]
        points = 1
        order_index = 0
        explanation = "Because a"

    out = question_with_review(FakeQ(), selected_indices=[0], passed=True)
    assert out["is_correct"] is True
    assert out["correct_answers"] == [0]
    assert out["explanation"] == "Because a"


def test_question_with_review_failed_hides_correct():
    """Если passed=False — correct_answers и explanation скрыты."""
    class FakeQ:
        id = 1
        question = "Q?"
        kind = "single"
        options = ["a", "b"]
        correct_answers = [0]
        points = 1
        order_index = 0
        explanation = "Because a"

    out = question_with_review(FakeQ(), selected_indices=[1], passed=False)
    assert out["is_correct"] is False  # видно, что неправильно
    assert "correct_answers" not in out  # но какой правильный — нет
    assert "explanation" not in out


def test_question_with_review_failed_correct_still_hidden():
    """Даже если юзер выбрал правильно, но passed=False (мало баллов в среднем) —
    скрываем правильные ответы (security)."""
    class FakeQ:
        id = 1
        question = "Q?"
        kind = "single"
        options = ["a", "b"]
        correct_answers = [0]
        points = 1
        order_index = 0
        explanation = "Because a"

    out = question_with_review(FakeQ(), selected_indices=[0], passed=False)
    assert out["is_correct"] is True
    assert "correct_answers" not in out
    assert "explanation" not in out


# ============ Auto-assign ============

def test_course_matches_role_empty_roles_matches_all():
    """Курс с пустым target_roles — назначается всем."""
    assert _course_matches_role([], "manager") is True
    assert _course_matches_role(None, "admin") is True


def test_course_matches_role_specific():
    assert _course_matches_role(["manager"], "manager") is True
    assert _course_matches_role(["manager"], "admin") is False
    assert _course_matches_role(["admin", "director"], "director") is True


# ============ compute_assignment_status ============

@pytest.mark.asyncio
async def test_compute_assignment_status_no_progress_no_deadline():
    assert await compute_assignment_status(None, None) == "not_started"


@pytest.mark.asyncio
async def test_compute_assignment_status_no_progress_overdue():
    past = datetime.now(UTC) - timedelta(days=1)
    assert await compute_assignment_status(None, past) == "overdue"


@pytest.mark.asyncio
async def test_compute_assignment_status_completed_overrides_overdue():
    """Если course completed, deadline не важен — статус остаётся completed."""
    past = datetime.now(UTC) - timedelta(days=1)
    progress = CourseProgress(
        user_id=1, course_id=1, status="completed", percent=100,
    )
    assert await compute_assignment_status(progress, past) == "completed"


@pytest.mark.asyncio
async def test_compute_assignment_status_in_progress_with_future_deadline():
    future = datetime.now(UTC) + timedelta(days=3)
    progress = CourseProgress(
        user_id=1, course_id=1, status="in_progress", percent=40,
    )
    assert await compute_assignment_status(progress, future) == "in_progress"


@pytest.mark.asyncio
async def test_compute_assignment_status_in_progress_overdue():
    """in_progress + просроченный deadline → overdue (юзер не успел)."""
    past = datetime.now(UTC) - timedelta(days=1)
    progress = CourseProgress(
        user_id=1, course_id=1, status="in_progress", percent=40,
    )
    assert await compute_assignment_status(progress, past) == "overdue"


# ============ Pydantic schemas ============

def test_course_create_validates_pass_score():
    """passing_score_pct >100 → validation error."""
    with pytest.raises(Exception):
        CourseCreate(title="X", passing_score_pct=120)


def test_course_create_validates_pass_score_negative():
    with pytest.raises(Exception):
        CourseCreate(title="X", passing_score_pct=-5)


def test_course_create_defaults():
    c = CourseCreate(title="Курс")
    assert c.passing_score_pct == 80
    assert c.completion_policy == "soft_gate"
    assert c.deadline_days == 5
    assert c.target_roles == []


def test_lesson_create_kind_field():
    """LessonCreate принимает kind строкой (без enum в pydantic)."""
    l = LessonCreate(title="Урок 1", kind="theory", order_index=0)
    assert l.kind == "theory"


def test_question_create_requires_min_2_options():
    with pytest.raises(Exception):
        QuestionCreate(
            question="Q?",
            kind="single",
            options=["only one"],
            correct_answers=[0],
            order_index=0,
        )


def test_question_create_options_capped_at_10():
    with pytest.raises(Exception):
        QuestionCreate(
            question="Q?",
            kind="single",
            options=[f"opt{i}" for i in range(11)],
            correct_answers=[0],
            order_index=0,
        )


def test_assign_request_min_user_ids():
    with pytest.raises(Exception):
        AssignRequest(user_ids=[], course_id=1)


def test_wizard_patch_optional_fields():
    """WizardPatch может быть пустым (просто DRY-сохраняет state)."""
    p = WizardPatch()
    assert p.crm_experience_level is None
    assert p.dismissed is None


def test_wizard_patch_with_values():
    p = WizardPatch(crm_experience_level="basic", dismissed=True)
    assert p.crm_experience_level == "basic"
    assert p.dismissed is True


def test_quiz_answer_in_shape():
    """Корректный shape ответа на вопрос."""
    a = QuizAnswerIn(question_id=1, selected_indices=[0, 2])
    assert a.question_id == 1
    assert a.selected_indices == [0, 2]


def test_submit_quiz_in_default_empty():
    """SubmitQuizIn принимает пустой answers (= юзер сдал ничего)."""
    s = SubmitQuizIn()
    assert s.answers == []


# ============ Webhook events whitelist ============

def test_webhook_events_include_onboarding():
    """course.assigned + course.completed должны быть в whitelist."""
    assert "course.assigned" in WEBHOOK_EVENTS
    assert "course.completed" in WEBHOOK_EVENTS


def test_webhook_events_no_lesson_completed():
    """lesson.completed НЕ должен быть в whitelist — спам, по 5-15 уроков на курс."""
    assert "lesson.completed" not in WEBHOOK_EVENTS


# ============ Migration 0031 ============

VERSIONS_DIR = Path(__file__).parent.parent / "alembic" / "versions"


def _load_migration(name: str) -> str:
    path = VERSIONS_DIR / name
    assert path.exists(), f"Migration not found: {path}"
    return path.read_text(encoding="utf-8")


def test_migration_0031_revision_id():
    """Revision id ровно '0031_onboarding_schema' (≤32 chars)."""
    src = _load_migration("0031_onboarding_schema.py")
    m = re.search(r"^revision[^=]*=\s*[\"']([^\"']+)[\"']", src, re.M)
    assert m, "revision id не найден"
    assert m.group(1) == "0031_onboarding_schema"
    assert len(m.group(1)) <= 32


def test_migration_0031_down_revision():
    """down_revision = '0030_api_tokens_webhooks'."""
    src = _load_migration("0031_onboarding_schema.py")
    m = re.search(r"^down_revision[^=]*=\s*[\"']([^\"']+)[\"']", src, re.M)
    assert m
    assert m.group(1) == "0030_api_tokens_webhooks"


def test_migration_0031_creates_all_tables():
    """Миграция создаёт все 7 ожидаемых таблиц."""
    src = _load_migration("0031_onboarding_schema.py")
    expected_tables = [
        "courses",
        "course_modules",
        "course_lessons",
        "lesson_quiz_questions",
        "user_course_assignments",
        "course_progress",
        "quiz_attempts",
    ]
    for table in expected_tables:
        assert f'create_table(\n        "{table}"' in src or \
               f'create_table("{table}"' in src, f"Не найдено create_table для {table}"


def test_migration_0031_adds_user_columns():
    """Миграция добавляет crm_experience_level + onboarding_dismissed_at в users."""
    src = _load_migration("0031_onboarding_schema.py")
    assert "crm_experience_level" in src
    assert "onboarding_dismissed_at" in src


def test_migration_0031_has_partial_unique_index():
    """Должен быть partial UNIQUE INDEX uq_quiz_attempts_open (защита от race)."""
    src = _load_migration("0031_onboarding_schema.py")
    assert "uq_quiz_attempts_open" in src
    assert "WHERE finished_at IS NULL" in src


def test_migration_0031_has_unique_constraints():
    """Уникальные ограничения на key-fields."""
    src = _load_migration("0031_onboarding_schema.py")
    assert "uq_course_module_order" in src
    assert "uq_lesson_module_order" in src
    assert "uq_user_course_assignment" in src
    assert "uq_course_progress_user_course" in src


def test_migration_0031_has_check_constraints():
    """CHECK constraints на kind / status / completion_policy."""
    src = _load_migration("0031_onboarding_schema.py")
    assert "ck_courses_completion_policy" in src
    assert "ck_course_lessons_kind" in src
    assert "ck_lesson_quiz_questions_kind" in src
    assert "ck_course_progress_status" in src
    assert "ck_users_crm_experience_level" in src


def test_migration_0031_downgrade_drops_in_reverse():
    """downgrade должен дропать всё в обратном порядке (FK-dependent first)."""
    src = _load_migration("0031_onboarding_schema.py")
    assert "def downgrade()" in src
    # users columns first (хотя order здесь не критичен — CK constraint drop, потом colы)
    assert 'drop_table("courses")' in src
    assert 'drop_table("course_modules")' in src
    assert 'drop_table("quiz_attempts")' in src
    # Hot table должна дропаться раньше parent: quiz_attempts → course_progress → ... → courses
    quiz_attempts_idx = src.index('drop_table("quiz_attempts")')
    courses_idx = src.index('drop_table("courses")')
    assert quiz_attempts_idx < courses_idx, "quiz_attempts должна дропаться раньше courses"


# ============ Models registration ============

def test_models_registered():
    """ORM-модели импортируются и имеют tablename."""
    assert Course.__tablename__ == "courses"
    assert CourseModule.__tablename__ == "course_modules"
    assert CourseLesson.__tablename__ == "course_lessons"
    assert LessonQuizQuestion.__tablename__ == "lesson_quiz_questions"
    assert UserCourseAssignment.__tablename__ == "user_course_assignments"
    assert CourseProgress.__tablename__ == "course_progress"
    assert QuizAttempt.__tablename__ == "quiz_attempts"


def test_models_have_correct_fks():
    """FK с правильными ondelete (CASCADE / SET NULL)."""
    # Проверяем что FK существуют на ожидаемые таблицы через __table__
    course_modules_fks = {
        fk.column.table.name for fk in CourseModule.__table__.foreign_keys
    }
    assert "courses" in course_modules_fks

    course_lessons_fks = {
        fk.column.table.name for fk in CourseLesson.__table__.foreign_keys
    }
    assert "course_modules" in course_lessons_fks

    quiz_attempts_fks = {
        fk.column.table.name for fk in QuizAttempt.__table__.foreign_keys
    }
    assert "users" in quiz_attempts_fks
    assert "course_lessons" in quiz_attempts_fks


# ============ Rate-limit constant ============

def test_rate_limit_minutes_reasonable():
    """RATE_LIMIT_MINUTES — защита от brute-force, должен быть умеренным."""
    assert 1 <= RATE_LIMIT_MINUTES <= 60


# ============ Badge summary aggregation (Sidebar polling) ============

from app.routers.onboarding import (  # noqa: E402
    OnboardingBadgeSummary,
    summarize_assignments_for_badge,
)


def test_summarize_assignments_empty():
    """Юзер без assignments → все счётчики нули."""
    out = summarize_assignments_for_badge([])
    assert out.overdue_count == 0
    assert out.in_progress_count == 0
    assert out.not_started_count == 0
    assert out.total_assigned == 0


def test_summarize_assignments_mixed_statuses():
    """Смешанные статусы → правильное распределение по counters."""
    statuses = [
        "overdue", "overdue",
        "in_progress",
        "not_started", "not_started", "not_started",
        "completed",  # completed НЕ попадает ни в один counter но учитывается в total
    ]
    out = summarize_assignments_for_badge(statuses)
    assert out.overdue_count == 2
    assert out.in_progress_count == 1
    assert out.not_started_count == 3
    assert out.total_assigned == 7


def test_summarize_assignments_only_completed():
    """Все курсы completed → нет badge, но total>0."""
    statuses = ["completed", "completed", "completed"]
    out = summarize_assignments_for_badge(statuses)
    assert out.overdue_count == 0
    assert out.in_progress_count == 0
    assert out.not_started_count == 0
    assert out.total_assigned == 3


def test_summarize_assignments_unknown_status_ignored():
    """Неизвестные статусы не падают, просто не попадают в counters."""
    statuses = ["weird_status", "not_started"]
    out = summarize_assignments_for_badge(statuses)
    assert out.not_started_count == 1
    assert out.total_assigned == 2  # все assignments учитываются в total


def test_onboarding_badge_summary_defaults():
    """OnboardingBadgeSummary без аргументов → все нули."""
    s = OnboardingBadgeSummary()
    assert s.overdue_count == 0
    assert s.in_progress_count == 0
    assert s.not_started_count == 0
    assert s.total_assigned == 0


# ============ Revoke assignment doesn't touch progress (semantic spec) ============

def test_revoke_assignment_semantics_spec():
    """Контракт DELETE /assignments/{id}:
    - удаляет UserCourseAssignment запись
    - НЕ трогает CourseProgress (для аудита)
    - НЕ трогает QuizAttempt (для аудита)
    - повторное назначение через unique_constraint user+course
      автоматически подхватит сохранённый прогресс

    Этот тест документирует контракт — реализация в роутере не делает
    cascade-удаление progress/attempts (см. revoke_assignment endpoint).
    """
    # CourseProgress и QuizAttempt не зависят от UserCourseAssignment через FK —
    # они оба ссылаются на User + Course (или Lesson). Проверим это в моделях.
    cp_fk_tables = {
        fk.column.table.name for fk in CourseProgress.__table__.foreign_keys
    }
    # CourseProgress зависит от users + courses, но НЕ от user_course_assignments
    assert "users" in cp_fk_tables
    assert "courses" in cp_fk_tables
    assert "user_course_assignments" not in cp_fk_tables

    qa_fk_tables = {
        fk.column.table.name for fk in QuizAttempt.__table__.foreign_keys
    }
    # QuizAttempt зависит от users + course_lessons, но НЕ от user_course_assignments
    assert "users" in qa_fk_tables
    assert "course_lessons" in qa_fk_tables
    assert "user_course_assignments" not in qa_fk_tables


# ============ Detailed user course progress assembly ============

from app.routers.admin_onboarding import (  # noqa: E402
    LessonProgressOut,
    ModuleProgressOut,
    UserCourseAssignmentOut,
    UserCourseDetailedProgress,
    _lesson_state_to_progress,
    assemble_user_course_detailed_progress,
)


def _make_lesson(
    lesson_id: int,
    module_id: int,
    kind: str = "theory",
    order_index: int = 0,
    is_required: bool = True,
    title: str = "Урок",
) -> CourseLesson:
    """Hidden helper для unit-тестов: SQLAlchemy ORM можно инстанциировать без БД."""
    return CourseLesson(
        id=lesson_id,
        module_id=module_id,
        title=title,
        kind=kind,
        content_blocks=[],
        order_index=order_index,
        is_required=is_required,
    )


def _make_module(module_id: int, course_id: int, order: int = 0) -> CourseModule:
    return CourseModule(
        id=module_id, course_id=course_id, title=f"Модуль {module_id}",
        order_index=order,
    )


def test_lesson_state_to_progress_theory_not_completed():
    """Theory-урок без state → completed_at=None, attempts=0, best=None."""
    lesson = _make_lesson(1, 10, kind="theory")
    out = _lesson_state_to_progress(lesson, None)
    assert out.lesson_id == 1
    assert out.kind == "theory"
    assert out.completed_at is None
    assert out.attempts_count == 0  # theory всегда 0
    assert out.best_score_pct is None  # theory всегда None


def test_lesson_state_to_progress_theory_completed():
    """Theory-урок с completed_at в state → парсится ISO datetime."""
    lesson = _make_lesson(1, 10, kind="theory")
    state = {"completed_at": "2026-06-01T10:00:00+00:00"}
    out = _lesson_state_to_progress(lesson, state)
    assert out.completed_at is not None
    assert out.completed_at.year == 2026
    assert out.attempts_count == 0
    assert out.best_score_pct is None


def test_lesson_state_to_progress_quiz_attempts_from_db():
    """Quiz-урок: attempts_count и best_score_pct берутся из quiz_attempts_stats."""
    lesson = _make_lesson(5, 10, kind="quiz")
    stats = {5: {"attempts_count": 3, "best_score_pct": 90}}
    out = _lesson_state_to_progress(lesson, None, stats)
    assert out.attempts_count == 3
    assert out.best_score_pct == 90


def test_lesson_state_to_progress_quiz_takes_max_attempts():
    """Если в lesson_state и в quiz_attempts разные attempts — берём max."""
    lesson = _make_lesson(5, 10, kind="quiz")
    state = {"attempts_count": 1, "best_score_pct": 75}
    stats = {5: {"attempts_count": 3, "best_score_pct": 90}}
    out = _lesson_state_to_progress(lesson, state, stats)
    assert out.attempts_count == 3  # max(1, 3)
    assert out.best_score_pct == 90  # max(75, 90)


def test_lesson_state_to_progress_quiz_no_db_stats_uses_state():
    """Если quiz_attempts_stats пустой — fallback на lesson_state."""
    lesson = _make_lesson(5, 10, kind="quiz")
    state = {"attempts_count": 2, "best_score_pct": 85}
    out = _lesson_state_to_progress(lesson, state, {})
    assert out.attempts_count == 2
    assert out.best_score_pct == 85


def test_lesson_state_to_progress_invalid_iso_completed_at_handled():
    """Битый ISO timestamp → completed_at=None, не падаем."""
    lesson = _make_lesson(1, 10, kind="theory")
    state = {"completed_at": "garbage"}
    out = _lesson_state_to_progress(lesson, state)
    assert out.completed_at is None


def test_assemble_user_course_detailed_progress_structure():
    """Сборка детального прогресса: модули и уроки в правильной иерархии."""
    course = Course(
        id=100,
        title="Базовый курс",
        is_published=True,
        target_roles=[],
        passing_score_pct=80,
        completion_policy="soft_gate",
        deadline_days=5,
    )
    now = datetime.now(UTC)
    assignment = UserCourseAssignment(
        id=1,
        user_id=42,
        course_id=100,
        assigned_at=now,
        assigned_by_user_id=None,
        deadline_at=now + timedelta(days=5),
        is_mandatory=True,
    )
    progress = CourseProgress(
        user_id=42,
        course_id=100,
        status="in_progress",
        percent=50,
        started_at=now - timedelta(days=1),
        lesson_states={
            "1": {"completed_at": "2026-06-01T10:00:00+00:00"},
            "2": {"attempts_count": 2, "best_score_pct": 85},
        },
    )
    modules = [_make_module(10, 100, 0), _make_module(20, 100, 1)]
    lessons_by_module = {
        10: [_make_lesson(1, 10, "theory", 0)],
        20: [_make_lesson(2, 20, "quiz", 0), _make_lesson(3, 20, "video", 1)],
    }

    out = assemble_user_course_detailed_progress(
        user_id=42,
        course=course,
        assignment=assignment,
        progress=progress,
        modules=modules,
        lessons_by_module=lessons_by_module,
        quiz_attempts_stats={2: {"attempts_count": 2, "best_score_pct": 85}},
    )

    assert out.user_id == 42
    assert out.course_id == 100
    assert out.course_title == "Базовый курс"
    assert out.status == "in_progress"
    assert out.percent == 50
    assert out.assignment is not None
    assert out.assignment.id == 1
    assert out.assignment.deadline_at is not None
    assert out.assignment.is_mandatory is True

    # Иерархия: 2 модуля
    assert len(out.modules) == 2
    # Module 10: 1 урок (theory completed)
    mod1 = out.modules[0]
    assert mod1.module_id == 10
    assert len(mod1.lessons) == 1
    assert mod1.lessons[0].lesson_id == 1
    assert mod1.lessons[0].completed_at is not None  # parsed from state
    # Module 20: 2 урока (quiz + video)
    mod2 = out.modules[1]
    assert mod2.module_id == 20
    assert len(mod2.lessons) == 2
    quiz_lesson = mod2.lessons[0]
    assert quiz_lesson.kind == "quiz"
    assert quiz_lesson.attempts_count == 2
    assert quiz_lesson.best_score_pct == 85
    video_lesson = mod2.lessons[1]
    assert video_lesson.kind == "video"
    assert video_lesson.attempts_count == 0  # не quiz
    assert video_lesson.best_score_pct is None


def test_assemble_user_course_detailed_progress_no_progress_yet():
    """Юзер только что назначен, progress=None → status=not_started, percent=0."""
    course = Course(
        id=100,
        title="Курс",
        is_published=True,
        target_roles=[],
        passing_score_pct=80,
        completion_policy="soft_gate",
        deadline_days=5,
    )
    assignment = UserCourseAssignment(
        id=1, user_id=42, course_id=100,
        assigned_at=datetime.now(UTC),
        assigned_by_user_id=None, deadline_at=None, is_mandatory=True,
    )
    modules = [_make_module(10, 100, 0)]
    lessons_by_module = {10: [_make_lesson(1, 10, "theory", 0)]}

    out = assemble_user_course_detailed_progress(
        user_id=42,
        course=course,
        assignment=assignment,
        progress=None,
        modules=modules,
        lessons_by_module=lessons_by_module,
    )
    assert out.status == "not_started"
    assert out.percent == 0
    assert out.started_at is None
    assert out.completed_at is None
    assert len(out.modules) == 1
    assert out.modules[0].lessons[0].completed_at is None
    assert out.modules[0].lessons[0].attempts_count == 0


def test_assemble_user_course_detailed_progress_empty_course():
    """Курс без модулей → modules=[] (нет mt падений)."""
    course = Course(
        id=100, title="Пустой",
        is_published=False, target_roles=[],
        passing_score_pct=80, completion_policy="soft_gate", deadline_days=5,
    )
    assignment = UserCourseAssignment(
        id=1, user_id=42, course_id=100,
        assigned_at=datetime.now(UTC),
        assigned_by_user_id=None, deadline_at=None, is_mandatory=True,
    )
    out = assemble_user_course_detailed_progress(
        user_id=42, course=course, assignment=assignment, progress=None,
        modules=[], lessons_by_module={},
    )
    assert out.modules == []


def test_user_course_assignment_out_from_orm():
    """UserCourseAssignmentOut правильно валидируется из ORM-объекта."""
    a = UserCourseAssignment(
        id=99, user_id=1, course_id=2,
        assigned_at=datetime.now(UTC),
        assigned_by_user_id=5, deadline_at=None, is_mandatory=False,
    )
    out = UserCourseAssignmentOut.model_validate(a)
    assert out.id == 99
    assert out.is_mandatory is False
    assert out.assigned_by_user_id == 5
