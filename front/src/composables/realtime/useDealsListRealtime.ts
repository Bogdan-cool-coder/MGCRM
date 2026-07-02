/**
 * useDealsListRealtime — subscribes to `private('dept.{deptId}.deals')` and
 * debounce-calls a board/list refresh on deal.created|updated|stage_changed|deleted.
 *
 * Graceful-degradation: if Echo is not initialised (Reverb unconfigured or
 * unreachable), subscribePrivate is a no-op and the returned cleanup is a no-op.
 *
 * Debounce: 400ms to coalesce rapid bursts (e.g. bulk stage-moves).
 */

import { onUnmounted } from 'vue'
import { subscribePrivate } from './echo'

type VoidCallback = () => void

/**
 * Subscribe to the department deals channel.
 *
 * @param deptId       The user's department ID. If null/0 (dept-less user),
 *                     subscription is skipped gracefully.
 * @param onRefresh    Called (debounced) on any deal event in the department.
 * @returns            Cleanup function (also wired to onUnmounted automatically).
 */
export function useDealsListRealtime(
  deptId: () => number | null,
  onRefresh: VoidCallback,
): () => void {
  const id = deptId()
  if (!id) {
    // Dept-less user: no dept channel, nothing to subscribe to.
    return () => {}
  }

  const channel = `dept.${id}.deals`

  let debounceTimer: ReturnType<typeof setTimeout> | null = null

  function scheduleRefresh() {
    if (debounceTimer !== null) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
      debounceTimer = null
      onRefresh()
    }, 400)
  }

  const unsubscribe = subscribePrivate(channel, {
    'deal.created': scheduleRefresh,
    'deal.updated': scheduleRefresh,
    'deal.stage_changed': scheduleRefresh,
    'deal.deleted': scheduleRefresh,
  })

  const cleanup = () => {
    if (debounceTimer !== null) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
    unsubscribe()
  }

  onUnmounted(cleanup)

  return cleanup
}
