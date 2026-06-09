import type { LocalizedText } from '@/shared/types'

export type ReportTableCellDto = string | number | boolean | null
export type ReportTableRowDto = Record<string, ReportTableCellDto | Record<string, unknown>>

export interface ReportBadgeDto {
  severity: 'success' | 'info' | 'warning' | 'danger' | 'secondary' | 'contrast'
  label: LocalizedText
}

// Variant A: aggregate value with inline label { value, label }
export interface ReportGroupAggregateInlineDto {
  value: ReportTableCellDto
  label?: LocalizedText
}

// Variant B: separate aggregate_labels map
export type ReportGroupAggregateValueDto = ReportTableCellDto | ReportGroupAggregateInlineDto

export interface ReportGroupMetaDto {
  fields: Record<string, string>
  aggregates: Record<string, ReportGroupAggregateValueDto>
  // Variant B: labels stored separately
  aggregate_labels?: Record<string, LocalizedText>
}

export interface ReportGroupRowDto {
  group_key: string
  group_meta: ReportGroupMetaDto
  children_count: number
  has_children: boolean
}

export interface GroupRowsMetaDto {
  total: number
  page: number
  per_page: number
  last_page: number
}

export interface GroupRowsResponseDto {
  group_key: string
  group_meta: ReportGroupMetaDto
  rows: ReportTableRowDto[]
  meta: GroupRowsMetaDto
}

export type ReportAnyRowDto = ReportTableRowDto | ReportGroupRowDto

export interface ReportLabelLineDto {
  prefix?: Record<string, string>
  field: string
  default?: Record<string, string>
}

export interface ReportColumnDto {
  field: string
  header: LocalizedText
  /**
   * Optional column-header tooltip text. Rendered as a `?` icon next to the
   * header label; the icon is omitted entirely when this field is null /
   * undefined (backwards-compatible — existing report configs without
   * descriptions render as before). Pass-through from backend — backend does
   * not validate the localized text shape beyond `LocalizedText`.
   * See DEVELOPMENT_PLAN_CAPITALDATA.md §5.
   */
  description?: LocalizedText | null
  type?: string
  /**
   * Formatting hint for columns whose `type` is a structural/aggregate type
   * that does not itself imply a display format — currently `relation_aggregate`,
   * `window_aggregate` and `custom_attribute` (MACRO EAV attribute stored as
   * varchar; `value_type` says how to format it). The backend passes the whole
   * column config through
   * `getVisibleColumns()` verbatim, so this key reaches the payload untouched.
   * Values mirror the display-`type` vocabulary: `'number'` (grouped digits, no
   * currency), `'currency'` (money), `'date'`, etc. When absent the renderer
   * falls back to field-name heuristics. See `resolveColumnType`.
   */
  value_type?: string
  /**
   * Optional numeric display-precision pattern for number-typed cells, e.g.
   * `'0.00'` (2 decimal places) or `'0.000'` (3). The renderer counts the
   * characters after the decimal point in the pattern and uses that as a fixed
   * number of fraction digits (min = max), so trailing zeros are preserved
   * (`21.7000` with `'0.00'` → `21,70`, not `21,7`). Only applied to number
   * columns; ignored for money / date / percent. When absent, numbers render
   * with the runtime's default precision (back-compatible). See
   * `parseFractionDigits` + `resolveColumnType` in useReportPresentation.
   */
  format?: string
  /**
   * Optional measurement-unit suffix appended to this column's value in the
   * TOTALS (footer) row only — e.g. `"шт."` for a row-count total, `"м²"` for
   * a summed area. Money columns already render a currency symbol via the
   * formatter, so `unit` is intended for non-currency numeric / count columns.
   * Localizable: a plain string applies to all locales, an object selects per
   * locale (`{ ru, en }`). Pass-through from backend column config — not
   * rendered in body cells. See `formattedTotalsRow` in useReportPresentation.
   */
  unit?: string | Record<string, string>
  /**
   * When `true` on a money column (`type: 'currency'`, or
   * `relation_aggregate`/`window_aggregate` with `value_type: 'currency'`), the
   * currency symbol is hoisted OUT of the body cells and surfaced once:
   *   - body cells render the bare grouped number (`3 990 000`, no symbol);
   *   - the column header gets `, {symbol}` appended *dynamically* — the symbol
   *     is resolved from the active company's `currency_code` (Buildera → AED,
   *     KZT companies → ₸), never hardcoded in the config;
   *   - the TOTALS (footer) cell keeps the full money format *with* symbol.
   * This declutters wide money tables while keeping the currency unambiguous in
   * the header and the grand total. The column `type` stays `currency` — the
   * flag only changes where the symbol appears, not what the column *is*.
   * No-op on non-money columns. See `useReportPresentation`.
   */
  currency_in_header?: boolean
  /**
   * Optional per-unit suffix appended right after the currency symbol in BOTH
   * the header and the totals cell of a `currency_in_header` money column —
   * e.g. `"/м²"` turns the header into `Ст./м², AED/м²` and the total into
   * `19 840 000 AED/м²`. Body cells stay bare numbers (no symbol, no suffix).
   * Localizable: a plain string applies to all locales, an object selects per
   * locale (`{ ru, en }`). Only meaningful together with `currency_in_header`.
   */
  currency_suffix?: string | Record<string, string>
  sortable?: boolean
  expression?: string
  link_template?: string
  /**
   * Marks a `link`-type column as the "CRM object ID" column. The cell renders
   * the ID value plus a small external-link icon in the top-right corner; the
   * icon opens the resolved `link_template` URL (the CRM object) in a new tab.
   * Pass-through from backend.
   */
  is_crm_id?: boolean
  label_field?: string
  label_lines?: ReportLabelLineDto[]
  label_fallback?: string | Record<string, string>
  truncate?: 'first_word'
  badge?: Record<string, unknown>
  /** Optional display map for text cells. Key is the raw value; value is a localized label. */
  options?: Record<string, string | { ru: string; en: string }>
}

