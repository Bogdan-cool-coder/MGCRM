/**
 * useTaskBoard — composable for personal task kanban in the Tasks section.
 * Loads tasks via GET /api/activities/my-board and groups them into deadline buckets.
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

function getBucket(task: MyBoardActivityDto): MyBoardBucket {
  if (task.is_overdue) return 'overdue'
  if (!task.due_at) return 'this_week'

  const due = new Date(task.due_at)
  const now = new Date()

  const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const tomorrowStart = new Date(todayStart)
  tomorrowStart.setDate(tomorrowStart.getDate() + 1)
  const dayAfterTomorrow = new Date(tomorrowStart)
  dayAfterTomorrow.setDate(dayAfterTomorrow.getDate() + 1)

  // This week: Monday to Sunday
  const dayOfWeek = todayStart.getDay() === 0 ? 6 : todayStart.getDay() - 1 // 0=Mon
  const weekStart = new Date(todayStart)
  weekStart.setDate(weekStart.getDate() - dayOfWeek)
  const weekEnd = new Date(weekStart)
  weekEnd.setDate(weekEnd.getDate() + 7)

  const nextWeekEnd = new Date(weekEnd)
  nextWeekEnd.setDate(nextWeekEnd.getDate() + 7)

  if (due < tomorrowStart) return 'today'
  if (due < dayAfterTomorrow) return 'tomorrow'
  if (due < weekEnd) return 'this_week'
  if (due < nextWeekEnd) return 'next_week'
  return 'next_week'
}

function bucketsForScope(scope: TaskScope): MyBoardBucket[] {
  if (scope === 'day') return ['overdue', 'today', 'tomorrow']
  if (scope === 'week') return ['overdue', 'today', 'tomorrow', 'this_week']
  return ALL_BUCKETS
}

export function useTaskBoard() {
  const resource = useAsyncResource<MyBoardActivityDto[]>(() => [])
  const completeMutation = useMutation()
  const scope = ref<TaskScope>('week')
  const searchQuery = ref('')

  const allTasks = computed(() => resource.data.value ?? [])

  const bucketsData = computed((): TaskBucket[] => {
    const q = searchQuery.value.toLowerCase()
    const visibleBuckets = bucketsForScope(scope.value)

    return visibleBuckets.map((key) => {
      let tasks = allTasks.value.filter((t) => getBucket(t) === key)
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
    () => !resource.loading.value && totalVisible.value === 0 && allTasks.value.length === 0,
  )

  async function load() {
    await resource.run(
      () =>
        activityApi.getMyBoard().then((r) => Object.values(r.data).flat() as MyBoardActivityDto[]),
      {
        commit: (result) => {
          resource.data.value = result
        },
      },
    )
  }

  async function completeTask(id: number) {
    // Optimistic removal
    const idx = (resource.data.value ?? []).findIndex((t) => t.id === id)
    if (idx >= 0) {
      resource.data.value = [
        ...(resource.data.value ?? []).slice(0, idx),
        ...(resource.data.value ?? []).slice(idx + 1),
      ]
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
