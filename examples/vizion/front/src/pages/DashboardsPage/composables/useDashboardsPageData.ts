import { computed } from 'vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useScopedResource } from '@/composables/async/useScopedResource'
import { getLocalizedText } from '@/utils/localization'
import { useCompanySelection } from '@/pages/shared/useCompanySelection'
import { useCompaniesStore } from '@/stores/companies'
import type { DashboardListItem } from '@/entities/dashboard'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

export interface LocalizedDashboardItem extends DashboardListItem {
  localizedName: string
}

export const useDashboardsPageData = () => {
  const { t, locale } = useLocalI18n({ en, ru })
  const { notifyApiError } = useNotifications()
  const companiesStore = useCompaniesStore()
  const { dashboardService } = useServices()
  const activeCompanyId = computed(() => companiesStore.getActiveCompanyId)

  const dashboardsResource = useScopedResource<number, DashboardListItem[]>({
    scope: activeCompanyId,
    initialValue: () => [],
    load: () => dashboardService.fetchAllDashboards(),
  })
  const loading = dashboardsResource.loading
  const dashboards = dashboardsResource.data

  const fetchDashboards = async (companyId: number) => {
    try {
      await dashboardsResource.sync(companyId)
    } catch (error: unknown) {
      notifyApiError(error, t('errors.loadFailed'))
    }
  }

  const refresh = async () => {
    if (activeCompanyId.value !== null) {
      await fetchDashboards(activeCompanyId.value)
    }
  }

  const clearDashboards = () => {
    dashboardsResource.clear([])
  }

  useCompanySelection({
    onEnterCompanyScope: fetchDashboards,
    onLeaveCompanyScope: clearDashboards,
  })

  const localize = (items: DashboardListItem[]): LocalizedDashboardItem[] =>
    items.map((item) => ({
      ...item,
      localizedName: getLocalizedText(item.name, locale.value),
    }))

  // Library is split into three collapsible sections (mirror of the report
  // library): system (company-wide), published (shared by colleagues), personal.
  const systemDashboards = computed(() =>
    localize(dashboards.value.filter((d) => d.isSystem)),
  )
  const publishedDashboards = computed(() =>
    localize(dashboards.value.filter((d) => !d.isSystem && d.isPublished)),
  )
  const personalDashboards = computed(() =>
    localize(dashboards.value.filter((d) => !d.isSystem && !d.isPublished)),
  )

  const hasAny = computed(() => dashboards.value.length > 0)

  return {
    t,
    loading,
    dashboards,
    hasAny,
    systemDashboards,
    publishedDashboards,
    personalDashboards,
    refresh,
  }
}
