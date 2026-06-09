import type { Company } from '@/components/Company'
import type { User } from '@/entities/user'
import { computed } from 'vue'
import { useCompaniesStore } from '@/stores/companies'
import { useScopedResource } from '@/composables/async/useScopedResource'
import { useNotifications } from '@/composables/useNotifications'
import { useCompanySelection } from '@/pages/shared/useCompanySelection'
import { useServices } from '@/services'

export interface CompanyPageMessages {
  successSummary: string
  commonError: string
  networkError: string
  companyUpdatedSuccess: string
  /** Inline error when currency_code fails the ISO 4217 (`/^[A-Z]{3}$/`) check before submit. */
  currencyInvalid: string
  /** Toast shown when backend returns 403 on PUT /api/companies/{id}. */
  forbiddenError: string
  userCreatedSuccess: string
  userUpdatedSuccess: string
  userDeletedSuccess: string
  userIframeReady: string
  userIframeCopiedSuccess: string
  userIframeRegeneratedSuccess: string
  userIframeUnavailable: string
  userIframeRegenerateConfirm: string
}

export const useCompanyPageData = (messages: CompanyPageMessages) => {
  const { notifyApiError } = useNotifications()
  const companiesStore = useCompaniesStore()
  const { userService, companyService } = useServices()
  const activeCompanyId = computed(() => companiesStore.getActiveCompanyId)

  const companyResource = useScopedResource<number, Company | null>({
    scope: activeCompanyId,
    initialValue: null,
    load: async (companyId) => {
      const company = await companyService.fetchCompany(companyId)
      companiesStore.upsertCompany(company)
      return company
    },
  })
  // `scope: activeCompanyId` drives reactive re-fetches when the active
  // company switches. The loader takes no argument — backend resolves the
  // active company through session middleware.
  const usersResource = useScopedResource<number, User[]>({
    scope: activeCompanyId,
    initialValue: () => [],
    load: () => userService.fetchCompanyUsers(),
  })
  const company = companyResource.data
  const users = usersResource.data
  const usersLoading = usersResource.loading

  const fetchCompany = async (companyId: number) => {
    try {
      await companyResource.sync(companyId)
    } catch (error: unknown) {
      console.error('Failed to fetch company', error)
      notifyApiError(error, messages.networkError)
    }
  }

  const fetchUsers = async (companyId: number) => {
    try {
      await usersResource.sync(companyId)
    } catch (error: unknown) {
      console.error('Failed to fetch users', error)
      notifyApiError(error, messages.networkError)
    }
  }

  const clearScopedData = async () => {
    companyResource.clear(null)
    usersResource.clear([])
  }

  const refreshScopedData = async (companyId?: number | null) => {
    const scopedCompanyId = companyId ?? companiesStore.getActiveCompanyId

    if (!scopedCompanyId) {
      await clearScopedData()
      return
    }

    await Promise.all([fetchCompany(scopedCompanyId), fetchUsers(scopedCompanyId)])
  }

  useCompanySelection({
    onEnterCompanyScope: async (companyId) => {
      await refreshScopedData(companyId)
    },
    onLeaveCompanyScope: clearScopedData,
  })

  return {
    company,
    users,
    usersLoading,
    refreshScopedData,
  }
}
