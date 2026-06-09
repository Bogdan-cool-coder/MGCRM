"""Чистые хелперы аналитики: avg_days + build_xlsx + resolve_client_name +
sparkline/trend (без БД)."""

from datetime import UTC, date, datetime, timedelta

from collections import Counter

from app.services.analytics import (
    avg_days,
    build_kpi_trends,
    build_kpi_trends_from_aggregates,
    build_xlsx,
    compute_trend_pct,
    compute_weekly_counts,
    resolve_client_name,
    weekly_buckets_from_counts,
)


def test_avg_days():
    n = datetime(2026, 1, 11, tzinfo=UTC)
    assert avg_days([(n - timedelta(days=2), n), (n - timedelta(days=4), n)]) == 3.0
    assert avg_days([]) == 0.0
    assert avg_days([(None, n), (n, None)]) == 0.0  # неполные пары пропускаются


def test_build_xlsx_is_valid_zip():
    data = build_xlsx("Тест", ["A", "B"], [[1, 2], [3, None], ["x", "y"]])
    assert data[:2] == b"PK"  # .xlsx — это zip-контейнер
    assert len(data) > 200


# ============ CONTACTS 2.0 Ф4: resolve_client_name ============


def test_resolve_client_name_company_first():
    """Company-first: если company_id задан и есть в company_map — возвращаем его."""
    assert resolve_client_name(1, {1: "ПТС Казахстан"}, 5, {5: "PTC KZ old"}) == "ПТС Казахстан"


def test_resolve_client_name_fallback_to_counterparty():
    """Если company_id=None — fallback на cp_map."""
    assert resolve_client_name(None, {}, 5, {5: "PTC KZ"}) == "PTC KZ"


def test_resolve_client_name_both_none():
    """Нет ни company_id, ни counterparty_id — пустая строка."""
    assert resolve_client_name(None, {}, None, {}) == ""


def test_resolve_client_name_company_id_not_in_map():
    """company_id задан, но не загружен в map (не должно происходить, но safe) — пустая строка."""
    assert resolve_client_name(99, {1: "Другой"}, 5, {5: "Fallback"}) == ""


def test_resolve_client_name_counterparty_id_not_in_map():
    """company_id=None, counterparty_id задан, но не в cp_map — пустая строка."""
    assert resolve_client_name(None, {}, 7, {5: "Other"}) == ""


def test_resolve_client_name_company_id_zero_not_falsy():
    """company_id=0 — не None, значит используем company_map (даже если 0 = 0 в карте)."""
    # В реальности id=0 не бывает в PG, но проверяем логику is not None (не falsy-check).
    assert resolve_client_name(0, {0: "Edge"}, 5, {5: "Fallback"}) == "Edge"


# ============ Design v2: Sparkline + trend_pct ============


def test_compute_trend_pct_positive():
    """Рост на 10% от 100 до 110."""
    assert compute_trend_pct(110.0, 100.0) == 10.0


def test_compute_trend_pct_negative():
    """Снижение на 10% от 100 до 90."""
    assert compute_trend_pct(90.0, 100.0) == -10.0


def test_compute_trend_pct_zero_base():
    """Если предыдущее значение 0 — нет базы для сравнения, возвращаем None."""
    assert compute_trend_pct(5.0, 0.0) is None


def test_compute_trend_pct_zero_both():
    """Оба значения 0 — тоже None (нет базы)."""
    assert compute_trend_pct(0.0, 0.0) is None


def test_compute_trend_pct_rounding():
    """Результат округляется до 1 знака после запятой."""
    assert compute_trend_pct(133.0, 120.0) == 10.8


def test_compute_weekly_counts_basic():
    """Один контракт сегодня — попадает в последнюю (самую правую) неделю."""
    today = date(2026, 6, 4)
    counts = compute_weekly_counts([today], weeks=8, ref_date=today)
    assert len(counts) == 8
    assert counts[-1] == 1
    assert sum(counts[:-1]) == 0


