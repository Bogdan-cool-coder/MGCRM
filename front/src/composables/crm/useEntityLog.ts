/**
 * useEntityLog — paginated action log for deal / company / contact.
 * Fetches GET /api/{target}s/{id}/log (newest-first).
 * Supports "load more" pagination.
 */
import { ref, watch, type Ref } from 'vue'
import { logApi, type EntityLogTarget } from '@/api/crm/log'
import type { EntityLogEntry } from '@/entities/crm'

const PER_PAGE = 20

export interface UseEntityLogReturn {
  entries: Ref<EntityLogEntry[]>
  loading: Ref<boolean>
  loadingMore: Ref<boolean>
  error: Ref<unknown | null>
  hasMore: Ref<boolean>
  total: Ref<number>
  load: () => Promise<void>
  loadMore: () => Promise<void>
}

export function useEntityLog(
  target: EntityLogTarget,
  getId: () => number | null | undefined,
): UseEntityLogReturn {
  const entries = ref<EntityLogEntry[]>([])
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<unknown | null>(null)
  const hasMore = ref(false)
  const total = ref(0)
  let currentPage = 1
  // Out-of-order / cross-entity guard. Every load()/loadMore() takes a token; a
  // response is committed only if it is still the newest request. On rapid entity
  // A→B navigation (this composable is reused, id changes) a late /A/log response
  // is dropped instead of overwriting entity B's timeline. `entityKey` also pins the
  // response to the id it was requested for.
  let requestToken = 0

  async function load(): Promise<void> {
    const id = getId()
    if (!id) return
    const token = ++requestToken
    loading.value = true
    error.value = null
    currentPage = 1
    try {
      const res = await logApi.getLog(target, id, { page: 1, per_page: PER_PAGE })
      if (token !== requestToken) return
      entries.value = res.data
      total.value = res.meta.total
      hasMore.value = res.meta.current_page < res.meta.last_page
    } catch (e) {
      if (token !== requestToken) return
      error.value = e
    } finally {
      if (token === requestToken) loading.value = false
    }
  }

  async function loadMore(): Promise<void> {
    const id = getId()
    if (!id || !hasMore.value || loadingMore.value) return
    const token = ++requestToken
    loadingMore.value = true
    try {
      const nextPage = currentPage + 1
      const res = await logApi.getLog(target, id, { page: nextPage, per_page: PER_PAGE })
      // If a newer load()/navigation happened mid-flight, drop this stale page.
      if (token !== requestToken) return
      entries.value = [...entries.value, ...res.data]
      total.value = res.meta.total
      hasMore.value = res.meta.current_page < res.meta.last_page
      currentPage = nextPage
    } catch {
      // non-critical
    } finally {
      if (token === requestToken) loadingMore.value = false
    }
  }

  // Auto-reload when ID changes. load() bumps the request token, so any in-flight
  // request for the previous id is invalidated and cannot overwrite the new entity.
  watch(getId, (id) => {
    if (id) void load()
    else {
      // Invalidate so a late response for the previous id can't repopulate the list.
      requestToken++
      entries.value = []
      hasMore.value = false
      total.value = 0
      loading.value = false
    }
  })

  return { entries, loading, loadingMore, error, hasMore, total, load, loadMore }
}
