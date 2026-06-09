"""Реестр клиентов / Customer Success (Фаза 4).

Чистые механики (тестируемые без БД): % внедрения по чек-листу, тренд активности,
классификация тира A1–A6, триггеры «требуют внимания», агрегаты KPI как в листе «Аналитика».
+ Идемпотентные сидеры справочников (платформы/регионы/модули/чек-листы) и воронки
«Жизненный цикл клиента» (этапы B0–B6 / A1–A6 / C0) под advisory-lock.
"""
from __future__ import annotations

import json
import logging
import re
from collections import Counter
from datetime import UTC, date, datetime
from decimal import Decimal

from sqlalchemy import text
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy.future import select

logger = logging.getLogger(__name__)

from app.models import (
    ActivitySnapshot,
    ChecklistTemplate,
    ChecklistTemplateItem,
    ClientSubscription,
    Company,
    Contract,
    ContractItem,
    Counterparty,
    ImplementationItemStatus,
    Module,
    Pipeline,
    PipelineStage,
    Platform,
    Region,
    RegistryKpiSnapshot,
    Setting,
    SubscriptionModule,
)

HEALTH_THRESHOLDS_KEY = "cs_health_thresholds"

_SEED_LOCK_CS_REF = 728_274_005
_SEED_LOCK_LIFECYCLE = 728_274_006

LIFECYCLE_PIPELINE_NAME = "Жизненный цикл клиента"

# ============ Чек-лист внедрения: % готовности ============

# Статусы, при которых пункт не учитывается в знаменателе (не нужен клиенту)
_NOT_APPLICABLE = {"not_required", "not_used"}
_DONE = {"done"}


def _clamp01(x: float) -> float:
    return max(0.0, min(1.0, x))


def item_completion(status: str, num_done: int | None, num_total: int | None, pct: float | None) -> tuple[bool, float]:
    """(применим ли пункт, степень готовности 0..1).

    Приоритет источника готовности: явный pct → дробь X/Y → статус.
    in_progress даёт частичный кредит 0.5; waiting/not_started/not_done = 0.
    """
    if status in _NOT_APPLICABLE:
        return (False, 0.0)
    if pct is not None:
        return (True, _clamp01(float(pct)))
    if num_total:
        return (True, _clamp01((num_done or 0) / num_total))
    if status in _DONE:
        return (True, 1.0)
    if status == "in_progress":
        return (True, 0.5)
    return (True, 0.0)


def compute_checklist_pct(items: list[tuple[str, int | None, int | None, float | None]]) -> float:
    """% внедрения = Σ готовности применимых пунктов / число применимых, ×100 (0..100)."""
    applicable = 0
    done = 0.0
    for status, num_done, num_total, pct in items:
        ok, c = item_completion(status, num_done, num_total, pct)
        if ok:
            applicable += 1
            done += c
    if applicable == 0:
        return 0.0
    return round(done / applicable * 100, 1)


# ============ Активность: тренд и тиры A1–A6 ============

# Пороги по метрике «действия/сделки за период». Конфигурируется через Setting cs_health_thresholds.
DEFAULT_HEALTH_THRESHOLDS: dict = {
    "window": 4,            # окно усреднения (число последних периодов)
    "dormant_periods": 2,   # сколько последних периодов = 0 → A6 (спящий)
    "bands": [5, 20, 50, 150],  # A5≤b0 < A4≤b1 < A3≤b2 < A2≤b3 < A1
}


def trailing_zeros(values: list[int]) -> int:
    """Сколько последних периодов подряд равны 0 (values от старых к новым)."""
    n = 0
    for v in reversed(values):
        if v == 0:
            n += 1
        else:
            break
    return n


def window_avg(values: list[int], n: int) -> float:
    w = values[-n:] if values else []
    return round(sum(w) / len(w), 2) if w else 0.0


def activity_trend(values: list[int], n: int) -> float:
    """% изменения среднего за последние n периодов vs предыдущие n (отрицательное = падение)."""
    if len(values) < 2:
        return 0.0
    last = values[-n:]
    prev = values[-2 * n:-n]
    la = sum(last) / len(last) if last else 0.0
    pa = sum(prev) / len(prev) if prev else 0.0
    if pa == 0:
        return 0.0 if la == 0 else 100.0
    return round((la - pa) / pa * 100, 1)


def classify_tier(avg_actions: float, dormant_periods: int, thresholds: dict | None = None) -> str:
    """Тир сопровождения A1–A6 по средней активности и дормантности (метрика = действия/сделки)."""
    t = thresholds or DEFAULT_HEALTH_THRESHOLDS
    if dormant_periods >= t["dormant_periods"]:
        return "A6"
    b = t["bands"]
    if avg_actions <= b[0]:
        return "A5"
    if avg_actions <= b[1]:
        return "A4"
    if avg_actions <= b[2]:
        return "A3"
    if avg_actions <= b[3]:
        return "A2"
    return "A1"


def compute_health(values: list[int], thresholds: dict | None = None) -> dict:
    """Сводка здоровья по ряду активности. tier=None, если данных нет."""
    t = thresholds or DEFAULT_HEALTH_THRESHOLDS
    n = t.get("window", 4)
    if not values:
        return {"tier": None, "avg": 0.0, "trend_pct": 0.0, "dormant_periods": 0}
    dz = trailing_zeros(values)
    avg = window_avg(values, n)
    return {
        "tier": classify_tier(avg, dz, t),
        "avg": avg,
        "trend_pct": activity_trend(values, n),
        "dormant_periods": dz,
    }


