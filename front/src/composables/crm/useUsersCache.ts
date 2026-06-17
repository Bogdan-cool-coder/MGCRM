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

  return { users, loading, load }
}
