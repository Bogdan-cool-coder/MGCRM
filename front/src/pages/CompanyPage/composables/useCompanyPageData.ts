import { useRoute } from 'vue-router'
import { computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { companiesApi } from '@/api/crm/companies'
import { contactsApi } from '@/api/crm/contacts'
import { useDirectoriesStore } from '@/stores/directories'
import type { Company, ContactCompanyLink } from '@/entities/crm'

export const useCompanyPageData = () => {
  const route = useRoute()
  const directoriesStore = useDirectoriesStore()

  const companyId = computed(() => Number(route.params['id']))

  const companyResource = useAsyncResource<Company | null>(null)
  const employeesResource = useAsyncResource<ContactCompanyLink[]>([])

  async function loadCompany() {
    if (!companyId.value) return
    await companyResource.run(() => companiesApi.get(companyId.value))
    if (!directoriesStore.loaded) {
      void directoriesStore.fetchAll()
    }
  }

  async function loadEmployees() {
    if (!companyId.value) return
    await employeesResource.run(() => companiesApi.getEmployees(companyId.value))
  }

  async function loadAll() {
    await Promise.all([loadCompany(), loadEmployees()])
  }

  return {
    companyId,
    company: companyResource.data,
    companyLoading: companyResource.loading,
    companyError: companyResource.error,
    employees: employeesResource.data,
    employeesLoading: employeesResource.loading,
    loadAll,
    loadCompany,
    loadEmployees,
    directoriesStore,
    contactsApi,
  }
}
