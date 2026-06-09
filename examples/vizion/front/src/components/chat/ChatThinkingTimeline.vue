<template>
  <div
    v-if="visible"
    :class="['thinking-timeline', `thinking-timeline--${headerTone}`]"
  >
    <button
      :id="headerId"
      type="button"
      class="thinking-timeline__header"
      :aria-expanded="isExpanded"
      :aria-controls="bodyId"
      :aria-label="isExpanded ? t('thinkingTimeline.toggleClose') : t('thinkingTimeline.toggleOpen')"
      @click="toggle"
    >
      <span class="thinking-timeline__header-icon">
        <i v-if="isWorking" class="pi pi-spin pi-spinner" />
        <i v-else-if="isError" class="pi pi-times-circle" />
        <i v-else class="pi pi-check-circle" />
      </span>
      <span class="thinking-timeline__header-label">{{ headerLabel }}</span>
      <i
        class="thinking-timeline__caret pi pi-chevron-down"
        :class="{ 'thinking-timeline__caret--open': isExpanded }"
        aria-hidden="true"
      />
    </button>

    <div
      v-show="isExpanded"
      :id="bodyId"
      ref="bodyRef"
      class="thinking-timeline__body"
      role="region"
      :aria-label="t('thinkingTimeline.regionLabel')"
      :aria-labelledby="headerId"
    >
      <ul v-if="timelineItems.length > 0" class="thinking-timeline__list">
        <li
          v-for="item in timelineItems"
          :key="item.key"
          :class="[
            'thinking-timeline__item',
            `thinking-timeline__item--${item.state}`,
            { 'thinking-timeline__item--indented': item.indented },
          ]"
        >
          <span class="thinking-timeline__item-icon">
            <i v-if="item.state === 'running'" class="pi pi-spin pi-spinner" />
            <i v-else-if="item.state === 'error'" class="pi pi-times-circle" />
            <i v-else :class="['pi', item.icon]" />
          </span>
          <span class="thinking-timeline__item-body">
            <span class="thinking-timeline__item-label">{{ item.label }}</span>
            <span
              v-if="item.argsLabel"
              class="thinking-timeline__item-args"
            >{{ item.argsLabel }}</span>
            <span
              v-if="item.detail"
              class="thinking-timeline__item-detail"
            >{{ item.detail }}</span>
            <span
              v-if="item.errorMessage"
              class="thinking-timeline__item-error"
            >{{ t('thinkingTimeline.errors.toolFailed', { error: item.errorMessage }) }}</span>
          </span>
        </li>
      </ul>

      <details
        v-if="thinkingContent && thinkingContent.length > 0"
        class="thinking-timeline__thinking"
      >
        <summary class="thinking-timeline__thinking-summary">
          {{ t('thinkingTimeline.thinkingDetails') }}
        </summary>
        <pre class="thinking-timeline__thinking-body">{{ thinkingContent }}</pre>
      </details>

      <!-- Interim preamble: the model's `kind='content'` deltas streamed BEFORE
           a tool call / final answer. Rendered here (inside the progress block)
           rather than in the message body so it doesn't "spill" into the bubble
           while the turn is still running. Only shown while working — once the
           turn settles the canonical answer lives in the body. -->
      <div
        v-if="isWorking && interimContent"
        class="thinking-timeline__interim"
      >
        <span class="thinking-timeline__interim-label">{{ t('thinkingTimeline.interimLabel') }}</span>
        <p class="thinking-timeline__interim-body">{{ interimContent }}</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import { useLocalI18n } from '@/composables/useLocalI18n'
import type {
  ChatMessageEventDto,
  ChatMessageEventType,
} from '@/api/types/chats'
import type { ChatMessageStatus } from '@/entities/chat'
import en from './locale/en.json'
import ru from './locale/ru.json'

interface Props {
  events: ChatMessageEventDto[] | undefined
  status: ChatMessageStatus | undefined
  thinkingContent?: string | null
  /**
   * Live accumulator of the model's interim `kind='content'` deltas — the
   * preamble it emits BEFORE a tool call or the final answer. Rendered inside
   * the progress block (not the message body) while the turn is in flight.
   */
  streamingContent?: string | null
  /**
   * Optional explicit start/finish timestamps from the backend. When omitted,
   * the component derives the turn duration from the first/last event in
   * `events` so the "Думал N секунд" header still works during a live SSE
   * stream (before backend `started_at`/`finished_at` get round-tripped).
   */
  startedAt?: string | null
  finishedAt?: string | null
}

