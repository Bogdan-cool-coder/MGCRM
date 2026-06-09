import { computed, onMounted, ref, watch } from 'vue'
import { useApplicationServices } from '@/application'
import { createCompanySyncService } from '@/application/company/companySyncService'
import { useCompaniesStore } from '@/stores/companies'

interface UseCompanySelectionOptions {
  onEnterCompanyScope: (_companyId: number) => Promise<void> | void
  onLeaveCompanyScope?: () => Promise<void> | void
}

export const useCompanySelection = (options: UseCompanySelectionOptions) => {
  const companiesStore = useCompaniesStore()
  const { sessionCoordinator } = useApplicationServices()
  const guardLoading = ref(false)
  const companySync = createCompanySyncService(options)

  const activeCompanyId = computed(() => companiesStore.getActiveCompanyId)
  const hasActiveCompany = computed(() => activeCompanyId.value !== null)

  const guardCompanySelection = async () => {
    guardLoading.value = true

    try {
      const companyId = await sessionCoordinator.ensureCompanySelected()
      await companySync.sync(companyId, true)
      return companyId
    } finally {
      guardLoading.value = false
    }
  }

  const refreshCompanySelection = async () => {
    await companySync.sync(activeCompanyId.value, true)
  }

  onMounted(() => {
    void guardCompanySelection()
  })

  watch(activeCompanyId, (companyId, previousCompanyId) => {
    if (guardLoading.value || companyId === previousCompanyId) {
      return
    }

    void companySync.sync(companyId)
  })

  return {
    activeCompanyId,
    hasActiveCompany,
    guardLoading,
    guardCompanySelection,
    refreshCompanySelection,
  }
}
