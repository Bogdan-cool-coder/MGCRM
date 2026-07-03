/**
 * Sales-analytics reports API — R1 Registry, R2 Income schedule.
 *
 * Contract: `docs/contracts/plan-targets-api-contract.md` §6.4/§6.5. All under
 * /api, auth:sanctum (Bearer). Reads are visibility-scoped server-side (the
 * aggregator applies VisibilityResolver). Backend is built in parallel — until
 * it ships, these calls return 404 (graceful-degrade handled in the composables).
 */
import { apiClient } from '@/api/client'
import type {
  RegistryReportQuery,
  RegistryReportResponse,
  IncomeScheduleQuery,
  IncomeScheduleResponse,
} from '@/entities/reports'

/** R1 · GET /api/reports/registry — expected + squeeze registry. */
export const getRegistryReport = (
  query: RegistryReportQuery,
): Promise<RegistryReportResponse> => {
  const params: Record<string, unknown> = {
    year: query.year,
    month: query.month,
  }
  if (query.pipeline_id != null) params.pipeline_id = query.pipeline_id
  if (query.manager_id != null) params.manager_id = query.manager_id
  if (query.product_group_id != null) params.product_group_id = query.product_group_id
  return apiClient
    .get<RegistryReportResponse>('/api/reports/registry', { params })
    .then((r) => r.data)
}

/** R2 · GET /api/reports/income-schedule — day-calendar + cumulative series. */
export const getIncomeSchedule = (
  query: IncomeScheduleQuery,
): Promise<IncomeScheduleResponse> => {
  const params: Record<string, unknown> = {
    year: query.year,
    month: query.month,
  }
  if (query.pipeline_id != null) params.pipeline_id = query.pipeline_id
  return apiClient
    .get<IncomeScheduleResponse>('/api/reports/income-schedule', { params })
    .then((r) => r.data)
}
