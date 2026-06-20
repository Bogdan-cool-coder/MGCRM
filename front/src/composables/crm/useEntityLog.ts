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

  async function load(): Promise<void> {
    const id = getId()
    if (!id) return
    loading.value = true
    error.value = null
    currentPage = 1
    try {
      const res = await logApi.getLog(target, id, { page: 1, per_page: PER_PAGE })
      entries.value = res.data
      total.value = res.meta.total
      hasMore.value = res.meta.current_page < res.meta.last_page
    } catch (e) {
      error.value = e
    } finally {
      loading.value = false
    }
  }

  async function loadMore(): Promise<void> {
    const id = getId()
    if (!id || !hasMore.value || loadingMore.value) return
    loadingMore.value = true
    try {
      const nextPage = currentPage + 1
      const res = await logApi.getLog(target, id, { page: nextPage, per_page: PER_PAGE })
      entries.value = [...entries.value, ...res.data]
      total.value = res.meta.total
      hasMore.value = res.meta.current_page < res.meta.last_page
      currentPage = nextPage
    } catch {
      // non-critical
    } finally {
      loadingMore.value = false
    }
  }

  // Auto-reload when ID changes
  watch(getId, (id) => {
    if (id) void load()
    else {
      entries.value = []
      hasMore.value = false
      total.value = 0
    }
  })

  return { entries, loading, loadingMore, error, hasMore, total, load, loadMore }
}
