/**
 * Sales entities — Pipeline, Deal, DealProduct, DealContact, LostReason, History.
 * Typed manually from Laravel API Resources (S1.3).
 * All monetary values are integers (kopecks).
 */

// ─── Pipeline / Stage ─────────────────────────────────────────────────────────

export type PipelineKind = 'sales' | 'onboarding'

export interface PipelineStageDto {
  id: number
  pipeline_id: number
  name: string
  code: string
  color: string | null
  sort_order: number
  is_won: boolean
  is_lost: boolean
  won_gate: boolean
  hidden_by_default: boolean
  parent_stage_id: number | null
  stage_features: string[]
  sla_hours: number | null
  task_types: string[]
  required_fields: { deal?: string[]; company?: string[] }
  visible_department_ids?: number[] | null
  visible_user_ids?: number[] | null
  children?: PipelineStageDto[]
  warn_days: number | null
  danger_days: number | null
}

// ─── Graph layout (canvas positions) ─────────────────────────────────────────

export interface GraphLayoutNodes {
  [nodeId: string]: { x: number; y: number }
}

export interface GraphLayout {
  nodes: GraphLayoutNodes
}

export interface PipelineDto {
  id: number
  name: string
  kind: PipelineKind
  stages: PipelineStageDto[]
  graph_layout: GraphLayout | null
  created_at: string | null
  updated_at: string | null
}

// ─── User ref (minimal) ───────────────────────────────────────────────────────

export interface UserRefDto {
  id: number
  name: string
  avatar_path: string | null
}

// ─── Company ref (minimal) ────────────────────────────────────────────────────

export interface CompanyRefDto {
  id: number
  name: string
}

// ─── Deal ─────────────────────────────────────────────────────────────────────

export type DealStatus = 'open' | 'won' | 'lost'

export interface DealDto {
  id: number
  title: string
  company: CompanyRefDto
  pipeline: { id: number; name: string; kind: string | null }
  stage: PipelineStageDto
  owner: UserRefDto
  department_id: number | null
  department_name?: string | null
  status: DealStatus
  amount: number
  currency: string
  tags: string[]
  extra_fields: Record<string, unknown>
  expected_close_date: string | null
  expected_sign_date: string | null
  expected_payment_date: string | null
  stage_changed_at: string | null
  lost_reason_id: number | null
  lost_reason: string | null
  created_at: string
  updated_at: string
  // v2 additions — populated when backend eager-loads nextTask + computes days_in_stage
  next_task?: NextTaskDto | null
  days_in_stage?: number | null
  products?: DealProductDto[]
  contacts?: DealContactDto[]
  /** Sum of all per-line discounts (kopecks). Present when products relation is loaded. */
  discount_total?: number
  /**
   * Key-action bar — 6 entries in stable order (DealPage 2.0).
   * Always present on show/mark endpoints; dates are null on list payloads.
   */
  key_actions?: DealKeyAction[]
  /** ISO timestamp of КП submission; null when not yet sent. */
  kp_sent_at?: string | null
  /** ISO timestamp of contract submission; null when not yet sent. */
  contract_sent_at?: string | null
  /** True when deal products use perpetual (one-time) pricing model. */
  perpetual_license?: boolean
  /** True when deal amount is manually locked (does not auto-recalc from line items). */
  amount_locked?: boolean
  /** ISO date: actual contract signing date (факт). */
  signed_at?: string | null
  /** ISO date: actual payment date (факт). */
  paid_at?: string | null
  /** Actual paid amount in kopecks (integer). Distinct from amount (budget). */
  paid_amount?: number | null
  /** ISO currency code for the paid amount (RUB, USD, EUR, KZT, UZS, AED). */
  payment_currency?: string | null
  /**
   * ISO-2 lowercase country code (e.g. "kz") from company.
   * Present on list payloads when backend eager-loads company country.
   */
  country?: string | null
  /**
   * Deal category: L (large), M (medium), S1 / S2 (small tiers).
   * S1+S2 should be aggregated as "S" in UI KPI chips.
   */
  category?: 'L' | 'M' | 'S1' | 'S2' | null
  /**
   * ISO-8601 timestamp of last activity (contact) on this deal.
   * Used for freshness colouring in list view.
   */
  last_contact_at?: string | null
  /**
   * Aggregated deal metrics — present on SHOW endpoint only.
   * NOT included in list/board/store/update payloads.
   */
  metrics?: DealMetricsDto
  /**
   * Deal-level discount percent (0..50). Present on all payloads, defaults 0.
   */
  discount_percent: number
  /**
   * Sum of all product line amounts before deal-level discount (kopecks).
   * Present on SHOW endpoint only (products relation loaded).
   */
  products_gross_total?: number
  /**
   * Grand total after deal-level discount_percent applied (kopecks).
   * Present on SHOW endpoint only.
   */
  products_net_total?: number
  /**
   * Per-line net amounts after deal-level discount_percent.
   * Present on SHOW endpoint only.
   */
  products_discounted?: Array<{ id: number; net_amount: number }>
}

