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

// Server-supported sort column keys (from backend whitelist)
export type DealSortKey = 'name' | 'country' | 'amount' | 'stage' | 'days_in_stage' | 'last_contact' | 'owner' | 'created'

export interface DealSortState {
  sortBy: DealSortKey | null
  sortDir: 'asc' | 'desc'
}

export function useDealsList(
  filters: Ref<DealsFilters>,
  pipelineId: () => number | null,
) {
  const page = ref(1)
  const perPage = ref(25)

  // ── Sort state ───────────────────────────────────────────────────────────────
  const sortState = ref<DealSortState>({ sortBy: null, sortDir: 'desc' })

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
    const { sortBy, sortDir } = sortState.value
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
        country: f.country || undefined,
        city: f.city || undefined,
        budget_from: f.budget_from ?? undefined,
        budget_to: f.budget_to ?? undefined,
        tags: f.tags.length ? f.tags : undefined,
        created_from: dateRange?.[0] ? dateRange[0].toISOString().slice(0, 10) : undefined,
        created_to: dateRange?.[1] ? dateRange[1].toISOString().slice(0, 10) : undefined,
        page: page.value,
        per_page: perPage.value,
        sort_by: sortBy ?? undefined,
        sort_dir: sortBy ? sortDir : undefined,
      }),
    )
  }

  function onPageChange(event: { page: number; rows: number }) {
    page.value = event.page + 1
    perPage.value = event.rows
    void load()
  }

  /**
   * Cycle sort for a column: if this column is already active, toggle asc/desc.
   * If a different column, switch to it with desc direction.
   */
  function onSort(key: DealSortKey) {
    if (sortState.value.sortBy === key) {
      sortState.value = {
        sortBy: key,
        sortDir: sortState.value.sortDir === 'desc' ? 'asc' : 'desc',
      }
    } else {
      sortState.value = { sortBy: key, sortDir: 'desc' }
    }
    page.value = 1
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
    sortState,
    load,
    onPageChange,
    onSort,
    resetPage,
  }
}
