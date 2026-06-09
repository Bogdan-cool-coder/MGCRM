import { computed, nextTick, ref, shallowRef } from 'vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { createRequestGate } from '@/utils/requestGate'
import { useWidgetGenerationModalStore } from '@/stores/widgetGenerationModal'
import type {
  ChatDetail,
  ChatMessage,
  ChatMessageStatus,
} from '@/entities/chat'
import type { ChatMessageEventDto, WidgetVariantDto } from '@/api/types/chats'
import { computeStreamEventPatch } from './chatHelpers'
import { useChatStream } from './useChatStream'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

/**
 * State machine for the global widget-generation modal — a direct mirror of
 * `useReportGenerationModalChat`. The only domain differences:
 *  - sends `type='widget_generation'` (instead of `report_generation`);
 *  - tracks `createdWidgetId` from the canonical `currentChat.widgetId` after
 *    every settle (instead of `reportId`);
 *  - edit-mode without a pinned chat lazy-creates a chat bound to `widgetId`
 *    (passes `widget_id` to `sendInline`).
 *
 * Like the report modal, it does NOT touch `useChatsStore` — a background
 * `/ai-chat` tab must never pick up the modal's chat.
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

/**
 * Validates a `widget_variants` event payload before trusting it. Keeps only
 * well-formed variants ({index:number, label:string, config:object}); a payload
 * with no usable variants is treated as absent (variants panel stays hidden).
 */
const parseWidgetVariantsPayload = (raw: unknown): WidgetVariantDto[] => {
  if (typeof raw !== 'object' || raw === null) return []
  const candidate = (raw as Record<string, unknown>).variants
  if (!Array.isArray(candidate)) return []

  const variants: WidgetVariantDto[] = []
  for (const entry of candidate as unknown[]) {
    if (typeof entry !== 'object' || entry === null) continue
    const obj = entry as Record<string, unknown>
    if (
      typeof obj.index === 'number' &&
      typeof obj.label === 'string' &&
      typeof obj.config === 'object' &&
      obj.config !== null
    ) {
      variants.push(entry as WidgetVariantDto)
    }
  }
  return variants
}