// ─── Activity type (used in NextTaskDto) ─────────────────────────────────────

export type ActivityType = 'call' | 'meeting' | 'task' | 'note' | 'follow_up' | 'presentation'

// ─── Key actions (DealPage 2.0 header bar) ────────────────────────────────────

export type KeyActionType =
  | 'last_presentation'
  | 'max_stage'
  | 'kp_sent'
  | 'contract_sent'
  | 'last_touch'
  | 'last_event'

export interface MaxStageRef {
  stage_id: number
  name: string
  color: string | null
}

export interface DealKeyAction {
  type: KeyActionType
  /** ISO date-time or null when the action never happened */
  date: string | null
  /** Populated only for max_stage — the high-water mark stage ref */
  ref?: MaxStageRef | null
}

// ─── Next task (board card health signal) ─────────────────────────────────────

export interface NextTaskDto {
  id: number
  type: ActivityType
  title: string | null
  due_at: string | null
  is_overdue: boolean
}

// ─── Primary product (board card line-item) ───────────────────────────────────

export interface PrimaryProductDto {
  id: number
  name: string
}

// ─── Deal card (board view — lighter payload) ─────────────────────────────────

export interface DealCardDto {
  id: number
  title: string
  company: CompanyRefDto
  stage_id: number
  owner: UserRefDto
  amount: number
  currency: string
  stage_changed_at: string | null
  days_in_stage: number | null
  next_task: NextTaskDto | null
  primary_product: PrimaryProductDto | null
}

// ─── Board response ───────────────────────────────────────────────────────────

export interface BoardColumnDto {
  stage: PipelineStageDto
  total: number
  sum_amount: number
  base_currency: string
  currency: string
  amounts_by_currency: Record<string, number>
  multi_currency_warning: boolean
  fx_rate_available: boolean
  deals: DealCardDto[]
  has_more: boolean
}

// ─── Hidden stage entry (returned by board endpoint) ─────────────────────────

/**
 * A hidden-by-default stage entry returned in the top-level `hidden_stages`
 * array of the board response. Deals_count is scope+filter-aware.
 */
export interface HiddenStageDto {
  id: number
  name: string
  color: string | null
  sort_order: number
  deals_count: number
}

export interface BoardResponseDto {
  pipeline: { id: number; name: string; kind: string | null }
  columns: BoardColumnDto[]
  /** All hidden-by-default stages in funnel order (regardless of revealed set). */
  hidden_stages: HiddenStageDto[]
}

/**
 * Raw shape returned by DealController::board() before frontend adapter.
 * Backend sends columns as a keyed object indexed by stage_id, plus a
 * separate top-level `stages` array.
 */
