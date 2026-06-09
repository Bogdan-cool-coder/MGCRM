/**
 * Static lists for the company settings form (currency + timezone selects).
 *
 * Backend validation:
 *  - `currency_code`: regex `/^[A-Z]{3}$/` (ISO 4217). The list below covers
 *    the currencies actually used by our customers; the user can still type
 *    any other 3-letter code via the "Other" custom-input fallback.
 *  - `timezone`: any IANA identifier accepted by PHP's
 *    `DateTimeZone::listIdentifiers()`. We try `Intl.supportedValuesOf`
 *    (Chrome 99+, Safari 15.4+, Node 18+) and fall back to a curated list
 *    of ~50 zones that matter for MACRO-region developers.
 */

export interface SelectChoice {
  value: string
  label: string
}

export const CURRENCY_CODE_PATTERN = /^[A-Z]{3}$/

/**
 * Currencies that show up in real customer companies (RU/CIS + a few global
 * ones). Ordered by relevance, not alphabetically — RUB first because most
 * customers are RU-based.
 */
export const COMMON_CURRENCIES: ReadonlyArray<SelectChoice> = Object.freeze([
  { value: 'RUB', label: 'RUB · ₽ Russian rouble' },
  { value: 'KZT', label: 'KZT · ₸ Kazakhstani tenge' },
  { value: 'UZS', label: 'UZS · soʻm Uzbekistani soʻm' },
  { value: 'AED', label: 'AED · د.إ UAE dirham' },
  { value: 'USD', label: 'USD · $ US dollar' },
  { value: 'EUR', label: 'EUR · € Euro' },
  { value: 'TRY', label: 'TRY · ₺ Turkish lira' },
  { value: 'CNY', label: 'CNY · ¥ Chinese yuan' },
])

/**
 * Fallback list when `Intl.supportedValuesOf('timeZone')` is not available
 * (older browsers / weird environments). Curated for MACRO customers —
 * covers RU regions, CIS, Middle East, and a handful of global anchors.
 */
const FALLBACK_TIMEZONES: ReadonlyArray<string> = Object.freeze([
  'UTC',
  'Europe/Moscow',
  'Europe/Kaliningrad',
  'Europe/Samara',
  'Europe/Kyiv',
  'Europe/Minsk',
  'Europe/Istanbul',
  'Europe/London',
  'Europe/Berlin',
  'Europe/Paris',
  'Europe/Madrid',
  'Europe/Rome',
  'Europe/Amsterdam',
  'Europe/Warsaw',
  'Europe/Helsinki',
  'Europe/Athens',
  'Asia/Almaty',
  'Asia/Aqtau',
  'Asia/Aqtobe',
  'Asia/Tashkent',
  'Asia/Samarkand',
  'Asia/Bishkek',
  'Asia/Dushanbe',
  'Asia/Ashgabat',
  'Asia/Baku',
  'Asia/Yerevan',
  'Asia/Tbilisi',
  'Asia/Dubai',
  'Asia/Qatar',
  'Asia/Riyadh',
  'Asia/Tehran',
  'Asia/Karachi',
  'Asia/Kolkata',
  'Asia/Yekaterinburg',
  'Asia/Omsk',
  'Asia/Novosibirsk',
  'Asia/Krasnoyarsk',
  'Asia/Irkutsk',
  'Asia/Yakutsk',
  'Asia/Vladivostok',
  'Asia/Magadan',
  'Asia/Kamchatka',
  'Asia/Shanghai',
  'Asia/Hong_Kong',
  'Asia/Singapore',
  'Asia/Tokyo',
  'Asia/Seoul',
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Los_Angeles',
  'America/Toronto',
  'America/Sao_Paulo',
  'Australia/Sydney',
])

interface SupportedValuesOfIntl {
  supportedValuesOf?: (key: 'timeZone') => string[]
}

/**
 * Returns a sorted list of IANA timezone identifiers suitable for a
 * Select dropdown. Prefers `Intl.supportedValuesOf('timeZone')` (full
 * IANA list — ~430 zones), falls back to the curated list above.
 *
 * Pure function — safe to call in setup. Result is stable per call, so
 * callers may want to memoise with `computed(() => listTimezoneOptions())`.
 */
export const listTimezoneOptions = (): SelectChoice[] => {
  const intl = Intl as unknown as SupportedValuesOfIntl
  const zones = typeof intl.supportedValuesOf === 'function'
    ? intl.supportedValuesOf('timeZone')
    : [...FALLBACK_TIMEZONES]

  return zones
    .slice()
    .sort((a, b) => a.localeCompare(b))
    .map((value) => ({ value, label: value }))
}
