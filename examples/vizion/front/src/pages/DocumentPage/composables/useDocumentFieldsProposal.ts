import { computed, nextTick, ref, shallowRef, type Ref } from 'vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { createRequestGate } from '@/utils/requestGate'
import { useChatStream } from '@/components/chat/composables/useChatStream'
import type {
  ChatDetail,
  ChatMessage,
  ChatMessageStatus,
} from '@/entities/chat'
import type {
  ChatMessageEventDto,
  ChatTextDeltaPayload,
  DocumentFieldProposalDto,
} from '@/api/types/chats'

type Translate = (key: string) => string

interface UseDocumentFieldsProposalArgs {
  t: Translate
  documentId: Ref<number>
  /** Gate: only analyst+ / non-system docx templates run the AI mapping. */
  canManage: Ref<boolean>
}

/**
 * Document-scoped AI flow for the docx field auto-mapping panel.
 *
 * Mirrors `useDocumentGenerationModalChat` (lazy-create `type='document_template'`,
 * stream through `useChatStream`), but with two domain differences:
 *  - the chat is `scope_type='document'`, bound to the open template
 *    (`document_id`) so the AI reads the uploaded docx;
 *  - instead of tracking a created entity, it collects the
 *    `document_fields_proposed` SSE event into `proposals` — the panel renders
 *    a card per placeholder and the user accepts all / individual rows.
 *
 * Deliberately isolated from `useChatsStore` — this is an inline panel flow,
 * not a full-screen chat, so it must not touch active-chat state.
 */

const replaceMessageInChat = (
  chatRef: { value: ChatDetail | null },
  messageId: number,
  patch: Partial<ChatMessage>,
): boolean => {
  const chat = chatRef.value
  if (!chat) return false
  const idx = chat.messages.findIndex((m) => m.id === messageId)
  if (idx === -1) return false
  const nextMessages = [
    ...chat.messages.slice(0, idx),
    { ...chat.messages[idx]!, ...patch },
    ...chat.messages.slice(idx + 1),
  ]
  chatRef.value = { ...chat, messages: nextMessages }
  return true
}

const TIMELINE_EVENT_TYPES = new Set([
  'started',
  'thinking',
  'tool_call',
  'tool_result',
  'dry_run_start',
  'dry_run_result',
  'retry',
  'final_message',
])

const isTextDeltaPayload = (raw: unknown): raw is ChatTextDeltaPayload => {
  if (typeof raw !== 'object' || raw === null) return false
  const obj = raw as Record<string, unknown>
  return (
    typeof obj.delta === 'string' &&
    (obj.kind === 'content' || obj.kind === 'thinking')
  )
}

/**
 * Validates a `document_fields_proposed` payload before trusting it. Keeps only
 * well-formed proposals ({token:string, suggested_field:string, source}); a
 * payload with no usable rows is treated as absent (panel stays hidden).
 */
const parseProposalsPayload = (raw: unknown): DocumentFieldProposalDto[] => {
  if (typeof raw !== 'object' || raw === null) return []
  const candidate = (raw as Record<string, unknown>).placeholders
  if (!Array.isArray(candidate)) return []

  const proposals: DocumentFieldProposalDto[] = []
  for (const entry of candidate as unknown[]) {
    if (typeof entry !== 'object' || entry === null) continue
    const obj = entry as Record<string, unknown>
    if (typeof obj.token !== 'string' || obj.token === '') continue
    if (typeof obj.suggested_field !== 'string' || obj.suggested_field === '') continue
    const source = obj.source === 'macrodata' ? 'macrodata' : 'catalog'
    proposals.push({
      token: obj.token,
      suggested_field: obj.suggested_field,
      model: typeof obj.model === 'string' ? obj.model : null,
      confidence: typeof obj.confidence === 'number' ? obj.confidence : null,
      source,
    })
  }
  return proposals
}

