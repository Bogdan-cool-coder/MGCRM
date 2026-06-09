"""merge 0044 (ai_analysis) + 0045 (subscription_history) branches

Revision ID: 0046_merge_ai_history
Revises: 0044_ai_analysis_fields, 0045_subscription_history
Create Date: 2026-06-02 03:35:00

Both 0044 (Epic 18 — AI Features) and 0045 (Epic 22 — Cohort Analytics) were
created in parallel from 0043. This empty merge migration joins both heads
into a single linear chain at 0046, allowing `alembic upgrade head` to apply
both.
"""

from __future__ import annotations

from typing import Sequence, Union

from alembic import op  # noqa: F401  (kept for symmetry with other migrations)
import sqlalchemy as sa  # noqa: F401


# revision identifiers, used by Alembic.
revision: str = "0046_merge_ai_history"
down_revision: Union[str, Sequence[str], None] = (
    "0044_ai_analysis_fields",
    "0045_subscription_history",
)
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # Empty merge migration — no schema changes.
    pass


def downgrade() -> None:
    # Empty merge migration — no schema changes.
    pass
