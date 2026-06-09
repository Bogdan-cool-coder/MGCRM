import { useDashboardsPageActions } from './useDashboardsPageActions'
import { useDashboardsPageData } from './useDashboardsPageData'

export const useDashboardsPage = () => {
  const data = useDashboardsPageData()
  const actions = useDashboardsPageActions()

  return {
    ...data,
    ...actions,
  }
}
