"""Эпик 14 + P0 security (Unit 3a) — Departments + Visibility ACL: сидер матрицы.

Создаёт ЯВНЫЕ per-role строки `VisibilitySetting(entity_type, applies_to_role, scope)`
для каждого entity_type из ALLOWED_ENTITY_TYPES. Owner decision: «всё настраивается
на роль явно».

Дефолтная матрица (fail-CLOSED разумная; админ может расширять через
PATCH /admin/visibility-settings):
  admin      → all
  director   → all
  lawyer     → all      (юрист видит все контракты/лиды для согласования)
  manager    → personal (own — только свои записи)
  accountant → personal (own)
  cfo        → personal (own)

Любая НЕ сидированная / новая роль по умолчанию → 'personal' (resolve_scope
fail-closed default). NULL-role строки сидер БОЛЬШЕ НЕ создаёт со scope='all' —
это был fail-open default, открывавший всё до настройки.

Сидер идемпотентен (insert-missing), сериализуется через advisory-lock 728_274_142.
Вызывается из app.main.seed_initial_data() в lifespan startup. Та же логика —
в миграции (advisory 96_010), чтобы прод-БД получила матрицу при deploy.
"""
from __future__ import annotations

import logging

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import VisibilitySetting
from app.services.access_control import ALLOWED_ENTITY_TYPES

logger = logging.getLogger(__name__)

# Семья 728_274_*: 001=migration global lock, 002..010 заняты, 140-142 — Epic 14.
_SEED_LOCK_VISIBILITY = 728_274_142

# P0 security (Unit 3a): дефолтный scope per role. 'personal' == «own».
# admin override происходит в get_effective_scope (admin всегда 'all'), но строку
# для admin тоже сидируем для полноты матрицы в UI.
_DEFAULT_ROLE_SCOPES: dict[str, str] = {
    "admin": "all",
    "director": "all",
    "lawyer": "all",
    "manager": "personal",
    "accountant": "personal",
    "cfo": "personal",
}


async def seed_default_visibility_settings(session: AsyncSession) -> int:
    """Insert-missing per-role дефолтных правил scope для всех entity × role.

    Идемпотентно: если строка (entity_type, applies_to_role) уже есть — пропускаем
    (НЕ перетираем существующую настройку админа). Возвращает количество вставленных
    строк. Advisory-lock сериализует параллельные запуски (api scale=2).
    """
    await session.execute(
        text("SELECT pg_advisory_lock(:k)"),
        {"k": _SEED_LOCK_VISIBILITY},
    )
    try:
        # Существующие (entity_type, applies_to_role) пары — чтобы не дублировать.
        existing_rows = (
            await session.execute(
                select(
                    VisibilitySetting.entity_type,
                    VisibilitySetting.applies_to_role,
                )
            )
        ).all()
        existing = {(et, role) for (et, role) in existing_rows}

        inserted = 0
        for et in sorted(ALLOWED_ENTITY_TYPES):
            for role, scope in _DEFAULT_ROLE_SCOPES.items():
                if (et, role) in existing:
                    continue
                session.add(VisibilitySetting(
                    entity_type=et,
                    scope=scope,
                    applies_to_role=role,
                ))
                inserted += 1
        if inserted:
            await session.commit()
            logger.info(
                "seed_default_visibility_settings: inserted %d per-role rules",
                inserted,
            )
        return inserted
    finally:
        await session.execute(
            text("SELECT pg_advisory_unlock(:k)"),
            {"k": _SEED_LOCK_VISIBILITY},
        )
