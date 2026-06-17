import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { catalogApi } from '@/api/catalog'
import { getApiErrorMessage } from '@/utils/errors'
import type { ProductDto, ProductGroupDto, CatalogPaginatedResponse } from '@/entities/catalog'

export interface ProductsFilter {
  q: string
  group_id: number | null
  pricing_type: string | null
  active_only: boolean | null
}

const DEFAULT_FILTER: ProductsFilter = {
  q: '',
  group_id: null,
  pricing_type: null,
  active_only: true,
}

const emptyPage = (): CatalogPaginatedResponse<ProductDto> => ({
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
})

export const useProductsPageData = () => {
  const { t } = useI18n()
  const toast = useToast()

  const page = ref(1)
  const perPage = ref(25)
  const filter = ref<ProductsFilter>({ ...DEFAULT_FILTER })

  const productsResource = useAsyncResource<CatalogPaginatedResponse<ProductDto>>(emptyPage)
  const groupsResource = useAsyncResource<ProductGroupDto[]>(() => [])

  const loading = computed(() => productsResource.loading.value)
  const products = computed(() => productsResource.data.value.data)
  const total = computed(() => productsResource.data.value.meta.total)
  const groups = computed(() => groupsResource.data.value)

  const isFiltered = computed(
    () =>
      !!filter.value.q ||
      filter.value.group_id !== null ||
      filter.value.pricing_type !== null ||
      filter.value.active_only !== true,
  )

  async function load() {
    const params = {
      page: page.value,
      per_page: perPage.value,
      q: filter.value.q || undefined,
      group_id: filter.value.group_id ?? undefined,
      pricing_type: filter.value.pricing_type ?? undefined,
      active_only:
        filter.value.active_only !== null ? filter.value.active_only : undefined,
    }
    try {
      await productsResource.run(() => catalogApi.getProducts(params))
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('catalog.products.page.empty.title'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    }
  }

  async function loadGroups() {
    if (groupsResource.data.value.length > 0) return
    try {
      await groupsResource.run(() => catalogApi.getProductGroups({ active_only: true }))
    } catch {
      // non-critical — groups are optional filter
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

  function onPageChange(event: { page: number; rows: number }) {
    page.value = event.page + 1
    perPage.value = event.rows
    void load()
  }

  // Reload when perPage changes
  watch(perPage, () => {
    page.value = 1
    void load()
  })

  return {
    page,
    perPage,
    filter,
    loading,
    products,
    total,
    groups,
    isFiltered,
    load,
    loadGroups,
    applyFilter,
    resetFilter,
    onPageChange,
  }
}
