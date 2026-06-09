import type { WidgetConfigDto } from '@/api/types/widgets'

export type ChatMessageRole = 'user' | 'assistant' | 'system'
export type ChatType =
  | 'report_generation'
  | 'quick_qa'
  | 'widget_generation'
  | 'document_template'

/**
 * UI-level scope of a chat (independent from the AI `type` enum).
 *
 * `'report'` — chat is bound to a specific report (MiniChat opened from `/reports/{id}`).
 * `'dashboard'` — chat is bound to a specific dashboard (MiniChat opened from `/dashboards/{id}`).
 * `'document'` — chat is bound to a specific document template (docx field auto-mapping,
 *    or the document-page MiniChat). Carries `document_id`.
 * `'general'` — free-form chat unbound from any report / dashboard / document.
 *
 * `'report_generation'` / `'widget_generation'` / `'document_template'` live in
 * the separate `type` enum (see `ChatType`), never in `scope_type`.
 * `GET /api/chats` and `GET /api/chats/resume` validate `scope_type` server-side.
 */
export type ChatScopeType = 'report' | 'dashboard' | 'document' | 'general'

/**
 * Lifecycle status for assistant messages in the M4 async flow.
 * `pending` — job queued, AI turn not started.
 * `running` — worker picked up the job, AI / tool-calls in progress.
 * `done` / `error` / `cancelled` — terminal states.
 * `null`/missing — legacy sync-flow message (pre-M4) or user/system role.
 */
export type ChatMessageStatus = 'pending' | 'running' | 'done' | 'error' | 'cancelled'

export interface ChatToolCallDto {
  name: string
  arguments: string
}

export interface ChatUsageDto {
  prompt_tokens: number
  completion_tokens: number
  total_tokens?: number
}

export interface ChatMessageErrorDto {
  exception_class?: string
  message?: string
  [key: string]: unknown
}

export interface ChatMessageMetadataDto {
  finish_reason?: string | null
  usage?: ChatUsageDto | null
  tool_calls?: ChatToolCallDto[] | null
  tool_results?: unknown[] | null
  /**
   * `error` field shape changed with M4: now `{exception_class, message}` instead of a plain string.
   * Legacy sync-flow messages may still carry a string value — readers must accept both.
   */
  error?: ChatMessageErrorDto | string | null
  [key: string]: unknown
}

export interface ChatAiContextDto {
  last_tool_calls?: string[]
  total_steps?: number
  probed_models?: string[]
  report_created?: boolean
  [key: string]: unknown
}

export interface ChatMessageDto {
  id: number
  chat_id: number
  user_id?: number
  company_id?: number
  role: ChatMessageRole
  /**
   * `null` when the assistant message is still `pending` / `running` (job has not produced output yet).
   * User / system messages always have a string body.
   */
  content: string | null
  /**
   * Only present for assistant messages in the M4 async flow. Absent for user/system messages
   * and for legacy sync-flow messages (pre-M4).
   */
  status?: ChatMessageStatus
  started_at?: string | null
  finished_at?: string | null
  /**
   * Number of rows in `chat_message_events` for this message. Returned by GET /messages.
   * If > 0 and `status` is terminal, the timeline can be replayed via the batch events endpoint.
   */
  events_count?: number
  metadata?: ChatMessageMetadataDto | null
  created_at: string
  updated_at?: string
}

export interface ChatLastMessageDto {
  role: ChatMessageRole
  content: string
  created_at: string
}

