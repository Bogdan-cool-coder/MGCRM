/**
 * Custom Fields API — definitions management + deal custom fields.
 *
 * Endpoints (canonical per docs/contracts/custom-fields-api-contract.md):
 *   GET    /api/crm/custom-fields                            → admin list (active + inactive)
 *   GET    /api/crm/custom-fields/schema?entity_scope=       → active defs grouped by group
 *   GET    /api/crm/custom-fields/{id}                       → single def
 *   POST   /api/crm/custom-fields                            → create (admin-write)
 *   PATCH  /api/crm/custom-fields/{id}                       → update (admin-write)
 *   DELETE /api/crm/custom-fields/{id}                       → delete (admin-write)
 *   PATCH  /api/crm/custom-fields/reorder?entity_scope=      → bulk sort_order (admin-write)
 *   GET    /api/deals/{deal}/custom-fields                   → enriched values for a deal
 */
import { apiClient } from '@/api/client'
import type { CustomFieldDef, CustomFieldScope, DealCustomFieldsResponse } from '@/entities/crm'

// ─── Request payload types ────────────────────────────────────────────────────

export interface CreateCustomFieldPayload {
  entity_scope: CustomFieldScope
  code: string
  label: string
  help_text?: string | null
  field_type: string
  options?: string[]
  default_value?: unknown
  required?: boolean
  group?: string | null
  sort_order?: number
  is_active?: boolean
}

export interface UpdateCustomFieldPayload {
  label?: string
  help_text?: string | null
  field_type?: string
  options?: string[]
  default_value?: unknown
  required?: boolean
  group?: string | null
  sort_order?: number
  is_active?: boolean
}

export interface ReorderCustomFieldItem {
  id: number
  sort_order: number
}

// ─── API ──────────────────────────────────────────────────────────────────────

export const customFieldsApi = {
  /**
   * Admin list — returns ALL defs (active + inactive) for the management screen.
   * Optionally filter by scope (but still includes inactive for that scope).
   */
  async getAll(scope?: CustomFieldScope): Promise<CustomFieldDef[]> {
    const params: Record<string, string> = {}
    if (scope) params['scope'] = scope
    const res = await apiClient.get<{ data: CustomFieldDef[] }>('/api/crm/custom-fields', {
      params,
    })
    return res.data.data ?? []
  },

  /**
   * Schema endpoint — returns active definitions for given entity_scope,
   * grouped and sorted. Registered BEFORE apiResource in the backend.
   * Used by CustomFieldRenderer on entity cards.
   */
  async getSchema(entityScope: CustomFieldScope): Promise<CustomFieldDef[]> {
    const res = await apiClient.get<{ data: CustomFieldDef[] }>(
      '/api/crm/custom-fields/schema',
      { params: { entity_scope: entityScope } },
    )
    return res.data.data ?? []
  },

  /** Single definition (for edit pre-fill). */
  async getOne(id: number): Promise<CustomFieldDef> {
    const res = await apiClient.get<{ data: CustomFieldDef }>(`/api/crm/custom-fields/${id}`)
    return res.data.data
  },

  /** Create a new definition (admin-write gate). */
  async create(payload: CreateCustomFieldPayload): Promise<CustomFieldDef> {
    const res = await apiClient.post<{ data: CustomFieldDef }>('/api/crm/custom-fields', payload)
    return res.data.data
  },

  /** Update an existing definition (admin-write gate). entity_scope/code are immutable. */
  async update(id: number, payload: UpdateCustomFieldPayload): Promise<CustomFieldDef> {
    const res = await apiClient.patch<{ data: CustomFieldDef }>(
      `/api/crm/custom-fields/${id}`,
      payload,
    )
    return res.data.data
  },

  /** Delete a definition (admin-write gate). Orphans values in extra_fields but does not erase them. */
  async remove(id: number): Promise<void> {
    await apiClient.delete(`/api/crm/custom-fields/${id}`)
  },

  /**
   * Bulk reorder — updates sort_order for a set of defs within one scope.
   * Backend requires ?entity_scope= query param to prevent cross-scope mutations.
   */
  async reorder(entityScope: CustomFieldScope, items: ReorderCustomFieldItem[]): Promise<void> {
    await apiClient.patch('/api/crm/custom-fields/reorder', { items }, {
      params: { entity_scope: entityScope },
    })
  },

  // ─── Legacy helpers (used by CustomFieldRenderer) ────────────────────────────

  /** @deprecated Use getAll(scope) instead. Kept for backward compat of renderer fallback. */
  async getDefinitions(scope: CustomFieldScope): Promise<CustomFieldDef[]> {
    return this.getAll(scope)
  },

  async getDealCustomFields(dealId: number): Promise<DealCustomFieldsResponse> {
    const res = await apiClient.get<DealCustomFieldsResponse>(
      `/api/deals/${dealId}/custom-fields`,
    )
    return res.data
  },
}
