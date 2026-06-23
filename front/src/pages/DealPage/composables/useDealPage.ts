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

  /**
   * Silent background reload — fetches fresh deal data WITHOUT setting
   * loading.value to true, so the skeleton is not shown. Used after
   * discount changes (and similar mutations) that need server-computed
   * fields (products_discounted, etc.) without a jarring page-flash.
   */
  async function reloadSilent() {
    try {
      const fresh = await salesApi.getDeal(dealId.value)
      if (resource.data.value) {
        resource.data.value = fresh
      }
    } catch {
      // non-critical: stale data stays visible
    }
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
    reloadSilent,
    updateDealLocal,
  }
}
