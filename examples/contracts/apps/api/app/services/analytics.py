"""Аналитика по договорам/воронкам + генерация Excel-выгрузок (чистые хелперы).

Чистые функции (`avg_days`, `build_xlsx`, `probability_for_stage`,
`compute_funnel_metrics`, `compute_forecast_revenue`) живут здесь, чтобы их
было удобно покрывать unit-тестами без БД. Реальные запросы и роуты — в
`app.routers.analytics`.
"""
from __future__ import annotations

from datetime import date, datetime, timedelta
from io import BytesIO


def avg_days(pairs: list[tuple[datetime | None, datetime | None]]) -> float:
    """Среднее число дней между парами (start, end). Пары с None пропускаются."""
    deltas = [(b - a).total_seconds() / 86400 for a, b in pairs if a and b]
    return round(sum(deltas) / len(deltas), 1) if deltas else 0.0


# ============ Sparkline + trend (Design v2 / Dashboard KPI) ============


def compute_weekly_counts(
    dates: list[date],
    weeks: int = 8,
    ref_date: date | None = None,
) -> list[int]:
    """Разбить список дат по неделям (последние N недель) и вернуть счётчик каждой недели.

    Нулевые недели заполняются нулями (sparkline не рваный).
    Неделя 0 = самая ранняя, неделя N-1 = текущая.

    Args:
        dates: список дат событий (например created_at.date() контрактов).
        weeks: глубина (по умолчанию 8 точек).
        ref_date: «сегодня» — граница отсчёта (по умолчанию date.today()).

    Returns:
        Список целых чисел длиной `weeks` — слева старые, справа новые.
    """
    today = ref_date or date.today()
    # Начало окна: начало недели (понедельник) N недель назад
    start_of_window = today - timedelta(weeks=weeks)
    buckets = [0] * weeks
    for d in dates:
        if d is None:
            continue
        delta = (today - d).days
        if delta < 0 or delta >= weeks * 7:
            continue
        # Неделя с конца: 0 = текущая, 1 = предыдущая, ...
        week_from_end = delta // 7
        idx = weeks - 1 - week_from_end
        if 0 <= idx < weeks:
            buckets[idx] += 1
    return buckets


def compute_trend_pct(
    current_value: float,
    previous_value: float,
) -> float | None:
    """Процент изменения current_value относительно previous_value.

    Возвращает значение в диапазоне [−∞, +∞] как float (не умноженное на 100).
    Возвращает None если previous_value == 0 (нет базы для сравнения).

    Примеры:
        >>> compute_trend_pct(110, 100) == 10.0
        >>> compute_trend_pct(90, 100) == -10.0
        >>> compute_trend_pct(5, 0)    is None
    """
    if previous_value == 0:
        return None
    return round((current_value - previous_value) / abs(previous_value) * 100, 1)


def compute_avg_days_for_period(
    pairs: list[tuple[datetime | None, datetime | None]],
    cutoff_start: datetime,
    cutoff_end: datetime,
) -> float:
    """Среднее число дней для пар, у которых start >= cutoff_start и start < cutoff_end."""
    filtered = [
        (a, b)
        for a, b in pairs
        if a is not None and b is not None and cutoff_start <= a < cutoff_end
    ]
    return avg_days(filtered)


