import { computed, nextTick, ref, type Ref } from 'vue'
import { useChatsStore } from '@/stores/chats'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { createRequestGate } from '@/utils/requestGate'
import type { ChatDetail, ChatMessage, ChatMessageStatus } from '@/entities/chat'
import type { ChatMessageEventDto, ReportContextPayload } from '@/api/types/chats'
import {
  computeStreamEventPatch,
  createOptimisticMessage,
  removeMessageById,
} from './chatHelpers'
import { useChatStream } from './useChatStream'

interface UseChatMessagingOptions {
  currentChat: Ref<ChatDetail | null>
  t: (_key: string) => string
}

/**
 * Replaces a message in the `currentChat.messages` array in place (by id).
 * Returns `true` when a replacement happened, `false` when no message with the
 * given id was present (e.g. user switched chats while the stream was running).
 */
const replaceMessageById = (
  chat: ChatDetail | null,
  messageId: number,
  patch: Partial<ChatMessage>,
): boolean => {
  if (!chat) return false
  const idx = chat.messages.findIndex((m) => m.id === messageId)
  if (idx === -1) return false
  chat.messages = [
    ...chat.messages.slice(0, idx),
    { ...chat.messages[idx]!, ...patch },
    ...chat.messages.slice(idx + 1),
  ]
  return true
}

