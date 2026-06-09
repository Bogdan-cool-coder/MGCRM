import type { LocalizedText } from '@/shared/types'

/**
 * Chart kind a widget renders. Lives inside `WidgetConfigDto.chart.type` —
 * the single source of truth (there is no separate `widgets.chart_type`
 * column — see DEVELOPMENT_PLAN_DASHBOARDS.md §8 O5).
 */
export type WidgetChartType = 'bar' | 'line' | 'pie' | 'doughnut'

/**
 * Widget chart definition mirror of the report `chart` config shape. Backend
 * stores the full widget query/aggregation/chart config in `widgets.config`
 * (jsonb). The frontend treats it as opaque-but-typed: it renders the chart
 * from `GET /api/widgets/{id}/data` (labels + datasets), never re-deriving the
 * dataset from `config`. The structured `chart.type` is the only field the UI
 * reads directly (to pick the ECharts series type).
 */
export interface WidgetChartConfigDto {
  type: WidgetChartType
  [key: string]: unknown
}

export interface WidgetConfigDto {
  primary_model?: string
  chart?: WidgetChartConfigDto
  group_by?: Record<string, unknown>
  aggregates?: unknown[]
  filters?: Record<string, unknown>
  [key: string]: unknown
}

/**
 * Lightweight author projection returned with custom (non-system) widgets.
 * Backend sets `null` for system widgets — mirrors `ReportAuthorDto`.
 */
export interface WidgetAuthorDto {
  id: number
  name: string
  email: string
}

/**
 * Library list item from `GET /api/widgets`.
 */
export interface WidgetListItemDto {
  id: number
  name: LocalizedText
  config: WidgetConfigDto
  is_system: boolean
  is_published: boolean
  /** `null` for system widgets. */
  user_id: number | null
  /** How many dashboards reference this widget — drives the "used in N dashboards" warning. */
  used_in_dashboards_count: number
  author: WidgetAuthorDto | null
}

/**
 * Full widget detail from `GET /api/widgets/{id}`.
 */
export interface WidgetDto {
  id: number
  name: LocalizedText
  config: WidgetConfigDto
  is_system: boolean
  is_published: boolean
  /** `null` for system widgets. */
  user_id: number | null
  /** Id of the source `chat_messages` row (the assistant turn that created it). */
  chat_message_id: number | null
  /** Id of the widget's `widget_generation` chat — resume target for the edit modal. */
  chat_id: number | null
  used_in_dashboards_count: number
  author: WidgetAuthorDto | null
  created_at: string
  updated_at: string
}

export interface WidgetDatasetDto {
  /**
   * Series label. Usually a technical alias ("total" / "cnt") the frontend
   * humanises via `resolveSeriesLabel`, but the backend passes through the
   * widget config `chart.label` verbatim, so it may also arrive as a localized
   * `{ru, en}` object — hence the `LocalizedText` union (not plain `string`).
   */
  label: LocalizedText
  data: number[]
}

/**
 * `GET /api/widgets/{id}/data?period_from=YYYY-MM&period_to=YYYY-MM` response —
 * `{labels, datasets}` shape the frontend maps into an ECharts `option`
 * (vue-echarts `<VChart>`). `period_applied` is true when the widget has a
 * `period_field` and the range was applied (snapshot widgets report `false`).
 */
export interface WidgetDataDto {
  labels: string[]
  datasets: WidgetDatasetDto[]
  meta: {
    period_from: string | null
    period_to: string | null
    period_applied: boolean
    row_count: number
  }
}

export interface CreateWidgetRequest {
  name: LocalizedText
  config: WidgetConfigDto
  /** Optional — pins the widget to the assistant message that produced it. */
  chat_message_id?: number
}

/**
 * Body for `POST /api/widgets/preview` — computes chart-ready data for an
 * arbitrary widget config WITHOUT persisting a widget. Used to render the live
 * preview of each AI-proposed variant in the widget-generation modal before the
 * user commits to one. Optional `period_from` / `period_to` are `YYYY-MM`
 * bounds (same semantics as `GET /api/widgets/{id}/data`).
 */
export interface PreviewWidgetRequest {
  config: WidgetConfigDto
  period_from?: string
  period_to?: string
}

export interface UpdateWidgetRequest {
  name?: LocalizedText
  config?: WidgetConfigDto
  /** Admin / superadmin only — backend ACL rejects others. */
  is_published?: boolean
}

/**
 * 409 body when DELETE is blocked because the widget is still referenced by
 * one or more dashboards.
 */
export interface WidgetInUseErrorDto {
  message: string
  used_in_dashboards_count: number
}
