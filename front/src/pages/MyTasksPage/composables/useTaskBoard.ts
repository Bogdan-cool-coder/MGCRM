/**
 * useTaskBoard — composable for personal task kanban in the Tasks section.
 * Loads tasks via GET /api/activities/my-board and renders the server's
 * Dubai-tz buckets directly (F4: no client-side re-bucketing in browser-local tz).
 */
import { ref, computed } from 'vue'
import { activityApi } from '@/api/activity'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { OPERATIONAL_TZ } from '@/utils/activity'
import type { MyBoardActivityDto, MyBoardBucket } from '@/entities/activity'

export type TaskScope = 'day' | 'week' | 'month'

export interface TaskBucket {
  key: MyBoardBucket
  tasks: MyBoardActivityDto[]
}

const ALL_BUCKETS: MyBoardBucket[] = ['overdue', 'today', 'tomorrow', 'this_week', 'next_week']

// ── Bucket → due_at computation (Dubai-tz calendar dates) ──────────────────────
/**
 * Compute the target YYYY-MM-DD for a drop into a kanban bucket.
 *
 * Uses the current moment in the operational TZ (Asia/Dubai) to ensure
 * the resulting date string matches what the server's TZ-aware bucketing
 * expects (same logic as bucketMeta in TasksKanbanBoard.vue).
 */
export function bucketToDueDate(bucket: MyBoardBucket): string {
  const now = new Date()

  /** Format a Date as YYYY-MM-DD in the operational TZ */
  function tzDate(d: Date): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: OPERATIONAL_TZ }).format(d)
  }

  if (bucket === 'today') {
    return tzDate(now)
  }

  if (bucket === 'tomorrow') {
    return tzDate(new Date(now.getTime() + 86_400_000))
  }

  if (bucket === 'this_week') {
    // "end-of-week" = Sunday, matching bucketMeta "до {Sunday}"
    const dayIdx = now.getDay() // 0=Sun
    const daysToSunday = dayIdx === 0 ? 0 : 7 - dayIdx
    return tzDate(new Date(now.getTime() + daysToSunday * 86_400_000))
  }

  if (bucket === 'next_week') {
    // Next Monday — the START of next week, used as the reschedule target
    const dayIdx = now.getDay()
    const daysToNextMon = dayIdx === 0 ? 1 : 8 - dayIdx
    return tzDate(new Date(now.getTime() + daysToNextMon * 86_400_000))
  }

  // Fallback (shouldn't happen — overdue is not a valid drop target)
  return tzDate(now)
}

export function useTaskBoard() {
  // Store the server's bucket map directly — keys are already Dubai-tz correct
  const serverBuckets = ref<Partial<Record<MyBoardBucket, MyBoardActivityDto[]>>>({})
  const resource = useAsyncResource<MyBoardActivityDto[]>(() => [])
  const completeMutation = useMutation()
  const rescheduleMutation = useMutation()
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

  /**
   * Reschedule a task by dragging it into a bucket.
   *
   * Optimistically moves the card from its current bucket into targetBucket
   * (mirroring the completeTask pattern), then calls the reschedule API.
   * On error it rolls back by reloading from the server.
   */
  async function rescheduleTask(id: number, targetBucket: MyBoardBucket): Promise<void> {
    const dueAt = bucketToDueDate(targetBucket)

    // Optimistic: find the task in any bucket and splice it into targetBucket
    let movedTask: MyBoardActivityDto | null = null

    for (const key of ALL_BUCKETS) {
      const bucket = serverBuckets.value[key]
      if (bucket) {
        const idx = bucket.findIndex((t) => t.id === id)
        if (idx >= 0) {
          movedTask = { ...(bucket[idx] as MyBoardActivityDto), due_at: `${dueAt}T00:00:00+04:00` }
          serverBuckets.value = {
            ...serverBuckets.value,
            [key]: [...bucket.slice(0, idx), ...bucket.slice(idx + 1)],
          }
          break
        }
      }
    }

    if (movedTask) {
      const target = serverBuckets.value[targetBucket] ?? []
      serverBuckets.value = {
        ...serverBuckets.value,
        [targetBucket]: [...target, movedTask],
      }
    }

    try {
      await rescheduleMutation.run(async () => {
        await activityApi.rescheduleActivity(id, { dueAt })
      })
    } catch {
      // Rollback
      await load()
      throw new Error('reschedule_failed')
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
    rescheduleTask,
  }
}