def test_compute_weekly_counts_fills_gaps():
    """Недели без событий заполняются нулями (sparkline не рваный)."""
    today = date(2026, 6, 4)
    # Контракт ровно 5 недель назад (попадает в 3-ю неделю от начала окна = индекс 2)
    five_weeks_ago = today - timedelta(weeks=5)
    counts = compute_weekly_counts([five_weeks_ago], weeks=8, ref_date=today)
    assert len(counts) == 8
    # Индекс: от конца weeks-1-5 = 2, то есть counts[2] == 1
    assert counts[2] == 1
    # Остальные — 0
    assert counts.count(0) == 7


def test_compute_weekly_counts_outside_window_ignored():
    """Даты за пределами окна не учитываются."""
    today = date(2026, 6, 4)
    too_old = today - timedelta(weeks=10)
    counts = compute_weekly_counts([too_old], weeks=8, ref_date=today)
    assert sum(counts) == 0


def test_compute_weekly_counts_multiple():
    """Несколько событий в одной неделе суммируются."""
    today = date(2026, 6, 4)
    dates = [today, today - timedelta(days=1), today - timedelta(days=2)]
    counts = compute_weekly_counts(dates, weeks=8, ref_date=today)
    assert counts[-1] == 3


def test_build_kpi_trends_empty():
    """Пустые данные: sparkline из нулей, trend_pct=None."""
    ref = datetime(2026, 6, 4, 12, 0, tzinfo=UTC)
    result = build_kpi_trends(
        contract_dates=[],
        cycle_pairs=[],
        ttapprove_pairs=[],
        ref_datetime=ref,
    )
    assert result["total_sparkline"] == [0] * 8
    assert result["total_trend_pct"] is None
    assert result["avg_cycle_trend_pct"] is None
    assert result["avg_time_to_approve_trend_pct"] is None


def test_build_kpi_trends_positive_total_trend():
    """Больше договоров в последние 4 недели → положительный trend."""
    ref = datetime(2026, 6, 4, 12, 0, tzinfo=UTC)
    today = ref.date()
    # 2 договора в последних 4 неделях, 1 в предыдущих 4
    recent = [today - timedelta(weeks=1), today - timedelta(weeks=2)]
    older = [today - timedelta(weeks=5)]
    result = build_kpi_trends(
        contract_dates=recent + older,
        cycle_pairs=[],
        ttapprove_pairs=[],
        ref_datetime=ref,
    )
    # last 4 weeks = 2, previous 4 weeks = 1 → trend = +100%
    assert result["total_trend_pct"] == 100.0


def test_build_kpi_trends_negative_total_trend():
    """Меньше договоров в последние 4 недели → отрицательный trend."""
    ref = datetime(2026, 6, 4, 12, 0, tzinfo=UTC)
    today = ref.date()
    recent = [today - timedelta(weeks=1)]
    older = [today - timedelta(weeks=5), today - timedelta(weeks=6)]
    result = build_kpi_trends(
        contract_dates=recent + older,
        cycle_pairs=[],
        ttapprove_pairs=[],
        ref_datetime=ref,
    )
    # last 4w = 1, prev 4w = 2 → -50%
    assert result["total_trend_pct"] == -50.0


def test_build_kpi_trends_cycle_trend():
    """avg_cycle_trend_pct рассчитывается по cycle_pairs (signed_at)."""
    ref = datetime(2026, 6, 4, 12, 0, tzinfo=UTC)
    # Текущий период (last 30d): цикл 10 дней
    recent_start = ref - timedelta(days=20)
    recent_end = recent_start + timedelta(days=10)
    # Предыдущий период (-60d до -30d): цикл 20 дней
    prev_start = ref - timedelta(days=50)
    prev_end = prev_start + timedelta(days=20)
    result = build_kpi_trends(
        contract_dates=[],
        cycle_pairs=[(recent_start, recent_end), (prev_start, prev_end)],
        ttapprove_pairs=[],
        ref_datetime=ref,
    )
    # 10 дней vs 20 дней → -50% (цикл стал короче)
    assert result["avg_cycle_trend_pct"] == -50.0