# Дискретная 0..100 шкала здоровья по тиру. Раньше health_score считался как
# clamp(avg / top_band) * 100 — у всех активных клиентов с avg ≥ верхней полосы
# (A1) получалось ровно 100, шкала «схлопывалась» наверху и не различала здоровье
# (#11). Теперь основа — РАНГ тира A1..A6/C0, что монотонно и осмысленно
# дискриминирует. Внутри тира (кроме крайних A1/A6) добавляем небольшую
# интерполяцию по avg относительно полос порогов, чтобы score не был ступенькой.
_TIER_SCORE_BASE: dict[str, float] = {
    "A1": 100.0,
    "A2": 80.0,
    "A3": 60.0,
    "A4": 40.0,
    "A5": 20.0,
    "A6": 5.0,
    "C0": 0.0,
}
# Полуширина «коридора» интерполяции вокруг базы тира (баллы). Score тира Ax
# гуляет в [base - SPAN, base + SPAN] по положению avg внутри полосы порога.
_TIER_SCORE_SPAN = 10.0


def health_score_from_tier(
    tier: str | None, avg: float | None = None, thresholds: dict | None = None
) -> float | None:
    """0..100 балл здоровья по тиру (+мягкая интерполяция по avg внутри полосы).

    None → None (данных нет, UI не рисует «0 баллов» как реальный скоринг).

    Маппинг базы тира (монотонно убывает): A1=100, A2=80, A3=60, A4=40, A5=20,
    A6=5, C0=0. Это даёт честную дискриминацию здоровья вместо плоского 100.

    Если передан avg и тир — Ax с конечной полосой, score интерполируется внутри
    [base-SPAN, base+SPAN] по позиции avg в полосе [lo, hi] своего тира. Для A1
    (верхняя полоса, hi=∞) и A6/C0 — без интерполяции (только база), чтобы не
    плодить искусственный разброс на открытых интервалах.
    """
    if tier is None:
        return None
    base = _TIER_SCORE_BASE.get(tier)
    if base is None:
        return None
    if avg is None or tier in ("A1", "A6", "C0"):
        return round(base, 2)
    t = thresholds or DEFAULT_HEALTH_THRESHOLDS
    bands = t.get("bands") or DEFAULT_HEALTH_THRESHOLDS["bands"]
    # Границы полос: A5≤b0 < A4≤b1 < A3≤b2 < A2≤b3 < A1. Полоса тира [lo, hi].
    ranges: dict[str, tuple[float, float]] = {
        "A5": (0.0, float(bands[0])),
        "A4": (float(bands[0]), float(bands[1])),
        "A3": (float(bands[1]), float(bands[2])),
        "A2": (float(bands[2]), float(bands[3])),
    }
    rng = ranges.get(tier)
    if rng is None:
        return round(base, 2)
    lo, hi = rng
    if hi <= lo:
        return round(base, 2)
    frac = _clamp01((float(avg) - lo) / (hi - lo))  # 0 у нижней границы, 1 у верхней
    score = base + (frac * 2 - 1) * _TIER_SCORE_SPAN  # base-SPAN .. base+SPAN
    return round(max(0.0, min(100.0, score)), 2)


# ============ Триггеры «требуют внимания» ============

def attention_flags(
    *,
    tier: str | None,
    trend_pct: float,
    dormant_periods: int,
    discount_until: date | None,
    last_fee_increase_at: date | None,
    today: date,
    drop_threshold_pct: float = -30.0,
    discount_soon_days: int = 30,
    upsell_months: int = 12,
) -> list[str]:
    """Список причин, по которым клиент требует внимания (для списка рисков на дашборде)."""
    flags: list[str] = []
    if dormant_periods >= 1:
        flags.append("dormant")          # нет активности
    if trend_pct <= drop_threshold_pct:
        flags.append("activity_drop")    # резкое падение
    if discount_until is not None and 0 <= (discount_until - today).days <= discount_soon_days:
        flags.append("discount_expiring")
    if last_fee_increase_at is None or (today - last_fee_increase_at).days >= upsell_months * 30:
        flags.append("upsell")           # давно не повышали АП
    if tier in ("A5", "A6"):
        flags.append("low_health")
    return flags


def on_premise_attention(on_premise: bool, manual_tier_override: str | None) -> bool:
    """CS hotfix (май 2026): on_premise клиенты без ручного override невидимы в
    attention-дашборде — ActivitySnapshot для них не поступает (телеметрии
    нет), поэтому health_tier остаётся None или некорректным.

    Решение — отдельный health_reason='on_premise_no_override', который ТП
    обязан закрыть либо проставлением manual_tier_override (зафиксировать тир
    вручную), либо переводом подписки на cloud (`on_premise=false`).

    Pure-function (без БД), тестируется в test_cs_hotfixes.py.
    """
    return bool(on_premise) and not manual_tier_override


# ============ Агрегаты KPI (как лист «Аналитика») ============

