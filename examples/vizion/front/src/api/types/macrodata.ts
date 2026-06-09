/**
 * Snake_case mirrors of the MacroData lookup responses used by the Documents
 * section (DOCUMENTS.md §MacroData-components). These back the object search /
 * detail used to pick the property a commercial proposal is built for, and the
 * field schema used by the docx placeholder helper (Phase 2).
 *
 * NB: these are separate from `macrodataMappings.ts` (per-company semantic-key
 * mappings) — different endpoints, different concern.
 */

/**
 * One option from `GET /api/macrodata/estate-sells/search?q=&limit=`. `value`
 * is the `estate_sell_id`; `label` is a human-readable summary
 * ("кв.45, ЖК X, 65м²").
 */
export interface EstateSellOptionDto {
  value: number
  label: string
}

/**
 * Detail envelope from `GET /api/macrodata/estate-sells/{id}`. `data` is the
 * flat field bag (~25 fields) used to fill the proposal; `label` is the same
 * readable name as in search results.
 */
export interface EstateSellDetailDto {
  data: Record<string, unknown>
  label: string
}

/** One column descriptor in a MacroData model schema. */
export interface MacroDataSchemaFieldDto {
  name: string
  type: string
}

/**
 * Response from `GET /api/macrodata/schema?model=EstateDeals`. Powers the docx
 * "available fields" reference tree (Phase 2 / M6). 422 when the model is
 * outside the backend whitelist, 503 when MacroData is unreachable.
 */
export interface MacroDataSchemaDto {
  model: string
  table: string
  fields: MacroDataSchemaFieldDto[]
}
