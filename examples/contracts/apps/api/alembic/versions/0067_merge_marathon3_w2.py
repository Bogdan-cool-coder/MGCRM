"""merge marathon-3 wave-2 branches (Epic 24.2 + 24.3)

Revision ID: 0067_merge_m3w2
Revises: 0065_gcal_sync, 0066_tg_intent_log
Create Date: 2026-06-02 18:00:00

MARATHON-3 Wave-2 created 2 parallel migration branches from 0064:
- Epic 24.2 (GCal sync): 0065
- Epic 24.3 (TG NL parsing): 0066

This empty merge joins them into linear chain at 0067.
"""

from __future__ import annotations

from typing import Sequence, Union

from alembic import op  # noqa: F401
import sqlalchemy as sa  # noqa: F401


revision: str = "0067_merge_m3w2"
down_revision: Union[str, Sequence[str], None] = (
    "0065_gcal_sync",
    "0066_tg_intent_log",
)
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    pass


def downgrade() -> None:
    pass
