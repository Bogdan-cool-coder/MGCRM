/**
 * useTaskBoard — composable for personal task kanban in the Tasks section.
 * Loads tasks via GET /api/activities/my-board and renders the server's
 * Dubai-tz buckets directly (F4: no client-side re-bucketing in browser-local tz).
 */
import { ref, computed } from 'vue'
import { activityApi } from '@/api/activity'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import type { MyBoardActivityDto, MyBoardBucket } from '@/entities/activity'

export type TaskScope = 'day' | 'week' | 'month'

export interface TaskBucket {
  key: MyBoardBucket
  tasks: MyBoardActivityDto[]
}

const ALL_BUCKETS: MyBoardBucket[] = ['overdue', 'today', 'tomorrow', 'this_week', 'next_week']

export function useTaskBoard() {
  // Store the server's bucket map directly — keys are already Dubai-tz correct
  const serverBuckets = ref<Partial<Record<MyBoardBucket, MyBoardActivityDto[]>>>({})
  const resource = useAsyncResource<MyBoardActivityDto[]>(() => [])
  const completeMutation = useMutation()
  // scope is now controlled by the parent (TasksTopBar); keep ref for backward compat
  const scope = ref<TaskScope>('month')
  const searchQuery = ref('')

  const allTasks = computed(() =>
    // Flat list for allDone check (all buckets, all tasks)
    ALL_BUCKETS.flatMap((k) => serverBuckets.value[k] ?? []),
  )

  // Returns ALL bucket data (unfiltered by scope). Scope-filtering is done in
  // TasksKanbanBoard which receives scope as a prop from the page.
  const bucketsData = computed((): TaskBucket[] => {
    const q = searchQuery.value.toLowerCase()

    return ALL_BUCKETS.map((key) => {
      let tasks = serverBuckets.value[key] ?? []
      if (q) {
        tasks = tasks.filter(
          (t) =>
            (t.title ?? '').toLowerCase().includes(q) ||
            (t.body ?? t.description ?? '').toLowerCase().includes(q),
        )
      }
      return { key, tasks }
    })
  })

  const totalVisible = computed(() => bucketsData.value.reduce((s, b) => s + b.tasks.length, 0))

  const allDone = computed(
    () => !resource.loading.value && allTasks.value.length === 0,
  )

  async function load() {
    await resource.run(
      async () => {
        const r = await activityApi.getMyBoard()
        // Normalise: backend sends `responsible`, DTO also has `assigned_to` alias
        // for backward compat with old TaskCard internals. Both fields are set.
        const normalised = Object.fromEntries(
          ALL_BUCKETS.map((k) => [
            k,
            (r.data[k] ?? []).map((t) => ({
              ...t,
              // Ensure assigned_to mirrors responsible so both work in components
              assigned_to: t.responsible ?? t.assigned_to ?? null,
            })),
          ]),
        ) as Partial<Record<MyBoardBucket, MyBoardActivityDto[]>>
        // Store server buckets directly — TZ-correct (F4)
        serverBuckets.value = normalised
        // Return flat list so resource.loading/error tracking works
        return ALL_BUCKETS.flatMap((k) => normalised[k] ?? [])
      },
    )
  }

  async function completeTask(id: number) {
    // Optimistic: remove from whichever server bucket contains this task
    for (const key of ALL_BUCKETS) {
      const bucket = serverBuckets.value[key]
      if (bucket) {
        const idx = bucket.findIndex((t) => t.id === id)
        if (idx >= 0) {
          serverBuckets.value = {
            ...serverBuckets.value,
            [key]: [...bucket.slice(0, idx), ...bucket.slice(idx + 1)],
          }
          break
        }
      }
    }

    try {
      await completeMutation.run(async () => { await activityApi.completeActivity(id) })
    } catch {
      // Rollback: reload
      await load()
      throw new Error('complete_failed')
    }
  }

  return {
    loading: computed(() => resource.loading.value),
    error: computed(() => resource.error.value),
    scope,
    searchQuery,
    bucketsData,
    allDone,
    totalVisible,
    load,
    completeTask,
  }
}
