/**
 * Shared singleton cache for the users list (for owner/assignee selects).
 * All components share one fetch; no per-component onMounted fetch storms.
 */
import { ref } from 'vue'
import { usersApi, type UserOptionDto } from '@/api/users'

const users = ref<UserOptionDto[]>([])
const loading = ref(false)
const loaded = ref(false)

// The single in-flight fetch. Concurrent callers (multiple selects mounting on
// the same tick) all await THIS promise instead of each firing their own GET —
// true single-flight, so a page never produces a `/api/users` request storm.
let inFlight: Promise<void> | null = null

export function useUsersCache() {
  function load(): Promise<void> {
    if (loaded.value) return Promise.resolve()
    if (inFlight) return inFlight

    loading.value = true
    inFlight = (async () => {
      try {
        users.value = await usersApi.getUsers()
        loaded.value = true
      } catch {
        // non-critical — leave empty list, component renders without options
      } finally {
        loading.value = false
        inFlight = null
      }
    })()
    return inFlight
  }

  /**
   * Drop the cache so the next `load()` refetches. Call after admin CRUD on
   * users (create / update / deactivate / reactivate) so owner/assignee selects
   * across the app pick up new / renamed / deactivated users without a full SPA
   * reload. Also called from resetAllStores on logout (this is a module-level
   * singleton, not a Pinia store, so it must be reset explicitly).
   */
  function invalidate(): void {
    loaded.value = false
  }

  /** Force an immediate refetch (invalidate + load). */
  async function reload(): Promise<void> {
    invalidate()
    await load()
  }

  return { users, loading, load, invalidate, reload }
}
