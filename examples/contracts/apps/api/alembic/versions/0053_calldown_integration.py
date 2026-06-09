"""Эпик 15 — Integration Hub + Calldown + Whisper: integration settings + calls.

Две связанные таблицы для нового интеграционного слоя:

1) `integration_settings` — единая admin-таблица с настройками для всех
   integration-провайдеров (AmoCRM, Calldown Mango/UIS, Whisper, Slack,
   и т.д.). Один ряд = один провайдер.

   provider VARCHAR(32) UNIQUE — "calldown_mango" | "calldown_uis" |
   "whisper" | "amocrm" | "slack" | ... Whitelist в app-коде
   (services/integrations.py::PROVIDERS).

   is_enabled BOOLEAN — admin может включить/отключить интеграцию без
   удаления конфига. UI показывает switch.

   config JSONB — credentials (зашифрованы Fernet через services/totp.py
   reuse) + settings конкретного провайдера. Структура — per-provider.

   webhook_secret VARCHAR(64) — для верификации входящих webhook'ов от
   провайдера (например, Mango подписывает callback HMAC-SHA256). Может
   быть NULL для провайдеров без inbound (outbound-only).

   last_sync_at — best-effort marker для cron-job'ов. На MVP — null/touch
   при тестовом вызове; в будущем cron full-sync будет писать сюда.

2) `calldown_calls` — лог звонков от Calldown-провайдеров (Mango/UIS).
   Записывается из inbound webhook'а после verify-signature → parse →
   match-counterparty → create-activity.

   provider VARCHAR(32) — "calldown_mango" | "calldown_uis" (тот же
   whitelist что в integration_settings).

   external_call_id VARCHAR(128) — UUID/id звонка от провайдера. Для
   дедупа повторных доставок webhook'а (один и тот же звонок может
   прийти 2-3 раза в течение часа).

   direction VARCHAR(8) — "in" | "out" (входящий/исходящий).

   from_number / to_number VARCHAR(32) — E.164 без + (например,
   "77001234567") или короткий внутренний номер ("101"). Не нормализуем
   принудительно — храним as-is для аудита.

   duration_seconds INT — длительность разговора (после ответа). NULL =
   звонок ещё в процессе / не было ответа.

   started_at / ended_at TIMESTAMPTZ — границы звонка. Могут быть NULL
   на первом callback'е (start event без длительности).

   recording_url TEXT — где хранится запись. Структура URL зависит от
   провайдера. На MVP не скачиваем локально — просто храним ссылку.

   transcript_text TEXT — текст после прогона через Whisper. NULL пока
   не запросили транскрипцию (или если запись отсутствует).

   transcript_status VARCHAR(16) — "pending" | "processing" | "done" |
   "failed". State-machine: pending → processing → done/failed.

   transcript_lang VARCHAR(8) — язык для Whisper API ("ru" | "en" | ...).
   Default "ru" в app-коде; не CHECK на БД (пусть бэкенд решает).

   user_id INT NULL FK users.id ON DELETE SET NULL — кто owner звонка
   (резолвится из to_number = User.phone или to_number = соответствующий
   аккаунт провайдера). NULL = не удалось определить owner'а.

   counterparty_id INT NULL FK counterparties.id ON DELETE SET NULL —
   resolve по from_number (для входящих) или to_number (для исходящих).
   NULL = телефон не нашли в counterparties.

   deal_id INT NULL FK deals.id ON DELETE SET NULL — может быть привязан
   через UI «прикрепить к сделке» (PATCH /calls/{id}/attach-deal).

   activity_id INT NULL FK activities.id ON DELETE SET NULL — auto-
   created Activity(kind='call') — таймлайн карточки контрагента/сделки.

   raw_payload JSONB — полное тело webhook'а от провайдера. Для дебага
   и для будущей миграции схемы (если провайдер добавит поля).

Индексы:
- idx_calldown_user (user_id, started_at DESC) — UI «звонки сотрудника X
  за период» / отчёты по продажнику.
- idx_calldown_counterparty (counterparty_id) — карточка контрагента
  «история звонков».
- ix_calldown_external (provider, external_call_id) UNIQUE — дедуп
  webhook'а (один и тот же звонок может прийти повторно).

Defensive advisory-lock seed-key 57_989 (0xE285 — Epic 15 Calldown).
DDL-only, без seed-данных — провайдеры заводятся через POST
/api/integrations/{provider}/settings админом.

Revision ID: 0053_calldown_integration  (24 chars ≤32 ✓)
Revises: 0052_contract_payments_ftm (Epic 10.5 tail)
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import JSONB

revision: str = "0053_calldown_integration"
down_revision: Union[str, None] = "0052_contract_payments_ftm"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE285 = 57_989 — Epic 15 Calldown seed-key.
_SEED_LOCK_EPIC_15_CALLDOWN = 57_989


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_15_CALLDOWN},
    )

    # ============ integration_settings ============
    op.create_table(
        "integration_settings",
        sa.Column("id", sa.Integer(), primary_key=True),
        # "calldown_mango" | "calldown_uis" | "whisper" | "amocrm" | ...
        # Whitelist в services/integrations.py::PROVIDERS.
        sa.Column("provider", sa.String(length=32), nullable=False),
        sa.Column(
            "is_enabled",
            sa.Boolean(),
            nullable=False,
            server_default=sa.text("false"),
        ),
        # Credentials и settings per-provider (структура задана в app-коде).
        # Чувствительные поля шифруются Fernet'ом перед записью.
        sa.Column(
            "config",
            JSONB(),
            nullable=False,
            server_default=sa.text("'{}'::jsonb"),
        ),
        # Для HMAC верификации inbound webhook'ов от провайдера.
        sa.Column("webhook_secret", sa.String(length=64), nullable=True),
        sa.Column(
            "last_sync_at",
            sa.DateTime(timezone=True),
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
        sa.UniqueConstraint("provider", name="uq_integration_settings_provider"),
    )

    # ============ calldown_calls ============
    op.create_table(
        "calldown_calls",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("provider", sa.String(length=32), nullable=False),
        sa.Column("external_call_id", sa.String(length=128), nullable=True),
        # "in" | "out" — CHECK на БД.
        sa.Column("direction", sa.String(length=8), nullable=False),
        sa.Column("from_number", sa.String(length=32), nullable=True),
        sa.Column("to_number", sa.String(length=32), nullable=True),
        sa.Column("duration_seconds", sa.Integer(), nullable=True),
        sa.Column("started_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("ended_at", sa.DateTime(timezone=True), nullable=True),
        sa.Column("recording_url", sa.Text(), nullable=True),
        sa.Column("transcript_text", sa.Text(), nullable=True),
        # "pending" | "processing" | "done" | "failed". Whitelist в app-коде.
        sa.Column("transcript_status", sa.String(length=16), nullable=True),
        sa.Column("transcript_lang", sa.String(length=8), nullable=True),
        sa.Column(
            "user_id",
            sa.Integer(),
            sa.ForeignKey("users.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "counterparty_id",
            sa.Integer(),
            sa.ForeignKey("counterparties.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "deal_id",
            sa.Integer(),
            sa.ForeignKey("deals.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column(
            "activity_id",
            sa.Integer(),
            sa.ForeignKey("activities.id", ondelete="SET NULL"),
            nullable=True,
        ),
        sa.Column("raw_payload", JSONB(), nullable=True),
        sa.Column(
            "created_at",
            sa.DateTime(timezone=True),
            server_default=sa.func.now(),
            nullable=False,
        ),
        sa.CheckConstraint(
            "direction IN ('in','out')",
            name="ck_calldown_direction",
        ),
    )

    # Главный индекс отчётов: «звонки X-го за период».
    op.create_index(
        "idx_calldown_user",
        "calldown_calls",
        ["user_id", sa.text("started_at DESC")],
    )
    # Карточка контрагента / сделки — обратный lookup.
    op.create_index(
        "idx_calldown_counterparty",
        "calldown_calls",
        ["counterparty_id"],
    )
    # Дедуп webhook'а (один и тот же звонок может прийти повторно).
    # UNIQUE составной — на (provider, external_call_id), потому что
    # external_call_id уникален только внутри одного провайдера.
    # Partial WHERE external_call_id IS NOT NULL чтобы не блокировать
    # NULL'ы (случайные тестовые звонки без id).
    op.create_index(
        "ix_calldown_external",
        "calldown_calls",
        ["provider", "external_call_id"],
        unique=True,
        postgresql_where=sa.text("external_call_id IS NOT NULL"),
    )


def downgrade() -> None:
    op.drop_index("ix_calldown_external", table_name="calldown_calls")
    op.drop_index("idx_calldown_counterparty", table_name="calldown_calls")
    op.drop_index("idx_calldown_user", table_name="calldown_calls")
    op.drop_table("calldown_calls")
    op.drop_table("integration_settings")
