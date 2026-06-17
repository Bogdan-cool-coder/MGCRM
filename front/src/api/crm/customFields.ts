/**
 * Custom Fields API — definitions (scope=deal|company|contact) + deal custom fields.
 * Endpoints:
 *   GET  /api/crm/custom-fields?scope={scope}  → definitions list
 *   GET  /api/deals/{deal}/custom-fields        → definitions + current values for a deal
 */
import { apiClient } from '@/api/client'
import type { CustomFieldDef, CustomFieldScope, DealCustomFieldsResponse } from '@/entities/crm'

export const customFieldsApi = {
  async getDefinitions(scope: CustomFieldScope): Promise<CustomFieldDef[]> {
    const res = await apiClient.get<{ data: CustomFieldDef[] }>('/api/crm/custom-fields', {
      params: { scope },
    })
    return res.data.data ?? []
  },

  async getDealCustomFields(dealId: number): Promise<DealCustomFieldsResponse> {
    const res = await apiClient.get<DealCustomFieldsResponse>(
      `/api/deals/${dealId}/custom-fields`,
    )
    return res.data
  },
}
