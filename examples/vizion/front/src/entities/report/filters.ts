import type { LocalizedText } from '@/shared/types'

export type ReportFilterType = 'date_range' | 'multiselect' | 'select' | 'text' | 'number_range' | 'async_select'

export interface ReportFilterOption {
  value: string | number
  label: LocalizedText | number
}

export interface DateRangeValue {
  from?: string | null
  to?: string | null
}

export interface NumberRangeValue {
  from?: number | null
  to?: number | null
}

export type ReportFilterValue =
  | string
  | number
  | Array<string | number>
  | DateRangeValue
  | NumberRangeValue

/**
 * Default value shapes as sent by the backend in filters_available[field].default.
 * - date_range / number_range: { from, to } (same as filter value shape)
 * - select: { value: scalar } — needs to be unwrapped to scalar before storing in localFilters
 * - multiselect: { values: [...] } — needs to be unwrapped to array
 * - text: { value: string }
 */
export interface FilterDefaultDateRange {
  from?: string | null
  to?: string | null
}

export interface FilterDefaultNumberRange {
  from?: number | null
  to?: number | null
}

export interface FilterDefaultSelect {
  value: string | number
}

export interface FilterDefaultMultiselect {
  values: Array<string | number>
}

export interface FilterDefaultText {
  value: string
}

export interface FilterDefaultAsyncSelect {
  value: string
}

export interface FilterDefaultAsyncSelectMultiple {
  values: string[]
}

export type FilterDefault =
  | FilterDefaultDateRange
  | FilterDefaultNumberRange
  | FilterDefaultSelect
  | FilterDefaultMultiselect
  | FilterDefaultText
  | FilterDefaultAsyncSelect
  | FilterDefaultAsyncSelectMultiple

interface BaseFilterConfig {
  label?: LocalizedText
  source?: string
  field?: string
  /** Pre-resolved default value set by the backend (filter_default in report config). */
  default?: FilterDefault
}

export interface DateRangeFilterConfig extends BaseFilterConfig {
  type: 'date_range'
  default?: FilterDefaultDateRange
}

export interface SingleSelectFilterConfig extends BaseFilterConfig {
  type: 'select'
  options: ReportFilterOption[]
  default?: FilterDefaultSelect
}

export interface MultiSelectFilterConfig extends BaseFilterConfig {
  type: 'multiselect'
  options: ReportFilterOption[]
  default?: FilterDefaultMultiselect
}

export interface TextFilterConfig extends BaseFilterConfig {
  type: 'text'
  placeholder?: LocalizedText
  default?: FilterDefaultText
}

export interface NumberRangeFilterConfig extends BaseFilterConfig {
  type: 'number_range'
  placeholder?: LocalizedText
  min?: number
  max?: number
  mode?: 'decimal' | 'currency'
  default?: FilterDefaultNumberRange
}

export interface AsyncSelectFilterConfig extends BaseFilterConfig {
  type: 'async_select'
  /** Backend endpoint for searching options, e.g. /api/reports/17/filter-options/field.name */
  search_endpoint: string
  /** When true, renders a MultiSelect (array value); when false/absent, renders a single Select (scalar value). */
  multiple?: boolean
  default?: FilterDefaultAsyncSelect | FilterDefaultAsyncSelectMultiple
}

export type ReportFilterConfig =
  | DateRangeFilterConfig
  | SingleSelectFilterConfig
  | MultiSelectFilterConfig
  | TextFilterConfig
  | NumberRangeFilterConfig
  | AsyncSelectFilterConfig

export type ReportFiltersAvailable = Record<string, ReportFilterConfig>
export type ReportFiltersApplied = Record<string, ReportFilterValue>

export const isReportFilterOption = (value: unknown): value is ReportFilterOption => {
  if (typeof value !== 'object' || value === null) return false

  const option = value as Partial<ReportFilterOption>

  return (
    (typeof option.value === 'string' || typeof option.value === 'number') &&
    (typeof option.label === 'string' ||
      typeof option.label === 'number' ||
      (typeof option.label === 'object' && option.label !== null))
  )
}

