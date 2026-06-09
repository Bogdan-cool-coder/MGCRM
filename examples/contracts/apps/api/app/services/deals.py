"""Воронки/сделки: ACL видимости этапов, сид (Отдел продаж + 14 этапов AmoCRM)."""
from __future__ import annotations

from sqlalchemy import text
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

from app.models import Department, LostReason, Pipeline, PipelineStage, User, UserRole
from app.services.deals_v2 import DEFAULT_LOST_REASONS, NEW_SALES_STAGES

_SEED_LOCK_KEY = 728_274_004
_SEED_LOCK_LOST_REASONS = 728_274_020


def stage_visible_to(stage: PipelineStage, user: User) -> bool:
    """Этап виден: admin/director — всегда; пустые списки доступа = всем; иначе — по отделу/юзеру."""
    if user.role in (UserRole.admin, UserRole.director):
        return True
    dept_ids = stage.visible_department_ids or []
    user_ids = stage.visible_user_ids or []
    if not dept_ids and not user_ids:
        return True
    if user.id in user_ids:
        return True
    if user.department_id is not None and user.department_id in dept_ids:
        return True
    return False


async def visible_stage_ids(session: AsyncSession, user: User) -> set[int]:
    stages = (await session.execute(select(PipelineStage))).scalars().all()
    return {s.id for s in stages if stage_visible_to(s, user)}


def avg_days_in_stage(changed_ats: list, now) -> float:
    """Средн. число дней «в этапе» для текущих сделок (now - stage_changed_at)."""
    days = [(now - t).total_seconds() / 86400 for t in changed_ats if t is not None]
    return round(sum(days) / len(days), 1) if days else 0.0


def is_redundant_stage_move(
    current_stage_id: int, target_stage_id: int, substage_id: int | None,
) -> bool:
    """True, если перевод сделки в этап — холостой (no-op).

    P1 concurrency (audit S6 B1): под row-lock'ом в move_deal перечитываем
    текущий stage_id. Если сделка уже в целевом этапе И не запрошено уточнение
    substage_id — повторный move (вторая реплика api / двойной клик) не должен
    писать дублирующую DealStageHistory и перефайривать автоматизации/вебхуки.
    Когда substage_id задан, move не холостой даже при равенстве этапов: нужен
    второй переход в подстатус (await_payment/paid). Чистая функция — тестируется
    без БД.
    """
    return current_stage_id == target_stage_id and not substage_id


def ordered_lock_ids(ids: list[int]) -> list[int]:
    """Детерминированный порядок взятия row-lock'ов в bulk-операциях.

    P1 concurrency (audit S6 B1 follow-up): bulk_deals лочит сделки по очереди в
    одной транзакции. Если два конкурентных bulk-запроса передают пересекающиеся
    id в ПРОТИВОПОЛОЖНОМ порядке (req A: [1,2], req B: [2,1]), Postgres ловит
    deadlock (40P01) и аборитит одну из транзакций → необработанный 500.
    Сортируем id по возрастанию ПЕРЕД lock-циклом, чтобы все запросы брали
    блокировки в одном порядке — deadlock'а по lock-ordering не будет. Дедупим
    (set), чтобы дубль id в data.ids не обрабатывался дважды. Чистая функция —
    тестируется без БД.
    """
    return sorted(set(ids))


# ============ Hot deals (Эпик 10) ============

# Пороги «горячести»:
# - застряла дольше HOT_IDLE_DAYS_THRESHOLD без движения этапа;
# - до целевой даты закрытия (expected_close_date) меньше HOT_DEADLINE_DAYS.
# Цифры подобраны для AmoCRM-style воронки (14 этапов, средний цикл 14-21 день);
# править осознанно — это влияет на UI-дашборд «горячие сделки» прямо сейчас.
HOT_IDLE_DAYS_THRESHOLD = 3
HOT_DEADLINE_DAYS = 7


def compute_heat_reason(
    idle_days: int, days_to_close: int | None
) -> str | None:
    """Pure-function классификатор «почему сделка горячая».

    Возвращает:
    - "deadline" — если до expected_close_date меньше HOT_DEADLINE_DAYS (даже
      если idle_days маленький, deadline важнее);
    - "idle" — если простаивает дольше HOT_IDLE_DAYS_THRESHOLD;
    - None — если оба критерия не сработали (сделка НЕ горячая).

    Приоритет deadline над idle важен: deadline через 2 дня + idle=1 → "deadline",
    а не "idle" (потому что менеджеру важнее «горит срок», чем «давно не трогали»).

    >>> compute_heat_reason(idle_days=5, days_to_close=None)
    'idle'
    >>> compute_heat_reason(idle_days=1, days_to_close=2)
    'deadline'
    >>> compute_heat_reason(idle_days=10, days_to_close=3)
    'deadline'
    >>> compute_heat_reason(idle_days=1, days_to_close=30)
    >>> compute_heat_reason(idle_days=1, days_to_close=None)
    """
    if days_to_close is not None and days_to_close < HOT_DEADLINE_DAYS:
        return "deadline"
    if idle_days > HOT_IDLE_DAYS_THRESHOLD:
        return "idle"
    return None


