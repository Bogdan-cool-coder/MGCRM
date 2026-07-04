/**
 * useTeamBoard — composable for the team task kanban (GET /api/activities/team-board).
 *
 * Intentionally uses its own isolated reactive state (not myTasksStore) because:
 *  - team-board cards belong to other people; optimistic mutations don't apply;
 *  - the personal board (myTasksStore) must not be polluted with team data.
 *
 * Actions available: load (with optional responsible_id / q params), reload,
 * rescheduleTask (drag-and-drop due-date shift).
 *
 * A manager-tier actor (admin/director/manager) IS allowed to reschedule a
 * colleague's task — the backend gates POST /reschedule by the same `update`
 * policy that grants All-scope / department-subtree write access
 * (RescheduleActivityRequest → ActivityPolicy::update). So drag-and-drop here
 * optimistically moves the card and calls the reschedule API, rolling back on
 * error (e.g. a task that moved out of scope → 403). Complete/pin stay off the
 * team board (those actions live on the personal board).
 */
import { ref, computed } from 'vue'
import { activityApi } from '@/api/activity'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { OPERATIONAL_TZ } from '@/utils/activity'
import { bucketToDueDate } from './useTaskBoard'
import type { MyBoardBucket, MyBoardActivityDto } from '@/entities/activity'

export type { MyBoardBucket }

export const ALL_TEAM_BOARD_BUCKETS: MyBoardBucket[] = [
  'overdue',
  'today',
  'tomorrow',
  'this_week',
  'next_week',
  'later',
]

export interface TeamTaskBucket {
  key: MyBoardBucket
  tasks: MyBoardActivityDto[]
}

export interface TeamBoardParams {
  responsible_id?: number | null
  q?: string
  kind?: string | null
  status?: string | null
  priority?: string | null
  due_from?: string | null
  due_to?: string | null
}

