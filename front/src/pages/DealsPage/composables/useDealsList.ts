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
    const f = filters.value
    const dateRange = f.dateRange
    await resource.run(() =>
      salesApi.getDeals({
        view: 'list',
        pipeline_id: pid ?? undefined,
        q: f.q || undefined,
        owner_ids: f.owner_ids.length ? f.owner_ids : undefined,
        stage_ids: f.stage_ids.length ? f.stage_ids : undefined,
        status: f.status ?? undefined,
        only_mine: f.only_mine || undefined,
        only_no_task: f.only_no_task || undefined,
        only_overdue: f.only_overdue || undefined,
        product_q: f.product_q || undefined,
        country: f.region || undefined,
        city: f.city || undefined,
        budget_from: f.budget_from ?? undefined,
        budget_to: f.budget_to ?? undefined,
        tags: f.tags.length ? f.tags : undefined,
        created_from: dateRange?.[0] ? dateRange[0].toISOString().slice(0, 10) : undefined,
        created_to: dateRange?.[1] ? dateRange[1].toISOString().slice(0, 10) : undefined,
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
