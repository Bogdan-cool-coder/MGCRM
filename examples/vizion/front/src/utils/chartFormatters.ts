/**
 * Chart label / axis / tooltip formatters for widget ECharts cards.
 *
 * Shared between `WidgetChartCard.vue` (full dashboard chart) and
 * `WidgetPreviewCard.vue` (library mini-preview) so both read numbers, dates
 * and series labels the same way.
 *
 * The widget `/data` payload (`{labels, datasets}`) is raw:
 *   - monetary / count axes arrive as bare numbers (210000000, 50000000000)
 *   - temporal labels arrive as "YYYY-MM" or ISO timestamps
 *   - series are labelled with technical aliases ("total" / "value" / "cnt")
 *
 * These helpers turn that into sales-ready output. They are intentionally
 * Vue-agnostic (no reactivity inside) — callers resolve `locale` / `currency`
 * (e.g. via `useFormatter`'s active-company resolution) and pass them in.
 */
import type { WidgetConfigDto } from '@/api/types/widgets'
import type { LocalizedText } from '@/shared/types'

// ---------------------------------------------------------------------------
// Locale helpers
// ---------------------------------------------------------------------------

/** Map vue-i18n short codes ("ru" / "en") to BCP-47 tags for `Intl.*`. */
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

// Ordered large → small so the first match wins.
const ABBR_TIERS: AbbrTier[] = [
  { value: 1_000_000_000_000, ru: ' трлн', en: 'T' },
  { value: 1_000_000_000, ru: ' млрд', en: 'B' },
  { value: 1_000_000, ru: ' млн', en: 'M' },
  { value: 1_000, ru: ' тыс', en: 'K' },
]

/**
 * Compact abbreviation for axis labels: 210000000 → "210 млн",
 * 47622368177 → "47,6 млрд", 50000000000 → "50 млрд".
 *
 * Below 10 000 the value is returned with grouping separators and no suffix
 * (e.g. 8 432 → "8 432") — abbreviating small numbers hurts readability.
 * One decimal is kept only when it carries information ("47,6 млрд", not
 * "47,0 млрд").
 */