export const useChatMessaging = (options: UseChatMessagingOptions) => {
  const chatsStore = useChatsStore()
  const { chatService } = useServices()
  const { notifyApiError, notifyError } = useNotifications()
  const stream = useChatStream()

  const isPostingMessage = ref(false)
  const sendRequestGate = createRequestGate()
  let nextOptimisticMessageId = -1

  /**
   * Buffer for timeline events that arrive before their target assistant
   * message is present in `currentChat.messages`. This guards against a race
   * we observed in QA on the second-and-later turn of an existing chat:
   * `POST /messages` returns the placeholder DTO and we immediately call
   * `subscribeToAssistantStream(...)` → `stream.start(...)`. Inside `start`,
   * the very first `await` yields control back to the microtask queue —
   * meanwhile the SSE `consumeStream` can begin flushing events as soon as the
   * fetch body opens. If our caller's `currentChat.value.messages = [...]`
   * mutation has not yet rippled through Vue's reactive proxy / DOM update
   * by the time `appendTimelineEvent` runs, `findMessage` returns `null` and
   * the event would be silently dropped.
   *
   * The buffer stores per-message arrays; every time `appendTimelineEvent` is
   * called we ALSO try to flush any pending events for the same message id.
   * Cleared in `clearMessagingScope` (page leave / company switch / logout)
   * to avoid leaking across unrelated chats.
   */
  const pendingEventsByMessageId = new Map<number, ChatMessageEventDto[]>()

  /**
   * `true` while either (a) the POST /messages request is in flight, or
   * (b) the SSE stream for the resulting assistant message is still active.
   * The send button stays disabled across both phases so the user can't issue
   * a parallel request and trip the backend's 409 guard.
   */
  const isSending = computed(
    () =>
      isPostingMessage.value ||
      stream.lifecycle.value === 'connecting' ||
      stream.lifecycle.value === 'streaming',
  )

  const finalizeAssistantMessage = async (
    chatId: number,
    messageId: number,
    status: ChatMessageStatus,
  ): Promise<void> => {
    // Stream finished — re-fetch the chat to pull the final assistant content,
    // metadata (tool_calls / tool_results / error), and any new `report_id` /
    // `ai_context`. The list item also gets refreshed via `syncChatListItemFromDetail`.
    try {
      // Snapshot runtime-only accumulators (timelineEvents, thinkingContent)
      // before replacing `currentChat` — the fresh chat from backend does not
      // carry them and we want them preserved so the user can review the
      // thinking-block after settle.
      const liveRuntimeState = new Map<
        number,
        { timelineEvents?: ChatMessageEventDto[]; thinkingContent?: string | null }
      >()
      const existingChat = options.currentChat.value
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
      chatsStore.syncChatListItemFromDetail(freshChat)
      if (options.currentChat.value?.id === chatId) {
        // Re-attach runtime-only state to the corresponding messages, and
        // merge any leftover buffered timeline events (events that arrived
        // before the placeholder was committed — see comment on
        // `pendingEventsByMessageId`). The buffer is the only place those
        // events would otherwise survive; the backend doesn't carry the
        // runtime-only `timelineEvents` shape on the persisted message.
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
        // Buffer is consumed (merged into the message above) — clear it so a
        // subsequent turn doesn't replay events from this one.
        pendingEventsByMessageId.clear()
        options.currentChat.value = freshChat
      }

      if (status === 'error') {
        const finalMsg = freshChat.messages.find((m) => m.id === messageId)
        const errMessage = finalMsg?.metadata?.error?.message ?? null
        if (errMessage) {
          notifyError(errMessage, options.t('common.error'))
        } else {
          notifyError(options.t('errors.aiTurnFailed'), options.t('common.error'))
        }
      }
    } catch (error) {
      notifyApiError(error, options.t('errors.loadChatFailed'), options.t('common.error'))
    }
  }

  /**
   * Whitelist of event types that drive the visual timeline (thinking-block).
   * `text_delta` and `error` are handled separately — they update content /
   * status rather than appearing as a timeline step.
   */
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
   * Reads the current message from `currentChat` (by id) without mutating it,
   * returning `null` when missing (e.g. user switched chats mid-stream).
   */
  const findMessage = (chatId: number, messageId: number): ChatMessage | null => {
    const chat = options.currentChat.value
    if (!chat || chat.id !== chatId) return null
    return chat.messages.find((m) => m.id === messageId) ?? null
  }

  /**
   * Drains the pending-events buffer for a given message id into the message's
   * `timelineEvents`. Used as the second half of `appendTimelineEvent` and on
   * its own from `sendMessage` after the placeholder is committed — that lets
   * the buffer get flushed even if the first SSE frame arrived before we got
   * here.
   */
  const flushPendingEvents = (chatId: number, messageId: number): void => {
    const buffered = pendingEventsByMessageId.get(messageId)
    if (!buffered || buffered.length === 0) return
    const msg = findMessage(chatId, messageId)
    if (!msg) return // still not in array — keep buffering for the next attempt

    const seen = new Set((msg.timelineEvents ?? []).map((e) => e.sequence))
    const merged = [...(msg.timelineEvents ?? [])]
    for (const event of buffered) {
      if (seen.has(event.sequence)) continue
      seen.add(event.sequence)
      merged.push(event)
    }
    // Order matters for the renderer (it pairs tool_call→tool_result by
    // arrival order). Sort by sequence to be safe — backend emits monotonic,
    // but a reconnect-mid-buffer could interleave.
    merged.sort((a, b) => a.sequence - b.sequence)

    pendingEventsByMessageId.delete(messageId)
    replaceMessageById(options.currentChat.value, messageId, {
      timelineEvents: merged,
    })
  }

  const appendTimelineEvent = (
    chatId: number,
    messageId: number,
    event: ChatMessageEventDto,
  ): void => {
    if (!TIMELINE_EVENT_TYPES.has(event.type)) return

    const msg = findMessage(chatId, messageId)
    if (!msg) {
      // Placeholder not yet committed to `currentChat.messages` — buffer the
      // event so we can apply it once the placeholder lands. See the comment
      // on `pendingEventsByMessageId` above.
      const existing = pendingEventsByMessageId.get(messageId) ?? []
      if (existing.some((e) => e.sequence === event.sequence)) return
      existing.push(event)
      pendingEventsByMessageId.set(messageId, existing)
      return
    }

    const existing = msg.timelineEvents ?? []
    // De-dup by sequence — the SSE reader already filters, but batch replay
    // may overlap with the live stream on reconnect.
    if (existing.some((e) => e.sequence === event.sequence)) return
    replaceMessageById(options.currentChat.value, messageId, {
      timelineEvents: [...existing, event],
    })

    // Opportunistic drain — covers the case where some events were buffered
    // before the placeholder landed and now newer events are arriving live.
    flushPendingEvents(chatId, messageId)
  }

  /**
   * Applies the content/status/error patch for one SSE event using the shared
   * `computeStreamEventPatch` router (interim → streamingContent, final →
   * content, error → errorMessage). Timeline-only events return `null` and are
   * handled by `appendTimelineEvent` separately.
   */
  const applyStreamPatch = (
    chatId: number,
    messageId: number,
    event: ChatMessageEventDto,
  ): void => {
    const msg = findMessage(chatId, messageId)
    if (!msg) return
    const patch = computeStreamEventPatch(msg, event)
    if (!patch) return
    replaceMessageById(options.currentChat.value, messageId, patch)
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
          // 1. Timeline (thinking-block above the bubble): every step the user
          //    should see. `appendTimelineEvent` whitelists timeline-step types;
          //    `text_delta` / `final_message` / `error` are no-ops there.
          appendTimelineEvent(chatId, messageId, event)

          // 2. Content/status/error: routed by the shared patch helper. Interim
          //    `kind='content'` deltas go to `streamingContent` (rendered inside
          //    the thinking block), `final_message` sets the body `content`, and
          //    `error` sets `errorMessage` + status so the bubble shows an
          //    error-state instead of an eternal spinner. Buffered providers
          //    (GLM) emit only `final_message` after the steps — same path.
          applyStreamPatch(chatId, messageId, event)
        },
        onSettled: async (settledStatus) => {
          if (options.currentChat.value?.id !== chatId) {
            // The user switched chats — leave the stream state alone; the next
            // `loadChat` will reconcile.
            return
          }
          await finalizeAssistantMessage(chatId, messageId, settledStatus)
        },
      },
    })
  }

  /**
   * `sendOptions.reportContext` — optional in-report snapshot for quick_qa sends.
   * Only the MiniChat widget on a report page passes this; the full-screen
   * `/ai-chat` page and the report-generation modal leave it undefined so the
   * backend uses the legacy QUICK_QA prompt.
   */
  const sendMessage = async (
    content: string,
    sendOptions?: { reportContext?: ReportContextPayload },
  ): Promise<void> => {
    if (!options.currentChat.value || isSending.value) return

    const chatId = options.currentChat.value.id
    const requestToken = sendRequestGate.next()
    const optimisticMessageId = nextOptimisticMessageId--
    const optimisticMessage: ChatMessage = createOptimisticMessage(
      chatId,
      content,
      optimisticMessageId,
    )
    options.currentChat.value.messages.push(optimisticMessage)

    isPostingMessage.value = true

    try {
      // M4 async flow: backend returns 202 with `user_message`, `assistant_message`
      // (status=pending, content=null), `stream_url`, and an optional `chat` snapshot.
      const result = await chatService.sendMessage(chatId, content, sendOptions?.reportContext)

      if (!sendRequestGate.isCurrent(requestToken)) {
        return
      }

      // Drop the optimistic placeholder and append the canonical user/assistant rows.
      // We pre-initialise `timelineEvents: []` on the assistant placeholder
      // so the buffer-flush in `appendTimelineEvent` has a concrete array to
      // merge into (instead of `undefined`), and so renderers can safely
      // `.length` without an optional-chain.
      if (options.currentChat.value?.id === chatId) {
        const assistantPlaceholder: ChatMessage = {
          ...result.assistantMessage,
          timelineEvents: result.assistantMessage.timelineEvents ?? [],
        }
        options.currentChat.value.messages = [
          ...removeMessageById(options.currentChat.value.messages, optimisticMessageId),
          result.userMessage,
          assistantPlaceholder,
        ]

        if (result.chat) {
          // Backend returned a partial chat snapshot — apply title / report_id /
          // ai_context / report immediately so the UI can show the report banner
          // without an extra GET, but keep the freshly-built messages array we
          // just composed (the snapshot's `messages` is not authoritative here).
          options.currentChat.value.title = result.chat.title
          options.currentChat.value.reportId = result.chat.reportId
          options.currentChat.value.aiContext = result.chat.aiContext
          options.currentChat.value.report = result.chat.report
          options.currentChat.value.updatedAt = result.chat.updatedAt
          chatsStore.syncChatListItemFromDetail(options.currentChat.value)
        }
      }

      // Give Vue one tick to flush the messages-array mutation before we
      // open the SSE stream. Belt-and-braces against the second-turn race
      // we observed in QA: `subscribeToAssistantStream` is `void`-fired and
      // its first `await` yields immediately — without this `nextTick`, the
      // first SSE frame could land in `appendTimelineEvent` BEFORE the
      // placeholder is visible to `findMessage`. The pending-events buffer
      // catches that scenario too, but ordering things correctly here keeps
      // the buffer as a safety net rather than the primary path.
      await nextTick()

      subscribeToAssistantStream(chatId, result.assistantMessage.id, result.streamUrl)

      // Drain any timeline events that slipped through during the await above.
      flushPendingEvents(chatId, result.assistantMessage.id)
    } catch (error) {
      if (options.currentChat.value?.id === chatId) {
        options.currentChat.value.messages = removeMessageById(
          options.currentChat.value.messages,
          optimisticMessageId,
        )
      }
      notifyApiError(error, options.t('errors.networkFailed'), options.t('common.error'))
    } finally {
      if (sendRequestGate.isCurrent(requestToken)) {
        isPostingMessage.value = false
      }
    }
  }

  /**
   * Reconnects to the SSE stream for an in-flight assistant message after a
   * page reload or after `loadChat` finishes. The current message's `status`
   * is read from the freshly-loaded chat — only `pending` / `running` rows
   * need a live stream; terminal rows are already complete.
   */
  const resumeActiveStream = (chatId: number): void => {
    const chat = options.currentChat.value
    if (!chat || chat.id !== chatId) return

    const inFlight = chat.messages.find(
      (m) => m.role === 'assistant' && (m.status === 'pending' || m.status === 'running'),
    )
    if (!inFlight) return

    const streamUrl = `/api/chats/${chatId}/stream/${inFlight.id}`
    subscribeToAssistantStream(chatId, inFlight.id, streamUrl, { resumeFromBeginning: true })
  }

  const clearMessagingScope = (): void => {
    sendRequestGate.invalidate()
    isPostingMessage.value = false
    pendingEventsByMessageId.clear()
    stream.stop()
    stream.reset()
  }

  return {
    isSending,
    sendMessage,
    resumeActiveStream,
    clearMessagingScope,
  }
}