def build_kpi_trends(
    contract_dates: list[date],
    cycle_pairs: list[tuple[datetime | None, datetime | None]],
    ttapprove_pairs: list[tuple[datetime | None, datetime | None]],
    ref_datetime: datetime | None = None,
    sparkline_weeks: int = 8,
) -> dict:
    """Вычислить sparkline-данные и trend_pct для KPI-карточек дашборда.

    Чистая функция (без БД). Все данные передаются уже загруженными.

    Метрики:
    - total_sparkline: 8-недельный ряд новых договоров (по created_at).
    - total_trend_pct: изменение числа новых договоров за последние 4 недели
      vs предыдущие 4 недели.
    - avg_cycle_trend_pct: изменение avg_cycle_days (последние 30 дней vs
      предыдущие 30 дней). Отрицательное = цикл стал короче = хорошо.
    - avg_time_to_approve_trend_pct: аналогично для avg_time_to_approve_days.

    Аргументы:
        contract_dates: список date (created_at.date()) всех контрактов в выборке.
        cycle_pairs: список (created_at, signed_at) для подписанных контрактов.
        ttapprove_pairs: список (approval.created_at, approval.decided_at) для
            согласованных договоров.
        ref_datetime: точка отсчёта (по умолчанию datetime.utcnow()).
        sparkline_weeks: количество недель для ряда sparkline (по умолчанию 8).

    Returns:
        {
            total_sparkline: list[int],         # 8 точек (недель), старые→новые
            total_trend_pct: float | None,       # % изменения count новых договоров
            avg_cycle_trend_pct: float | None,   # % изм. avg_cycle_days
            avg_time_to_approve_trend_pct: float | None,  # % изм. avg_time_to_approve_days
        }
    """
    now = ref_datetime or datetime.utcnow()
    today = now.date()

    # --- sparkline: weekly buckets (последние sparkline_weeks недель) ---
    total_sparkline = compute_weekly_counts(
        contract_dates, weeks=sparkline_weeks, ref_date=today
    )

    # --- total trend: последние 4 недели vs предыдущие 4 недели ---
    half = sparkline_weeks // 2
    current_half = sum(total_sparkline[half:])
    previous_half = sum(total_sparkline[:half])
    total_trend_pct = compute_trend_pct(current_half, previous_half)

    # --- avg_cycle trend: [-30d, now) vs [-60d, -30d) ---
    _30d = timedelta(days=30)
    cutoff_now = now
    cutoff_30 = now - _30d
    cutoff_60 = now - 2 * _30d

    cycle_current = compute_avg_days_for_period(cycle_pairs, cutoff_30, cutoff_now)
    cycle_prev = compute_avg_days_for_period(cycle_pairs, cutoff_60, cutoff_30)
    avg_cycle_trend_pct = compute_trend_pct(cycle_current, cycle_prev)

    # --- avg_time_to_approve trend ---
    tta_current = compute_avg_days_for_period(ttapprove_pairs, cutoff_30, cutoff_now)
    tta_prev = compute_avg_days_for_period(ttapprove_pairs, cutoff_60, cutoff_30)
    avg_time_to_approve_trend_pct = compute_trend_pct(tta_current, tta_prev)

    return {
        "total_sparkline": total_sparkline,
        "total_trend_pct": total_trend_pct,
        "avg_cycle_trend_pct": avg_cycle_trend_pct,
        "avg_time_to_approve_trend_pct": avg_time_to_approve_trend_pct,
    }


def build_kpi_trends_from_aggregates(
    weekly_counts: list[int],
    cycle_current: float,
    cycle_prev: float,
    tta_current: float,
    tta_prev: float,
) -> dict:
    """SQL-fed вариант build_kpi_trends: на вход уже агрегированные значения.

    Используется в /analytics/contracts, чтобы НЕ загружать всю таблицу в
    Python ради sparkline/trend. Все per-period средние и недельные счётчики
    считаются в SQL (GROUP BY / avg с фильтром по created_at-окну), а финальная
    арифметика тренда — здесь (чистая функция, тестируемая без БД).

    Результат идентичен build_kpi_trends при тех же исходных данных:
    - total_sparkline = weekly_counts (8 точек, старые→новые)
    - total_trend_pct = trend(sum(вторая половина), sum(первая половина))
    - *_trend_pct = trend(текущее_окно_среднее, предыдущее_окно_среднее)

    Args:
        weekly_counts: список int длиной N недель (старые→новые) — sparkline.
        cycle_current: avg_cycle_days за [-30d, now).
        cycle_prev: avg_cycle_days за [-60d, -30d).
        tta_current: avg_time_to_approve за [-30d, now).
        tta_prev: avg_time_to_approve за [-60d, -30d).
    """
    half = len(weekly_counts) // 2
    current_half = sum(weekly_counts[half:])
    previous_half = sum(weekly_counts[:half])
    return {
        "total_sparkline": weekly_counts,
        "total_trend_pct": compute_trend_pct(current_half, previous_half),
        "avg_cycle_trend_pct": compute_trend_pct(cycle_current, cycle_prev),
        "avg_time_to_approve_trend_pct": compute_trend_pct(tta_current, tta_prev),
    }


