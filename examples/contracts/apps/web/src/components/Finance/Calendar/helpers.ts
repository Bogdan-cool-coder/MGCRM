import type {
  FinCalendarEvent,
  FinCalendarSourceType,
  FinCalendarStatus,
} from "@/lib/types";

// ── Дата-математика (локальная, без UTC-сдвигов) ──────────────────────────────

/** YYYY-MM-DD из Date (по локальному календарю). */
export function toISODate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

/** Date из YYYY-MM-DD (локальная полночь). */
export function fromISODate(iso: string): Date {
  const [y, m, d] = iso.split("-").map(Number);
  return new Date(y, m - 1, d);
}

export function addDays(d: Date, n: number): Date {
  const out = new Date(d);
  out.setDate(out.getDate() + n);
  return out;
}

export function addMonths(d: Date, n: number): Date {
  return new Date(d.getFullYear(), d.getMonth() + n, 1);
}

export function startOfMonth(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), 1);
}

export function endOfMonth(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth() + 1, 0);
}

/** Понедельник недели, в которую входит d (ISO: пн=0). */
export function startOfWeekMonday(d: Date): Date {
  const out = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const dow = (out.getDay() + 6) % 7; // вс=0 → 6, пн=1 → 0
  out.setDate(out.getDate() - dow);
  return out;
}

export function isSameDay(a: Date, b: Date): boolean {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

/** Сетка месяца 6×7: 42 дня с понедельника недели первого числа. */
export function monthGridDays(cursor: Date): Date[] {
  const first = startOfMonth(cursor);
  const start = startOfWeekMonday(first);
  const days: Date[] = [];
  for (let i = 0; i < 42; i++) days.push(addDays(start, i));
  return days;
}

/** Дни недели (пн–вс) для week-view. */
export function weekDays(cursor: Date): Date[] {
  const start = startOfWeekMonday(cursor);
  const days: Date[] = [];
  for (let i = 0; i < 7; i++) days.push(addDays(start, i));
  return days;
}

/** Диапазон выборки для текущего вида. */
export function rangeForView(
  view: "month" | "week" | "list",
  cursor: Date,
): { from: string; to: string } {
  if (view === "week") {
    const days = weekDays(cursor);
    return { from: toISODate(days[0]), to: toISODate(days[6]) };
  }
  if (view === "month") {
    const days = monthGridDays(cursor);
    return { from: toISODate(days[0]), to: toISODate(days[41]) };
  }
  // list — полный месяц
  return { from: toISODate(startOfMonth(cursor)), to: toISODate(endOfMonth(cursor)) };
}

// ── Метки и форматирование ────────────────────────────────────────────────────

export const MONTH_NAMES = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];

export const WEEKDAY_SHORT = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];

export function monthTitle(cursor: Date): string {
  return `${MONTH_NAMES[cursor.getMonth()]} ${cursor.getFullYear()}`;
}

export function weekTitle(cursor: Date): string {
  const days = weekDays(cursor);
  const a = days[0];
  const b = days[6];
  const aStr = `${a.getDate()} ${MONTH_NAMES[a.getMonth()].toLowerCase().slice(0, 3)}`;
  const bStr = `${b.getDate()} ${MONTH_NAMES[b.getMonth()].toLowerCase().slice(0, 3)} ${b.getFullYear()}`;
  return `${aStr} — ${bStr}`;
}

export function fullDateLabel(iso: string): string {
  const d = fromISODate(iso);
  return `${d.getDate()} ${MONTH_NAMES[d.getMonth()].toLowerCase()} ${d.getFullYear()}`;
}

const moneyFmt = new Intl.NumberFormat("ru-RU", {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
});

/** Сумма со знаком по направлению: in → +, out → −. */
export function formatSignedAmount(amount: number, direction: "in" | "out", currency: string): string {
  const sign = direction === "in" ? "+" : "−";
  return `${sign}${moneyFmt.format(Math.abs(amount))} ${currency}`;
}

export function formatAmount(amount: number, currency: string): string {
  return `${moneyFmt.format(Math.abs(amount))} ${currency}`;
}

// ── Источники → маршрут и метка ───────────────────────────────────────────────

const SOURCE_LABELS: Record<FinCalendarSourceType, string> = {
  invoice: "Инвойс",
  act: "Акт",
  vendor_bill: "Счёт поставщика",
  request: "Заявка",
  deal: "Сделка",
};

export function sourceLabel(t: FinCalendarSourceType): string {
  return SOURCE_LABELS[t];
}

/**
 * Маршрут карточки-источника. Все detail-роуты подтверждены:
 * /finance/invoices/[id], /finance/acts/[id], /finance/vendor-bills/[id],
 * /finance/requests/[id], /deals/[id].
 */
export function sourceRoute(t: FinCalendarSourceType, id: number): string {
  switch (t) {
    case "invoice":
      return `/finance/invoices/${id}`;
    case "act":
      return `/finance/acts/${id}`;
    case "vendor_bill":
      return `/finance/vendor-bills/${id}`;
    case "request":
      return `/finance/requests/${id}`;
    case "deal":
      return `/deals/${id}`;
  }
}

// ── Статусы ───────────────────────────────────────────────────────────────────

const STATUS_LABELS: Record<FinCalendarStatus, string> = {
  planned: "план",
  paid: "оплачено",
  overdue: "просрочено",
};

export function statusLabel(s: FinCalendarStatus): string {
  return STATUS_LABELS[s];
}

const STATUS_BADGE: Record<FinCalendarStatus, string> = {
  planned: "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300",
  paid: "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400",
  overdue: "bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400",
};

export function statusBadgeCls(s: FinCalendarStatus): string {
  return STATUS_BADGE[s];
}

// ── Агрегация ─────────────────────────────────────────────────────────────────

/** События, сгруппированные по дате (YYYY-MM-DD → events). */
export function groupByDate(events: FinCalendarEvent[]): Map<string, FinCalendarEvent[]> {
  const map = new Map<string, FinCalendarEvent[]>();
  for (const e of events) {
    const arr = map.get(e.date);
    if (arr) arr.push(e);
    else map.set(e.date, [e]);
  }
  return map;
}

export interface CurrencyNet {
  currency: string;
  in_amount: number;
  out_amount: number;
}

/** Итоги по валютам для набора событий. */
export function netByCurrency(events: FinCalendarEvent[]): CurrencyNet[] {
  const map = new Map<string, CurrencyNet>();
  for (const e of events) {
    let row = map.get(e.currency);
    if (!row) {
      row = { currency: e.currency, in_amount: 0, out_amount: 0 };
      map.set(e.currency, row);
    }
    if (e.direction === "in") row.in_amount += e.amount;
    else row.out_amount += e.amount;
  }
  return Array.from(map.values()).sort((a, b) => a.currency.localeCompare(b.currency));
}
