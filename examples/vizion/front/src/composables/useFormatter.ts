import { toValue, type MaybeRef } from 'vue'
import { useI18n } from 'vue-i18n'
import Decimal from 'decimal.js'
import { useCompaniesStore } from '@/stores/companies'

export type FormatType = 'number' | 'money' | 'area' | 'percent' | 'date' | 'datetime' | 'string' | 'link'

interface FormatOptions {
  type?: FormatType
  locale?: MaybeRef<string>
  /** Override active-company currency for this call (ISO 4217, e.g. "USD"). */
  currency?: string | null
  /** Override active-company timezone for this call (IANA tz, e.g. "Europe/Moscow"). */
  timezone?: string | null
  /**
   * Fixed number of fraction digits for `number` cells (min = max), e.g. a
   * report column config `format: '0.00'` resolves to `decimals: 2`. Preserves
   * trailing zeros. Only honoured by the plain-number branch — money / date /
   * percent / area keep their own precision rules. When omitted, numbers use
   * the runtime default precision (existing behaviour, no regression).
   */
  decimals?: number
}

interface FormatCurrencyOptions {
  currency?: string | null
  locale?: MaybeRef<string>
}

interface FormatDateOptions {
  timezone?: string | null
  withTime?: boolean
  locale?: MaybeRef<string>
}

const isDateInput = (value: unknown): value is string | number | Date => {
  return typeof value === 'string' || typeof value === 'number' || value instanceof Date
}

/**
 * Map vue-i18n short locale codes ("ru" / "en") to BCP-47 tags expected by
 * `Intl.NumberFormat` / `Intl.DateTimeFormat`. Anything that already looks
 * like a BCP-47 tag (contains a hyphen) is passed through.
 */
const toBcp47 = (locale: string | undefined): string => {
  if (!locale) return 'ru-RU'
  if (locale.includes('-')) return locale
  if (locale === 'ru') return 'ru-RU'
  if (locale === 'en') return 'en-US'
  return locale
}