const props = defineProps<Props>()
const { t, te } = useLocalI18n({ en, ru })

const events = computed<ChatMessageEventDto[]>(() => props.events ?? [])

// Stable a11y ids — generated once per instance.
const headerId = useId()
const bodyId = useId()

const isWorking = computed(
  () => props.status === 'pending' || props.status === 'running',
)
const isError = computed(() => props.status === 'error')

const visible = computed(() => {
  // Show the timeline once any event has arrived OR while the turn is active
  // (so the user immediately sees "Connecting..." even before the first event).
  return events.value.length > 0 || isWorking.value
})

const interimContent = computed(() => {
  const raw = props.streamingContent
  return typeof raw === 'string' && raw.length > 0 ? raw : null
})

const headerTone = computed<'working' | 'done' | 'error'>(() => {
  if (isWorking.value) return 'working'
  if (isError.value) return 'error'
  return 'done'
})

/**
 * Duration of the turn in milliseconds, or `null` while still pending or when
 * we lack any timing data. Prefers backend-stamped `started_at`/`finished_at`
 * (canonical, immune to client clock drift); falls back to the first/last
 * event timestamps so the header label works during a live stream and for
 * legacy messages that never persisted those fields.
 */
const durationMs = computed<number | null>(() => {
  if (isWorking.value) return null

  const startIso =
    props.startedAt ?? events.value[0]?.created_at ?? null
  const endIso =
    props.finishedAt ?? events.value[events.value.length - 1]?.created_at ?? null
  if (!startIso || !endIso) return null

  const start = Date.parse(startIso)
  const end = Date.parse(endIso)
  if (Number.isNaN(start) || Number.isNaN(end)) return null
  const diff = end - start
  return diff >= 0 ? diff : 0
})

/**
 * Localized "Думал N секунд" / "Thought for N minutes" once the turn settles.
 * Returns `null` for in-flight turns (header falls back to "Думаю…").
 */
const durationLabel = computed<string | null>(() => {
  const ms = durationMs.value
  if (ms == null) return null
  if (ms < 1000) return t('thinkingTimeline.headerThoughtLessThanSecond')
  if (ms < 60_000) {
    const seconds = Math.round(ms / 1000)
    return t('thinkingTimeline.headerThoughtSeconds', seconds, {
      named: { count: seconds },
    })
  }
  const minutes = Math.round(ms / 60_000)
  return t('thinkingTimeline.headerThoughtMinutes', minutes, {
    named: { count: minutes },
  })
})

const headerLabel = computed<string>(() => {
  if (isWorking.value) {
    // Switch verbiage when AI is actively in a tool call so the user has a
    // clearer cue than a generic "Thinking...".
    const last = [...events.value].reverse().find((e) => e.type === 'tool_call')
    const lastResult = [...events.value].reverse().find((e) => e.type === 'tool_result')
    const inFlightTool =
      last && (!lastResult || lastResult.sequence < last.sequence)
    if (inFlightTool) return t('thinkingTimeline.headerUsingTools')
    return t('thinkingTimeline.headerThinking')
  }
  if (isError.value) {
    // Show duration even on error if we have it — "Думал 5 секунд (ошибка)".
    return durationLabel.value
      ? `${durationLabel.value} · ${t('thinkingTimeline.headerError')}`
      : t('thinkingTimeline.headerError')
  }
  return durationLabel.value ?? t('thinkingTimeline.headerDone')
})

// Default: expanded while running, collapsed once settled. Watch flips it on
// transition so the user can still toggle manually after settle.
const isExpanded = ref(isWorking.value)
watch(
  () => props.status,
  (next) => {
    if (next === 'done' || next === 'error' || next === 'cancelled') {
      isExpanded.value = false
    } else if (next === 'pending' || next === 'running') {
      isExpanded.value = true
    }
  },
)

const toggle = () => {
  isExpanded.value = !isExpanded.value
}