export interface ChatListItemDto {
  id: number
  type: ChatType
  /** UI-scope of the chat — see `ChatScopeType`. */
  scope_type: ChatScopeType
  title: string | null
  report_id: number | null
  /** Id of the widget produced by this `widget_generation` chat. `null` until WidgetTool runs. */
  widget_id?: number | null
  /** Id of the dashboard this chat is scoped to (`scope_type='dashboard'`). `null` otherwise. */
  dashboard_id?: number | null
  /**
   * Id of the document template this chat is scoped to / produced. Set for
   * `scope_type='document'` chats and `document_template` chats that ran
   * `generate_document_template`. `null` otherwise.
   */
  document_id?: number | null
  created_at: string
  updated_at: string
  /**
   * Mini-chat aggregates: MAX(messages.created_at) across all roles.
   * `null` for empty chats that have no messages yet.
   */
  last_message_at: string | null
  /** Mini-chat aggregate: COUNT(messages) where role='user'. */
  user_message_count: number
  /**
   * Mini-chat decision flag. `true` iff (a) the chat has no messages OR
   * (b) `last_message_at >= now()-24h` AND `user_message_count < 10`.
   * Used to decide "continue this chat" vs "start a new one".
   */
  is_active_window: boolean
  last_message: ChatLastMessageDto | null
}

export interface ChatDetailDto {
  id: number
  user_id?: number
  company_id?: number
  type: ChatType
  /** UI-scope of the chat — see `ChatScopeType`. */
  scope_type: ChatScopeType
  title: string | null
  report_id: number | null
  /** Id of the widget produced by this `widget_generation` chat. `null` until WidgetTool runs. */
  widget_id?: number | null
  /** Id of the dashboard this chat is scoped to (`scope_type='dashboard'`). `null` otherwise. */
  dashboard_id?: number | null
  /**
   * Id of the document template this chat is scoped to / produced. Set for
   * `scope_type='document'` chats and `document_template` chats that ran
   * `generate_document_template`. `null` otherwise.
   */
  document_id?: number | null
  ai_context?: ChatAiContextDto | null
  messages?: ChatMessageDto[]
  report?: import('./reports').ReportDto | null
  /** Mini-chat aggregate (see `ChatListItemDto.last_message_at`). */
  last_message_at?: string | null
  /** Mini-chat aggregate (see `ChatListItemDto.user_message_count`). */
  user_message_count?: number
  /** Mini-chat decision flag (see `ChatListItemDto.is_active_window`). */
  is_active_window?: boolean
  created_at: string
  updated_at: string
}

/**
 * 202 Accepted response from POST /api/chats/{chat_id}/messages and
 * POST /api/chats/messages (inline-create) in the M4 async flow.
 *
 * - `user_message` — the persisted user message (status terminal, content set).
 * - `assistant_message` — placeholder with `status='pending'`, `content=null`; will be filled
 *    in the background by ProcessChatMessageJob and emitted via the SSE stream.
 * - `stream_url` — endpoint to subscribe for live AI-turn events
 *    (`GET /api/chats/{chat_id}/stream/{message_id}`).
 * - `chat` — partial chat snapshot (title, report_id, scope_type, ai_context, report).
 *    Always present on the inline-create endpoint (the chat was just created and the caller
 *    needs the id). On the legacy `POST /chats/{id}/messages` endpoint it is informational —
 *    callers should treat as optional and fall back to `fetchChat(id)` when full consistency
 *    is required.
 */
export interface SendMessageResponseDto {
  user_message: ChatMessageDto
  assistant_message: ChatMessageDto
  stream_url: string
  chat?: ChatDetailDto
}

export type ChatMessageEventType =
  | 'started'
  | 'thinking'
  | 'tool_call'
  | 'tool_result'
  | 'dry_run_start'
  | 'dry_run_result'
  | 'retry'
  | 'text_delta'
  | 'final_message'
  | 'widget_variants'
  | 'document_fields_proposed'
  | 'error'

/**
 * A single AI-proposed widget variant in the two-step widget-generation flow.
 * `index` is 1-based — the user selects a variant by sending "Create variant N".
 * `label` is a human-readable name ("Deals by status — doughnut"). `config` is a
 * full widget config the modal renders a live preview for via
 * `POST /api/widgets/preview`.
 */
export interface WidgetVariantDto {
  index: number
  label: string
  config: WidgetConfigDto
}

