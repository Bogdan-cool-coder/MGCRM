import type { Company } from '@/components/Company'
import type { User } from '@/entities/user'
import type { Ref } from 'vue'
import type { CompanyPageMessages } from './useCompanyPageData'
import { useCompanySettingsActions } from './useCompanySettingsActions'
import { useCompanyUserFormActions } from './useCompanyUserFormActions'
import { useCompanyUserDeletionActions } from './useCompanyUserDeletionActions'

interface UseCompanyPageActionsOptions {
  messages: CompanyPageMessages
  company: Ref<Company | null>
  users: Ref<User[]>
  refreshScopedData: () => Promise<void>
}

export const useCompanyPageActions = (options: UseCompanyPageActionsOptions) => {
  const companySettings = useCompanySettingsActions({
    company: options.company,
    messages: options.messages,
    refreshScopedData: options.refreshScopedData,
  })
  const userForm = useCompanyUserFormActions({
    messages: options.messages,
    refreshScopedData: options.refreshScopedData,
  })
  const userDeletion = useCompanyUserDeletionActions({
    messages: options.messages,
    users: options.users,
    refreshScopedData: options.refreshScopedData,
  })

  return {
    ...companySettings,
    ...userForm,
    ...userDeletion,
  }
}
