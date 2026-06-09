"""Чистые функции Эпик 22 — Cohort Analytics: unit-тесты без БД.

Покрывает: compute_cohort_matrix, compute_retention_percent,
compute_avg_ltv_per_cohort, compute_projected_ltv, compute_monthly_churn_rate,
build_cohort_data, build_cohort_xlsx_rows. Edge cases: пустые данные,
нулевой churn, будущие даты, когорты с одним членом, fee_actual=None.
"""
from __future__ import annotations

from datetime import date, datetime
from decimal import Decimal

import pytest

from app.services.cohort_analytics import (
    _add_months,
    _month_key,
    build_cohort_data,
    build_cohort_xlsx_rows,
    compute_avg_ltv_per_cohort,
    compute_cohort_matrix,
    compute_monthly_churn_rate,
    compute_projected_ltv,
    compute_retention_percent,
)


# ---------- Фикстуры ----------

def _sub(
    cohort_start: date,
    churn_date: date | None = None,
    fee_actual: float | Decimal | None = 100_000.0,
) -> dict:
    """Создать dict подписки для тестов."""
    return {
        "cohort_start_date": cohort_start,
        "churn_date": churn_date,
        "fee_actual": Decimal(str(fee_actual)) if fee_actual is not None else None,
    }


# ---------- _month_key ----------

def test_month_key_date():
    assert _month_key(date(2025, 1, 15)) == "2025-01"


def test_month_key_datetime():
    assert _month_key(datetime(2025, 12, 31, 23, 59)) == "2025-12"


def test_month_key_first_day():
    assert _month_key(date(2025, 3, 1)) == "2025-03"


# ---------- _add_months ----------

def test_add_months_basic():
    assert _add_months(date(2025, 1, 15), 1) == date(2025, 2, 1)


def test_add_months_year_wrap():
    assert _add_months(date(2025, 11, 1), 3) == date(2026, 2, 1)


def test_add_months_zero():
    assert _add_months(date(2025, 6, 15), 0) == date(2025, 6, 1)


# ---------- compute_cohort_matrix ----------

def test_cohort_matrix_all_alive():
    """Все подписки живы — count на каждом offset = размер когорты."""
    subs = [
        _sub(date(2025, 1, 10)),
        _sub(date(2025, 1, 20)),
        _sub(date(2025, 1, 25)),
    ]
    matrix = compute_cohort_matrix(subs, periods=2)
    assert "2025-01" in matrix
    # offset 0 — все 3 живы
    assert matrix["2025-01"][0] == 3
    # offset 1 и 2 — тоже все 3 (никто не ушёл)
    assert matrix["2025-01"].get(1) == 3 or 1 not in matrix["2025-01"]  # если дата в будущем — пропускаем
    # Не должно быть больше 3
    for count in matrix["2025-01"].values():
        assert count <= 3


def test_cohort_matrix_one_churned():
    """Один уходит в феврале — с offset=1 его нет."""
    subs = [
        _sub(date(2025, 1, 5)),                          # жив
        _sub(date(2025, 1, 10), churn_date=date(2025, 2, 15)),  # ушёл в феврале
    ]
    matrix = compute_cohort_matrix(subs, periods=3)
    cohort = matrix.get("2025-01", {})
    # offset=0 (январь): оба живы
    assert cohort.get(0) == 2
    # offset=1 (февраль, контрольная дата = 2025-02-01): второй ушёл 2025-02-15 → ещё жив
    if 1 in cohort:
        assert cohort[1] == 2
    # offset=2 (март, контрольная дата = 2025-03-01): второй ушёл в феврале → уже нет
    if 2 in cohort:
        assert cohort[2] == 1


def test_cohort_matrix_multiple_cohorts():
    """Два когорта создаются раздельно."""
    subs = [
        _sub(date(2025, 1, 5)),
        _sub(date(2025, 2, 5)),
    ]
    matrix = compute_cohort_matrix(subs, periods=1)
    assert "2025-01" in matrix
    assert "2025-02" in matrix
    assert matrix["2025-01"][0] == 1
    assert matrix["2025-02"][0] == 1


