import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import type {
  ReportFiltersApplied,
  ReportFiltersAvailable,
  ReportFilterValue,
} from '@/entities/report'
import type { ReportItem } from '@/entities/report'
import { buildDefaultFilters } from '@/entities/report'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useNotifications } from '@/composables/useNotifications'
import { useServices } from '@/services'
import {
  DEFAULT_ROWS_PER_PAGE,
  hasFilterConfigs,
  resetReportPageState,
  toReportSortOption,
} from './reportPageState'

export const useReportPageData = (networkErrorMessage: string) => {
  const route = useRoute()
  const { reportService } = useServices()
  const { notifyApiError } = useNotifications()

  const reportResource = useAsyncResource<ReportItem | null>(null)
  const report = reportResource.data
  const loading = reportResource.loading
  const currentPage = ref(1)
  const currentRowsPerPage = ref(DEFAULT_ROWS_PER_PAGE)
  const currentFilters = ref<ReportFiltersApplied>({})
  const currentSort = ref<ReturnType<typeof toReportSortOption>>(null)
  const originalFiltersAvailable = ref<ReportFiltersAvailable | null>(null)
  const localFilters = ref<ReportFiltersApplied>({})
  // Human-readable labels for async_select single values, keyed by field.
  // The selected option's display text only lives inside AsyncSelectFilter
  // (the applied filter holds an opaque id), so the component surfaces it via
  // `update:selectedLabel` and we cache it here. Read by the header filter
  // summary (#11) to render "Контрагент: {имя}" instead of a raw id.
  const asyncSelectLabels = ref<Record<string, string>>({})
  const filterCollapsed = ref(true)
  const paginationCollapsed = ref(false)

  const hasFilters = computed(() => hasFilterConfigs(effectiveFiltersAvailable.value))
  const effectiveFiltersAvailable = computed<ReportFiltersAvailable>(() => {
    if (hasFilterConfigs(originalFiltersAvailable.value)) {
      return originalFiltersAvailable.value
    }

    if (hasFilterConfigs(report.value?.filters_available)) {
      return report.value.filters_available
    }

    return {}
  })
  const isFilterValueNonEmpty = (value: ReportFilterValue): boolean => {
    if (value === null || value === undefined || value === '') return false
    if (Array.isArray(value)) return value.length > 0
    if (typeof value === 'object') {
      const range = value as { from?: unknown; to?: unknown }
      return (range.from != null && range.from !== '') || (range.to != null && range.to !== '')
    }
    return true
  }

  const hasActiveFilters = computed(() => Object.keys(localFilters.value).length > 0)
  const activeFiltersCount = computed(
    () => Object.values(localFilters.value).filter(isFilterValueNonEmpty).length,
  )
  const showPagination = computed(() => !!report.value?.meta && report.value.meta.last_page > 1)

  // ─── Primary filter (header quick filter) ───────────────────────────────
  // `report.config.primary_filter` is the field key of the one filter we
  // surface as an interactive widget in the header (right of the title). The
  // widget only renders when the named field is actually present in the
  // available filter metadata — otherwise the feature is silently off.
  const primaryFilterField = computed<string | null>(() => {
    const field = report.value?.config?.primary_filter
    if (typeof field !== 'string' || field === '') return null
    return field in effectiveFiltersAvailable.value ? field : null
  })

  const primaryFilterConfig = computed(() => {
    const field = primaryFilterField.value
    if (!field) return null
    return effectiveFiltersAvailable.value[field] ?? null
  })

  const getFilterValue = (field: string): ReportFilterValue | undefined => localFilters.value[field]

  /**
   * Issues a single GET to the report endpoint with the currently applied
   * filter / sort / pagination state. Returns the fetched report so the
   * caller can chain post-load steps (e.g. applying defaults).
   */
  const runFetch = async (): Promise<ReportItem | null | undefined> => {
    const id = Number(route.params.id)
    if (!id) return undefined

    return reportResource.run(() =>
      reportService.fetchReportWithFilters(
        id,
        currentFilters.value,
        currentPage.value,
        currentRowsPerPage.value,
        currentSort.value,
      ),
    )
  }

  /**
   * Two-phase loader (replaces previous recursive `fetchReport`):
   *   1. fetch report (which exposes `filters_available` for the first time);
   *   2. if backend exposed non-empty defaults — apply them and refetch ONCE.
   *
   * Recursion-free; explicit double round-trip is obvious from the code.
   * The second round-trip cost is acceptable today (no `?apply_defaults=true`
   * shortcut on the backend yet — see audit pt. 7).
   */
  const fetchReport = async () => {
    try {
      const fetchedReport = await runFetch()

      if (
        fetchedReport &&
        !hasFilterConfigs(originalFiltersAvailable.value) &&
        hasFilterConfigs(fetchedReport.filters_available)
      ) {
        originalFiltersAvailable.value = fetchedReport.filters_available

        // Apply backend-supplied default values; refetch only when at least
        // one default is non-empty (otherwise the second round-trip is wasted).
        const defaults = buildDefaultFilters(fetchedReport.filters_available)
        if (Object.keys(defaults).length > 0) {
          localFilters.value = { ...defaults }
          currentFilters.value = { ...defaults }
          await runFetch()
        }
      }
    } catch (error: unknown) {
      notifyApiError(error, networkErrorMessage)
    }
  }

  watch(
    () => route.params.id,
    () => {
      reportResource.reset(null)
      resetReportPageState({
        currentPage,
        currentRowsPerPage,
        currentFilters,
        currentSort,
        originalFiltersAvailable,
        localFilters,
        asyncSelectLabels,
        filterCollapsed,
        paginationCollapsed,
      })
      void fetchReport()
    },
  )

  onMounted(fetchReport)

  return {
    report,
    loading,
    currentPage,
    currentRowsPerPage,
    currentFilters,
    currentSort,
    localFilters,
    asyncSelectLabels,
    filterCollapsed,
    paginationCollapsed,
    hasFilters,
    effectiveFiltersAvailable,
    hasActiveFilters,
    activeFiltersCount,
    showPagination,
    primaryFilterField,
    primaryFilterConfig,
    getFilterValue,
    fetchReport,
  }
}
