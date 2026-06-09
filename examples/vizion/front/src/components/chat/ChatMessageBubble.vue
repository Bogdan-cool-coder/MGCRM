<template>
  <div :class="['message-wrapper', `message-wrapper--${message.role}`]">
    <div :class="['bubble', `bubble--${message.role}`, { 'bubble--optimistic': message.isOptimistic }]">
      <!-- Assistant: render the live thinking-timeline (steps + tool calls)
           above the markdown content, then the content itself (with a blinking
           caret while streaming), and finally any CTA / metadata.
           The bare typing dots are only shown when the turn is still
           connecting and no events / content have arrived yet. -->
      <template v-if="message.role === 'assistant'">
        <ChatThinkingTimeline
          :events="message.timelineEvents"
          :status="message.status"
          :thinking-content="message.thinkingContent"
          :streaming-content="message.streamingContent"
          :started-at="message.startedAt"
          :finished-at="message.finishedAt"
        />

        <ChatTypingIndicator v-if="showBareTyping" class="bubble-typing" />
        <template v-else-if="hasRenderableContent">
          <div
            class="bubble-content bubble-content--markdown"
            v-html="renderedHtml"
          />
          <div v-if="isStreamingContent" class="bubble-caret-row">
            <span class="bubble-caret" aria-hidden="true" />
          </div>
        </template>

        <!-- Error-state: a failed turn (terminal `error` event / status=error)
             renders a clear message instead of an eternal spinner. Shown only
             when there's no canonical answer in the body. -->
        <div v-if="showErrorState" class="bubble-error" role="alert">
          <i class="pi pi-exclamation-triangle bubble-error__icon" aria-hidden="true" />
          <span class="bubble-error__text">{{ errorText }}</span>
        </div>
      </template>
      <p v-else class="bubble-content">{{ message.content ?? '' }}</p>

      <div v-if="actionMarker" class="bubble-cta">
        <Button
          size="small"
          severity="primary"
          :label="actionMarker.label"
          :loading="isInvokingAction"
          :disabled="isInvokingAction"
          icon="pi pi-sparkles"
          @click="handleActionClick"
        />
      </div>

      <slot name="metadata" :metadata="message.metadata" />
      <span v-if="showTime" class="bubble-time">{{ formattedTime }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useLocalI18n } from '@/composables/useLocalI18n'
import Button from 'primevue/button'
import type { ChatMessage } from '@/entities/chat'
import {
  extractActionMarker,
  renderChatMarkdown,
  type ChatActionMarker,
} from '@/utils/markdown'
import { canUseDocuments } from '@/shared/auth/capabilities'
import ChatTypingIndicator from './ChatTypingIndicator.vue'
import ChatThinkingTimeline from './ChatThinkingTimeline.vue'
import en from './locale/en.json'
import ru from './locale/ru.json'

interface Props {
  message: ChatMessage
  /**
   * When `true`, fenced JSON blocks are inspected for an action-marker and a CTA
   * button is shown when one is found. The marker block is hidden from the
   * rendered markdown. Per backend contract, only `quick_qa` chats should opt in.
   */
  enableActionMarker?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  enableActionMarker: false,
})

const emit = defineEmits<{
  /**
   * Fired when the user clicks the action-marker CTA button. Parent is expected
   * to open the report-generation modal pre-filled with the marker `prompt`,
   * and call `onComplete()` to clear the loading state.
   */
  action: [payload: { marker: ChatActionMarker; onComplete: () => void }]
}>()

const { t, locale } = useLocalI18n({ en, ru })

const formattedTime = computed(() =>
  new Intl.DateTimeFormat(locale.value, { hour: '2-digit', minute: '2-digit' }).format(
    new Date(props.message.createdAt),
  ),
)

/**
 * An assistant turn is still "in flight" until its status reaches a terminal
 * state (done / error / cancelled). Drives the blinking caret + typing-dots.
 */
const isInFlight = computed(() => {
  if (props.message.role !== 'assistant') return false
  return props.message.status === 'pending' || props.message.status === 'running'
})