export const useWidgetGenerationModalChat = () => {
  const { chatService } = useServices()
  const { notifyApiError, notifyError } = useNotifications()
  const { t } = useLocalI18n({ en, ru })
  const stream = useChatStream()
  const modalStore = useWidgetGenerationModalStore()

  const isPreview = ref(false)
  const currentChat = shallowRef<ChatDetail | null>(null)
  const isLoading = ref(false)
  const isPostingMessage = ref(false)
  const error = ref<string | null>(null)

  /** Id of the widget created/updated in this session — drives the CTA. */
  const createdWidgetId = ref<number | null>(null)
  const inputValue = ref('')

  /**
   * AI-proposed widget variants from the latest `widget_variants` event. While
   * non-empty the modal shows the variant-picker grid instead of the message
   * list. Cleared when the user picks one (a new create turn begins) or on reset.
   */
  const variants = shallowRef<WidgetVariantDto[]>([])
  /** True from the moment a variant is picked until that turn settles. */
  const isSelectingVariant = ref(false)

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
   * Reads the freshly-fetched chat's `widgetId` and, if a widget
   * appeared/changed during this turn, updates `createdWidgetId` (CTA) and
   * fires the store's "widget updated, refetch it" signal.
   */
  const syncCreatedWidget = (freshChat: ChatDetail): void => {
    const fresh = freshChat.widgetId
    if (fresh === null) return
    createdWidgetId.value = fresh
    modalStore.signalWidgetUpdated(fresh)
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
        syncCreatedWidget(freshChat)
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
          appendTimelineEvent(chatId, messageId, event)

          if (event.type === 'widget_variants') {
            const parsed = parseWidgetVariantsPayload(event.payload)
            if (parsed.length > 0) {
              variants.value = parsed
            }
            return
          }

          // Shared content/status/error routing (interim → streamingContent,
          // final → content, error → errorMessage). See `computeStreamEventPatch`.
          applyStreamPatch(chatId, messageId, event)
        },
        onSettled: async (settledStatus) => {
          if (currentChat.value?.id !== chatId) return
          await finalizeAssistantMessage(chatId, messageId, settledStatus)
        },
      },
    })
  }

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
   */
  const init = async (): Promise<void> => {
    error.value = null
    createdWidgetId.value = null
    inputValue.value = modalStore.prefillPrompt ?? ''

    if (modalStore.mode === 'edit') {
      const chatId = modalStore.chatId
      createdWidgetId.value = modalStore.widgetId ?? null

      if (chatId === null || chatId <= 0) {
        // Edit-mode without a chat to resume (older widget). Preview-state — the
        // first send creates a fresh widget_generation chat bound to widgetId.
        isPreview.value = true
        currentChat.value = null
        return
      }

      isLoading.value = true
      try {
        const loaded = await chatService.fetchChat(chatId)
        currentChat.value = loaded
        isPreview.value = false
        if (loaded.widgetId !== null) {
          createdWidgetId.value = loaded.widgetId
        }
        reconnectInFlight(loaded)
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

  /**
   * Returns `true` when the message was accepted (a turn started), `false` when
   * it was dropped by the in-flight / empty-content guard. The boolean lets
   * `selectVariant` distinguish "send started" from "send refused" and restore
   * the picker instead of leaving the user in a dead-end with no variants and no
   * outgoing request.
   */
  const sendMessage = async (content: string): Promise<boolean> => {
    if (isSending.value || !content.trim()) return false

    // A new turn supersedes any previously-proposed variants — drop them so the
    // picker doesn't flash back over the message list mid-send.
    variants.value = []

    const requestToken = sendRequestGate.next()
    isPostingMessage.value = true
    error.value = null

    try {
      if (isPreview.value || !currentChat.value) {
        // ── Inline-create path ───────────────────────────────────────────
        // Edit-mode without a pinned chat passes `widget_id` so the new chat
        // binds to the existing widget instead of creating a fresh one.
        const editWidgetId =
          modalStore.mode === 'edit' && modalStore.widgetId ? modalStore.widgetId : undefined

        const result = await chatService.sendInline({
          scope_type: 'general',
          type: 'widget_generation',
          widget_id: editWidgetId,
          content,
        })

        // Superseded by a newer send / reset — the modal has moved on, so report
        // "accepted" and let the newer flow own the picker state.
        if (!sendRequestGate.isCurrent(requestToken)) return true
        if (!result.chat) {
          notifyError(t('errors.createChatFailed'), t('common.error'))
          // No turn actually started — let the caller restore the picker so the
          // user can retry.
          return false
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
          if (!sendRequestGate.isCurrent(requestToken)) return true

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
                    widgetId: result.chat.widgetId,
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
      // The send failed before a turn started — report "not accepted" so the
      // caller can restore the variant picker for a retry.
      return false
    } finally {
      if (sendRequestGate.isCurrent(requestToken)) {
        isPostingMessage.value = false
      }
    }

    // Reached only on the happy path: a turn started and the stream subscription
    // is live. The user has left the picker for the message timeline.
    return true
  }

  /**
   * The user picked variant N. Recommended path (per backend contract): send a
   * normal chat message so the chat↔widget link and timeline are preserved — the
   * AI then calls `create_widget` with that variant's config. The label is
   * included to keep the instruction unambiguous if the variant config has
   * scrolled out of the model's short context.
   *
   * Variants are hidden immediately on pick (the create turn takes over the
   * modal body) and `isSelectingVariant` guards against a double-click during
   * the in-flight send.
   */
  const selectVariant = async (index: number): Promise<void> => {
    // Only guard against a double-pick here. The "is a turn already running?"
    // decision belongs to `sendMessage` alone — duplicating it with `isSending`
    // risked the two guards disagreeing and silently swallowing the pick (panel
    // cleared, no request sent, user stuck). `sendMessage` reports back whether
    // it accepted the turn so we can restore the picker if it refused.
    if (isSelectingVariant.value) return
    const chosen = variants.value.find((v) => v.index === index)
    if (!chosen) return

    // Snapshot so we can restore the picker verbatim if the send is refused.
    const snapshot = variants.value
    isSelectingVariant.value = true
    variants.value = []
    try {
      const content = t('widgetGenerationModal.variants.selectMessage', {
        index: chosen.index,
        label: chosen.label,
      })
      const accepted = await sendMessage(content)
      if (!accepted) {
        // A turn was still in flight — bring the picker back so the user can
        // retry instead of facing an empty body with nothing happening.
        variants.value = snapshot
      }
    } finally {
      isSelectingVariant.value = false
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
    createdWidgetId.value = null
    inputValue.value = ''
    variants.value = []
    isSelectingVariant.value = false
  }

  return {
    // state
    isPreview,
    currentChat,
    messages,
    isLoading,
    isSending,
    error,
    createdWidgetId,
    inputValue,
    variants,
    isSelectingVariant,
    // actions
    init,
    sendMessage,
    selectVariant,
    reset,
  }
}
