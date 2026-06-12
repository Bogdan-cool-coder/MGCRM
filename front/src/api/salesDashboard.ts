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

export const exportDashboardXlsx = (filters: DashboardFilters): void => {
  const p = new URLSearchParams({ period: filters.period })
  if (filters.pipeline_id != null) p.set('pipeline_id', String(filters.pipeline_id))
  if (filters.manager_id != null) p.set('manager_id', String(filters.manager_id))
  window.open(`/api/sales/dashboard.xlsx?${p.toString()}`, '_blank')
}
