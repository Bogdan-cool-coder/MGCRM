import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Global state for the document-generation AI modal — mirror of
 * `reportGenerationModal` / `widgetGenerationModal`. The modal is mounted once
 * at the layout level (always present, `isOpen=false` by default) and driven
 * entirely through this store so any surface (the documents library, the create
 * dialog, an action-marker CTA) can pop it open without prop-drilling.
 *
 * Two modes (mirror of `reportGenerationModal`):
 *  - `'create'` — fresh HTML-КП generation. The user describes the proposal,
 *    the AI calls `generate_document_template`, a new HTML template is created
 *    and linked to the chat. The chat is NOT created in the DB until the first
 *    send (lazy creation via `sendInline` with `type='document_template'`,
 *    `scope_type='general'`).
 *  - `'edit'` — edit an existing template through the AI. The chat is bound to
 *    the open template via `scope_type='document'` + `document_id`; on open we
 *    resume the latest document-scoped chat (or lazy-create one on first send).
 *    The AI's `generate_document_template` updates the template in place
 *    (backend M7 supports update when `chat.document_id` is set).
 *
 * `documentUpdatedTick` / `lastUpdatedDocumentId` form a one-way "a document
 * template was generated/updated, react to it" signal. The modal navigates to
 * the new template on create; the documents library / open document page watch
 * the tick to refetch.
 */
export type DocumentGenerationModalMode = 'create' | 'edit'

export interface OpenDocumentGenerationModalOptions {
  /** Defaults to `'create'`. */
  mode?: DocumentGenerationModalMode
  /** Edit-mode: the template being edited (document-scoped chat target). */
  documentId?: number | null
  /** Optional text to pre-populate the input without auto-sending. */
  prefillPrompt?: string | null
}

export const useDocumentGenerationModalStore = defineStore('documentGenerationModal', () => {
  const isOpen = ref(false)
  const mode = ref<DocumentGenerationModalMode>('create')
  const documentId = ref<number | null>(null)
  const prefillPrompt = ref<string | null>(null)

  /**
   * Monotone counter bumped every time the AI turn settles having
   * created/updated a document template. Consumers `watch` it (not
   * `lastUpdatedDocumentId`, which can repeat for back-to-back updates of the
   * same template) to react to each settle individually.
   */
  const documentUpdatedTick = ref(0)
  const lastUpdatedDocumentId = ref<number | null>(null)

  function open(opts: OpenDocumentGenerationModalOptions = {}): void {
    mode.value = opts.mode ?? 'create'
    documentId.value = opts.documentId ?? null
    prefillPrompt.value = opts.prefillPrompt ?? null
    isOpen.value = true
  }

  /**
   * Hides the modal. `prefillPrompt` is intentionally left untouched here — the
   * modal component clears it on its own `@hide` (after `chat.reset()`), so a
   * close→reopen race can't read a half-reset state mid-transition.
   */
  function close(): void {
    isOpen.value = false
  }

  /** Called by the modal once `@hide` has fully settled — resets transient opts. */
  function resetOptions(): void {
    mode.value = 'create'
    documentId.value = null
    prefillPrompt.value = null
  }

  function signalDocumentUpdated(id: number): void {
    lastUpdatedDocumentId.value = id
    documentUpdatedTick.value += 1
  }

  return {
    isOpen,
    mode,
    documentId,
    prefillPrompt,
    documentUpdatedTick,
    lastUpdatedDocumentId,
    open,
    close,
    resetOptions,
    signalDocumentUpdated,
  }
})
