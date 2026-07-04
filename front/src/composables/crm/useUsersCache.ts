/**
 * Shared singleton cache for the users list (for owner/assignee selects).
 * All components share one fetch; no per-component onMounted fetch storms.
 */
import { ref } from 'vue'
import { usersApi, type UserOptionDto } from '@/api/users'

const users = ref<UserOptionDto[]>([])
const loading = ref(false)
const loaded = ref(false)

export function useUsersCache() {
  async function load(): Promise<void> {
    if (loaded.value || loading.value) return
    loading.value = true
    try {
      users.value = await usersApi.getUsers()
      loaded.value = true
    } catch {
      // non-critical — leave empty list, component renders without options
    } finally {
      loading.value = false
    }
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