# Legacy-этапы AmoCRM (до DEALS 2.0). Оставлены как историческая справка —
# структура свежей БД теперь идёт из NEW_SALES_STAGES (services/deals_v2.py).
AMO_STAGES = [
    ("Входящие лиды", "#9AA6BF", False, False),
    ("Исходящие лиды", "#9AA6BF", False, False),
    ("Квалификация", "#E6B800", False, False),
    ("Назначить встречу", "#39A85B", False, False),
    ("Выезд", "#7C5CBF", False, False),
    ("Встреча", "#7C5CBF", False, False),
    ("Холодные (заморозка)", "#3B82C4", False, False),
    ("Тёплые", "#E8853A", False, False),
    ("Trial", "#E8853A", False, False),
    ("Горячие", "#D14545", False, False),
    ("Успех", "#1F9D55", True, False),
    ("Проигрыш", "#6B7280", False, True),
]


async def seed_pipeline(session: AsyncSession) -> int:
    """Сид «Отдел продаж» + воронки «Продажи» (DEALS 2.0 структура). Advisory-lock.

    Insert-missing pattern: дозаливает недостающие этапы по code (идемпотентно),
    проставляет parent_stage_id для подстатусов. Не трогает существующие этапы
    (миграция 0074 отвечает за ремап/переименование прод-данных).
    """
    await session.execute(text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_KEY})
    try:
        dep = (await session.execute(select(Department).where(Department.name == "Отдел продаж"))).scalar_one_or_none()
        if not dep:
            session.add(Department(name="Отдел продаж", sort_order=1))
            await session.commit()

        pipe = (await session.execute(
            select(Pipeline).where(Pipeline.name == "Продажи", Pipeline.kind == "sales")
        )).scalar_one_or_none()
        if pipe is None:
            pipe = Pipeline(name="Продажи", kind="sales", is_active=True, sort_order=1)
            session.add(pipe)
            await session.flush()

        existing = (await session.execute(
            select(PipelineStage).where(PipelineStage.pipeline_id == pipe.id)
        )).scalars().all()
        by_code: dict[str, PipelineStage] = {s.code: s for s in existing if s.code}

        added = 0
        # Первый проход: создаём недостающие этапы (без parent — resolved во 2-м).
        for spec in NEW_SALES_STAGES:
            if spec["code"] in by_code:
                continue
            st = PipelineStage(
                pipeline_id=pipe.id,
                name=spec["name"],
                code=spec["code"],
                sort_order=spec["sort_order"],
                color=spec["color"],
                is_won=spec["is_won"],
                is_lost=spec["is_lost"],
                hidden_by_default=spec["hidden_by_default"],
                stage_features=list(spec["stage_features"]),
                won_gate=spec["won_gate"],
            )
            session.add(st)
            by_code[spec["code"]] = st
            added += 1
        await session.flush()

        # Второй проход: проставляем parent_stage_id (подстатусы под «Успех»).
        for spec in NEW_SALES_STAGES:
            parent_code = spec.get("parent_code")
            if not parent_code:
                continue
            child = by_code.get(spec["code"])
            parent = by_code.get(parent_code)
            if child is not None and parent is not None and child.parent_stage_id != parent.id:
                child.parent_stage_id = parent.id

        await session.commit()
        return added
    finally:
        await session.execute(text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_KEY})


async def seed_lost_reasons(session: AsyncSession) -> int:
    """Сид реестра причин отказа (LostReason). Insert-missing + advisory-lock."""
    await session.execute(text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_LOST_REASONS})
    try:
        existing = {
            r.name for r in (await session.execute(select(LostReason))).scalars().all()
        }
        added = 0
        for name, order in DEFAULT_LOST_REASONS:
            if name in existing:
                continue
            session.add(LostReason(name=name, sort_order=order, is_active=True))
            added += 1
        await session.commit()
        return added
    finally:
        await session.execute(text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_LOST_REASONS})
