/**
 * Dashboard (Обзор tab) data composable — widget data, loading, reload, export.
 *
 * Pattern: watch(filters, debounce 350ms) → reload via useAsyncResource.
 *
 * Filters are OWNED BY THE HUB now (audit §3в: the legacy DashboardToolbar with
 * its own duplicate pipeline/manager pickers was removed). The hub's
 * AnalyticsFilterBar is the single source of pipeline + manager, and the Обзор
 * period Select lives in that same bar. This composable receives those three as
 * reactive getters and refetches when any of them change — so the ONE filter row
 * genuinely drives the Обзор widgets (previously the top bar did not refetch it).
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { getDashboardData, exportDashboardXlsx } from '@/api/salesDashboard'
import { triggerBlobDownload } from '@/utils/download'
import type {
  DashboardFilters,
  DashboardPeriod,
  DashboardResponse,
} from '@/entities/salesDashboard'

export interface DashboardPageOptions {
  /** Named period enum (Месяц/Прошлый месяц/Квартал/Год) from the hub filter bar. */
  period: () => DashboardPeriod
  /** Selected funnel from the hub filter bar (shared across all tabs). */
  pipelineId: () => number | null
  /** Cross-user manager filter from the hub filter bar (admin/director only). */
  managerId: () => number | null
}

export const useDashboardPage = (options: DashboardPageOptions) => {
  const { t } = useI18n()
  const toast = useToast()

  // Snapshot the hub-driven filters into the API request shape.
  const currentFilters = (): DashboardFilters => ({
    period: options.period(),
    pipeline_id: options.pipelineId(),
    manager_id: options.managerId(),
  })

  // ─── Main data resource ─────────────────────────────────────────────────────
  const dashboardResource = useAsyncResource<DashboardResponse | null>(() => null)
  // Prevent empty-state flash before the very first load completes.
  // useAsyncResource starts with loading=false; widgets render empty immediately.
  // dataReady flips to true after the first successful (or failed) fetch.
  const dataReady = ref(false)

  const reload = async (): Promise<void> => {
    try {
      await dashboardResource.run(() => getDashboardData(currentFilters()))
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: msg,
        life: 5000,
      })
    } finally {
      dataReady.value = true
    }
  }

  // ─── Debounced reload on filter changes ────────────────────────────────────
  // `initialized` stays false until the caller fires the first reload (after the
  // hub's async pipeline pre-selection resolves), so the null→id pipeline change
  // does not trigger a duplicate fetch 350 ms after the explicit initial load.
  const initialized = ref(false)
  let debounceTimer: ReturnType<typeof setTimeout> | null = null
  watch(
    () => [options.period(), options.pipelineId(), options.managerId()],
    () => {
      if (!initialized.value) return
      if (debounceTimer) clearTimeout(debounceTimer)
      debounceTimer = setTimeout(() => {
        debounceTimer = null
        void reload()
      }, 350)
    },
  )

  /**
   * Fire the first load and arm the watch. Called by the overview tab once the
   * hub has resolved a pipeline (or immediately if one is already selected).
   */
  const start = async (): Promise<void> => {
    await reload()
    initialized.value = true
  }

  // ─── Export ─────────────────────────────────────────────────────────────────
  // Download via the authenticated axios client as a Blob, then trigger a
  // temporary object-URL <a download> (the app is Bearer-only — window.open
  // would 500). Uses the shared download helper (same path as the report exports).
  const exportXlsx = async (): Promise<void> => {
    try {
      const blob = await exportDashboardXlsx(currentFilters())
      triggerBlobDownload(blob, `dashboard-${new Date().toISOString().slice(0, 10)}.xlsx`)
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: msg,
        life: 5000,
      })
    }
  }

  // loading = true until the first fetch completes OR while re-fetching.
  // Widgets show skeleton in both cases — no empty-state flash on initial mount.
  const loading = computed(() => !dataReady.value || dashboardResource.loading.value)

  return {
    data: dashboardResource.data,
    loading,
    reload,
    start,
    exportXlsx,
  }
}