export const useDocumentFieldsProposal = (args: UseDocumentFieldsProposalArgs) => {
  const { t, documentId, canManage } = args
  const { chatService } = useServices()
  const { notifyApiError, notifyError } = useNotifications()
  const stream = useChatStream()

  const currentChat = shallowRef<ChatDetail | null>(null)
  const isPostingMessage = ref(false)
  const error = ref<string | null>(null)

  /**
   * AI-proposed mappings from the latest `document_fields_proposed` event. While
   * non-empty the panel renders the proposal cards. Cleared when the user
   * accepts / dismisses, or on reset.
   */
  const proposals = shallowRef<DocumentFieldProposalDto[]>([])

  const sendRequestGate = createRequestGate()

  const pendingEventsByMessageId = new Map<number, ChatMessageEventDto[]>()

  const isRunning = computed(
    () =>
      isPostingMessage.value ||
      stream.lifecycle.value === 'connecting' ||
      stream.lifecycle.value === 'streaming',
  )

  const hasProposals = computed(() => proposals.value.length > 0)

  const findMessage = (chatId: number, messageId: number): ChatMessage | null => {
    const chat = currentChat.value
    if (!chat || chat.id !== chatId) return null
    return chat.messages.find((m) => m.id === messageId) ?? null
  }

  const flushPendingEvents = (chatId: number, messageId: number): void => {
    const buffered = pendingEventsByMessageId.get(messageId)
    if (!buffered || buffered.length === 0) return
    const msg = findMessage(chatId, messageId)
    if (!msg) return

    const seen = new Set((msg.timelineEvents ?? []).map((e) => e.sequence))
    const merged = [...(msg.timelineEvents ?? [])]
    for (const event of buffered) {
      if (seen.has(event.sequence)) continue
      seen.add(event.sequence)
      merged.push(event)
    }
    merged.sort((a, b) => a.sequence - b.sequence)

    pendingEventsByMessageId.delete(messageId)
    replaceMessageInChat(currentChat, messageId, { timelineEvents: merged })
  }

  const appendTimelineEvent = (
    chatId: number,
    messageId: number,
    event: ChatMessageEventDto,
  ): void => {
    if (!TIMELINE_EVENT_TYPES.has(event.type)) return

    const msg = findMessage(chatId, messageId)
    if (!msg) {
      const existing = pendingEventsByMessageId.get(messageId) ?? []
      if (existing.some((e) => e.sequence === event.sequence)) return
      existing.push(event)
      pendingEventsByMessageId.set(messageId, existing)
      return
    }

    const existing = msg.timelineEvents ?? []
    if (existing.some((e) => e.sequence === event.sequence)) return
    replaceMessageInChat(currentChat, messageId, {
      timelineEvents: [...existing, event],
    })

    flushPendingEvents(chatId, messageId)
  }

  const applyTextDelta = (
    chatId: number,
    messageId: number,
    payload: ChatTextDeltaPayload,
  ): void => {
    const msg = findMessage(chatId, messageId)
    if (!msg) return

    if (payload.kind === 'content') {
      const next = (msg.content ?? '') + payload.delta
      replaceMessageInChat(currentChat, messageId, { content: next })
    } else if (payload.kind === 'thinking') {
      const next = (msg.thinkingContent ?? '') + payload.delta
      replaceMessageInChat(currentChat, messageId, { thinkingContent: next })
    }
  }

  const finalizeAssistantMessage = async (
    chatId: number,
    messageId: number,
    status: ChatMessageStatus,
  ): Promise<void> => {
    try {
      const freshChat = await chatService.fetchChat(chatId)
      if (currentChat.value?.id === chatId) {
        pendingEventsByMessageId.clear()
        currentChat.value = freshChat
      }

      if (status === 'error') {
        const finalMsg = freshChat.messages.find((m) => m.id === messageId)
        const errMessage = finalMsg?.metadata?.error?.message ?? null
        notifyError(errMessage ?? t('docx.ai.errors.turnFailed'), t('docx.ai.errors.title'))
      }
    } catch (err) {
      notifyApiError(err, t('docx.ai.errors.loadChat'), t('docx.ai.errors.title'))
    }
  }

  const subscribeToAssistantStream = (
    chatId: number,
    messageId: number,
    streamUrl: string,
  ): void => {
    void stream.start({
      chatId,
      messageId,
      streamUrl,
      callbacks: {
        onEvent: (event) => {
          appendTimelineEvent(chatId, messageId, event)

          if (event.type === 'document_fields_proposed') {
            const parsed = parseProposalsPayload(event.payload)
            if (parsed.length > 0) {
              proposals.value = parsed
            }
            return
          }

          if (event.type === 'started') {
            replaceMessageInChat(currentChat, messageId, { status: 'running' })
          } else if (event.type === 'text_delta') {
            if (isTextDeltaPayload(event.payload)) {
              applyTextDelta(chatId, messageId, event.payload)
            }
          } else if (event.type === 'final_message') {
            const content =
              typeof event.payload?.content === 'string' ? event.payload.content : null
            if (content !== null) {
              replaceMessageInChat(currentChat, messageId, { content })
            }
          }
        },
        onSettled: async (settledStatus) => {
          if (currentChat.value?.id !== chatId) return
          await finalizeAssistantMessage(chatId, messageId, settledStatus)
        },
      },
    })
  }

  /**
   * Kick off the AI auto-mapping. Lazily creates a `document_template`,
   * `scope_type='document'` chat bound to the open template, with a fixed
   * instruction prompt asking the AI to propose a placeholder mapping. The AI
   * reads the docx (backend injects its text) and emits
   * `document_fields_proposed` → cards.
   */
  const propose = async (): Promise<void> => {
    const id = documentId.value
    if (!id || id <= 0 || !canManage.value || isRunning.value) return

    proposals.value = []
    const requestToken = sendRequestGate.next()
    isPostingMessage.value = true
    error.value = null

    try {
      const result = await chatService.sendInline({
        scope_type: 'document',
        type: 'document_template',
        document_id: id,
        content: t('docx.ai.prompt'),
      })

      if (!sendRequestGate.isCurrent(requestToken)) return
      if (!result.chat) {
        notifyError(t('docx.ai.errors.createChat'), t('docx.ai.errors.title'))
        return
      }

      const assistantPlaceholder: ChatMessage = {
        ...result.assistantMessage,
        timelineEvents: result.assistantMessage.timelineEvents ?? [],
      }
      currentChat.value = {
        ...result.chat,
        messages: [result.userMessage, assistantPlaceholder],
      }

      await nextTick()
      subscribeToAssistantStream(
        result.chat.id,
        result.assistantMessage.id,
        result.streamUrl,
      )
      flushPendingEvents(result.chat.id, result.assistantMessage.id)
    } catch (err) {
      notifyApiError(err, t('docx.ai.errors.network'), t('docx.ai.errors.title'))
    } finally {
      if (sendRequestGate.isCurrent(requestToken)) {
        isPostingMessage.value = false
      }
    }
  }

  /** Drop a single proposal card (the user declined that suggestion). */
  const dismissProposal = (token: string): void => {
    proposals.value = proposals.value.filter((p) => p.token !== token)
  }

  /** Clear all proposal cards without applying. */
  const clearProposals = (): void => {
    proposals.value = []
  }

  const reset = (): void => {
    stream.stop()
    stream.reset()
    sendRequestGate.invalidate()
    pendingEventsByMessageId.clear()
    currentChat.value = null
    isPostingMessage.value = false
    error.value = null
    proposals.value = []
  }

  return {
    // state
    proposals,
    hasProposals,
    isRunning,
    error,
    // actions
    propose,
    dismissProposal,
    clearProposals,
    reset,
  }
}
