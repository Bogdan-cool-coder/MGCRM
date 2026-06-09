import { chatsApi } from '@/api/chats'
import {
  mapChatDetailDtoToDetail,
  mapChatListItemDtoToItem,
  mapChatMessageDtoToMessage,
} from '@/entities/chat/mappers'
import type { ChatDetail, ChatListItem, ChatMessage, ChatType } from '@/entities/chat'
import type {
  ChatMessageEventsResponseDto,
  InlineCreateMessageRequest,
  ListChatsRequest,
  ReportContextPayload,
  ResumeChatRequest,
} from '@/api/types/chats'

export interface SendMessageResult {
  /** Persisted user message returned by the backend (terminal, content set). */
  userMessage: ChatMessage
  /** Assistant placeholder — `status='pending'`, `content=null`. Filled later via SSE. */
  assistantMessage: ChatMessage
  /** SSE endpoint to subscribe to live AI-turn events. */
  streamUrl: string
  /**
   * Partial chat snapshot returned alongside the 202 (title, report_id, ai_context, report).
   * Not present on every backend build — callers should treat as optional and fall back to
   * `fetchChat(id)` when they need a fully consistent ChatDetail.
   */
  chat?: ChatDetail
}

export class ChatService {
  async fetchChats(): Promise<ChatListItem[]> {
    return (await chatsApi.fetchChats()).map(mapChatListItemDtoToItem)
  }

  /**
   * Scoped list for the MiniChat widget. Wraps `chatsApi.fetchChatsScoped`
   * and maps to the entity-layer `ChatListItem` shape (snake → camel + mini-chat
   * aggregates `lastMessageAt` / `userMessageCount` / `isActiveWindow`).
   *
   * Kept separate from `fetchChats()` so full-screen sidebar callers stay on
   * the unparameterised endpoint.
   */
  async fetchChatsScoped(params: ListChatsRequest): Promise<ChatListItem[]> {
    return (await chatsApi.fetchChatsScoped(params)).map(mapChatListItemDtoToItem)
  }

  /**
   * Atomic auto-resume for MiniChat mount/open. Returns the most recent
   * active-window chat in the requested scope as a fully-mapped `ChatDetail`,
   * or `null` when the backend has nothing to resume (204 No Content).
   */
  async resume(params: ResumeChatRequest): Promise<ChatDetail | null> {
    const dto = await chatsApi.resumeChat(params)
    return dto ? mapChatDetailDtoToDetail(dto) : null
  }

  /**
   * Inline create + first user message in one transaction (MiniChat lazy creation).
   * Mirrors `sendMessage`'s return shape so the consumer composable can treat both
   * paths uniformly; the only difference is that `chat` is always present here
   * (the caller needs the freshly-created chat id to open the SSE stream).
   */
  async sendInline(payload: InlineCreateMessageRequest): Promise<SendMessageResult> {
    const response = await chatsApi.sendInlineCreate(payload)
    return {
      userMessage: mapChatMessageDtoToMessage(response.user_message),
      assistantMessage: mapChatMessageDtoToMessage(response.assistant_message),
      streamUrl: response.stream_url,
      chat: response.chat ? mapChatDetailDtoToDetail(response.chat) : undefined,
    }
  }

  async createChat(type: ChatType): Promise<ChatDetail> {
    return mapChatDetailDtoToDetail(await chatsApi.createChat({ type }))
  }

  async fetchChat(id: number): Promise<ChatDetail> {
    return mapChatDetailDtoToDetail(await chatsApi.fetchChat(id))
  }

  async deleteChat(id: number): Promise<void> {
    await chatsApi.deleteChat(id)
  }

  /**
   * M4 async flow. Returns immediately (202) with placeholder assistant message and a
   * `streamUrl`. The caller is expected to subscribe via `useChatStream` to receive the
   * AI-turn progress and the final content.
   *
   * `reportContext` is the slim in-report snapshot — only set by the MiniChat widget
   * when the chat is opened on a report page. Backend uses it to swap the bulky quick_qa
   * model catalog for a primaryModel-specific note. Omitted → legacy quick_qa prompt.
   */
  async sendMessage(
    chatId: number,
    content: string,
    reportContext?: ReportContextPayload,
  ): Promise<SendMessageResult> {
    const payload = reportContext
      ? { content, report_context: reportContext }
      : { content }
    const response = await chatsApi.sendMessage(chatId, payload)
    return {
      userMessage: mapChatMessageDtoToMessage(response.user_message),
      assistantMessage: mapChatMessageDtoToMessage(response.assistant_message),
      streamUrl: response.stream_url,
      chat: response.chat ? mapChatDetailDtoToDetail(response.chat) : undefined,
    }
  }

  /**
   * Batch replay of an assistant message's event log. Used by `useChatStream`
   * to restore the timeline after reload and to backfill events missed across
   * an SSE disconnect.
   */
  async fetchMessageEvents(
    chatId: number,
    messageId: number,
    since = 0,
    limit = 100,
  ): Promise<ChatMessageEventsResponseDto> {
    return chatsApi.fetchMessageEvents(chatId, messageId, since, limit)
  }
}
