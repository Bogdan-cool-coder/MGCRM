"""Чистые механики реестра CS: % чек-листа, тренд/тиры активности, KPI (без БД)."""

from datetime import date

from app.services.customer_success import (
    activity_trend,
    attention_flags,
    build_kpi_buckets,
    classify_tier,
    compute_checklist_pct,
    compute_health,
    compute_kpis,
    health_score_from_tier,
    item_completion,
    trailing_zeros,
    window_avg,
)


# ---- чек-лист ----

def test_item_completion_priority_and_credit():
    assert item_completion("not_required", None, None, None) == (False, 0.0)
    assert item_completion("done", None, None, None) == (True, 1.0)
    assert item_completion("in_progress", None, None, None) == (True, 0.5)
    assert item_completion("waiting", None, None, None) == (True, 0.0)
    # дробь X/Y
    assert item_completion("in_progress", 3, 5, None) == (True, 0.6)
    # явный pct имеет приоритет и клампится
    assert item_completion("in_progress", None, None, 0.45) == (True, 0.45)
    assert item_completion("done", None, None, 1.5) == (True, 1.0)


def test_compute_checklist_pct_skips_not_applicable():
    items = [
        ("done", None, None, None),          # 1.0
        ("not_required", None, None, None),  # пропуск
        ("in_progress", None, None, None),   # 0.5
        ("waiting", None, None, None),       # 0.0
    ]
    assert compute_checklist_pct(items) == 50.0  # (1.0+0.5+0.0)/3


def test_compute_checklist_pct_empty_and_all_not_required():
    assert compute_checklist_pct([]) == 0.0
    assert compute_checklist_pct([("not_required", None, None, None)]) == 0.0


# ---- активность ----

def test_trailing_zeros():
    assert trailing_zeros([1, 2, 0, 0]) == 2
    assert trailing_zeros([0, 0, 0]) == 3
    assert trailing_zeros([5]) == 0
    assert trailing_zeros([]) == 0


def test_window_avg():
    assert window_avg([1, 2, 3, 4, 5], 4) == 3.5
    assert window_avg([], 4) == 0.0


def test_activity_trend():
    assert activity_trend([10, 10, 10, 10, 20, 20, 20, 20], 4) == 100.0
    assert activity_trend([0, 0, 0, 0], 4) == 0.0
    assert activity_trend([0, 0, 0, 0, 10, 10, 10, 10], 4) == 100.0  # рост с нуля
    assert activity_trend([5], 4) == 0.0


def test_classify_tier_defaults():
    assert classify_tier(0, 2) == "A6"      # спящий
    assert classify_tier(3, 0) == "A5"
    assert classify_tier(6, 0) == "A4"
    assert classify_tier(21, 0) == "A3"
    assert classify_tier(51, 0) == "A2"
    assert classify_tier(151, 0) == "A1"
    assert classify_tier(1000, 1) == "A1"   # 1 период простоя < порога дормантности


def test_compute_health_empty_and_dormant():
    assert compute_health([])["tier"] is None
    h = compute_health([0, 0])
    assert h["tier"] == "A6"
    assert h["dormant_periods"] == 2
    assert compute_health([100, 100, 100, 100, 100])["tier"] == "A2"


# ---- триггеры внимания ----

def test_attention_flags():
    today = date(2026, 5, 29)
    f = attention_flags(
        tier="A6", trend_pct=-50.0, dormant_periods=2,
        discount_until=None, last_fee_increase_at=None, today=today,
    )
    assert "dormant" in f and "activity_drop" in f and "upsell" in f and "low_health" in f
    assert "discount_expiring" not in f

    f2 = attention_flags(
        tier="A2", trend_pct=10.0, dormant_periods=0,
        discount_until=date(2026, 6, 10), last_fee_increase_at=date(2026, 1, 1), today=today,
    )
    assert "discount_expiring" in f2
    assert "upsell" not in f2 and "dormant" not in f2 and "low_health" not in f2


# ---- KPI ----

def test_compute_kpis():
    # CS-hotfix (0080): подписки без этапа (None) теперь учитываются в total и
    # operating (как «действующие, без классификации»), плюс корзина no_stage.
    codes = ["A1", "A1", "A2", "A3", "A6", "B0", "B5", "B6", "C0", None]
    k = compute_kpis(codes)
    assert k["total"] == 10          # 9 классифицированных + 1 no_stage
    assert k["no_stage"] == 1
    assert k["active"] == 3          # A1+A2
    assert k["support"] == 4         # A1..A5
    assert k["in_implementation"] == 2  # B0..B5
    assert k["closed"] == 1
    assert k["operating"] == 7       # total - (A6+B6+C0) = 10 - 3
    assert round(k["conversion_support"], 4) == 0.4   # 4/10
    assert round(k["conversion_closed"], 4) == 0.1    # 1/10


