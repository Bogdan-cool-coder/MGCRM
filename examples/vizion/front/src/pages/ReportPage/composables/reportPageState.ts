import type { Ref } from 'vue'
import type { DataTableSortEvent } from 'primevue/datatable'
import type { ReportSortOption } from '@/api/types'
import type { ReportFiltersApplied, ReportFiltersAvailable } from '@/entities/report'

export const DEFAULT_ROWS_PER_PAGE = 100

export const hasFilterConfigs = (
  filters: ReportFiltersAvailable | null | undefined,
): filters is ReportFiltersAvailable => {
  return filters != null && Object.keys(filters).length > 0
}

export const toReportSortOption = (
  event: DataTableSortEvent,
): ReportSortOption | null => {
  if (typeof event.sortField !== 'string' || !event.sortOrder) {
    return null
  }

  return {
    field: event.sortField,
    direction: event.sortOrder === 1 ? 'asc' : 'desc',
  }
}

interface ResetReportPageStateOptions {
  currentPage: Ref<number>
  currentRowsPerPage: Ref<number>
  currentFilters: Ref<ReportFiltersApplied>
  currentSort: Ref<ReportSortOption | null>
  originalFiltersAvailable: Ref<ReportFiltersAvailable | null>
  localFilters: Ref<ReportFiltersApplied>
  asyncSelectLabels: Ref<Record<string, string>>
  filterCollapsed: Ref<boolean>
  paginationCollapsed: Ref<boolean>
}

export const resetReportPageState = (
  state: ResetReportPageStateOptions,
): void => {
  state.currentPage.value = 1
  state.currentRowsPerPage.value = DEFAULT_ROWS_PER_PAGE
  state.currentFilters.value = {}
  state.currentSort.value = null
  state.originalFiltersAvailable.value = null
  state.localFilters.value = {}
  state.asyncSelectLabels.value = {}
  state.filterCollapsed.value = true
  state.paginationCollapsed.value = false
}
