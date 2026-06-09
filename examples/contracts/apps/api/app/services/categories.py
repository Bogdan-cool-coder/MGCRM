"""Категории клиентов: ₽-снимок договора, оборот по подписанным, назначение категории.

Холдинги: если контрагент в группе — оборот агрегируется по всем членам, и категория
группы наследуется членами. Источник оборота (подписанные договоры) вынесен в
compute_turnover_rub — позже заменим на платежи без переделки остального.
"""
from __future__ import annotations

from datetime import UTC, datetime, timedelta
from decimal import Decimal

from sqlalchemy import func, text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import ClientCategory, ClientGroup, Contract, ContractStatus, Counterparty
from app.services.fx import get_rate_to_rub
from app.services.pricing import q2

WINDOW_DAYS = 365  # скользящие 12 мес
_SEED_LOCK_KEY = 728_274_003


async def snapshot_total_rub(session: AsyncSession, contract: Contract) -> None:
    """Фиксирует ₽-эквивалент итога договора на дату подписания. Вызывается в /sign."""
    on_date = (contract.signed_at or datetime.now(UTC)).date()
    contract.fx_rate_date = on_date
    if not contract.currency or not contract.total:
        contract.total_rub = Decimal("0")
        contract.fx_rate = None
        return
    rate = await get_rate_to_rub(session, contract.currency, on_date)
    if rate is None:
        contract.total_rub = None  # курс не получен — пересчитается ночным cron
        contract.fx_rate = None
        return
    contract.fx_rate = rate
    contract.total_rub = q2(Decimal(contract.total) * rate)


async def compute_turnover_rub(session: AsyncSession, counterparty_ids: list[int], as_of: datetime) -> Decimal:
    """Σ total_rub подписанных договоров членов за окно WINDOW_DAYS. Источник оборота."""
    if not counterparty_ids:
        return Decimal("0")
    since = as_of - timedelta(days=WINDOW_DAYS)
    total = (
        await session.execute(
            select(func.coalesce(func.sum(Contract.total_rub), 0)).where(
                Contract.counterparty_id.in_(counterparty_ids),
                Contract.status == ContractStatus.signed,
                Contract.signed_at >= since,
            )
        )
    ).scalar_one()
    return Decimal(str(total or 0))


def match_category(turnover: Decimal, bands: list[tuple[str, Decimal, Decimal | None]]) -> str | None:
    """Чистый матчинг: первая категория, чей диапазон [min, max) содержит turnover."""
    for code, lo, hi in bands:
        if turnover >= lo and (hi is None or turnover < hi):
            return code
    return None


async def pick_category_code(session: AsyncSession, turnover: Decimal) -> str | None:
    # Нет оборота (нет подписанных договоров за окно) → без категории «—», а не S2.
    # S2 имеет min=0, иначе нулевой клиент ошибочно попадал бы в S2.
    if turnover <= 0:
        return None
    cats = (
        await session.execute(
            select(ClientCategory).where(ClientCategory.is_active.is_(True)).order_by(ClientCategory.sort_order)
        )
    ).scalars().all()
    return match_category(turnover, [(c.code, c.min_amount, c.max_amount) for c in cats])


async def _members_of_group(session: AsyncSession, group_id: int) -> list[Counterparty]:
    return list((await session.execute(select(Counterparty).where(Counterparty.group_id == group_id))).scalars().all())


async def assign_for_counterparty(session: AsyncSession, counterparty_id: int) -> None:
    """Пересчитать категорию для контрагента (или его группы) и записать в кеш."""
    cp = (await session.execute(select(Counterparty).where(Counterparty.id == counterparty_id))).scalar_one_or_none()
    if not cp:
        return
    now = datetime.now(UTC)
    if cp.group_id:
        group = (await session.execute(select(ClientGroup).where(ClientGroup.id == cp.group_id))).scalar_one_or_none()
        if group:
            members = await _members_of_group(session, group.id)
            turnover = await compute_turnover_rub(session, [m.id for m in members], now)
            code = await pick_category_code(session, turnover)
            group.turnover_rub, group.category_code, group.category_recalc_at = turnover, code, now
            for m in members:
                m.category_code, m.turnover_rub, m.category_recalc_at = code, turnover, now
            return
    turnover = await compute_turnover_rub(session, [cp.id], now)
    cp.category_code = await pick_category_code(session, turnover)
    cp.turnover_rub = turnover
    cp.category_recalc_at = now


