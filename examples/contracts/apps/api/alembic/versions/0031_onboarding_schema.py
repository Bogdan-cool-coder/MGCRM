"""Эпик 13 (Онбординг): схема обучающих курсов + прогресса + квизов.

Богдан наполняет контент сам через admin UI после деплоя — поэтому миграция
schema-only, без sample-courses (никаких "Sales 101 Demo" / "How to use CRM").

7 таблиц (compact версия: progress + lesson_states объединены в один row):

1. courses                 — курс верхнего уровня
2. course_modules          — модуль внутри курса
3. course_lessons          — урок (theory/video/quiz), content_blocks JSONB
4. lesson_quiz_questions   — вопросы quiz-урока (single/multi choice)
5. user_course_assignments — назначение курса юзеру (manual или auto-assign по роли)
6. course_progress         — прогресс юзера по курсу (с lesson_states JSONB)
7. quiz_attempts           — попытка прохождения quiz-урока

Плюс ADD COLUMN на users: crm_experience_level, onboarding_dismissed_at.

DDL-only. Advisory-lock на уровне env.py уже стоит для всей миграции — отдельный
не нужен (нет seed-data). См. apps/api/alembic/env.py `pg_advisory_xact_lock`.

content_blocks JSONB формат (см. validate_content_blocks в services/onboarding/courses.py):
    [{"kind": "markdown", "text": "..."},
     {"kind": "image", "url": "...", "caption": "..."},
     {"kind": "drive_video", "drive_url": "https://drive.google.com/file/d/<ID>/preview"},
     {"kind": "loom_video", "loom_url": "https://www.loom.com/share/<ID>"},
     {"kind": "youtube_video", "youtube_id": "<ID>"},
     {"kind": "callout", "style": "info|warning|success|danger", "text": "..."}]

Для kind="quiz" content_blocks игнорируется, вопросы — в lesson_quiz_questions.

Revision ID: 0031_onboarding_schema (24 chars — лимит 32, OK)
Revises: 0030_api_tokens_webhooks
Create Date: 2026-06-01

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0031_onboarding_schema"
down_revision: Union[str, None] = "0030_api_tokens_webhooks"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ============ 1. courses ============
    op.create_table(
        "courses",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("title", sa.String(length=255), nullable=False),
        sa.Column("description", sa.Text(), nullable=True),
        sa.Column("cover_image_url", sa.String(length=512), nullable=True),
        # target_roles JSONB array, например ["manager","lawyer"]. JSONB ?| для запроса.
        sa.Column(
            "target_roles",
            JSONB(),
            nullable=False,
            server_default=sa.text("'[]'::jsonb"),
        ),
        sa.Column(
            "is_published",
            sa.Boolean(),
            nullable=False,
            server_default=sa.false(),
        ),
        sa.Column(
            "passing_score_pct",
            sa.SmallInteger(),
            nullable=False,
            server_default=sa.text("80"),
        ),
        # 'informational' (только баджик) или 'soft_gate' (overdue блокирует bulk-действия)
        sa.Column(
            "completion_policy",
            sa.String(length=16),
            nullable=False,
            server_default=sa.text("'soft_gate'"),
        ),
        sa.Column(
            "deadline_days",
            sa.SmallInteger(),
            nullable=False,
            server_default=sa.text("5"),
        ),
        sa.Column(
            "created_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.CheckConstraint(
            "completion_policy IN ('informational','soft_gate')",
            name="ck_courses_completion_policy",
        ),
    )
    op.create_index("ix_courses_is_published", "courses", ["is_published"])

    # ============ 2. course_modules ============
    op.create_table(
        "course_modules",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "course_id",
            sa.Integer(),
            sa.ForeignKey("courses.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("title", sa.String(length=255), nullable=False),
        sa.Column("order_index", sa.SmallInteger(), nullable=False),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.UniqueConstraint(
            "course_id", "order_index", name="uq_course_module_order",
        ),
    )
    op.create_index("ix_course_modules_course_id", "course_modules", ["course_id"])

    # ============ 3. course_lessons ============
    op.create_table(
        "course_lessons",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "module_id",
            sa.Integer(),
            sa.ForeignKey("course_modules.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("title", sa.String(length=255), nullable=False),
        # 'theory' | 'video' | 'quiz'
        sa.Column("kind", sa.String(length=16), nullable=False),
        # Список блоков контента; для quiz игнорируется
        sa.Column(
            "content_blocks",
            JSONB(),
            nullable=False,
            server_default=sa.text("'[]'::jsonb"),
        ),
        # Оценочная длительность урока (минуты) — для UI
        sa.Column("duration_min", sa.SmallInteger(), nullable=True),
        sa.Column("order_index", sa.SmallInteger(), nullable=False),
        sa.Column(
            "is_required",
            sa.Boolean(),
            nullable=False,
            server_default=sa.true(),
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.CheckConstraint(
            "kind IN ('theory','video','quiz')",
            name="ck_course_lessons_kind",
        ),
        sa.UniqueConstraint(
            "module_id", "order_index", name="uq_lesson_module_order",
        ),
    )
    op.create_index(
        "ix_course_lessons_module_id_order",
        "course_lessons",
        ["module_id", "order_index"],
    )

    # ============ 4. lesson_quiz_questions ============
    op.create_table(
        "lesson_quiz_questions",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "lesson_id",
            sa.Integer(),
            sa.ForeignKey("course_lessons.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("question", sa.Text(), nullable=False),
        # 'single' (один правильный) | 'multi' (несколько)
        sa.Column("kind", sa.String(length=16), nullable=False),
        # options: ["Вариант 1", "Вариант 2", ...]
        sa.Column("options", JSONB(), nullable=False),
        # correct_answers: [0, 2] — индексы правильных вариантов
        sa.Column("correct_answers", JSONB(), nullable=False),
        sa.Column(
            "points",
            sa.SmallInteger(),
            nullable=False,
            server_default=sa.text("1"),
        ),
        sa.Column("order_index", sa.SmallInteger(), nullable=False),
        # Объяснение, показывается ПОСЛЕ ответа (правильного или нет)
        sa.Column("explanation", sa.Text(), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.CheckConstraint(
            "kind IN ('single','multi')",
            name="ck_lesson_quiz_questions_kind",
        ),
    )
    op.create_index(
        "ix_lesson_quiz_questions_lesson_id_order",
        "lesson_quiz_questions",
        ["lesson_id", "order_index"],
    )

    # ============ 5. user_course_assignments ============
    op.create_table(
        "user_course_assignments",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "course_id",
            sa.Integer(),
            sa.ForeignKey("courses.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "assigned_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        # NULL = auto-assign (по target_roles при POST /users)
        sa.Column(
            "assigned_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        # NULL = бессрочно (для опциональных курсов). Auto-assign ставит NOW() + deadline_days
        sa.Column("deadline_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column(
            "is_mandatory",
            sa.Boolean(),
            nullable=False,
            server_default=sa.true(),
        ),
        sa.UniqueConstraint(
            "user_id", "course_id", name="uq_user_course_assignment",
        ),
    )
    op.create_index(
        "ix_user_course_assignments_user_id",
        "user_course_assignments",
        ["user_id"],
    )

    # ============ 6. course_progress ============
    op.create_table(
        "course_progress",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "course_id",
            sa.Integer(),
            sa.ForeignKey("courses.id", ondelete="CASCADE"),
            nullable=False,
        ),
        # 'not_started' | 'in_progress' | 'completed' | 'overdue'
        sa.Column(
            "status",
            sa.String(length=16),
            nullable=False,
            server_default=sa.text("'not_started'"),
        ),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("completed_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column(
            "percent",
            sa.SmallInteger(),
            nullable=False,
            server_default=sa.text("0"),
        ),
        # lesson_states: {lesson_id: {completed_at, attempts_count, best_score_pct}}
        sa.Column(
            "lesson_states",
            JSONB(),
            nullable=False,
            server_default=sa.text("'{}'::jsonb"),
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column(
            "updated_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.CheckConstraint(
            "status IN ('not_started','in_progress','completed','overdue')",
            name="ck_course_progress_status",
        ),
        sa.UniqueConstraint(
            "user_id", "course_id", name="uq_course_progress_user_course",
        ),
    )
    op.create_index(
        "ix_course_progress_user_id",
        "course_progress",
        ["user_id"],
    )
    op.create_index(
        "ix_course_progress_course_status",
        "course_progress",
        ["course_id", "status"],
    )

    # ============ 7. quiz_attempts ============
    op.create_table(
        "quiz_attempts",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "lesson_id",
            sa.Integer(),
            sa.ForeignKey("course_lessons.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "started_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.Column("finished_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("score_pct", sa.SmallInteger(), nullable=True),
        # answers: [{question_id, selected_indices}]
        sa.Column("answers", JSONB(), nullable=True),
        sa.Column(
            "passed",
            sa.Boolean(),
            nullable=False,
            server_default=sa.false(),
        ),
    )
    op.create_index(
        "ix_quiz_attempts_user_lesson",
        "quiz_attempts",
        ["user_id", "lesson_id"],
    )
    # Partial UNIQUE INDEX: на user×lesson может быть только ОДНА открытая попытка
    # (finished_at IS NULL). Защита от race condition при двойном клике "начать".
    op.execute(
        "CREATE UNIQUE INDEX uq_quiz_attempts_open "
        "ON quiz_attempts (user_id, lesson_id) "
        "WHERE finished_at IS NULL"
    )

    # ============ 8. users — wizard поля ============
    # crm_experience_level: для будущей персонализации онбординга (none/basic/advanced).
    # ASK на первом логине; user может пропустить (NULL).
    op.add_column(
        "users",
        sa.Column(
            "crm_experience_level",
            sa.String(length=16),
            nullable=True,
        ),
    )
    op.create_check_constraint(
        "ck_users_crm_experience_level",
        "users",
        "crm_experience_level IS NULL OR "
        "crm_experience_level IN ('none','basic','advanced')",
    )
    # onboarding_dismissed_at: NULL = wizard не показывали; не-NULL = юзер dismiss'ил
    # (или прошёл) и больше не показываем. Используется в /users/me для UI.
    op.add_column(
        "users",
        sa.Column(
            "onboarding_dismissed_at",
            sa.DateTime(timezone=True),
            nullable=True,
        ),
    )


def downgrade() -> None:
    # Сначала FK-зависимые → потом родительские
    op.drop_constraint("ck_users_crm_experience_level", "users", type_="check")
    op.drop_column("users", "onboarding_dismissed_at")
    op.drop_column("users", "crm_experience_level")

    op.execute("DROP INDEX IF EXISTS uq_quiz_attempts_open")
    op.drop_index("ix_quiz_attempts_user_lesson", table_name="quiz_attempts")
    op.drop_table("quiz_attempts")

    op.drop_index("ix_course_progress_course_status", table_name="course_progress")
    op.drop_index("ix_course_progress_user_id", table_name="course_progress")
    op.drop_table("course_progress")

    op.drop_index("ix_user_course_assignments_user_id", table_name="user_course_assignments")
    op.drop_table("user_course_assignments")

    op.drop_index("ix_lesson_quiz_questions_lesson_id_order", table_name="lesson_quiz_questions")
    op.drop_table("lesson_quiz_questions")

    op.drop_index("ix_course_lessons_module_id_order", table_name="course_lessons")
    op.drop_table("course_lessons")

    op.drop_index("ix_course_modules_course_id", table_name="course_modules")
    op.drop_table("course_modules")

    op.drop_index("ix_courses_is_published", table_name="courses")
    op.drop_table("courses")
