import { computed, nextTick, ref, shallowRef } from 'vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { createRequestGate } from '@/utils/requestGate'
import { useDocumentGenerationModalStore } from '@/stores/documentGenerationModal'
import type {
  ChatDetail,
  ChatMessage,
  ChatMessageStatus,
} from '@/entities/chat'
import type { ChatMessageEventDto } from '@/api/types/chats'
import { computeStreamEventPatch } from './chatHelpers'
import { useChatStream } from './useChatStream'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

/**
 * State machine for the global document-generation modal — a direct mirror of
 * `useWidgetGenerationModalChat` / `useReportGenerationModalChat`. The domain
 * differences:
 *  - create-mode sends `type='document_template'`, `scope_type='general'`
 *    (fresh HTML-КП flow) and tracks the new template's id;
 *  - edit-mode resumes the open template's document-scoped chat
 *    (`scope_type='document'` + `document_id`), or lazy-creates one bound to
 *    that template on the first send. The AI's `generate_document_template`
 *    updates the existing template in place (backend M7);
 *  - both track `createdDocumentId` from the canonical `currentChat.documentId`
 *    after every settle (so the modal can offer "go to the template" / refetch).
 *
 * Like the other generation modals it does NOT touch `useChatsStore` — a
 * background `/ai-chat` tab or the Toolbox mini-chat must never pick up the
 * modal's chat.
 *
 * Note: this modal does NOT render `document_fields_proposed` variant cards.
 * That two-step proposal flow belongs to the docx auto-mapping panel on the
 * document page (scope=document), not to the create-КП modal.
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

export const useDocumentGenerationModalChat = () => {
  const { chatService } = useServices()
  const { notifyApiError, notifyError } = useNotifications()
  const { t } = useLocalI18n({ en, ru })
  const stream = useChatStream()
  const modalStore = useDocumentGenerationModalStore()

  const isPreview = ref(false)
  const currentChat = shallowRef<ChatDetail | null>(null)
  const isLoading = ref(false)
  const isPostingMessage = ref(false)
  const error = ref<string | null>(null)

  /** Id of the document template created in this session — drives the CTA. */
  const createdDocumentId = ref<number | null>(null)
  const inputValue = ref('')

  const sendRequestGate = createRequestGate()
  let nextOptimisticMessageId = -1

  const pendingEventsByMessageId = new Map<number, ChatMessageEventDto[]>()

  const messages = computed<ChatMessage[]>(() => currentChat.value?.messages ?? [])

  const isSending = computed(
    () =>
      isPostingMessage.value ||
      stream.lifecycle.value === 'connecting' ||
      stream.lifecycle.value === 'streaming',
  )

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

  const applyStreamPatch = (
    chatId: number,
    messageId: number,
    event: ChatMessageEventDto,
  ): void => {
    const msg = findMessage(chatId, messageId)
    if (!msg) return
    const patch = computeStreamEventPatch(msg, event)
    if (!patch) return
    replaceMessageInChat(currentChat, messageId, patch)
  }

  /**
   * Reads the freshly-fetched chat's `documentId` and, if a template
   * appeared/changed during this turn, updates `createdDocumentId` (CTA) and
   * fires the store's "document updated" signal.
   */
  const syncCreatedDocument = (freshChat: ChatDetail): void => {
    const fresh = freshChat.documentId
    if (fresh === null || fresh === undefined) return
    createdDocumentId.value = fresh
    modalStore.signalDocumentUpdated(fresh)
  }

  const finalizeAssistantMessage = async (
    chatId: number,
    messageId: number,
    status: ChatMessageStatus,
  ): Promise<void> => {
    try {
      const liveRuntimeState = new Map<
        number,
        { timelineEvents?: ChatMessageEventDto[]; thinkingContent?: string | null }
      >()
      const existingChat = currentChat.value
      if (existingChat?.id === chatId) {
        for (const m of existingChat.messages) {
          if (m.role !== 'assistant') continue
          if (m.timelineEvents || m.thinkingContent) {
            liveRuntimeState.set(m.id, {
              timelineEvents: m.timelineEvents,
              thinkingContent: m.thinkingContent,
            })
          }
        }
      }

      const freshChat = await chatService.fetchChat(chatId)
      if (currentChat.value?.id === chatId) {
        const hasCarry = liveRuntimeState.size > 0
        const hasBuffered = pendingEventsByMessageId.size > 0
        if (hasCarry || hasBuffered) {
          freshChat.messages = freshChat.messages.map((m) => {
            const carry = liveRuntimeState.get(m.id)
            const buffered = pendingEventsByMessageId.get(m.id)
            if (!carry && !buffered) return m

            let timelineEvents = carry?.timelineEvents ?? m.timelineEvents
            if (buffered && buffered.length > 0) {
              const seen = new Set((timelineEvents ?? []).map((e) => e.sequence))
              const merged = [...(timelineEvents ?? [])]
              for (const ev of buffered) {
                if (seen.has(ev.sequence)) continue
                seen.add(ev.sequence)
                merged.push(ev)
              }
              merged.sort((a, b) => a.sequence - b.sequence)
              timelineEvents = merged
            }

            return {
              ...m,
              timelineEvents,
              thinkingContent: carry?.thinkingContent ?? m.thinkingContent,
            }
          })
        }
        pendingEventsByMessageId.clear()
        currentChat.value = freshChat
        syncCreatedDocument(freshChat)
      }

      if (status === 'error') {
        const finalMsg = freshChat.messages.find((m) => m.id === messageId)
        const errMessage = finalMsg?.metadata?.error?.message ?? null
        notifyError(errMessage ?? t('errors.aiTurnFailed'), t('common.error'))
      }
    } catch (err) {
      notifyApiError(err, t('errors.loadChatFailed'), t('common.error'))
    }
  }

  const subscribeToAssistantStream = (
    chatId: number,
    messageId: number,
    streamUrl: string,
    { resumeFromBeginning = false }: { resumeFromBeginning?: boolean } = {},
  ): void => {
    void stream.start({
      chatId,
      messageId,
      streamUrl,
      resumeFromBeginning,
      callbacks: {
        onEvent: (event) => {
          // Timeline steps + shared content/status/error routing (interim →
          // streamingContent, final → content, error → errorMessage). See
          // `computeStreamEventPatch`.
          appendTimelineEvent(chatId, messageId, event)
          applyStreamPatch(chatId, messageId, event)
        },
        onSettled: async (settledStatus) => {
          if (currentChat.value?.id !== chatId) return
          await finalizeAssistantMessage(chatId, messageId, settledStatus)
        },
      },
    })
  }

  /**
   * Reconnects to an in-flight assistant message's SSE stream (the document chat
   * is mid-turn from another surface / tab). Used by edit-mode `init`. Same
   * pattern as `useReportGenerationModalChat.reconnectInFlight`.
   */
  const reconnectInFlight = (chat: ChatDetail): void => {
    const inFlight = chat.messages.find(
      (m) => m.role === 'assistant' && (m.status === 'pending' || m.status === 'running'),
    )
    if (!inFlight) return
    const streamUrl = `/api/chats/${chat.id}/stream/${inFlight.id}`
    subscribeToAssistantStream(chat.id, inFlight.id, streamUrl, {
      resumeFromBeginning: true,
    })
  }

  /**
   * Initializes the modal for the current `modalStore` mode. Call once when the
   * modal opens.
   *  - create-mode → preview-state (no DB write); first send lazy-creates a
   *    `scope_type='general'` document_template chat.
   *  - edit-mode → resume the open template's latest document-scoped chat
   *    (`scope_type='document'` + `document_id`); seed `createdDocumentId` from
   *    the template id; reconnect to any in-flight turn. When the backend has
   *    nothing to resume (no prior chat), fall back to preview-state — the first
   *    send lazy-creates a document-scoped chat bound to the template.
   * `prefillPrompt` (if any) is written into the input without auto-sending.
   */
  const init = async (): Promise<void> => {
    error.value = null
    createdDocumentId.value = null
    inputValue.value = modalStore.prefillPrompt ?? ''

    if (modalStore.mode === 'edit') {
      const documentId = modalStore.documentId
      // Seed the CTA target early — the template exists regardless of whether a
      // prior chat resumes. (The CTA is hidden when we're already on it.)
      createdDocumentId.value = documentId ?? null

      if (documentId === null || documentId <= 0) {
        // Edit-mode with no template id — degenerate; behave like create.
        isPreview.value = true
        currentChat.value = null
        return
      }

      isLoading.value = true
      try {
        const resumed = await chatService.resume({
          scope_type: 'document',
          document_id: documentId,
        })
        if (resumed) {
          currentChat.value = resumed
          isPreview.value = false
          if (resumed.documentId !== null && resumed.documentId !== undefined) {
            createdDocumentId.value = resumed.documentId
          }
          reconnectInFlight(resumed)
        } else {
          // 204 — nothing to resume. First send lazy-creates the chat.
          isPreview.value = true
          currentChat.value = null
        }
      } catch (err) {
        error.value = t('errors.loadChatFailed')
        notifyApiError(err, t('errors.loadChatFailed'), t('common.error'))
        currentChat.value = null
        isPreview.value = true
      } finally {
        isLoading.value = false
      }
      return
    }

    // create-mode
    isPreview.value = true
    currentChat.value = null
  }

  const sendMessage = async (content: string): Promise<void> => {
    if (isSending.value || !content.trim()) return

    const requestToken = sendRequestGate.next()
    isPostingMessage.value = true
    error.value = null

    try {
      if (isPreview.value || !currentChat.value) {
        // ── Inline-create path ───────────────────────────────────────────
        // Edit-mode binds the new chat to the open template (scope=document)
        // so the AI updates it in place; create-mode is a general session.
        const isEdit = modalStore.mode === 'edit' && (modalStore.documentId ?? 0) > 0
        const result = await chatService.sendInline(
          isEdit
            ? {
                scope_type: 'document',
                type: 'document_template',
                document_id: modalStore.documentId as number,
                content,
              }
            : {
                scope_type: 'general',
                type: 'document_template',
                content,
              },
        )

        if (!sendRequestGate.isCurrent(requestToken)) return
        if (!result.chat) {
          notifyError(t('errors.createChatFailed'), t('common.error'))
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
        isPreview.value = false

        await nextTick()
        subscribeToAssistantStream(
          result.chat.id,
          result.assistantMessage.id,
          result.streamUrl,
        )
        flushPendingEvents(result.chat.id, result.assistantMessage.id)
      } else {
        // ── Existing-chat path ───────────────────────────────────────────
        const chatId = currentChat.value.id
        const optimisticMessageId = nextOptimisticMessageId--
        const optimisticMessage: ChatMessage = {
          id: optimisticMessageId,
          chatId,
          role: 'user',
          content,
          metadata: null,
          createdAt: new Date().toISOString(),
          isOptimistic: true,
        }

        currentChat.value = {
          ...currentChat.value,
          messages: [...currentChat.value.messages, optimisticMessage],
        }

        try {
          const result = await chatService.sendMessage(chatId, content)
          if (!sendRequestGate.isCurrent(requestToken)) return

          if (currentChat.value?.id === chatId) {
            const assistantPlaceholder: ChatMessage = {
              ...result.assistantMessage,
              timelineEvents: result.assistantMessage.timelineEvents ?? [],
            }
            currentChat.value = {
              ...currentChat.value,
              messages: [
                ...currentChat.value.messages.filter(
                  (m) => m.id !== optimisticMessageId,
                ),
                result.userMessage,
                assistantPlaceholder,
              ],
              ...(result.chat
                ? {
                    title: result.chat.title,
                    documentId: result.chat.documentId,
                    aiContext: result.chat.aiContext,
                    updatedAt: result.chat.updatedAt,
                  }
                : {}),
            }
          }

          await nextTick()
          subscribeToAssistantStream(chatId, result.assistantMessage.id, result.streamUrl)
          flushPendingEvents(chatId, result.assistantMessage.id)
        } catch (innerError) {
          if (currentChat.value?.id === chatId) {
            currentChat.value = {
              ...currentChat.value,
              messages: currentChat.value.messages.filter(
                (m) => m.id !== optimisticMessageId,
              ),
            }
          }
          throw innerError
        }
      }
    } catch (err) {
      notifyApiError(err, t('errors.networkFailed'), t('common.error'))
    } finally {
      if (sendRequestGate.isCurrent(requestToken)) {
        isPostingMessage.value = false
      }
    }
  }

  const reset = (): void => {
    stream.stop()
    stream.reset()
    sendRequestGate.invalidate()
    pendingEventsByMessageId.clear()
    currentChat.value = null
    isPreview.value = false
    isLoading.value = false
    isPostingMessage.value = false
    error.value = null
    createdDocumentId.value = null
    inputValue.value = ''
  }

  return {
    // state
    isPreview,
    currentChat,
    messages,
    isLoading,
    isSending,
    error,
    createdDocumentId,
    inputValue,
    // actions
    init,
    sendMessage,
    reset,
  }
}