export interface BoardRawColumnDto {
  stage_id: number
  total: number
  sum_amount: number
  base_currency: string
  amounts_by_currency: Record<string, number>
  multi_currency_warning: boolean
  rate_available?: boolean
  deals: Array<{
    id: number
    title: string
    amount: number
    currency: string
    stage_id: number
    company_id: number
    company_name: string | null
    owner: { id: number; full_name: string } | null
    stage_changed_at: string | null
    days_in_stage: number | null
    next_task: NextTaskDto | null
    primary_product: PrimaryProductDto | null
  }>
}

export interface BoardRawResponseDto {
  pipeline: { id: number; name: string; kind: string | null }
  stages: PipelineStageDto[]
  columns: Record<string, BoardRawColumnDto>
  hidden_stages?: HiddenStageDto[]
}

// ─── Deal Product (line-item) ─────────────────────────────────────────────────

export interface ProductRefDto {
  id: number
  name: string
  code: string
}

export interface PlanRefDto {
  id: number
  name: string
  unit: string
}

export interface DealProductDto {
  id: number
  deal_id: number
  product_id: number
  product: ProductRefDto
  plan_id: number | null
  plan: PlanRefDto | null
  quantity: number
  unit_price: number
  /** Currency code for this line item (e.g. 'KZT', 'RUB', 'USD', 'EUR'). */
  currency: string
  /** Per-line manual discount, kopecks, default 0 */
  discount: number
  /** NET amount = max(0, round(quantity*unit_price) - discount), kopecks */
  amount: number
  created_at: string
  updated_at: string
}

// ─── Deal Contact ─────────────────────────────────────────────────────────────

export interface ContactRefDto {
  id: number
  full_name: string
  email: string | null
  phone: string | null
  position: string | null
}

export interface DealContactDto {
  id: number
  contact: ContactRefDto
  is_primary: boolean
}

// ─── Lost Reason ──────────────────────────────────────────────────────────────

export interface LostReasonDto {
  id: number
  name: string
  is_active: boolean
}

// ─── Stage History ────────────────────────────────────────────────────────────

export interface DealStageHistoryDto {
  id: number
  deal_id: number
  from_stage: PipelineStageDto | null
  to_stage: PipelineStageDto
  user: UserRefDto | null
  created_at: string
}

// ─── Paginated response ───────────────────────────────────────────────────────

export interface SalesPaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

// ─── Payloads ─────────────────────────────────────────────────────────────────

export interface CreateDealPayload {
  company_id: number
  title: string
  pipeline_id: number
  stage_id?: number
  currency: string
  owner_user_id: number
  expected_close_date?: string | null
}

export interface UpdateDealPayload {
  title?: string
  currency?: string
  tags?: string[]
  expected_close_date?: string | null
  expected_sign_date?: string | null
  expected_payment_date?: string | null
  signed_at?: string | null
  paid_at?: string | null
  owner_user_id?: number
  /** Change the deal's associated company. */
  company_id?: number
  extra_fields?: Record<string, unknown>
  perpetual_license?: boolean
  amount_locked?: boolean
  /** Actual paid amount in kopecks (integer, min 0). */
  paid_amount?: number | null
  /** ISO currency code for the payment (RUB, USD, EUR, KZT, UZS, AED). */
  payment_currency?: string | null
  /** Deal-level discount percent (0..50). FE clamps; backend clamps >50 silently. */
  discount_percent?: number
}

export interface MoveDealPayload {
  to_stage_id: number
  lost_reason_id?: number | null
  lost_reason?: string | null
}

export interface AddDealProductPayload {
  product_id: number
  plan_id?: number | null
  quantity: number
  unit_price?: number | null
}

export interface UpdateDealProductPayload {
  quantity?: number
  unit_price?: number
  /** Per-line manual discount, kopecks, min 0 */
  discount?: number
}

export interface AddDealContactPayload {
  contact_id: number
  is_primary?: boolean
}

// ─── Pipeline / Stage CRUD payloads (S1.5) ───────────────────────────────────

export interface CreatePipelinePayload {
  name: string
  kind?: string
  is_active?: boolean
  sort_order?: number
}

