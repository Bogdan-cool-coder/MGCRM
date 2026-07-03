/**
 * Sales-analytics report Excel export — one endpoint per report tab.
 *
 * Contract: `docs/contracts/plan-targets-api-contract.md` §1.1/§9 Ф5 — «PhpSpreadsheet
 * export of all reports (reuse `buildXlsx` pattern)». Endpoints follow the existing
 * `GET /api/sales/dashboard.xlsx` convention (`.../export`, `responseType: 'blob'`).
 *
 * Backend is built in PARALLEL — until the export endpoints ship, these calls 404;
 * the caller (`useAnalyticsExport`) degrades gracefully to an info-toast «скоро».
 *
 * Returns the raw axios response so the caller can read the `Content-Disposition`
 * filename (via `@/utils/download`).
 */
import type { AxiosResponse } from 'axios'
import { apiClient } from '@/api/client'

/** Which report tab is being exported (maps to an export endpoint). */
export type ExportableReport = 'registry' | 'schedule' | 'rating' | 'plans'

/** Cross-cutting params the reports export accepts (only the relevant ones are sent). */
export interface ReportExportParams {
  year?: number
  month?: number
  layer?: string
  pipeline_id?: number | null
  manager_id?: number | null
  mode?: string
  /** For the plans export — which metric grid to render. */
  metric?: string
  /** For the plans export — the visible grid's scope axis (user|company|pipeline). */
  scope_type?: string
}

const ENDPOINTS: Record<ExportableReport, string> = {
  registry: '/api/reports/registry/export',
  schedule: '/api/reports/income-schedule/export',
  rating: '/api/reports/best-manager/export',
  plans: '/api/plans/matrix/export',
}

/** GET the xlsx export for a report as a Blob (raw response — headers needed). */
export const exportReportXlsx = (
  report: ExportableReport,
  params: ReportExportParams,
): Promise<AxiosResponse<Blob>> => {
  const query: Record<string, unknown> = {}
  if (params.year != null) query.year = params.year
  if (params.month != null) query.month = params.month
  if (params.layer != null) query.layer = params.layer
  if (params.pipeline_id != null) query.pipeline_id = params.pipeline_id
  if (params.manager_id != null) query.manager_id = params.manager_id
  if (params.mode != null) query.mode = params.mode
  if (params.metric != null) query.metric = params.metric
  if (params.scope_type != null) query.scope_type = params.scope_type
  return apiClient.get<Blob>(ENDPOINTS[report], { params: query, responseType: 'blob' })
}