def compute_kpis(stage_codes: list[str | None]) -> dict:
    """Агрегаты реестра по кодам этапов ЖЦ (B0–B6 / A1–A6 / C0).

    CS-hotfix (0080): подписки без этапа ЖЦ (NULL stage — пустой статус в TSV
    или ещё не классифицированные) раньше тихо выпадали из total и operating.
    Теперь они учитываются в total и в operating (как «действующие, но не
    распределённые»), а также выносятся отдельной корзиной `no_stage`.
    """
    c = Counter(code for code in stage_codes if code)
    classified = sum(c.values())
    no_stage = sum(1 for code in stage_codes if not code)
    total = classified + no_stage
    active = c.get("A1", 0) + c.get("A2", 0)                          # «Активные»
    support = sum(c.get(f"A{i}", 0) for i in range(1, 6))            # A1..A5 «Сопровождение»
    in_impl = sum(c.get(f"B{i}", 0) for i in range(0, 6))           # B0..B5 «Внедряемые»
    closed = c.get("C0", 0)
    dormant = c.get("A6", 0) + c.get("B6", 0) + closed
    # operating = total минус «спящие/закрытые». no_stage остаётся в operating
    # (это действующие подписки, просто без классификации этапа).
    operating = total - dormant                                      # «Действующие (без A6/B6/C0)»
    return {
        "total": total,
        "active": active,
        "support": support,
        "in_implementation": in_impl,
        "operating": operating,
        "closed": closed,
        "no_stage": no_stage,
        "conversion_support": round(support / total, 4) if total else 0.0,
        "conversion_closed": round(closed / total, 4) if total else 0.0,
        "by_code": dict(c),
    }


# ============ Сид-данные справочников ============

PLATFORMS: list[tuple[str, str, int]] = [
    ("macrosales", "MacroSales", 1),
    ("macroerp", "MacroERP", 2),
]

REGIONS: list[tuple[str, str, int]] = [
    ("ca", "Центральная Азия", 1),
    ("gcc", "GCC (Залив)", 2),
    ("caucasus", "Кавказ", 3),
    # CS-hotfix (0080): отдельный регион РФ — раньше RU-клиенты падали в
    # region=None и смешивались с KZ-подписками, не фильтровались на дашборде.
    ("ru", "Россия / РФ", 4),
]

# Модули/спутники (флаги True/False из реестра) по платформам
MODULES: dict[str, list[tuple[str, str]]] = {
    "macrosales": [
        ("macroweb", "MacroWEB"),
        ("catalog", "МакроКаталог"),
        ("agent_cabinet", "Кабинет агента"),
        ("client_cabinet", "Кабинет клиента"),
        ("dco", "ДЦО"),
        ("keys_handover", "Передача ключей"),
        ("erp", "ERP"),
        ("1c", "1C"),
        ("macrodata", "MacroDATA"),
        ("wazzup", "Wazzup"),
        ("touchlink", "TouchLink / WebJack"),
        ("passport_ocr", "Распознавание паспортов"),
    ],
    "macroerp": [
        ("crm", "CRM"),
        ("bank", "BANK"),
        ("1c", "1C"),
        ("box", "Коробка"),
    ],
}

# Чек-листы внедрения по платформам: (code, label, group, kind, optional)
CHECKLISTS: dict[str, list[tuple[str, str, str, str, bool]]] = {
    "macrosales": [
        ("houses", "Дома", "Внедрение", "fraction", False),
        ("layouts", "Планировки", "Внедрение", "fraction", False),
        ("renders_in", "Внесение планы, рендеры", "Внедрение", "fraction", True),
        ("renders_mark", "Разметка планы, рендеры", "Внедрение", "fraction", True),
        ("telephony", "Телефония", "Внедрение", "status", True),
        ("calltracking", "Коллтрекинг", "Внедрение", "status", True),
        ("sms", "SMS", "Внедрение", "status", True),
        ("leadforms", "Лидформы сайта", "Внедрение", "status", True),
        ("feeds", "Фиды на классифайды", "Внедрение", "status", True),
        ("data_migration", "Перенос данных", "Внедрение", "status", True),
        ("other_integrations", "Прочие подключения", "Внедрение", "status", True),
        ("templates", "Шаблоны и настройки", "Внедрение", "percent", True),
        ("training", "Обучение", "Внедрение", "percent", True),
        ("passport_ocr", "Распознавание паспортов", "Внедрение", "status", True),
        # Качество внедрения (QA)
        ("qa_layouts", "Загруженные планировки квартир", "Качество", "status", True),
        ("qa_floors", "Загруженные поэтажки", "Качество", "status", True),
        ("qa_markup", "Сформирована разметка поэтажек", "Качество", "status", True),
        ("qa_finance", "Учёт финансов в сделках", "Качество", "status", True),
        ("qa_policy", "Базовые настройки политики компании", "Качество", "status", True),
    ],
    "macroerp": [
        ("info", "Сбор информации", "Внедрение", "status", True),
        ("construction", "Стройка", "Внедрение", "status", True),
        ("documents", "Документы", "Внедрение", "status", True),
        ("supply", "Снабжение", "Внедрение", "status", True),
        ("finance", "Финансы", "Внедрение", "status", True),
        ("tasks", "Задачник", "Внедрение", "status", True),
        ("tender", "Тендер", "Внедрение", "status", True),
        ("crm", "CRM", "Внедрение", "status", True),
        ("bank", "BANK", "Внедрение", "status", True),
        ("1c", "Интеграция с 1С", "Внедрение", "status", True),
    ],
}

