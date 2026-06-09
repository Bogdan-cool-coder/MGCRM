import { defineStore } from 'pinia'
import type { ReportConfig, ReportFiltersApplied } from '@/entities/report'

/**
 * Snapshot of the currently-open report page, exposed to surfaces outside the
 * page itself (chiefly the Toolbox mini-chat widget). Living in Pinia keeps the
 * contract one-way (page writes, observers read) without prop-drilling through
 * the layout.
 *
 * `title` is the already-localized string for the user's active locale —
 * resolving it once on the writer side keeps the mini-chat consumer locale-agnostic.
 */
export interface ReportContextSnapshot {
  reportId: number
  title: string | null
  config: ReportConfig | null
  filtersApplied: ReportFiltersApplied | null
}

interface ReportContextState {
  reportId: number | null
  title: string | null
  config: ReportConfig | null
  filtersApplied: ReportFiltersApplied | null
}

export const useReportContextStore = defineStore('reportContext', {
  state: (): ReportContextState => ({
    reportId: null,
    title: null,
    config: null,
    filtersApplied: null,
  }),

  getters: {
    /**
     * True when the user is on a report page that has finished loading.
     * Consumers use this as a single "should I inject report context?" flag.
     */
    hasReportContext(): boolean {
      return this.reportId !== null
    },
  },

  actions: {
    set(snapshot: ReportContextSnapshot): void {
      this.reportId = snapshot.reportId
      this.title = snapshot.title
      this.config = snapshot.config
      this.filtersApplied = snapshot.filtersApplied
    },

    clear(): void {
      this.reportId = null
      this.title = null
      this.config = null
      this.filtersApplied = null
    },
  },
})
