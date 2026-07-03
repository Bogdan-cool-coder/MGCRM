/**
 * Motivation Card (МК) entity — types mirror the Phase-A backend contract
 * (`docs/contracts/motivation-cards-api-contract.md`, §6 shapes B-1…B-5).
 *
 * Money is ALWAYS integer kopecks — format on the frontend
 * (`utils/currency.ts` `formatCurrency`). Percentages are whole integers,
 * `null` when no plan is set (→ render «—»).
 *
 * The backend for МК is built in parallel (`sales-backender`); until B-1…B-3
 * land, the API layer degrades gracefully (404 → null). See `api/motivation.ts`.
 */

// ─── Shared enums ─────────────────────────────────────────────────────────────

export type MotivationStatus = 'draft' | 'finalized' | 'paid'

export type MotivationItemKind =
  | 'base_salary'
  | 'commission'
  | 'kpi'
  | 'bonus'
  | 'team_kpi'

export type KpiType = 'count' | 'amount' | 'manual'

/** Fact source flag — Phase A always `won_deals`; Phase B → `payments`. */
export type FactBasis = 'won_deals' | 'payments'

/** PctTag / badge severity. `none` = no plan → muted «—». */
export type PctBadge = 'success' | 'warning' | 'danger' | 'none'

// ─── B-1 · Cabinet card (read-only) ───────────────────────────────────────────

export interface MkUserRef {
  id: number
  full_name: string
  avatar_path?: string | null
}

export interface MkPipelineRef {
  id: number
  name: string
}

export interface MkPeriod {
  year: number
  month: number
  label: string
}

export interface MkCommissionBreakdownRow {
  deal_id: number
  company_name: string
  amount_kopecks: number
  currency: string
}

/** `params` bag — a flexible union per kind. All money stays in `*_kopecks`. */
export interface MkItemParams {
  payment_note?: 'immediate' | 'next_month' | string
  rate_pct_times_100?: number
  condition?: string
  breakdown?: MkCommissionBreakdownRow[]
  // KPI (flexible — §9 ОВ-5)
  kpi_type?: KpiType
  unit?: string
  plan_count?: number
  fact_count?: number
  salary_per_completion_kopecks?: number
  manual_done?: boolean
  // team_kpi snapshot
  split_contribution_pct?: number
  split_equal_pct?: number
  min_threshold_pct?: number
  gate_passed?: boolean
  dept_pct?: number
  part1_kopecks?: number
  part2_kopecks?: number
  reason?: string
}

export interface MkCardItem {
  kind: MotivationItemKind
  name: string
  sort: number
  plan_amount_kopecks: number
  fact_amount_kopecks: number
  currency: string
  salary_plan_kopecks: number
  salary_fact_kopecks: number
  /** null when no plan → render «—». */
  pct: number | null
  badge: PctBadge
  params: MkItemParams | null
}

export interface MkRatePair {
  from: string
  to: string
  rate: string
}

export interface MkCardMeta {
  user: MkUserRef
  supervisor: MkUserRef | null
  pipeline: MkPipelineRef | null
  period: MkPeriod
  status: MotivationStatus
  base_currency: string
  fact_source: FactBasis
  multi_currency_warning: boolean
  has_card: boolean
}

export interface MkDeptPlan {
  target_kopecks: number
  target_currency: string
  fact_kopecks: number
  pct: number | null
  badge: PctBadge
}

export interface MkTeamBonusForecast {
  gate_passed: boolean
  dept_pct: number | null
  threshold_pct: number
  part1_kopecks: number
  part2_kopecks: number
  total_kopecks: number
  currency: string
}

export interface MkTotal {
  salary_plan_kopecks: number
  salary_fact_kopecks: number
  currency: string
}

export interface MkRates {
  date: string
  source: string
  pairs: MkRatePair[]
}

/** Full B-1 payload. */
export interface MotivationCard {
  meta: MkCardMeta
  dept_plan: MkDeptPlan | null
  items: MkCardItem[]
  total: MkTotal
  team_bonus_forecast: MkTeamBonusForecast | null
  rates: MkRates | null
}

// ─── B-2 · Editable plan (constructor) ────────────────────────────────────────

export interface MkPlanItem {
  kind: MotivationItemKind
  name: string
  plan_amount_kopecks: number
  fact_amount_kopecks?: number
  currency: string
  params: MkItemParams | null
}

export interface MkTeamKpiRule {
  pipeline_id: number | null
  team_income_target_kopecks: number
  target_currency: string
  base_pool_kopecks: number
  per_extra_member_kopecks: number
  min_members: number
  pool_currency: string
  split_contribution_pct: number
  split_equal_pct: number
  min_threshold_pct: number
}

export interface MotivationPlan {
  id: number | null
  user_id: number
  year: number
  month: number
  pipeline_id: number | null
  base_currency: string
  supervisor_user_id: number | null
  status: MotivationStatus
  items: MkPlanItem[]
  team_kpi_rule: MkTeamKpiRule | null
}

// ─── B-3 · Upsert payload ─────────────────────────────────────────────────────

export interface UpsertPlanItemPayload {
  kind: MotivationItemKind
  name: string
  plan_amount_kopecks?: number
  fact_amount_kopecks?: number
  currency: string
  params?: MkItemParams | null
}

export interface UpsertPlanPayload {
  user_id: number
  year: number
  month: number
  pipeline_id: number | null
  base_currency: string
  supervisor_user_id?: number | null
  items: UpsertPlanItemPayload[]
  team_kpi_rule?: MkTeamKpiRule | null
}

// ─── B-5 · Manager option (AutoComplete) ──────────────────────────────────────

export interface MkManagerOption {
  id: number
  full_name: string
  email: string
  avatar_path: string | null
  role: string
}

// ─── UI-local constants ───────────────────────────────────────────────────────

export const MK_ITEM_ICONS: Record<MotivationItemKind, string> = {
  base_salary: 'pi pi-wallet',
  commission: 'pi pi-percentage',
  kpi: 'pi pi-chart-bar',
  bonus: 'pi pi-star',
  team_kpi: 'pi pi-users',
}

/** Positions that participate in the constructor toggles, in display order. */
export const MK_POSITION_ORDER: MotivationItemKind[] = [
  'base_salary',
  'commission',
  'kpi',
  'bonus',
  'team_kpi',
]