// Autoscroll inside the body to the latest event while the turn is running.
// Stops once settled — the user can scroll freely through the completed log.
const bodyRef = ref<HTMLElement | null>(null)
watch(
  // Track both step count and interim-content length so the latest streamed
  // preamble line stays in view while the turn runs.
  () => [events.value.length, interimContent.value?.length ?? 0],
  async () => {
    if (!isWorking.value || !isExpanded.value) return
    await nextTick()
    const el = bodyRef.value
    if (el) {
      el.scrollTop = el.scrollHeight
    }
  },
)

interface TimelineItem {
  key: string
  label: string
  argsLabel?: string
  detail?: string
  errorMessage?: string
  icon: string
  state: 'running' | 'done' | 'error'
  /**
   * True when this row visually nests under the previously rendered tool-card
   * (currently used for `dry_run_*` and `retry` rows that fall between a
   * `create_report`/`update_report` `tool_call` and its matching `tool_result`).
   * Rendered with a left indent and a vertical connector so the user can see
   * the sub-step belongs to the tool above it.
   */
  indented?: boolean
}

/**
 * Tool names whose dry_run / retry sub-steps should visually nest under the
 * tool's card while the call is in-flight. Restricted to write tools, since
 * those are the ones with dry-run + semantic-retry around them.
 */
const TOOLS_WITH_NESTED_SUBSTEPS = new Set([
  'create_report',
  'update_report',
  'create_widget',
  'update_widget',
])

/**
 * Looks up a localized human-readable name for a tool. Falls back to the raw
 * identifier so future backend tools degrade gracefully without a 404 in the
 * UI.
 */
const toolLabel = (toolName: string): string => {
  const key = `thinkingTimeline.tools.${toolName}`
  return te(key) ? t(key) : toolName
}

const toolIcon = (toolName: string): string => {
  switch (toolName) {
    case 'probe_data':
      return 'pi-search'
    case 'query_data':
      return 'pi-database'
    case 'create_report':
      return 'pi-chart-bar'
    case 'update_report':
      return 'pi-pencil'
    case 'create_widget':
      return 'pi-chart-pie'
    case 'update_widget':
      return 'pi-pencil'
    default:
      return 'pi-cog'
  }
}

/**
 * Type guard for the inner `arguments` object on a `tool_call` payload.
 * Backend contract: `{ tool: string, arguments: object }`. We accept missing /
 * malformed arguments and degrade to an empty record.
 */
const readArgs = (payload: Record<string, unknown> | undefined): Record<string, unknown> => {
  const args = payload?.arguments
  if (typeof args === 'object' && args !== null) {
    return args as Record<string, unknown>
  }
  return {}
}

/**
 * Build a short, one-line human summary of a tool_call's arguments — feeds
 * the second line under the tool name on the timeline card. Designed to be
 * dense and skimmable; never echoes raw user data verbatim (clamps long
 * strings server-side already, but we don't rely on that here).
 */