/**
 * Payload of the `widget_variants` SSE event emitted in a `widget_generation`
 * chat turn. The assistant proposes 2–4 variants instead of immediately
 * creating a widget; the frontend renders each with a preview and lets the user
 * pick one (which then triggers the standard create flow).
 */
export interface WidgetVariantsPayload {
  variants: WidgetVariantDto[]
}

/**
 * Where a proposed docx field-mapping came from:
 *  - `'catalog'` — a literal key from the static field catalogue (`field-catalog`);
 *  - `'macrodata'` — a raw MacroData field the AI inferred (less certain).
 */
export type DocumentFieldSource = 'catalog' | 'macrodata'

/**
 * A single AI-proposed docx placeholder mapping in the two-step
 * document-template (Word) flow. `token` is the bare `${...}` name; the AI
 * suggests `suggested_field` (a field-catalog key or a MacroData field) for it.
 * `model` / `confidence` are advisory hints surfaced on the proposal card.
 */
export interface DocumentFieldProposalDto {
  token: string
  suggested_field: string
  model?: string | null
  confidence?: number | null
  source: DocumentFieldSource
}

/**
 * Payload of the `document_fields_proposed` SSE event emitted in a
 * `document_template` (scope=document) chat turn. The assistant reads the
 * uploaded docx and proposes a placeholder → field mapping instead of filling
 * it silently; the frontend renders each proposal as a card and lets the user
 * accept all / individual rows. Accepting persists into `config.field_mapping`.
 */
export interface DocumentFieldsProposedPayload {
  placeholders: DocumentFieldProposalDto[]
}

/**
 * Payload shape for `text_delta` events (chats_frontend.md §`text_delta`).
 * `delta` is a non-empty string increment. `kind` differentiates the main
 * assistant content stream from optional reasoning-token streams that some
 * providers (Anthropic extended thinking) emit alongside.
 *
 * Frontend contract: concatenate all `kind='content'` deltas in sequence
 * order to progressively render the live response. `final_message.content`
 * is the canonical source of truth — overwrite the accumulator on settle
 * to guard against missed deltas on reconnect.
 */
export interface ChatTextDeltaPayload {
  delta: string
  kind: 'content' | 'thinking'
}

/**
 * Coarse machine-readable error class for the terminal `error` SSE event.
 * Lets the UI tailor messaging / retry affordances per failure mode.
 */
export type ChatErrorCategory = 'context_overflow' | 'rate_limit' | 'timeout' | 'other'

/**
 * Payload of the terminal `error` SSE event. This event is now GUARANTEED to
 * arrive when an AI turn fails (no more eternal spinners) — the stream settles
 * with `status='error'` right after.
 *
 * `user_message` is an already-localized, user-facing string the frontend
 * renders verbatim in the bubble's error-state. `message` /
 * `exception_class` are diagnostic only. `category` classifies the failure.
 */
export interface ChatErrorPayload {
  exception_class?: string
  message?: string
  category?: ChatErrorCategory | string
  user_message?: string
}

export interface ChatMessageEventDto {
  sequence: number
  type: ChatMessageEventType | string
  payload: Record<string, unknown>
  created_at: string
}

/**
 * Response shape of GET /api/chats/{chat}/messages/{message}/events
 * (batch replay of the message event-log; used for resume + reload restore).
 */
export interface ChatMessageEventsResponseDto {
  events: ChatMessageEventDto[]
  message_status: ChatMessageStatus
  has_more: boolean
  next_cursor: number | null
}

export interface CreateChatRequest {
  type: ChatType
}

/**
 * Slim report snapshot piggybacked on quick_qa sends from the MiniChat widget
 * when the chat is opened on a report page. Lets backend swap the bulky
 * `QUICK_QA_PROMPT.md` catalog for a primaryModel-specific note plus the
 * report's columns / applied filters. See `chats_frontend.md` §`report_context`.
 *
 * Activation requires `primaryModel` to be a non-empty PascalCase MacroData
 * model name; any other shape silently falls back to the legacy catalog
 * (backend is defensive). Field is ignored for `report_generation` chats.
 */
