/**
 * Entity Log API — GET log for deals, companies, contacts.
 * Endpoints: GET /api/deals/{id}/log, /api/companies/{id}/log, /api/contacts/{id}/log
 */
import { apiClient } from '@/api/client'
import type { EntityLogPaginatedResponse } from '@/entities/crm'

export type EntityLogTarget = 'deal' | 'company' | 'contact'

export interface EntityLogParams {
  page?: number
  per_page?: number
}

function logPath(target: EntityLogTarget, id: number): string {
  if (target === 'deal') return `/api/deals/${id}/log`
  if (target === 'company') return `/api/companies/${id}/log`
  return `/api/contacts/${id}/log`
}

export const logApi = {
  getLog(
    target: EntityLogTarget,
    id: number,
    params: EntityLogParams = {},
  ): Promise<EntityLogPaginatedResponse> {
    return apiClient
      .get<EntityLogPaginatedResponse>(logPath(target, id), { params })
      .then((r) => r.data)
  },
}
