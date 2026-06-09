import { computed, nextTick, ref, shallowRef, watch } from 'vue'
import { useUserStore } from '@/stores/user'
import { useReportContextStore } from '@/stores/reportContext'
import { useDashboardContextStore } from '@/stores/dashboardContext'
import { useDocumentContextStore } from '@/stores/documentContext'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { createRequestGate } from '@/utils/requestGate'
import type {
  ChatDetail,
  ChatListItem,
  ChatMessage,
  ChatMessageStatus,
} from '@/entities/chat'
import type { ChatMessageEventDto, ReportContextPayload } from '@/api/types/chats'
import {
  computeStreamEventPatch,
  createOptimisticMessage,
  removeMessageById,
} from './chatHelpers'
import { useChatStream } from './useChatStream'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

/**
 * Mini-chat state machine — independent from the full-screen `useChat()`.
 *
 * Why a parallel composable instead of reusing `useChat` / `useChatMessaging`:
 *  - The full-screen flow drives the shared `useChatsStore` (setActive,
 *    prependChat, syncChatListItemFromDetail). MiniChat must NOT touch
 *    active-id state — otherwise a concurrent `/ai-chat` page (or the
 *    report-generation modal) would pick up the mini-chat's chat on mount
 *    and the user would lose the chat they were actually viewing there.
 *  - The flow itself is different: full-screen always has a persisted
 *    chat; MiniChat has a preview-state (UI-only) and lazy-creates the
 *    chat only on the first user send via `sendInline`.
 *
 * Lifecycle:
 *  - `initializeOnOpen()` — called by the widget when the Popover opens.
 *    Auto-resume picks the most recent active-window chat in the current
 *    scope; on 204 → preview-state.
 *  - `enterPreview()` — "Новый диалог" action; drops `currentChat`, no
 *    backend write.
 *  - `selectFromDropdown(id)` — loads an existing chat without touching
 *    `chatsStore`.
 *  - `sendMessage(content, reportContext?)` — `sendInline` in preview,
 *    canonical `sendMessage` otherwise. SSE stream subscribed on success.
 *
 * Scope-change watcher: when the user navigates between report pages
 * (or off a report) with the widget alive, all mini-chat state is reset
 * — re-opening triggers a fresh resume for the new scope.
 */

interface SendMessageOptions {
  /** When true, attach the in-report payload to the send (only for scope=report). */
  reportContext?: ReportContextPayload
}

interface MiniChatScope {
  scopeType: 'report' | 'dashboard' | 'document' | 'general'
  reportId: number | null
  dashboardId: number | null
  documentId: number | null
}

