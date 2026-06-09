import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Global state for the widget-generation AI modal — mirror of
 * `reportGenerationModal`. The modal is mounted once at the layout level
 * (always present, `isOpen=false` by default) and driven entirely through this
 * store so any surface (the widget library, a dashboard card's "edit", an
 * action-marker CTA) can pop it open without prop-drilling.
 *
 * Two modes:
 *  - `'create'` — fresh `widget_generation` session. The chat is NOT created in
 *    the DB until the first send (lazy creation via `sendInline`).
 *  - `'edit'` — resume an existing widget's `widget_generation` chat. The chat
 *    already exists (`chatId`), so we load it instead of lazy-creating. When the
 *    widget has no pinned chat (older widget), edit falls back to preview-state
 *    and the first send lazy-creates a chat bound to `widgetId`.
 *
 * `widgetUpdatedTick` / `lastUpdatedWidgetId` form a one-way "widget was
 * created/updated, refetch it" signal. Dashboards / the library watch the tick
 * and refetch the affected widget's data without a full reload.
 *
 * `dashboardId` (optional) — when the modal is opened from a dashboard, this is
 * the dashboard the user came from. The "Add to dashboard" CTA after a
 * successful create attaches the freshly-built widget to it.
 */
export type WidgetGenerationModalMode = 'create' | 'edit'

export interface OpenWidgetGenerationModalOptions {
  mode: WidgetGenerationModalMode
  /** Edit-mode: the widget being edited. Ignored in create-mode. */
  widgetId?: number | null
  /** Edit-mode: the widget's `widget_generation` chat id to resume. */
  chatId?: number | null
  /**
   * Dashboard the modal was opened from. Drives the "Add to dashboard" CTA in
   * create-mode. `null` when opened from a context without a dashboard
   * (e.g. an ai-chat action-marker).
   */
  dashboardId?: number | null
  /** Optional text to pre-populate the input without auto-sending. */
  prefillPrompt?: string | null
}

export const useWidgetGenerationModalStore = defineStore('widgetGenerationModal', () => {
  const isOpen = ref(false)
  const mode = ref<WidgetGenerationModalMode>('create')
  const widgetId = ref<number | null>(null)
  const chatId = ref<number | null>(null)
  const dashboardId = ref<number | null>(null)
  const prefillPrompt = ref<string | null>(null)

  /**
   * Monotone counter bumped every time the AI turn settles having
   * created/updated a widget. Consumers `watch` it (not `lastUpdatedWidgetId`,
   * which can repeat for back-to-back updates of the same widget).
   */
  const widgetUpdatedTick = ref(0)
  const lastUpdatedWidgetId = ref<number | null>(null)

  function open(opts: OpenWidgetGenerationModalOptions): void {
    mode.value = opts.mode
    widgetId.value = opts.widgetId ?? null
    chatId.value = opts.chatId ?? null
    dashboardId.value = opts.dashboardId ?? null
    prefillPrompt.value = opts.prefillPrompt ?? null
    isOpen.value = true
  }

  function close(): void {
    isOpen.value = false
  }

  /** Called by the modal once `@hide` has fully settled — resets transient opts. */
  function resetOptions(): void {
    mode.value = 'create'
    widgetId.value = null
    chatId.value = null
    dashboardId.value = null
    prefillPrompt.value = null
  }

  function signalWidgetUpdated(id: number): void {
    lastUpdatedWidgetId.value = id
    widgetUpdatedTick.value += 1
  }

  return {
    isOpen,
    mode,
    widgetId,
    chatId,
    dashboardId,
    prefillPrompt,
    widgetUpdatedTick,
    lastUpdatedWidgetId,
    open,
    close,
    resetOptions,
    signalWidgetUpdated,
  }
})