async def recompute_all(session: AsyncSession) -> int:
    """Пересчёт категорий по всем группам и одиночным контрагентам (ночной cron / кнопка)."""
    now = datetime.now(UTC)
    n = 0
    for g in (await session.execute(select(ClientGroup))).scalars().all():
        members = await _members_of_group(session, g.id)
        turnover = await compute_turnover_rub(session, [m.id for m in members], now)
        code = await pick_category_code(session, turnover)
        g.turnover_rub, g.category_code, g.category_recalc_at = turnover, code, now
        for m in members:
            m.category_code, m.turnover_rub, m.category_recalc_at = code, turnover, now
        n += 1
    for cp in (await session.execute(select(Counterparty).where(Counterparty.group_id.is_(None)))).scalars().all():
        turnover = await compute_turnover_rub(session, [cp.id], now)
        cp.category_code = await pick_category_code(session, turnover)
        cp.turnover_rub = turnover
        cp.category_recalc_at = now
        n += 1
    await session.commit()
    return n


def seed_categories_payload() -> list[dict]:
    """Категории из Excel (пороги в ₽ + опции)."""
    return [
        {"code": "L", "name": "L (крупные)", "group": None, "min_amount": 2_000_000, "max_amount": None,
         "sort_order": 1, "color": "#172747",
         "options": ["Выездное внедрение", "Приоритетная разработка", "Выделенный руководитель проекта (ТОП)",
                     "Проактивный CSM-менеджмент", "Контент-поддержка без лимита",
                     "Техподдержка на всех уровнях (чат/звонки/миты/выезд)", "SLA 30 минут",
                     "Серверная версия", "Ежеквартальная демонстрация обновлений",
                     "Приоритетный тестовый доступ к новинкам (1 мес)"]},
        {"code": "M", "name": "M (средние)", "group": None, "min_amount": 500_000, "max_amount": 2_000_000,
         "sort_order": 2, "color": "#2B4987",
         "options": ["Платное выездное внедрение", "Руководитель проекта Senior", "Проактивный CSM-менеджмент",
                     "Разовые платные доработки", "Техподдержка (чат/звонки/миты)", "SLA 30 минут",
                     "Контент-поддержка до ~10 000 ₽/мес", "Серверная версия за доп. плату",
                     "Приоритетный тестовый доступ (14 дней)"]},
        {"code": "S1", "name": "S1", "group": "S", "min_amount": 300_000, "max_amount": 500_000,
         "sort_order": 3, "color": "#6B7A99",
         "options": ["Базовое онлайн-внедрение", "Разовые платные доработки после внедрения",
                     "Техподдержка (чат)", "SLA 1 час", "Контент-поддержка платно"]},
        {"code": "S2", "name": "S2", "group": "S", "min_amount": 0, "max_amount": 300_000,
         "sort_order": 4, "color": "#9AA6BF",
         "options": ["Бот техподдержки", "Контент-поддержка платно", "Базовое онлайн-внедрение", "SLA до 2 часов"]},
    ]


async def seed_categories(session: AsyncSession) -> int:
    """Сид категорий (insert-missing по code), сериализован advisory-локом (гонка реплик)."""
    await session.execute(text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_KEY})
    try:
        existing = set((await session.execute(select(ClientCategory.code))).scalars().all())
        inserted = 0
        for c in seed_categories_payload():
            if c["code"] in existing:
                continue
            session.add(ClientCategory(
                code=c["code"], name=c["name"], group=c.get("group"),
                min_amount=Decimal(str(c["min_amount"])),
                max_amount=(Decimal(str(c["max_amount"])) if c["max_amount"] is not None else None),
                options=c["options"], color=c.get("color"), sort_order=c["sort_order"],
            ))
            inserted += 1
        if inserted:
            await session.commit()
        return inserted
    finally:
        await session.execute(text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_KEY})
