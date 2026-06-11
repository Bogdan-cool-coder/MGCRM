/**
 * Currency utilities for Catalog module.
 *
 * IMPORTANT: Backend stores all monetary values in kopecks (integer).
 * These helpers convert for display and back to kopecks for API requests.
 */

const CURRENCY_SYMBOLS: Record<string, string> = {
  KZT: '₸',
  RUB: '₽',
  USD: '$',
  EUR: '€',
  UZS: 'сум',
  AED: 'AED',
}

/** Whitelist of supported currencies, in display order */
export const CURRENCY_WHITELIST = ['KZT', 'RUB', 'USD', 'UZS', 'AED', 'EUR'] as const
export type SupportedCurrency = (typeof CURRENCY_WHITELIST)[number]

/**
 * Format kopecks amount to human-readable string.
 *
 * @example
 * formatCurrency(15000000, 'KZT') → "150 000 ₸"
 * formatCurrency(3500000, 'RUB')  → "35 000 ₽"
 * formatCurrency(40000, 'USD')    → "400 $"
 * formatCurrency(150000000, 'UZS')→ "1 500 000 сум"
 */
export function formatCurrency(kopecks: number, currencyCode: string): string {
  const units = kopecks / 100
  const symbol = CURRENCY_SYMBOLS[currencyCode] ?? currencyCode

  // Format with space as thousands separator
  const formatted = units.toLocaleString('ru-RU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
    useGrouping: true,
  })

  // Replace narrow no-break space / regular space from toLocaleString with plain space
  const normalized = formatted.replace(/\s/g, ' ')

  return `${normalized} ${symbol}`
}

/**
 * Convert display units to kopecks (integer, Math.round).
 *
 * @example toKopecks(150000) → 15000000
 */
export function toKopecks(units: number): number {
  return Math.round(units * 100)
}

/**
 * Convert kopecks to display units.
 *
 * @example fromKopecks(15000000) → 150000
 */
export function fromKopecks(kopecks: number): number {
  return kopecks / 100
}

/**
 * Get just the numeric symbol for a currency code.
 */
export function getCurrencySymbol(currencyCode: string): string {
  return CURRENCY_SYMBOLS[currencyCode] ?? currencyCode
}
