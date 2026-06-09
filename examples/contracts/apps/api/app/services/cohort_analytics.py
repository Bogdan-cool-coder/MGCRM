"""Эпик 22 — Cohort Analytics: чистые функции для когортного retention + LTV.

Все функции pure (без БД): принимают списки dict или tuple, возвращают
Python-структуры. Реальные SQL-запросы — в routers/analytics.py.

Терминология:
- cohort_month: строка "YYYY-MM" — месяц первой активации подписки
  (cohort = подписки с одним cohort_month)
- month_offset: целое число 0, 1, 2, ..., N — месяцев с начала когорты
- survival: подписка «жива» если её stage_code != 'C0' на дату cohort_start + N мес.
- LTV = sum(fee_actual × months_active) / cohort_size, усреднённо по когорте
- Projected LTV = current_mrr / monthly_churn_rate (стандартная формула)

Ограничения MVP:
- Нет DealStageHistory аналогов для сделок — только subscription history.
- «Дата смерти» подписки = min(changed_at WHERE to_stage_code='C0') из history.
  Если history пуста (backfill не запускался) — используем current stage как
  приближение (is_active=True → жива, stage_code='C0' → мертва с now).
- Когорта определяется по cohort_start_date, которая передаётся снаружи
  (routers берёт impl_start_date или created_at — в зависимости от доступности).
"""
from __future__ import annotations

from datetime import date, datetime, timedelta
from decimal import Decimal
from typing import TypedDict


# ---------- Типы возврата ----------

class CohortRow(TypedDict):
    """Данные одного периода внутри когорты."""
    month_offset: int       # 0 = стартовый месяц
    active_count: int       # число «живых» подписок на этот offset
    churned_count: int      # ушло за этот период (от предыдущего offset)
    retention_pct: float    # active_count / initial_count * 100


class CohortData(TypedDict):
    """Полная когорта для одного cohort_month."""
    cohort_month: str           # "2025-01"
    initial_count: int          # размер когорты (active на offset=0)
    retention: list[CohortRow]  # по одному на каждый period 0..max_offset
    avg_ltv: float              # средний LTV в когорте (0.0 если нет данных)


# Матрица: cohort_month → {month_offset → active_count}
CohortMatrix = dict[str, dict[int, int]]

# Retention в процентах: cohort_month → {month_offset → pct}
RetentionMatrix = dict[str, dict[int, float]]


# ---------- Вспомогательные функции ----------

def _month_key(d: date | datetime) -> str:
    """Превратить дату в ключ когорты 'YYYY-MM'."""
    if isinstance(d, datetime):
        d = d.date()
    return d.strftime("%Y-%m")


def _add_months(d: date, months: int) -> date:
    """Добавить N месяцев к дате (сдвигает на первое число целевого месяца)."""
    year = d.year + (d.month - 1 + months) // 12
    month = (d.month - 1 + months) % 12 + 1
    # Используем первое число месяца как контрольную точку для comparison
    return date(year, month, 1)


# ---------- Основные функции ----------

def compute_cohort_matrix(
    subscriptions: list[dict],
    periods: int = 12,
) -> CohortMatrix:
    """Построить матрицу активных подписок по месяцам от старта когорты.

    Аргументы:
        subscriptions: список dict с ключами:
            - cohort_start_date: date — дата начала подписки (impl_start_date или created_at)
            - churn_date: date | None — дата перехода в C0 (None = ещё активна)
        periods: число месяцев для анализа (включая month_offset=0)

    Возвращает:
        CohortMatrix — dict[cohort_month][month_offset] = active_count
        active_count на offset=N = число подписок, которые НЕ ушли к дату
        cohort_start_date + N месяцев.

    Пример:
        >>> subs = [
        ...     {"cohort_start_date": date(2025, 1, 15), "churn_date": None},
        ...     {"cohort_start_date": date(2025, 1, 20), "churn_date": date(2025, 3, 1)},
        ... ]
        >>> matrix = compute_cohort_matrix(subs, periods=3)
        >>> matrix["2025-01"]
        {0: 2, 1: 2, 2: 1, 3: 1}  # второй ушёл в марте
    """
    # Группируем по cohort_month
    cohorts: dict[str, list[dict]] = {}
    for sub in subscriptions:
        csd = sub.get("cohort_start_date")
        if csd is None:
            continue
        if isinstance(csd, datetime):
            csd = csd.date()
        key = _month_key(csd)
        cohorts.setdefault(key, []).append(sub)

    matrix: CohortMatrix = {}
    today = date.today()

    for cohort_month, subs in sorted(cohorts.items()):
        # Начало когорты = первое число соответствующего месяца (для сравнения)
        year, month = int(cohort_month[:4]), int(cohort_month[5:7])
        cohort_start = date(year, month, 1)
        counts: dict[int, int] = {}

        for offset in range(periods + 1):
            check_date = _add_months(cohort_start, offset)
            # Если контрольная дата в будущем — не считаем (нет данных)
            if check_date > today:
                break
            active = 0
            for sub in subs:
                churn = sub.get("churn_date")
                if churn is None:
                    # Подписка всё ещё активна
                    active += 1
                else:
                    if isinstance(churn, datetime):
                        churn = churn.date()
                    # Живая, если ушла ПОСЛЕ контрольной даты
                    if churn >= check_date:
                        active += 1
            counts[offset] = active

        matrix[cohort_month] = counts

    return matrix