const hasRenderableContent = computed(() => {
  if (props.message.role !== 'assistant') return false
  const content = props.message.content
  return typeof content === 'string' && content.length > 0
})

/**
 * Bare typing-dots: shown only at the very beginning of the turn before any
 * timeline event or content delta arrives. Once we have at least one event
 * (handled by `ChatThinkingTimeline`) or a partial content stream, dots are
 * unnecessary — the timeline + caret are the live activity cue.
 */
const showBareTyping = computed(() => {
  if (props.message.role !== 'assistant') return false
  if (!isInFlight.value) return false
  if (hasRenderableContent.value) return false
  const eventsLen = props.message.timelineEvents?.length ?? 0
  return eventsLen === 0
})

/**
 * Blinking caret at the tail of the content while the assistant is still
 * streaming text. Stops once the turn settles, even if more deltas
 * theoretically could arrive (final_message is canonical).
 */
const isStreamingContent = computed(
  () => isInFlight.value && hasRenderableContent.value,
)

/**
 * A failed assistant turn. Sourced either from the runtime `errorMessage`
 * (set by the terminal `error` SSE event's `user_message`) or from the
 * settled message `status='error'`. Suppressed when a canonical answer made
 * it into the body (partial success — render the answer instead).
 */
const showErrorState = computed(() => {
  if (props.message.role !== 'assistant') return false
  if (hasRenderableContent.value) return false
  const hasRuntimeError =
    typeof props.message.errorMessage === 'string' && props.message.errorMessage.length > 0
  return hasRuntimeError || props.message.status === 'error'
})

/**
 * User-facing error copy. Prefers the already-localized `user_message` carried
 * on the runtime `errorMessage`, then the persisted `metadata.error.message`,
 * then a generic chat-domain fallback.
 */
const errorText = computed(() => {
  const runtime = props.message.errorMessage
  if (typeof runtime === 'string' && runtime.trim() !== '') return runtime
  const persisted = props.message.metadata?.error?.message
  if (typeof persisted === 'string' && persisted.trim() !== '') return persisted
  return t('errors.aiTurnFailed')
})

const showTime = computed(() => {
  if (props.message.role !== 'assistant') return true
  // Hide the timestamp while the turn is in flight (otherwise the user sees
  // an "11:32" right next to a still-running spinner). Show once settled.
  return !isInFlight.value
})

const extracted = computed(() => {
  const content = props.message.content ?? ''
  if (props.message.role !== 'assistant') {
    return { cleanContent: content, marker: null }
  }
  if (!props.enableActionMarker) {
    return { cleanContent: content, marker: null }
  }
  const result = extractActionMarker(content)
  // Drop the document-generation CTA when the Documents feature is OFF — the
  // raw JSON block is already stripped from `cleanContent`, so the marker just
  // becomes absent (no dead button) without leaking the fenced block.
  if (result.marker?.action === 'redirect_to_document_generation' && !canUseDocuments()) {
    return { cleanContent: result.cleanContent, marker: null }
  }
  return result
})

const actionMarker = computed(() => extracted.value.marker)
const renderedHtml = computed(() => renderChatMarkdown(extracted.value.cleanContent))

const isInvokingAction = ref(false)

const handleActionClick = () => {
  const marker = actionMarker.value
  if (!marker || isInvokingAction.value) return
  isInvokingAction.value = true
  emit('action', {
    marker,
    onComplete: () => {
      isInvokingAction.value = false
    },
  })
}
</script>

<style lang="scss" scoped>
.message-wrapper {
  display: flex;
  margin-bottom: 0.5rem;
  // The wrapper is itself a flex item inside `.message-list` (column flex).
  // `min-width: 0` is required so the inner bubble's `max-width: 100%` can
  // actually shrink below the wrapper's intrinsic min-content size when a
  // descendant carries an unbreakable token.
  min-width: 0;
  max-width: 100%;

  &--user {
    justify-content: flex-end;
  }

  &--assistant,
  &--system {
    justify-content: flex-start;
  }
}