# Воронка «Жизненный цикл клиента»: (code, name, color, is_won, is_lost)
LIFECYCLE_STAGES: list[tuple[str, str, str, bool, bool]] = [
    ("B0", "B0 · Внедрение: старт", "#9AA6BF", False, False),
    ("B1", "B1 · Внедрение: сбор данных", "#8C9BBE", False, False),
    ("B2", "B2 · Внедрение: настройка", "#7C8CB5", False, False),
    ("B3", "B3 · Внедрение: интеграции", "#6E7FAD", False, False),
    ("B4", "B4 · Внедрение: обучение", "#5F71A4", False, False),
    ("B5", "B5 · Внедрение: приёмка/акт", "#39A85B", False, False),
    ("B6", "B6 · Внедрение: стоп/проблема", "#C0392B", False, False),
    ("A1", "A1 · Активный (макс.)", "#1F9D55", False, False),
    ("A2", "A2 · Активный", "#3FAE6A", False, False),
    ("A3", "A3 · Сопровождение", "#E6B800", False, False),
    ("A4", "A4 · Низкая активность", "#E8853A", False, False),
    ("A5", "A5 · Риск оттока", "#D9682A", False, False),
    ("A6", "A6 · Спящий", "#9B59B6", False, False),
    ("C0", "C0 · Отвал", "#6B7280", False, True),
]


async def seed_cs_reference(session: AsyncSession) -> int:
    """Insert-missing платформ/регионов/модулей/чек-листов (по code). Advisory-lock (гонка реплик)."""
    await session.execute(text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_CS_REF})
    try:
        added = 0
        # Платформы
        existing_pl = {p.code: p for p in (await session.execute(select(Platform))).scalars().all()}
        for code, name, order in PLATFORMS:
            if code not in existing_pl:
                p = Platform(code=code, name=name, sort_order=order)
                session.add(p)
                existing_pl[code] = p
                added += 1
        await session.flush()

        # Регионы
        existing_rg = {r.code for r in (await session.execute(select(Region))).scalars().all()}
        for code, name, order in REGIONS:
            if code not in existing_rg:
                session.add(Region(code=code, name=name, sort_order=order))
                added += 1

        # Модули (по platform_id+code)
        existing_mod = {(m.platform_id, m.code) for m in (await session.execute(select(Module))).scalars().all()}
        for pl_code, mods in MODULES.items():
            pl = existing_pl.get(pl_code)
            if not pl:
                continue
            for i, (code, name) in enumerate(mods):
                if (pl.id, code) not in existing_mod:
                    session.add(Module(code=code, name=name, platform_id=pl.id, sort_order=i))
                    added += 1

        # Чек-листы (один шаблон на платформу) + пункты
        for pl_code, items in CHECKLISTS.items():
            pl = existing_pl.get(pl_code)
            if not pl:
                continue
            tpl = (await session.execute(
                select(ChecklistTemplate).where(ChecklistTemplate.platform_id == pl.id)
            )).scalars().first()
            if not tpl:
                tpl = ChecklistTemplate(platform_id=pl.id, name=f"Внедрение {pl.name}")
                session.add(tpl)
                await session.flush()
                added += 1
            existing_items = {
                it.code for it in (await session.execute(
                    select(ChecklistTemplateItem).where(ChecklistTemplateItem.template_id == tpl.id)
                )).scalars().all()
            }
            for i, (code, label, group, kind, optional) in enumerate(items):
                if code not in existing_items:
                    session.add(ChecklistTemplateItem(
                        template_id=tpl.id, code=code, label=label, group=group,
                        kind=kind, optional=optional, sort_order=i,
                    ))
                    added += 1
        await session.commit()
        return added
    finally:
        await session.execute(text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_CS_REF})


async def seed_lifecycle_pipeline(session: AsyncSession) -> int:
    """Сид воронки «Жизненный цикл клиента» (этапы B0–B6 / A1–A6 / C0). Advisory-lock."""
    await session.execute(text("SELECT pg_advisory_lock(:k)"), {"k": _SEED_LOCK_LIFECYCLE})
    try:
        pipe = (await session.execute(
            select(Pipeline).where(Pipeline.name == LIFECYCLE_PIPELINE_NAME)
        )).scalar_one_or_none()
        if pipe:
            # воронка уже есть — гарантируем kind=lifecycle (чтобы не светилась в /deals)
            if pipe.kind != "lifecycle":
                pipe.kind = "lifecycle"
                await session.commit()
            return 0
        pipe = Pipeline(name=LIFECYCLE_PIPELINE_NAME, kind="lifecycle", is_active=True, sort_order=2)
        session.add(pipe)
        await session.flush()
        for i, (code, name, color, won, lost) in enumerate(LIFECYCLE_STAGES):
            session.add(PipelineStage(
                pipeline_id=pipe.id, name=name, code=code, sort_order=i,
                color=color, is_won=won, is_lost=lost,
            ))
        await session.commit()
        return len(LIFECYCLE_STAGES)
    finally:
        await session.execute(text("SELECT pg_advisory_unlock(:k)"), {"k": _SEED_LOCK_LIFECYCLE})


# ============ DB-механики (используются роутерами и cron-джобами) ============

async def get_health_thresholds(session: AsyncSession) -> dict:
    """Пороги здоровья: Setting cs_health_thresholds (JSON) поверх дефолтов."""
    row = (await session.execute(select(Setting).where(Setting.key == HEALTH_THRESHOLDS_KEY))).scalar_one_or_none()
    t = dict(DEFAULT_HEALTH_THRESHOLDS)
    if row and row.value:
        try:
            t.update(json.loads(row.value))
        except (ValueError, TypeError):
            pass
    return t


async def lifecycle_stage_by_code(session: AsyncSession) -> dict[str, PipelineStage]:
    """{код этапа → PipelineStage} для воронки «Жизненный цикл клиента»."""
    pipe = (await session.execute(
        select(Pipeline).where(Pipeline.name == LIFECYCLE_PIPELINE_NAME)
    )).scalar_one_or_none()
    if not pipe:
        return {}
    stages = (await session.execute(
        select(PipelineStage).where(PipelineStage.pipeline_id == pipe.id)
    )).scalars().all()
    return {s.code: s for s in stages if s.code}