export interface ReportMetaDto {
  total: number
  page: number
  per_page: number
  last_page: number
  grouped?: boolean
  group_by?: ReportGroupByDto
}

export interface ReportDatasetDto {
  label: string
  data: number[]
}

export interface ReportChartDto {
  type: string
  labels: string[]
  datasets: ReportDatasetDto[]
  options?: Record<string, unknown>
}

export interface ReportChartDefinitionDto {
  type: string
  x?: string
  y?: string
  aggregation?: string
  label?: LocalizedText
}

export interface ReportDemoDataDto {
  rows: ReportTableRowDto[]
  meta?: ReportMetaDto
  chart?: ReportChartDto
}

export interface ReportGroupByAggregateDto {
  type?: string
  label?: LocalizedText
  [key: string]: unknown
}

export interface ReportGroupByDto {
  fields: string[]
  aggregates?: Record<string, ReportGroupByAggregateDto>
  collapsible?: boolean
  collapsed_by_default?: boolean
}

export interface ReportConfigDto {
  primary_model?: string
  columns?: ReportColumnDto[]
  chart?: ReportChartDefinitionDto
  filters?: Record<string, unknown>
  sort?: Record<string, unknown>
  pagination?: Record<string, unknown>
  demo_data?: ReportDemoDataDto
  group_by?: ReportGroupByDto
  /**
   * Field key of the report's "primary" filter — the one everyday filter
   * surfaced as an interactive widget in the report header (right of the
   * title). Matches a key in `filters_available` / `config.filters[]`.
   * Optional — when absent the header shows no primary filter widget.
   */
  primary_filter?: string
}

export interface ReportChartConfigDto {
  type: string
  data: ReportChartDto
  options?: Record<string, unknown>
}

/**
 * Lightweight author projection returned with custom (non-system) reports.
 * Backend sets `null` for system reports — see backend Report transformer.
 * `email` is the fallback display string when `name` is empty.
 */
export interface ReportAuthorDto {
  id: number
  name: string
  email: string
}

export interface ReportDto {
  id: number
  title: LocalizedText
  description?: LocalizedText
  columns?: ReportColumnDto[]
  rows?: ReportAnyRowDto[]
  meta?: ReportMetaDto
  chart?: ReportChartDto
  filters_available?: Record<string, unknown>
  filters_applied?: Record<string, unknown>
  config?: ReportConfigDto
  chart_config?: ReportChartConfigDto
  is_system: boolean
  is_published: boolean
  /**
   * Owner user id. `null` for system reports (`is_system === true`) — backend
   * sets it to null when seeding via `ReportSeeder`. Callers that compare
   * ownership must guard for null (see `ReportActionsMenu.isOwner`).
   */
  user_id: number | null
  company_id: number
  chat_message_id?: number
  /**
   * Id of the report's `report_generation` chat. Lets the report page open the
   * "edit with AI" modal straight into the existing session instead of
   * lazy-creating a new one. `null` for system reports and for older custom
   * reports created before chats were pinned to their report.
   */
  chat_id?: number | null
  totals?: Record<string, ReportTableCellDto>
  /** ISO 8601 timestamp (UTC). Always present on responses from /api/reports. */
  created_at: string
  /** ISO 8601 timestamp (UTC). Always present on responses from /api/reports. */
  updated_at: string
  /**
   * Author projection. `null` for system reports (`is_system === true`) —
   * the UI must guard before reading `author.name`.
   */
  author: ReportAuthorDto | null
}

export interface ReportSortOption {
  field: string
  direction: 'asc' | 'desc'
}

export interface FetchReportOptions {
  page?: number
  per_page?: number
  filters?: Record<string, unknown>
  sort?: ReportSortOption
}

export interface UpdateReportRequest {
  title?: LocalizedText
  description?: LocalizedText
  config?: ReportConfigDto
  is_published?: boolean
}

/**
 * Request / response for `PUT /api/reports/order`. The `order` array is the
 * full ordered list of report ids; an empty array resets to the per-company
 * default order. Persisted per-user + company on the backend.
 */
export interface UpdateReportsOrderRequest {
  order: number[]
}

export interface UpdateReportsOrderResponse {
  company_id: number
  order: number[]
}
