import { computed, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { FormattedReportRow, ReportItem, ReportTableCellValue, ReportTableRow } from '@/entities/report'
import { useFormatter } from '@/composables/useFormatter'
import type { FormatType } from '@/composables/useFormatter'
import { useServices } from '@/services'
import { getLocalizedText } from '@/utils/localization'
import { useReportLink } from './useReportLink'
import type { ReportLabelLineDto } from '@/api/types/reports'

/**
 * Raw column-type literal for payment-schedule cells. Used as both a
 * `rawType` discriminator and a renderer guard — the template renders a
 * dedicated `PaymentScheduleCell` when this string matches. Lives here so
 * a backend rename can be tracked in one place rather than a string-grep
 * over `index.vue + composables`.
 */
export const PAYMENT_SCHEDULE_TYPE = 'payment_schedule' as const

/** Returns true when the column is rendered by `PaymentScheduleCell`. */
export const isPaymentScheduleColumn = (col: { rawType?: string }): boolean =>
  col.rawType === PAYMENT_SCHEDULE_TYPE

export type PresentationColumn = {
  /**
   * Stable, unique per-column identity. Several columns can share the same
   * `field` (e.g. `estateSells.estate_sell_id` reused for "Номер договора"
   * [label_field=agreement_number], "Номер объекта" [label_field=geo_flatnum]
   * and "ID объекта" [is_crm_id]). `field` is therefore NOT a usable map key —
   * keying anything by it collapses these columns into one (they'd all render
   * the last column's header + value). `_key` is the identity to use for column
   * ordering / visibility / drag / persistence and the `<Column>` v-for key.
   *
   * Shape: `${index}|${field}|${label_field ?? ''}`. The leading config index
   * guarantees uniqueness; field + label_field make it readable and give the
   * persisted-order back-compat mapping something to match against. Deterministic
   * for a given report config (config column order is stable) so a persisted
   * order replays identically across reloads.
   */
  _key: string
  field: string
  header: string
  sortable?: boolean
  type: FormatType
  /** Raw type string from the report config (preserved for special renderers) */
  rawType?: string
  link_template?: string
  /** True for the "CRM object ID" link column — renders an external-link icon. */
  is_crm_id?: boolean
  label_field?: string
  label_lines?: ReportLabelLineDto[]
  /** Fallback link label text when label_field is empty. Shown in muted style. */
  label_fallback?: string | Record<string, string>
  truncate?: 'first_word'
  badge?: Record<string, unknown>
  /** Display map for text cells: raw value → localized label. */
  options?: Record<string, string | { ru: string; en: string }>
  /**
   * Resolved measurement-unit suffix for the TOTALS row (e.g. "шт." / "м²"),
   * already localized for the current locale. `null` when the column config
   * declares no unit. Applied only in the footer total cell — body cells are
   * unaffected. Money columns leave this null (currency symbol comes from the
   * money formatter). See `formattedTotalsRow`.
   */
  unit?: string | null
  /**
   * True for money columns flagged `currency_in_header` in config. When set:
   *   - body cells render a bare grouped number (no currency symbol);
   *   - the `header` string already includes the resolved `, {symbol}{suffix}`
   *     (appended in `tableColumns` below — see `currencyHeaderSuffix`);
   *   - the footer total keeps the currency symbol (+ optional `currencySuffix`).
   * Only meaningful on money-typed columns (`type === 'money'`). See the
   * money-cell branch in `formattedTableData` and the totals branch in
   * `formattedTotalsRow`.
   */
  currencyInHeader?: boolean
  /**
   * Resolved per-unit suffix (e.g. "/м²") for a `currencyInHeader` money column,
   * already localized for the current locale. Appended after the currency symbol
   * in both the header and the totals cell. `null` when not configured.
   */
  currencySuffix?: string | null
  /**
   * Resolved column-header tooltip text for the current locale. `null` when
   * the report config did not provide a description for this column — the
   * template uses this nullity to decide whether to render the `?` icon
   * (see DEVELOPMENT_PLAN_CAPITALDATA §5). Empty string (e.g. localized
   * object missing the active locale and all fallbacks) is also treated as
   * "no description" — we normalize to `null` here so the template can use
   * a single truthiness check.
   */
  description?: string | null
  /**
   * Fixed number of fraction digits parsed from the column config's `format`
   * pattern (e.g. `'0.00'` → 2). `undefined` when the column declares no
   * `format` — the formatter then uses runtime-default precision (unchanged
   * behaviour). Applied only to number cells; money / date / percent ignore it.
   * See `parseFractionDigits`.
   */
  decimals?: number
}

/**
 * Parse the fraction-digit count from a column `format` pattern. Counts the
 * characters after the (last) decimal point — `'0.00'` → 2, `'0.000'` → 3,
 * `'#,##0.0'` → 1. Returns `undefined` for patterns with no decimal point
 * (`'0'`, `'#,##0'`) or for blank / non-string input, so callers can treat
 * "no format" and "integer format" the same way (runtime-default precision)
 * — we only override precision when the author explicitly asked for decimals.
 */
export const parseFractionDigits = (format: string | undefined): number | undefined => {
  if (typeof format !== 'string') return undefined
  const trimmed = format.trim()
  if (trimmed === '') return undefined
  const dotIndex = trimmed.lastIndexOf('.')
  if (dotIndex === -1) return undefined
  const decimals = trimmed.length - dotIndex - 1
  return decimals > 0 ? decimals : undefined
}

export type FooterCell = {
  /** Matches the owning column's `_key` (unique per column even when `field`
   * is shared). Used to align the footer row with the body columns after
   * reorder / hide — see `visibleFooterCells` in index.vue. */
  _key: string
  field: string
  footer: string
  isTotalsLabel?: boolean
  /** Set for payment_schedule columns so the caller can render a localised footer string */
  isPaymentSchedule?: boolean
  /** Raw paid_total from report.totals (only set when isPaymentSchedule is true) */
  paidTotal?: number | null
  /** Raw due_total from report.totals (only set when isPaymentSchedule is true) */
  dueTotal?: number | null
}

export type LinkRef = {
  href: string | null
  label: string
  /** True when label comes from label_fallback (not from label_field). Used for muted style. */
  isFallback?: boolean
}

/** Minimal column shape required to build a LinkRef. Accepts both
 * `PresentationColumn` (for flat rows) and the slimmer column subset
 * used by grouped-children rendering — anything carrying link_template +
 * label config. */
export interface LinkRefColumn {
  field: string
  link_template?: string
  label_field?: string
  label_lines?: ReportLabelLineDto[]
  label_fallback?: string | Record<string, string>
}

export interface ReportBadgeInfo {
  severity: string
  label: string
}

/**
 * Defensive guard: a grouped/drill-down row carries `group_key` + a
 * `group_meta` aggregate header. The grouped report *view* was removed —
 * the report page always renders a flat table. If the backend ever returns
 * grouped rows (e.g. a config still declares `group_by`), we filter them out
 * of the flat dataset rather than crashing or rendering a broken table.
 */
/**
 * Render a footer COUNT total as a bare integer string. Used for link / text
 * columns whose footer total is a row count (not a measured sum), so we omit
 * locale grouping and any currency word — "1293", not "1 293 ₸".
 * Non-numeric / null values render as an empty string.
 */
const formatCountTotal = (value: ReportTableCellValue): string => {
  if (value === null || value === undefined || value === '') return ''
  const num = typeof value === 'number' ? value : Number(String(value).replace(/\s/g, ''))
  if (!Number.isFinite(num)) return String(value)
  return String(Math.round(num))
}

/**
 * Build the stable, unique `_key` for a presentation column. The config index
 * guarantees uniqueness even when `field` + `label_field` collide; appending
 * field + label_field keeps the key human-readable and gives the
 * persisted-order back-compat mapping a value to match against.
 *
 * Deterministic for a given report config: the config column order is stable
 * across loads, so a persisted order built from these keys replays identically.
 */
export const buildColumnKey = (
  index: number,
  field: string,
  labelField: string | undefined,
): string => `${index}|${field}|${labelField ?? ''}`

const isGroupRow = (row: unknown): boolean => {
  return (
    typeof row === 'object' &&
    row !== null &&
    'group_key' in row &&
    'has_children' in row &&
    'group_meta' in row
  )
}

export const useReportPresentation = (
  report: Ref<ReportItem | null>,
  crmUrl: Ref<string | null | undefined>,
) => {
  const { reportService } = useServices()
  const { format, formatCurrencySymbol } = useFormatter()
  const { locale } = useI18n()
  const { resolveLink } = useReportLink()

  // Raw flat rows. The report page renders a flat table only — if the backend
  // returns grouped rows (group_key/group_meta), we drop them so the flat
  // table doesn't render aggregate-header rows as if they were data rows.
  const tableData = computed(() => {
    const rows = report.value?.rows

    if (rows && rows.length > 0) {
      const flatRows = (rows as ReportTableRow[]).filter((row) => !isGroupRow(row))
      if (flatRows.length > 0) return flatRows
    }

    if (!report.value?.chart) return []
    return reportService.extractTableData(report.value.chart)
  })

  const tableColumns = computed<PresentationColumn[]>(() => {
    if (report.value?.columns && report.value.columns.length > 0) {
      return report.value.columns.map((col, index) => {
        // Description: resolve LocalizedText → string for the current locale.
        // `getLocalizedText` already handles object lookup with fallbacks
        // (locale → en → first value). We normalize empty strings to null
        // so the template's truthiness check (`v-if="col.description"`)
        // suppresses the `?` icon for blank descriptions too — the icon
        // would otherwise render with an empty tooltip and confuse users.
        let description: string | null = null
        if (col.description != null) {
          const resolved = getLocalizedText(col.description, locale.value)
          description = resolved.trim() !== '' ? resolved : null
        }

        // Unit suffix for the totals row. Same localized-text resolution as
        // description: a plain string applies to all locales, an object is
        // looked up by the active locale (with en / first-value fallback).
        // Empty / blank resolves to null so the footer appends nothing.
        let unit: string | null = null
        if (col.unit != null) {
          const resolvedUnit = getLocalizedText(col.unit, locale.value)
          unit = resolvedUnit.trim() !== '' ? resolvedUnit : null
        }

        const resolvedType = resolveColumnType(col.type, col.field, col.value_type)

        // `currency_in_header` only applies to money columns — guard so a stray
        // flag on a text/number column is silently ignored (no symbol drift).
        const currencyInHeader = resolvedType === 'money' && col.currency_in_header === true

        // Per-unit suffix (e.g. "/м²") for currency_in_header columns. Resolved
        // for the active locale; null when absent.
        let currencySuffix: string | null = null
        if (currencyInHeader && col.currency_suffix != null) {
          const resolvedSuffix = getLocalizedText(col.currency_suffix, locale.value)
          currencySuffix = resolvedSuffix.trim() !== '' ? resolvedSuffix : null
        }

        // Build the header. For currency_in_header columns we append
        // `, {symbol}{suffix}` where the symbol is resolved DYNAMICALLY from the
        // active company's currency (AED for Buildera, ₸ for KZT companies) via
        // the same formatter that renders the totals symbol — so they can never
        // drift apart. The config header itself carries NO currency (report-author
        // writes "Стоимость", the front appends ", AED" / ", ₸").
        let header = getLocalizedText(col.header, locale.value)
        if (currencyInHeader) {
          const symbol = formatCurrencySymbol()
          header = `${header}, ${symbol}${currencySuffix ?? ''}`
        }

        return {
          _key: buildColumnKey(index, col.field, col.label_field),
          field: col.field,
          header,
          sortable: Boolean(col.sortable) && !col.expression,
          type: resolvedType,
          rawType: col.type,
          link_template: col.link_template,
          is_crm_id: col.is_crm_id,
          label_field: col.label_field,
          label_lines: col.label_lines,
          label_fallback: col.label_fallback,
          truncate: col.truncate,
          badge: col.badge,
          options: col.options,
          unit,
          currencyInHeader,
          currencySuffix,
          description,
          decimals: parseFractionDigits(col.format),
        }
      })
    }

    if (tableData.value.length === 0) return []

    const firstRow = tableData.value[0]
    if (!firstRow) return []

    return Object.keys(firstRow)
      .filter((key) => !key.startsWith('_badge_'))
      .map((key, index) => ({
        _key: buildColumnKey(index, key, undefined),
        field: key,
        header: key,
        type: 'string' as const,
      }))
  })

  /**
   * Resolve a text-cell value through the column's options map.
   * If the column has no options, or the raw value has no match, returns the raw value as-is.
   * Locale fallback: preferred locale → 'en' → first key value → raw string.
   */
  const resolveOptionLabel = (
    value: string | number | boolean | null,
    column: PresentationColumn | undefined,
  ): string | number | boolean | null => {
    if (!column?.options || value == null) return value
    const rawKey = String(value)
    const entry = column.options[rawKey]
    if (entry === undefined) return value
    if (typeof entry === 'string') return entry
    const currentLocale = locale.value
    return entry[currentLocale as 'ru' | 'en'] ?? entry['en'] ?? Object.values(entry)[0] ?? rawKey
  }

  const formattedTableData = computed<FormattedReportRow[]>(() => {
    const columnMap = Object.fromEntries(tableColumns.value.map((column) => [column.field, column]))

    return tableData.value.map((row) => {
      const result: FormattedReportRow = {}

      for (const key in row) {
        if (key.startsWith('_badge_')) continue
        const value = row[key]
        const column = columnMap[key]
        const resolved = resolveOptionLabel(value as string | number | boolean | null, column)

        // currency_in_header money cells render the bare grouped number (no
        // currency symbol) — the symbol lives in the header + totals only. We
        // format as a plain 'number' instead of 'money' for these body cells.
        const cellType: FormatType | undefined =
          column?.type === 'money' && column.currencyInHeader ? 'number' : column?.type

        // `decimals` (from the column's `format` pattern) is forwarded only for
        // plain-number cells. The formatter ignores it for money/date/percent,
        // but we additionally gate on the resolved cell type so a stray `format`
        // on a money column never leaks fixed-precision into the number branch.
        const decimals =
          cellType === 'number' || cellType === undefined ? column?.decimals : undefined

        result[key] = format(resolved as string | number | boolean | null, {
          type: cellType,
          decimals,
        })
      }

      return result
    })
  })

  const tableStateKey = computed(() =>
    tableColumns.value
      .map((column) => `${column._key}:${column.sortable ? 'sortable' : 'plain'}`)
      .join('|'),
  )

  const formattedTotalsRow = computed<FormattedReportRow | null>(() => {
    const totals = report.value?.totals
    if (!totals || Object.keys(totals).length === 0) return null

    const columnMap = Object.fromEntries(tableColumns.value.map((column) => [column.field, column]))
    const result: FormattedReportRow = {}

    // Append a column's measurement unit (e.g. "шт." / "м²") to a formatted
    // footer value. No-op when the column declares no unit or the formatted
    // value is empty. Money columns leave `unit` null (currency symbol comes
    // from the formatter), so this never doubles up a currency suffix.
    const withUnit = (formatted: string | number, column: PresentationColumn | undefined): string | number => {
      const unit = column?.unit
      if (!unit) return formatted
      const str = String(formatted)
      if (str === '') return formatted
      return `${str} ${unit}`
    }

    // Append a `currency_in_header` column's per-unit suffix (e.g. "/м²")
    // directly after the formatted money total — `19 840 000 AED` → `19 840 000
    // AED/м²`. No leading space (the suffix attaches to the symbol). No-op when
    // the column has no suffix or the formatted value is empty.
    const withCurrencySuffix = (
      formatted: string | number,
      column: PresentationColumn | undefined,
    ): string | number => {
      const suffix = column?.currencySuffix
      if (!suffix) return formatted
      const str = String(formatted)
      if (str === '') return formatted
      return `${str}${suffix}`
    }

    for (const key in totals) {
      const value = totals[key] as ReportTableCellValue
      const column = columnMap[key]

      // Footer total semantics by column type:
      //   - currency → money (symbol + grouping), via formatter
      //   - number / area → sum, formatted number (grouping / area rounding)
      //   - link / text / string → COUNT, rendered as a bare integer with no
      //     thousands separator and no currency word (e.g. "1293", "874").
      //     The backend sends a count for these (e.g. number of payments, of
      //     contract rows). We deliberately skip locale grouping here so the
      //     footer reads as a plain tally rather than a measured quantity.
      // A per-column `unit` suffix (config-driven) is appended last, so a
      // count total reads "75 шт." and an area sum reads "4134.3 м²".
      const isCountColumn =
        column?.type === 'link' || column?.type === 'string' || column == null
      if (isCountColumn) {
        result[key] = withUnit(formatCountTotal(value), column)
        continue
      }

      // Money total: keep the currency symbol (column type stays 'money' even
      // for currency_in_header), then append the per-unit suffix if any. The
      // body cells dropped the symbol, but the grand total surfaces it again.
      // Number totals honour the column's `format` precision so the footer sum
      // matches the body cells (e.g. an area-style 2-decimal column).
      const totalDecimals = column?.type === 'number' ? column?.decimals : undefined
      result[key] = withCurrencySuffix(
        withUnit(format(value, { type: column?.type, decimals: totalDecimals }), column),
        column,
      )
    }

    return result
  })

  const footerCells = computed<FooterCell[]>(() => {
    const rawTotals = report.value?.totals as Record<string, unknown> | undefined
    return tableColumns.value.map((column, index) => {
      if (isPaymentScheduleColumn(column)) {
        const paidTotal = rawTotals?.paid_total != null ? Number(rawTotals.paid_total) : null
        const dueTotal = rawTotals?.due_total != null ? Number(rawTotals.due_total) : null
        const hasTotals = paidTotal != null || dueTotal != null
        return {
          _key: column._key,
          field: column.field,
          // footer string is intentionally empty — the template uses getFooterLabel() for this cell
          footer: '',
          isTotalsLabel: index === 0 && !hasTotals,
          isPaymentSchedule: true,
          paidTotal,
          dueTotal,
        }
      }
      return {
        _key: column._key,
        field: column.field,
        footer:
          formattedTotalsRow.value?.[column.field] != null
            ? String(formattedTotalsRow.value[column.field])
            : '',
        isTotalsLabel: index === 0 && formattedTotalsRow.value?.[column.field] == null,
      }
    })
  })

  // Build a multi-line label from label_lines config for a single row.
  // If prefix/default are present: each line is "<prefix[locale]>: <row[field] || default[locale]>"
  // If prefix/default are absent (simple form): each line is just the raw row[field] value.
  // Lines are joined with "\n" so that white-space:pre-line renders them as separate lines.
  const buildLabelLinesLabel = (
    labelLines: ReportLabelLineDto[],
    row: ReportTableRow,
    currentLocale: string,
  ): string => {
    return labelLines
      .map((line) => {
        const raw = row[line.field]
        const rawStr = raw != null ? String(raw).trim() : ''

        if (line.prefix !== undefined) {
          // Full form: prefix + value (with optional default fallback)
          const prefix = line.prefix[currentLocale] ?? line.prefix['ru'] ?? ''
          const value =
            rawStr !== ''
              ? rawStr
              : (line.default?.[currentLocale] ?? line.default?.['ru'] ?? '')
          return `${prefix}: ${value}`
        }

        // Simple form: just the field value (or default if provided)
        return rawStr !== ''
          ? rawStr
          : (line.default?.[currentLocale] ?? line.default?.['ru'] ?? '')
      })
      .filter((line) => line !== '')
      .join('\n')
  }

  // Resolve label_fallback to a plain string for the current locale.
  const resolveLabelFallback = (
    fallback: string | Record<string, string> | undefined,
    currentLocale: string,
  ): string => {
    if (!fallback) return ''
    if (typeof fallback === 'string') return fallback
    return fallback[currentLocale] ?? fallback['ru'] ?? ''
  }

  /**
   * Single source of truth for resolving an `href` + `label` pair for a link
   * cell. Used by:
   *   - `linkRefs` computed (flat / non-grouped rows)
   *   - `index.vue` template via the returned `buildLinkRef` for grouped
   *     children rows.
   *
   * Centralised here so that label_lines / label_fallback / empty-label
   * handling stays consistent across flat and grouped renderers.
   */
  const buildLinkRef = (col: LinkRefColumn, row: ReportTableRow): LinkRef => {
    if (!col.link_template) return { href: null, label: '', isFallback: false }

    let label: string
    let isFallback = false

    if (col.label_lines && col.label_lines.length > 0) {
      label = buildLabelLinesLabel(col.label_lines, row, locale.value)
    } else {
      const rawLabel = row[col.label_field ?? col.field]
      label = rawLabel != null ? String(rawLabel).trim() : ''
    }

    if (label === '' && col.label_fallback) {
      label = resolveLabelFallback(col.label_fallback, locale.value)
      isFallback = label !== ''
    }

    const href =
      label !== ''
        ? resolveLink(
            col.link_template,
            row as Record<string, string | number | boolean | null>,
            crmUrl.value,
          )
        : null

    return { href, label, isFallback }
  }

  // Pre-computed href + label for each link-type column, indexed by column index then rowIndex.
  // Keyed by column index (not col.field) so that duplicate fields (e.g. two link columns
  // sharing the same field name for different labels) do not overwrite each other.
  const linkRefs = computed<Record<number, LinkRef[]>>(() => {
    const result: Record<number, LinkRef[]> = {}

    tableColumns.value.forEach((col, colIndex) => {
      if (col.type !== 'link' || !col.link_template) return
      result[colIndex] = tableData.value.map((row) => buildLinkRef(col, row))
    })

    return result
  })

  // Resolve badge info for a cell, returns null if no badge for this row/field
  const resolveBadge = (
    row: Record<string, unknown>,
    field: string,
  ): ReportBadgeInfo | null => {
    const badgeData = row[`_badge_${field}`]
    if (!badgeData || typeof badgeData !== 'object') return null
    const b = badgeData as { severity?: string; label?: unknown }
    if (!b.severity) return null
    const label =
      b.label && typeof b.label === 'object'
        ? getLocalizedText(b.label as Record<string, string>, locale.value)
        : typeof b.label === 'string'
          ? b.label
          : ''
    return { severity: b.severity, label }
  }

  return {
    tableColumns,
    tableData,
    formattedTableData,
    formattedTotalsRow,
    footerCells,
    tableStateKey,
    linkRefs,
    buildLinkRef,
    resolveBadge,
  }
}

const detectColumnType = (field: string): FormatType => {
  if (field.includes('sum') || field.includes('pay') || field.includes('income')) {
    return 'money'
  }

  if (field.includes('area')) {
    return 'area'
  }

  if (field.includes('date')) {
    return 'date'
  }

  return 'string'
}

const resolveColumnType = (
  type: string | undefined,
  field: string,
  valueType?: string,
): FormatType => {
  switch (type) {
    case 'number':
      return 'number'
    case 'currency':
      return 'money'
    case 'percent':
      return 'percent'
    case 'date':
      return 'date'
    case 'datetime':
      return 'datetime'
    case 'link':
      return 'link'
    case PAYMENT_SCHEDULE_TYPE:
      // Raw object cell — pass-through, rendered by PaymentScheduleCell
      return 'string'
    // Column types that carry no intrinsic display format — the backend
    // computes a raw value and the intended format travels in `value_type`
    // (e.g. 'number' → grouped digits w/o currency, 'currency' → money):
    //   - relation_aggregate = correlated subquery (raw number)
    //   - window_aggregate   = SQL window function (raw number)
    //   - custom_attribute   = MACRO EAV attribute stored as varchar; cell is
    //     a string, `value_type` hints how to format it (e.g. balcony/terrace
    //     area → 'number').
    // Re-resolve through `value_type`; fall back to the field-name heuristic
    // only when no hint is given (the recursive `default` branch). Without
    // this, money/number columns fell through to `detectColumnType(field)` and
    // were formatted inconsistently (some plain 'string', some heuristically
    // as 'money').
    case 'relation_aggregate':
    case 'window_aggregate':
    case 'custom_attribute':
      return resolveColumnType(valueType, field)
    default:
      return detectColumnType(field)
  }
}
