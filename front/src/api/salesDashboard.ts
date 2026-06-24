/**
 * Sales Dashboard API — GET /api/sales/dashboard + xlsx export.
 * Follows the pattern of api/sales.ts (apiClient from @/api/client).
 */
import { apiClient } from '@/api/client'
import type { DashboardFilters, DashboardResponse } from '@/entities/salesDashboard'

export const getDashboardData = (
  filters: DashboardFilters,
): Promise<DashboardResponse> => {
  const params: Record<string, unknown> = { period: filters.period }
  if (filters.pipeline_id != null) params.pipeline_id = filters.pipeline_id
  if (filters.manager_id != null) params.manager_id = filters.manager_id
  return apiClient
    .get<DashboardResponse>('/api/sales/dashboard', { params })
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
  const params: Record<string, unknown> = { period: filters.period }
  if (filters.pipeline_id != null) params.pipeline_id = filters.pipeline_id
  if (filters.manager_id != null) params.manager_id = filters.manager_id
  return apiClient
    .get<Blob>('/api/sales/dashboard.xlsx', { params, responseType: 'blob' })
    .then((r) => r.data)
}