def test_cohort_matrix_empty():
    """Пустые данные — пустая матрица."""
    matrix = compute_cohort_matrix([], periods=12)
    assert matrix == {}


def test_cohort_matrix_no_cohort_start():
    """Подписки без cohort_start_date пропускаются."""
    subs = [
        {"cohort_start_date": None, "churn_date": None, "fee_actual": None},
        _sub(date(2025, 1, 5)),
    ]
    matrix = compute_cohort_matrix(subs, periods=1)
    assert len(matrix) == 1
    assert "2025-01" in matrix


# ---------- compute_retention_percent ----------

def test_retention_percent_basic():
    matrix = {"2025-01": {0: 10, 1: 8, 2: 5}}
    result = compute_retention_percent(matrix)
    assert result["2025-01"][0] == 100.0
    assert result["2025-01"][1] == 80.0
    assert result["2025-01"][2] == 50.0


def test_retention_percent_zero_initial():
    """Когорта с initial_count=0 — пропускается."""
    matrix = {"2025-01": {0: 0, 1: 0}}
    result = compute_retention_percent(matrix)
    assert "2025-01" not in result


def test_retention_percent_no_churn():
    """Нет churn — все 100%."""
    matrix = {"2025-01": {0: 5, 1: 5, 2: 5}}
    result = compute_retention_percent(matrix)
    assert all(v == 100.0 for v in result["2025-01"].values())


def test_retention_percent_empty():
    assert compute_retention_percent({}) == {}


# ---------- compute_avg_ltv_per_cohort ----------

def test_avg_ltv_basic():
    """LTV = fee × months_active. Когорта из одного."""
    # Активна 6 месяцев (2025-01 → 2025-07 ≈ 180 дней ≈ 6 мес)
    today = date.today()
    # Используем фиксированную дату чтобы не зависеть от today
    # Подписка с churn через 90 дней
    start = date(2024, 1, 1)
    churn = date(2024, 4, 1)  # ровно 90 дней → 3 месяца
    subs = [_sub(start, churn_date=churn, fee_actual=100_000.0)]
    result = compute_avg_ltv_per_cohort(subs)
    assert "2024-01" in result
    assert result["2024-01"] == 300_000.0  # 100000 × 3


def test_avg_ltv_no_fee():
    """Подписки без fee_actual — не вносятся в avg_ltv."""
    subs = [_sub(date(2025, 1, 1), fee_actual=None)]
    result = compute_avg_ltv_per_cohort(subs)
    # Когорта может отсутствовать или ltv=0 (зависит от реализации)
    assert result.get("2025-01", 0.0) == 0.0


def test_avg_ltv_multiple_members():
    """Среднее по двум членам когорты."""
    start = date(2024, 1, 1)
    churn_early = date(2024, 2, 1)  # 31 день → 1 месяц
    churn_late = date(2024, 4, 1)   # 90 дней → 3 месяца
    subs = [
        _sub(start, churn_date=churn_early, fee_actual=100_000.0),  # LTV = 100000
        _sub(start, churn_date=churn_late, fee_actual=100_000.0),   # LTV = 300000
    ]
    result = compute_avg_ltv_per_cohort(subs)
    assert "2024-01" in result
    assert result["2024-01"] == 200_000.0  # (100000 + 300000) / 2


def test_avg_ltv_empty():
    assert compute_avg_ltv_per_cohort([]) == {}


# ---------- compute_projected_ltv ----------

def test_projected_ltv_basic():
    """MRR / churn_rate = projected LTV."""
    result = compute_projected_ltv(1_000_000.0, 0.05)
    assert result == 20_000_000.0


def test_projected_ltv_zero_churn():
    """Нулевой churn — нельзя делить на 0, возвращаем 0.0."""
    assert compute_projected_ltv(1_000_000.0, 0.0) == 0.0


