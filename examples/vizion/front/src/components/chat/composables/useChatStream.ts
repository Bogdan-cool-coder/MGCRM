import { ref, shallowRef, type Ref } from 'vue'
import { useUserStore } from '@/stores/user'
import type {
  ChatMessageEventDto,
  ChatMessageEventType,
  ChatMessageEventsResponseDto,
  ChatMessageStatus,
} from '@/api/types/chats'

/**
 * Connection lifecycle for one assistant message turn.
 * `idle` — not started yet (default after composable creation or after `stop`).
 * `connecting` — opening the fetch or replaying batch events.
 * `streaming` — SSE stream is actively delivering events.
 * `done` / `error` / `cancelled` — terminal; mirrors the server `done`-sentinel status.
 */
export type ChatStreamLifecycle =
  | 'idle'
  | 'connecting'
  | 'streaming'
  | 'done'
  | 'error'
  | 'cancelled'

interface StreamCallbacks {
  /** Emitted for each event (live or replayed). Use to drive timeline indicators. */
  onEvent?: (_event: ChatMessageEventDto) => void
  /**
   * Emitted when the AI turn finishes (server done-sentinel) OR when a non-recoverable
   * error happens on the client side (network down, auth lost, etc.).
   *
   * `finalStatus` is what the consumer should set on the assistant message:
   *  - `done` / `error` / `cancelled` come from the server sentinel
   *  - `error` is also returned for client-side failures.
   */
  onSettled?: (_status: ChatMessageStatus, _ctx: { error?: Error }) => void
}

interface StartParams {
  chatId: number
  messageId: number
  /** Absolute or relative URL coming from `sendMessage` response. */
  streamUrl: string
  /** When `true`, replay already-recorded events via the batch endpoint before opening the SSE stream. */
  resumeFromBeginning?: boolean
  callbacks?: StreamCallbacks
}

const TYPED_EVENT_TYPES = new Set<ChatMessageEventType>([
  'started',
  'thinking',
  'tool_call',
  'tool_result',
  'dry_run_start',
  'dry_run_result',
  'retry',
  'text_delta',
  'final_message',
  'widget_variants',
  'document_fields_proposed',
  'error',
])

const isKnownEventType = (value: string): value is ChatMessageEventType =>
  TYPED_EVENT_TYPES.has(value as ChatMessageEventType)

/**
 * Subscribes to the AI-turn SSE stream for a single assistant message and exposes
 * its progress as reactive state. Designed to be reused across:
 *  - fresh send (open with `streamUrl` from the 202 response)
 *  - reload restore (replay batch events first, then resume the live stream)
 *
 * Why `fetch` + `ReadableStream` instead of `EventSource`:
 * the backend authenticates via Bearer token in the `Authorization` header.
 * `EventSource` cannot set custom headers, so we hand-parse the SSE frames.
 *
 * Resume contract (per chats_frontend.md):
 *  - `?since=N` in the stream URL is the authoritative cursor.
 *  - The composable tracks the last applied `sequence` internally and reconnects
 *    with `?since=lastSeq` on transient network failures.
 *  - For terminal messages we **do not** open the live stream — the batch
 *    endpoint replays the full timeline by itself.
 */
