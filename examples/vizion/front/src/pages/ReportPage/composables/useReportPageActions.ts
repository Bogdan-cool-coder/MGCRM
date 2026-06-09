import { useRouter } from 'vue-router'
import type { Ref } from 'vue'
import type { DataTableSortEvent } from 'primevue/datatable'
import type {
  ReportFiltersApplied,
  ReportFilterValue,
} from '@/entities/report'
import { DEFAULT_ROWS_PER_PAGE, toReportSortOption } from './reportPageState'

interface UseReportPageActionsOptions {
  currentPage: Ref<number>
  currentRowsPerPage: Ref<number>
  currentFilters: Ref<ReportFiltersApplied>
  currentSort: Ref<ReturnType<typeof toReportSortOption>>
  localFilters: Ref<ReportFiltersApplied>
  asyncSelectLabels: Ref<Record<string, string>>
  filterCollapsed: Ref<boolean>
  fetchReport: () => Promise<void>
}

export const useReportPageActions = (options: UseReportPageActionsOptions) => {
  const router = useRouter()

  const updateFilterValue = (field: string, value: ReportFilterValue) => {
    options.localFilters.value[field] = value
  }

  /**
   * Cache the human-readable label for an async_select single value so the
   * header filter summary (#11) can show the contractor name instead of the
   * raw id. Cleared when the value is cleared.
   */
  const setAsyncSelectLabel = (field: string, label: string | null) => {
    if (label == null || label === '') {
      delete options.asyncSelectLabels.value[field]
      return
    }
    options.asyncSelectLabels.value[field] = label
  }

  const toggleFilter = () => {
    options.filterCollapsed.value = !options.filterCollapsed.value
  }

  const applyFilters = async () => {
    options.currentFilters.value = { ...options.localFilters.value }
    options.currentPage.value = 1
    await options.fetchReport()
  }

  /**
   * Apply a single filter immediately — used by the header "primary filter"
   * widget (everyday quick filter), which has no Apply button.
   *
   * Shares the same source of truth as the panel: it mutates `localFilters`
   * (so the panel widget reflects the change reactively) and then commits the
   * full `localFilters` snapshot into `currentFilters` and refetches. Any
   * pending panel edits to *other* fields are committed alongside it — that is
   * acceptable here (the primary filter is the everyday driver; committing the
   * rest of the panel's in-progress edits matches the user's intent of "give
   * me data for this", and keeps a single, consistent commit path instead of a
   * second parallel applied-state object).
   */
  const applyPrimaryFilter = async (field: string, value: ReportFilterValue) => {
    options.localFilters.value[field] = value
    options.currentFilters.value = { ...options.localFilters.value }
    options.currentPage.value = 1
    await options.fetchReport()
  }

  const resetFilters = async () => {
    options.localFilters.value = {}
    options.currentFilters.value = {}
    options.asyncSelectLabels.value = {}
    options.currentPage.value = 1
    options.currentRowsPerPage.value = DEFAULT_ROWS_PER_PAGE
    await options.fetchReport()
  }

  const onPageChange = async (event: { page: number; rows: number }) => {
    options.currentPage.value = event.page + 1
    options.currentRowsPerPage.value = event.rows
    await options.fetchReport()
  }

  const onSortChange = async (event: DataTableSortEvent) => {
    options.currentPage.value = 1
    options.currentSort.value = toReportSortOption(event)
    await options.fetchReport()
  }

  const goBack = async () => {
    await router.push('/reports')
  }

  return {
    updateFilterValue,
    setAsyncSelectLabel,
    toggleFilter,
    applyFilters,
    applyPrimaryFilter,
    resetFilters,
    onPageChange,
    onSortChange,
    goBack,
  }
}
