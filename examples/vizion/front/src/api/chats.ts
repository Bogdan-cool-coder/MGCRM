import { apiClient } from '@/api/client'
import type {
  ChatDetailDto,
  ChatListItemDto,
  ChatMessageDto,
  ChatMessageEventsResponseDto,
  CreateChatRequest,
  InlineCreateMessageRequest,
  ListChatsRequest,
  ResumeChatRequest,
  SendMessageRequest,
  SendMessageResponseDto,
} from '@/api/types/chats'

export const chatsApi = {
  // Canonical chat list endpoint used by sidebar/chat collections.
  async fetchChats(): Promise<ChatListItemDto[]> {
    const response = await apiClient.get<ChatListItemDto[]>('/api/chats')
    return response.data
  },

  /**
   * Scoped variant of `GET /api/chats` for the MiniChat widget — filters by
   * UI `scope_type` and (optionally) `report_id`, plus a `limit` cap. The legacy
   * `fetchChats()` (no params) remains the source for the full-screen sidebar
   * to avoid breaking existing pages — do not collapse the two.
   *
   * Backend response items include the same mini-chat aggregates
   * (`last_message_at`, `user_message_count`, `is_active_window`) on every call;
   * this endpoint is just convenience for filtered fetches.
   */
  async fetchChatsScoped(params: ListChatsRequest): Promise<ChatListItemDto[]> {
    const response = await apiClient.get<ChatListItemDto[]>('/api/chats', { params })
    return response.data
  },

  /**
   * Auto-resume the most recent active-window chat for the user in the given
   * scope. Used by MiniChat on mount/open to atomically pick "continue chat X"
   * vs "open empty in-memory chat".
   *
   * Returns `null` when the backend responds with 204 No Content (no chat matches
   * the active-window criterion in the requested scope). On 200 returns the full
   * `ChatDetailDto` shape (same as `GET /api/chats/{id}` plus mini-chat aggregates,
   * eager-loaded `messages` and `report`).
   *
   * Errors:
   *   - 403 — report_id is outside the active company or viewer cannot read it.
   *   - 422 — missing `scope_type`, or `scope_type='report'` without `report_id`.
   *   These propagate as axios errors; the 204 case is the only success-without-data.
   */
  async resumeChat(params: ResumeChatRequest): Promise<ChatDetailDto | null> {
    const response = await apiClient.get<ChatDetailDto | ''>('/api/chats/resume', {
      params,
      // Axios resolves all 2xx as success — explicit no-op here for clarity.
    })
    if (response.status === 204) return null
    // Defensive: some axios stacks return empty string body for 204 even when we
    // didn't enter the branch above (e.g. proxied response). Treat empty body as null.
    return response.data === '' ? null : (response.data as ChatDetailDto)
  },

  /**
   * Inline-create: atomically materializes a new chat + first user message and
   * dispatches the AI-turn job in a single DB transaction. Used by the MiniChat
   * widget when the user sends the first message in an in-memory chat. Returns 202
   * with the same envelope as the legacy `POST /chats/{id}/messages`, plus the
   * full `chat` object (always present here — the caller needs the new chat id
   * to open the SSE stream).
   *
   * No idempotency-key: caller must gate double-clicks (otherwise two chats are
   * created — backend will not deduplicate).
   */
  async sendInlineCreate(payload: InlineCreateMessageRequest): Promise<SendMessageResponseDto> {
    const response = await apiClient.post<SendMessageResponseDto>('/api/chats/messages', payload)
    return response.data
  },

  // Returns a full chat detail that can be opened immediately in UI.
  async createChat(data: CreateChatRequest): Promise<ChatDetailDto> {
    const response = await apiClient.post<ChatDetailDto>('/api/chats', data)
    return response.data
  },

  // Canonical chat detail endpoint for synchronizing the open chat state.
  async fetchChat(id: number): Promise<ChatDetailDto> {
    const response = await apiClient.get<ChatDetailDto>(`/api/chats/${id}`)
    return response.data
  },

  async deleteChat(id: number): Promise<void> {
    await apiClient.delete(`/api/chats/${id}`)
  },

  /**
   * M4 async flow: server dispatches a background job and returns 202 in tens of milliseconds.
   * The full AI turn is consumed via the SSE stream at `response.stream_url`
   * (see `useChatStream` composable). Default axios timeout is sufficient — we no longer
   * need the 10-minute timeout that the legacy sync flow required.
   */
  async sendMessage(chatId: number, data: SendMessageRequest): Promise<SendMessageResponseDto> {
    const response = await apiClient.post<SendMessageResponseDto>(
      `/api/chats/${chatId}/messages`,
      data,
    )
    return response.data
  },

  // Optional helper endpoint. Frontend does not use it as the canonical source
  // for the chat screen because it does not include full chat detail metadata.
  async fetchMessages(chatId: number): Promise<ChatMessageDto[]> {
    const response = await apiClient.get<ChatMessageDto[]>(`/api/chats/${chatId}/messages`)
    return response.data
  },

  /**
   * Batch replay of the assistant-message event log. Used to restore the timeline
   * after a page reload and to backfill events missed while the SSE stream was disconnected.
   *
   * `since=0` returns events from the beginning. Pagination is cursor-based via
   * the response's `next_cursor` (when `has_more=true`).
   */
  async fetchMessageEvents(
    chatId: number,
    messageId: number,
    since = 0,
    limit = 100,
  ): Promise<ChatMessageEventsResponseDto> {
    const response = await apiClient.get<ChatMessageEventsResponseDto>(
      `/api/chats/${chatId}/messages/${messageId}/events`,
      { params: { since, limit } },
    )
    return response.data
  },
}
