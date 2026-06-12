/**
 * Main deal page data composable — loads DealDto (with products + contacts).
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { salesApi } from '@/api/sales'
import type { DealDto } from '@/entities/sales'

export function useDealPage() {
  const route = useRoute()
  const dealId = computed(() => Number(route.params.id))

  const resource = useAsyncResource<DealDto | null>(() => null)

  const deal = computed(() => resource.data.value)
  const loading = computed(() => resource.loading.value)
  const error = computed(() => resource.error.value)

  async function load() {
    await resource.run(() => salesApi.getDeal(dealId.value))
  }

  function updateDealLocal(updates: Partial<DealDto>) {
    if (resource.data.value) {
      resource.data.value = { ...resource.data.value, ...updates }
    }
  }

  return {
    dealId,
    deal,
    loading,
    error,
    load,
    updateDealLocal,
  }
}
