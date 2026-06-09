import type {
  ChatMessageEventDto,
  ChatMessageRole,
  ChatMessageStatus,
  ChatScopeType,
  ChatType,
} from '@/api/types/chats'
import type { Report } from '@/entities/report'

export type { ChatMessageRole, ChatMessageStatus, ChatScopeType, ChatType }

export interface ChatToolCall {
  name: string
  arguments: string
}

export interface ChatUsage {
  promptTokens: number
  completionTokens: number
  totalTokens?: number
}

export interface ChatMessageError {
  exceptionClass?: string
  message?: string
}

export interface ChatMessageMetadata {
  finishReason?: string | null
  usage?: ChatUsage | null
  toolCalls?: ChatToolCall[] | null
  toolResults?: unknown[] | null
  /**
   * Normalized to `ChatMessageError` object even when backend returns a legacy string.
   * Null/absent when there is no error.
   */
  error?: ChatMessageError | null
  [key: string]: unknown
}

export interface ChatAiContext {
  lastToolCalls?: string[]
  totalSteps?: number
  probedModels?: string[]
  reportCreated?: boolean
  [key: string]: unknown
}

export interface ChatMessage {
  id: number
  chatId: number
  role: ChatMessageRole
  /**
   * `null` while assistant message is `pending` / `running` (no AI output yet).
   * For `user` / `system` always a string.
   */
  content: string | null
  /**
   * Assistant-only lifecycle. Missing for `user` / `system` and for legacy
   * (pre-M4 sync-flow) assistant rows.
   */
  status?: ChatMessageStatus
  startedAt?: string | null
  finishedAt?: string | null
  eventsCount?: number
  metadata: ChatMessageMetadata | null
  createdAt: string
  isOptimistic?: boolean
  /**
   * Live timeline events accumulated during the active AI-turn (or replayed
   * via the batch endpoint). Runtime-only — never persisted to backend, never
   * round-trips through mappers. Used by `ChatThinkingTimeline` to render the
   * step-by-step "AI is using a tool / thinking / done" indicator above the
   * bubble content. Stays attached to the message after settle so the user
   * can collapse / re-expand the completed timeline.
   */
  timelineEvents?: ChatMessageEventDto[]
  /**
   * Live accumulator for `text_delta` events with `kind='thinking'` (Anthropic
   * extended thinking and similar reasoning-token streams). Runtime-only.
   * `null` / undefined means no thinking-deltas arrived. Empty string means
   * the stream started but produced no thinking yet.
   */
  thinkingContent?: string | null
  /**
   * Live accumulator for `text_delta` events with `kind='content'` while the
   * turn is still in flight — i.e. the model's interim preamble emitted BEFORE
   * a tool call / `final_message`. Runtime-only, never persisted.
   *
   * Why this is separate from `content`: the interim preamble must render
   * INSIDE the thinking/progress block, not in the message body. The body
   * (`content`) is reserved for the canonical answer, which arrives only via
   * the terminal `final_message` event. Mixing the two made the interim text
   * "spill" into the body while the timeline was still open. Cleared/ignored
   * once `content` (final_message) lands.
   */
  streamingContent?: string | null
  /**
   * Human-readable, already-localized error string surfaced from the terminal
   * `error` SSE event (`payload.user_message`). Runtime-only. Drives the
   * inline error-state in the bubble instead of an eternal spinner when a turn
   * fails. `null` / undefined means no error.
   */
  errorMessage?: string | null
}

export interface ChatListItem {
  id: number
  type: ChatType
  /**
   * UI-scope of the chat — see `ChatScopeType`. Optional in the entity layer because
   * legacy in-app constructors (`useChatsStore.syncChatListItemFromDetail`, `toChatListItem`
   * helper, optimistic local items) project from older data without the aggregate set.
   * Real list items mapped from the backend always carry it.
   */
  scopeType?: ChatScopeType
  title: string | null
  reportId: number | null
  /** Id of the widget produced by this `widget_generation` chat. `null`/undefined otherwise. */
  widgetId?: number | null
  /** Id of the dashboard this chat is scoped to. `null`/undefined otherwise. */
  dashboardId?: number | null
  /** Id of the document template this chat is scoped to / produced. `null`/undefined otherwise. */
  documentId?: number | null
  updatedAt: string
  /**
   * MAX(messages.created_at) across all roles. `null` for chats with no messages.
   * Used by mini-chat list ordering and the active-window heuristic.
   * Optional for the same reason as `scopeType` — local optimistic items omit it.
   */
  lastMessageAt?: string | null
  /** COUNT(messages) where role='user'. Optional in optimistic local items. */
  userMessageCount?: number
  /**
   * `true` iff (a) the chat has no messages OR (b) `lastMessageAt >= now()-24h`
   * AND `userMessageCount < 10`. Mini-chat uses this to decide "continue this chat"
   * vs "start a new one". Optional in optimistic local items.
   */
  isActiveWindow?: boolean
  /**
   * `content` can be `null` when the last message is an assistant placeholder
   * (M4 async flow: `status='pending'` / `'running'`, no content yet).
   * Sidebar UIs should render an em-dash or typing indicator in that case.
   */
  lastMessage: { role: ChatMessageRole; content: string | null } | null
}

export interface ChatDetail {
  id: number
  type: ChatType
  /** UI-scope of the chat — see `ChatScopeType`. */
  scopeType: ChatScopeType
  title: string | null
  reportId: number | null
  /** Id of the widget produced by this `widget_generation` chat. `null` until WidgetTool runs. */
  widgetId: number | null
  /** Id of the dashboard this chat is scoped to (`scope_type='dashboard'`). `null` otherwise. */
  dashboardId: number | null
  /** Id of the document template this chat is scoped to / produced. `null` otherwise. */
  documentId: number | null
  updatedAt: string
  aiContext: ChatAiContext | null
  messages: ChatMessage[]
  /**
   * Mini-chat aggregates. Optional because legacy endpoints (e.g. the original
   * `POST /api/chats`, `GET /api/chats/{id}`) may not include them on older builds.
   * The newer `/resume` + `/messages` (inline create) responses always set them.
   */
  lastMessageAt?: string | null
  userMessageCount?: number
  isActiveWindow?: boolean
  // Report entity (не ReportItem из features) — не требует поля type:'dashboard'|'custom'
  report: (Report & { title: string; description?: string }) | null
}
