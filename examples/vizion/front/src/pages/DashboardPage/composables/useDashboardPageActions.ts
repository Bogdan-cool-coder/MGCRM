import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useUserStore } from '@/stores/user'
import { useWidgetGenerationModalStore } from '@/stores/widgetGenerationModal'
import { canManageDashboardLayout } from '@/shared/auth/capabilities'
import type { Dashboard, DashboardWidget } from '@/entities/dashboard'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

export const useDashboardPageActions = (options: {
  dashboard: { value: Dashboard | null }
  reloadAll: () => Promise<void>
}) => {
  const router = useRouter()
  const userStore = useUserStore()
  const { dashboardService, widgetService } = useServices()
  const { notifyApiError } = useNotifications()
  const { t } = useLocalI18n({ en, ru })
  const widgetModalStore = useWidgetGenerationModalStore()

  const role = computed(() => userStore.getUserRole)

  /**
   * The dashboard is editable (drag / resize / add / remove widgets) when the
   * role grants layout management AND the dashboard is not a system one. System
   * dashboards are read-only — the user must clone first.
   */
  const isEditable = computed(() => {
    const dash = options.dashboard.value
    if (!dash) return false
    if (dash.isSystem) return false
    return canManageDashboardLayout(role.value)
  })

  const isCloning = ref(false)

  const goBack = () => {
    void router.push('/dashboards')
  }

  /** Clone a system / published dashboard into a personal copy and open it. */
  const cloneDashboard = async () => {
    const dash = options.dashboard.value
    if (!dash || isCloning.value) return
    isCloning.value = true
    try {
      const cloned = await dashboardService.cloneDashboard(dash.id)
      void router.push(`/dashboards/${cloned.id}`)
    } catch (error) {
      notifyApiError(error, t('errors.cloneFailed'), t('common.error'))
    } finally {
      isCloning.value = false
    }
  }

  /** "+ Create widget" → open the widget-generation modal (bound to this dashboard). */
  const openWidgetGeneration = () => {
    const dash = options.dashboard.value
    if (!dash) return
    widgetModalStore.open({ mode: 'create', dashboardId: dash.id })
  }

  /** Per-widget "edit with AI" — resumes the widget's chat (or lazy-creates in edit-mode). */
  const editWidget = async (widget: DashboardWidget) => {
    let chatId: number | null = null
    try {
      // The pivot widget projection doesn't carry chat_id; fetch the full widget.
      const full = await widgetService.fetchWidget(widget.id)
      chatId = full.chatId
    } catch {
      // Fall through — edit-mode without a chat lazy-creates one bound to widgetId.
    }
    widgetModalStore.open({ mode: 'edit', widgetId: widget.id, chatId })
  }

  /** Detach a widget from this dashboard (the widget entity is untouched). */
  const detachWidget = async (widget: DashboardWidget) => {
    const dash = options.dashboard.value
    if (!dash) return
    try {
      await dashboardService.detachWidget(dash.id, widget.id)
      await options.reloadAll()
    } catch (error) {
      notifyApiError(error, t('errors.detachFailed'), t('common.error'))
    }
  }

  return {
    role,
    isEditable,
    isCloning,
    goBack,
    cloneDashboard,
    openWidgetGeneration,
    editWidget,
    detachWidget,
  }
}