.bubble {
  // Cap the visual width independent of the parent container size. Without
  // an absolute cap a wide flex-row (e.g. the report-generation modal or the
  // fullscreen /ai-chat surface) lets
  // 70% mean 700-900px, and a single very long unbroken token (serialized
  // JSON, base64, long URL) can then push the bubble even further because
  // the inline content has no break opportunities. `min(720px, 100%)` keeps
  // bubbles readable in the fullscreen chat while still respecting the
  // tight 420px MiniChat popover width.
  max-width: min(720px, 100%);
  padding: 0.625rem 0.875rem;
  border-radius: 12px;
  position: relative;
  // Defense-in-depth against unbreakable strings (serialized config JSON,
  // base64 blobs, long URLs) that would otherwise grow the bubble past its
  // max-width because there are no spaces to wrap on. `overflow-wrap:
  // anywhere` lets the browser break inside any character when no other
  // break opportunity exists; `word-break: break-word` keeps the legacy
  // behavior as a fallback.
  overflow-wrap: anywhere;
  word-break: break-word;
  // Allow this bubble to be a real flex item that can shrink below its
  // intrinsic content width — without `min-width: 0` the long-token wrap
  // rules above don't kick in inside a flex row because the bubble is
  // pinned at its content's min-content width.
  min-width: 0;

  &--optimistic {
    opacity: 0.72;
  }

  &--user {
    background-color: $primary;
    color: #fff;
    border-bottom-right-radius: 4px;
  }

  &--assistant {
    background-color: $surface-100;
    color: $surface-900;
    border-bottom-left-radius: 4px;
  }

  &--system {
    background-color: $surface-200;
    color: $surface-700;
    border-bottom-left-radius: 4px;
    border-left: 3px solid $orange-500;
  }
}

.bubble-content {
  margin: 0 0 0.25rem;
  white-space: pre-wrap;
  word-break: break-word;
  // Same defense as on `.bubble`: a user pasting a 4KB JSON blob into the
  // input lands here as a plain `<p>` with `white-space: pre-wrap`. Without
  // `overflow-wrap: anywhere` the unbreakable token would overflow the
  // bubble even though wrapping is enabled — `break-word` falls back to
  // breaking at word boundaries first, which the JSON has none of.
  overflow-wrap: anywhere;
  // Hard cap so a non-wrapping descendant (rare in plain text but possible
  // via copy-paste of pre-formatted content) cannot push the paragraph wider
  // than its parent bubble.
  max-width: 100%;
  line-height: 1.5;
}

