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

// Sort key types per backend contract
export type ContactSortBy = 'name' | 'company' | 'phone' | 'last_contact' | 'open_deals' | 'author' | 'created'
export type CompanySortBy = 'name' | 'category' | 'country' | 'deals' | 'last_contact' | 'engagement' | 'owner' | 'created'
export type SortDir = 'asc' | 'desc'

// Map from column field → backend sort_by value
export const CONTACT_SORT_MAP: Partial<Record<string, ContactSortBy>> = {
  full_name: 'name',
  company: 'company',
  phone: 'phone',
  last_activity_at: 'last_contact',
  open_deals_count: 'open_deals',
  owner: 'author',
  // created_at not in default visible cols
}

export const COMPANY_SORT_MAP: Partial<Record<string, CompanySortBy>> = {
  name: 'name',
  category_code: 'category',
  country_code: 'country',
  open_deals_count: 'deals',
  last_activity_at: 'last_contact',
  engagement_tier: 'engagement',
  owner: 'owner',
}

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

  // Sort state — reset when entity type changes
  const sortByContact = ref<ContactSortBy | null>(null)
  const sortByCompany = ref<CompanySortBy | null>(null)
  const sortDir = ref<SortDir>('asc')
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
    // city only counts for companies
    if (entityType.value === 'company' && f.city) n++
    // position only counts for contacts
    if (entityType.value === 'contact' && f.position) n++
    if (f.open_deals_min !== null || f.open_deals_max !== null) n++
    if (f.created_range) n++
    if (f.last_touch_range) n++
    if (f.only_mine) n++
    if (f.only_active) n++
    if (f.only_with_deals) n++
    if (f.only_no_task) n++
    return n
  })

  const isFiltered = computed(
    () =>
      !!filter.value.search ||
      activeFilterCount.value > 0,
  )

  function isoFromDate(d: Date): string {
    return d.toISOString().slice(0, 10)
  }

  function buildContactParams(): ContactListParams {
    const f = overlayFilters.value
    const params: ContactListParams = {
      page: page.value,
      per_page: perPage.value,
      search: filter.value.search || undefined,
      // Sorting
      sort_by: sortByContact.value ?? undefined,
      sort_dir: sortByContact.value ? sortDir.value : undefined,
      // Multi-value
      owner_ids: f.owner_ids.length ? f.owner_ids : undefined,
      author_ids: f.author_ids.length ? f.author_ids : undefined,
      sources: f.sources.length ? f.sources : undefined,
      tags: f.tags.length ? f.tags : (filter.value.tags.length ? filter.value.tags : undefined),
      // Single-value
      position: f.position || undefined,
      country_code: f.country_code ?? filter.value.country_code ?? undefined,
      engagement_tier: f.engagement_tier ?? undefined,
      // Date ranges
      created_from: f.created_range?.[0] ? isoFromDate(f.created_range[0]) : undefined,
      created_to: f.created_range?.[1] ? isoFromDate(f.created_range[1]) : undefined,
      last_touch_from: f.last_touch_range?.[0] ? isoFromDate(f.last_touch_range[0]) : undefined,
      last_touch_to: f.last_touch_range?.[1] ? isoFromDate(f.last_touch_range[1]) : undefined,
      // Open deals range
      open_deals_min: f.open_deals_min ?? undefined,
      open_deals_max: f.open_deals_max ?? undefined,
      // Presets
      only_mine: f.only_mine || undefined,
      only_active: f.only_active || undefined,
      only_with_deals: f.only_with_deals || undefined,
      only_no_task: f.only_no_task || undefined,
    }
    return params
  }

  function buildCompanyParams(): CompanyListParams {
    const f = overlayFilters.value
    const params: CompanyListParams = {
      page: page.value,
      per_page: perPage.value,
      search: filter.value.search || undefined,
      // Sorting
      sort_by: sortByCompany.value ?? undefined,
      sort_dir: sortByCompany.value ? sortDir.value : undefined,
      // Multi-value
      owner_ids: f.owner_ids.length ? f.owner_ids : undefined,
      author_ids: f.author_ids.length ? f.author_ids : undefined,
      company_type_ids: f.company_type_ids.length ? f.company_type_ids : undefined,
      category_code: f.categories.length ? f.categories : undefined,
      sources: f.sources.length ? f.sources : undefined,
      tags: f.tags.length ? f.tags : (filter.value.tags.length ? filter.value.tags : undefined),
      // Single-value
      country_code: f.country_code ?? filter.value.country_code ?? undefined,
      city: f.city || undefined,
      engagement_tier: f.engagement_tier ?? undefined,
      // Date ranges
      created_from: f.created_range?.[0] ? isoFromDate(f.created_range[0]) : undefined,
      created_to: f.created_range?.[1] ? isoFromDate(f.created_range[1]) : undefined,
      last_touch_from: f.last_touch_range?.[0] ? isoFromDate(f.last_touch_range[0]) : undefined,
      last_touch_to: f.last_touch_range?.[1] ? isoFromDate(f.last_touch_range[1]) : undefined,
      // Presets
      only_mine: f.only_mine || undefined,
      only_active: f.only_active || undefined,
      only_with_deals: f.only_with_deals || undefined,
      only_no_task: f.only_no_task || undefined,
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
    else if (key === 'engagement_tier') { f.engagement_tier = null }
    else if (key === 'country') { f.country_code = null }
    else if (key === 'city') { f.city = '' }
    else if (key === 'position') { f.position = '' }
    else if (key === 'open_deals') { f.open_deals_min = null; f.open_deals_max = null }
    else if (key.startsWith('owner_')) {
      const id = parseInt(key.slice(6))
      f.owner_ids = f.owner_ids.filter((x) => x !== id)
    } else if (key.startsWith('author_')) {
      const id = parseInt(key.slice(7))
      f.author_ids = f.author_ids.filter((x) => x !== id)
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

  /**
   * Toggle sort on a column field. Cycles: none → asc → desc → none.
   * Uses the sort maps to convert field → backend sort_by value.
   */
  function onSort(field: string) {
    if (entityType.value === 'contact') {
      const backendKey = CONTACT_SORT_MAP[field]
      if (!backendKey) return
      if (sortByContact.value === backendKey) {
        if (sortDir.value === 'asc') {
          sortDir.value = 'desc'
        } else {
          sortByContact.value = null
          sortDir.value = 'asc'
        }
      } else {
        sortByContact.value = backendKey
        sortDir.value = 'asc'
      }
    } else {
      const backendKey = COMPANY_SORT_MAP[field]
      if (!backendKey) return
      if (sortByCompany.value === backendKey) {
        if (sortDir.value === 'asc') {
          sortDir.value = 'desc'
        } else {
          sortByCompany.value = null
          sortDir.value = 'asc'
        }
      } else {
        sortByCompany.value = backendKey
        sortDir.value = 'asc'
      }
    }
    page.value = 1
    void load()
  }

  /** Current active sort field (backend key) */
  const activeSortBy = computed(() =>
    entityType.value === 'contact' ? sortByContact.value : sortByCompany.value,
  )

  watch(entityType, () => {
    // Reset sort when switching entity type
    sortByContact.value = null
    sortByCompany.value = null
    sortDir.value = 'asc'
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
    activeSortBy,
    sortDir,
    load,
    applyFilter,
    applyOverlayFilters,
    resetFilter,
    resetOverlayFilters,
    removeChipFilter,
    onPageChange,
    onSort,
    ensureDirectories,
  }
}
