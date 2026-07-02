/**
 * useTasksRealtime — subscribes to personal and/or team task channels and
 * debounce-calls refresh callbacks on activity.* events.
 *
 * Personal tasks: `private('user.{userId}')` — always subscribed when userId is set.
 * Team tasks:     `private('dept.{deptId}.tasks')` — subscribed only when deptId is set.
 *
 * Graceful-degradation: no-ops when Echo is not initialised.
 * Debounce: 400ms per channel to coalesce bursts.
 */

import { onUnmounted } from 'vue'
import { subscribePrivate } from './echo'

type VoidCallback = () => void

const ACTIVITY_EVENTS = ['activity.created', 'activity.status_changed', 'activity.updated', 'activity.deleted'] as const

function makeDebounced(fn: VoidCallback, delay: number): { schedule: VoidCallback; cancel: () => void } {
  let timer: ReturnType<typeof setTimeout> | null = null
  return {
    schedule() {
      if (timer !== null) clearTimeout(timer)
      timer = setTimeout(() => {
        timer = null
        fn()
      }, delay)
    },
    cancel() {
      if (timer !== null) {
        clearTimeout(timer)
        timer = null
      }
    },
  }
}

export interface TasksRealtimeCallbacks {
  /** Called when the personal task list should refresh (user.{id} channel). */
  onPersonalRefresh: VoidCallback
  /** Called when the team task board should refresh (dept.{id}.tasks channel).
   *  Only fired if deptId is provided. */
  onTeamRefresh?: VoidCallback
}

/**
 * Subscribe to personal + (optionally) team task events.
 *
 * @param userId        Current user's ID. Personal channel only when set.
 * @param deptId        Current user's department ID. Team channel when set.
 * @param callbacks     Refresh callbacks.
 * @returns             Cleanup function (also wired to onUnmounted automatically).
 */
export function useTasksRealtime(
  userId: () => number | null,
  deptId: () => number | null,
  callbacks: TasksRealtimeCallbacks,
): () => void {
  const cleanups: Array<() => void> = []

  // ─── Personal channel: user.{userId} ─────────────────────────────────────────
  const uid = userId()
  if (uid) {
    const { schedule, cancel } = makeDebounced(callbacks.onPersonalRefresh, 400)
    const events: Record<string, () => void> = {}
    for (const event of ACTIVITY_EVENTS) {
      events[event] = schedule
    }
    const unsub = subscribePrivate(`user.${uid}`, events)
    cleanups.push(() => { cancel(); unsub() })
  }

  // ─── Team channel: dept.{deptId}.tasks ───────────────────────────────────────
  const did = deptId()
  if (did && callbacks.onTeamRefresh) {
    const onTeam = callbacks.onTeamRefresh
    const { schedule, cancel } = makeDebounced(onTeam, 400)
    const events: Record<string, () => void> = {}
    for (const event of ACTIVITY_EVENTS) {
      events[event] = schedule
    }
    const unsub = subscribePrivate(`dept.${did}.tasks`, events)
    cleanups.push(() => { cancel(); unsub() })
  }

  const cleanup = () => { for (const fn of cleanups) fn() }
  onUnmounted(cleanup)
  return cleanup
}
