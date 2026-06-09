import type { Pinia } from 'pinia'
import type { Services } from '@/services'
import { resolveAllowedCompanyIds } from '@/shared/session/invariants'
import { useCompaniesStore } from '@/stores/companies'
import { useUserStore } from '@/stores/user'

export interface CompanySelectionService {
  readonly activeCompanyId: number | null
  readonly hasCompanies: boolean
  clear(): void
  reconcile(): void
  refreshCompanies(): Promise<void>
}

export const createCompanySelectionService = (options: {
  pinia: Pinia
  services: Services
}): CompanySelectionService => {
  const userStore = useUserStore(options.pinia)
  const companiesStore = useCompaniesStore(options.pinia)

  const getAllowedCompanyIds = (): number[] | undefined => {
    return resolveAllowedCompanyIds({
      role: userStore.getUserRole,
      availableCompanyIds: userStore.getAvailableCompanyIds,
    })
  }

  const companySelection: CompanySelectionService = {
    get activeCompanyId(): number | null {
      return companiesStore.getActiveCompanyId
    },

    get hasCompanies(): boolean {
      return companiesStore.getCompanies.length > 0
    },

    clear: () => {
      companiesStore.clear()
    },

    reconcile: () => {
      companiesStore.reconcileActiveCompany(
        getAllowedCompanyIds(),
        companySelection.activeCompanyId,
      )
    },

    refreshCompanies: async () => {
      companiesStore.setCompanies(await options.services.companyService.fetchCompanies(), {
        availableCompanyIds: getAllowedCompanyIds(),
        preferredId: companySelection.activeCompanyId,
      })
    },
  }

  return companySelection
}
