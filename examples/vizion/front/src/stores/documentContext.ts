import { defineStore } from 'pinia'
import type { DocumentTemplateType } from '@/entities/document'

/**
 * Snapshot of the currently-open document page, exposed to surfaces outside the
 * page itself (chiefly the Toolbox mini-chat widget in document scope). Mirror
 * of `useReportContextStore` / `useDashboardContextStore` — the page writes,
 * observers read, one-way, no prop-drilling.
 *
 * `title` is already-localized for the active locale. `mappedCount` /
 * `placeholderCount` are slim mapping-state hints so the mini-chat can describe
 * the open template's progress to the AI without shipping the full config.
 */
export interface DocumentContextSnapshot {
  documentId: number
  type: DocumentTemplateType
  title: string | null
  /** Number of `${...}` placeholders declared in the uploaded docx source. */
  placeholderCount: number
  /** Number of placeholders that already have a mapped field. */
  mappedCount: number
}

interface DocumentContextState {
  documentId: number | null
  type: DocumentTemplateType | null
  title: string | null
  placeholderCount: number
  mappedCount: number
}

export const useDocumentContextStore = defineStore('documentContext', {
  state: (): DocumentContextState => ({
    documentId: null,
    type: null,
    title: null,
    placeholderCount: 0,
    mappedCount: 0,
  }),

  getters: {
    /** True when the user is on a document page that has finished loading. */
    hasDocumentContext(): boolean {
      return this.documentId !== null
    },
  },

  actions: {
    set(snapshot: DocumentContextSnapshot): void {
      this.documentId = snapshot.documentId
      this.type = snapshot.type
      this.title = snapshot.title
      this.placeholderCount = snapshot.placeholderCount
      this.mappedCount = snapshot.mappedCount
    },

    clear(): void {
      this.documentId = null
      this.type = null
      this.title = null
      this.placeholderCount = 0
      this.mappedCount = 0
    },
  },
})
