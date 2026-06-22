/**
 * KPI aggregate composable for DealsPage.
 * Fetches whole-funnel counts from GET /api/deals/kpi using the same
 * active filters as the list/board (pagination params are intentionally excluded).
 */
import { computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { salesApi } from '@/api/sales'
import type { DealKpiDto } from '@/entities/sales'
import type { DealsFilters } from './useDealsFilters'
import type { Ref } from 'vue'

const NULL_KPI: DealKpiDto = {
  pipeline_id: null,
  in_work: 0,
  cat_l: 0,
  cat_m: 0,
  cat_s: 0,
  won: 0,
  no_task: 0,
  overdue: 0,
}

export function useDealsKpi(
  filters: Ref<DealsFilters>,
  pipelineId: () => number | null,
) {
  const resource = useAsyncResource<DealKpiDto>(() => NULL_KPI)

  const kpi = computed(() => resource.data.value)
  const loading = computed(() => resource.loading.value)

  async function load() {
    const pid = pipelineId()
    const f = filters.value
    const dateRange = f.dateRange
    await resource.run(() =>
      salesApi.getDealKpi({
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
      }),
    )
  }

  return {
    kpi,
    loading,
    load,
  }
}
