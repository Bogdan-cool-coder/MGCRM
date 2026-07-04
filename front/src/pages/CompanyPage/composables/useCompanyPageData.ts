import { useRoute } from 'vue-router'
import { computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { companiesApi } from '@/api/crm/companies'
import { contactsApi } from '@/api/crm/contacts'
import { getDocuments } from '@/api/documents'
import { useDirectoriesStore } from '@/stores/directories'
import type { Company, ContactCompanyLink, HoldingTreeDto, CompanyChannel } from '@/entities/crm'
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
  // Channels moved into the data layer so they get the same stale-request token
  // guard as every other resource: on rapid company A→B navigation a late
  // /companies/A/channels response can no longer overwrite company B's channels.
  const channelsResource = useAsyncResource<CompanyChannel[]>([])

  /**
   * @param silent — background refetch: keep the current company on screen (no full
   * skeleton) and quietly swap in the fresh copy. Used after activity mutations so
   * the whole card + tabs don't flash.
   */
  async function loadCompany(silent = false) {
    if (!companyId.value) return
    await companyResource.run(() => companiesApi.get(companyId.value), { silent })
    if (!directoriesStore.loaded) {
      void directoriesStore.fetchAll()
    }
  }

  async function loadChannels() {
    if (!companyId.value) return
    await channelsResource.run(() => companiesApi.getChannels(companyId.value))
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
      loadChannels(),
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
    channels: channelsResource.data,
    channelsLoading: channelsResource.loading,
    loadAll,
    loadCompany,
    loadEmployees,
    loadHolding,
    loadDeals,
    loadChannels,
    directoriesStore,
    contactsApi,
  }
}
