import { defineStore } from 'pinia'
import type { WidgetConfigDto } from '@/api/types/widgets'

/**
 * Snapshot of the currently-open dashboard page, exposed to surfaces outside
 * the page itself (chiefly the Toolbox mini-chat widget in dashboard scope).
 * Mirror of `useReportContextStore` — page writes, observers read, one-way,
 * no prop-drilling.
 *
 * `title` is already-localized for the active locale. `widgets` is a slim
 * projection (id + localized name + primary_model + chart type) so the
 * mini-chat can describe the dashboard's contents to the AI without shipping
 * full widget configs.
 */
export interface DashboardContextWidget {
  id: number
  name: string
  primaryModel: string | null
  chartType: string | null
}

export interface DashboardContextSnapshot {
  dashboardId: number
  title: string | null
  widgets: DashboardContextWidget[]
}

interface DashboardContextState {
  dashboardId: number | null
  title: string | null
  widgets: DashboardContextWidget[]
}

const slimChartType = (config: WidgetConfigDto | null | undefined): string | null => {
  const type = config?.chart?.type
  return typeof type === 'string' ? type : null
}

const slimPrimaryModel = (config: WidgetConfigDto | null | undefined): string | null => {
  const model = config?.primary_model
  return typeof model === 'string' ? model : null
}

export const useDashboardContextStore = defineStore('dashboardContext', {
  state: (): DashboardContextState => ({
    dashboardId: null,
    title: null,
    widgets: [],
  }),

  getters: {
    /** True when the user is on a dashboard page that has finished loading. */
    hasDashboardContext(): boolean {
      return this.dashboardId !== null
    },
  },

  actions: {
    set(snapshot: DashboardContextSnapshot): void {
      this.dashboardId = snapshot.dashboardId
      this.title = snapshot.title
      this.widgets = snapshot.widgets
    },

    clear(): void {
      this.dashboardId = null
      this.title = null
      this.widgets = []
    },
  },
})

export { slimChartType, slimPrimaryModel }
