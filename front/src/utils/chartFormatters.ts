/**
 * Chart label / axis / tooltip formatters for MACRO CRM dashboard widgets.
 *
 * All monetary values from the API are integers (kopecks).
 * Use `formatMoney(kopecks)` — never pass float ruble amounts.
 *
 * Vue-agnostic (no reactivity inside) — callers pass `locale` / `currency`.
 */

// ---------------------------------------------------------------------------
// Locale helpers
// ---------------------------------------------------------------------------

const toBcp47 = (locale: string | undefined): string => {
  if (!locale) return 'ru-RU'
  if (locale.includes('-')) return locale
  if (locale === 'ru') return 'ru-RU'
  if (locale === 'en') return 'en-US'
  return locale
}

const isRu = (locale: string): boolean => toBcp47(locale).toLowerCase().startsWith('ru')

// ---------------------------------------------------------------------------
// Number abbreviation (axis labels)
// ---------------------------------------------------------------------------

interface AbbrTier {
  value: number
  ru: string
  en: string
}

const ABBR_TIERS: AbbrTier[] = [
  { value: 1_000_000_000_000, ru: ' трлн', en: 'T' },
  { value: 1_000_000_000, ru: ' млрд', en: 'B' },
  { value: 1_000_000, ru: ' млн', en: 'M' },
  { value: 1_000, ru: ' тыс', en: 'K' },
]

/**
 * Compact abbreviation: 210000000 → "210 млн", 47622368177 → "47,6 млрд".
 * Below 10 000 returns grouped digits without suffix.
 */
export const abbreviateNumber = (value: number, locale: string): string => {
  if (!Number.isFinite(value)) return ''
  const tag = toBcp47(locale)
  const ru = isRu(locale)
  const sign = value < 0 ? '-' : ''
  const abs = Math.abs(value)

  if (abs < 10_000) {
    return sign + new Intl.NumberFormat(tag, { maximumFractionDigits: 0 }).format(abs)
  }

  const tier = ABBR_TIERS.find((t) => abs >= t.value)
  if (!tier) {
    return sign + new Intl.NumberFormat(tag, { maximumFractionDigits: 0 }).format(abs)
  }

  const scaled = abs / tier.value
  const rounded = Math.round(scaled * 10) / 10
  const fractionDigits = Number.isInteger(rounded) ? 0 : 1
  const num = new Intl.NumberFormat(tag, {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(rounded)

  return `${sign}${num}${ru ? tier.ru : tier.en}`
}

// ---------------------------------------------------------------------------
// Currency symbol
// ---------------------------------------------------------------------------

const currencySymbol = (currency: string, locale: string): string => {
  try {
    const parts = new Intl.NumberFormat(toBcp47(locale), {
      style: 'currency',
      currency,
      currencyDisplay: 'narrowSymbol',
      maximumFractionDigits: 0,
    }).formatToParts(0)
    return parts.find((p) => p.type === 'currency')?.value ?? currency
  } catch {
    return currency
  }
}

// ---------------------------------------------------------------------------
// Full number / currency (tooltips)
// ---------------------------------------------------------------------------

/**
 * Full number with grouping separators for tooltips.
 * When `currency` is given the narrow symbol is appended: "47 622 368 ₽".
 */
export const formatFullNumber = (
  value: number,
  locale: string,
  currency?: string | null,
): string => {
  if (!Number.isFinite(value)) return ''
  const tag = toBcp47(locale)
  const num = new Intl.NumberFormat(tag, { maximumFractionDigits: 0 }).format(value)
  if (!currency) return num
  return `${num} ${currencySymbol(currency, locale)}`
}

/**
 * Abbreviated axis value with optional currency: 50000000000 (RUB) → "50 млрд ₽".
 */
export const formatAxisValue = (
  value: number,
  locale: string,
  currency?: string | null,
): string => {
  if (!Number.isFinite(value)) return ''
  const abbr = abbreviateNumber(value, locale)
  if (!currency) return abbr
  return `${abbr} ${currencySymbol(currency, locale)}`
}

// ---------------------------------------------------------------------------
// Monetary (kopecks → human-readable)
// ---------------------------------------------------------------------------

/**
 * Format kopecks (integer) into an abbreviated human-readable money string.
 *
 * `formatMoney(158000000, 'ru', 'RUB')` → "1,58 млн ₽"
 * `formatMoney(0)` → "0"
 *
 * NEVER pass float ruble amounts — always integers (kopecks).
 */
export const formatMoney = (
  kopecks: number,
  locale = 'ru',
  currency = 'RUB',
): string => {
  if (!Number.isFinite(kopecks)) return ''
  const rubles = Math.round(kopecks) / 100
  return formatAxisValue(rubles, locale, currency)
}

// ---------------------------------------------------------------------------
// Trend percentage
// ---------------------------------------------------------------------------

/**
 * Format a trend percentage for KPI badges.
 *
 * `formatTrendPct(12.5)` → `{ text: '+12.5%', positive: true }`
 * `formatTrendPct(-3.1)` → `{ text: '−3.1%', positive: false }`
 * `formatTrendPct(null)` → `{ text: '—', positive: null }`
 */
export const formatTrendPct = (
  pct: number | null,
): { text: string; positive: boolean | null } => {
  if (pct === null || !Number.isFinite(pct)) return { text: '—', positive: null }
  const sign = pct > 0 ? '+' : ''
  return {
    text: `${sign}${pct.toFixed(1)}%`,
    positive: pct > 0 ? true : pct < 0 ? false : null,
  }
}