def test_build_kpi_trends_sparkline_length():
    """total_sparkline всегда содержит ровно 8 точек (или sparkline_weeks)."""
    ref = datetime(2026, 6, 4, 12, 0, tzinfo=UTC)
    result = build_kpi_trends([], [], [], ref_datetime=ref, sparkline_weeks=12)
    assert len(result["total_sparkline"]) == 12


# ============ P2 (audit B4): SQL-fed helpers — равенство со старым in-memory ============


def test_weekly_buckets_equals_compute_weekly_counts():
    """weekly_buckets_from_counts (SQL GROUP BY дата) == compute_weekly_counts (сырые даты).

    Доказываем, что свёртка дневных счётчиков из SQL даёт тот же sparkline, что
    старый проход по полному списку дат.
    """
    today = date(2026, 6, 4)
    raw_dates = [
        today,
        today,
        today - timedelta(days=1),
        today - timedelta(weeks=5),
        today - timedelta(weeks=5),
        today - timedelta(weeks=10),  # вне окна — игнор
    ]
    old = compute_weekly_counts(raw_dates, weeks=8, ref_date=today)
    # Эмуляция SQL GROUP BY created_at::date → [(date, count)]
    day_counts = sorted(Counter(raw_dates).items())
    new = weekly_buckets_from_counts(day_counts, weeks=8, ref_date=today)
    assert new == old


def test_weekly_buckets_empty():
    assert weekly_buckets_from_counts([], weeks=8, ref_date=date(2026, 6, 4)) == [0] * 8


def test_weekly_buckets_skips_outside_window():
    today = date(2026, 6, 4)
    rows = [(today - timedelta(weeks=20), 99)]
    assert weekly_buckets_from_counts(rows, weeks=8, ref_date=today) == [0] * 8


def test_build_kpi_trends_from_aggregates_matches_full():
    """build_kpi_trends_from_aggregates даёт тот же результат, что build_kpi_trends.

    Готовим один и тот же набор «сырых» данных, прогоняем старый путь, затем
    извлекаем агрегаты руками и прогоняем SQL-fed путь — поля должны совпасть.
    """
    ref = datetime(2026, 6, 4, 12, 0, tzinfo=UTC)
    today = ref.date()
    contract_dates = [
        today - timedelta(weeks=1),
        today - timedelta(weeks=2),
        today - timedelta(weeks=5),
    ]
    # cycle: 10д (последние 30д), 20д (предыдущие 30д)
    rs = ref - timedelta(days=20)
    ps = ref - timedelta(days=50)
    cycle_pairs = [(rs, rs + timedelta(days=10)), (ps, ps + timedelta(days=20))]
    # tta: 3д (последние 30д), 9д (предыдущие 30д)
    ta = ref - timedelta(days=15)
    tp = ref - timedelta(days=45)
    ttapprove_pairs = [(ta, ta + timedelta(days=3)), (tp, tp + timedelta(days=9))]

    old = build_kpi_trends(
        contract_dates=contract_dates,
        cycle_pairs=cycle_pairs,
        ttapprove_pairs=ttapprove_pairs,
        ref_datetime=ref,
    )

    # «SQL-эмуляция» агрегатов, которые в проде считает GROUP BY / avg-окна.
    weekly = compute_weekly_counts(contract_dates, weeks=8, ref_date=today)
    new = build_kpi_trends_from_aggregates(
        weekly_counts=weekly,
        cycle_current=10.0, cycle_prev=20.0,
        tta_current=3.0, tta_prev=9.0,
    )
    assert new == old


def test_build_kpi_trends_from_aggregates_empty():
    new = build_kpi_trends_from_aggregates(
        weekly_counts=[0] * 8,
        cycle_current=0.0, cycle_prev=0.0,
        tta_current=0.0, tta_prev=0.0,
    )
    assert new["total_sparkline"] == [0] * 8
    assert new["total_trend_pct"] is None
    assert new["avg_cycle_trend_pct"] is None
    assert new["avg_time_to_approve_trend_pct"] is None


