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
import type { PctBadge } from '@/entities/motivation'
import type {
  PlanLayer,
  PlanScopeType,
  PlanScopeRef,
  PlanMatrixColumn,
  PlanMatrixCell,
  PlanMatrixRow,
  PlanMatrixTotals,
  PlanMatrixResponse,
} from '@/entities/planTargets'

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

// --- R4 · Task matrix (kind × user × 12 мес) (§6.7) ---------------------------

export interface TaskMatrixMeta {
  year: number
  layer: PlanLayer
  /** Always "count" for task matrices. */
  value_kind: 'count'
  /** $user->can('plans.manage') — FE renders inputs only when true. */
  can_edit: boolean
  base_currency?: string
  multi_currency_warning?: boolean
}

/**
 * One activity-kind matrix. Shape is the P-1 matrix restricted to that kind, so
 * a group adapts 1:1 to a `PlanMatrixResponse` (count cells) — the PlanMatrix
 * component + editing core are reused per selected kind.
 */
export interface TaskMatrixGroup {
  /** Activity kind key: call|meeting|task|note|follow_up|presentation, or "all". */
  kind: string
  /** RU label from the backend (no FE kind-label map needed). */
  label: string
  columns: PlanMatrixColumn[]
  rows: PlanMatrixRow[]
  totals: PlanMatrixTotals
}

export interface TaskMatrixResponse {
  meta: TaskMatrixMeta
  groups: TaskMatrixGroup[]
}

export interface TaskMatrixQuery {
  year: number
  layer: PlanLayer
  pipeline_id?: number | null
  /** Optional server-side kind filter (the FE selects a kind client-side too). */
  kind?: string | null
}

/**
 * Adapt one R4 group to a `PlanMatrixResponse` so the shared PlanMatrix component
 * and matrix-editing core render/edit it exactly like the income grid (count mode).
 */
export const taskGroupToMatrix = (
  group: TaskMatrixGroup,
  meta: TaskMatrixMeta,
): PlanMatrixResponse => ({
  meta: {
    metric: 'tasks_completed',
    scope_type: 'user',
    layer: meta.layer,
    year: meta.year,
    pipeline_id: null,
    value_kind: 'count',
    base_currency: meta.base_currency ?? 'RUB',
    fact_source: 'activities',
    multi_currency_warning: meta.multi_currency_warning ?? false,
    can_edit: meta.can_edit,
  },
  columns: group.columns,
  rows: group.rows,
  totals: group.totals,
})

// --- R5 · Conversions (§6.8) --------------------------------------------------

export interface ConversionReportMeta {
  year: number
  layer: PlanLayer
  pipeline_id: number | null
}

/** Numerator / denominator descriptor for a conversion pair (labels for the UI). */
export interface ConversionSide {
  /** "task" | "deal" | "stage". */
  type: string
  /** Activity kind (task numerator/denominator). */
  kind?: string | null
  /** Deal status (deal denominator, e.g. "won"). */
  status?: string | null
  /** Stage id (stage-based side). */
  stage_id?: number | null
}

/** One conversion-pair cell (plan% / fact% / raw counts + scored badge). */
export interface ConversionCell {
  /** Target % (null for stage-honest blocks — fact-only). */
  plan_pct?: number | null
  fact_pct: number | null
  num_count: number
  den_count: number
  /** scorePct(fact_pct, plan_pct) — null for fact-only stage block. */
  pct?: number | null
  badge?: PctBadge
}

/** A conversion pair (general/custom): plan-scored, keyed by column. */
export interface ConversionPair {
  plan_id?: number | null
  name: string
  numerator: ConversionSide
  denominator: ConversionSide
  columns: PlanMatrixColumn[]
  /** Keyed by column.key ("1".."12" | "annual"). */
  cells: Record<string, ConversionCell>
}

