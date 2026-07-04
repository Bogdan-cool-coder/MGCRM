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

  // Last-wins token shared by reloadSilent and every commit path below. Bumping
  // it on load()/updateDealLocal invalidates any in-flight silent GET so a late
  // stale response cannot overwrite fresher data.
  let silentToken = 0

  async function load() {
    silentToken++
    await resource.run(() => salesApi.getDeal(dealId.value))
  }

  /**
   * Silent background reload — fetches fresh deal data WITHOUT setting
   * loading.value to true, so the skeleton is not shown. Used after
   * discount changes (and similar mutations) that need server-computed
   * fields (products_discounted, etc.) without a jarring page-flash.
   *
   * Guarded by a last-wins token: reloadSilent races with load(), with other
   * reloadSilent calls (discount + realtime echo) and with local PATCH updates
   * (updateDealLocal). Without the gate a slower/older GET returning late would
   * overwrite a just-saved value — the field visibly "snaps back" to the old
   * value until the next echo. The token drops any response that is no longer
   * the freshest request.
   */
  async function reloadSilent() {
    const token = ++silentToken
    try {
      const fresh = await salesApi.getDeal(dealId.value)
      if (token !== silentToken) return
      if (resource.data.value) {
        resource.data.value = fresh
      }
    } catch {
      // non-critical: stale data stays visible
    }
  }

  function updateDealLocal(updates: Partial<DealDto>) {
    // Invalidate any in-flight silent GET: a local PATCH result is authoritative
    // and must not be overwritten by a slower reloadSilent started before it.
    silentToken++
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