const buildArgsLabel = (toolName: string, payload: Record<string, unknown> | undefined): string | undefined => {
  const args = readArgs(payload)

  switch (toolName) {
    case 'probe_data': {
      const model = typeof args.model === 'string' ? args.model : null
      if (!model) return undefined
      const fields = Array.isArray(args.fields) ? args.fields.filter((f): f is string => typeof f === 'string') : []
      if (fields.length === 0) return model
      if (fields.length <= 3) {
        return t('thinkingTimeline.args.modelWithFieldsList', { model, fields: fields.join(', ') })
      }
      return t('thinkingTimeline.args.modelWithFieldsCount', fields.length, {
        named: { model, count: fields.length },
      })
    }
    case 'query_data': {
      const model = typeof args.model === 'string' ? args.model : null
      const aggregate = typeof args.aggregate === 'string' ? args.aggregate : null
      if (!model || !aggregate) return undefined
      const filtersCount =
        typeof args.filters_count === 'number' ? args.filters_count : null
      const groupBy = typeof args.group_by === 'string' ? args.group_by : null

      let base: string
      if (filtersCount && filtersCount > 0) {
        base = t('thinkingTimeline.args.queryAggregateFilters', filtersCount, {
          named: { model, aggregate, count: filtersCount },
        })
      } else {
        base = t('thinkingTimeline.args.queryAggregateNoFilters', { model, aggregate })
      }
      if (groupBy) {
        return `${base} · ${t('thinkingTimeline.args.queryGroupedBy', { field: groupBy })}`
      }
      return base
    }
    case 'create_report': {
      const title = typeof args.title === 'string' ? args.title : null
      const model = typeof args.primary_model === 'string' ? args.primary_model : null
      const columnsCount = typeof args.columns_count === 'number' ? args.columns_count : null
      const widgetsCount = typeof args.widgets_count === 'number' ? args.widgets_count : null
      if (!title || !model) return title ?? model ?? undefined

      if (widgetsCount && widgetsCount > 0 && columnsCount && columnsCount > 0) {
        const columnsLabel = t('thinkingTimeline.args.columnsPlural', columnsCount, { named: { count: columnsCount } })
        const widgetsLabel = t('thinkingTimeline.args.widgetsPlural', widgetsCount, { named: { count: widgetsCount } })
        return t('thinkingTimeline.args.createReportArgsColumnsWidgets', {
          title,
          model,
          columnsLabel,
          widgetsLabel,
        })
      }
      if (columnsCount && columnsCount > 0) {
        return t('thinkingTimeline.args.createReportArgsColumns', columnsCount, {
          named: { title, model, columns: columnsCount },
        })
      }
      return t('thinkingTimeline.args.createReportArgs', { title, model })
    }
    case 'update_report': {
      const reportId = typeof args.report_id === 'number' ? args.report_id : null
      const model = typeof args.primary_model === 'string' ? args.primary_model : null
      const columnsCount = typeof args.columns_count === 'number' ? args.columns_count : null
      if (reportId == null) return undefined

      const modelLabel = model ?? '—'
      if (columnsCount && columnsCount > 0) {
        return t('thinkingTimeline.args.updateReportArgsColumns', columnsCount, {
          named: { reportId, model: modelLabel, columns: columnsCount },
        })
      }
      return t('thinkingTimeline.args.updateReportArgs', { reportId, model: modelLabel })
    }
    case 'create_widget': {
      const title =
        typeof args.name === 'string'
          ? args.name
          : typeof args.title === 'string'
            ? args.title
            : null
      const model = typeof args.primary_model === 'string' ? args.primary_model : null
      const chartType = typeof args.chart_type === 'string' ? args.chart_type : null
      if (!title || !model) return title ?? model ?? undefined
      return t('thinkingTimeline.args.createWidgetArgs', {
        title,
        model,
        chartType: chartType ?? '—',
      })
    }
    case 'update_widget': {
      const widgetId = typeof args.widget_id === 'number' ? args.widget_id : null
      const model = typeof args.primary_model === 'string' ? args.primary_model : null
      const chartType = typeof args.chart_type === 'string' ? args.chart_type : null
      if (widgetId == null) return undefined
      return t('thinkingTimeline.args.updateWidgetArgs', {
        widgetId,
        model: model ?? '—',
        chartType: chartType ?? '—',
      })
    }
    default:
      return undefined
  }
}

/**
 * Best-effort extraction of a short human-readable detail from a tool_result
 * payload. Returns `undefined` for arbitrary / unknown shapes — never throws.
 *
 * Backend contract (success path, flat keys on the payload):
 *   probe_data    → { rows_count, total_count, fields_count }
 *   query_data    → { aggregate_value } | { group_rows_count }
 *   create_report → { report_id }
 *   update_report → { report_id }
 */
const extractToolResultDetail = (
  toolName: string,
  payload: Record<string, unknown> | undefined,
): string | undefined => {
  if (!payload) return undefined

  switch (toolName) {
    case 'probe_data': {
      const rows = typeof payload.rows_count === 'number' ? payload.rows_count : null
      const total = typeof payload.total_count === 'number' ? payload.total_count : null
      const fields = typeof payload.fields_count === 'number' ? payload.fields_count : null
      if (rows == null && total == null && fields == null) return undefined
      if (total != null && fields != null && rows != null) {
        return t('thinkingTimeline.details.probeSummary', fields, {
          named: { rows, total, fields },
        })
      }
      if (rows != null && fields != null) {
        return t('thinkingTimeline.details.probeNoTotal', fields, {
          named: { rows, fields },
        })
      }
      if (rows != null) {
        return t('thinkingTimeline.details.rowCount', rows, { named: { count: rows } })
      }
      return undefined
    }
    case 'query_data': {
      if (typeof payload.aggregate_value === 'number' || typeof payload.aggregate_value === 'string') {
        const value = formatAggregateValue(payload.aggregate_value)
        return t('thinkingTimeline.details.aggregateValue', { value })
      }
      if (typeof payload.group_rows_count === 'number') {
        return t('thinkingTimeline.details.groupRowsCount', payload.group_rows_count, {
          named: { count: payload.group_rows_count },
        })
      }
      return undefined
    }
    case 'create_report': {
      if (typeof payload.report_id === 'number') {
        return t('thinkingTimeline.details.reportCreated', { id: payload.report_id })
      }
      return undefined
    }
    case 'update_report': {
      if (typeof payload.report_id === 'number') {
        return t('thinkingTimeline.details.reportUpdated', { id: payload.report_id })
      }
      return undefined
    }
    case 'create_widget': {
      if (typeof payload.widget_id === 'number') {
        return t('thinkingTimeline.details.widgetCreated', { id: payload.widget_id })
      }
      return undefined
    }
    case 'update_widget': {
      if (typeof payload.widget_id === 'number') {
        return t('thinkingTimeline.details.widgetUpdated', { id: payload.widget_id })
      }
      return undefined
    }
    default:
      return undefined
  }
}

