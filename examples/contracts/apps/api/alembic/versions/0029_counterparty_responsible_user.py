"""Эпик 10 (frontend fallback bridge): добавляем `counterparties.responsible_user_id`
— ответственного менеджера контрагента.

Сейчас фронтенд (карточка КА → блок «Ответственный менеджер») использует fallback
через `Deal.owner_user_id` свежей сделки, что нестабильно (КА может не иметь
сделок, или владельцев сделок несколько — кто реальный ответственный?). Заводим
явное поле на самой Counterparty.

Backfill — НЕ делаем (пусть NULL до ручного назначения). Если в будущем
потребуется автозаливка из последней сделки, это отдельной командой/скриптом.

DDL-only (без seed-данных) — advisory-lock не нужен. ADD COLUMN с NULL безопасен
на больших таблицах в PG 11+ (без table rewrite). Index — для фильтра
«мои клиенты» в карточке менеджера.

Revision ID: 0029_cpty_responsible_user
Revises: 0028_ensure_one_admin_trigger
Create Date: 2026-05-31

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit).
Файл сохраняем как `0029_counterparty_responsible_user.py` для читаемости
(имя файла не используется alembic — только переменная `revision`).
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0029_cpty_responsible_user"
down_revision: Union[str, None] = "0028_ensure_one_admin_trigger"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    # ON DELETE SET NULL — если пользователь удалён (или деактивирован/уволен и
    # запись физически дропнута), КА не теряется, просто остаётся без
    # ответственного.
    op.add_column(
        "counterparties",
        sa.Column(
            "responsible_user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
    )
    op.create_index(
        "ix_counterparties_responsible_user_id",
        "counterparties",
        ["responsible_user_id"],
    )


def downgrade() -> None:
    op.drop_index(
        "ix_counterparties_responsible_user_id",
        table_name="counterparties",
    )
    op.drop_column("counterparties", "responsible_user_id")
