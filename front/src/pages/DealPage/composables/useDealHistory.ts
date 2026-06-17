/**
 * Deal stage history composable.
 */
import { computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { salesApi } from '@/api/sales'
import type { DealStageHistoryDto } from '@/entities/sales'

export function useDealHistory(dealId: () => number) {
  const resource = useAsyncResource<DealStageHistoryDto[]>(() => [])

  const history = computed(() => resource.data.value)
  const loading = computed(() => resource.loading.value)

  async function load() {
    await resource.run(() => salesApi.getDealHistory(dealId()))
  }

  return {
    history,
    loading,
    load,
  }
}
