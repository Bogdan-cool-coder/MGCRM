/**
 * Sales Dashboard API — GET /api/sales/dashboard + xlsx export.
 * Follows the pattern of api/sales.ts (apiClient from @/api/client).
 */
import { apiClient } from '@/api/client'
import type { DashboardFilters, DashboardResponse } from '@/entities/salesDashboard'

/**
 * Build the query params for a dashboard request. When `months[]` is non-empty it
 * is sent INSTEAD of `period` (the backend gives months priority, but we omit the
 * enum entirely to keep the request unambiguous — Ф8). Otherwise the legacy named
 * period drives the window, exactly as before.
 */
const buildDashboardParams = (filters: DashboardFilters): Record<string, unknown> => {
  const params: Record<string, unknown> = {}
  if (filters.months != null && filters.months.length > 0) {
    params.months = filters.months
  } else {
    params.period = filters.period
  }
  if (filters.pipeline_id != null) params.pipeline_id = filters.pipeline_id
  if (filters.manager_id != null) params.manager_id = filters.manager_id
  return params
}

export const getDashboardData = (
  filters: DashboardFilters,
): Promise<DashboardResponse> => {
  return apiClient
    .get<DashboardResponse>('/api/sales/dashboard', {
      params: buildDashboardParams(filters),
    })
    .then((r) => r.data)
}

/**
 * Fetch the dashboard XLSX through the authenticated axios client as a Blob.
 * The app is Bearer-only (no cookie/session) so a top-level window.open carries
 * no Authorization header and 500s on the auth middleware — the caller must
 * download via this authenticated request and turn the Blob into an object URL
 * (same pattern as salesApi.exportDeals / companies.exportCompanies).
 */
export const exportDashboardXlsx = (filters: DashboardFilters): Promise<Blob> => {
  // Same DashboardRequest binds both routes, so months[] is honoured on export too.
  return apiClient
    .get<Blob>('/api/sales/dashboard.xlsx', {
      params: buildDashboardParams(filters),
      responseType: 'blob',
    })
    .then((r) => r.data)
}