/**
 * Replaces a message in `currentChat.value.messages` in place. Triggers a
 * shallow-ref reassignment so Vue picks up the change.
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

export const useMiniChat = () => {
  const userStore = useUserStore()
  const reportContextStore = useReportContextStore()
  const dashboardContextStore = useDashboardContextStore()
  const documentContextStore = useDocumentContextStore()
  const { chatService } = useServices()
  const { notifyApiError, notifyError } = useNotifications()
  const { t } = useLocalI18n({ en, ru })
  const stream = useChatStream()

  // ────────────────────────────────────────────────────────────────────────
  // State
  // ────────────────────────────────────────────────────────────────────────

  /**
   * `true` while the widget is in UI-only preview-state — no chat exists
   * in the DB yet. Cleared when (a) `initializeOnOpen` resumes an existing
   * chat, (b) `selectFromDropdown` loads one, or (c) `sendMessage` lazily
   * materializes one via `sendInline`.
   */
  const isPreview = ref(false)

  /**
   * `shallowRef` because we replace the whole `ChatDetail` object on each
   * mutation (immutable update pattern). Avoids the cost of Vue's deep
   * reactivity on the messages array — same pattern used by `useChat`.
   */
  const currentChat = shallowRef<ChatDetail | null>(null)

  const dropdownItems = ref<ChatListItem[]>([])
  const isInitializing = ref(false)
  const isLoadingChat = ref(false)
  const isLoadingDropdown = ref(false)
  const isPostingMessage = ref(false)
  const lastError = ref<string | null>(null)

  const sendRequestGate = createRequestGate()
  let nextOptimisticMessageId = -1

  /**
   * See `useChatMessaging.pendingEventsByMessageId` for the race this
   * guards against. The buffer is keyed by assistant message id and
   * drained whenever the matching message lands in `currentChat.messages`.
   */
  const pendingEventsByMessageId = new Map<number, ChatMessageEventDto[]>()

  // ────────────────────────────────────────────────────────────────────────
  // Scope detection
  // ────────────────────────────────────────────────────────────────────────

  /**
   * Derived purely from `reportContextStore`, not `route.name`. The store is
   * written by exactly one place — `ReportPage` sets it on a loaded report and
   * clears it on unmount / id-change — so a hydrated context with a positive
   * `reportId` unambiguously means "we're on a report page with a live report".
   * This is the precondition for report-scope anyway (the resume / inline-create
   * calls require that `report_id`), and it can't silently break if the
   * `/reports/:id` route is ever renamed (a `route.name` literal would).
   * Single read site for the scope decision so we don't drift across the composable.
   */
  const currentScope = computed<MiniChatScope>(() => {
    const reportId = reportContextStore.reportId
    if (reportContextStore.hasReportContext && reportId !== null && reportId > 0) {
      return {
        scopeType: 'report',
        reportId,
        dashboardId: null,
        documentId: null,
      }
    }
    const dashboardId = dashboardContextStore.dashboardId
    if (dashboardContextStore.hasDashboardContext && dashboardId !== null && dashboardId > 0) {
      return {
        scopeType: 'dashboard',
        reportId: null,
        dashboardId,
        documentId: null,
      }
    }
    const documentId = documentContextStore.documentId
    if (documentContextStore.hasDocumentContext && documentId !== null && documentId > 0) {
      return {
        scopeType: 'document',
        reportId: null,
        dashboardId: null,
        documentId,
      }
    }
    return { scopeType: 'general', reportId: null, dashboardId: null, documentId: null }
  })

  const isSending = computed(
    () =>
      isPostingMessage.value ||
      stream.lifecycle.value === 'connecting' ||
      stream.lifecycle.value === 'streaming',
  )

  // ────────────────────────────────────────────────────────────────────────
  // SSE stream wiring (copy of useChatMessaging's relevant subset, scoped to
  // the local `currentChat` ref). Kept inline rather than extracted so
  // mini-chat's state stays fully isolated from `useChatMessaging`'s store
  // mutations.
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
   * After the AI turn settles — refetch the chat (without going through
   * `chatsStore`) to pull the canonical content/metadata. Preserves the
   * runtime-only `timelineEvents` / `thinkingContent` accumulators.
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
      }

      if (status === 'error') {
        const finalMsg = freshChat.messages.find((m) => m.id === messageId)
        const errMessage = finalMsg?.metadata?.error?.message ?? null
        if (errMessage) {
          notifyError(errMessage, t('common.error'))
        } else {
          notifyError(t('errors.aiTurnFailed'), t('common.error'))
        }
      }

      // Refresh the dropdown — fresh chat probably moved to the top
      // (or aggregate counters changed). Best-effort: ignore failures so
      // we don't toast twice for the same turn.
      void loadDropdown().catch(() => {})
    } catch (error) {
      notifyApiError(error, t('errors.loadChatFailed'), t('common.error'))
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

  // ────────────────────────────────────────────────────────────────────────
  // Public flow
  // ────────────────────────────────────────────────────────────────────────

  const loadDropdown = async (): Promise<void> => {
    if (!userStore.getAuthCredential) {
      dropdownItems.value = []
      return
    }
    isLoadingDropdown.value = true
    try {
      dropdownItems.value = await chatService.fetchChatsScoped({
        scope_type: currentScope.value.scopeType,
        report_id: currentScope.value.reportId ?? undefined,
        dashboard_id: currentScope.value.dashboardId ?? undefined,
        document_id: currentScope.value.documentId ?? undefined,
        limit: 10,
      })
    } catch (error) {
      // Dropdown is supplementary — log but don't toast (the user can still
      // use the main flow). The empty-state in the dropdown surfaces visually.
      notifyApiError(error, t('miniChat.errors.loadHistoryFailed'), t('common.error'))
      dropdownItems.value = []
    } finally {
      isLoadingDropdown.value = false
    }
  }

  /**
   * Called when the widget Popover opens. Atomically resumes the most
   * recent active-window chat for the current scope (or transitions to
   * preview-state on 204), then loads the dropdown in the background.
   */
  const initializeOnOpen = async (): Promise<void> => {
    isInitializing.value = true
    lastError.value = null
    try {
      const resumed = await chatService.resume({
        scope_type: currentScope.value.scopeType,
        report_id: currentScope.value.reportId ?? undefined,
        dashboard_id: currentScope.value.dashboardId ?? undefined,
        document_id: currentScope.value.documentId ?? undefined,
      })
      if (resumed) {
        currentChat.value = resumed
        isPreview.value = false
        // If the resumed chat has an in-flight assistant message (page
        // reloaded mid-turn / another tab is talking to the same chat),
        // reconnect to its stream so the timeline keeps ticking.
        const inFlight = resumed.messages.find(
          (m) =>
            m.role === 'assistant' && (m.status === 'pending' || m.status === 'running'),
        )
        if (inFlight) {
          const streamUrl = `/api/chats/${resumed.id}/stream/${inFlight.id}`
          subscribeToAssistantStream(resumed.id, inFlight.id, streamUrl, {
            resumeFromBeginning: true,
          })
        }
      } else {
        currentChat.value = null
        isPreview.value = true
      }
      // Parallel dropdown load — UI doesn't need to block on it.
      void loadDropdown()
    } catch (error) {
      lastError.value = t('miniChat.errors.initFailed')
      notifyApiError(error, t('miniChat.errors.initFailed'), t('common.error'))
      currentChat.value = null
      isPreview.value = true
    } finally {
      isInitializing.value = false
    }
  }

  /**
   * "Новый диалог" — drops the current chat from the widget without
   * touching the backend. The next send will materialize a fresh chat
   * via `sendInline`.
   */
  const enterPreview = (): void => {
    if (isSending.value) return
    // Cancel any active SSE subscription before dropping the chat — otherwise
    // the stream's settle callbacks would touch `currentChat.value` after
    // we set it to null and produce a no-op (the chatId guard catches it,
    // but explicit cleanup is clearer).
    stream.stop()
    stream.reset()
    pendingEventsByMessageId.clear()
    currentChat.value = null
    isPreview.value = true
  }

  /**
   * Loads an existing chat by id WITHOUT mutating `chatsStore`. Keeps the
   * full-screen pages' active-id state untouched (see top-of-file note).
   */
  const selectFromDropdown = async (chatId: number): Promise<void> => {
    if (isLoadingChat.value || isSending.value) return
    isLoadingChat.value = true
    lastError.value = null
    try {
      stream.stop()
      stream.reset()
      pendingEventsByMessageId.clear()

      const loaded = await chatService.fetchChat(chatId)
      currentChat.value = loaded
      isPreview.value = false

      const inFlight = loaded.messages.find(
        (m) =>
          m.role === 'assistant' && (m.status === 'pending' || m.status === 'running'),
      )
      if (inFlight) {
        const streamUrl = `/api/chats/${loaded.id}/stream/${inFlight.id}`
        subscribeToAssistantStream(loaded.id, inFlight.id, streamUrl, {
          resumeFromBeginning: true,
        })
      }
    } catch (error) {
      notifyApiError(error, t('errors.loadChatFailed'), t('common.error'))
    } finally {
      isLoadingChat.value = false
    }
  }

  /**
   * Sends a user message. Two paths:
   *  1. Preview-state → `sendInline` (atomic chat create + first message).
   *  2. Existing chat → canonical `sendMessage`.
   *
   * The assistant placeholder is pushed into `currentChat.messages` before
   * the SSE stream opens; runtime-only `timelineEvents: []` is initialized
   * so renderers can `.length` safely.
   */
  const sendMessage = async (
    content: string,
    sendOptions?: SendMessageOptions,
  ): Promise<void> => {
    if (isSending.value || !content.trim()) return

    const requestToken = sendRequestGate.next()
    isPostingMessage.value = true
    lastError.value = null

    try {
      if (isPreview.value || !currentChat.value) {
        // ── Inline-create path ───────────────────────────────────────────
        const result = await chatService.sendInline({
          scope_type: currentScope.value.scopeType,
          report_id: currentScope.value.reportId ?? undefined,
          dashboard_id: currentScope.value.dashboardId ?? undefined,
          document_id: currentScope.value.documentId ?? undefined,
          content,
          report_context: sendOptions?.reportContext,
        })

        if (!sendRequestGate.isCurrent(requestToken)) return
        if (!result.chat) {
          // sendInline contract guarantees `chat`; defensive against future
          // backend changes.
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

        // Refresh dropdown — newly created chat should appear at the top.
        // Best-effort, swallow errors (already toasted by loadDropdown if any).
        void loadDropdown().catch(() => {})
      } else {
        // ── Existing-chat path ───────────────────────────────────────────
        const chatId = currentChat.value.id
        const optimisticMessageId = nextOptimisticMessageId--
        const optimisticMessage = createOptimisticMessage(
          chatId,
          content,
          optimisticMessageId,
        )

        currentChat.value = {
          ...currentChat.value,
          messages: [...currentChat.value.messages, optimisticMessage],
        }

        try {
          const result = await chatService.sendMessage(
            chatId,
            content,
            sendOptions?.reportContext,
          )
          if (!sendRequestGate.isCurrent(requestToken)) return

          if (currentChat.value?.id === chatId) {
            const assistantPlaceholder: ChatMessage = {
              ...result.assistantMessage,
              timelineEvents: result.assistantMessage.timelineEvents ?? [],
            }
            currentChat.value = {
              ...currentChat.value,
              messages: [
                ...removeMessageById(currentChat.value.messages, optimisticMessageId),
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
          // Strip the optimistic row so the user can retry from a clean state.
          if (currentChat.value?.id === chatId) {
            currentChat.value = {
              ...currentChat.value,
              messages: removeMessageById(currentChat.value.messages, optimisticMessageId),
            }
          }
          throw innerError
        }
      }
    } catch (error) {
      notifyApiError(error, t('errors.networkFailed'), t('common.error'))
    } finally {
      if (sendRequestGate.isCurrent(requestToken)) {
        isPostingMessage.value = false
      }
    }
  }

  // ────────────────────────────────────────────────────────────────────────
  // Reset on scope change. When the user navigates between report pages (or
  // away from /reports entirely) with the widget still alive, drop all local
  // state — the next `initializeOnOpen` will re-resume in the new scope.
  // ────────────────────────────────────────────────────────────────────────
  watch(currentScope, (next, prev) => {
    if (
      prev !== undefined &&
      next.scopeType === prev.scopeType &&
      next.reportId === prev.reportId &&
      next.dashboardId === prev.dashboardId &&
      next.documentId === prev.documentId
    ) {
      return
    }
    stream.stop()
    stream.reset()
    pendingEventsByMessageId.clear()
    sendRequestGate.invalidate()
    isPostingMessage.value = false
    currentChat.value = null
    isPreview.value = false
    dropdownItems.value = []
  })

  return {
    // state
    isPreview,
    currentChat,
    dropdownItems,
    isInitializing,
    isLoadingChat,
    isLoadingDropdown,
    isSending,
    lastError,
    currentScope,
    // actions
    initializeOnOpen,
    enterPreview,
    selectFromDropdown,
    sendMessage,
    loadDropdown,
  }
}
