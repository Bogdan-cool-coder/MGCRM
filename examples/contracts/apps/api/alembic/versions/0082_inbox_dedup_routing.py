"""Inbox dedup + routing hardening — partial UNIQUE (channel_id, external_id) + routing_status.

Зона Integration / Inbox (Эпики 5/11). Закрывает HIGH-баги код-аудита приёма
входящего потока:

1. inbound_messages.routing_status (VARCHAR(16), nullable) — статус маршрутизации
   сообщения в Deal. Значения:
   - 'routed'   → Deal создан/привязан успешно.
   - 'dedup'    → external_id уже был, привязано к существующему Deal.
   - 'failed'   → НЕ создан Deal (нет sales-воронки/этапа new) — нужно ручное
     разобрать. Inbox UI показывает «не разобрано».
   NULL = legacy-строки (до миграции) — UI трактует как «routed/неизвестно».

2. Частичный UNIQUE-индекс ux_inbound_messages_channel_external
   (channel_id, external_id) WHERE external_id IS NOT NULL. Раньше был обычный
   (не-уникальный) Index — гонка scale=2 + легитимные ретраи провайдера webhook'а
   создавали дубль Deal+Company. UNIQUE даёт транзакционную защиту: второй INSERT
   с тем же (channel_id, external_id) ловит IntegrityError, сервис привязывается
   к существующему. Partial — формы/сообщения без external_id (NULL) не
   конфликтуют.

   Старый обычный индекс ix_inbound_messages_channel_external удаляется
   (UNIQUE его перекрывает по составу колонок).

Идемпотентность миграции: ADD COLUMN IF NOT EXISTS, DROP INDEX IF EXISTS,
CREATE UNIQUE INDEX IF NOT EXISTS. Advisory-lock seed-key 74_007 (DDL-only,
держим единый паттерн с соседними миграциями зоны; параллельный старт scale=2
безопасен и за счёт alembic_version row-lock, lock тут — дешёвая страховка).

ВАЖНО (миграция данных): создаём UNIQUE на существующих данных. Если в проде
уже есть дубли (channel_id, external_id) с непустым external_id — CREATE UNIQUE
INDEX упадёт. Перед созданием схлопываем дубли: оставляем строку с минимальным id,
у остальных обнуляем external_id (теряем дедуп-ключ для уже-принятых дублей, но
это безопаснее, чем падение миграции; реальных дублей быть не должно — в MVP
external_id заполнялся только webhook-каналами с дедупом).

Revision ID: 0082_inbox_dedup       (18 chars ≤32 ✓)
Revises: 0081_automation_idem
Create Date: 2026-06-03
"""
from __future__ import annotations

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0082_inbox_dedup"
down_revision: Union[str, None] = "0081_automation_idem"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

_SEED_LOCK_INBOX_DEDUP = 74_007


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_INBOX_DEDUP},
    )

    # 1. routing_status — статус маршрутизации (routed/dedup/failed/NULL legacy).
    conn.execute(
        sa.text(
            "ALTER TABLE inbound_messages "
            "ADD COLUMN IF NOT EXISTS routing_status VARCHAR(16)"
        )
    )

    # 2. Схлопнуть возможные существующие дубли (channel_id, external_id) перед
    #    созданием UNIQUE: у всех строк, кроме минимального id в группе, обнуляем
    #    external_id, чтобы UNIQUE не упал. Реальных дублей в MVP быть не должно.
    conn.execute(
        sa.text(
            """
            UPDATE inbound_messages im
            SET external_id = NULL
            WHERE im.external_id IS NOT NULL
              AND im.id > (
                SELECT MIN(im2.id)
                FROM inbound_messages im2
                WHERE im2.channel_id = im.channel_id
                  AND im2.external_id = im.external_id
              )
            """
        )
    )

    # 3. Удаляем старый обычный (не-уникальный) индекс — UNIQUE его заменит.
    conn.execute(
        sa.text("DROP INDEX IF EXISTS ix_inbound_messages_channel_external")
    )

    # 4. Частичный UNIQUE — дедуп webhook-доставок на уровне БД.
    conn.execute(
        sa.text(
            "CREATE UNIQUE INDEX IF NOT EXISTS ux_inbound_messages_channel_external "
            "ON inbound_messages (channel_id, external_id) "
            "WHERE external_id IS NOT NULL"
        )
    )


def downgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_INBOX_DEDUP},
    )
    # Вернуть обычный индекс (как было до 0082).
    conn.execute(
        sa.text("DROP INDEX IF EXISTS ux_inbound_messages_channel_external")
    )
    conn.execute(
        sa.text(
            "CREATE INDEX IF NOT EXISTS ix_inbound_messages_channel_external "
            "ON inbound_messages (channel_id, external_id)"
        )
    )
    conn.execute(
        sa.text(
            "ALTER TABLE inbound_messages DROP COLUMN IF EXISTS routing_status"
        )
    )