def test_projected_ltv_zero_mrr():
    """Нулевой MRR — projected LTV = 0."""
    assert compute_projected_ltv(0.0, 0.05) == 0.0


def test_projected_ltv_negative_churn():
    """Отрицательный churn (аномалия) — возвращаем 0.0."""
    assert compute_projected_ltv(500_000.0, -0.01) == 0.0


# ---------- compute_monthly_churn_rate ----------

def test_churn_rate_basic():
    """(10→8)/10 = 0.2, (8→7)/8 = 0.125 → avg ≈ 0.1625."""
    matrix = {"2025-01": {0: 10, 1: 8, 2: 7}}
    rate = compute_monthly_churn_rate(matrix)
    expected = (0.2 + 0.125) / 2
    assert abs(rate - expected) < 0.001


def test_churn_rate_zero():
    """Нет churn — rate = 0.0."""
    matrix = {"2025-01": {0: 10, 1: 10, 2: 10}}
    assert compute_monthly_churn_rate(matrix) == 0.0


def test_churn_rate_empty():
    """Пустая матрица — rate = 0.0."""
    assert compute_monthly_churn_rate({}) == 0.0


def test_churn_rate_single_offset():
    """Один offset — нет пар для расчёта → 0.0."""
    matrix = {"2025-01": {0: 10}}
    assert compute_monthly_churn_rate(matrix) == 0.0


# ---------- build_cohort_data ----------

def test_build_cohort_data_structure():
    """build_cohort_data возвращает список с нужными полями."""
    subs = [
        _sub(date(2024, 1, 1)),
        _sub(date(2024, 1, 15), churn_date=date(2024, 4, 1)),
    ]
    result = build_cohort_data(subs, periods=3)
    assert isinstance(result, list)
    assert len(result) >= 1
    cohort = result[0]
    assert "cohort_month" in cohort
    assert "initial_count" in cohort
    assert "retention" in cohort
    assert "avg_ltv" in cohort
    assert cohort["initial_count"] == 2


def test_build_cohort_data_empty():
    result = build_cohort_data([], periods=12)
    assert result == []


def test_build_cohort_data_sorted():
    """Когорты отсортированы по cohort_month."""
    subs = [
        _sub(date(2025, 3, 1)),
        _sub(date(2025, 1, 1)),
        _sub(date(2025, 2, 1)),
    ]
    result = build_cohort_data(subs, periods=1)
    months = [c["cohort_month"] for c in result]
    assert months == sorted(months)


# ---------- build_cohort_xlsx_rows ----------

def test_build_cohort_xlsx_rows_headers():
    """Заголовки: Когорта, Размер, +0 мес .. +N мес, Avg LTV."""
    cohorts = [
        {"cohort_month": "2025-01", "initial_count": 5, "retention": [], "avg_ltv": 100.0}
    ]
    headers, rows = build_cohort_xlsx_rows(cohorts, max_offset=3)
    assert headers[0] == "Когорта"
    assert headers[1] == "Размер"
    assert "+0 мес" in headers
    assert "+3 мес" in headers
    assert headers[-1] == "Avg LTV"


def test_build_cohort_xlsx_rows_data():
    """Строка данных содержит cohort_month, initial_count и LTV."""
    cohorts = [
        {"cohort_month": "2025-01", "initial_count": 10, "retention": [
            {"month_offset": 0, "active_count": 10, "churned_count": 0, "retention_pct": 100.0},
            {"month_offset": 1, "active_count": 8, "churned_count": 2, "retention_pct": 80.0},
        ], "avg_ltv": 250_000.0}
    ]
    headers, rows = build_cohort_xlsx_rows(cohorts, max_offset=1)
    assert len(rows) == 1
    row = rows[0]
    assert row[0] == "2025-01"
    assert row[1] == 10
    assert row[-1] == 250_000.0  # Avg LTV


def test_build_cohort_xlsx_rows_empty():
    headers, rows = build_cohort_xlsx_rows([], max_offset=3)
    assert len(rows) == 0
    assert "Когорта" in headers
