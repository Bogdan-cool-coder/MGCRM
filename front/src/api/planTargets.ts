/**
 * Plan Targets API — P-1 matrix read, P-2 bulk upsert, P-3 copy-previous.
 *
 * Contract: `docs/contracts/plan-targets-api-contract.md` §6. All under /api,
 * auth:sanctum (Bearer). Reads are visibility-scoped server-side; writes gated
 * by `plans.manage`. Backend is built in parallel — until it ships, these calls
 * return 404 (graceful-degrade handled in the composable, established pattern).
 */
import { apiClient } from '@/api/client'
import type {
  PlanMatrixQuery,
  PlanMatrixResponse,
  BulkUpsertCellsPayload,
  PlanCell,
  CopyPreviousPayload,
  CopyPreviousResult,
} from '@/entities/planTargets'

/** P-1 · GET /api/plans/matrix — plan+fact matrix (inline-grid source). */
export const getPlanMatrix = (
  query: PlanMatrixQuery,
): Promise<PlanMatrixResponse> => {
  const params: Record<string, unknown> = {
    metric: query.metric,
    scope_type: query.scope_type,
    layer: query.layer,
    year: query.year,
  }
  if (query.pipeline_id != null) params.pipeline_id = query.pipeline_id
  if (query.month != null) params.month = query.month
  return apiClient
    .get<PlanMatrixResponse>('/api/plans/matrix', { params })
    .then((r) => r.data)
}

/** P-2 · POST /api/plans/cells — bulk upsert dirty cells (can:plans.manage). */
export const bulkUpsertCells = (
  payload: BulkUpsertCellsPayload,
): Promise<PlanCell[]> =>
  apiClient
    .post<{ data: PlanCell[] }>('/api/plans/cells', payload)
    .then((r) => r.data.data)

/** P-3 · POST /api/plans/copy-previous — clone prior period (can:plans.manage). */
export const copyPreviousPeriod = (
  payload: CopyPreviousPayload,
): Promise<CopyPreviousResult> =>
  apiClient
    .post<{ data: CopyPreviousResult }>('/api/plans/copy-previous', payload)
    .then((r) => r.data.data)
