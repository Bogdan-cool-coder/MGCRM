import { useRoute } from 'vue-router'
import { computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { companiesApi } from '@/api/crm/companies'
import { contactsApi } from '@/api/crm/contacts'
import { getDocuments } from '@/api/documents'
import { useDirectoriesStore } from '@/stores/directories'
import type { Company, ContactCompanyLink, HoldingTreeDto } from '@/entities/crm'
import type { DealDto } from '@/entities/sales'
import type { DocumentListItemDto } from '@/entities/document'

export const useCompanyPageData = () => {
  const route = useRoute()
  const directoriesStore = useDirectoriesStore()

  const companyId = computed(() => Number(route.params['id']))

  const companyResource = useAsyncResource<Company | null>(null)
  const employeesResource = useAsyncResource<ContactCompanyLink[]>([])
  const holdingResource = useAsyncResource<HoldingTreeDto | null>(null)
  const dealsResource = useAsyncResource<DealDto[]>([])
  const documentsResource = useAsyncResource<DocumentListItemDto[]>([])

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

  async function loadHolding() {
    if (!companyId.value) return
    await holdingResource.run(() => companiesApi.getHolding(companyId.value))
  }

  async function loadDeals() {
    if (!companyId.value) return
    await dealsResource.run(async () => {
      const result = await companiesApi.getDeals(companyId.value, { per_page: 50 })
      return result.data
    })
  }

  async function loadDocuments() {
    if (!companyId.value) return
    await documentsResource.run(async () => {
      const result = await getDocuments({ source_company_id: companyId.value, per_page: 20 })
      return result.data
    })
  }

  async function loadAll() {
    await Promise.all([
      loadCompany(),
      loadEmployees(),
      loadHolding(),
      loadDeals(),
      loadDocuments(),
    ])
  }

  return {
    companyId,
    company: companyResource.data,
    companyLoading: companyResource.loading,
    companyError: companyResource.error,
    employees: employeesResource.data,
    employeesLoading: employeesResource.loading,
    holding: holdingResource.data,
    holdingLoading: holdingResource.loading,
    deals: dealsResource.data,
    dealsLoading: dealsResource.loading,
    documents: documentsResource.data,
    documentsLoading: documentsResource.loading,
    loadAll,
    loadCompany,
    loadEmployees,
    loadHolding,
    loadDeals,
    directoriesStore,
    contactsApi,
  }
}
