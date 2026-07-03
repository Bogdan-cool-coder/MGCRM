/**
 * Sales-analytics reports API — R1 Registry, R2 Income schedule, R3 Best manager,
 * R4 Task matrix, R5 Conversions.
 *
 * Contract: `docs/contracts/plan-targets-api-contract.md` §6.4–§6.8. All under
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
  BestManagerQuery,
  BestManagerResponse,
  TaskMatrixQuery,
  TaskMatrixResponse,
  ConversionReportQuery,
  ConversionReportResponse,
  ProductIncomeQuery,
  ProductIncomeResponse,
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

/** R3 · GET /api/reports/best-manager — yearly manager rating + leader. */
export const getBestManagerReport = (
  query: BestManagerQuery,
): Promise<BestManagerResponse> => {
  const params: Record<string, unknown> = { year: query.year }
  if (query.mode != null) params.mode = query.mode
  if (query.pipeline_id != null) params.pipeline_id = query.pipeline_id
  return apiClient
    .get<BestManagerResponse>('/api/reports/best-manager', { params })
    .then((r) => r.data)
}

/** R4 · GET /api/reports/task-matrix — completed-tasks matrix (kind × user × 12 мес). */
export const getTaskMatrix = (
  query: TaskMatrixQuery,
): Promise<TaskMatrixResponse> => {
  const params: Record<string, unknown> = {
    year: query.year,
    layer: query.layer,
  }
  if (query.pipeline_id != null) params.pipeline_id = query.pipeline_id
  if (query.kind != null) params.kind = query.kind
  return apiClient
    .get<TaskMatrixResponse>('/api/reports/task-matrix', { params })
    .then((r) => r.data)
}

/** R5 · GET /api/reports/conversions — конверсии (pairs + honest stage layer). */
export const getConversionsReport = (
  query: ConversionReportQuery,
): Promise<ConversionReportResponse> => {
  const params: Record<string, unknown> = {
    year: query.year,
    layer: query.layer,
  }
  if (query.pipeline_id != null) params.pipeline_id = query.pipeline_id
  if (query.scope_type != null) params.scope_type = query.scope_type
  return apiClient
    .get<ConversionReportResponse>('/api/reports/conversions', { params })
    .then((r) => r.data)
}

/** R6 · GET /api/reports/product-income — Поступления по продуктовым линейкам. */
export const getProductIncomeReport = (
  query: ProductIncomeQuery,
): Promise<ProductIncomeResponse> => {
  const params: Record<string, unknown> = {
    year: query.year,
    layer: query.layer,
  }
  return apiClient
    .get<ProductIncomeResponse>('/api/reports/product-income', { params })
    .then((r) => r.data)
}
