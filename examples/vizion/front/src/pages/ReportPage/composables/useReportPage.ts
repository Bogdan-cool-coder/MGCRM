import { useReportPageActions } from './useReportPageActions'
import { useReportPageData } from './useReportPageData'

export const useReportPage = (networkErrorMessage: string) => {
  const data = useReportPageData(networkErrorMessage)
  const actions = useReportPageActions({
    currentPage: data.currentPage,
    currentRowsPerPage: data.currentRowsPerPage,
    currentFilters: data.currentFilters,
    currentSort: data.currentSort,
    localFilters: data.localFilters,
    asyncSelectLabels: data.asyncSelectLabels,
    filterCollapsed: data.filterCollapsed,
    fetchReport: data.fetchReport,
  })

  return {
    ...data,
    ...actions,
  }
}