def compute_retention_percent(matrix: CohortMatrix) -> RetentionMatrix:
    """Нормализовать матрицу в проценты retention.

    Возвращает dict[cohort_month][month_offset] = retention_pct (0.0..100.0).
    offset=0 всегда 100.0 (если initial_count > 0).
    Если initial_count == 0 — пропускаем когорту (не включаем в результат).

    Пример:
        >>> matrix = {"2025-01": {0: 10, 1: 8, 2: 5}}
        >>> compute_retention_percent(matrix)
        {"2025-01": {0: 100.0, 1: 80.0, 2: 50.0}}
    """
    result: RetentionMatrix = {}
    for cohort_month, offsets in matrix.items():
        initial = offsets.get(0, 0)
        if initial == 0:
            continue
        result[cohort_month] = {
            offset: round(count / initial * 100, 1)
            for offset, count in offsets.items()
        }
    return result


def compute_avg_ltv_per_cohort(
    subscriptions: list[dict],
    periods_cap: int = 60,
) -> dict[str, float]:
    """Средний LTV на члена когорты (в валюте fee_actual).

    LTV = sum(fee_actual × months_active) / cohort_size

    months_active считается от cohort_start_date до:
    - churn_date если подписка ушла (C0)
    - today если ещё активна

    Аргументы:
        subscriptions: список dict с:
            - cohort_start_date: date
            - churn_date: date | None
            - fee_actual: Decimal | float | None (абонентская плата)
        periods_cap: максимальное число месяцев для расчёта LTV (защита от выбросов)

    Возвращает:
        dict[cohort_month, avg_ltv] — только когорты с fee_actual > 0

    Пример:
        >>> subs = [
        ...     {"cohort_start_date": date(2025, 1, 1), "churn_date": None,
        ...      "fee_actual": Decimal("100000")},
        ...     {"cohort_start_date": date(2025, 1, 15), "churn_date": date(2025, 4, 1),
        ...      "fee_actual": Decimal("50000")},
        ... ]
        >>> compute_avg_ltv_per_cohort(subs)
        # Первый: активен, допустим сейчас 2025-07 → 6 месяцев = 600000
        # Второй: ушёл в апреле → 3 месяца = 150000
        # avg = (600000 + 150000) / 2 = 375000
        {'2025-01': 375000.0}
    """
    today = date.today()
    cohorts: dict[str, list[float]] = {}

    for sub in subscriptions:
        csd = sub.get("cohort_start_date")
        if csd is None:
            continue
        if isinstance(csd, datetime):
            csd = csd.date()
        key = _month_key(csd)

        fee = sub.get("fee_actual")
        if fee is None:
            # Подписки без fee_actual не вносим в LTV-среднее
            # (но их всё равно считаем для размера когорты в других функциях)
            cohorts.setdefault(key, [])
            continue
        try:
            fee_f = float(fee)
        except (TypeError, ValueError):
            cohorts.setdefault(key, [])
            continue

        churn = sub.get("churn_date")
        if churn is None:
            end_date = today
        else:
            if isinstance(churn, datetime):
                churn = churn.date()
            end_date = churn

        # months_active = целое число полных месяцев
        delta_days = (end_date - csd).days
        months_active = max(0, min(delta_days // 30, periods_cap))

        ltv = fee_f * months_active
        cohorts.setdefault(key, []).append(ltv)

    return {
        cohort_month: round(sum(ltvs) / len(ltvs), 2)
        for cohort_month, ltvs in cohorts.items()
        if ltvs  # пропускаем когорты где у всех fee_actual=None
    }


def compute_projected_ltv(
    current_mrr: float,
    predicted_churn_rate: float,
) -> float:
    """Прогнозный LTV на основе текущего MRR и ожидаемого месячного churn.

    Формула: LTV = MRR / monthly_churn_rate
    (стандартная SaaS формула: если churn 5%/мес → avg lifetime 20 мес → LTV = MRR * 20)

    Аргументы:
        current_mrr: суммарный monthly recurring revenue (сумма fee_actual за месяц)
        predicted_churn_rate: месячный churn [0..1] (например, 0.05 = 5%/мес)
                              Вычисляется из retention matrix как avg(churned/active) per month.
                              Если 0 — возвращаем 0.0 (нет churn данных).

    Пример:
        >>> compute_projected_ltv(1_000_000, 0.05)
        20_000_000.0  # 1M / 0.05 = 20M прогнозный общий LTV
    """
    if predicted_churn_rate <= 0 or current_mrr <= 0:
        return 0.0
    return round(current_mrr / predicted_churn_rate, 2)


def compute_monthly_churn_rate(matrix: CohortMatrix) -> float:
    """Средний месячный churn rate по всей матрице.

    Считается как среднее по всем когортам и периодам:
    churn_in_period = (active[offset-1] - active[offset]) / active[offset-1]

    Возвращает значение [0..1] (например, 0.05 = 5% в месяц).
    Если данных нет — 0.0.

    Пример:
        >>> matrix = {"2025-01": {0: 10, 1: 8, 2: 7}}
        >>> compute_monthly_churn_rate(matrix)
        0.1625  # ((10-8)/10 + (8-7)/8) / 2 = (0.2 + 0.125) / 2 = 0.1625
    """
    rates: list[float] = []
    for offsets in matrix.values():
        sorted_offsets = sorted(offsets.keys())
        for i in range(1, len(sorted_offsets)):
            prev_offset = sorted_offsets[i - 1]
            curr_offset = sorted_offsets[i]
            prev_count = offsets[prev_offset]
            curr_count = offsets[curr_offset]
            if prev_count > 0 and curr_count <= prev_count:
                churn_rate = (prev_count - curr_count) / prev_count
                rates.append(churn_rate)
    if not rates:
        return 0.0
    return round(sum(rates) / len(rates), 4)


def build_cohort_data(
    subscriptions: list[dict],
    periods: int = 12,
) -> list[CohortData]:
    """Собрать полный список CohortData для frontend/API response.

    Объединяет compute_cohort_matrix + compute_retention_percent + compute_avg_ltv_per_cohort
    в один удобный формат.

    Аргументы:
        subscriptions: список dict (cohort_start_date, churn_date, fee_actual)
        periods: глубина анализа в месяцах

    Возвращает:
        list[CohortData] — отсортированный по cohort_month
    """
    matrix = compute_cohort_matrix(subscriptions, periods)
    retention_pct = compute_retention_percent(matrix)
    avg_ltv = compute_avg_ltv_per_cohort(subscriptions)

    result: list[CohortData] = []
    for cohort_month in sorted(matrix.keys()):
        offsets = matrix[cohort_month]
        pcts = retention_pct.get(cohort_month, {})
        initial = offsets.get(0, 0)

        retention_rows: list[CohortRow] = []
        prev_count = initial
        for offset in sorted(offsets.keys()):
            count = offsets[offset]
            churned = max(0, prev_count - count) if offset > 0 else 0
            row: CohortRow = {
                "month_offset": offset,
                "active_count": count,
                "churned_count": churned,
                "retention_pct": pcts.get(offset, 0.0),
            }
            retention_rows.append(row)
            prev_count = count

        result.append({
            "cohort_month": cohort_month,
            "initial_count": initial,
            "retention": retention_rows,
            "avg_ltv": avg_ltv.get(cohort_month, 0.0),
        })

    return result


def build_cohort_xlsx_rows(cohorts: list[CohortData], max_offset: int) -> tuple[list[str], list[list]]:
    """Подготовить заголовки и строки для Excel-экспорта матрицы retention.

    Возвращает (headers, rows) для передачи в build_xlsx.

    Формат:
        Когорта | Размер | +0 мес | +1 мес | ... | +N мес | Avg LTV
        2025-01 |   20   | 100%   | 90%    | ... | 60%    | 150000
    """
    headers = ["Когорта", "Размер"] + [f"+{i} мес" for i in range(max_offset + 1)] + ["Avg LTV"]
    rows: list[list] = []
    for cohort in cohorts:
        pct_by_offset = {r["month_offset"]: r["retention_pct"] for r in cohort["retention"]}
        row: list = [cohort["cohort_month"], cohort["initial_count"]]
        for i in range(max_offset + 1):
            pct = pct_by_offset.get(i)
            row.append(f"{pct:.1f}%" if pct is not None else "—")
        row.append(cohort["avg_ltv"])
        rows.append(row)
    return headers, rows
