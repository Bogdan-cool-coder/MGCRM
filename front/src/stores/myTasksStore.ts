/**
 * myTasksStore — single source of truth for MyTasksPage (list + kanban).
 *
 * Both the list view and the kanban board read from ONE reactive dataset so
 * mutations (complete / reopen / delete / pin / reschedule / status-patch)
 * are immediately reflected in both views without a remount or extra fetch.
 *
 * Architecture:
 *  - `serverBuckets`  — board bucket map from GET /my-board (used by kanban)
 *  - `listItems`      — paginated page from GET /presets/* (used by list)
 *  - All mutations patch BOTH `serverBuckets` AND `listItems` in one place.
 *  - The list view still uses its own load/paginate helpers (pagination is
 *    per-view), but delegates every mutation to this store so the board
 *    stays consistent.
 */
import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import { activityApi } from '@/api/activity'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import type { ActivityDto, MyBoardActivityDto, MyBoardBucket } from '@/entities/activity'

export const ALL_BOARD_BUCKETS: MyBoardBucket[] = [
  'overdue',
  'today',
  'tomorrow',
  'this_week',
  'next_week',
  'later',
]

export const useMyTasksStore = defineStore('myTasks', () => {
  // ── Board state (kanban) ─────────────────────────────────────────────────────
  const serverBuckets = ref<Partial<Record<MyBoardBucket, MyBoardActivityDto[]>>>({})
  const boardResource = useAsyncResource<MyBoardActivityDto[]>(() => [])

  const boardLoading = computed(() => boardResource.loading.value)
  const boardError = computed(() => boardResource.error.value)

  /** Flat list of all tasks across all board buckets (for allDone / select-all). */
  const allBoardTasks = computed((): MyBoardActivityDto[] =>
    ALL_BOARD_BUCKETS.flatMap((k) => serverBuckets.value[k] ?? []),
  )

  const allBoardDone = computed(
    () => !boardLoading.value && allBoardTasks.value.length === 0,
  )

  // ── List state (paginated) ────────────────────────────────────────────────────
  const listItems = ref<ActivityDto[]>([])
  const listTotal = ref(0)
  // listLoading is intentionally NOT here — callers use their own useAsyncResource
  // to track loading state per-view. The store holds only the data.

  // ── Board mutations ───────────────────────────────────────────────────────────

  /** Remove a task from the board by id (across all buckets). */
  function boardRemove(id: number) {
    for (const key of ALL_BOARD_BUCKETS) {
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
  }

  /** Patch specific fields of a task in the board. */
  function boardPatch(id: number, patch: Partial<MyBoardActivityDto>) {
    for (const key of ALL_BOARD_BUCKETS) {
      const bucket = serverBuckets.value[key]
      if (bucket) {
        const idx = bucket.findIndex((t) => t.id === id)
        if (idx >= 0) {
          serverBuckets.value = {
            ...serverBuckets.value,
            [key]: [
              ...bucket.slice(0, idx),
              { ...(bucket[idx] as MyBoardActivityDto), ...patch },
              ...bucket.slice(idx + 1),
            ],
          }
          break
        }
      }
    }
  }

  /** Move a task from its current bucket into targetBucket (optimistic reschedule). */
  function boardMove(id: number, targetBucket: MyBoardBucket, dueAtIso: string) {
    let movedTask: MyBoardActivityDto | null = null
    for (const key of ALL_BOARD_BUCKETS) {
      const bucket = serverBuckets.value[key]
      if (bucket) {
        const idx = bucket.findIndex((t) => t.id === id)
        if (idx >= 0) {
          movedTask = { ...(bucket[idx] as MyBoardActivityDto), due_at: dueAtIso }
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
  }

  // ── List mutations ────────────────────────────────────────────────────────────

  /** Remove a task from the list by id. */
  function listRemove(id: number) {
    listItems.value = listItems.value.filter((a) => a.id !== id)
    listTotal.value = Math.max(0, listTotal.value - 1)
  }

  /** Update a task in the list. */
  function listUpdate(updated: ActivityDto) {
    listItems.value = listItems.value.map((a) => (a.id === updated.id ? updated : a))
  }

  /** Add a task to the top of the list (guarded by matchesPreset at call-site). */
  function listAdd(activity: ActivityDto) {
    listItems.value = [activity, ...listItems.value]
    listTotal.value += 1
  }

  // ── Cross-view mutations (update both list + board) ───────────────────────────

  /**
   * Remove from BOTH list and board.
   * Use for complete / delete / reopen (when the task leaves the current preset).
   */
  function removeFromBoth(id: number) {
    listRemove(id)
    boardRemove(id)
  }

  /**
   * Re-add to the list after a failed mutation (optimistic rollback).
   * The board rolls back via a full reload at the caller level.
   */
  function listAddBack(activity: ActivityDto) {
    // Only re-insert if the row is not already present (idempotent)
    if (!listItems.value.some((a) => a.id === activity.id)) {
      listItems.value = [activity, ...listItems.value]
      listTotal.value += 1
    }
  }

  // ── Board load ────────────────────────────────────────────────────────────────

  async function loadBoard() {
    await boardResource.run(async () => {
      const r = await activityApi.getMyBoard()
      const normalised = Object.fromEntries(
        ALL_BOARD_BUCKETS.map((k) => [
          k,
          (r.data[k] ?? []).map((t) => ({
            ...t,
            assigned_to: t.responsible ?? t.assigned_to ?? null,
          })),
        ]),
      ) as Partial<Record<MyBoardBucket, MyBoardActivityDto[]>>
      serverBuckets.value = normalised
      return ALL_BOARD_BUCKETS.flatMap((k) => normalised[k] ?? [])
    })
  }

  return {
    // Board
    serverBuckets,
    boardLoading,
    boardError,
    allBoardTasks,
    allBoardDone,
    loadBoard,
    boardRemove,
    boardPatch,
    boardMove,
    // List
    listItems,
    listTotal,
    listRemove,
    listUpdate,
    listAdd,
    listAddBack,
    // Cross-view
    removeFromBoth,
  }
})
