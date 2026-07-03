/**
 * Plan Targets (Sales Analytics) DTO types.
 *
 * Mirrors the backend contract docs/contracts/plan-targets-api-contract.md:
 *   - P-1 GET /api/plans/matrix        -> PlanMatrixResponse (sec 6.1)
 *   - P-2 POST /api/plans/cells        -> UpsertCellsPayload / PlanCell[] (sec 6.2)
 *   - P-3 POST /api/plans/copy-previous (sec 6.3)
 *
 * Money = integer kopecks (format on FE). Counts = plain integers. pct may be
 * null (no plan -> dash). The cell shape (plan/fact kopecks|count + pct/badge/
 * plan_id/currency) is the contract every metric grid builds on - keep it stable.
 */
import type { PctBadge } from '@/entities/motivation'

// --- Enums (string unions, mirror the PHP enums) -----------------------------

/** sec 3.1 - Phase 1 wires new_income end-to-end; others declared for the sub-tab shell. */
export type PlanMetric =
  | 'new_income'
  | 'expected_deals'
  | 'tasks_completed'
  | 'conversion'
  | 'product_income'

/** sec 3.2 - which axis a cell targets. */
export type PlanScopeType = 'user' | 'pipeline' | 'company'

/** sec 3.4 - revisable operative vs fixed annual layer. */
export type PlanLayer = 'operative' | 'annual'

/** Response meta.value_kind - FE picks kopeck-formatter vs int. */
export type PlanValueKind = 'money' | 'count'

// --- Matrix (P-1) ------------------------------------------------------------

export interface PlanMatrixMeta {
  metric: PlanMetric
  scope_type: PlanScopeType
  layer: PlanLayer
  year: number
  pipeline_id: number | null
  value_kind: PlanValueKind
  base_currency: string
  /** "calculated on won deals, not on payment" badge basis. */
  fact_source: string
  multi_currency_warning: boolean
  /** $user->can('plans.manage') - FE renders inputs only when true. */
  can_edit: boolean
}

/** 12 months + the derived "annual" column. */
export interface PlanMatrixColumn {
  key: string
  label: string
  /** 1..12 for months; null for the annual column. */
  period_month: number | null
}

export interface PlanScopeRef {
  type: PlanScopeType | 'product_group'
  id: number | null
  label: string
}

/** Per-currency breakdown for the info-popover (money metrics only). */
export interface PlanCurrencyBreakdown {
  currency: string
  plan_kopecks: number
}

/**
 * One matrix cell. Money metrics carry plan_kopecks/fact_kopecks/currency;
 * count metrics carry plan_count/fact_count. plan_id is present on real
 * stored monthly cells (null on the derived annual column).
 */
export interface PlanMatrixCell {
  plan_kopecks?: number | null
  fact_kopecks?: number | null
  plan_count?: number | null
  fact_count?: number | null
  pct: number | null
  badge: PctBadge
  currency?: string | null
  has_plan?: boolean
  plan_id?: number | null
  currency_breakdown?: PlanCurrencyBreakdown[]
}

export interface PlanMatrixRow {
  scope: PlanScopeRef
  /** Keyed by column.key ("1".."12" | "annual"). */
  cells: Record<string, PlanMatrixCell>
}

/** TOTAL row - sum across scope rows, per column, in base currency. */
export type PlanMatrixTotals = Record<string, PlanMatrixCell>

export interface PlanMatrixResponse {
  meta: PlanMatrixMeta
  columns: PlanMatrixColumn[]
  rows: PlanMatrixRow[]
  totals: PlanMatrixTotals
}

// --- Matrix query (P-1 params) -----------------------------------------------

export interface PlanMatrixQuery {
  metric: PlanMetric
  scope_type: PlanScopeType
  layer: PlanLayer
  year: number
  pipeline_id?: number | null
  /** Optional single-month drill (rarely used by the grid). */
  month?: number | null
}

// --- Bulk upsert (P-2 payload) -----------------------------------------------

/** One cell in a bulk-upsert body (sec 6.2). Setting value to null = delete. */
export interface UpsertCellData {
  metric: PlanMetric
  scope_type: PlanScopeType
  scope_user_id?: number | null
  scope_pipeline_id?: number | null
  scope_product_group_id?: number | null
  period_year: number
  period_month: number | null
  layer: PlanLayer
  value_kopecks?: number | null
  value_count?: number | null
  currency?: string | null
  config?: Record<string, unknown> | null
}

export interface BulkUpsertCellsPayload {
  cells: UpsertCellData[]
}

/** P-2 response - the affected cells (same shape as a matrix cell + plan_id). */
export interface PlanCell extends PlanMatrixCell {
  plan_id: number | null
}

// --- Copy previous period (P-3 payload) --------------------------------------

export interface CopyPreviousPayload {
  metric: PlanMetric
  layer: PlanLayer
  scope_type: PlanScopeType
  from_year: number
  from_month?: number | null
  to_year: number
  to_month?: number | null
  pipeline_id?: number | null
}

export interface CopyPreviousResult {
  created: number
}
