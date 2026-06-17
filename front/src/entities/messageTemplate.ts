/**
 * MessageTemplate entities — S2.10 Documents module.
 * Message templates for Telegram / email / SMS dispatch.
 */

// ChannelKind enum values from backend
export type MessageChannel = 'tg' | 'wa' | 'email' | 'web_form' | 'api'
export type ActivityTypeBinding = 'call' | 'meeting' | 'task' | 'note'

// ─── Entities ────────────────────────────────────────────────────────────────

export interface MessageTemplateBindingDto {
  id: number
  message_template_id: number
  channel_kind: MessageChannel | null
  channel_label: string | null
  pipeline_id: number | null
  pipeline_name: string | null
  pipeline_stage_id: number | null
  stage_name: string | null
  activity_type: ActivityTypeBinding | null
  automation_slot: string | null
}

export interface MessageTemplateDto {
  id: number
  title: string
  subject: string | null
  body: string
  is_active: boolean
  bindings: MessageTemplateBindingDto[]
  created_at: string
  updated_at: string
}

export interface MessageTemplateListItemDto {
  id: number
  title: string
  is_active: boolean
  bindings: MessageTemplateBindingDto[]
  created_at: string
}

// ─── Payloads ────────────────────────────────────────────────────────────────

export interface CreateMessageTemplatePayload {
  title: string
  subject?: string | null
  body: string
  is_active?: boolean
}

export type PatchMessageTemplatePayload = Partial<CreateMessageTemplatePayload>

export interface CreateBindingPayload {
  channel_kind: MessageChannel | null
  pipeline_id?: number | null
  pipeline_stage_id?: number | null
  activity_type?: ActivityTypeBinding | null
  automation_slot?: string | null
}

export interface PreviewPayload {
  vars: Record<string, string>
}

export interface PreviewResponseDto {
  subject: string | null
  body: string
  unresolved_keys: string[]
}
