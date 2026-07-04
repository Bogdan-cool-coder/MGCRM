/**
 * Session teardown — reset all user-scoped Pinia stores on logout / 401.
 *
 * Clearing only the user store leaked the previous user's data into the next
 * session: the other data stores (tasks, deals, notifications, directories, inbox, …)
 * plus the persisted `recentRoutes` navigation history survived a logout.
 *
 * This is the single teardown point, called from BOTH the logout action and the
 * 401 interceptor. It resets every DATA store to its code-initial state (via the
 * $reset() patched on by storeResetPlugin) while PRESERVING device-level user
 * preferences (theme, density, sidebar/orbit layout) which are not user-scoped
 * secrets. The user store itself is cleared by the caller.
 */
import type { Pinia } from 'pinia'
import { useActivityStore } from '@/stores/activityStore'
import { useApprovalsStore } from '@/stores/approvalsStore'
import { useDirectoriesStore } from '@/stores/directories'
import { useInboxStore } from '@/stores/inboxStore'
import { useMyTasksStore } from '@/stores/myTasksStore'
import { useNotificationsStore } from '@/stores/notificationsStore'
import { useOnboardingStore } from '@/stores/onboardingStore'
import { useSalesStore } from '@/stores/salesStore'
import { useLayoutStore } from '@/stores/layout'
import { useUsersCache } from '@/composables/crm/useUsersCache'

/**
 * Reset user-scoped stores + clear persisted recent-routes history.
 * Preserves theme / density / layout-appearance preference stores.
 */
export function resetAllStores(pinia: Pinia): void {
  // Stop background pollers first so no in-flight fetch re-populates a store
  // right after it is reset (both stop* are idempotent).
  const notifications = useNotificationsStore(pinia)
  const inbox = useInboxStore(pinia)
  notifications.stopPolling()
  inbox.stopPolling()

  // Reset every data store to its code-initial state.
  useActivityStore(pinia).$reset()
  useApprovalsStore(pinia).$reset()
  useDirectoriesStore(pinia).$reset()
  inbox.$reset()
  useMyTasksStore(pinia).$reset()
  notifications.$reset()
  useOnboardingStore(pinia).$reset()
  useSalesStore(pinia).$reset()

  // Layout store is a preference store (sidebar/orbit/nav-mode are device-level
  // and should survive logout) — but recentRoutes is user navigation history and
  // must NOT leak into the next session. Clear only that slice; persist plugin
  // writes the cleared value back to localStorage automatically.
  useLayoutStore(pinia).recentRoutes = []

  // The users cache is a module-level singleton (not a Pinia store), so $reset
  // above doesn't touch it — invalidate explicitly so the previous session's
  // user list can't bleed into the next login's owner/assignee selects.
  useUsersCache().invalidate()
}