# ---- health_score: осмысленная 0..100 шкала по тиру (#11) ----

def test_health_score_none_for_no_tier():
    assert health_score_from_tier(None) is None
    assert health_score_from_tier(None, 100.0) is None


def test_health_score_strictly_monotonic_by_tier():
    # База тира монотонно убывает A1>A2>...>A6>C0 — score реально дискриминирует,
    # а не схлопывается в плоские 100 (была проблема #11).
    bases = [health_score_from_tier(t) for t in ("A1", "A2", "A3", "A4", "A5", "A6", "C0")]
    assert bases == sorted(bases, reverse=True)
    assert len(set(bases)) == len(bases)            # все различны
    assert bases[0] == 100.0 and bases[-1] == 0.0   # крайние точки шкалы


def test_health_score_does_not_saturate_at_100():
    # Раньше любой active с avg ≥ верхней полосы → ровно 100. Теперь только A1
    # достигает 100; A2 (умеренно активный) заметно ниже.
    a1 = health_score_from_tier("A1", 500.0)
    a2 = health_score_from_tier("A2", 100.0)
    assert a1 == 100.0
    assert a2 < 90.0
    assert a1 > a2


def test_health_score_interpolates_within_band():
    # Внутри полосы A2 (bands [5,20,50,150] → A2 коридор avg в (50,150]) score
    # растёт с avg: нижняя граница полосы < середина < верхняя. Не ступенька.
    low = health_score_from_tier("A2", 55.0)
    mid = health_score_from_tier("A2", 100.0)
    high = health_score_from_tier("A2", 145.0)
    assert low < mid < high
    # но всё ещё внутри коридора тира A2 (±SPAN вокруг базы 80)
    assert 70.0 <= low <= 90.0 and 70.0 <= high <= 90.0


def test_health_score_clamped_0_100():
    for t in ("A1", "A2", "A3", "A4", "A5", "A6", "C0"):
        for avg in (0.0, 5.0, 1000.0):
            s = health_score_from_tier(t, avg)
            assert s is not None and 0.0 <= s <= 100.0


# ---- daily KPI-снапшот: детерминированное бакетирование (идемпотентность) ----

def test_build_kpi_buckets_slices():
    # 2 платформы × регионы. Срезы: общий (None,None), per-platform, per-(pl,rg).
    rows = [
        (1, 10, "A1"),
        (1, 10, "A2"),
        (1, 20, "A6"),
        (2, 10, "B0"),
    ]
    b = build_kpi_buckets(rows)
    # общий срез — все 4 подписки
    assert b[(None, None)]["total"] == 4
    # платформа 1 — 3 подписки
    assert b[(1, None)]["total"] == 3
    # платформа 1 + регион 10 — 2 подписки (A1+A2 → active=2)
    assert b[(1, 10)]["total"] == 2
    assert b[(1, 10)]["active"] == 2
    # платформа 2 + регион 10 — 1 подписка во внедрении
    assert b[(2, 10)]["in_implementation"] == 1


def test_build_kpi_buckets_deterministic():
    # Один и тот же вход → идентичные metrics (фундамент идемпотентного
    # ON CONFLICT DO UPDATE: повторный снапшот за день кладёт те же значения).
    rows = [(1, 10, "A1"), (1, None, None), (2, 30, "C0")]
    assert build_kpi_buckets(rows) == build_kpi_buckets(rows)


def test_build_kpi_buckets_handles_none_stage():
    rows = [(1, 10, None), (1, 10, "A1")]
    b = build_kpi_buckets(rows)
    assert b[(1, 10)]["no_stage"] == 1
    assert b[(1, 10)]["total"] == 2


def test_build_kpi_buckets_emits_null_bearing_keys():
    # C2: writer апсёртит каждый bucket по uq_kpi_snapshot(date, platform_id, region_id).
    # Среди ключей всегда есть NULL-несущие: общий (None,None) и per-platform (pid,None).
    # Именно поэтому constraint обязан быть NULLS NOT DISTINCT — иначе ON CONFLICT не
    # ловит эти строки и force-прогон их задваивает (миграция 0107).
    rows = [(1, 10, "A1"), (2, 20, "B0")]
    keys = set(build_kpi_buckets(rows).keys())
    assert (None, None) in keys          # общий срез — platform_id=NULL, region_id=NULL
    assert (1, None) in keys             # per-platform — region_id=NULL
    assert (2, None) in keys
    # ровно один общий срез (не должен множиться при повторной сборке)
    again = set(build_kpi_buckets(rows).keys())
    assert keys == again
