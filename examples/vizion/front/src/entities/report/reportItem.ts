import type { LocalizedText } from '@/shared/types'
import type { ReportAuthorDto } from '@/api/types/reports'
import type {
  Report,
  ReportAnyRow,
  ReportChart,
  ReportChartConfig,
  ReportColumn,
  ReportConfig,
  ReportMeta,
  ReportTotalsRow,
} from './types'
import type { ReportFiltersApplied, ReportFiltersAvailable } from './filters'

export interface ReportItem {
  id: number
  title: LocalizedText
  description?: LocalizedText
  type: 'dashboard' | 'custom'
  is_system?: boolean
  is_published?: boolean
  /**
   * Owner user id. `null` for system reports (backend `ReportSeeder` seeds
   * `user_id = null`). Consumers comparing ownership must use `== null`
   * (loose) or check both `null`/`undefined` explicitly.
   */
  user_id?: number | null
  /**
   * Id of the report's `report_generation` chat (camelCase here, `chat_id` on
   * the DTO). Drives the "edit with AI" action — when present and the user is
   * the owner of a non-system report, the actions menu can resume this chat in
   * the modal. `null` for system reports and older reports with no pinned chat.
   */
  chatId?: number | null
  company_id?: number
  columns?: ReportColumn[]
  rows?: ReportAnyRow[]
  meta?: ReportMeta
  chart?: ReportChart
  filters_available?: ReportFiltersAvailable
  filters_applied?: ReportFiltersApplied
  config?: ReportConfig
  chart_config?: ReportChartConfig
  totals?: ReportTotalsRow
  /**
   * ISO 8601 timestamps + author projection — surfaced to the page so the
   * actions-menu popover can render "Created at" / "Author" without an
   * extra fetch. `author` is null on system reports (backend contract).
   */
  created_at?: string
  updated_at?: string
  author?: ReportAuthorDto | null
}

export type FormattedReportRow = Record<string, string | number>

export const mapReportToItem = (report: Report): ReportItem => {
  return {
    id: report.id,
    title: report.title,
    description: report.description,
    type: report.is_system ? 'dashboard' : 'custom',
    is_system: report.is_system,
    is_published: report.is_published,
    user_id: report.user_id,
    chatId: report.chat_id ?? null,
    company_id: report.company_id,
    columns: report.columns,
    rows: report.rows,
    meta: report.meta,
    chart: report.chart,
    filters_available: report.filters_available,
    filters_applied: report.filters_applied,
    config: report.config,
    chart_config: report.chart_config,
    totals: report.totals,
    created_at: report.created_at,
    updated_at: report.updated_at,
    author: report.author,
  }
}
