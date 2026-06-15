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

export interface ActionConfigTgNotify {
  recipient_type: 'owner' | 'user' | 'chat_id'
  user_id?: number | null
  chat_id?: string | null
  message: string
  [key: string]: unknown
}

export interface ActionConfigCreateTask {
  title: string
  description?: string | null
  assignee_type?: 'owner' | 'user'
  user_id?: number | null
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

export interface DryRunRecord {
  id: number
  type: string
  title: string
}

export interface DryRunResponse {
  automation_id: number
  matched_count: number
  matched_records: DryRunRecord[]
  actions_plan: string
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