export interface UpdatePipelinePayload {
  name?: string
  is_active?: boolean
  sort_order?: number
  graph_layout?: GraphLayout | null
}

export interface CreateStagePayload {
  name: string
  code: string
  color?: string | null
  hidden_by_default?: boolean
  won_gate?: boolean
  sla_hours?: number | null
  stage_features?: string[]
  task_types?: string[]
  required_fields?: { deal?: string[]; company?: string[] }
  parent_stage_id?: number | null
  sort_order?: number
  warn_days?: number | null
  danger_days?: number | null
}

export interface UpdateStagePayload {
  name?: string
  color?: string | null
  hidden_by_default?: boolean
  won_gate?: boolean
  sla_hours?: number | null
  stage_features?: string[]
  task_types?: string[]
  required_fields?: { deal?: string[]; company?: string[] }
  parent_stage_id?: number | null
  warn_days?: number | null
  danger_days?: number | null
}

export interface ReorderStageItem {
  id: number
  sort_order?: number
}

// ─── Deal metrics (present on SHOW only — not on list/board/store/update payloads) ───

export interface DealMetricsDto {
  /** Calendar days from deal created_at until now */
  days_in_deal: number
  /** Calendar days the deal has been in the current stage */
  days_in_stage: number
  /** Total count of activities on the deal */
  activities_count: number
  /** Number of stage transitions (excludes the creation row) */
  stage_changes_count: number
  /** Number of documents attached to the deal */
  documents_count: number
  /** ISO-8601 timestamp of the latest activity, or null */
  last_activity_at: string | null
}

// ─── API list params ─────────────────────────────────────────────────────────

export interface DealListParams {
  view?: 'list' | 'board'
  pipeline_id?: number
  stage_id?: number | null
  stage_ids?: number[]
  owner_id?: number | null
  owner_ids?: number[]
  q?: string | null
  page?: number
  per_page?: number
  // Extended overlay filters
  status?: 'open' | 'won' | 'lost' | null
  only_mine?: boolean
  only_no_task?: boolean
  only_overdue?: boolean
  product_q?: string | null
  country?: string | null
  city?: string | null
  budget_from?: number | null
  budget_to?: number | null
  tags?: string[]
  created_from?: string | null
  created_to?: string | null
  archived?: boolean
  /**
   * Server-side sort column key. Whitelist:
   * name | country | amount | stage | days_in_stage | last_contact | owner | created
   */
  sort_by?: string | null
  /** Sort direction (default: desc) */
  sort_dir?: 'asc' | 'desc' | null
  /**
   * Board-only: stage IDs to reveal in addition to always-visible stages.
   * Sends as repeated query param: revealed_stage_ids[]=12&revealed_stage_ids[]=18
   */
  revealed_stage_ids?: number[]
}

// ─── KPI aggregate (GET /api/deals/kpi) ──────────────────────────────────────

export interface DealKpiDto {
  pipeline_id: number | null
  in_work: number
  cat_l: number
  cat_m: number
  cat_s: number
  won: number
  no_task: number
  overdue: number
}

// ─── Bulk payloads (PATCH /api/deals/bulk, DELETE /api/deals/bulk) ────────────

export type BulkDealField = 'owner_id' | 'tags' | 'extra_fields' | 'currency'

export interface BulkPatchDealsPayload {
  deal_ids: number[]
  operation: 'change_owner' | 'change_stage' | 'set_field' | 'edit_tags'
  owner_id?: number | null
  stage_id?: number | null
  field?: BulkDealField
  value?: unknown
  tags?: string[]
}

export interface BulkDeleteDealsPayload {
  deal_ids: number[]
}

// ─── Bulk activity payload (POST /api/activities/bulk) ───────────────────────

export interface BulkCreateActivityPayload {
  deal_ids: number[]
  type: string
  title: string
  body?: string | null
  responsible_id?: number | null
  due_at?: string | null
  priority?: string
}
