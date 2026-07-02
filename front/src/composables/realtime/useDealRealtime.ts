/**
 * useDealRealtime — subscribes to `private('deal.{id}')` and
 * fires refetch callbacks on relevant broadcast events.
 *
 * Usage: call from DealPage index.vue onMounted/onUnmounted.
 * The composable is stateless — all data fetching is delegated back to the
 * existing page composables (useDealFeed.load, useDealPage.reloadSilent)
 * via the provided callbacks.
 *
 * Graceful-degradation: if Echo is not initialised (Reverb unconfigured or
 * unreachable), subscribePrivate is a no-op and the returned cleanup is a no-op.
 */

import { onUnmounted } from 'vue'
import { subscribePrivate } from './echo'

type VoidCallback = () => void

export interface DealRealtimeCallbacks {
  /** Called when a feed item (activity.*) or deal header (deal.updated|stage_changed) changes. */
  onFeedRefresh: VoidCallback
  /** Called when deal header data changes (deal.updated|stage_changed). */
  onDealRefresh: VoidCallback
}

/**
 * Subscribe to live deal-card events.
 *
 * @param dealId      Reactive getter — channel name is evaluated once on call.
 * @param callbacks   Functions to invoke on each event type.
 * @returns           Cleanup function (also wired to onUnmounted automatically).
 */
export function useDealRealtime(
  dealId: () => number,
  callbacks: DealRealtimeCallbacks,
): () => void {
  const channel = `deal.${dealId()}`

  const unsubscribe = subscribePrivate(channel, {
    // Activity events — fan out to the entity channel
    'activity.created': () => {
      callbacks.onFeedRefresh()
    },
    'activity.status_changed': () => {
      callbacks.onFeedRefresh()
    },
    'activity.updated': () => {
      callbacks.onFeedRefresh()
    },
    'activity.deleted': () => {
      callbacks.onFeedRefresh()
    },
    // Deal-level changes — refresh header + feed
    'deal.updated': () => {
      callbacks.onDealRefresh()
      callbacks.onFeedRefresh()
    },
    'deal.stage_changed': () => {
      callbacks.onDealRefresh()
      callbacks.onFeedRefresh()
    },
  })

  onUnmounted(unsubscribe)

  return unsubscribe
}
