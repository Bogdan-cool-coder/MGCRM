/**
 * useTaskBoard — composable for personal task kanban in the Tasks section.
 *
 * Delegates all server-state to `useMyTasksStore` (single source of truth).
 * Mutations (complete / reschedule) patch the shared store so the list view
 * stays consistent without a remount.
 */
import { ref, computed } from 'vue'
import { activityApi } from '@/api/activity'
import { useMutation } from '@/composables/async/useMutation'
import { OPERATIONAL_TZ } from '@/utils/activity'
import { useMyTasksStore, ALL_BOARD_BUCKETS } from '@/stores/myTasksStore'
import type { MyBoardBucket } from '@/entities/activity'

export type TaskScope = 'day' | 'week' | 'month'

export interface TaskBucket {
  key: MyBoardBucket
  tasks: import('@/entities/activity').MyBoardActivityDto[]
}

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

  if (bucket === 'later') {
    // "Later" = two weeks from now (next_week + 7 days)
    const dayIdx = now.getDay()
    const daysToNextMon = dayIdx === 0 ? 1 : 8 - dayIdx
    return tzDate(new Date(now.getTime() + (daysToNextMon + 7) * 86_400_000))
  }

  // Fallback (overdue is not a valid drop target)
  return tzDate(now)
}

export function useTaskBoard() {
  const store = useMyTasksStore()
  const completeMutation = useMutation()
  const rescheduleMutation = useMutation()

  // scope is controlled by the parent (TasksTopBar); keep ref for backward compat
  const scope = ref<TaskScope>('month')
  const searchQuery = ref('')

  const bucketsData = computed((): TaskBucket[] => {
    const q = searchQuery.value.toLowerCase()

    return ALL_BOARD_BUCKETS.map((key) => {
      let tasks = store.serverBuckets[key] ?? []
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

  async function load() {
    await store.loadBoard()
  }

  async function completeTask(id: number) {
    // Optimistic: remove from board
    store.boardRemove(id)

    try {
      await completeMutation.run(async () => { await activityApi.completeActivity(id) })
    } catch {
      // Rollback: reload from server
      await store.loadBoard()
      throw new Error('complete_failed')
    }
  }

  /**
   * Reschedule a task by dragging it into a bucket.
   *
   * 10.3: Preserves the task's original TIME-OF-DAY when moving to a new day.
   * Sends a full ISO datetime (new date + original time) to avoid resetting
   * the time to 00:00 on the server.
   *
   * Optimistically moves the card between buckets in the store, then calls
   * the reschedule API. On error it rolls back by reloading from the server.
   */
  async function rescheduleTask(id: number, targetBucket: MyBoardBucket): Promise<void> {
    const newDateStr = bucketToDueDate(targetBucket) // YYYY-MM-DD in operational TZ

    // Find the task in the store to preserve its original time component
    let dueAtIso: string
    const existingTask = Object.values(store.serverBuckets)
      .flat()
      .find((t) => t.id === id)

    if (existingTask?.due_at) {
      // Extract time component from original due_at and combine with new date
      const origDate = new Date(existingTask.due_at)
      const hours = String(origDate.getUTCHours()).padStart(2, '0')
      const minutes = String(origDate.getUTCMinutes()).padStart(2, '0')
      const seconds = String(origDate.getUTCSeconds()).padStart(2, '0')
      // Use operational TZ offset (+04:00) — keep original UTC time → combine with new date in +04:00
      dueAtIso = `${newDateStr}T${hours}:${minutes}:${seconds}+04:00`
    } else {
      // No original time — use start of day in operational TZ
      dueAtIso = `${newDateStr}T09:00:00+04:00`
    }

    // Optimistic move in the shared store
    store.boardMove(id, targetBucket, dueAtIso)

    try {
      await rescheduleMutation.run(async () => {
        await activityApi.rescheduleActivity(id, { dueAt: dueAtIso })
      })
    } catch {
      // Rollback
      await store.loadBoard()
      throw new Error('reschedule_failed')
    }
  }

  /**
   * Optimistically remove a task from the board by ID (page-level bulk actions).
   * Delegates to store so list view is also updated.
   */
  function removeLocalById(id: number) {
    store.boardRemove(id)
  }

  /**
   * Optimistically patch specific fields of a task in the board.
   * Delegates to store so the list view is also updated (where applicable).
   */
  function patchLocalById(id: number, patch: Partial<import('@/entities/activity').MyBoardActivityDto>) {
    store.boardPatch(id, patch)
  }

  return {
    loading: computed(() => store.boardLoading),
    error: computed(() => store.boardError),
    scope,
    searchQuery,
    bucketsData,
    allDone: computed(() => store.allBoardDone),
    totalVisible,
    load,
    completeTask,
    rescheduleTask,
    removeLocalById,
    patchLocalById,
  }
}