/**
 * Light-weight number formatter for the `query_data` aggregate value. Uses the
 * active i18n locale for thousands separators so RU and EN both look natural.
 * Falls back to the raw value if the input isn't finite (covers string-typed
 * aggregates from some providers).
 */
const formatAggregateValue = (raw: number | string): string => {
  const num = typeof raw === 'number' ? raw : Number(raw)
  if (!Number.isFinite(num)) return String(raw)
  // Locale-aware grouping; `useLocalI18n` keeps the inherited locale in sync
  // with the active app locale, so this matches the rest of the UI.
  try {
    return new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(num)
  } catch {
    return String(num)
  }
}

/**
 * Collapses paired `tool_call` / `tool_result` events into a single timeline
 * row (running while the result is pending, done once the result arrives).
 * Non-paired meta events render as standalone rows. `dry_run_*` / `retry`
 * that happen between an active write tool's call and its result are marked
 * `indented: true` so the template can render them as visual sub-steps of
 * the tool above them.
 */
const timelineItems = computed<TimelineItem[]>(() => {
  const items: TimelineItem[] = []
  // FIFO queue of pending tool_calls per tool name. We pop from the front when
  // a matching tool_result arrives. Multiple in-flight calls of the same tool
  // would resolve in dispatch order — the backend currently serialises tool
  // calls per turn, but the queue keeps us correct if that ever changes.
  const pendingByTool = new Map<string, number[]>()
  // Tracks whether the most recently-pushed tool_call slot is for a write
  // tool that's still in-flight. When true, dry_run / retry events that come
  // right after are rendered as indented sub-steps under that card.
  let activeWriteToolIdx: number | null = null

  const pushPending = (tool: string, idx: number) => {
    const queue = pendingByTool.get(tool) ?? []
    queue.push(idx)
    pendingByTool.set(tool, queue)
  }

  const popPending = (tool: string): number | undefined => {
    const queue = pendingByTool.get(tool)
    if (!queue || queue.length === 0) return undefined
    const idx = queue.shift()
    if (queue.length === 0) pendingByTool.delete(tool)
    return idx
  }

  for (const ev of events.value) {
    const type = ev.type as ChatMessageEventType | string
    const payload = (ev.payload ?? {}) as Record<string, unknown>

    switch (type) {
      case 'started': {
        items.push({
          key: `s-${ev.sequence}`,
          label: t('thinkingTimeline.steps.started'),
          icon: 'pi-bolt',
          state: 'done',
        })
        break
      }
      case 'thinking': {
        // Backend emits structured `thinking` events with a `stage` discriminator.
        // We only surface a row for `connecting` (visible signal to the user
        // that we've handed off to the LLM provider). Other stages stay silent
        // — they would be noisy and the tool_call rows already convey progress.
        if (payload.stage === 'connecting') {
          items.push({
            key: `th-${ev.sequence}`,
            label: t('thinkingTimeline.steps.connecting'),
            icon: 'pi-cloud',
            state: 'done',
          })
        }
        break
      }
      case 'tool_call': {
        const name = typeof payload.tool === 'string' ? payload.tool : 'tool'
        const argsLabel = buildArgsLabel(name, payload)
        pushPending(name, items.length)
        items.push({
          key: `tc-${ev.sequence}`,
          label: toolLabel(name),
          argsLabel,
          icon: toolIcon(name),
          state: 'running',
        })
        if (TOOLS_WITH_NESTED_SUBSTEPS.has(name)) {
          activeWriteToolIdx = items.length - 1
        }
        break
      }
      case 'tool_result': {
        const name = typeof payload.tool === 'string' ? payload.tool : 'tool'
        const success = payload.success === true
        const idx = popPending(name)
        const detail = extractToolResultDetail(name, payload)
        const errorMessage =
          !success && typeof payload.error === 'string' && payload.error !== ''
            ? payload.error
            : undefined

        if (idx != null && items[idx]) {
          const existing = items[idx]!
          items[idx] = {
            ...existing,
            state: success ? 'done' : 'error',
            detail: detail ?? existing.detail,
            errorMessage,
          }
        } else {
          // Orphan result (resume / reconnect could deliver result without
          // having replayed the call). Render as a standalone row.
          items.push({
            key: `tr-${ev.sequence}`,
            label: toolLabel(name),
            icon: toolIcon(name),
            state: success ? 'done' : 'error',
            detail,
            errorMessage,
          })
        }

        // Close out the active write-tool nesting once its result lands. dry_run
        // events for the next tool call will start a fresh nesting block.
        if (TOOLS_WITH_NESTED_SUBSTEPS.has(name)) {
          activeWriteToolIdx = null
        }
        break
      }
      case 'dry_run_start': {
        items.push({
          key: `drs-${ev.sequence}`,
          label: t('thinkingTimeline.steps.dryRunStart'),
          icon: 'pi-shield',
          state: 'running',
          indented: activeWriteToolIdx !== null,
        })
        break
      }
      case 'dry_run_result': {
        const success = payload.success === true
        // Mark the last running dry_run_start as terminal if present.
        for (let i = items.length - 1; i >= 0; i -= 1) {
          if (items[i]?.label === t('thinkingTimeline.steps.dryRunStart')) {
            const errorMessage =
              !success && typeof payload.error === 'string' && payload.error !== ''
                ? payload.error
                : undefined
            items[i] = {
              ...items[i]!,
              state: success ? 'done' : 'error',
              label: success
                ? t('thinkingTimeline.steps.dryRunOk')
                : t('thinkingTimeline.steps.dryRunFailed'),
              errorMessage,
            }
            break
          }
        }
        break
      }
      case 'retry': {
        items.push({
          key: `r-${ev.sequence}`,
          label: t('thinkingTimeline.steps.retry'),
          icon: 'pi-refresh',
          state: 'done',
          indented: activeWriteToolIdx !== null,
        })
        break
      }
      case 'final_message': {
        items.push({
          key: `f-${ev.sequence}`,
          label: t('thinkingTimeline.steps.finalMessage'),
          icon: 'pi-flag',
          state: 'done',
        })
        break
      }
      default:
        // Unknown / out-of-band events are intentionally dropped — keeps the
        // timeline tight and tolerates forward-compat additions on the backend.
        break
    }
  }

  return items
})

