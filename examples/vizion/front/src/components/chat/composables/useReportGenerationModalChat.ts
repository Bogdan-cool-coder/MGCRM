import { computed, nextTick, ref, shallowRef } from 'vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { createRequestGate } from '@/utils/requestGate'
import { useReportGenerationModalStore } from '@/stores/reportGenerationModal'
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
 * State machine for the global report-generation modal — the overlay that
 * replaced the now-removed standalone `/ai-reports` page.
 *
 * Why a parallel composable instead of reusing `useChat` / `useChatPage`:
 *  - Same reasoning as `useMiniChat`: the full-screen flow drives the shared
 *    `useChatsStore` (setActive / prependChat / reconcile). This modal must NOT
 *    touch that active-id state — a `/ai-chat` tab open in the background would
 *    otherwise pick up the modal's chat on mount.
 *  - Lifecycle differs: create-mode has a UI-only preview-state and lazy-creates
 *    the chat via `sendInline({ type: 'report_generation' })`; edit-mode resumes
 *    an existing report's chat by id.
 *
 * Built directly on the `useMiniChat` SSE wiring (same immutable shallowRef
 * update pattern, the same `pendingEventsByMessageId` buffer, the same
 * `useChatStream` subscription, the same in-flight reconnect on load). The only
 * domain difference is that this composable additionally tracks `createdReportId`
 * — the id of the report created/updated during the session — by reading the
 * canonical `currentChat.reportId` after every settle (the same signal the
 * modal surfaces via `ChatReportBanner`).
 */

