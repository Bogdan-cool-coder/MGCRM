"""Эпик 20 — Performance Scale: FTS (Postgres tsvector) + GIN индексы.

Цель — заменить медленный ILIKE ('%query%') в /search и /duplicates/check
на PostgreSQL full-text search. На 10k+ записях ILIKE без индекса даёт
500-2000ms; tsvector + GIN — десятки миллисекунд.

Что делает миграция:

1) CREATE EXTENSION pg_trgm (IF NOT EXISTS) — для будущего trigram-similarity
   в /duplicates/check (короткие префиксы name/phone, где FTS малоэффективен).

2) Добавляет search_vector tsvector GENERATED ALWAYS AS (...) STORED на
   7 таблицах: counterparties, crm_companies, crm_contacts, leads, deals,
   contracts, client_subscriptions.

   - setweight('A') — самые важные поля (name / number)
   - setweight('B') — email
   - setweight('C') — phone / tax_id / website
   - setweight('D') — notes (длинные тексты)

   GENERATED ALWAYS STORED означает что Postgres сам пересчитывает
   tsvector при UPDATE строки → нет триггеров, нет рассинхрона при
   массовых импортах.

3) CREATE INDEX ... USING GIN (search_vector) — собственно индекс.
   GIN — стандарт для tsvector, быстро на @@ запросах.

Конфиг tsvector — 'russian' для большинства полей (морфология русского
языка для notes/имён); 'simple' для коротких technical ID (contract.number,
tax_id) — там морфология лишняя, нам нужно точное совпадение токенов.

Идемпотентность: используем IF NOT EXISTS для индексов и `ADD COLUMN IF NOT
EXISTS` где можем. STORED-колонка идемпотентна тоже — повторное добавление
вернёт ошибку, поэтому проверяем существование колонки явно через
information_schema (или DROP COLUMN IF EXISTS перед ADD).

NB: 'russian' tsvector config есть в стандартном PostgreSQL 16 (Alpine,
Debian) — это часть `contrib/dict_russian.sql` который входит в default
build. Никаких extension'ов добавлять не надо.

Defensive advisory-lock seed-key 57_856 (0xE200 — Epic 20 FTS).

Revision ID: 0039_fts_gin_indexes  (19 chars ≤32 ✓)
Revises: 0038_sso_links
Create Date: 2026-06-02

NB: revision string ≤32 chars (alembic_version.version_num VARCHAR(32) limit),
проверяется в tests/test_migration_revision_length.py.
"""
from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op

revision: str = "0039_fts_gin_indexes"
down_revision: Union[str, None] = "0038_sso_links"
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None

# 0xE200 = 57856 — Epic 20 FTS seed-key. Defensive advisory lock: env.py уже
# даёт глобальный lock на миграцию, но отдельный семантический key улучшает
# диагностику в pg_locks при concurrent rolling-restart.
_SEED_LOCK_EPIC_20_FTS = 57_856


