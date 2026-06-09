import { useReportsPageActions } from './useReportsPageActions'
import { useReportsPageData } from './useReportsPageData'

export const useReportsPage = () => {
  const data = useReportsPageData()
  const actions = useReportsPageActions()

  return {
    ...data,
    ...actions,
  }
}