/**
 * Replaces a message in `currentChat.value.messages` in place via an immutable
 * shallowRef reassignment (copied from `useMiniChat.replaceMessageInChat`).
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

export const useReportGenerationModalChat = () => {
  const { chatService } = useServices()
  const { notifyApiError, notifyError } = useNotifications()
  const { t } = useLocalI18n({ en, ru })
  const stream = useChatStream()
  const modalStore = useReportGenerationModalStore()

  // ────────────────────────────────────────────────────────────────────────
  // State
  // ────────────────────────────────────────────────────────────────────────

  /**
   * `true` while the modal is in create-mode preview-state — no chat exists in
   * the DB yet. Cleared when `sendMessage` lazily materializes one via
   * `sendInline`, or when edit-mode resumes an existing chat.
   */
  const isPreview = ref(false)

  /** Immutable-update target — replaced wholesale on each mutation. */
  const currentChat = shallowRef<ChatDetail | null>(null)

  const isLoading = ref(false)
  const isPostingMessage = ref(false)
  const error = ref<string | null>(null)

  /**
   * Id of the report created or updated in this session. Drives the
   * "Open report" CTA. Seeded from `reportId` in edit-mode (the report already
   * exists); set/updated after each settle when `currentChat.reportId` lands.
   */
  const createdReportId = ref<number | null>(null)

  /**
   * Two-way bound to the modal's `ChatInput`. Used for prefill (write a prompt
   * without auto-sending) and cleared by the input on submit.
   */
  const inputValue = ref('')

  const sendRequestGate = createRequestGate()
  let nextOptimisticMessageId = -1

  /** Same race-buffer as `useMiniChat` — events arriving before their message. */
  const pendingEventsByMessageId = new Map<number, ChatMessageEventDto[]>()

  const messages = computed<ChatMessage[]>(() => currentChat.value?.messages ?? [])

  const isSending = computed(
    () =>
      isPostingMessage.value ||
      stream.lifecycle.value === 'connecting' ||
      stream.lifecycle.value === 'streaming',
  )

  // ────────────────────────────────────────────────────────────────────────
  // SSE stream wiring — a scoped copy of `useMiniChat`'s subset so the modal's
  // state stays fully isolated from `useChatsStore` mutations.
  // ────────────────────────────────────────────────────────────────────────

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
   * Reads the freshly-fetched chat's `reportId` and, if a report
   * appeared/changed during this turn, (a) updates `createdReportId` to drive
   * the "Open report" CTA and (b) fires the store's one-way "report updated,
   * refetch it" signal so an open report page reflects the AI's changes.
   */
  const syncCreatedReport = (freshChat: ChatDetail): void => {
    const fresh = freshChat.reportId
    if (fresh === null) return
    createdReportId.value = fresh
    // Always signal on settle (even when the id didn't change — an
    // `update_report` re-touches the same report) so the open report page
    // refetches after every successful turn.
    modalStore.signalReportUpdated(fresh)
  }

  /**
   * After the AI turn settles — refetch the chat (without going through
   * `chatsStore`) to pull canonical content/metadata and the now-populated
   * `reportId`. Preserves runtime-only `timelineEvents` / `thinkingContent`.
   * Mirrors `useMiniChat.finalizeAssistantMessage` plus the `syncCreatedReport`
   * step.
   */
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
        syncCreatedReport(freshChat)
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
          // Timeline steps (above the bubble) + the shared content/status/error
          // patch (interim → streamingContent, final → content, error →
          // errorMessage). See `computeStreamEventPatch`.
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
   * Reconnects to an in-flight assistant message's SSE stream (page reloaded /
   * another tab is mid-turn). Used by edit-mode `init`. Same pattern as
   * `useMiniChat.initializeOnOpen`.
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

  // ────────────────────────────────────────────────────────────────────────
  // Public flow
  // ────────────────────────────────────────────────────────────────────────

  /**
   * Initializes the modal for the current `modalStore` mode. Call once when the
   * modal opens.
   *  - create-mode → preview-state (no DB write); first send lazy-creates.
   *  - edit-mode → resume the report's existing `report_generation` chat by id,
   *    seed `createdReportId` from the report id, reconnect to any in-flight turn.
   * `prefillPrompt` (if any) is written into the input without auto-sending.
   */
  const init = async (): Promise<void> => {
    error.value = null
    createdReportId.value = null
    inputValue.value = modalStore.prefillPrompt ?? ''

    if (modalStore.mode === 'edit') {
      const chatId = modalStore.chatId
      // Seed the CTA target early — the report exists regardless of whether the
      // chat loads. (CTA is still hidden when we're already on that report.)
      createdReportId.value = modalStore.reportId ?? null

      if (chatId === null || chatId <= 0) {
        // Edit-mode without a chat to resume (e.g. a system report or a report
        // whose generation chat was pruned). Fall back to preview-state so the
        // user can still start describing changes — the first send creates a
        // fresh report_generation chat.
        isPreview.value = true
        currentChat.value = null
        return
      }

      isLoading.value = true
      try {
        const loaded = await chatService.fetchChat(chatId)
        currentChat.value = loaded
        isPreview.value = false
        if (loaded.reportId !== null) {
          createdReportId.value = loaded.reportId
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
   * Sends a user message. Two paths (mirrors `useMiniChat.sendMessage`):
   *  1. Preview-state → `sendInline({ type: 'report_generation' })` (atomic
   *     chat create + first message).
   *  2. Existing chat → canonical `sendMessage`.
   */
  const sendMessage = async (content: string): Promise<void> => {
    if (isSending.value || !content.trim()) return

    const requestToken = sendRequestGate.next()
    isPostingMessage.value = true
    error.value = null

    try {
      if (isPreview.value || !currentChat.value) {
        // ── Inline-create path ───────────────────────────────────────────
        const result = await chatService.sendInline({
          scope_type: 'general',
          type: 'report_generation',
          content,
        })

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
                    reportId: result.chat.reportId,
                    aiContext: result.chat.aiContext,
                    report: result.chat.report,
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

  /**
   * Tears down all local state. Called on modal `@hide`. The active SSE
   * subscription is stopped — but note the AI turn continues running in the
   * background job regardless (the stream just stops being consumed); reopening
   * the report's chat in edit-mode reconnects via `reconnectInFlight`. This
   * matches the mini-chat policy: closing mid-stream never blocks the turn.
   */
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
    createdReportId.value = null
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
    createdReportId,
    inputValue,
    // actions
    init,
    sendMessage,
    reset,
  }
}
