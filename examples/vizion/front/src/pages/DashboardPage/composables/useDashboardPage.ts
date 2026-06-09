import { computed, ref, watch, watchEffect, onUnmounted } from 'vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { getLocalizedText } from '@/utils/localization'
import { useWidgetGenerationModalStore } from '@/stores/widgetGenerationModal'
import {
  useDashboardContextStore,
  slimChartType,
  slimPrimaryModel,
} from '@/stores/dashboardContext'
import { useDashboardPageData } from './useDashboardPageData'
import { useDashboardPageActions } from './useDashboardPageActions'
import { useDashboardLayout } from './useDashboardLayout'

export const useDashboardPage = () => {
  const data = useDashboardPageData()
  const actions = useDashboardPageActions({
    dashboard: data.dashboard,
    reloadAll: data.reloadAll,
  })
  const { dashboardService } = useServices()
  const { notifyApiError } = useNotifications()
  const widgetModalStore = useWidgetGenerationModalStore()
  const dashboardContextStore = useDashboardContextStore()

  const layout = useDashboardLayout({
    dashboardId: data.dashboardId,
    editable: actions.isEditable,
  })

  // Re-seed the grid layout whenever the dashboard (and thus its pivots) loads.
  watch(
    () => data.dashboard.value,
    (dash) => {
      layout.rebuild(dash)
    },
    { immediate: true },
  )

  // ── Library modal (add widget) state ───────────────────────────────────
  const isLibraryOpen = ref(false)
  const openLibrary = () => {
    isLibraryOpen.value = true
  }
  const closeLibrary = () => {
    isLibraryOpen.value = false
  }

  /** Attach a widget reference from the library, then refetch. */
  const attachWidget = async (widgetId: number) => {
    const dash = data.dashboard.value
    if (!dash) return
    try {
      await dashboardService.attachWidget(dash.id, { widget_id: widgetId })
      await data.reloadAll()
    } catch (error) {
      notifyApiError(error, data.t('errors.attachFailed'), data.t('common.error'))
    }
  }

  /** Widget card visibility toggle → mutate layout + persist. */
  const toggleWidgetVisibility = (widgetId: number, visible: boolean) => {
    layout.setVisibility(widgetId, visible)
  }

  // After the widget-generation modal settles (create / edit), refetch so a
  // new/changed widget surfaces. Attaching from the modal's CTA also signals.
  watch(
    () => widgetModalStore.widgetUpdatedTick,
    () => {
      if (data.dashboardId.value > 0) void data.reloadAll()
    },
  )

  // ── Mini-chat dashboard context (mirror of ReportPage → reportContext) ──
  watchEffect(() => {
    const dash = data.dashboard.value
    if (!dash) {
      dashboardContextStore.clear()
      return
    }
    dashboardContextStore.set({
      dashboardId: dash.id,
      title: data.localizedTitle.value,
      widgets: dash.widgets.map((w) => ({
        id: w.id,
        name: getLocalizedText(w.name, data.locale.value),
        primaryModel: slimPrimaryModel(w.config),
        chartType: slimChartType(w.config),
      })),
    })
  })

  onUnmounted(() => {
    dashboardContextStore.clear()
  })

  // Chart data lookup by widget id (only visible widgets are returned by API).
  const widgetData = (widgetId: number) => data.data.value?.widgets[widgetId] ?? null

  const hasWidgets = computed(() => (data.dashboard.value?.widgets.length ?? 0) > 0)

  return {
    ...data,
    ...actions,
    // layout
    gridLayout: layout.layout,
    persistLayout: layout.persist,
    // library
    isLibraryOpen,
    openLibrary,
    closeLibrary,
    attachWidget,
    toggleWidgetVisibility,
    widgetData,
    hasWidgets,
  }
}