export const abbreviateNumber = (value: number, locale: string): string => {
  if (!Number.isFinite(value)) return ''
  const tag = toBcp47(locale)
  const ru = isRu(locale)
  const sign = value < 0 ? '-' : ''
  const abs = Math.abs(value)

  // Keep precise grouping for "small" magnitudes — abbreviation starts at 10k.
  if (abs < 10_000) {
    return sign + new Intl.NumberFormat(tag, { maximumFractionDigits: 0 }).format(abs)
  }

  const tier = ABBR_TIERS.find((t) => abs >= t.value)
  if (!tier) {
    return sign + new Intl.NumberFormat(tag, { maximumFractionDigits: 0 }).format(abs)
  }

  const scaled = abs / tier.value
  // Show one decimal only when it is non-zero after rounding.
  const rounded = Math.round(scaled * 10) / 10
  const fractionDigits = Number.isInteger(rounded) ? 0 : 1
  const num = new Intl.NumberFormat(tag, {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(rounded)

  return `${sign}${num}${ru ? tier.ru : tier.en}`
}

// ---------------------------------------------------------------------------
// Full number / currency (tooltips)
// ---------------------------------------------------------------------------

/** Resolve a narrow currency symbol ("₸", "₽", "$") for a given ISO code. */
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

/**
 * Full number with grouping separators — for tooltips. When `currency` is
 * given the narrow symbol is appended (suffix style, matching ru convention:
 * "47 622 368 177 ₸"). Non-finite → "".
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
  return `${num} ${currencySymbol(currency, locale)}`
}

/**
 * Abbreviated axis value with optional currency symbol:
 * 50000000000 (KZT) → "50 млрд ₸". Used for the cartesian yAxis labels.
 */
export const formatAxisValue = (
  value: number,
  locale: string,
  currency?: string | null,
): string => {
  if (!Number.isFinite(value)) return ''
  const abbr = abbreviateNumber(value, locale)
  if (!currency) return abbr
  return `${abbr} ${currencySymbol(currency, locale)}`
}

// ---------------------------------------------------------------------------
// Monetary detection (heuristic)
// ---------------------------------------------------------------------------

interface WidgetAggregate {
  field?: string
  fn?: string
  as?: string
}

// Field-name patterns that signal a money column (case-insensitive substring).
// Covers MacroData (deal_sum, estate_price, summa) + generic *_sum / *_price.
const MONEY_FIELD_PATTERNS = [
  'sum', // deal_sum, _sum, summa
  'summa',
  'price', // estate_price, _price
  'amount',
  'revenue',
  'cost',
  'payment',
  'paid',
  'debt',
  'balance',
  'выручк',
  'сумм',
  'цен',
  'деньг',
  'оплат',
  'долг',
]

// Aggregate functions that, applied to a money field, still yield money.
const MONEY_FNS = new Set(['sum', 'avg', 'min', 'max'])

const looksMonetary = (field: string | undefined): boolean => {
  if (!field) return false
  const f = field.toLowerCase()
  return MONEY_FIELD_PATTERNS.some((p) => f.includes(p))
}

/**
 * Heuristic: is this widget's primary value a money amount (→ currency symbol +
 * abbreviation) rather than a count?
 *
 * Strategy, in priority order:
 *  1. Find the aggregate whose `as` matches `chart.value_field` (the series the
 *     chart actually plots). If its `fn` is sum/avg/min/max AND its `field`
 *     name matches a money pattern → monetary.
 *  2. `count` aggregates are never monetary (whole-number cardinality).
 *  3. Fallback when value_field doesn't resolve to an aggregate: sniff the
 *     `value_field` / `label` strings themselves for money patterns.
 *
 * Conservative by design: an unrecognised widget is treated as a plain number
 * (abbreviated, no currency) — never wrongly stamped with a currency symbol.
 */
export const isMonetaryWidget = (config: WidgetConfigDto | null | undefined): boolean => {
  if (!config) return false

  const chart = config.chart as Record<string, unknown> | undefined
  const valueField = typeof chart?.value_field === 'string' ? chart.value_field : undefined
  const aggregates = Array.isArray(config.aggregates)
    ? (config.aggregates as WidgetAggregate[])
    : []

  // 1 — resolve the plotted aggregate via value_field → aggregate.as
  const plotted =
    aggregates.find((a) => a.as === valueField) ??
    (aggregates.length === 1 ? aggregates[0] : undefined)

  if (plotted) {
    const fn = (plotted.fn ?? '').toLowerCase()
    if (fn === 'count') return false
    if (MONEY_FNS.has(fn)) return looksMonetary(plotted.field)
    // Unknown fn — fall through to string sniffing.
  }

  // 3 — fallback: sniff the value_field alias itself ("revenue", "sum"...).
  // `cnt` / `count` aliases are explicitly non-monetary.
  if (valueField) {
    const vf = valueField.toLowerCase()
    if (vf === 'cnt' || vf === 'count') return false
    if (looksMonetary(valueField)) return true
  }

  return false
}

// ---------------------------------------------------------------------------
// Temporal axis labels
// ---------------------------------------------------------------------------

const RU_MONTHS_GENITIVE = [
  'января',
  'февраля',
  'марта',
  'апреля',
  'мая',
  'июня',
  'июля',
  'августа',
  'сентября',
  'октября',
  'ноября',
  'декабря',
]

const YEAR_MONTH_RE = /^(\d{4})-(\d{2})$/
const ISO_DATE_RE = /^\d{4}-\d{2}-\d{2}([T\s].*)?$/

/**
 * Format a single category-axis label.
 *  - "2026-05"        → "май 2026" (ru) / "May 2026" (en)
 *  - "2026-05-04..."  → "4 мая" (ru) / "May 4" (en)  [ISO date / timestamp]
 *  - anything else    → returned unchanged (manager name, complex name, …)
 *
 * Pure try-parse: if the string is not a recognised date/period shape it is a
 * plain category and passes through verbatim.
 */
export const formatTemporalLabel = (label: string, locale: string): string => {
  if (typeof label !== 'string' || label.length === 0) return label
  const tag = toBcp47(locale)
  const ru = isRu(locale)

  // YYYY-MM period bucket.
  const ym = YEAR_MONTH_RE.exec(label)
  if (ym) {
    const year = Number(ym[1])
    const monthIndex = Number(ym[2]) - 1
    if (monthIndex < 0 || monthIndex > 11) return label
    // Use a mid-month date to avoid TZ edge rollovers; only month + year shown.
    const date = new Date(Date.UTC(year, monthIndex, 15))
    return new Intl.DateTimeFormat(tag, {
      month: 'long',
      year: 'numeric',
      timeZone: 'UTC',
    }).format(date)
  }

  // ISO date / full timestamp → "4 мая" / "May 4".
  if (ISO_DATE_RE.test(label)) {
    const date = new Date(label)
    if (!Number.isNaN(date.getTime())) {
      if (ru) {
        // Intl ru day-month renders "4 мая." with a trailing dot in some
        // engines; build it explicitly for a clean genitive form.
        const day = date.getUTCDate()
        const month = RU_MONTHS_GENITIVE[date.getUTCMonth()]
        return `${day} ${month}`
      }
      return new Intl.DateTimeFormat(tag, {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
      }).format(date)
    }
  }

  return label
}

// ---------------------------------------------------------------------------
// Series label
// ---------------------------------------------------------------------------

// Technical aliases the backend uses for the single aggregate series.
const SUM_ALIASES = new Set(['total', 'value', 'sum', 'val', 'amount'])
const COUNT_ALIASES = new Set(['cnt', 'count', 'qty', 'n'])

/**
 * Resolve a human-readable series name from a raw dataset label.
 *
 * Priority:
 *  1. `config.chart.label` — author-supplied human name ("Выручка", "Сделок").
 *  2. Technical alias map: total/value/sum → "Сумма"/"Total",
 *     cnt/count → "Количество"/"Count".
 *  3. The raw label unchanged (already meaningful, e.g. a multi-series name).
 *
 * Multi-series widgets keep their own labels (the alias map only fires on
 * recognised technical aliases), so this never clobbers genuine names.
 *
 * `rawLabel` is a `LocalizedText` because the backend passes the widget
 * config `chart.label` through verbatim into the dataset label — so it can
 * arrive either as a technical alias string or as a `{ru, en}` object.
 */
const pickLocalized = (value: Record<string, string>, ru: boolean): string =>
  value[ru ? 'ru' : 'en'] ?? value.en ?? Object.values(value)[0] ?? ''

export const resolveSeriesLabel = (
  rawLabel: LocalizedText,
  config: WidgetConfigDto | null | undefined,
  locale: string,
): string => {
  const ru = isRu(locale)

  const chart = config?.chart as Record<string, unknown> | undefined
  const chartLabel = chart?.label
  if (typeof chartLabel === 'string' && chartLabel.trim().length > 0) {
    return chartLabel
  }
  if (chartLabel && typeof chartLabel === 'object') {
    const picked = pickLocalized(chartLabel as Record<string, string>, ru)
    if (picked) return picked
  }

  // Raw label arrived as a localized object — no alias mapping applies.
  if (typeof rawLabel !== 'string') {
    return pickLocalized(rawLabel, ru)
  }

  const key = rawLabel.trim().toLowerCase()
  if (SUM_ALIASES.has(key)) return ru ? 'Сумма' : 'Total'
  if (COUNT_ALIASES.has(key)) return ru ? 'Количество' : 'Count'

  return rawLabel
}