async def recompute_checklist_pct(session: AsyncSession, subscription_id: int) -> float | None:
    """% внедрения по чек-листу платформы подписки. Пишет ClientSubscription.impl_pct."""
    sub = (await session.execute(
        select(ClientSubscription).where(ClientSubscription.id == subscription_id)
    )).scalar_one_or_none()
    if not sub:
        return None
    tpl = (await session.execute(
        select(ChecklistTemplate).where(ChecklistTemplate.platform_id == sub.platform_id)
    )).scalars().first()
    if not tpl:
        return None
    items = (await session.execute(
        select(ChecklistTemplateItem).where(ChecklistTemplateItem.template_id == tpl.id)
    )).scalars().all()
    statuses = {
        s.template_item_id: s
        for s in (await session.execute(
            select(ImplementationItemStatus).where(ImplementationItemStatus.subscription_id == sub.id)
        )).scalars().all()
    }
    rows: list[tuple[str, int | None, int | None, float | None]] = []
    for it in items:
        st = statuses.get(it.id)
        if st is None:
            rows.append(("not_started", None, None, None))
        else:
            rows.append((st.status, st.num_done, st.num_total, float(st.pct) if st.pct is not None else None))
    pct = compute_checklist_pct(rows)
    sub.impl_pct = pct
    return pct


async def recompute_subscription_health(
    session: AsyncSession,
    sub: ClientSubscription,
    thresholds: dict | None = None,
    stage_map: dict[str, PipelineStage] | None = None,
    today: date | None = None,
    dispatch_webhook: bool = True,
) -> dict:
    """Считает здоровье из ряда активности и пишет кеш в подписку.

    Если подписка СЕЙЧАС на этапе сопровождения (код Ax) и нет ручного override —
    переводит её на этап, соответствующий вычисленному тиру. B-этапы/C0 не трогает.
    """
    t = thresholds or await get_health_thresholds(session)
    today = today or datetime.now(UTC).date()
    # Эпик 11.2: запоминаем старый tier для health_changed webhook'а.
    old_tier = sub.health_tier
    values = [
        v for (v,) in (await session.execute(
            select(ActivitySnapshot.value)
            .where(ActivitySnapshot.subscription_id == sub.id, ActivitySnapshot.metric == "actions")
            .order_by(ActivitySnapshot.period_start)
        )).all()
    ]
    h = compute_health(values, t)
    computed_tier = h["tier"]
    sub.activity_avg = h["avg"]
    sub.activity_trend_pct = h["trend_pct"]
    sub.dormant_periods = h["dormant_periods"]
    # health_score: осмысленная 0..100 шкала по РАНГУ тира (#11). Раньше был
    # clamp(avg/top_band)*100 → у всех активных ≥ порога A1 ровно 100 (шкала
    # схлопывалась наверху, не различала здоровье). Теперь база по тиру
    # A1=100..A6=5/C0=0 + мягкая интерполяция по avg внутри полосы.
    # NB: при ручном override (effective_tier) score считаем по computed_tier —
    # это объективный сигнал телеметрии, а не зафиксированный ТП ярлык.
    _score = health_score_from_tier(computed_tier, float(h["avg"]), t)
    sub.health_score = Decimal(str(_score)) if _score is not None else None

    # текущий код этапа ЖЦ
    cur_stage = None
    if sub.lifecycle_stage_id is not None:
        cur_stage = (await session.execute(
            select(PipelineStage).where(PipelineStage.id == sub.lifecycle_stage_id)
        )).scalar_one_or_none()
    cur_code = cur_stage.code if cur_stage else None

    effective_tier = sub.manual_tier_override or computed_tier
    sub.health_tier = effective_tier

    # авто-перевод A-этапа по активности (только на сопровождении, без ручного override)
    if (
        not sub.manual_tier_override
        and computed_tier
        and cur_code
        and cur_code.startswith("A")
        and computed_tier != cur_code
    ):
        smap = stage_map if stage_map is not None else await lifecycle_stage_by_code(session)
        target = smap.get(computed_tier)
        if target:
            sub.lifecycle_stage_id = target.id
            sub.stage_changed_at = datetime.now(UTC)
            cur_code = computed_tier

    reasons = attention_flags(
        tier=effective_tier,
        trend_pct=h["trend_pct"],
        dormant_periods=h["dormant_periods"],
        discount_until=sub.discount_until,
        last_fee_increase_at=sub.last_fee_increase_at,
        today=today,
    )
    # CS hotfix (май 2026): on_premise + нет manual_tier_override → клиент
    # невидим в attention (нет данных активности). Поднимаем флаг отдельно,
    # чтобы ТП обязательно его обработал.
    if on_premise_attention(sub.on_premise, sub.manual_tier_override):
        reasons.append("on_premise_no_override")
    sub.health_reasons = reasons
    sub.health_computed_at = datetime.now(UTC)
    # Эпик 11.2: outbound webhook subscription.health_changed (если tier сменился).
    # Не блокирует ответ — safe_dispatch_event catch'ит ошибки. NB: вызывается
    # ВНУТРИ recompute_subscription_health, чтобы все вызывающие места получили
    # событие (cron health-recompute, ручной endpoint, импорт). Если old_tier
    # был None (первое вычисление) — событие тоже шлём (это переход «нет тира → A1»).
    # dispatch_webhook=False у массового фонового пересчёта (cron): иначе первый
    # же проход разошлёт сотни health_changed (старый кеш None → tier) и зашумит
    # подписчиков. Реальные смены тира всё равно поймает ручной/импортный путь.
    if dispatch_webhook and old_tier != effective_tier:
        from app.services.webhook_dispatcher import (
            safe_dispatch_event,
            subscription_to_payload,
        )
        payload = subscription_to_payload(sub)
        payload["old_tier"] = old_tier
        payload["new_tier"] = effective_tier
        await safe_dispatch_event(
            session, "subscription.health_changed", "subscription", sub.id, payload,
        )
    return {"tier": effective_tier, "computed_tier": computed_tier, **h}


