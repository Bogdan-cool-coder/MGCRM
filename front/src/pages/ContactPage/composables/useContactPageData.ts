import { useRoute } from 'vue-router'
import { computed, ref } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { contactsApi } from '@/api/crm/contacts'
import { useDirectoriesStore } from '@/stores/directories'
import type { ContactExtended, ContactCompanyLink, ContactRelation, ContactChannel } from '@/entities/crm'
import type { DealDto } from '@/entities/sales'

export const useContactPageData = () => {
  const route = useRoute()
  const directoriesStore = useDirectoriesStore()

  const contactId = computed(() => Number(route.params['id']))

  const contactResource = useAsyncResource<ContactExtended | null>(null)
  const companiesResource = useAsyncResource<ContactCompanyLink[]>([])
  const relationsResource = useAsyncResource<ContactRelation[]>([])
  const dealsResource = useAsyncResource<DealDto[]>([])

  // Deals pagination
  const dealsPage = ref(1)
  const dealsLastPage = ref(1)

  /**
   * @param silent — background refetch: keep the current contact on screen (no
   * full-page skeleton) and quietly swap in the fresh copy. Used after activity
   * mutations that only need to refresh KPI counters.
   */
  async function loadContact(silent = false) {
    if (!contactId.value) return
    await contactResource.run(
      () => contactsApi.get(contactId.value) as Promise<ContactExtended>,
      { silent },
    )
    if (!directoriesStore.loaded) {
      void directoriesStore.fetchAll()
    }
  }

  async function loadCompanies() {
    if (!contactId.value) return
    await companiesResource.run(() => contactsApi.getCompanies(contactId.value))
  }

  async function loadRelations() {
    if (!contactId.value) return
    await relationsResource.run(() => contactsApi.getRelations(contactId.value))
  }

  async function loadDeals(page = 1) {
    if (!contactId.value) return
    if (page === 1) {
      dealsPage.value = 1
      await dealsResource.run(async () => {
        const res = await contactsApi.getDeals(contactId.value, { page: 1, per_page: 10 })
        dealsLastPage.value = res.meta.last_page
        return res.data
      })
    } else {
      dealsPage.value = page
      const res = await contactsApi.getDeals(contactId.value, { page, per_page: 10 })
      dealsLastPage.value = res.meta.last_page
      dealsResource.data.value = [...(dealsResource.data.value ?? []), ...res.data]
    }
  }

  /**
   * Load the next page of deals, tracking the page counter internally so the
   * "load more" button never skips pages after navigation or a page-1 reload.
   */
  async function loadMoreDeals() {
    if (dealsPage.value >= dealsLastPage.value) return
    await loadDeals(dealsPage.value + 1)
  }

  async function loadAll() {
    await Promise.all([loadContact(), loadCompanies(), loadRelations(), loadDeals(1)])
  }

  // Channels come from ContactResource (show endpoint loads them)
  const channels = computed<ContactChannel[]>(
    () => contactResource.data.value?.channels ?? [],
  )

  return {
    contactId,
    contact: contactResource.data,
    contactLoading: contactResource.loading,
    contactError: contactResource.error,
    companies: companiesResource.data,
    companiesLoading: companiesResource.loading,
    relations: relationsResource.data,
    relationsLoading: relationsResource.loading,
    deals: dealsResource.data,
    dealsLoading: dealsResource.loading,
    dealsHasMore: computed(() => dealsPage.value < dealsLastPage.value),
    channels,
    loadAll,
    loadContact,
    loadCompanies,
    loadRelations,
    loadDeals,
    loadMoreDeals,
    directoriesStore,
  }
}
