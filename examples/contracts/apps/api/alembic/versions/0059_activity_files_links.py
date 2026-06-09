"""Эпик 24 — Tasks v2: вложения и связанные активности.

Создаёт:
- activity_attachments — файлы, прикреплённые к активности (multipart upload)
- activity_related_links — связи между активностями (related|blocks|blocked_by|duplicates)

Defensive advisory-lock seed-key 57_995 (0xE28B — Epic 24 Activity Files+Links).
DDL-only, без seed-данных.

Revision ID: 0059_activity_files_links  (26 chars ≤32 ✓)
Revises: 0058_activity_v2
Create Date: 2026-06-02
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0059_activity_files_links"
down_revision: Union[str, None] = "0058_activity_v2"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE28B = 57_995 — Epic 24 Activity Files+Links seed-key.
_SEED_LOCK_EPIC_24_FILES = 57_995


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_24_FILES},
    )

    # Вложения активности.
    op.create_table(
        "activity_attachments",
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column(
            "activity_id",
            sa.Integer(),
            sa.ForeignKey("activities.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("file_path", sa.Text(), nullable=False),
        sa.Column("original_name", sa.String(256), nullable=True),
        sa.Column("file_size", sa.Integer(), nullable=True),
        sa.Column("mime_type", sa.String(64), nullable=True),
        sa.Column(
            "uploaded_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "uploaded_at",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.PrimaryKeyConstraint("id"),
    )
    op.create_index("idx_attach_activity", "activity_attachments", ["activity_id"])

    # Связи между активностями (граф).
    op.create_table(
        "activity_related_links",
        sa.Column("id", sa.Integer(), nullable=False),
        sa.Column(
            "activity_id_from",
            sa.Integer(),
            sa.ForeignKey("activities.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "activity_id_to",
            sa.Integer(),
            sa.ForeignKey("activities.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "link_type",
            sa.String(16),
            nullable=False,
            server_default="related",
        ),  # related|blocks|blocked_by|duplicates
        sa.Column(
            "created_by_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.PrimaryKeyConstraint("id"),
        sa.UniqueConstraint("activity_id_from", "activity_id_to", name="uq_related_link_pair"),
    )
    op.create_index("idx_related_from", "activity_related_links", ["activity_id_from"])
    op.create_index("idx_related_to", "activity_related_links", ["activity_id_to"])


def downgrade() -> None:
    op.drop_index("idx_related_to", "activity_related_links")
    op.drop_index("idx_related_from", "activity_related_links")
    op.drop_table("activity_related_links")
    op.drop_index("idx_attach_activity", "activity_attachments")
    op.drop_table("activity_attachments")
