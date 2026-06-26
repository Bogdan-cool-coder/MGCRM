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

function bucketsForScope(scope: TaskScope): MyBoardBucket[] {
  if (scope === 'day') return ['overdue', 'today', 'tomorrow']
  if (scope === 'week') return ['overdue', 'today', 'tomorrow', 'this_week']
  return ALL_BUCKETS
}

export function useTaskBoard() {
  // Store the server's bucket map directly — keys are already Dubai-tz correct
  const serverBuckets = ref<Partial<Record<MyBoardBucket, MyBoardActivityDto[]>>>({})
  const resource = useAsyncResource<MyBoardActivityDto[]>(() => [])
  const completeMutation = useMutation()
  const scope = ref<TaskScope>('week')
  const searchQuery = ref('')

  const allTasks = computed(() =>
    // Flat list for allDone check (all buckets, all tasks)
    ALL_BUCKETS.flatMap((k) => serverBuckets.value[k] ?? []),
  )

  const bucketsData = computed((): TaskBucket[] => {
    const q = searchQuery.value.toLowerCase()
    const visibleBuckets = bucketsForScope(scope.value)

    return visibleBuckets.map((key) => {
      let tasks = serverBuckets.value[key] ?? []
      if (q) {
        tasks = tasks.filter(
          (t) =>
            (t.title ?? '').toLowerCase().includes(q) ||
            (t.description ?? '').toLowerCase().includes(q),
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
        // Store server buckets directly — TZ-correct (F4)
        serverBuckets.value = r.data
        // Return flat list so resource.loading/error tracking works
        return ALL_BUCKETS.flatMap((k) => r.data[k] ?? [])
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