export const isReportFilterConfig = (value: unknown): value is ReportFilterConfig => {
  if (typeof value !== 'object' || value === null) return false

  const config = value as Partial<ReportFilterConfig>

  switch (config.type) {
    case 'date_range':
      return true
    case 'text':
      return true
    case 'number_range':
      return true
    case 'select':
    case 'multiselect':
      return Array.isArray(config.options) && config.options.every(isReportFilterOption)
    case 'async_select':
      return typeof (config as { search_endpoint?: unknown }).search_endpoint === 'string'
    default:
      return false
  }
}

export const isReportFiltersAvailable = (value: unknown): value is ReportFiltersAvailable => {
  if (typeof value !== 'object' || value === null) return false

  return Object.values(value).every(isReportFilterConfig)
}

export const isReportFiltersApplied = (value: unknown): value is ReportFiltersApplied => {
  if (typeof value !== 'object' || value === null) return false

  return Object.values(value).every((filterValue) => {
    if (
      typeof filterValue === 'string' ||
      typeof filterValue === 'number' ||
      filterValue === null
    ) {
      return true
    }

    if (Array.isArray(filterValue)) {
      return filterValue.every((item) => typeof item === 'string' || typeof item === 'number')
    }

    if (typeof filterValue !== 'object') return false

    const record = filterValue as Partial<DateRangeValue & NumberRangeValue>

    const isStringRange =
      (record.from === undefined || record.from === null || typeof record.from === 'string') &&
      (record.to === undefined || record.to === null || typeof record.to === 'string')

    const isNumberRange =
      (record.from === undefined || record.from === null || typeof record.from === 'number') &&
      (record.to === undefined || record.to === null || typeof record.to === 'number')

    return isStringRange || isNumberRange
  })
}

/**
 * Extracts default filter values from filters_available metadata.
 *
 * Handles shape differences between backend `default` objects and the flat
 * ReportFilterValue shape that localFilters/currentFilters expect:
 * - date_range / number_range: { from, to } — passed through as-is
 * - select: { value: scalar } — unwrapped to scalar
 * - multiselect: { values: [...] } — unwrapped to array
 * - text: { value: string } — unwrapped to string
 *
 * Only entries with at least one non-null/non-empty field are included in the result
 * so that empty defaults (e.g. { from: null, to: null }) do not pollute the filter state.
 */
export const buildDefaultFilters = (
  filtersAvailable: ReportFiltersAvailable,
): ReportFiltersApplied => {
  const result: ReportFiltersApplied = {}

  for (const [field, config] of Object.entries(filtersAvailable)) {
    if (!config.default) continue

    let value: ReportFilterValue | undefined

    if (config.type === 'date_range') {
      const d = config.default as FilterDefaultDateRange
      // Include if at least one bound is non-null/non-empty
      if ((d.from != null && d.from !== '') || (d.to != null && d.to !== '')) {
        value = { from: d.from ?? null, to: d.to ?? null }
      }
    } else if (config.type === 'number_range') {
      const d = config.default as FilterDefaultNumberRange
      if (d.from != null || d.to != null) {
        value = { from: d.from ?? null, to: d.to ?? null }
      }
    } else if (config.type === 'select') {
      const d = config.default as FilterDefaultSelect
      if (d.value != null && d.value !== '') {
        value = d.value
      }
    } else if (config.type === 'multiselect') {
      const d = config.default as FilterDefaultMultiselect
      if (Array.isArray(d.values) && d.values.length > 0) {
        value = d.values
      }
    } else if (config.type === 'text') {
      const d = config.default as FilterDefaultText
      if (d.value != null && d.value !== '') {
        value = d.value
      }
    } else if (config.type === 'async_select') {
      if (config.multiple) {
        const d = config.default as FilterDefaultAsyncSelectMultiple
        if (Array.isArray(d.values) && d.values.length > 0) {
          value = d.values
        }
      } else {
        const d = config.default as FilterDefaultAsyncSelect
        if (d.value != null && d.value !== '') {
          value = d.value
        }
      }
    }

    if (value !== undefined) {
      result[field] = value
    }
  }

  return result
}