export interface ReportContextPayload {
  primaryModel: string
  reportId?: number | null
  reportTitle?: string | null
  /** Plain field-name array (lighter than the column DTOs we have client-side). */
  columns?: string[]
  /** Applied filters dictionary — serialized as-is into the prompt header. */
  filters?: Record<string, unknown>
}

export interface SendMessageRequest {
  content: string
  /** Optional. Only set for MiniChat sends while on a report page — see `ReportContextPayload`. */
  report_context?: ReportContextPayload
}

/**
 * Query params for the scoped variant of `GET /api/chats`.
 *
 * - `scope_type` — filter chats by UI scope.
 * - `report_id` — required when `scope_type='report'`; backend asserts read-ACL
 *    (403 if the report is outside the active company or not visible to the role).
 * - `limit` — 1..50, default 50 server-side.
 */
export interface ListChatsRequest {
  scope_type?: ChatScopeType
  report_id?: number
  /** Required when `scope_type='dashboard'`. Backend asserts read-ACL. */
  dashboard_id?: number
  /** Required when `scope_type='document'`. Backend asserts read-ACL. */
  document_id?: number
  limit?: number
}

/**
 * Query params for `GET /api/chats/resume`. Atomically returns the most recent
 * "active-window" chat for the user in the requested scope, or 204 No Content if
 * no such chat exists (MiniChat should then open a new in-memory chat and lazy-create
 * it on the first message via `POST /api/chats/messages`).
 *
 * `report_id` is required when `scope_type='report'`; same read-ACL as `ListChatsRequest`.
 */
export interface ResumeChatRequest {
  scope_type: ChatScopeType
  report_id?: number
  /** Required when `scope_type='dashboard'`. */
  dashboard_id?: number
  /** Required when `scope_type='document'`. */
  document_id?: number
}

/**
 * Body for `POST /api/chats/messages` — inline create + first user message in a single
 * DB transaction. Two callers:
 *  - the MiniChat widget — omits `type`, so the chat defaults to `'quick_qa'`
 *    (mini-chat never builds full reports);
 *  - the report-generation modal (create-mode) — sends `type='report_generation'`
 *    so the lazily-created chat can drive the report-building tool flow.
 *
 * - `scope_type` — required.
 * - `report_id` — required when `scope_type='report'`.
 * - `type` — optional; defaults to `'quick_qa'` server-side. Set to
 *    `'report_generation'` for the report-generation modal.
 * - `content` — required; ≤4000 chars; auto-becomes the chat title (truncated to 80).
 * - `report_context` — optional in-report quick_qa snapshot, same shape as the legacy
 *    send-message endpoint. See `ReportContextPayload`.
 */
export interface InlineCreateMessageRequest {
  scope_type: ChatScopeType
  report_id?: number
  /** Required when `scope_type='dashboard'` (mini-chat) or for dashboard-scoped widget edits. */
  dashboard_id?: number
  /**
   * Edit an existing widget through the AI chat — set by the widget-generation
   * modal in edit-mode when the widget has no pinned chat to resume. The lazily
   * created `widget_generation` chat is bound to this widget.
   */
  widget_id?: number
  /**
   * Required when `scope_type='document'` — binds the lazily-created
   * `document_template` chat to the docx template being auto-mapped. The AI then
   * reads that template's source and proposes a field mapping.
   */
  document_id?: number
  /**
   * Optional; defaults to `'quick_qa'` server-side.
   * `'report_generation'` — report-generation modal.
   * `'widget_generation'` — widget-generation modal.
   * `'document_template'` — document-generation modal (HTML КП) and docx
   *    field auto-mapping (scope=document).
   */
  type?: ChatType
  content: string
  report_context?: ReportContextPayload
}
