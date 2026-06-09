/** Утилиты человекочитаемых форматтеров (даты, числа, деньги).
 *
 * См. также `lib/dates.ts` — там Activity/Timeline форматтеры и парсеры
 * ДД.ММ.ГГГГ. Здесь — общие short-форматы для виджетов (relative time,
 * currency без копеек).
 *
 * Единый форматтер валют: используй `formatCurrency(amount, currency)`.
 * formatCurrencyRu оставлен для обратной совместимости — @deprecated.
 */

// ---------------------------------------------------------------------------
// Валютный конфиг
// ---------------------------------------------------------------------------

/**
 * Описание форматирования одной валюты.
 * - locale: локаль Intl.NumberFormat для группировки тысяч
 *   (en-US → запятые «4,000»; ru-RU → пробелы «4 000»)
 * - symbol: символ после числа (или код, если символ сломает LTR-вёрстку)
 */
export interface CurrencyConfig {
  locale: string;
  symbol: string;
}

/**
 * Конфиг валют системы MACRO CRM.
 *
 * | Валюта | Локаль  | Символ | Пример      |
 * |--------|---------|--------|-------------|
 * | USD    | en-US   | $      | 4,000 $     |
 * | EUR    | en-US   | €      | 4,000 €     |
 * | RUB    | ru-RU   | ₽      | 4 000 ₽     |
 * | KZT    | ru-RU   | ₸      | 4 000 ₸     |
 * | UZS    | ru-RU   | UZS    | 4 000 UZS   |
 * | AED    | en-US   | AED    | 4,000 AED   |
 *
 * UZS/AED используют код, т.к. их символы (сўм / د.إ) ломают LTR-вёрстку.
 * Для неизвестных валют применяется DEFAULT_CURRENCY_CONFIG (ru-RU, код).
 */
export const CURRENCY_CONFIG: Record<string, CurrencyConfig> = {
  USD: { locale: "en-US", symbol: "$" },
  EUR: { locale: "en-US", symbol: "€" },
  RUB: { locale: "ru-RU", symbol: "₽" },
  KZT: { locale: "ru-RU", symbol: "₸" },
  UZS: { locale: "ru-RU", symbol: "UZS" },
  AED: { locale: "en-US", symbol: "AED" },
};

/** Конфиг по умолчанию для неизвестных/пустых валют (код подставляется снаружи). */
const DEFAULT_LOCALE = "ru-RU";

// ---------------------------------------------------------------------------
// Основные форматтеры
// ---------------------------------------------------------------------------

/**
 * Форматирует денежную сумму с символом валюты ПОСЛЕ числа.
 *
 * @example
 * formatCurrency(4000, "USD")            // "4,000 $"
 * formatCurrency(4000, "RUB")            // "4 000 ₽"
 * formatCurrency(4000.5, "KZT")          // "4 000,5 ₸"
 * formatCurrency(4000, "UZS")            // "4 000 UZS"
 * formatCurrency(4000, "AED")            // "4,000 AED"
 * formatCurrency(null, "USD")            // "—"
 * formatCurrency(4000, null)             // "4 000"   (число без символа)
 * formatCurrency("4000.50", "EUR")       // "4,000.5 €"
 *
 * @param amount  Число или строка (Decimal из API), null/undefined → "—"
 * @param currency Код валюты ISO 4217 (USD/RUB/KZT/…), null/пусто → только число
 * @param opts    Переопределение дробных знаков (minFractionDigits / maxFractionDigits)
 */
export function formatCurrency(
  amount: number | string | null | undefined,
  currency: string | null | undefined,
  opts?: { maxFractionDigits?: number; minFractionDigits?: number },
): string {
  if (amount === null || amount === undefined || amount === "") return "—";
  const n = typeof amount === "string" ? parseFloat(amount) : amount;
  if (isNaN(n)) return "—";

  const code = currency?.toUpperCase() ?? "";
  const cfg = code ? (CURRENCY_CONFIG[code] ?? null) : null;
  const locale = cfg ? cfg.locale : DEFAULT_LOCALE;

  const formatted = new Intl.NumberFormat(locale, {
    minimumFractionDigits: opts?.minFractionDigits ?? 0,
    maximumFractionDigits: opts?.maxFractionDigits ?? 2,
  }).format(n);

  if (!code) return formatted;

  const symbol = cfg ? cfg.symbol : code;
  return `${formatted} ${symbol}`;
}

/**
 * Форматирует число без символа валюты (пробел-группировка ru-RU).
 * Null-safe: null/undefined/NaN → "—".
 *
 * Используй когда символ валюты выводится отдельно (например в заголовке колонки).
 *
 * @example
 * formatAmount(4000)        // "4 000"
 * formatAmount(4000.5)      // "4 000,5"
 * formatAmount(null)        // "—"
 */
export function formatAmount(
  amount: number | string | null | undefined,
  opts?: { maxFractionDigits?: number; minFractionDigits?: number },
): string {
  if (amount === null || amount === undefined || amount === "") return "—";
  const n = typeof amount === "string" ? parseFloat(amount) : amount;
  if (isNaN(n)) return "—";
  return new Intl.NumberFormat("ru-RU", {
    minimumFractionDigits: opts?.minFractionDigits ?? 0,
    maximumFractionDigits: opts?.maxFractionDigits ?? 2,
  }).format(n);
}

// ---------------------------------------------------------------------------
// Относительное время
// ---------------------------------------------------------------------------

/** ISO → "только что" / "5 мин назад" / "3 ч назад" / "вчера" / "2 дн назад" / "12 мая". */
export function formatRelativeTime(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return "";
  const now = new Date();
  const diffMs = now.getTime() - d.getTime();
  const diffMin = Math.floor(diffMs / 60000);
  if (diffMin < 1) return "только что";
  if (diffMin < 60) return `${diffMin} мин назад`;
  const diffH = Math.floor(diffMin / 60);
  if (diffH < 24) return `${diffH} ч назад`;
  const diffD = Math.floor(diffH / 24);
  if (diffD === 1) return "вчера";
  if (diffD < 7) return `${diffD} дн назад`;
  return d.toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
}

// ---------------------------------------------------------------------------
// Легаси — сохранены для обратной совместимости
// ---------------------------------------------------------------------------

/**
 * @deprecated Используй formatCurrency(value, "RUB") вместо этой функции.
 * Оставлена для обратной совместимости.
 * Целое число с пробелами как разделителями тысяч + знак валюты ₽.
 */
export function formatCurrencyRu(value: number): string {
  if (!isFinite(value)) return "—";
  return new Intl.NumberFormat("ru-RU", { maximumFractionDigits: 0 }).format(value) + " ₽";
}

/** Целое или дробное число с пробелами как разделителями тысяч (без валюты). */
export function formatNumberRu(value: number, fractionDigits = 0): string {
  if (!isFinite(value)) return "—";
  return new Intl.NumberFormat("ru-RU", {
    maximumFractionDigits: fractionDigits,
    minimumFractionDigits: fractionDigits,
  }).format(value);
}

/**
 * Безопасный аналог `.toFixed()` — не падает на null/undefined.
 * Используй вместо прямого `value.toFixed(n)` везде, где value может
 * прийти из API как null/undefined несмотря на типы.
 */
export function safeToFixed(value: number | null | undefined, digits = 1): string {
  return (value ?? 0).toFixed(digits);
}