export const useChatStream = () => {
  const userStore = useUserStore()

  const lifecycle: Ref<ChatStreamLifecycle> = ref('idle')
  /** Append-only event log for the currently tracked message. */
  const events = shallowRef<ChatMessageEventDto[]>([])
  /** Last error that aborted the stream (network, parse, server-error frame). */
  const lastError = shallowRef<Error | null>(null)

  let controller: AbortController | null = null
  let lastSequence = 0
  let currentMessageId: number | null = null

  const reset = () => {
    lifecycle.value = 'idle'
    events.value = []
    lastError.value = null
    lastSequence = 0
    currentMessageId = null
  }

  const stop = () => {
    if (controller) {
      controller.abort()
      controller = null
    }
    if (lifecycle.value === 'connecting' || lifecycle.value === 'streaming') {
      lifecycle.value = 'cancelled'
    }
    currentMessageId = null
  }

  const pushEvent = (event: ChatMessageEventDto, callbacks?: StreamCallbacks) => {
    // SSE frames may arrive out of order across reconnects when the server replays
    // events from `?since=N` — drop anything we've already applied.
    if (event.sequence <= lastSequence) return
    lastSequence = event.sequence
    events.value = [...events.value, event]
    callbacks?.onEvent?.(event)
  }

  const buildStreamRequestUrl = (streamUrl: string, since: number): string => {
    // `streamUrl` from the API is a relative path (e.g. `/api/chats/13/stream/26`).
    // Strip an existing `since` query-param to avoid duplicates on reconnect.
    const [path, search] = streamUrl.split('?')
    const params = new URLSearchParams(search ?? '')
    params.set('since', String(since))
    return `${path}?${params.toString()}`
  }

  const buildEventsRequestUrl = (
    chatId: number,
    messageId: number,
    since: number,
    limit: number,
  ): string => {
    const params = new URLSearchParams()
    params.set('since', String(since))
    params.set('limit', String(limit))
    return `/api/chats/${chatId}/messages/${messageId}/events?${params.toString()}`
  }

  /**
   * Fetches one page of the batch event-replay endpoint via native `fetch`.
   *
   * Why native fetch and not the shared axios client:
   * the chat stream is bracketed by two HTTP calls that MUST carry the same
   * Bearer token under identical conditions — the batch GET (`/events`) and
   * the live SSE GET (`/stream`). The SSE side cannot use axios because
   * `EventSource` lacks header support and we already hand-roll the request,
   * so we mirror that for the batch GET. Going through axios in one branch
   * and `fetch` in the other has bitten us with QA observing the second-turn
   * `/events` GET arriving without an `Authorization` header — using a single
   * code path with an explicit `Authorization` header removes that asymmetry
   * and any chance of an interceptor / staleness race.
   */
  const fetchEventsPage = async (
    chatId: number,
    messageId: number,
    cursor: number,
  ): Promise<ChatMessageEventsResponseDto> => {
    const token = userStore.getAuthCredential
    const headers: Record<string, string> = {
      Accept: 'application/json',
    }
    if (token) headers.Authorization = `Bearer ${token}`

    const response = await fetch(buildEventsRequestUrl(chatId, messageId, cursor, 100), {
      method: 'GET',
      headers,
      credentials: 'same-origin',
      signal: controller?.signal,
    })

    if (!response.ok) {
      throw new Error(`Batch events endpoint returned HTTP ${response.status}`)
    }

    return (await response.json()) as ChatMessageEventsResponseDto
  }

  /**
   * Replays already-recorded events via the batch endpoint (one or more pages).
   * Returns the final `message_status` reported by the backend.
   */
  const replayBatchEvents = async (
    chatId: number,
    messageId: number,
    callbacks?: StreamCallbacks,
  ): Promise<ChatMessageStatus> => {
    let cursor = lastSequence
    let messageStatus: ChatMessageStatus = 'pending'

    // Paginate through all pages. Limit guard prevents infinite loops if the
    // server keeps returning has_more=true (defensive — should never happen).
    for (let page = 0; page < 50; page += 1) {
      const response = await fetchEventsPage(chatId, messageId, cursor)
      for (const event of response.events) {
        pushEvent(event, callbacks)
      }
      messageStatus = response.message_status
      if (!response.has_more || response.next_cursor == null) break
      cursor = response.next_cursor
    }

    return messageStatus
  }

  /**
   * Splits a raw SSE frame chunk into `event:` / `data:` / `id:` fields.
   * Per the SSE spec, lines starting with `:` are comments and should be ignored.
   */
  const parseSseFrame = (
    rawFrame: string,
  ): { type: string | null; data: string | null; id: number | null } => {
    let type: string | null = null
    let data: string | null = null
    let id: number | null = null

    for (const line of rawFrame.split('\n')) {
      if (line === '' || line.startsWith(':')) continue
      const colonIdx = line.indexOf(':')
      if (colonIdx === -1) continue
      const field = line.slice(0, colonIdx)
      const value = line.slice(colonIdx + 1).replace(/^ /, '')

      if (field === 'event') type = value
      else if (field === 'data') data = data === null ? value : `${data}\n${value}`
      else if (field === 'id') {
        const parsed = Number.parseInt(value, 10)
        if (Number.isFinite(parsed)) id = parsed
      }
    }

    return { type, data, id }
  }

  const consumeStream = async (
    chatId: number,
    messageId: number,
    streamUrl: string,
    callbacks: StreamCallbacks | undefined,
  ): Promise<{ settled: boolean; status: ChatMessageStatus }> => {
    controller = new AbortController()
    const token = userStore.getAuthCredential
    const headers: Record<string, string> = {
      Accept: 'text/event-stream',
    }
    if (token) headers.Authorization = `Bearer ${token}`

    const response = await fetch(buildStreamRequestUrl(streamUrl, lastSequence), {
      method: 'GET',
      headers,
      signal: controller.signal,
      credentials: 'same-origin',
    })

    if (!response.ok || !response.body) {
      throw new Error(`SSE stream returned HTTP ${response.status}`)
    }

    lifecycle.value = 'streaming'

    const reader = response.body.getReader()
    const decoder = new TextDecoder('utf-8')
    let buffer = ''
    let settledStatus: ChatMessageStatus | null = null

    while (true) {
      const { value, done } = await reader.read()
      if (done) break

      buffer += decoder.decode(value, { stream: true })

      // SSE frames are delimited by a blank line (CRLF or LF). Normalize CRLFs first.
      const normalized = buffer.replace(/\r\n/g, '\n')
      const frames = normalized.split('\n\n')
      buffer = frames.pop() ?? ''

      for (const frame of frames) {
        if (frame.trim() === '') continue
        const { type, data, id } = parseSseFrame(frame)
        if (data === null) continue

        // The sentinel `event: done` arrives without an `id:` field — the spec
        // says the browser won't update `Last-Event-ID` for it, and we mirror
        // that semantics on reconnects.
        if (type === 'done') {
          try {
            const parsed = JSON.parse(data) as { status?: ChatMessageStatus }
            settledStatus = parsed.status ?? 'done'
          } catch {
            settledStatus = 'done'
          }
          continue
        }

        // Typed events carry `id: <sequence>` and a JSON-encoded data envelope.
        let payload: { type?: string; sequence?: number; payload?: Record<string, unknown>; created_at?: string } = {}
        try {
          payload = JSON.parse(data)
        } catch {
          // Malformed frame — skip but keep the stream open. Server should not emit these.
          continue
        }

        const eventType = (type ?? payload.type ?? '') as string
        const sequence = id ?? payload.sequence ?? 0
        if (!eventType || sequence <= 0) continue

        pushEvent(
          {
            sequence,
            type: isKnownEventType(eventType) ? eventType : eventType,
            payload: payload.payload ?? {},
            created_at: payload.created_at ?? new Date().toISOString(),
          },
          callbacks,
        )
      }
    }

    if (settledStatus) {
      return { settled: true, status: settledStatus }
    }

    // Stream closed without a sentinel — typically the 480s wall-clock budget
    // expired on the server side. Signal "not settled" so the caller can
    // reconnect with `?since=lastSequence`.
    return { settled: false, status: 'running' }
  }

  /**
   * Opens (or resumes) the stream for one assistant message.
   *
   * Order of operations:
   * 1. Replay the batch endpoint up to its current state (gives us the timeline
   *    accumulated while we were disconnected; advances `lastSequence`).
   * 2. If the message is already terminal — settle immediately, no live stream.
   * 3. Otherwise open the SSE stream with `?since=lastSequence`. On a non-aborted
   *    network failure with the message still active, reconnect once with the
   *    advanced cursor.
   */
  const start = async ({
    chatId,
    messageId,
    streamUrl,
    resumeFromBeginning = false,
    callbacks,
  }: StartParams): Promise<void> => {
    stop()
    reset()
    currentMessageId = messageId
    lifecycle.value = 'connecting'

    if (resumeFromBeginning) {
      lastSequence = 0
    }

    // Pre-create the controller so the batch-replay fetch below honours `stop()`.
    // `consumeStream` later overwrites this with a fresh controller per attempt;
    // that overwrite is intentional — each reconnect needs its own abort scope.
    controller = new AbortController()

    try {
      const status = await replayBatchEvents(chatId, messageId, callbacks)
      if (currentMessageId !== messageId) return // superseded by another `start`

      if (status === 'done' || status === 'error' || status === 'cancelled') {
        lifecycle.value = status === 'done' ? 'done' : status === 'error' ? 'error' : 'cancelled'
        callbacks?.onSettled?.(status, {})
        return
      }

      // Reconnect up to two times after wall-clock timeouts. Each `consumeStream`
      // call resumes with `?since=lastSequence`, so we never lose events.
      let attempts = 0
      while (attempts < 3) {
        attempts += 1
        const result = await consumeStream(chatId, messageId, streamUrl, callbacks)
        if (currentMessageId !== messageId) return

        if (result.settled) {
          lifecycle.value =
            result.status === 'done' ? 'done' : result.status === 'error' ? 'error' : 'cancelled'
          callbacks?.onSettled?.(result.status, {})
          return
        }
        // Server closed without sentinel — loop and reconnect.
      }

      // Hit the reconnect ceiling — surface as error so the caller can prompt the user.
      lifecycle.value = 'error'
      const err = new Error('SSE stream exceeded reconnect attempts')
      lastError.value = err
      callbacks?.onSettled?.('error', { error: err })
    } catch (error) {
      if (currentMessageId !== messageId) return

      const isAbort = error instanceof DOMException && error.name === 'AbortError'
      if (isAbort) {
        // Aborted by `stop()` — keep `cancelled` lifecycle set by `stop()`.
        return
      }

      const err = error instanceof Error ? error : new Error(String(error))
      lifecycle.value = 'error'
      lastError.value = err
      callbacks?.onSettled?.('error', { error: err })
    } finally {
      controller = null
    }
  }

  return {
    lifecycle,
    events,
    lastError,
    start,
    stop,
    reset,
  }
}