# ============ Связь «договор подписан → подписка» (Фаза 4, волна 2) ============

# Продукт договора → платформа реестра CS
PLATFORM_BY_PRODUCT_CODE: dict[str, str] = {
    "macrosales": "macrosales",
    "macrocrm": "macrosales",   # MacroCRM — линейка Sales
    "macroerp": "macroerp",
}

# Страна контрагента → регион подписки (автоподсказка)
REGION_BY_COUNTRY: dict[str, str] = {
    "kz": "ca", "uz": "ca", "kg": "ca", "tj": "ca", "tm": "ca",
    "ge": "caucasus", "am": "caucasus", "az": "caucasus",
    "ae": "gcc", "sa": "gcc", "qa": "gcc", "kw": "gcc", "bh": "gcc", "om": "gcc",
    "ru": "ru",  # CS-hotfix (0080): РФ → отдельный регион
}


def _norm(s: str | None) -> str:
    """Нормализация для эвристического матчинга: только буквы/цифры, нижний регистр."""
    return re.sub(r"[^a-zа-я0-9]", "", (s or "").lower())


def _tokens(s: str | None) -> set[str]:
    """Токены строки (слова из букв/цифр, нижний регистр) для матча по границе слова."""
    return {t for t in re.split(r"[^a-zа-я0-9]+", (s or "").lower()) if t}


def _module_matches(module_name: str | None, module_code: str | None, item_tokens: set[str], item_norm: str) -> bool:
    """CS-hotfix (0080): матч продукт↔модуль без ложных включений на коротких кодах.

    Раньше короткий code ("1c"/"crm"/"erp") искался подстрокой в склеенном тексте
    позиции → ложные срабатывания (напр. "crm" внутри "microcrmexport"). Теперь:
    - code матчится ТОЛЬКО как отдельный токен (граница слова) — точное совпадение;
    - name матчится либо как отдельный токен, либо подстрокой, но только если name
      достаточно длинное (>=4 символа после нормализации), чтобы не ловить шум.
    """
    kcode = (module_code or "").lower().strip()
    if kcode and kcode in item_tokens:
        return True
    kname_tokens = _tokens(module_name)
    if kname_tokens and kname_tokens <= item_tokens:
        # все слова имени модуля присутствуют как отдельные токены позиции
        return True
    kname_norm = _norm(module_name)
    if len(kname_norm) >= 4 and kname_norm in item_norm:
        return True
    return False


async def _enable_modules_from_contract(session: AsyncSession, sub: ClientSubscription, contract: Contract) -> int:
    """Эвристика продукт↔модуль: включить модули подписки по названиям позиций договора.
    Матч по токенам/полному коду модуля (граница слова), а не подстрокой коротких
    кодов (#7). Существующие не трогает, не выключает (только добавляет). ТП может
    поправить тогглами в карточке."""
    items = (await session.execute(select(ContractItem).where(ContractItem.contract_id == contract.id))).scalars().all()
    # На каждую позицию — её токены (для матча по границе слова) и норм-строка (для длинных name).
    parsed = [(_tokens(it.name_snapshot), _norm(it.name_snapshot)) for it in items if it.name_snapshot]
    if not parsed:
        return 0
    modules = (await session.execute(
        select(Module).where((Module.platform_id == sub.platform_id) | (Module.platform_id.is_(None)))
    )).scalars().all()
    existing = {
        sm.module_id for sm in (await session.execute(
            select(SubscriptionModule).where(SubscriptionModule.subscription_id == sub.id)
        )).scalars().all()
    }
    added = 0
    for m in modules:
        if m.id in existing:
            continue
        if any(_module_matches(m.name, m.code, toks, nrm) for toks, nrm in parsed):
            session.add(SubscriptionModule(subscription_id=sub.id, module_id=m.id, enabled=True, status="по договору"))
            added += 1
    return added


