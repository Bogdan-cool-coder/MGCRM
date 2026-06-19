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
}

// ─── Activity type (used in NextTaskDto) ─────────────────────────────────────

export type ActivityType = 'call' | 'meeting' | 'task' | 'note' | 'follow_up'

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

export interface BoardResponseDto {
  pipeline: { id: number; name: string; kind: string | null }
  columns: BoardColumnDto[]
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
  owner_user_id?: number
  extra_fields?: Record<string, unknown>
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

// ─── API list params ─────────────────────────────────────────────────────────

export interface DealListParams {
  view?: 'list' | 'board'
  pipeline_id?: number
  stage_id?: number | null
  owner_id?: number | null
  q?: string | null
  page?: number
  per_page?: number
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
