import { onScopeDispose, ref, watch, type Ref } from 'vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import type { Dashboard } from '@/entities/dashboard'
import type { DashboardLayoutItem } from '@/api/types/dashboards'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

/** grid-layout-plus item shape (`i` is the widget id stringified). */
export interface GridItemModel {
  i: number
  x: number
  y: number
  w: number
  h: number
  /** `static` disables drag/resize for this item (read-only dashboards). */
  static?: boolean
  /** Carried alongside the grid coords so the persist payload can include it. */
  visible: boolean
}

/** Persist debounce window — covers drag/resize bursts without feeling laggy. */
const PUT_DEBOUNCE_MS = 600

/**
 * Bridges the dashboard's widget pivots to a `grid-layout-plus` layout array
 * and persists changes to `PUT /api/dashboards/{id}/layout` (debounced).
 *
 * - `layout` is the reactive array bound to `<GridLayout :layout>`.
 * - `rebuild(dashboard)` re-seeds it from the server pivots (on load / after a
 *   widget is attached/detached/toggled).
 * - `persist()` is called on the grid's `layout-updated` event; it debounces a
 *   batch PUT. When `editable` is false (read-only dashboards) persist is a no-op.
 */
export const useDashboardLayout = (options: {
  dashboardId: Ref<number>
  editable: Ref<boolean>
}) => {
  const { dashboardService } = useServices()
  const { notifyApiError } = useNotifications()
  const { t } = useLocalI18n({ en, ru })

  const layout = ref<GridItemModel[]>([])

  let debounceTimer: ReturnType<typeof setTimeout> | null = null

  const rebuild = (dashboard: Dashboard | null): void => {
    if (!dashboard) {
      layout.value = []
      return
    }
    const editable = options.editable.value
    layout.value = dashboard.widgets.map((w) => ({
      i: w.id,
      x: w.pivot.x,
      y: w.pivot.y,
      w: w.pivot.w,
      h: w.pivot.h,
      visible: w.pivot.visible,
      static: !editable,
    }))
  }

  const buildPayload = (): DashboardLayoutItem[] =>
    layout.value.map((item, index) => ({
      widget_id: item.i,
      x: item.x,
      y: item.y,
      w: item.w,
      h: item.h,
      sort: index,
      visible: item.visible,
    }))

  const flush = async (): Promise<void> => {
    if (debounceTimer) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
    if (!options.editable.value || options.dashboardId.value <= 0) return
    if (layout.value.length === 0) return
    try {
      await dashboardService.updateLayout(options.dashboardId.value, buildPayload())
    } catch (error) {
      notifyApiError(error, t('errors.layoutFailed'), t('common.error'))
    }
  }

  /** Schedule a debounced persist (called on grid `layout-updated`). */
  const persist = (): void => {
    if (!options.editable.value) return
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
      debounceTimer = null
      void flush()
    }, PUT_DEBOUNCE_MS)
  }

  /** Toggle a widget's visibility in the layout + persist. */
  const setVisibility = (widgetId: number, visible: boolean): void => {
    const item = layout.value.find((i) => i.i === widgetId)
    if (!item) return
    item.visible = visible
    persist()
  }

  // Re-static the whole layout when editability flips (e.g. role check resolves).
  watch(
    () => options.editable.value,
    (editable) => {
      layout.value = layout.value.map((item) => ({ ...item, static: !editable }))
    },
  )

  onScopeDispose(() => {
    void flush()
  })

  return {
    layout,
    rebuild,
    persist,
    flush,
    setVisibility,
  }
}
