/**
 * Per-company MacroData ID mapping (DTOs).
 *
 * Each MACRO CRM tenant has its own integer IDs for things like
 * "sale finance type", "booking finance type", etc. Backend stores these
 * as semantic_key → JSON value pairs in `company_macrodata_mappings` and
 * exposes CRUD + auto-probe endpoints under
 * `GET|PUT|DELETE /api/companies/{company}/macrodata-mappings`.
 *
 * `value` is intentionally typed as `unknown` — for current semantic keys
 * it is always `number[]` (list of IDs), but the schema is open-ended and
 * future keys may store strings, scalar numbers or nested objects. UI keeps
 * the JSON-passthrough model so backend can extend without a frontend bump.
 */

export interface MacrodataMappingDto {
  id: number
  semantic_key: string
  value: unknown
  notes: string | null
  /** ISO 8601 UTC. `null` if the mapping was created/edited manually. */
  auto_probed_at: string | null
  updated_at: string
}

export interface MacrodataMappingsListResponse {
  data: MacrodataMappingDto[]
}

/**
 * Body of `PUT /api/companies/{company}/macrodata-mappings`. The backend
 * performs a bulk upsert — existing rows whose `semantic_key` is not in
 * `mappings[]` stay untouched (use the DELETE endpoint to remove them).
 */
export interface MacrodataMappingUpsertItem {
  semantic_key: string
  value: unknown
  notes?: string | null
  /**
   * Optional ISO 8601 UTC timestamp marking when this value was discovered by
   * the auto-probe. Only sent when the upsert originates from a probe-apply
   * flow — manual inline edits omit the key so the backend partial-update
   * preserves whatever timestamp was already in the DB. Sending `null`
   * explicitly clears the field.
   */
  auto_probed_at?: string | null
}

export interface MacrodataMappingsUpsertRequest {
  mappings: MacrodataMappingUpsertItem[]
}

export interface MacrodataProbeCandidate {
  id: number
  name: string
}

export interface MacrodataProbeMappingDto {
  semantic_key: string
  value: unknown
  /** Human-readable hint about how the auto-mapping decided on this value. */
  matched_by: string
  candidates: MacrodataProbeCandidate[]
}

export interface MacrodataProbeResultDto {
  probed_at: string
  mappings: MacrodataProbeMappingDto[]
  /** Semantic keys the probe was looking for but could not resolve. */
  unresolved: string[]
}

export interface MacrodataProbeResponse {
  data: MacrodataProbeResultDto
}
