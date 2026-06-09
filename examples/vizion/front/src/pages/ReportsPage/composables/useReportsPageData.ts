import { computed } from 'vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useScopedResource } from '@/composables/async/useScopedResource'
import { getLocalizedText } from '@/utils/localization'
import { useCompanySelection } from '@/pages/shared/useCompanySelection'
import { useCompaniesStore } from '@/stores/companies'
import type { ReportItem } from '@/entities/report'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

export const useReportsPageData = () => {
  const { t, locale } = useLocalI18n({ en, ru })
  const { notifyApiError } = useNotifications()
  const companiesStore = useCompaniesStore()
  const { reportService } = useServices()
  const activeCompanyId = computed(() => companiesStore.getActiveCompanyId)

  // `scope: activeCompanyId` drives reactive re-fetches when the active
  // company switches. The loader itself takes no arguments — the backend
  // resolves the active company via session middleware.
  const reportsResource = useScopedResource<number, ReportItem[]>({
    scope: activeCompanyId,
    initialValue: () => [],
    load: () => reportService.fetchAllReports(),
  })
  const loading = reportsResource.loading
  const reports = reportsResource.data

  const fetchReports = async (companyId: number) => {
    try {
      await reportsResource.sync(companyId)
    } catch (error: unknown) {
      console.error('Failed to fetch reports', error)
      notifyApiError(error, t('errors.networkError'))
    }
  }

  const clearReports = async () => {
    reportsResource.clear([])
  }

  useCompanySelection({
    onEnterCompanyScope: fetchReports,
    onLeaveCompanyScope: clearReports,
  })

  const localizedReports = computed(() =>
    reports.value.map((report) => ({
      ...report,
      localizedTitle: getLocalizedText(report.title, locale.value),
      localizedDescription: report.description
        ? getLocalizedText(report.description, locale.value)
        : undefined,
    })),
  )

  /**
   * Persist a new report ordering. The reports array is reordered locally
   * first (optimistic — the drag already moved the card), then the new order
   * is sent to the backend. On failure we restore the previous order and
   * surface a toast. The backend stores the order per-user + active company;
   * the list arrives pre-ordered on subsequent loads, so we don't refetch.
   */
  const reorderReports = async (orderedIds: number[]): Promise<void> => {
    const previous = reports.value
    const byId = new Map(previous.map((report) => [report.id, report]))
    const reordered = orderedIds
      .map((id) => byId.get(id))
      .filter((report): report is ReportItem => report !== undefined)

    // Guard: only apply when the reordered list covers every current report
    // (no id dropped / unknown). Otherwise leave state untouched.
    if (reordered.length !== previous.length) return

    reports.value = reordered

    try {
      await reportService.updateReportsOrder(orderedIds)
    } catch (error: unknown) {
      reports.value = previous
      notifyApiError(error, t('errors.reorderFailed'))
    }
  }

  return {
    t,
    loading,
    reports,
    localizedReports,
    reorderReports,
  }
}
