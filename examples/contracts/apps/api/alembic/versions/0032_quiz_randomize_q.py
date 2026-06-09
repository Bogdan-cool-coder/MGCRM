"""Tech Sprint Фаза 0 (задача 3): добавить `course_lessons.randomize_questions`.

Эпик 13 (Онбординг) — квизы. По умолчанию вопросы возвращаются в фиксированном
порядке (order_index). Этот флаг позволяет admin'у пометить отдельный quiz-урок
как «перемешать вопросы при каждой попытке» — защита от шеринга порядка ответов
(если сотрудники передают друг другу «правильно ответить на 1-2-3 вопросы по
порядку»).

Shuffle стабилен для одной попытки (seed = attempt.id), см. `randomize_questions`
в `services/onboarding/quiz.py` — реализация уже есть, добавляем только флаг.

DDL-only. Advisory-lock на уровне env.py уже стоит, отдельный не нужен.
Backfill: server_default=false — все существующие уроки получают false.

Revision ID: 0032_quiz_randomize_q   (≤32 chars ✓)
Revises: 0031_onboarding_schema
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0032_quiz_randomize_q"
down_revision: Union[str, None] = "0031_onboarding_schema"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.add_column(
        "course_lessons",
        sa.Column(
            "randomize_questions",
            sa.Boolean(),
            nullable=False,
            server_default=sa.false(),
        ),
    )


def downgrade() -> None:
    op.drop_column("course_lessons", "randomize_questions")
