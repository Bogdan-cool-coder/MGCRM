import { apiClient } from '@/api/client'
import type { DedupCandidate, DedupScope } from '@/entities/crm'

export interface DedupGroup {
  key: string
  entities: DedupCandidate[]
}

export interface DedupScanResponse {
  data: DedupGroup[]
}

export interface DedupMergePayload {
  scope: DedupScope
  master_id: number
  duplicate_ids: number[]
}

export interface DedupDismissPayload {
  scope: DedupScope
  entity_a_id: number
  entity_b_id: number
}

export const dedupApi = {
  async scan(scope: DedupScope): Promise<DedupGroup[]> {
    const res = await apiClient.get<DedupScanResponse>('/api/crm/dedup/scan', {
      params: { scope },
    })
    return res.data.data
  },

  async merge(payload: DedupMergePayload): Promise<void> {
    await apiClient.post('/api/crm/dedup/merge', payload)
  },

  async dismiss(payload: DedupDismissPayload): Promise<void> {
    await apiClient.post('/api/crm/dedup/dismiss', payload)
  },
}
