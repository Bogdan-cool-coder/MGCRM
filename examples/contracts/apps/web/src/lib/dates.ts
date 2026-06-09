/** Утилиты для работы с датами в формате ДД.ММ.ГГГГ */

const MONTHS_GENITIVE_RU = [
  "января", "февраля", "марта", "апреля", "мая", "июня",
  "июля", "августа", "сентября", "октября", "ноября", "декабря",
];

/** "12.05.2026" → { day: "12", month: "мая", year: "2026" } */
export function parseRuDate(s: string): { day: string; month: string; year: string } | null {
  if (!s) return null;
  const m = s.trim().match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
  if (!m) return null;
  const d = parseInt(m[1], 10);
  const mo = parseInt(m[2], 10);
  const y = parseInt(m[3], 10);
  if (mo < 1 || mo > 12 || d < 1 || d > 31) return null;
  return {
    day: String(d).padStart(2, "0"),
    month: MONTHS_GENITIVE_RU[mo - 1],
    year: String(y),
  };
}

/** "12.05.2026" → "2026-05-12" (для <input type=date>) */
export function ruToIso(s: string): string {
  if (!s) return "";
  const m = s.trim().match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
  if (!m) return "";
  return `${m[3]}-${m[2].padStart(2, "0")}-${m[1].padStart(2, "0")}`;
}

/** "2026-05-12" → "12.05.2026" */
export function isoToRu(iso: string): string {
  if (!iso) return "";
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!m) return "";
  return `${m[3]}.${m[2]}.${m[1]}`;
}

/* ============== Activity / Timeline форматтеры (Эпик 2) ============== */

const FORMATTER_DATETIME_SHORT = new Intl.DateTimeFormat("ru-RU", {
  day: "numeric",
  month: "short",
  hour: "2-digit",
  minute: "2-digit",
});

const FORMATTER_DATE_FULL = new Intl.DateTimeFormat("ru-RU", {
  day: "numeric",
  month: "long",
  year: "numeric",
});

const FORMATTER_DATE_THIS_YEAR = new Intl.DateTimeFormat("ru-RU", {
  day: "numeric",
  month: "long",
});

/** ISO → "12 мая, 14:30" (компактно). Безопасен к null/undefined и невалидному входу. */
export function formatDateTimeShort(iso: string | null | undefined): string {
  if (!iso) return "";
  try {
    const d = new Date(iso);
    if (isNaN(d.getTime())) return "";
    return FORMATTER_DATETIME_SHORT.format(d);
  } catch {
    return "";
  }
}

/** ISO → "Сегодня" / "Вчера" / "12 мая" / "12 мая 2025 г.". Для группировки Timeline. */
export function formatDateTimeRelative(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return "";
  const today = new Date();
  const yesterday = new Date();
  yesterday.setDate(yesterday.getDate() - 1);
  if (d.toDateString() === today.toDateString()) return "Сегодня";
  if (d.toDateString() === yesterday.toDateString()) return "Вчера";
  if (d.getFullYear() === today.getFullYear()) {
    return FORMATTER_DATE_THIS_YEAR.format(d);
  }
  return FORMATTER_DATE_FULL.format(d);
}

// ---------------------------------------------------------------------------
// Канонические ISO → русский форматтеры (используй везде вместо локальных копий)
// ---------------------------------------------------------------------------

const FORMATTER_DATE_RU = new Intl.DateTimeFormat("ru-RU", {
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
});

const FORMATTER_DATETIME_RU = new Intl.DateTimeFormat("ru-RU", {
  day: "2-digit",
  month: "2-digit",
  year: "numeric",
  hour: "2-digit",
  minute: "2-digit",
});

/**
 * ISO-строка → "12.05.2026" (только дата). Null-safe: null/undefined/"" → "—".
 * Канонический форматтер MACRO CRM для дат без времени.
 */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  try {
    const d = new Date(iso);
    if (isNaN(d.getTime())) return "—";
    return FORMATTER_DATE_RU.format(d);
  } catch {
    return "—";
  }
}

/**
 * ISO-строка → "12.05.2026, 14:30" (дата + время). Null-safe: null/undefined/"" → "—".
 * Канонический форматтер MACRO CRM для временны́х меток.
 */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "—";
  try {
    const d = new Date(iso);
    if (isNaN(d.getTime())) return "—";
    return FORMATTER_DATETIME_RU.format(d);
  } catch {
    return "—";
  }
}

/** Прибавить N месяцев к ДД.ММ.ГГГГ */
export function addMonths(ruDate: string, months: number): string {
  const iso = ruToIso(ruDate);
  if (!iso || !months) return "";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return "";
  // Корректно: если 31 января + 1 мес → 28/29 февраля, не 3 марта
  const targetMonth = d.getMonth() + months;
  const originalDate = d.getDate();
  d.setDate(1);
  d.setMonth(targetMonth);
  const lastOfMonth = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
  d.setDate(Math.min(originalDate, lastOfMonth));
  const dd = String(d.getDate()).padStart(2, "0");
  const mm = String(d.getMonth() + 1).padStart(2, "0");
  const yyyy = d.getFullYear();
  return `${dd}.${mm}.${yyyy}`;
}
