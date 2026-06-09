import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Global state for the report-generation AI modal — the overlay that replaced
 * the now-removed standalone `/ai-reports` page. The modal is mounted once at the layout
 * level (always present, `visible=false` by default) and driven entirely
 * through this store so any surface (report list tiles, action-marker CTAs,
 * the report page edit button) can pop it open without prop-drilling.
 *
 * Two modes:
 *  - `'create'` — fresh report-generation session. The chat is NOT created in
 *    the DB until the first send (lazy creation via `sendInline`).
 *  - `'edit'` — resume an existing report's `report_generation` chat. The chat
 *    already exists (`chatId`), so we load it instead of lazy-creating.
 *
 * `reportUpdatedTick` / `lastUpdatedReportId` form a one-way "report was
 * generated/updated, refetch it" signal. The open report page watches the tick
 * and, when `lastUpdatedReportId` matches its own id, refetches its data so the
 * SPA reflects the AI's changes without a full reload.
 */
export type ReportGenerationModalMode = 'create' | 'edit'

export interface OpenReportGenerationModalOptions {
  mode: ReportGenerationModalMode
  /** Edit-mode: the report being edited. Ignored in create-mode. */
  reportId?: number | null
  /** Edit-mode: the report's `report_generation` chat id to resume. */
  chatId?: number | null
  /** Optional text to pre-populate the input without auto-sending. */
  prefillPrompt?: string | null
}

export const useReportGenerationModalStore = defineStore('reportGenerationModal', () => {
  const isOpen = ref(false)
  const mode = ref<ReportGenerationModalMode>('create')
  const reportId = ref<number | null>(null)
  const chatId = ref<number | null>(null)
  const prefillPrompt = ref<string | null>(null)

  /**
   * Monotone counter bumped every time the AI turn settles having
   * created/updated a report. Consumers `watch` it (not `lastUpdatedReportId`,
   * which can repeat for back-to-back updates of the same report) to react to
   * each settle individually.
   */
  const reportUpdatedTick = ref(0)
  const lastUpdatedReportId = ref<number | null>(null)

  function open(opts: OpenReportGenerationModalOptions): void {
    mode.value = opts.mode
    reportId.value = opts.reportId ?? null
    chatId.value = opts.chatId ?? null
    prefillPrompt.value = opts.prefillPrompt ?? null
    isOpen.value = true
  }

  /**
   * Hides the modal. Ids / prefill are intentionally left untouched here — the
   * modal component clears them on its own `@hide` (after `chat.reset()`), so a
   * close→reopen race can't read a half-reset state mid-transition.
   */
  function close(): void {
    isOpen.value = false
  }

  /** Called by the modal once `@hide` has fully settled — resets transient opts. */
  function resetOptions(): void {
    mode.value = 'create'
    reportId.value = null
    chatId.value = null
    prefillPrompt.value = null
  }

  function signalReportUpdated(id: number): void {
    lastUpdatedReportId.value = id
    reportUpdatedTick.value += 1
  }

  return {
    isOpen,
    mode,
    reportId,
    chatId,
    prefillPrompt,
    reportUpdatedTick,
    lastUpdatedReportId,
    open,
    close,
    resetOptions,
    signalReportUpdated,
  }
})