async def ensure_subscription_from_contract(session: AsyncSession, contract: Contract) -> ClientSubscription | None:
    """Подписан договор → создать/привязать подписку клиента на платформу и перенести данные.

    Идемпотентно: ищет существующую подписку (клиент+платформа+регион); создаёт
    при отсутствии. Заполняет только пустые поля (не затирает ручной ввод).
    Новая подписка стартует на этапе B0.

    CS hotfix (май 2026): до фикса поиск шёл по (counterparty_id, platform_id)
    без region_id — это могло смешивать подписки одного клиента в KZ и UAE
    (KZ MacroSales + UAE MacroSales — разные регионы, должны быть разные
    подписки). Теперь — если region определился из country, ищем строго по
    тройке; если region неизвестен (нет country_code или его нет в маппинге) —
    fallback на старое поведение `(cp, platform)` + лог warning.
    """
    if not contract.counterparty_id:
        return None
    platform_code = PLATFORM_BY_PRODUCT_CODE.get((contract.product_code or "").lower())
    if not platform_code:
        return None
    platform = (await session.execute(select(Platform).where(Platform.code == platform_code))).scalar_one_or_none()
    if not platform:
        return None

    # CONTACTS 2.0 Ф3-B: регион из страны КОМПАНИИ (источник истины).
    # Раньше читалось из Counterparty.country_code; теперь — Company.country (ISO alpha-2)
    # с fallback на Company.country_code, и только потом на Counterparty.
    region = None
    country_code_for_region: str | None = None

    if contract.company_id:
        company = (await session.execute(
            select(Company).where(Company.id == contract.company_id)
        )).scalar_one_or_none()
        if company:
            # company.country — ISO alpha-2, primary. country_code — зеркало реквизита.
            country_code_for_region = (company.country or company.country_code or "").lower() or None
    if country_code_for_region is None and contract.counterparty_id:
        # Fallback: Counterparty (если company_id не задан или нет Company)
        cp = (await session.execute(
            select(Counterparty).where(Counterparty.id == contract.counterparty_id)
        )).scalar_one_or_none()
        if cp and cp.country_code:
            country_code_for_region = cp.country_code.lower()

    if country_code_for_region:
        rcode = REGION_BY_COUNTRY.get(country_code_for_region)
        if rcode:
            region = (await session.execute(
                select(Region).where(Region.code == rcode)
            )).scalar_one_or_none()

    signed_date = (contract.signed_at or datetime.now(UTC)).date()
    fee = contract.total if (contract.total and contract.total > 0) else None

    # Поиск существующей подписки.
    # Приоритет: company_id (если есть) + platform + region → строгая тройка.
    # Fallback на counterparty_id для подписок, где company_id ещё NULL.
    if contract.company_id and region is not None:
        sub = (await session.execute(
            select(ClientSubscription).where(
                ClientSubscription.company_id == contract.company_id,
                ClientSubscription.platform_id == platform.id,
                ClientSubscription.region_id == region.id,
            )
        )).scalars().first()
        if sub is None:
            # Fallback: та же подписка может быть без company_id (импорт до Ф3-B)
            sub = (await session.execute(
                select(ClientSubscription).where(
                    ClientSubscription.counterparty_id == contract.counterparty_id,
                    ClientSubscription.platform_id == platform.id,
                    ClientSubscription.region_id == region.id,
                )
            )).scalars().first()
    elif region is not None:
        sub = (await session.execute(
            select(ClientSubscription).where(
                ClientSubscription.counterparty_id == contract.counterparty_id,
                ClientSubscription.platform_id == platform.id,
                ClientSubscription.region_id == region.id,
            )
        )).scalars().first()
    else:
        # Region не определён → ищем по паре (company/cp, platform).
        logger.warning(
            "ensure_subscription_from_contract: region unresolved for "
            "company=%s counterparty=%s (country_code=%s), falling back to (company/cp, platform) search",
            contract.company_id,
            contract.counterparty_id,
            country_code_for_region,
        )
        if contract.company_id:
            sub = (await session.execute(
                select(ClientSubscription).where(
                    ClientSubscription.company_id == contract.company_id,
                    ClientSubscription.platform_id == platform.id,
                )
            )).scalars().first()
            if sub is None:
                sub = (await session.execute(
                    select(ClientSubscription).where(
                        ClientSubscription.counterparty_id == contract.counterparty_id,
                        ClientSubscription.platform_id == platform.id,
                    )
                )).scalars().first()
        else:
            sub = (await session.execute(
                select(ClientSubscription).where(
                    ClientSubscription.counterparty_id == contract.counterparty_id,
                    ClientSubscription.platform_id == platform.id,
                )
            )).scalars().first()

    smap = await lifecycle_stage_by_code(session)
    b0 = smap.get("B0")

    created_new_sub = False
    if sub is None:
        sub = ClientSubscription(
            counterparty_id=contract.counterparty_id,
            # CONTACTS 2.0 Ф3-A: дублируем company_id (реестр переведут отдельно).
            company_id=contract.company_id,
            platform_id=platform.id,
            region_id=region.id if region else None,
            lifecycle_stage_id=b0.id if b0 else None,
            stage_changed_at=datetime.now(UTC) if b0 else None,
            act_signed_date=signed_date,
            impl_start_date=signed_date,
            fee_contract=fee,
            fee_actual=fee,
            fee_currency=contract.currency,
        )
        session.add(sub)
        created_new_sub = True
    else:
        # дозаполняем пустые поля, ручной ввод не трогаем
        if sub.company_id is None and contract.company_id is not None:
            sub.company_id = contract.company_id
        if sub.act_signed_date is None:
            sub.act_signed_date = signed_date
        if sub.impl_start_date is None:
            sub.impl_start_date = signed_date
        if sub.region_id is None and region:
            sub.region_id = region.id
        if sub.fee_contract is None:
            sub.fee_contract = fee
        if sub.fee_actual is None:
            sub.fee_actual = fee
        if sub.fee_currency is None:
            sub.fee_currency = contract.currency
        if sub.lifecycle_stage_id is None and b0:
            sub.lifecycle_stage_id = b0.id
            sub.stage_changed_at = datetime.now(UTC)
    await session.flush()  # нужен sub.id для привязки модулей
    await _enable_modules_from_contract(session, sub, contract)
    # Эпик 11.2: outbound webhook subscription.created (только если реально новая).
    # Существующие подписки (дозаполнение полей при повторном sign договора) —
    # не повторяем событие, чтобы подписчики не ошибочно обрабатывали "повторное"
    # рождение клиента в реестре.
    if created_new_sub:
        from app.services.webhook_dispatcher import (
            safe_dispatch_event,
            subscription_to_payload,
        )
        await safe_dispatch_event(
            session, "subscription.created", "subscription", sub.id,
            subscription_to_payload(sub),
        )
    return sub


