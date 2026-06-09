import type { ChatDetail, ChatListItem, ChatMessage } from '@/entities/chat'
import type {
  ChatErrorPayload,
  ChatMessageEventDto,
  ChatTextDeltaPayload,
} from '@/api/types/chats'

export const toChatListItem = (chat: ChatDetail): ChatListItem => ({
  id: chat.id,
  type: chat.type,
  title: chat.title,
  reportId: chat.reportId,
  updatedAt: chat.updatedAt,
  lastMessage:
    chat.messages.length > 0
      ? {
          role: chat.messages[chat.messages.length - 1]?.role ?? 'assistant',
          content: chat.messages[chat.messages.length - 1]?.content ?? '',
        }
      : null,
})

export const createOptimisticMessage = (
  chatId: number,
  content: string,
  optimisticMessageId: number,
): ChatMessage => ({
  id: optimisticMessageId,
  chatId,
  role: 'user',
  content,
  metadata: null,
  createdAt: new Date().toISOString(),
  isOptimistic: true,
})

export const removeMessageById = (
  messages: ChatMessage[],
  optimisticMessageId: number,
): ChatMessage[] => {
  return messages.filter((message) => message.id !== optimisticMessageId)
}

const isTextDeltaPayload = (raw: unknown): raw is ChatTextDeltaPayload => {
  if (typeof raw !== 'object' || raw === null) return false
  const obj = raw as Record<string, unknown>
  return typeof obj.delta === 'string' && (obj.kind === 'content' || obj.kind === 'thinking')
}

/**
 * Picks the user-facing error string from an `error` event payload. Prefers the
 * already-localized `user_message`; falls back to the diagnostic `message`, then
 * to `null` (caller substitutes a generic i18n string).
 */
export const extractStreamErrorMessage = (payload: Record<string, unknown> | undefined): string | null => {
  if (!payload) return null
  const err = payload as ChatErrorPayload
  if (typeof err.user_message === 'string' && err.user_message.trim() !== '') {
    return err.user_message
  }
  if (typeof err.message === 'string' && err.message.trim() !== '') {
    return err.message
  }
  return null
}

/**
 * Computes the patch to apply to an assistant message for a single SSE event,
 * sharing the interim-vs-final-vs-error routing across every chat surface
 * (full-screen page, mini-chat, report/widget/document modals) so they can
 * never drift.
 *
 * Routing contract (matches the backend SSE contract):
 *  - `started` → mark the row `running`.
 *  - `text_delta` `kind='content'` → accumulate into `streamingContent` (the
 *    interim preamble shown INSIDE the thinking block, NOT the body). Never
 *    touches `content` so nothing spills into the bubble body mid-turn.
 *  - `text_delta` `kind='thinking'` → accumulate into `thinkingContent`
 *    (reasoning trace).
 *  - `final_message` → set `content` (the canonical body answer). This is the
 *    only source of the body text; buffered providers (GLM) emit just this
 *    after the tool steps, with no preceding deltas.
 *  - `error` → set `errorMessage` from the localized `user_message`, so the
 *    bubble renders an error-state instead of an eternal spinner.
 *
 * Returns `null` when the event does not affect message content/status (e.g.
 * timeline-only events like `tool_call`), so callers can skip the write.
 * `current` is the message as it stands right now (needed for delta accumulation).
 */
export const computeStreamEventPatch = (
  current: ChatMessage,
  event: ChatMessageEventDto,
): Partial<ChatMessage> | null => {
  switch (event.type) {
    case 'started':
      return { status: 'running' }
    case 'text_delta': {
      if (!isTextDeltaPayload(event.payload)) return null
      if (event.payload.kind === 'content') {
        return { streamingContent: (current.streamingContent ?? '') + event.payload.delta }
      }
      return { thinkingContent: (current.thinkingContent ?? '') + event.payload.delta }
    }
    case 'final_message': {
      const content = typeof event.payload?.content === 'string' ? event.payload.content : null
      return content !== null ? { content } : null
    }
    case 'error': {
      const errorMessage = extractStreamErrorMessage(event.payload)
      // Even when no message string is present, mark the row errored so the UI
      // leaves the spinner state. `null` errorMessage → caller's generic copy.
      return { errorMessage, status: 'error' }
    }
    default:
      return null
  }
}

