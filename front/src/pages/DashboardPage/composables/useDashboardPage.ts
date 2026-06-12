/**
 * Dashboard page composable — filters, loading, reload, export.
 *
 * Pattern: watch(filters, debounce 350ms) → reload via useAsyncResource.
 * Pipelines loaded on mount; managers loaded for admin/director only.
 */
import { reactive, ref, watch, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useUserStore } from '@/stores/user'
import { getDashboardData, exportDashboardXlsx } from '@/api/salesDashboard'
import { salesApi } from '@/api/sales'
import { usersApi } from '@/api/users'
import type { DashboardFilters, DashboardResponse } from '@/entities/salesDashboard'
import type { PipelineDto } from '@/entities/sales'
import type { UserOptionDto } from '@/api/users'

export const useDashboardPage = () => {
  const { t } = useI18n()
  const toast = useToast()
  const userStore = useUserStore()

  // ─── Filters ────────────────────────────────────────────────────────────────
  const filters = reactive<DashboardFilters>({
    period: 'current_month',
    pipeline_id: null,
    manager_id: null,
  })

  // ─── Supplementary data ─────────────────────────────────────────────────────
  const pipelines = ref<PipelineDto[]>([])
  const managers = ref<UserOptionDto[]>([])
  const pipelinesLoading = ref(false)

  const canSeeAllManagers = computed<boolean>(() =>
    userStore.getUserRole !== null &&
    ['admin', 'director'].includes(userStore.getUserRole),
  )

  // ─── Main data resource ─────────────────────────────────────────────────────
  const dashboardResource = useAsyncResource<DashboardResponse | null>(() => null)

  const reload = async (): Promise<void> => {
    try {
      await dashboardResource.run(() => getDashboardData(filters))
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

  // ─── Filter helpers ──────────────────────────────────────────────────────────
  const setFilter = <K extends keyof DashboardFilters>(
    key: K,
    value: DashboardFilters[K],
  ): void => {
    if (key === 'pipeline_id') {
      filters.manager_id = null
    }
    filters[key] = value
  }

  // ─── Load supplementary data ─────────────────────────────────────────────────
  const loadPipelines = async (): Promise<void> => {
    pipelinesLoading.value = true
    try {
      const data = await salesApi.getPipelines()
      pipelines.value = data
      // Pre-select first pipeline
      const first = data[0]
      if (first != null && filters.pipeline_id == null) {
        filters.pipeline_id = first.id
      }
    } catch {
      // non-critical: silently ignore pipeline load errors
    } finally {
      pipelinesLoading.value = false
    }
  }

  const loadManagers = async (): Promise<void> => {
    if (!canSeeAllManagers.value) return
    try {
      const data = await usersApi.getUsers()
      managers.value = data.filter((u) => ['admin', 'director', 'manager'].includes(u.role))
    } catch {
      // non-critical
    }
  }

  // ─── Debounced reload on filter changes ────────────────────────────────────
  // `initialized` is false until the first onMounted reload completes, so
  // the watch does not fire a second reload for the pipeline_id pre-selection
  // that happens synchronously inside loadPipelines().
  const initialized = ref(false)
  let debounceTimer: ReturnType<typeof setTimeout> | null = null
  watch(
    () => ({ ...filters }),
    () => {
      if (!initialized.value) return
      if (debounceTimer) clearTimeout(debounceTimer)
      debounceTimer = setTimeout(() => {
        debounceTimer = null
        void reload()
      }, 350)
    },
    { deep: true },
  )

  // ─── Export ─────────────────────────────────────────────────────────────────
  const exportXlsx = (): void => {
    exportDashboardXlsx({ ...filters })
  }

  // ─── Mount ──────────────────────────────────────────────────────────────────
  onMounted(async () => {
    await loadPipelines()
    await loadManagers()
    await reload()
    // Enable watch-driven reloads only after the initial load so that the
    // pipeline_id pre-selection in loadPipelines() does not trigger a
    // duplicate API call + Toast 350 ms later.
    initialized.value = true
  })

  return {
    filters,
    pipelines,
    managers,
    pipelinesLoading,
    canSeeAllManagers,
    data: dashboardResource.data,
    loading: dashboardResource.loading,
    setFilter,
    reload,
    exportXlsx,
  }
}