# ============ Фоновый пересчёт health (#3) + daily KPI-снапшот (#2) ============

# Размер батча массового пересчёта: коммитим раз в N подписок, чтобы не держать
# одну гигантскую транзакцию (128+ подписок в проде, будет расти). Каждый коммит
# освобождает локи/WAL и не блокирует ручные правки реестра надолго.
_HEALTH_RECOMPUTE_BATCH = 50


async def recompute_all_health(
    session: AsyncSession, batch_size: int = _HEALTH_RECOMPUTE_BATCH
) -> int:
    """Фоновый пересчёт health/attention по ВСЕМ активным подпискам (#3).

    Без этого джоба health_tier/health_reasons обновляются только при ручном
    вводе активности → CS работает по устаревшим флагам. Коммитим батчами.

    Webhook health_changed НЕ шлём (dispatch_webhook=False): массовый проход
    иначе зашумит подписчиков сотнями событий. Реальные смены тира ловит
    ручной/импортный путь (там dispatch_webhook=True по умолчанию).

    Возвращает число обработанных подписок.
    """
    thresholds = await get_health_thresholds(session)
    stage_map = await lifecycle_stage_by_code(session)
    today = datetime.now(UTC).date()
    ids = [
        sid for (sid,) in (
            await session.execute(
                select(ClientSubscription.id).where(ClientSubscription.is_active.is_(True))
            )
        ).all()
    ]
    processed = 0
    for i in range(0, len(ids), batch_size):
        chunk = ids[i : i + batch_size]
        subs = (
            await session.execute(
                select(ClientSubscription).where(ClientSubscription.id.in_(chunk))
            )
        ).scalars().all()
        for sub in subs:
            try:
                await recompute_subscription_health(
                    session, sub,
                    thresholds=thresholds, stage_map=stage_map, today=today,
                    dispatch_webhook=False,
                )
                processed += 1
            except Exception:  # noqa: BLE001
                # Одна сломанная подписка не валит весь проход.
                logger.exception("recompute_all_health: subscription %s failed", sub.id)
        await session.commit()
    return processed


def build_kpi_buckets(
    rows: list[tuple[int | None, int | None, str | None]],
) -> dict[tuple[int | None, int | None], dict]:
    """Pure: (platform_id, region_id, stage_code) подписок → {срез: compute_kpis}.

    Срезы: общий (None,None), per-platform (pid,None), per-(platform,region).
    Детерминирован по входу → один и тот же снимок при повторном проходе
    (фундамент идемпотентности daily-снапшота: ON CONFLICT DO UPDATE кладёт те же
    metrics). Тестируется без БД.
    """
    codes_by_key: dict[tuple[int | None, int | None], list[str | None]] = {}
    for platform_id, region_id, code in rows:
        for key in (
            (None, None),
            (platform_id, None),
            (platform_id, region_id),
        ):
            codes_by_key.setdefault(key, []).append(code)
    return {key: compute_kpis(codes) for key, codes in codes_by_key.items()}


async def _kpi_already_written_today(session: AsyncSession, snapshot_date: date) -> bool:
    """Есть ли уже снимок KPI за дату (idempotency-guard для daily-частоты)."""
    row = (
        await session.execute(
            select(RegistryKpiSnapshot.id)
            .where(RegistryKpiSnapshot.snapshot_date == snapshot_date)
            .limit(1)
        )
    ).first()
    return row is not None


async def snapshot_registry_kpis(
    session: AsyncSession, snapshot_date: date | None = None, force: bool = False
) -> int:
    """Daily upsert RegistryKpiSnapshot: срез compute_kpis по (платформа, регион)
    + общий срез (platform_id=NULL, region_id=NULL) на дату (#2).

    Без этого writer'а модель RegistryKpiSnapshot пустая → тренды реестра по
    времени недоступны на дашборде.

    Идемпотентно: ON CONFLICT (snapshot_date, platform_id, region_id) DO UPDATE.
    Если за сегодня уже писали и не force — no-op (раз в сутки). Возвращает число
    upsert'нутых строк (0, если skip).
    """
    snapshot_date = snapshot_date or datetime.now(UTC).date()
    if not force and await _kpi_already_written_today(session, snapshot_date):
        return 0

    # Тянем (platform_id, region_id, lifecycle code) по всем активным подпискам
    # одним проходом, агрегируем в Python (датасет реестра небольшой, 100–1000 строк).
    id_to_code = {s.id: s.code for s in (await session.execute(select(PipelineStage))).scalars().all() if s.code}
    raw = (
        await session.execute(
            select(
                ClientSubscription.platform_id,
                ClientSubscription.region_id,
                ClientSubscription.lifecycle_stage_id,
            ).where(ClientSubscription.is_active.is_(True))
        )
    ).all()
    rows = [
        (platform_id, region_id, id_to_code.get(stage_id) if stage_id else None)
        for platform_id, region_id, stage_id in raw
    ]
    buckets = build_kpi_buckets(rows)

    upserted = 0
    for (platform_id, region_id), metrics in buckets.items():
        stmt = pg_insert(RegistryKpiSnapshot).values(
            snapshot_date=snapshot_date,
            platform_id=platform_id,
            region_id=region_id,
            metrics=metrics,
        )
        stmt = stmt.on_conflict_do_update(
            constraint="uq_kpi_snapshot",
            set_={"metrics": metrics},
        )
        await session.execute(stmt)
        upserted += 1
    await session.commit()
    return upserted
