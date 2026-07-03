/**
 * Sales-analytics report DTO types — R1 Registry + R2 Income schedule.
 *
 * Mirrors the backend contract `docs/contracts/plan-targets-api-contract.md`:
 *   - R1 GET /api/reports/registry         -> RegistryReportResponse (§6.4)
 *   - R2 GET /api/reports/income-schedule  -> IncomeScheduleResponse (§6.5)
 *
 * Money = integer kopecks (format on FE). Backend is built in parallel — until
 * the aggregators ship these calls 404 and the composables degrade gracefully
 * to an empty report (the established graceful-404 pattern, same as the plans tab).
 */

// --- Shared report meta -------------------------------------------------------

export interface ReportPeriod {
  year: number
  month: number
  /** RU label, e.g. «Январь 2026». */
  label: string
}

// --- R1 · Registry + Squeeze (§6.4) ------------------------------------------

export interface RegistryReportMeta {
  period: ReportPeriod
  pipeline_id: number | null
  base_currency: string
  /** "calculated on won deals, not on payment" badge basis. */
  fact_source: string
  multi_currency_warning: boolean
}

/** One deal owner reference. */
export interface RegistryOwner {
  id: number
  full_name: string
}

/** Product line a deal touches (name + product-group name). */
export interface RegistryProduct {
  name: string
  group: string
}

/** Last activity/task on the deal (result text + timestamp). */
export interface RegistryLastTask {
  result_text: string
  at: string
}

/**
 * One registry row (identical shape for both «expected» and «squeeze» lists).
 * `expected_payment_date` may be null in the squeeze list — those rows are
 * highlighted (no planned pay date, plan §7 risk).
 */
export interface RegistryRow {
  deal_id: number
  title: string
  company_name: string
  /** May be null for a deal with no assigned owner. */
  owner: RegistryOwner | null
  /** «Прямая / СБС» — derived, best-effort (contract O3). */
  deal_type: string
  products: RegistryProduct[]
  amount_kopecks: number
  currency: string
  amount_base_kopecks: number
  /** Paid so far (0 until Finance sprint). */
  fact_kopecks: number
  expected_payment_date: string | null
  signed_at: string | null
  last_task: RegistryLastTask | null
}

export interface RegistryExpectedBlock {
  rows: RegistryRow[]
  total_base_kopecks: number
}

export interface RegistrySqueezeBlock {
  rows: RegistryRow[]
  total_base_kopecks: number
  /** Open deals with NO expected_payment_date (UI warns). */
  no_date_count: number
  /**
   * The no-date deals themselves (same row shape). Separate from `rows` because
   * `rows` is built with whereNotNull(expected_payment_date), so filtering it for
   * null dates is always empty — the backend surfaces these here explicitly.
   * Optional: absent until the backend field ships (graceful → empty list).
   */
  no_date_rows?: RegistryRow[]
}

export interface RegistryReportResponse {
  meta: RegistryReportMeta
  expected: RegistryExpectedBlock
  squeeze: RegistrySqueezeBlock
}

export interface RegistryReportQuery {
  year: number
  month: number
  pipeline_id?: number | null
  manager_id?: number | null
  product_group_id?: number | null
}

// --- R2 · Income schedule (day calendar + cumulative) (§6.5) ------------------

export interface IncomeScheduleMeta {
  period: ReportPeriod
  days_in_month: number
  base_currency: string
  multi_currency_warning: boolean
}

/** One calendar day — four kopeck series + cumulative plan/fact. */
export interface IncomeScheduleDay {
  day: number
  is_weekend: boolean
  /** Paid on this day (won recognition date), base kopecks. */
  fact_base_kopecks: number
  /** expected_payment_date == this day, open, base kopecks. */
  expected_base_kopecks: number
  /** Overdue expected rolled onto today, base kopecks. */
  squeeze_base_kopecks: number
  cumulative_plan_base_kopecks: number
  cumulative_fact_base_kopecks: number
}

export interface IncomeScheduleResponse {
  meta: IncomeScheduleMeta
  /** new_income plan for the month (from plan_targets), base kopecks. */
  plan_total_base_kopecks: number
  days: IncomeScheduleDay[]
}

export interface IncomeScheduleQuery {
  year: number
  month: number
  pipeline_id?: number | null
}

// --- R3 · Best manager (yearly rating) (§6.6) --------------------------------

/** Scoring mode: `standard` excludes out-of-standings accounts from ranking. */
export type BestManagerMode = 'standard' | 'absolute'

export interface BestManagerMeta {
  year: number
  mode: BestManagerMode
  base_currency: string
  multi_currency_warning: boolean
}

/** One rated manager (yearly aggregates + points). */
export interface BestManagerRow {
  /** Rank in the standings; may be null for out-of-standings accounts. */
  rank: number | null
  user: RegistryOwner
  /** Division/pipeline label, e.g. «MACRO Global». */
  division: string
  won_deals: number
  supports: number
  new_income_base_kopecks: number
  avg_check_base_kopecks: number
  income_points: number
  total_points: number
  /** False → excluded from ranking (service/admin accounts) → «вне зачёта». */
  in_standings: boolean
}

/** The year's leader (hero card). Absent when there is no data. */
export interface BestManagerLeader {
  user: RegistryOwner
  division: string
  won_deals: number
  total_points: number
  new_income_base_kopecks: number
}

export interface BestManagerResponse {
  meta: BestManagerMeta
  rows: BestManagerRow[]
  /** Null when the year has no rated managers (empty state). */
  leader: BestManagerLeader | null
}

export interface BestManagerQuery {
  year: number
  mode?: BestManagerMode
  pipeline_id?: number | null
}
