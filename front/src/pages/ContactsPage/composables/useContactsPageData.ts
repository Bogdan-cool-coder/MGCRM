import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { contactsApi } from '@/api/crm/contacts'
import { companiesApi } from '@/api/crm/companies'
import { useDirectoriesStore } from '@/stores/directories'
import { getApiErrorMessage } from '@/utils/errors'
import type { Contact, Company, PaginatedResponse } from '@/entities/crm'
import type { CompanyListParams } from '@/api/crm/companies'
import type { ContactListParams } from '@/api/crm/contacts'

export type EntityType = 'contact' | 'company'

export interface ContactsFilter {
  search: string
  company_type_id: number | null
  source: string | null
  country_code: string | null
  tags: string[]
}

const DEFAULT_FILTER: ContactsFilter = {
  search: '',
  company_type_id: null,
  source: null,
  country_code: null,
  tags: [],
}

export interface UseContactsPageDataOptions {
  /** Начальный тип сущности. По умолчанию 'contact'. */
  initialType?: EntityType
}

export const useContactsPageData = ({ initialType = 'contact' }: UseContactsPageDataOptions = {}) => {
  const { t } = useI18n()
  const toast = useToast()
  const directoriesStore = useDirectoriesStore()

  const entityType = ref<EntityType>(initialType)
  const page = ref(1)
  const perPage = ref(25)
  const filter = ref<ContactsFilter>({ ...DEFAULT_FILTER })

  const emptyPage = (): PaginatedResponse<Contact | Company> => ({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
  })

  const contactsResource = useAsyncResource<PaginatedResponse<Contact>>(
    emptyPage as () => PaginatedResponse<Contact>,
  )
  const companiesResource = useAsyncResource<PaginatedResponse<Company>>(
    emptyPage as () => PaginatedResponse<Company>,
  )

  const loading = computed(() =>
    entityType.value === 'contact'
      ? contactsResource.loading.value
      : companiesResource.loading.value,
  )
  const items = computed<Array<Contact | Company>>(() =>
    entityType.value === 'contact'
      ? contactsResource.data.value.data
      : companiesResource.data.value.data,
  )
  const total = computed(() =>
    entityType.value === 'contact'
      ? contactsResource.data.value.meta.total
      : companiesResource.data.value.meta.total,
  )

  const isFiltered = computed(
    () =>
      !!filter.value.search ||
      !!filter.value.company_type_id ||
      !!filter.value.source ||
      !!filter.value.country_code ||
      filter.value.tags.length > 0,
  )

  async function load() {
    if (entityType.value === 'contact') {
      const params: ContactListParams = {
        page: page.value,
        per_page: perPage.value,
        search: filter.value.search || undefined,
        source: filter.value.source ?? undefined,
        country_code: filter.value.country_code ?? undefined,
        tags: filter.value.tags.length ? filter.value.tags : undefined,
      }
      try {
        await contactsResource.run(() => contactsApi.list(params))
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: t('contacts.page.errors.load'),
          detail: getApiErrorMessage(err, t('errors.server_error')),
          life: 4000,
        })
      }
    } else {
      const params: CompanyListParams = {
        page: page.value,
        per_page: perPage.value,
        search: filter.value.search || undefined,
        company_type_id: filter.value.company_type_id ?? undefined,
        source: filter.value.source ?? undefined,
        country_code: filter.value.country_code ?? undefined,
        tags: filter.value.tags.length ? filter.value.tags : undefined,
      }
      try {
        await companiesResource.run(() => companiesApi.list(params))
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: t('contacts.page.errors.load'),
          detail: getApiErrorMessage(err, t('errors.server_error')),
          life: 4000,
        })
      }
    }
  }

  function applyFilter() {
    page.value = 1
    void load()
  }

  function resetFilter() {
    filter.value = { ...DEFAULT_FILTER }
    page.value = 1
    void load()
  }

  function onPageChange(newPage: number) {
    page.value = newPage
    void load()
  }

  function ensureDirectories() {
    if (!directoriesStore.loaded) {
      void directoriesStore.fetchAll()
    }
  }

  watch(entityType, () => {
    page.value = 1
    void load()
  })

  return {
    entityType,
    page,
    perPage,
    filter,
    loading,
    items,
    total,
    isFiltered,
    load,
    applyFilter,
    resetFilter,
    onPageChange,
    ensureDirectories,
  }
}
