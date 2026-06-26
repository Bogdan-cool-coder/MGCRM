/**
 * MyTasksPage composable — presets, filters, pagination, counts.
 */
import { ref, computed, watch } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { activityApi } from '@/api/activity'
import { useActivityStore } from '@/stores/activityStore'
import { localDateString, todayInOperationalTz, thisWeekRangeInOperationalTz, dateInOperationalTz } from '@/utils/activity'
import type { ActivityDto, ActivityKind, ActivityStatus, ActivityPriority } from '@/entities/activity'
import type { ActivityPreset } from '@/api/activity'

export type TaskPreset = ActivityPreset | 'all'

// The completed preset uses its own endpoint; keep it out of the "open" presets.
export const COMPLETED_PRESET: TaskPreset = 'completed'

export interface TaskFilters {
  kind: ActivityKind | null
  status: ActivityStatus | null
  priority: ActivityPriority | null
  due_from: Date | null
  due_to: Date | null
  q: string
}

const DEFAULT_FILTERS: TaskFilters = {
  kind: null,
  status: null,
  priority: null,
  due_from: null,
  due_to: null,
  q: '',
}

export function useMyTasks() {
  const activityStore = useActivityStore()

  const activePreset = ref<TaskPreset>('my_tasks')
  const filters = ref<TaskFilters>({ ...DEFAULT_FILTERS })
  const page = ref(1)
  const perPage = ref(25)

  const resource = useAsyncResource<ActivityDto[]>(() => [])
  const total = ref(0)

  const items = computed(() => resource.data.value)
  const loading = computed(() => resource.loading.value)

  const counts = computed(() => activityStore.countsCache)

  async function load() {
    page.value = 1
    await fetchPage()
  }

  async function fetchPage() {
    const params = buildParams()

    if (activePreset.value === 'all') {
      await resource.run(async () => {
        const res = await activityApi.getActivities({
          ...params,
          page: page.value,
          per_page: perPage.value,
        })
        total.value = res.meta.total
        return res.data
      })
    } else {
      // 'completed' uses its own dedicated endpoint (GET /api/activities/presets/completed)
      const presetKey = activePreset.value as ActivityPreset
      await resource.run(async () => {
        const res = await activityApi.getPresetActivities(presetKey, {
          ...params,
          page: page.value,
          per_page: perPage.value,
        })
        total.value = res.meta.total
        return res.data
      })
    }
  }

  function buildParams() {
    const f = filters.value
    return {
      kind: f.kind ? [f.kind] : undefined,
      status: f.status ? [f.status] : undefined,
      priority: f.priority ? [f.priority] : undefined,
      // Use local calendar fields — TZ-safe for Asia/Dubai (F4)
      due_from: f.due_from ? localDateString(f.due_from) : undefined,
      due_to: f.due_to ? localDateString(f.due_to) : undefined,
      q: f.q || undefined,
      sort: 'pinned_first' as const,
    }
  }

  async function onPage(event: { page: number; rows: number }) {
    page.value = event.page + 1
    perPage.value = event.rows
    await fetchPage()
  }

  function resetFilters() {
    filters.value = { ...DEFAULT_FILTERS }
  }

  async function refreshCounts() {
    await activityStore.fetchCounts()
  }

  // Debounce search
  let searchDebounce: ReturnType<typeof setTimeout> | null = null

  watch(
    () => filters.value.q,
    () => {
      if (searchDebounce) clearTimeout(searchDebounce)
      searchDebounce = setTimeout(() => {
        void load()
      }, 400)
    },
  )

  watch(
    () => [
      filters.value.kind,
      filters.value.status,
      filters.value.priority,
      filters.value.due_from,
      filters.value.due_to,
    ],
    () => {
      void load()
    },
  )

  watch(activePreset, () => {
    void load()
  })

  function removeLocal(id: number) {
    resource.data.value = resource.data.value.filter((a) => a.id !== id)
    total.value = Math.max(0, total.value - 1)
  }

  function updateLocal(updated: ActivityDto) {
    resource.data.value = resource.data.value.map((a) => (a.id === updated.id ? updated : a))
  }

  function addLocal(activity: ActivityDto) {
    // Guard: only insert into the current preset list when the new item would
    // actually belong there. Preset tabs with strict criteria (overdue, today,
    // this_week, pinned) should NOT receive items that don't match — the badge
    // count is fetched separately from the API and stays correct either way.
    if (!matchesPreset(activity, activePreset.value)) return
    resource.data.value = [activity, ...resource.data.value]
    total.value += 1
  }

  function matchesPreset(activity: ActivityDto, preset: TaskPreset): boolean {
    if (preset === 'all') return true
    if (preset === 'my_tasks') return true // responsible=me filter is server-side; allow optimistic
    if (preset === 'overdue') {
      // B31: compare day strings in operational tz (Asia/Dubai) so the overdue boundary
      // matches the server's applyPreset — not the browser's local midnight.
      if (!activity.due_at || activity.is_closed || activity.status === 'done') return false
      const dueDayStr = dateInOperationalTz(new Date(activity.due_at))
      return dueDayStr < todayInOperationalTz()
    }
    if (preset === 'today') {
      if (!activity.due_at || activity.is_closed) return false
      // B31: use operational tz day boundary
      return dateInOperationalTz(new Date(activity.due_at)) === todayInOperationalTz()
    }
    if (preset === 'this_week') {
      if (!activity.due_at || activity.is_closed) return false
      // B31: use operational tz week boundaries (Monday–Sunday)
      const dueDayStr = dateInOperationalTz(new Date(activity.due_at))
      const { start, end } = thisWeekRangeInOperationalTz()
      return dueDayStr >= start && dueDayStr <= end
    }
    if (preset === 'pinned') return activity.is_pinned
    if (preset === 'my_orders') return true // server-side filter; allow optimistic
    if (preset === 'completed') return activity.is_closed || activity.status === 'done'
    return true
  }

  return {
    activePreset,
    filters,
    page,
    perPage,
    items,
    total,
    loading,
    counts,
    load,
    onPage,
    resetFilters,
    refreshCounts,
    removeLocal,
    updateLocal,
    addLocal,
  }
}