/**
 * A stage-honest conversion (fact-only, no plan) between two adjacent stages.
 *
 * As-built (R5): the backend ships this as a **period-aggregate** — one honest
 * first-entry conversion for the whole selected period (num/den/fact_pct flat on
 * the row), NOT a per-month `cells` map. There is no monthly breakdown by design
 * (first-entry counts across the period are the honest chain semantics).
 */
export interface StageConversion {
  name: string
  from_stage_id: number
  to_stage_id: number
  /** Reached the to-stage (numerator) across the period. */
  num_count: number
  /** Entered the from-stage (denominator) across the period. */
  den_count: number
  /** num_count / den_count × 100 (may be null when den_count = 0). */
  fact_pct: number | null
}

export interface ConversionReportResponse {
  meta: ConversionReportMeta
  /** Auto task-ratio pairs (SpaceCRM «Конверсии» general). */
  general?: ConversionPair[]
  /** User-defined pairs from plan_targets.config. */
  custom?: ConversionPair[]
  /** Honest stage conversions from deal_stage_history (fact-only, our advantage). */
  stage?: StageConversion[]
}

export interface ConversionReportQuery {
  year: number
  layer: PlanLayer
  pipeline_id?: number | null
  scope_type?: PlanScopeType
}

// --- R6 · Product income (Поступления по линейкам) (§6.9) ---------------------

export interface ProductIncomeMeta {
  year: number
  layer: PlanLayer
  base_currency: string
  /** "calculated on won deals, not on payment" badge basis. */
  fact_source: string
  multi_currency_warning: boolean
  /** $user->can('plans.manage') — FE renders inputs only when true. */
  can_edit: boolean
}

/**
 * One R6 cell — richer than a plain income cell: it carries the SpaceCRM
 * «Прогноз | Поступления» split (plan / expected / fact / total = fact+expected)
 * plus the two scored ratios (total_pct = total/plan, fact_pct = fact/plan).
 *
 * The `plan_kopecks`/`fact_kopecks`/`pct`/`badge`/`plan_id`/`currency` subset is
 * exactly a `PlanMatrixCell`, so a row adapts 1:1 to `PlanMatrixResponse` for the
 * shared editing core; the extra fields drive the R6-specific display.
 */
export interface ProductIncomeCell extends PlanMatrixCell {
  /** Open deals with expected_payment_date in the month, base kopecks. */
  expected_kopecks?: number | null
  /** fact + expected (SpaceCRM «Всего»), base kopecks. */
  total_kopecks?: number | null
  /** total / plan × 100. */
  total_pct?: number | null
  /** fact / plan × 100. */
  fact_pct?: number | null
}

export interface ProductIncomeRow {
  scope: PlanScopeRef
  /** Keyed by column.key ("1".."12" | "annual"). */
  cells: Record<string, ProductIncomeCell>
}

export type ProductIncomeTotals = Record<string, ProductIncomeCell>

export interface ProductIncomeResponse {
  meta: ProductIncomeMeta
  columns: PlanMatrixColumn[]
  rows: ProductIncomeRow[]
  totals: ProductIncomeTotals
}

export interface ProductIncomeQuery {
  year: number
  layer: PlanLayer
}

/**
 * Adapt an R6 product-income response to a `PlanMatrixResponse` so the shared
 * PlanMatrix component + editing core render/edit it exactly like the income
 * grid (money mode). The R6-only cell fields (expected/total/fact_pct) ride along
 * on each cell (ProductIncomeCell extends PlanMatrixCell) for a richer display.
 */
export const productIncomeToMatrix = (
  report: ProductIncomeResponse,
): PlanMatrixResponse => ({
  meta: {
    metric: 'product_income',
    scope_type: 'company',
    layer: report.meta.layer,
    year: report.meta.year,
    pipeline_id: null,
    value_kind: 'money',
    base_currency: report.meta.base_currency,
    fact_source: report.meta.fact_source,
    multi_currency_warning: report.meta.multi_currency_warning,
    can_edit: report.meta.can_edit,
  },
  columns: report.columns,
  rows: report.rows,
  totals: report.totals,
})
