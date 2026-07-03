/**
 * Analytics hub Excel export — the PageHeader «Экспорт в Excel» button action.
 *
 * The button in the shell exports whichever report tab is active (registry /
 * schedule / rating), passing that tab's slice of the cross-cutting hub filters.
 * Download goes through the authenticated axios client as a Blob (Bearer-only app)
 * and is saved via the shared `@/utils/download` helper.
 *
 * Backend export endpoints are built in PARALLEL (contract §9 Ф5). Until they ship
 * a call 404s → we show a friendly info-toast «Экспорт скоро будет доступен» rather
 * than an error, so the button is live but degrades gracefully (the established
 * graceful-404 pattern used across the hub).
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { exportReportXlsx, type ExportableReport, type ReportExportParams } from '@/api/reportsExport'
import { downloadBlobResponse } from '@/utils/download'
import { getApiErrorStatus } from '@/utils/errors'
import type { HubTab } from './useAnalyticsHub'

/** Which hub tabs export (overview does not). Планы exports its visible matrix. */
const TAB_TO_REPORT: Partial<Record<HubTab, ExportableReport>> = {
  plans: 'plans',
  registry: 'registry',
  schedule: 'schedule',
  rating: 'rating',
}

/** Default download filename (server Content-Disposition wins when present). */
const fallbackName = (report: ExportableReport): string =>
  `${report}-${new Date().toISOString().slice(0, 10)}.xlsx`

export const useAnalyticsExport = () => {
  const { t } = useI18n()
  const toast = useToast()

  const exporting = ref(false)

  /**
   * Export the given tab with its filter slice. Silently ignores non-report tabs.
   * 404 (endpoint not yet deployed) → info-toast; other errors → error-toast.
   */
  const exportTab = async (tab: HubTab, params: ReportExportParams): Promise<void> => {
    const report = TAB_TO_REPORT[tab]
    if (!report) return
    exporting.value = true
    try {
      const response = await exportReportXlsx(report, params)
      downloadBlobResponse(response, fallbackName(report))
    } catch (err) {
      if (getApiErrorStatus(err) === 404) {
        toast.add({ severity: 'info', summary: t('dashboard.hub.export_soon'), life: 4000 })
        return
      }
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
    } finally {
      exporting.value = false
    }
  }

  return { exporting, exportTab }
}
