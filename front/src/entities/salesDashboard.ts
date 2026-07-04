/**
 * Sales Dashboard DTO types.
 * Mirrors the shape of GET /api/sales/dashboard response.
 * All monetary values are integers in kopecks.
 */

export type DashboardPeriod =
  | 'current_month'
  | 'last_month'
  | 'current_quarter'
  | 'current_year'

export interface DashboardFilters {
  /**
   * Named-period enum (Месяц/Прошлый месяц/Квартал/Год). Sent only when `months`
   * is empty — the backend gives `months[]` priority over `period` (Ф8).
   */
  period: DashboardPeriod
  /**
   * Explicit month selection as "YYYY-MM" strings (1..12). When non-empty this is
   * what the Обзор month-picker sends and it overrides `period` on the backend.
   */
  months?: string[]
  pipeline_id?: number | null
  manager_id?: number | null
}

export interface StatusGroup {
  key: 'active' | 'won' | 'lost' | 'total'
  label: string
  count: number
  amount_kopecks: number
  trend_pct: number | null
}

export interface FunnelStage {
  stage_id: number
  stage_name: string
  sort_order: number
  count: number
  avg_days_in_stage: number
  transition_to_next_pct: number | null
  is_won: boolean
  is_lost: boolean
  probability: number
}

export interface FunnelData {
  stages: FunnelStage[]
  total_active: number
  total_won: number
  total_lost: number
}

export interface ForecastData {
  total_weighted_kopecks: number
  hot_kopecks: number
  warm_kopecks: number
  trial_kopecks: number
  by_stage: Array<{
    stage_name: string
    amount_kopecks: number
    count: number
    probability: number
  }>
}

export interface TopChartData {
  labels: string[]
  datasets: Array<{ label: string; data: number[] }>
  meta: { type: 'bar'; unit: 'kopecks' }
}

export interface DealsWithoutTasksData {
  count: number
  filter_url: string
}

export interface DashboardMeta {
  pipeline: { id: number; name: string; kind: string } | null
  period: {
    from: string
    to: string
    /**
     * Echo of the selected `months[]` ("YYYY-MM"), or null for a legacy
     * named-period request (Ф8) — lets the picker restore its state.
     */
    months: string[] | null
    /**
     * false when no honest previous period exists (a non-contiguous months[]
     * selection): every `trend_pct` is null and the KPI cards show «без сравнения».
     */
    trend_available: boolean
  }
  base_currency: string
  multi_currency_warning: boolean
  generated_at: string
  no_pipeline?: boolean
}

export interface DashboardResponse {
  meta: DashboardMeta
  status_groups: StatusGroup[]
  funnel: FunnelData
  forecast: ForecastData
  top_products: TopChartData
  top_managers: TopChartData
  deals_without_tasks: DealsWithoutTasksData
}