.bubble-content--markdown {
  white-space: normal;

  :deep(.chat-md-p) {
    margin: 0 0 0.5rem;
    white-space: pre-wrap;
    word-break: break-word;
    line-height: 1.5;

    &:last-child {
      margin-bottom: 0;
    }
  }

  :deep(.chat-md-h1),
  :deep(.chat-md-h2),
  :deep(.chat-md-h3) {
    margin: 0.5rem 0 0.375rem;
    line-height: 1.3;
    font-weight: 600;

    &:first-child {
      margin-top: 0;
    }
  }

  :deep(.chat-md-h1) { font-size: 1.15rem; }
  :deep(.chat-md-h2) { font-size: 1.05rem; }
  :deep(.chat-md-h3) { font-size: 0.98rem; }

  :deep(.chat-md-list) {
    margin: 0.25rem 0 0.5rem 1.25rem;
    padding: 0;

    li {
      margin-bottom: 0.125rem;
      line-height: 1.45;
    }

    &:last-child {
      margin-bottom: 0;
    }
  }

  :deep(.chat-md-pre) {
    margin: 0.375rem 0;
    padding: 0.5rem 0.625rem;
    background: rgba(15, 23, 42, 0.06);
    border-radius: 6px;
    overflow-x: auto;
    font-size: 0.8rem;
    line-height: 1.4;

    code {
      background: transparent;
      padding: 0;
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }
  }

  :deep(code) {
    background: rgba(15, 23, 42, 0.08);
    padding: 0.05rem 0.3rem;
    border-radius: 4px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.88em;
    // Inline `<code>` defaults to no wrapping for tokens without spaces
    // (e.g. JSON paths, IDs, long property names). Force wrap so a single
    // backticked blob can't stretch the bubble past its max-width.
    overflow-wrap: anywhere;
    word-break: break-word;
  }

  :deep(.chat-md-table) {
    // Markdown tables can return many columns or long string cells (e.g. AI
    // listing report rows). Default `display: table` lets the table grow past
    // the bubble's max-width because table-layout is `auto` and cells expand
    // to fit. `display: block` turns the table into a regular block-level
    // element so `max-width: 100%` is honored and `overflow-x: auto`
    // produces an inline horizontal scrollbar inside the bubble instead of
    // pushing the bubble (and the surrounding MiniChat popover) wider.
    display: block;
    max-width: 100%;
    overflow-x: auto;
    border-collapse: collapse;
    margin: 0.375rem 0;
    font-size: 0.88rem;

    th,
    td {
      padding: 0.3rem 0.5rem;
      border: 1px solid $surface-300;
      text-align: left;
      vertical-align: top;
      // Long cell content stays on one line — combined with the table's
      // overflow-x scroll, this gives a predictable horizontal scroll UX
      // (instead of cells wrapping into multi-line stacks and inflating row
      // height to unreadable extents on narrow widths).
      white-space: nowrap;
    }

    th {
      background: $surface-200;
      font-weight: 600;
    }
  }

  // Wide <pre> blocks (code samples) also push the bubble — the existing
  // `.chat-md-pre` already has `overflow-x: auto`, but its block default
  // grows to min-content. Cap to the bubble width so the scrollbar appears
  // inside the bubble instead of stretching the popover. Pair with
  // `white-space: pre-wrap` so a serialized JSON blob (one long line with
  // no spaces) breaks across rows inside the <pre> instead of producing a
  // scroll bar wider than the chat area.
  :deep(.chat-md-pre) {
    max-width: 100%;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;

    code {
      // <code> inside <pre> defaults to display:inline + no wrapping —
      // inherit the pre-wrap from the parent so the JSON wraps even when
      // wrapped by markdown-it as `<pre><code>...</code></pre>`.
      white-space: inherit;
      overflow-wrap: inherit;
      word-break: inherit;
    }
  }

  :deep(a) {
    color: $primary;
    text-decoration: underline;

    &:hover {
      text-decoration: none;
    }
  }
}

.bubble-caret-row {
  display: flex;
  margin-top: -0.25rem;
  margin-bottom: 0.25rem;
  height: 1em;
  align-items: center;
}

.bubble-caret {
  display: inline-block;
  width: 2px;
  height: 1em;
  background-color: $surface-700;
  animation: bubble-caret-blink 1s steps(2, start) infinite;
}

@keyframes bubble-caret-blink {
  to {
    visibility: hidden;
  }
}

.bubble-cta {
  margin-top: 0.5rem;
  display: flex;
}

.bubble-error {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin-top: 0.25rem;
  padding: 0.5rem 0.625rem;
  border-radius: 8px;
  background: rgba(239, 68, 68, 0.08);
  border: 1px solid $red-200;
  color: $red-700;
  font-size: 0.85rem;
  line-height: 1.45;
}

.bubble-error__icon {
  margin-top: 0.1rem;
  color: $red-500;
  flex-shrink: 0;
}

.bubble-error__text {
  word-break: break-word;
  overflow-wrap: anywhere;
}

// Typing indicator embedded inside an assistant bubble while its content is null
// (pending / running). Smaller padding than the standalone send-time indicator
// because the bubble already provides padding.
.bubble-typing {
  padding: 0;
  margin: 0;
}

.bubble-time {
  font-size: 0.7rem;
  opacity: 0.65;
  display: block;
  text-align: right;
}
</style>
