import { apiClient } from '@/api/client'
import type {
  MacrodataMappingDto,
  MacrodataMappingsListResponse,
  MacrodataMappingsUpsertRequest,
  MacrodataMappingUpsertItem,
  MacrodataProbeResponse,
  MacrodataProbeResultDto,
} from '@/api/types/macrodataMappings'

/**
 * Thin axios wrapper around the per-company MacroData mapping endpoints.
 *
 * The corresponding backend routes are protected by an admin/superadmin
 * gate. `viewer` / `analyst` callers will receive 403 — the UI should
 * already hide the surface for them (see `canManageCompanyMacrodataMappings`).
 *
 * The probe endpoint can return 503 when the company's MacroData database
 * is unreachable; the response body uses
 * `{ error: 'macrodata_unavailable', message: string }`. Callers surface
 * the human message as a toast and leave the UI state untouched.
 */
export interface MacrodataMappingsApi {
  listMappings(companyId: number): Promise<MacrodataMappingDto[]>
  bulkUpsertMappings(
    companyId: number,
    mappings: MacrodataMappingUpsertItem[],
  ): Promise<MacrodataMappingDto[]>
  deleteMapping(companyId: number, semanticKey: string): Promise<void>
  probeMappings(companyId: number): Promise<MacrodataProbeResultDto>
}

export const macrodataMappingsApi: MacrodataMappingsApi = {
  async listMappings(companyId: number): Promise<MacrodataMappingDto[]> {
    const response = await apiClient.get<MacrodataMappingsListResponse>(
      `/api/companies/${companyId}/macrodata-mappings`,
    )
    return response.data?.data ?? []
  },

  async bulkUpsertMappings(
    companyId: number,
    mappings: MacrodataMappingUpsertItem[],
  ): Promise<MacrodataMappingDto[]> {
    const payload: MacrodataMappingsUpsertRequest = { mappings }
    const response = await apiClient.put<MacrodataMappingsListResponse>(
      `/api/companies/${companyId}/macrodata-mappings`,
      payload,
    )
    return response.data?.data ?? []
  },

  async deleteMapping(companyId: number, semanticKey: string): Promise<void> {
    await apiClient.delete(
      `/api/companies/${companyId}/macrodata-mappings/${encodeURIComponent(semanticKey)}`,
    )
  },

  async probeMappings(companyId: number): Promise<MacrodataProbeResultDto> {
    const response = await apiClient.post<MacrodataProbeResponse>(
      `/api/companies/${companyId}/macrodata-mappings/probe`,
    )
    return response.data.data
  },
}

export type {
  MacrodataMappingDto,
  MacrodataMappingUpsertItem,
  MacrodataProbeResultDto,
}
