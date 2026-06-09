import { macroDataApi } from '@/api/macrodata'
import type {
  EstateSellDetailDto,
  EstateSellOptionDto,
  MacroDataSchemaDto,
} from '@/api/types/macrodata'

/**
 * Thin business wrapper over the MacroData lookup endpoints. Unlike the other
 * services there is no camelCase entity layer here: the payloads are live
 * MacroData field bags (object detail, raw column schema) whose natural shape
 * is the snake_case MacroData column names — re-keying them would only obscure
 * the real field identifiers the proposal templates reference. So the DTOs are
 * passed through verbatim.
 */
export class MacroDataLookupService {
  async searchEstateSells(q: string, limit?: number): Promise<EstateSellOptionDto[]> {
    return macroDataApi.searchEstateSells(q, limit)
  }

  async getEstateSell(id: number): Promise<EstateSellDetailDto> {
    return macroDataApi.getEstateSell(id)
  }

  async getSchema(model: string): Promise<MacroDataSchemaDto> {
    return macroDataApi.getSchema(model)
  }
}