def weekly_buckets_from_counts(
    rows: list[tuple[date, int]],
    weeks: int = 8,
    ref_date: date | None = None,
) -> list[int]:
    """Свернуть SQL-результат «дата → count» в N недельных бакетов (старые→новые).

    Эквивалент compute_weekly_counts, но на вход — уже сгруппированные по дню
    счётчики из SQL (GROUP BY created_at::date), а не сырой список дат. Логика
    бакетирования идентична: неделя 0 = самая ранняя, N-1 = текущая; даты вне
    окна игнорируются.
    """
    today = ref_date or date.today()
    buckets = [0] * weeks
    for d, cnt in rows:
        if d is None:
            continue
        delta = (today - d).days
        if delta < 0 or delta >= weeks * 7:
            continue
        idx = weeks - 1 - (delta // 7)
        if 0 <= idx < weeks:
            buckets[idx] += cnt
    return buckets


def build_xlsx(title: str, headers: list[str], rows: list[list]) -> bytes:
    """Сформировать .xlsx (один лист) из заголовков и строк. Возвращает байты файла."""
    from openpyxl import Workbook

    wb = Workbook()
    ws = wb.active
    ws.title = (title or "Sheet")[:31]
    ws.append(headers)
    for r in rows:
        ws.append(["" if v is None else v for v in r])
    # автоширина по простому эвристику
    for i, h in enumerate(headers, start=1):
        width = max([len(str(h))] + [len(str(r[i - 1])) for r in rows if i - 1 < len(r)] + [8])
        ws.column_dimensions[ws.cell(row=1, column=i).column_letter].width = min(width + 2, 50)
    buf = BytesIO()
    wb.save(buf)
    return buf.getvalue()


# ============ Конверсия воронки (Эпик 6 MVP) ============

# Хардкод вероятностей по «ключевым словам» в названии этапа. Используется и в
# forecast, и в funnel. Не идеально (зависит от человеческого нейминга этапов),
# но в MVP достаточно — UI показывает рассчитанные значения, юрист может
# скорректировать probability_by_stage вручную через override (будущий эпик 10,
# модель SalesPlan / Pipeline.config). Алгоритм: lowercase + первое совпадение
# из списка (порядок — от самых вероятных к менее).
PROBABILITY_KEYWORDS: list[tuple[str, float]] = [
    ("успех", 1.0),
    ("won", 1.0),
    ("signed", 0.95),
    ("подписан", 0.95),
    ("paid", 0.95),
    ("оплачен", 0.95),
    ("hot", 0.7),
    ("горяч", 0.7),
    ("warm", 0.4),
    ("тёпл", 0.4),
    ("тепл", 0.4),
    ("trial", 0.5),
    ("negotiation", 0.5),
    ("согласован", 0.5),
    ("proposal", 0.3),
    ("кп", 0.3),
    ("meeting", 0.2),
    ("встреч", 0.2),
    ("qualif", 0.15),
    ("квалиф", 0.15),
    ("cold", 0.1),
    ("холод", 0.1),
]

# Ключевые слова для «закрыто-выиграно» — для подсчёта won_count и base
# среднего чека в forecast.
WON_KEYWORDS: tuple[str, ...] = (
    "успех", "won", "signed", "подписан", "paid", "оплачен",
)

# Ключевые слова для «отказ» — для lost_count.
LOST_KEYWORDS: tuple[str, ...] = (
    "проигрыш", "lost", "отказ", "cancel", "archiv",
)


def probability_for_stage(stage_name: str, is_won: bool = False, is_lost: bool = False) -> float:
    """Вернёт вероятность конверсии для этапа [0..1] на основе названия/флагов.

    Сначала — по флагам (is_won → 1.0, is_lost → 0.0). Затем — keyword-маппинг
    по lowercase-имени. Если ничего не совпало — 0.0 (этап не вносит в forecast).
    """
    if is_lost:
        return 0.0
    if is_won:
        return 1.0
    n = (stage_name or "").lower()
    for kw, prob in PROBABILITY_KEYWORDS:
        if kw in n:
            return prob
    return 0.0


def _is_won_stage(name: str, is_won: bool) -> bool:
    if is_won:
        return True
    n = (name or "").lower()
    return any(kw in n for kw in WON_KEYWORDS)


def _is_lost_stage(name: str, is_lost: bool) -> bool:
    if is_lost:
        return True
    n = (name or "").lower()
    return any(kw in n for kw in LOST_KEYWORDS)


def compute_funnel_metrics(stages: list[dict], deals: list[dict]) -> list[dict]:
    """Per-stage metrics: count, avg_days_in_stage, transition_to_next_pct.

    Чистая функция: на вход sequence стадий (с id/name/sort_order/is_won/is_lost)
    и список deals (с stage_id, updated_at: datetime). Возвращает список dict
    с метриками для каждой стадии в порядке sort_order.

    Метрики:
    - count: число активных сделок на этой стадии
    - avg_days_in_stage: средняя длительность (now - updated_at) для активных
      на этом этапе. Без DealStageHistory это приближённо: сделка могла зайти
      в этап раньше последнего updated_at (например, изменили title).
    - transition_to_next_pct: упрощённая метрика — для нон-won/нон-lost этапов
      = count_deals_at_later_stages / (count_deals_at_this + later). Грубое
      приближение «сколько из всех попавших в воронку прошли этот этап».
      Для won/lost этапов = 100% / 0% соответственно.
    - is_won / is_lost: флаги для UI (раскрасить колонки воронки).
    """
    # Сортируем по sort_order (defensive — caller обычно отсортирован).
    sorted_stages = sorted(stages, key=lambda s: s.get("sort_order", 0))
    n = len(sorted_stages)
    now = datetime.now().astimezone()

    # Сначала подсчёт по стадии.
    by_stage_count: dict[int, int] = {}
    by_stage_days: dict[int, list[float]] = {}
    for d in deals:
        sid = d.get("stage_id")
        if sid is None:
            continue
        by_stage_count[sid] = by_stage_count.get(sid, 0) + 1
        ua = d.get("updated_at")
        if ua is not None:
            # Приведение к aware-datetime, если caller передал naive
            if ua.tzinfo is None:
                ua = ua.replace(tzinfo=now.tzinfo)
            days = (now - ua).total_seconds() / 86400
            by_stage_days.setdefault(sid, []).append(days)

    out: list[dict] = []
    cumulative_later_total = 0  # сделок на этапах ПОСЛЕ текущего
    for i in range(n - 1, -1, -1):
        st = sorted_stages[i]
        sid = st.get("id")
        name = st.get("name", "")
        is_won = bool(st.get("is_won", False))
        is_lost = bool(st.get("is_lost", False))
        cnt = by_stage_count.get(sid, 0)
        days_list = by_stage_days.get(sid, [])
        avg_d = round(sum(days_list) / len(days_list), 1) if days_list else 0.0

        if is_won:
            transition = 100.0
        elif is_lost:
            transition = 0.0
        elif (cnt + cumulative_later_total) == 0:
            transition = 0.0
        else:
            transition = round(
                cumulative_later_total / (cnt + cumulative_later_total) * 100, 1,
            )

        out.insert(0, {
            "stage_id": sid,
            "stage_name": name,
            "stage_code": st.get("code"),
            "sort_order": st.get("sort_order", 0),
            "count": cnt,
            "avg_days_in_stage": avg_d,
            "transition_to_next_pct": transition,
            "is_won": is_won,
            "is_lost": is_lost,
            "probability": probability_for_stage(name, is_won, is_lost),
        })
        # Лости НЕ добавляем в cumulative — они вне основного потока («ушли в архив»)
        if not is_lost:
            cumulative_later_total += cnt

    return out


# ============ Forecast (Эпик 6 MVP) ============


def compute_forecast_revenue(
    stages: list[dict],
    deals: list[dict],
    default_avg_value: float = 0.0,
) -> dict:
    """Простой forecast: sum(count × avg_value_per_won × probability_by_stage).

    Чистая функция:
    - stages: список dict (id, name, is_won, is_lost)
    - deals: список dict (stage_id, amount: float|None, currency: str|None) —
      все активные сделки воронки + закрытые-выиграные (для расчёта avg)
    - default_avg_value: фолбэк, если в воронке ещё нет won-сделок

    Мульти-валютность: avg чек считается только по won-сделкам в primary-валюте
    (валюта won-сделок с наибольшей суммой), чтобы не складывать KZT+RUB в одно
    число. estimated_revenue выражена в той же primary-валюте. Возвращаемое поле
    `currency` фронт использует для форматирования (вместо хардкода ₽).

    Возвращает:
    {
        "active_deals_by_stage": {stage_name: count},
        "avg_value_per_won": float,
        "won_count": int,
        "currency": str | None,
        "probability_by_stage": {stage_name: 0..1},
        "estimated_revenue": float,
        "by_stage_breakdown": [{stage_name, count, probability, estimated}]
    }
    """
    by_id: dict[int, dict] = {s["id"]: s for s in stages}

    # won-суммы группируем по валюте, чтобы avg чек не смешивал KZT+RUB.
    won_by_currency: dict[str, list[float]] = {}
    won_count = 0
    active_by_stage: dict[int, int] = {}

    for d in deals:
        sid = d.get("stage_id")
        if sid not in by_id:
            continue
        st = by_id[sid]
        amt = d.get("amount")
        try:
            amt_f = float(amt) if amt is not None else 0.0
        except (TypeError, ValueError):
            amt_f = 0.0
        if _is_won_stage(st.get("name", ""), bool(st.get("is_won", False))):
            cur = (d.get("currency") or "").upper() or "—"
            won_by_currency.setdefault(cur, []).append(amt_f)
            won_count += 1
        elif not _is_lost_stage(st.get("name", ""), bool(st.get("is_lost", False))):
            active_by_stage[sid] = active_by_stage.get(sid, 0) + 1

    # primary-валюта = та, в которой суммарно больше всего won-денег.
    primary_currency: str | None = None
    if won_by_currency:
        primary_currency = max(
            won_by_currency, key=lambda c: sum(won_by_currency[c]),
        )
        if primary_currency == "—":
            primary_currency = None
    primary_amounts = (
        won_by_currency.get(primary_currency, [])
        if primary_currency
        else won_by_currency.get("—", [])
    )
    avg_won = (
        round(sum(primary_amounts) / len(primary_amounts), 2)
        if primary_amounts
        else float(default_avg_value)
    )

    breakdown: list[dict] = []
    estimated_total = 0.0
    active_by_name: dict[str, int] = {}
    probability_by_name: dict[str, float] = {}
    for st in sorted(stages, key=lambda s: s.get("sort_order", 0)):
        sid = st["id"]
        name = st.get("name", "")
        is_won = bool(st.get("is_won", False))
        is_lost = bool(st.get("is_lost", False))
        if is_won or is_lost:
            continue
        cnt = active_by_stage.get(sid, 0)
        if cnt == 0:
            continue
        prob = probability_for_stage(name, is_won, is_lost)
        est = round(cnt * avg_won * prob, 2)
        breakdown.append({
            "stage_id": sid,
            "stage_name": name,
            "count": cnt,
            "probability": prob,
            "estimated": est,
        })
        estimated_total += est
        active_by_name[name] = cnt
        probability_by_name[name] = prob

    return {
        "active_deals_by_stage": active_by_name,
        "avg_value_per_won": avg_won,
        "won_count": won_count,
        "currency": primary_currency,
        "probability_by_stage": probability_by_name,
        "estimated_revenue": round(estimated_total, 2),
        "by_stage_breakdown": breakdown,
    }


# ============ CONTACTS 2.0 Ф4: resolve клиента ============


def resolve_client_name(
    company_id: int | None,
    company_map: dict[int, str],
    counterparty_id: int | None,
    cp_map: dict[int, str],
) -> str:
    """Вернуть отображаемое имя клиента: Company-first, fallback на Counterparty.

    CONTACTS 2.0 Ф4: Company — источник истины. Если company_id задан и есть в
    company_map — берём оттуда. Иначе fallback через counterparty_id → cp_map.

    Чистая функция (без БД): company_map и cp_map — уже загруженные словари
    id→name, собранные в роутере одним запросом.

    Примеры:
        >>> resolve_client_name(1, {1: "ПТС Казахстан"}, 5, {5: "PTC KZ"})
        'ПТС Казахстан'
        >>> resolve_client_name(None, {}, 5, {5: "PTC KZ"})
        'PTC KZ'
        >>> resolve_client_name(None, {}, None, {})
        ''
    """
    if company_id is not None:
        return company_map.get(company_id, "")
    if counterparty_id is not None:
        return cp_map.get(counterparty_id, "")
    return ""
