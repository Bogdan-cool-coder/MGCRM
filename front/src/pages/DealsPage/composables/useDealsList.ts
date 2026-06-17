/**
 * List view composable for DealsPage.
 * Manages paginated table state via useAsyncResource.
 */
import { ref, computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { salesApi } from '@/api/sales'
import type { DealDto, SalesPaginatedResponse } from '@/entities/sales'
import type { DealsFilters } from './useDealsFilters'
import type { Ref } from 'vue'

export function useDealsList(
  filters: Ref<DealsFilters>,
  pipelineId: () => number | null,
) {
  const page = ref(1)
  const perPage = ref(25)

  const resource = useAsyncResource<SalesPaginatedResponse<DealDto>>(() => ({
    data: [],
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: 0,
      from: null,
      to: null,
    },
  }))

  const deals = computed(() => resource.data.value.data)
  const total = computed(() => resource.data.value.meta.total)
  const loading = computed(() => resource.loading.value)
  const error = computed(() => resource.error.value)

  async function load() {
    const pid = pipelineId()
    await resource.run(() =>
      salesApi.getDeals({
        view: 'list',
        pipeline_id: pid ?? undefined,
        stage_id: filters.value.stage_id ?? undefined,
        owner_id: filters.value.owner_id ?? undefined,
        q: filters.value.q || undefined,
        page: page.value,
        per_page: perPage.value,
      }),
    )
  }

  function onPageChange(event: { page: number; rows: number }) {
    page.value = event.page + 1
    perPage.value = event.rows
    void load()
  }

  function resetPage() {
    page.value = 1
  }

  return {
    deals,
    total,
    loading,
    error,
    page,
    perPage,
    load,
    onPageChange,
    resetPage,
  }
}
