import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useUserStore } from '@/stores/user'
import { canManageDashboards } from '@/shared/auth/capabilities'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

export const useDashboardsPageActions = () => {
  const router = useRouter()
  const userStore = useUserStore()
  const { dashboardService } = useServices()
  const { notifyApiError } = useNotifications()
  const { t, locale } = useLocalI18n({ en, ru })

  const canManage = computed(() => canManageDashboards(userStore.getUserRole))
  const isCreating = ref(false)

  const openDashboard = (id: number) => {
    void router.push(`/dashboards/${id}`)
  }

  /**
   * Header "+ New dashboard" → creates an empty personal dashboard and opens
   * it. The name defaults to a localized placeholder the user can rename later.
   */
  const createDashboard = async () => {
    if (!canManage.value || isCreating.value) return
    isCreating.value = true
    try {
      const created = await dashboardService.createDashboard({
        name: { ru: 'Новый дашборд', en: 'New dashboard' },
      })
      openDashboard(created.id)
    } catch (error) {
      notifyApiError(error, t('errors.createFailed'), t('common.error'))
    } finally {
      isCreating.value = false
    }
  }

  return {
    canManage,
    isCreating,
    locale,
    openDashboard,
    createDashboard,
  }
}
