/**
 * Per-user × per-report UI preferences synchronised with the backend.
 *
 * Endpoint contract (see backend `ReportPreferencesController`):
 *   GET  /api/reports/{report}/preferences   → always 200, defaults if no row
 *   PUT  /api/reports/{report}/preferences   → partial upsert; omitted fields
 *                                              untouched, explicit `null` clears
 *
 * Only the flat-table `column_order` preference is synced — the dashboard-on-
 * report view (with its `view_mode` / `dashboard_layout` / `hidden_widget_groups`
 * fields) was removed; dashboards became standalone entities.
 *
 * `localStorage` key (`vizion-column-order-*`) remains as a fast-render cache
 * so the UI paints immediately on report open; the API response is the source
 * of truth and overwrites the cache as soon as it arrives.
 */

/**
 * Persisted column DnD + visibility state for the flat-table view.
 *   - `order`  — fields in user-curated order (source of truth for column
 *                position; missing/new fields are appended in the consumer)
 *   - `hidden` — per-column visibility. Field names listed here are hidden
 *                from the table. Absent / empty array means "all visible".
 *
 * `null` for the whole `column_order` field means "user has not customised"
 * — the consumer falls back to the config-default order. PUT-ing `null`
 * explicitly clears any prior customisation on the backend.
 */
export interface ReportColumnOrderPreference {
  order: string[]
  hidden?: string[]
}

export interface ReportPreferences {
  report_id: number
  column_order: ReportColumnOrderPreference | null
}

/**
 * PUT payload — `Partial<ReportPreferences>` minus the server-owned
 * `report_id`. Fields not present in the patch are not touched by the backend
 * (partial upsert). `null` is an explicit clear, distinct from "omit".
 */
export type ReportPreferencesPatch = Partial<Omit<ReportPreferences, 'report_id'>>
