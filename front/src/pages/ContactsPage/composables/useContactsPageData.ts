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
import type { ContactsOverlayFilters } from './useContactsFilters'
import { DEFAULT_OVERLAY_FILTERS } from './useContactsFilters'

export type EntityType = 'contact' | 'company'

/** Simple quick-filter (search bar only). Overlay filters handled separately. */
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

  // Persist perPage in localStorage
  const storedPerPage = Number(localStorage.getItem('mgcrm_contacts_per_page_v1')) || 50
  const perPage = ref(storedPerPage)
  /** Legacy simple filter — kept for backward compat; overlayFilters extends it. */
  const filter = ref<ContactsFilter>({ ...DEFAULT_FILTER })
  /** Full overlay filter state */
  const overlayFilters = ref<ContactsOverlayFilters>({ ...DEFAULT_OVERLAY_FILTERS })

  const emptyPage = (): PaginatedResponse<Contact | Company> => ({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 0, from: null, to: null },
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
  const allItemIds = computed(() => items.value.map((i) => i.id))

  const total = computed(() =>
    entityType.value === 'contact'
      ? contactsResource.data.value.meta.total
      : companiesResource.data.value.meta.total,
  )

  /** Count of active overlay filters (excluding simple search) */
  const activeFilterCount = computed(() => {
    const f = overlayFilters.value
    let n = 0
    if (f.owner_ids.length) n++
    if (f.author_ids.length) n++
    if (f.tags.length) n++
    if (f.sources.length) n++
    if (f.engagement_tier) n++
    if (f.company_type_ids.length) n++
    if (f.categories.length) n++
    if (f.country_code) n++
    if (f.city) n++
    if (f.open_deals_min !== null || f.open_deals_max !== null) n++
    if (f.created_range) n++
    if (f.last_touch_range) n++
    if (f.only_mine) n++
    if (f.only_active) n++
    if (f.only_with_deals) n++
    if (f.only_no_task) n++
    if (f.only_duplicates) n++
    return n
  })

  const isFiltered = computed(
    () =>
      !!filter.value.search ||
      activeFilterCount.value > 0,
  )

  function buildContactParams(): ContactListParams {
    const f = overlayFilters.value
    const params: ContactListParams = {
      page: page.value,
      per_page: perPage.value,
      search: filter.value.search || undefined,
      // Overlay filters mapped to API params
      tags: f.tags.length ? f.tags : (filter.value.tags.length ? filter.value.tags : undefined),
      source: f.sources[0] ?? filter.value.source ?? undefined,
      country_code: f.country_code ?? filter.value.country_code ?? undefined,
      engagement_tier: f.engagement_tier ?? undefined,
    }
    return params
  }

  function buildCompanyParams(): CompanyListParams {
    const f = overlayFilters.value
    const params: CompanyListParams = {
      page: page.value,
      per_page: perPage.value,
      search: filter.value.search || undefined,
      company_type_id: f.company_type_ids[0] ?? filter.value.company_type_id ?? undefined,
      source: f.sources[0] ?? filter.value.source ?? undefined,
      country_code: f.country_code ?? filter.value.country_code ?? undefined,
      tags: f.tags.length ? f.tags : (filter.value.tags.length ? filter.value.tags : undefined),
      engagement_tier: f.engagement_tier ?? undefined,
    }
    return params
  }

  async function load() {
    if (entityType.value === 'contact') {
      try {
        await contactsResource.run(() => contactsApi.list(buildContactParams()))
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: t('contacts.page.errors.load'),
          detail: getApiErrorMessage(err, t('errors.server_error')),
          life: 4000,
        })
      }
    } else {
      try {
        await companiesResource.run(() => companiesApi.list(buildCompanyParams()))
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

  function applyOverlayFilters(newFilters: ContactsOverlayFilters) {
    overlayFilters.value = { ...newFilters }
    page.value = 1
    void load()
  }

  function resetFilter() {
    filter.value = { ...DEFAULT_FILTER }
    overlayFilters.value = { ...DEFAULT_OVERLAY_FILTERS }
    page.value = 1
    void load()
  }

  function resetOverlayFilters() {
    overlayFilters.value = { ...DEFAULT_OVERLAY_FILTERS }
    page.value = 1
    void load()
  }

  /** Remove a single chip filter by its key */
  function removeChipFilter(key: string) {
    const f = overlayFilters.value
    if (key === 'search') { filter.value.search = '' }
    else if (key === 'only_mine') { f.only_mine = false }
    else if (key === 'only_active') { f.only_active = false }
    else if (key === 'only_with_deals') { f.only_with_deals = false }
    else if (key === 'only_no_task') { f.only_no_task = false }
    else if (key === 'only_duplicates') { f.only_duplicates = false }
    else if (key === 'engagement_tier') { f.engagement_tier = null }
    else if (key === 'country') { f.country_code = null }
    else if (key === 'city') { f.city = '' }
    else if (key === 'open_deals') { f.open_deals_min = null; f.open_deals_max = null }
    else if (key.startsWith('owner_')) {
      const id = parseInt(key.slice(6))
      f.owner_ids = f.owner_ids.filter((x) => x !== id)
    } else if (key.startsWith('tag_')) {
      const tag = key.slice(4)
      f.tags = f.tags.filter((x) => x !== tag)
    } else if (key.startsWith('source_')) {
      const code = key.slice(7)
      f.sources = f.sources.filter((x) => x !== code)
    } else if (key.startsWith('ctype_')) {
      const id = parseInt(key.slice(6))
      f.company_type_ids = f.company_type_ids.filter((x) => x !== id)
    }
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
    overlayFilters,
    loading,
    items,
    allItemIds,
    total,
    isFiltered,
    activeFilterCount,
    load,
    applyFilter,
    applyOverlayFilters,
    resetFilter,
    resetOverlayFilters,
    removeChipFilter,
    onPageChange,
    ensureDirectories,
  }
}