# Конфигурация FTS-колонок per-таблица: (table, column_sql).
# column_sql — это выражение GENERATED ALWAYS AS (...) STORED.
# Если у таблицы нет какого-то поля (или оно отсутствует на момент создания
# миграции), его пропускаем — иначе ADD COLUMN упадёт.
_FTS_TABLES: list[tuple[str, str]] = [
    (
        "counterparties",
        "setweight(to_tsvector('russian', coalesce(name, '')), 'A') || "
        "setweight(to_tsvector('russian', coalesce(email, '')), 'B') || "
        "setweight(to_tsvector('russian', coalesce(phone, '')), 'C') || "
        "setweight(to_tsvector('russian', coalesce(tax_id, '')), 'C') || "
        "setweight(to_tsvector('simple',  coalesce(notes, '')), 'D')",
    ),
    (
        "crm_companies",
        "setweight(to_tsvector('russian', coalesce(legal_name, '')), 'A') || "
        "setweight(to_tsvector('russian', coalesce(short_name, '')), 'A') || "
        "setweight(to_tsvector('russian', coalesce(email, '')), 'B') || "
        "setweight(to_tsvector('russian', coalesce(phone, '')), 'C') || "
        "setweight(to_tsvector('russian', coalesce(website, '')), 'C') || "
        "setweight(to_tsvector('simple',  coalesce(tax_id, '')), 'C') || "
        "setweight(to_tsvector('simple',  coalesce(notes, '')), 'D')",
    ),
    (
        "crm_contacts",
        "setweight(to_tsvector('russian', coalesce(full_name, '')), 'A') || "
        "setweight(to_tsvector('russian', coalesce(email, '')), 'B') || "
        "setweight(to_tsvector('russian', coalesce(phone, '')), 'C')",
    ),
    (
        "leads",
        # У Lead нет title/company_name/contact_name — есть name (заголовок
        # лида) + contact_email + contact_phone + notes. Используем то, что есть.
        "setweight(to_tsvector('russian', coalesce(name, '')), 'A') || "
        "setweight(to_tsvector('russian', coalesce(contact_email, '')), 'B') || "
        "setweight(to_tsvector('russian', coalesce(contact_phone, '')), 'C') || "
        "setweight(to_tsvector('simple',  coalesce(notes, '')), 'D')",
    ),
    (
        "deals",
        # У Deal нет notes — есть title и lost_reason. lost_reason тоже полезен
        # для поиска «причины проигрыша».
        "setweight(to_tsvector('russian', coalesce(title, '')), 'A') || "
        "setweight(to_tsvector('simple',  coalesce(lost_reason, '')), 'D')",
    ),
    (
        "contracts",
        # Contract.number — технический ID (ТШК-219/UZ), 'simple' лучше.
        # Контрагент через counterparty_id (relation) — индекс на нём отдельный
        # (counterparties.search_vector). title — основное name-поле.
        "setweight(to_tsvector('simple',  coalesce(number, '')), 'A') || "
        "setweight(to_tsvector('russian', coalesce(title, '')), 'A')",
    ),
    (
        "client_subscriptions",
        # notes — единственное текстовое поле, по которому стоит искать
        # (платформа/регион/команда — это FK, search не туда).
        "setweight(to_tsvector('simple',  coalesce(notes, '')), 'D')",
    ),
]


def _column_exists(conn, table: str, column: str) -> bool:
    """True если колонка table.column уже существует."""
    res = conn.execute(
        sa.text(
            "SELECT 1 FROM information_schema.columns "
            "WHERE table_name = :t AND column_name = :c LIMIT 1"
        ),
        {"t": table, "c": column},
    )
    return res.first() is not None


def upgrade() -> None:
    conn = op.get_bind()
    conn.execute(
        sa.text("SELECT pg_advisory_xact_lock(:k)"),
        {"k": _SEED_LOCK_EPIC_20_FTS},
    )

    # ============ pg_trgm extension (для будущего trigram /check) ============
    # CREATE EXTENSION требует superuser в большинстве конфигов. У нас в проде
    # PG в Docker рулится из docker-compose, postgres юзер = owner DB, so OK.
    op.execute("CREATE EXTENSION IF NOT EXISTS pg_trgm")

    # ============ search_vector + GIN индексы per-table ============
    for table, expr in _FTS_TABLES:
        # Идемпотентность: если колонка уже есть — пропускаем ADD COLUMN.
        # (Generated columns нельзя ALTER, проще скип.)
        if not _column_exists(conn, table, "search_vector"):
            op.execute(
                f"ALTER TABLE {table} ADD COLUMN search_vector tsvector "
                f"GENERATED ALWAYS AS ({expr}) STORED"
            )
        # CREATE INDEX IF NOT EXISTS — Postgres 9.5+, безопасно.
        op.execute(
            f"CREATE INDEX IF NOT EXISTS idx_{table}_fts "
            f"ON {table} USING GIN (search_vector)"
        )


def downgrade() -> None:
    # Удалить индексы и колонки в обратном порядке.
    for table, _expr in reversed(_FTS_TABLES):
        op.execute(f"DROP INDEX IF EXISTS idx_{table}_fts")
        op.execute(f"ALTER TABLE {table} DROP COLUMN IF EXISTS search_vector")
    # pg_trgm extension оставляем — другие части системы могут начать её
    # использовать; снимать в downgrade рискованно.
