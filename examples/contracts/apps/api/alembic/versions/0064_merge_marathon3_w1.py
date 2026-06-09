"""merge 3 marathon-3 wave-1 branches (Epic 24 + 14.2 + 21.2)

Revision ID: 0064_merge_m3w1
Revises: 0059_activity_files_links, 0062_schedule_calendar, 0063_notif_channels
Create Date: 2026-06-02 16:30:00

MARATHON-3 Wave-1 created 3 parallel migration branches from 0056:
- Epic 24 (Tasks v2): 0057 → 0058 → 0059
- Epic 14.2 (Company Mgmt): 0060 → 0061 → 0062
- Epic 21.2 (Notification channels): 0063 (from 0061)

This empty merge migration joins all 3 heads into a single linear chain
at 0064, allowing `alembic upgrade head` to apply them all.
"""

from __future__ import annotations

from typing import Sequence, Union

from alembic import op  # noqa: F401
import sqlalchemy as sa  # noqa: F401


revision: str = "0064_merge_m3w1"
down_revision: Union[str, Sequence[str], None] = (
    "0059_activity_files_links",
    "0062_schedule_calendar",
    "0063_notif_channels",
)
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    pass


def downgrade() -> None:
    pass
