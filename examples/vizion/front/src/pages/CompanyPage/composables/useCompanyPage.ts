import { useCompanyPageActions } from './useCompanyPageActions'
import { useCompanyPageData, type CompanyPageMessages } from './useCompanyPageData'

export const useCompanyPage = (messages: CompanyPageMessages) => {
  const data = useCompanyPageData(messages)
  const actions = useCompanyPageActions({
    messages,
    company: data.company,
    users: data.users,
    refreshScopedData: data.refreshScopedData,
  })

  return {
    ...data,
    ...actions,
  }
}
