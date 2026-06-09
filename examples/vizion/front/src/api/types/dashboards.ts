import type { LocalizedText } from '@/shared/types'
import type { WidgetConfigDto, WidgetDataDto } from './widgets'

/**
 * Author projection returned with custom (non-system) dashboards.
 * `null` for system dashboards — mirrors `WidgetAuthorDto` / `ReportAuthorDto`.
 */
export interface DashboardAuthorDto {
  id: number
  name: string
  email: string
}

/**
 * `dashboard_widget` pivot — the per-dashboard placement / visibility of a
 * widget. `x`/`y`/`w`/`h` are the `grid-layout-plus` grid coordinates.
 */
export interface DashboardWidgetPivotDto {
  x: number
  y: number
  w: number
  h: number
  sort: number
  visible: boolean
}

/**
 * A widget as it appears inside a dashboard detail — the widget entity plus
 * its placement pivot for this dashboard.
 */
export interface DashboardWidgetDto {
  id: number
  name: LocalizedText
  config: WidgetConfigDto
  is_system: boolean
  is_published: boolean
  user_id: number | null
  pivot: DashboardWidgetPivotDto
}

/**
 * Library list item from `GET /api/dashboards`.
 */
export interface DashboardListItemDto {
  id: number
  name: LocalizedText
  is_system: boolean
  is_published: boolean
  /** `null` for system dashboards. */
  user_id: number | null
  widgets_count: number
  author: DashboardAuthorDto | null
}

/**
 * Full dashboard detail from `GET /api/dashboards/{id}` (and returned by the
 * attach endpoint). Includes the ordered widgets with their placement pivots.
 */
export interface DashboardDto {
  id: number
  name: LocalizedText
  is_system: boolean
  is_published: boolean
  /** `null` for system dashboards. */
  user_id: number | null
  author: DashboardAuthorDto | null
  created_at: string
  updated_at: string
  /**
   * Omitted by the publish / unpublish endpoints (they return a tight payload
   * without the widget set) — optional so those responses still type-check.
   * The mapper defaults a missing value to `[]`.
   */
  widgets?: DashboardWidgetDto[]
}

/**
 * Inclusive month range applied as a global dashboard filter. Both bounds are
 * `YYYY-MM` strings; `from <= to`. Sent as `period_from` / `period_to` query
 * params. The range applies to every widget with a `period_field`; snapshot
 * widgets ignore it server-side.
 */
export interface PeriodRange {
  from: string
  to: string
}

export interface CreateDashboardRequest {
  name: LocalizedText
}

export interface UpdateDashboardRequest {
  name?: LocalizedText
  /** Admin / superadmin only. */
  is_published?: boolean
}

/**
 * Body for `POST /api/dashboards/{id}/widgets` — attach a widget reference.
 * Grid coordinates are optional; backend assigns sensible defaults.
 */
export interface AttachWidgetRequest {
  widget_id: number
  x?: number
  y?: number
  w?: number
  h?: number
  sort?: number
  visible?: boolean
}

/**
 * One row of the batch layout payload for `PUT /api/dashboards/{id}/layout`.
 */
export interface DashboardLayoutItem {
  widget_id: number
  x: number
  y: number
  w: number
  h: number
  sort: number
  visible: boolean
}

export interface UpdateDashboardLayoutRequest {
  widgets: DashboardLayoutItem[]
}

/**
 * `GET /api/dashboards/{id}/data?period_from=YYYY-MM&period_to=YYYY-MM` — keyed
 * by widget id (string keys per JSON object semantics). Only `visible` widgets
 * are included. The range applies to every widget with a `period_field`;
 * snapshot widgets ignore it (resolved backend-side).
 */
export interface DashboardDataDto {
  widgets: Record<string, WidgetDataDto>
  meta: {
    period_from: string | null
    period_to: string | null
  }
}