const thinkingContent = computed(() => props.thinkingContent ?? null)
</script>

<style lang="scss" scoped>
.thinking-timeline {
  border: 1px solid $surface-200;
  border-radius: 8px;
  background: $surface-50;
  margin: 0 0 0.5rem;
  overflow: hidden;
  font-size: 0.85rem;

  &--working {
    border-color: $primary-color;
    background: rgba(59, 130, 246, 0.04);
  }

  &--error {
    border-color: $red-200;
    background: rgba(239, 68, 68, 0.05);
  }
}

.thinking-timeline__header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.4rem 0.625rem;
  background: transparent;
  border: 0;
  text-align: left;
  font-weight: 500;
  color: $surface-700;
  cursor: pointer;

  &:hover {
    background: rgba(15, 23, 42, 0.04);
  }

  &:focus-visible {
    outline: 2px solid $primary-color;
    outline-offset: -2px;
  }
}

.thinking-timeline__header-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1rem;
  color: $surface-500;

  .pi-times-circle {
    color: $red-500;
  }
  .pi-check-circle {
    color: $surface-500;
  }
  .pi-spinner {
    color: $primary;
  }
}

.thinking-timeline__header-label {
  flex: 1;
  font-size: 0.85rem;
  // Muted-gray header text (matches the "Думал 2 минуты" visual cue).
  color: $surface-700;
  font-weight: 500;
}

