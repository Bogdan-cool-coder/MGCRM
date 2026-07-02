/**
 * useContactsRealtime — subscribes to `private('dept.{deptId}.contacts')` and
 * debounce-calls a list refresh on company.* and contact.* events.
 *
 * The contacts list shows both companies and contacts (individual persons).
 * Per the contract, both company and contact events fan out to this channel.
 *
 * Graceful-degradation: no-ops when Echo is not initialised.
 * Debounce: 400ms to coalesce bursts (e.g. bulk imports).
 */

import { onUnmounted } from 'vue'
import { subscribePrivate } from './echo'

type VoidCallback = () => void

/**
 * Subscribe to the department contacts channel.
 *
 * @param deptId      The user's department ID. If null/0, subscription is skipped.
 * @param onRefresh   Called (debounced) on any company or contact event.
 * @returns           Cleanup function (also wired to onUnmounted automatically).
 */
export function useContactsRealtime(
  deptId: () => number | null,
  onRefresh: VoidCallback,
): () => void {
  const id = deptId()
  if (!id) {
    return () => {}
  }

  const channel = `dept.${id}.contacts`

  let debounceTimer: ReturnType<typeof setTimeout> | null = null

  function scheduleRefresh() {
    if (debounceTimer !== null) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
      debounceTimer = null
      onRefresh()
    }, 400)
  }

  const EVENTS = [
    'company.created',
    'company.updated',
    'company.deleted',
    'contact.created',
    'contact.updated',
    'contact.deleted',
  ]

  const eventMap: Record<string, VoidCallback> = {}
  for (const event of EVENTS) {
    eventMap[event] = scheduleRefresh
  }

  const unsubscribe = subscribePrivate(channel, eventMap)

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
