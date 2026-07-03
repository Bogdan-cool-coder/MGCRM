/**
 * Motivation-card presentation helpers.
 *
 * Money everywhere in МК is integer kopecks (backend contract §2). These helpers
 * wrap the shared `utils/currency` / `chartFormatters` for the МК-specific
 * display rules from SPEC §Форматирование чисел:
 *   - full money: `1 200 000 ₽` (space separator, symbol after) → `formatCurrency`
 *   - hero abbreviation: `≈ 40,0 млн UZS`                        → `formatMkAbbrev`
 *   - percent: whole if integer (`100%`), one decimal otherwise  → `formatMkPct`
 *   - `pct === null` → «—»
 */
import { formatCurrency, getCurrencySymbol } from '@/utils/currency'
import { abbreviateNumber } from '@/utils/chartFormatters'
import type { PctBadge } from '@/entities/motivation'

export const MK_EMPTY = '—'

/** Full money: `1 200 000 ₽`. Re-export of the shared formatter for МК sites. */
export const formatMkMoney = (kopecks: number, currency: string): string =>
  formatCurrency(kopecks, currency)

/**
 * Hero abbreviation with `≈` prefix and currency code: `≈ 40,0 млн UZS`.
 * Only meaningful for large sums; falls through to grouped digits below 10 000.
 */
export const formatMkAbbrev = (
  kopecks: number,
  currency: string,
  locale = 'ru',
): string => {
  const units = Math.round(kopecks / 100)
  const abbr = abbreviateNumber(units, locale)
  const symbol = getCurrencySymbol(currency)
  return `≈ ${abbr} ${symbol}`
}

/** Percent per SPEC: whole if integer, one decimal otherwise; `null` → «—». */
export const formatMkPct = (pct: number | null): string => {
  if (pct === null || !Number.isFinite(pct)) return MK_EMPTY
  return Number.isInteger(pct) ? `${pct}%` : `${pct.toFixed(1)}%`
}

/** PrimeVue Tag severity from a badge value (`none` → no colour). */
export const badgeToSeverity = (
  badge: PctBadge,
): 'success' | 'warn' | 'danger' | 'secondary' => {
  switch (badge) {
    case 'success':
      return 'success'
    case 'warning':
      return 'warn'
    case 'danger':
      return 'danger'
    default:
      return 'secondary'
  }
}

/** Derive a badge from a raw pct against the ≥100 / 80–99 / <80 thresholds. */
export const pctToBadge = (pct: number | null): PctBadge => {
  if (pct === null || !Number.isFinite(pct)) return 'none'
  if (pct >= 100) return 'success'
  if (pct >= 80) return 'warning'
  return 'danger'
}

/** Exchange-rate age: stale when older than 7 days (SPEC §5). */
export const isRatesStale = (isoDate: string): boolean => {
  const then = new Date(isoDate).getTime()
  if (!Number.isFinite(then)) return false
  const days = (Date.now() - then) / 86_400_000
  return days > 7
}
