import { useRoute } from 'vue-router'
import { computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { contactsApi } from '@/api/crm/contacts'
import { useDirectoriesStore } from '@/stores/directories'
import type { Contact, ContactCompanyLink } from '@/entities/crm'

export const useContactPageData = () => {
  const route = useRoute()
  const directoriesStore = useDirectoriesStore()

  const contactId = computed(() => Number(route.params['id']))

  const contactResource = useAsyncResource<Contact | null>(null)
  const companiesResource = useAsyncResource<ContactCompanyLink[]>([])

  async function loadContact() {
    if (!contactId.value) return
    await contactResource.run(() => contactsApi.get(contactId.value))
    if (!directoriesStore.loaded) {
      void directoriesStore.fetchAll()
    }
  }

  async function loadCompanies() {
    if (!contactId.value) return
    await companiesResource.run(() => contactsApi.getCompanies(contactId.value))
  }

  async function loadAll() {
    await Promise.all([loadContact(), loadCompanies()])
  }

  return {
    contactId,
    contact: contactResource.data,
    contactLoading: contactResource.loading,
    contactError: contactResource.error,
    companies: companiesResource.data,
    companiesLoading: companiesResource.loading,
    loadAll,
    loadContact,
    loadCompanies,
    directoriesStore,
  }
}
