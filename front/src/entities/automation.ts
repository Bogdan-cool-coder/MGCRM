/**
 * Automation entities — PipelineAutomation, AutomationRun, enums, config types.
 * Typed manually from Laravel API Resources (M7).
 * Paths: POST/GET/PATCH/DELETE /api/automations, GET /api/automation-runs.
 */

// ─── Enums (mirroring backend) ────────────────────────────────────────────────

export type TriggerKind =
  | 'on_enter_stage'
  | 'on_create'
  | 'idle_in_stage_days'
  | 'date_field_approaching'

export type ActionKind =
  | 'tg_notify'
  | 'create_task'
  | 'set_field'
  | 'generate_document'
  | 'change_owner'
  | 'change_stage'
  | 'webhook'
  | 'email'

export type RunStatus = 'pending' | 'queued' | 'success' | 'skipped' | 'failed'

// ─── Trigger configs (one per TriggerKind) ───────────────────────────────────

export interface TriggerConfigOnEnterStage {
  // No required fields for on_enter_stage / on_create
  [key: string]: unknown
}

export interface TriggerConfigOnCreate {
  target_type?: string
  [key: string]: unknown
}

export interface TriggerConfigIdleInStageDays {
  days: number
  [key: string]: unknown
}

export interface TriggerConfigDateFieldApproaching {
  field: string
  days: number
  [key: string]: unknown
}

export type TriggerConfig =
  | TriggerConfigOnEnterStage
  | TriggerConfigOnCreate
  | TriggerConfigIdleInStageDays
  | TriggerConfigDateFieldApproaching

// ─── Action configs (one per ActionKind) ─────────────────────────────────────

// recipient is the single canonical spec string the engine's RecipientResolver
// reads: 'owner' | 'user_id:N' | 'chat_id:X'. The wizard composes it from its
// type/user/chat fields on emit; do NOT send recipient_type/user_id/chat_id
// separately — the backend ignores them.
export interface ActionConfigTgNotify {
  recipient: string
  message: string
  [key: string]: unknown
}

// body + responsible (spec string 'owner' | 'user_id:N') + due_days are the keys
// CreateTaskAction reads. The wizard's assignee_type/user_id are folded into
// `responsible` on emit; description is stored as `body`.
export interface ActionConfigCreateTask {
  title: string
  body?: string | null
  responsible?: string
  due_days?: number | null
  [key: string]: unknown
}

export interface ActionConfigSetField {
  field: string
  value: string
  [key: string]: unknown
}

export interface ActionConfigGenerateDocument {
  template_code: string
  attach_to?: 'deal' | 'company'
  [key: string]: unknown
}

export interface ActionConfigChangeOwner {
  rule?: 'round_robin' | 'by_product' | 'by_country' | 'by_department'
  pool?: number[]
  [key: string]: unknown
}

export interface ActionConfigChangeStage {
  to_stage_id: number
  [key: string]: unknown
}

export interface ActionConfigWebhook {
  url: string
  secret?: string | null
  [key: string]: unknown
}

export interface ActionConfigEmail {
  recipient_type: 'owner' | 'manual'
  to?: string | null
  subject: string
  body: string
  [key: string]: unknown
}

export type ActionConfig =
  | ActionConfigTgNotify
  | ActionConfigCreateTask
  | ActionConfigSetField
  | ActionConfigGenerateDocument
  | ActionConfigChangeOwner
  | ActionConfigChangeStage
  | ActionConfigWebhook
  | ActionConfigEmail
  | Record<string, unknown>

// ─── Main DTO ─────────────────────────────────────────────────────────────────

export interface AutomationDto {
  id: number
  name: string
  description: string | null
  pipeline_id: number
  stage_id: number | null
  trigger_kind: TriggerKind
  trigger_config: Record<string, unknown>
  action_kind: ActionKind
  action_config: Record<string, unknown>
  is_active: boolean
  created_by_user_id: number | null
  last_run_at: string | null
  created_at: string | null
  updated_at: string | null
  // Denormalised (when relations loaded)
  pipeline_name?: string | null
  stage_name?: string | null
  runs_count?: number
}

// ─── Automation Run ───────────────────────────────────────────────────────────

export interface AutomationRunDto {
  id: number
  automation_id: number
  automation_name?: string | null
  action_kind?: ActionKind | null
  target_type: string
  target_id: number
  status: RunStatus
  trigger_event_ts: string | null
  result: unknown | null
  error_message: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string | null
}

// ─── Dry-run (test) response ──────────────────────────────────────────────────

/** One matched deal from a dry-run (MatchedTarget::toArray()) */
export interface DryRunMatchedTarget {
  target_type: string
  target_id: number
  label: string
  matches_at: string | null
}

/** One action preview entry from a dry-run (ActionPreview::toArray() + target_id) */
export interface DryRunActionItem {
  target_id: number
  would_execute: boolean
  summary: string
  reason?: string | null
  [key: string]: unknown
}

/** DryRunResult::toArray() wrapped in { data: … } by AutomationController::test() */
export interface DryRunResponse {
  automation: {
    id: number
    name: string
    trigger_kind: string
    action_kind: string
  }
  match_count: number
  matched_targets: DryRunMatchedTarget[]
  actions_plan: DryRunActionItem[]
}

// ─── Payloads ─────────────────────────────────────────────────────────────────

export interface CreateAutomationPayload {
  name: string
  description?: string | null
  pipeline_id: number
  stage_id?: number | null
  trigger_kind: TriggerKind
  trigger_config?: Record<string, unknown>
  action_kind: ActionKind
  action_config?: Record<string, unknown>
  is_active?: boolean
}

export interface UpdateAutomationPayload {
  name?: string
  description?: string | null
  stage_id?: number | null
  trigger_kind?: TriggerKind
  trigger_config?: Record<string, unknown>
  action_kind?: ActionKind
  action_config?: Record<string, unknown>
  is_active?: boolean
}

// ─── Filter params ────────────────────────────────────────────────────────────

export interface AutomationListParams {
  pipeline_id?: number | null
  stage_id?: number | null
  trigger_kind?: TriggerKind | null
  is_active?: boolean | null
}

export interface AutomationRunListParams {
  automation_id?: number | null
  status?: RunStatus | null
  action_kind?: ActionKind | null
  from?: string | null
  to?: string | null
  per_page?: number
  page?: number
}

// ─── Execute response ─────────────────────────────────────────────────────────

export interface ExecuteResponse {
  executed: number
  skipped: number
  runs: AutomationRunDto[]
}
