/**
 * Motivation Card (МК) API — typed axios functions mapped to the Phase-A
 * backend contract (`docs/contracts/motivation-cards-api-contract.md` §6).
 *
 * Backend (`sales-backender`) ships in parallel. Until B-1…B-3 land, reads
 * degrade GRACEFULLY: a 404 (route not yet registered) resolves to `null`
 * instead of throwing — mirroring the pattern used in custom-fields / system
 * reset. Real server errors (5xx, 403, 422) still propagate.
 */
import { isAxiosError } from 'axios'
import { apiClient } from '@/api/client'
import type {
  MotivationCard,
  MotivationPlan,
  UpsertPlanPayload,
  MkManagerOption,
} from '@/entities/motivation'

/** True when the route itself is missing (backend not yet deployed). */
const isNotFound = (err: unknown): boolean =>
  isAxiosError(err) && err.response?.status === 404

// ─── B-1 · Cabinet card (read-only) ───────────────────────────────────────────

export interface CabinetCardParams {
  year: number
  month: number
  user_id?: number
}

/**
 * GET /api/motivation/cards/me — the manager's card for a period.
 * Returns `null` when the endpoint is not yet available (graceful 404).
 */
export const getMotivationCard = async (
  params: CabinetCardParams,
): Promise<MotivationCard | null> => {
  const p: Record<string, unknown> = { year: params.year, month: params.month }
  if (params.user_id != null) p.user_id = params.user_id
  try {
    const res = await apiClient.get<MotivationCard>('/api/motivation/cards/me', {
      params: p,
    })
    return res.data
  } catch (err) {
    if (isNotFound(err)) return null
    throw err
  }
}

// ─── B-2 · Read plan (constructor) ────────────────────────────────────────────

/**
 * GET /api/admin/motivation/plans/{userId}/{year}/{month}.
 * Resolves to `null` when no plan exists yet (`{ data: null }`) OR the route
 * is not yet deployed (404).
 */
export const getMotivationPlan = async (
  userId: number,
  year: number,
  month: number,
): Promise<MotivationPlan | null> => {
  try {
    const res = await apiClient.get<{ data: MotivationPlan | null }>(
      `/api/admin/motivation/plans/${userId}/${year}/${month}`,
    )
    return res.data.data
  } catch (err) {
    if (isNotFound(err)) return null
    throw err
  }
}

// ─── B-3 · Upsert plan + status transitions ───────────────────────────────────

/** POST /api/admin/motivation/plans — idempotent create/update. */
export const saveMotivationPlan = async (
  payload: UpsertPlanPayload,
): Promise<MotivationPlan> => {
  const res = await apiClient.post<{ data: MotivationPlan }>(
    '/api/admin/motivation/plans',
    payload,
  )
  return res.data.data
}

/** POST /api/admin/motivation/plans/copy-previous — clone prior month. */
export const copyPreviousMonthPlan = async (
  userId: number,
  year: number,
  month: number,
): Promise<MotivationPlan | null> => {
  const res = await apiClient.post<{ data: MotivationPlan | null }>(
    '/api/admin/motivation/plans/copy-previous',
    { user_id: userId, year, month },
  )
  return res.data.data
}

/** POST /api/admin/motivation/plans/{card}/finalize — draft → finalized. */
export const finalizeMotivationCard = async (
  cardId: number,
): Promise<MotivationPlan> => {
  const res = await apiClient.post<{ data: MotivationPlan }>(
    `/api/admin/motivation/plans/${cardId}/finalize`,
  )
  return res.data.data
}

/** POST /api/admin/motivation/plans/{card}/mark-paid — finalized → paid. */
export const markMotivationCardPaid = async (
  cardId: number,
): Promise<MotivationPlan> => {
  const res = await apiClient.post<{ data: MotivationPlan }>(
    `/api/admin/motivation/plans/${cardId}/mark-paid`,
  )
  return res.data.data
}

// ─── B-5 · Manager AutoComplete ───────────────────────────────────────────────

export interface ManagerSearchParams {
  q?: string
  /** `me` — director's own subordinates; a numeric id — admin scoping. */
  managed_by?: string
}

/**
 * GET /api/users?role=manager&q=&managed_by= — manager directory for the
 * constructor's AutoComplete. Backend scopes by visibility (§6.5).
 */
export const searchManagers = async (
  params: ManagerSearchParams = {},
): Promise<MkManagerOption[]> => {
  const p: Record<string, unknown> = { role: 'manager' }
  if (params.q) p.q = params.q
  if (params.managed_by) p.managed_by = params.managed_by
  const res = await apiClient.get<{ data: MkManagerOption[] }>('/api/users', {
    params: p,
  })
  return res.data.data
}
