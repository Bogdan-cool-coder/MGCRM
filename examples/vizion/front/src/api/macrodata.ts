import { apiClient } from '@/api/client'
import type {
  EstateSellDetailDto,
  EstateSellOptionDto,
  MacroDataSchemaDto,
} from '@/api/types/macrodata'

/**
 * MacroData lookup endpoints used by the Documents section (object search for
 * the proposal builder + field schema for the docx helper). Active company is
 * resolved by backend middleware — no `company_id` param.
 *
 * Distinct from `macrodataMappings.ts`, which deals with per-company semantic
 * ID mappings rather than live MacroData reads.
 */
export interface MacroDataApi {
  /** Async object picker — `?q=&limit=` → estate-sell options. */
  searchEstateSells(_q: string, _limit?: number): Promise<EstateSellOptionDto[]>
  /** Field bag + readable label for a single object, used to fill a proposal. */
  getEstateSell(_id: number): Promise<EstateSellDetailDto>
  /**
   * Column schema for a whitelisted MacroData model (docx field reference).
   * 422 for non-whitelisted models, 503 when MacroData is unreachable.
   */
  getSchema(_model: string): Promise<MacroDataSchemaDto>
}

export const macroDataApi: MacroDataApi = {
  async searchEstateSells(q: string, limit = 20): Promise<EstateSellOptionDto[]> {
    const response = await apiClient.get<EstateSellOptionDto[]>(
      '/api/macrodata/estate-sells/search',
      { params: { q, limit } },
    )
    return response.data
  },

  async getEstateSell(id: number): Promise<EstateSellDetailDto> {
    const response = await apiClient.get<EstateSellDetailDto>(
      `/api/macrodata/estate-sells/${id}`,
    )
    return response.data
  },

  async getSchema(model: string): Promise<MacroDataSchemaDto> {
    const response = await apiClient.get<MacroDataSchemaDto>('/api/macrodata/schema', {
      params: { model },
    })
    return response.data
  },
}
