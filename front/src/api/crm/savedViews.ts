import { apiClient } from '@/api/client'

// ── DTO (matches backend API Resource) ────────────────────────────────────────

export interface SavedViewPayload {
  columns?: string[]
  /** Omitted entirely when no active sort — backend rejects null for this field */
  sort?: { field: string; direction: 'asc' | 'desc' }
  density?: string
  filters?: Record<string, unknown>
}

export interface SavedViewDto {
  id: number
  user_id: number
  name: string
  entity_type: 'contact' | 'company'
  is_shared: boolean
  is_default: boolean
  payload: SavedViewPayload
  created_at: string
  updated_at: string
}

// ── Request payloads ──────────────────────────────────────────────────────────

export interface CreateSavedViewPayload {
  name: string
  entity_type: 'contact' | 'company'
  is_shared?: boolean
  is_default?: boolean
  payload: SavedViewPayload
}

export interface UpdateSavedViewPayload {
  name?: string
  is_shared?: boolean
  is_default?: boolean
  payload?: SavedViewPayload
}

// ── API module ────────────────────────────────────────────────────────────────

export const savedViewsApi = {
  async list(entityType: 'contact' | 'company'): Promise<SavedViewDto[]> {
    const res = await apiClient.get<{ data: SavedViewDto[] }>('/api/crm/saved-views', {
      params: { entity_type: entityType },
    })
    return res.data.data
  },

  async create(payload: CreateSavedViewPayload): Promise<SavedViewDto> {
    const res = await apiClient.post<{ data: SavedViewDto }>('/api/crm/saved-views', payload)
    return res.data.data
  },

  async update(id: number, payload: UpdateSavedViewPayload): Promise<SavedViewDto> {
    const res = await apiClient.patch<{ data: SavedViewDto }>(
      `/api/crm/saved-views/${id}`,
      payload,
    )
    return res.data.data
  },

  async remove(id: number): Promise<void> {
    await apiClient.delete(`/api/crm/saved-views/${id}`)
  },

  async setDefault(id: number): Promise<SavedViewDto> {
    const res = await apiClient.post<{ data: SavedViewDto }>(
      `/api/crm/saved-views/${id}/default`,
    )
    return res.data.data
  },
}
