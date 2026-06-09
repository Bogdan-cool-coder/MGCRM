import { apiClient } from '@/api/client'
import type {
  ReportPreferences,
  ReportPreferencesPatch,
} from '@/api/types/reportPreferences'

/**
 * Thin axios wrapper for `/api/reports/{report}/preferences`.
 *
 * - `get` always returns a complete `ReportPreferences` object — backend
 *   responds 200 with defaults when no row exists for this user×report.
 * - `update` is a partial PATCH (HTTP method is PUT for symmetry with the
 *   rest of the API; semantics are partial-upsert). Only the fields present
 *   in `patch` are touched; explicit `null` clears.
 *
 * No client-side `company_id` — backend resolves active company via
 * middleware (consistent with the rest of `reportsApi`).
 */
export interface ReportPreferencesApi {
  get(_reportId: number): Promise<ReportPreferences>
  update(_reportId: number, _patch: ReportPreferencesPatch): Promise<ReportPreferences>
}

export const reportPreferencesApi: ReportPreferencesApi = {
  async get(reportId: number): Promise<ReportPreferences> {
    const response = await apiClient.get<ReportPreferences>(
      `/api/reports/${reportId}/preferences`,
    )
    return response.data
  },

  async update(
    reportId: number,
    patch: ReportPreferencesPatch,
  ): Promise<ReportPreferences> {
    const response = await apiClient.put<ReportPreferences>(
      `/api/reports/${reportId}/preferences`,
      patch,
    )
    return response.data
  },
}