export function useTeamBoard() {
  const serverBuckets = ref<Partial<Record<MyBoardBucket, MyBoardActivityDto[]>>>({})
  // boardResource tracks loading/error and gates the commit; serverBuckets is the
  // render source, written only via the token-guarded commit below.
  const boardResource = useAsyncResource<Partial<Record<MyBoardBucket, MyBoardActivityDto[]>>>(
    () => ({}),
  )
  const rescheduleMutation = useMutation()

  // Current filter params — stored so reload() re-uses the last params
  const lastParams = ref<TeamBoardParams>({})

  const bucketsData = computed((): TeamTaskBucket[] => {
    return ALL_TEAM_BOARD_BUCKETS.map((key) => ({
      key,
      tasks: serverBuckets.value[key] ?? [],
    }))
  })

  const totalVisible = computed(() =>
    bucketsData.value.reduce((s, b) => s + b.tasks.length, 0),
  )

  const allDone = computed(
    () => !boardResource.loading.value && totalVisible.value === 0,
  )

  async function load(params: TeamBoardParams = {}) {
    lastParams.value = params
    await boardResource.run(
      async () => {
        const clean: Parameters<typeof activityApi.getTeamBoard>[0] = {}
        if (params.responsible_id) clean.responsible_id = params.responsible_id
        if (params.q) clean.q = params.q
        if (params.kind) clean.kind = params.kind
        if (params.status) clean.status = params.status
        if (params.priority) clean.priority = params.priority
        if (params.due_from) clean.due_from = params.due_from
        if (params.due_to) clean.due_to = params.due_to

        const r = await activityApi.getTeamBoard(clean)

        return Object.fromEntries(
          ALL_TEAM_BOARD_BUCKETS.map((k) => [
            k,
            (r.data[k] ?? []).map((t) => ({
              ...t,
              assigned_to: t.responsible ?? t.assigned_to ?? null,
            })),
          ]),
        ) as Partial<Record<MyBoardBucket, MyBoardActivityDto[]>>
      },
      {
        // Commit through the token-guarded phase — a late stale team-board
        // response (e.g. rapid search typing) can no longer overwrite a fresher
        // one. The search watcher is now debounced too, but this closes the race
        // for concurrent load/reload paths.
        commit: (normalised) => {
          serverBuckets.value = normalised
        },
      },
    )
  }

  async function reload() {
    await load(lastParams.value)
  }

  /** Remove a task locally after a mutation (e.g. complete from team view). */
  function removeLocalById(id: number) {
    for (const key of ALL_TEAM_BOARD_BUCKETS) {
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

  /**
   * Optimistically move a task from its current bucket into targetBucket,
   * stamping the new due_at. Mirrors myTasksStore.boardMove for the team board's
   * isolated state (which intentionally does NOT use myTasksStore).
   */
  function boardMove(id: number, targetBucket: MyBoardBucket, dueAtIso: string) {
    let moved: MyBoardActivityDto | null = null
    for (const key of ALL_TEAM_BOARD_BUCKETS) {
      const bucket = serverBuckets.value[key]
      if (!bucket) continue
      const idx = bucket.findIndex((t) => t.id === id)
      if (idx >= 0) {
        moved = { ...(bucket[idx] as MyBoardActivityDto), due_at: dueAtIso }
        serverBuckets.value = {
          ...serverBuckets.value,
          [key]: [...bucket.slice(0, idx), ...bucket.slice(idx + 1)],
        }
        break
      }
    }
    if (moved) {
      const target = serverBuckets.value[targetBucket] ?? []
      serverBuckets.value = {
        ...serverBuckets.value,
        [targetBucket]: [...target, moved],
      }
    }
  }

  /**
   * Reschedule a colleague's task by dragging it into a bucket (team board).
   *
   * Allowed for manager-tier actors — the backend gates POST /reschedule by the
   * same `update` policy that already granted this actor department/All-scope
   * write access (see composable docstring). Preserves the original time-of-day
   * when moving to a new day, exactly like the personal board's rescheduleTask.
   * Optimistically moves the card, then calls the API; on error (e.g. the task
   * moved out of scope → 403) it rolls back by reloading the team board.
   */
  async function rescheduleTask(id: number, targetBucket: MyBoardBucket): Promise<void> {
    const newDateStr = bucketToDueDate(targetBucket) // YYYY-MM-DD in operational TZ

    // Preserve the task's original time component if present
    const existing = ALL_TEAM_BOARD_BUCKETS
      .flatMap((k) => serverBuckets.value[k] ?? [])
      .find((t) => t.id === id)

    let dueAtIso: string
    if (existing?.due_at) {
      const orig = new Date(existing.due_at)
      const hh = String(orig.getUTCHours()).padStart(2, '0')
      const mm = String(orig.getUTCMinutes()).padStart(2, '0')
      const ss = String(orig.getUTCSeconds()).padStart(2, '0')
      dueAtIso = `${newDateStr}T${hh}:${mm}:${ss}+04:00`
    } else {
      dueAtIso = `${newDateStr}T09:00:00+04:00`
    }

    // Optimistic move in the team board's local state
    boardMove(id, targetBucket, dueAtIso)

    try {
      await rescheduleMutation.run(async () => {
        await activityApi.rescheduleActivity(id, { dueAt: dueAtIso })
      })
    } catch (err) {
      // Rollback (e.g. 403 — task moved out of the actor's scope) and re-throw
      // the ORIGINAL axios error so the caller can branch on response.status.
      await reload()
      throw err
    }
  }

  return {
    loading: computed(() => boardResource.loading.value),
    error: computed(() => boardResource.error.value),
    bucketsData,
    totalVisible,
    allDone,
    load,
    reload,
    removeLocalById,
    rescheduleTask,
  }
}

/**
 * Compute the operational-TZ date string for kanban bucket meta display.
 * Mirrors logic in TasksKanbanBoard (bucketMeta) — kept local to avoid
 * circular dep with useTaskBoard.
 */
export function tzDate(d: Date): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: OPERATIONAL_TZ }).format(d)
}