.thinking-timeline__caret {
  color: $surface-500;
  font-size: 0.7rem;
  transition: transform 0.15s ease;

  &--open {
    transform: rotate(180deg);
  }
}

.thinking-timeline__body {
  // Blockquote-style left rule + soft background — visually marks the body as
  // a sub-region (reasoning trace) distinct from the main bubble content.
  padding: 0.4rem 0.625rem 0.5rem 0.875rem;
  border-top: 1px solid $surface-200;
  border-left: 3px solid $surface-300;
  background: rgba(15, 23, 42, 0.02);
  // Cap the timeline height when many steps accumulate so the bubble stays
  // scannable; the autoscroll watcher keeps the latest step in view while
  // the turn is running.
  max-height: 320px;
  overflow-y: auto;
  // De-emphasise the entire reasoning block — final answer is the focus.
  opacity: 0.92;
}

.thinking-timeline__list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.thinking-timeline__item {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.2rem 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.78rem;
  line-height: 1.4;
  color: $surface-700;

  &--running {
    color: $primary;
  }

  &--error {
    color: $red-700;
  }

  // Indented sub-steps (dry_run / retry) under a write-tool card. Left padding
  // + a thin vertical connector visually links the row to the tool above.
  // We keep the icon column width consistent so the connector sits where the
  // parent's icon would be.
  &--indented {
    padding-left: 1.4rem;
    position: relative;

    &::before {
      content: '';
      position: absolute;
      left: 0.45rem;
      top: 0;
      bottom: 0;
      width: 2px;
      background: $surface-300;
      opacity: 0.6;
    }
  }
}

.thinking-timeline__item-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 0.9rem;
  flex-shrink: 0;
  margin-top: 0.1rem;

  // Inline error-state icon should pop in red even on the muted in-body row
  // colour (which is $red-700 — too dim for the times-circle glyph alone).
  .pi-times-circle {
    color: $red-500;
  }
}

.thinking-timeline__item-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.thinking-timeline__item-label {
  word-break: break-word;
}

.thinking-timeline__item-args {
  font-family: $font-family-sans;
  font-size: 0.74rem;
  color: $surface-600;
  margin-top: 0.05rem;
}

.thinking-timeline__item-detail {
  font-family: $font-family-sans;
  font-size: 0.74rem;
  color: $surface-500;
}

.thinking-timeline__item-error {
  font-family: $font-family-sans;
  font-size: 0.74rem;
  color: $red-700;
  margin-top: 0.05rem;
  word-break: break-word;
}

.thinking-timeline__thinking {
  margin-top: 0.5rem;
  border-top: 1px dashed $surface-200;
  padding-top: 0.375rem;
}

.thinking-timeline__thinking-summary {
  cursor: pointer;
  font-size: 0.78rem;
  color: $surface-600;
  list-style: none;
  user-select: none;

  &::-webkit-details-marker {
    display: none;
  }

  &::before {
    content: "▸";
    display: inline-block;
    margin-right: 0.25rem;
    transition: transform 0.15s ease;
  }
}

.thinking-timeline__thinking[open] .thinking-timeline__thinking-summary::before {
  transform: rotate(90deg);
}

.thinking-timeline__thinking-body {
  margin: 0.375rem 0 0;
  padding: 0.4rem 0.5rem;
  background: rgba(15, 23, 42, 0.04);
  border-radius: 4px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.78rem;
  line-height: 1.45;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 240px;
  overflow-y: auto;
}

// Interim preamble streamed before a tool call / final answer. Visually
// distinct from the step list (no monospace) but still de-emphasised — it's a
// transient "draft" that the final answer in the body will supersede.
.thinking-timeline__interim {
  margin-top: 0.5rem;
  border-top: 1px dashed $surface-200;
  padding-top: 0.4rem;
}

.thinking-timeline__interim-label {
  display: block;
  font-size: 0.72rem;
  color: $surface-500;
  margin-bottom: 0.2rem;
}

.thinking-timeline__interim-body {
  margin: 0;
  font-size: 0.82rem;
  line-height: 1.5;
  color: $surface-700;
  white-space: pre-wrap;
  word-break: break-word;
  overflow-wrap: anywhere;
}
</style>