export const useFormatter = () => {
  const companiesStore = useCompaniesStore()
  // useI18n() must be called inside a setup or composable scope. All callers
  // of useFormatter() already run in such a scope (composables / setup).
  const { locale: i18nLocale } = useI18n()

  /**
   * Resolve locale to BCP-47 form. Priority: explicit override > vue-i18n.
   * Both reads stay reactive — vue-i18n locale is a Ref, store getter is
   * a reactive getter, so callers using the returned functions inside a
   * computed/template automatically re-run on change.
   */
  const resolveLocale = (locale?: MaybeRef<string>) => {
    if (locale !== undefined) {
      return toBcp47(toValue(locale))
    }
    return toBcp47(i18nLocale.value)
  }

  /**
   * Resolve currency code. Priority: explicit arg > active-company > 'RUB'.
   * Reads `companiesStore.getCurrentCompany` getter so the returned value
   * stays reactive when the user switches active company.
   */
  const resolveCurrency = (override?: string | null): string => {
    if (override) return override
    return companiesStore.getCurrentCompany?.currency_code ?? 'RUB'
  }

  /**
   * Resolve timezone. Priority: explicit arg > active-company > 'UTC'.
   * Same reactivity story as resolveCurrency.
   */
  const resolveTimezone = (override?: string | null): string => {
    if (override) return override
    return companiesStore.getCurrentCompany?.timezone ?? 'UTC'
  }

  const isNumeric = (val: unknown) => {
    if (typeof val === 'number') return true
    if (typeof val === 'string') {
      return /^[\d\s.,-]+$/.test(val)
    }
    return false
  }

  /**
   * Currency formatter — see top-level `formatCurrency` doc-comment on the
   * returned object below for behaviour. Uses `narrowSymbol` so wide
   * currencies (KZT → ₸, UZS → сўм) don't blow up column width.
   */
  const formatCurrency = (
    value: number | string | null | undefined,
    options: FormatCurrencyOptions = {},
  ): string => {
    if (value === null || value === undefined || value === '') return ''
    const num = typeof value === 'number' ? value : Number(value)
    if (!Number.isFinite(num)) return ''

    const localeTag = resolveLocale(options.locale)
    const currency = resolveCurrency(options.currency)

    try {
      return new Intl.NumberFormat(localeTag, {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
        currencyDisplay: 'narrowSymbol',
      }).format(num)
    } catch {
      // Unknown currency code (e.g. typo) — degrade to plain number with no symbol.
      return new Intl.NumberFormat(localeTag, { maximumFractionDigits: 0 }).format(num)
    }
  }

  /**
   * Resolve the bare currency *symbol* (not a formatted amount) for the active
   * company — e.g. `KZT` → `₸`, `AED` → `AED`, `USD` → `$`. Used when a money
   * column hides the per-cell currency and instead surfaces the symbol once, in
   * the column header (`currency_in_header` flag). Same resolution + reactivity
   * story as `formatCurrency`: priority explicit override > active company >
   * `RUB`, and reading `companiesStore.getCurrentCompany` keeps callers reactive
   * to a company switch.
   *
   * We derive the symbol by formatting a throwaway `0` with `currencyDisplay:
   * 'narrowSymbol'` and reading the `currency` part — this matches exactly what
   * `formatCurrency` renders in body/footer cells, so the header symbol can
   * never drift from the cell symbol. Falls back to the ISO code itself if the
   * runtime can't isolate a currency part.
   */
  const formatCurrencySymbol = (override?: string | null): string => {
    const localeTag = resolveLocale()
    const currency = resolveCurrency(override)
    try {
      const parts = new Intl.NumberFormat(localeTag, {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
        currencyDisplay: 'narrowSymbol',
      }).formatToParts(0)
      const symbol = parts.find((p) => p.type === 'currency')?.value
      return symbol && symbol.trim() !== '' ? symbol : currency
    } catch {
      // Unknown currency code — show the ISO code as the symbol.
      return currency
    }
  }

  /**
   * Date formatter producing a deterministic `dd.mm.yyyy` (+ optional
   * `HH:mm`) regardless of the active locale. We do NOT rely on the locale's
   * own date ordering (en-US would yield `mm/dd/yyyy`) — reports always show
   * `dd.mm.yyyy`. Timezone conversion is still honoured via
   * `Intl.DateTimeFormat` + `formatToParts`: ISO strings from MacroData arrive
   * in UTC (Z-suffix) and are converted to the requested zone before the
   * parts are reassembled into the fixed order.
   */
  const formatDate = (
    value: string | number | Date | null | undefined,
    options: FormatDateOptions = {},
  ): string => {
    if (value === null || value === undefined || value === '') return ''
    const date = value instanceof Date ? value : new Date(value)
    if (Number.isNaN(date.getTime())) return ''

    const timeZone = resolveTimezone(options.timezone)

    const partsOptions: Intl.DateTimeFormatOptions = {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }

    if (options.withTime) {
      partsOptions.hour = '2-digit'
      partsOptions.minute = '2-digit'
      partsOptions.hour12 = false
    }

    // Use a fixed `en-GB` base (2-digit day/month, 24h time) only as a stable
    // numeric source — we read the parts and reassemble manually so the final
    // string order never depends on the active UI locale.
    const buildParts = (tz: string): Intl.DateTimeFormatPart[] =>
      new Intl.DateTimeFormat('en-GB', { ...partsOptions, timeZone: tz }).formatToParts(date)

    let parts: Intl.DateTimeFormatPart[]
    try {
      parts = buildParts(timeZone)
    } catch {
      // Invalid timezone — retry with UTC so we still render something.
      parts = buildParts('UTC')
    }

    const pick = (t: Intl.DateTimeFormatPartTypes): string =>
      parts.find((p) => p.type === t)?.value ?? ''

    const dateStr = `${pick('day')}.${pick('month')}.${pick('year')}`

    if (!options.withTime) return dateStr

    const hour = pick('hour')
    const minute = pick('minute')
    return hour !== '' ? `${dateStr} ${hour}:${minute}` : dateStr
  }

  const formatNumber = (
    value: number,
    type: FormatType,
    locale: string,
    options: FormatOptions,
  ): string => {
    switch (type) {
      case 'money':
        // Currency symbol comes from active-company `currency_code`
        // (resolved inside formatCurrency) — falls back to RUB when the
        // store is empty / null. Caller can override via `options.currency`.
        return formatCurrency(value, { currency: options.currency, locale: options.locale })

      case 'area':
        return (Math.round(value * 10) / 10).toLocaleString(locale)

      case 'percent':
        return `${(value * 100).toFixed(1)}%`

      default:
        // Fixed-precision number when the caller passed `decimals` (from a
        // column's `format` pattern, e.g. '0.00' → 2). min = max so trailing
        // zeros are preserved. Without `decimals`, fall through to the runtime
        // default precision — unchanged behaviour for the vast majority of
        // number columns that declare no `format`.
        if (options.decimals !== undefined) {
          return value.toLocaleString(locale, {
            minimumFractionDigits: options.decimals,
            maximumFractionDigits: options.decimals,
          })
        }
        return value.toLocaleString(locale)
    }
  }

  const formatDateInternal = (
    value: string | number | Date,
    type: FormatType,
    options: FormatOptions,
  ): string => {
    return formatDate(value, {
      timezone: options.timezone,
      withTime: type === 'datetime',
      locale: options.locale,
    })
  }

  const format = (value: unknown, options: FormatOptions = {}): string | number => {
    if (value === null || value === undefined) return ''

    const { type = 'string', locale } = options
    const resolvedLocale = resolveLocale(locale)

    // Date types are resolved before the numeric branch: an ISO date such as
    // "2026-05-12" matches `isNumeric` (only digits + dashes) and would
    // otherwise be mangled into a number. Handle date/datetime up front.
    if ((type === 'date' || type === 'datetime') && isDateInput(value)) {
      return formatDateInternal(value, type, options)
    }

    if (isNumeric(value)) {
      const strValue = String(value).replace(/\s/g, '').replace(',', '.')
      // Skip decimal formatting for non-numeric strings (e.g., ranges like "3-6")
      if (!/^-?\d*\.?\d*$/.test(strValue)) {
        return String(value)
      }
      const num = new Decimal(strValue).toNumber()

      return formatNumber(num, type, resolvedLocale, options)
    }

    return String(value)
  }

  return {
    format,
    /**
     * Format a numeric value with the active company's currency code.
     * Pass `currency` to override per-call. Pass `null`/empty value → ''.
     */
    formatCurrency,
    /**
     * Resolve the bare currency symbol (not an amount) for the active company.
     * Used for the `currency_in_header` money-column header suffix.
     */
    formatCurrencySymbol,
    /**
     * Format a date with the active company's timezone (or `UTC` fallback).
     * Pass `withTime: true` for `HH:mm`. Pass `timezone` to override per-call.
     */
    formatDate,
  }
}