def test_status_group_rollup_from_counts():
    """by_status_group свёртка из per-status счётчиков == прямой проход по статусам.

    Зеркалит post-step в /analytics/contracts: GROUP BY status → свёртка в 4
    первичные группы (фиксированный размер, не per-row).
    """
    from app.models import ContractStatus
    from app.services.contract_status import (
        group_codes_in_order,
        primary_group,
    )

    # «SQL GROUP BY status» результат
    status_counts = [
        (ContractStatus.draft, 3),
        (ContractStatus.submitted, 2),
        (ContractStatus.in_review, 1),
        (ContractStatus.needs_rework, 1),
        (ContractStatus.signed, 4),
        (ContractStatus.archived, 5),
    ]
    rollup: Counter = Counter()
    for code in group_codes_in_order():
        rollup[code] = 0
    for st, cnt in status_counts:
        rollup[primary_group(st)["code"]] += cnt

    # Эквивалент: проход по «развёрнутым» строкам (старый in-memory путь)
    expanded = []
    for st, cnt in status_counts:
        expanded.extend([st] * cnt)
    old_rollup: Counter = Counter()
    for code in group_codes_in_order():
        old_rollup[code] = 0
    for st in expanded:
        old_rollup[primary_group(st)["code"]] += 1

    assert dict(rollup) == dict(old_rollup)
    # На всякий — стабильный набор ключей (все 4 группы присутствуют)
    assert set(rollup.keys()) == set(group_codes_in_order())


# ============ Scope security: fail-CLOSED whitelist ============


def test_scope_elevated_roles_whitelist():
    """_SCOPE_ELEVATED_ROLES содержит ровно admin/director/lawyer — никакой другой роли.

    Этот тест фиксирует явный whitelist и сломается при случайном расширении
    (например добавлении accountant/cfo в elevated), что потребует явного решения.
    """
    from app.deps import _SCOPE_ELEVATED_ROLES
    from app.models import UserRole

    assert UserRole.admin in _SCOPE_ELEVATED_ROLES
    assert UserRole.director in _SCOPE_ELEVATED_ROLES
    assert UserRole.lawyer in _SCOPE_ELEVATED_ROLES

    # Fail-closed: следующие роли НЕ должны быть elevated по умолчанию.
    assert UserRole.manager not in _SCOPE_ELEVATED_ROLES
    assert UserRole.accountant not in _SCOPE_ELEVATED_ROLES


def test_scope_to_user_restricts_non_elevated(tmp_path):
    """scope_to_user добавляет WHERE для не-elevated ролей; elevated — без фильтра."""
    from unittest.mock import MagicMock
    from app.deps import scope_to_user, _SCOPE_ELEVATED_ROLES
    from app.models import Contract, UserRole

    def _make_user(role: UserRole, uid: int = 42) -> MagicMock:
        u = MagicMock()
        u.role = role
        u.id = uid
        return u

    from sqlalchemy.future import select

    # Elevated: stmt не изменяется (видят всё)
    for elevated_role in _SCOPE_ELEVATED_ROLES:
        user = _make_user(elevated_role)
        stmt = select(Contract)
        result = scope_to_user(stmt, Contract, user, "author_user_id")
        # Для elevated scope_to_user возвращает тот же stmt без WHERE
        assert result is stmt, f"role {elevated_role} should not be filtered"

    # Non-elevated: stmt получает WHERE clause
    non_elevated_role = UserRole.manager  # representative
    user = _make_user(non_elevated_role, uid=99)
    stmt = select(Contract)
    result = scope_to_user(stmt, Contract, user, "author_user_id")
    # Не тот же объект — scope_to_user добавил WHERE
    assert result is not stmt, "non-elevated role must receive a scoped WHERE clause"
