/**
 * useTeamBoard — composable for the team task kanban (GET /api/activities/team-board).
 *
 * Intentionally uses its own isolated reactive state (not myTasksStore) because:
 *  - team-board cards belong to other people; optimistic mutations don't apply;
 *  - the personal board (myTasksStore) must not be polluted with team data.
 *
 * Actions available: load (with optional responsible_id / q params), reload.
 * Read-only: no bulk/complete/pin mutations (directors view-only; actions stay
 * on personal board). If a task is completed from the team view the board is
 * simply reloaded.
 */
import { ref, computed } from 'vue'
import { activityApi } from '@/api/activity'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { OPERATIONAL_TZ } from '@/utils/activity'
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
}

export function useTeamBoard() {
  const serverBuckets = ref<Partial<Record<MyBoardBucket, MyBoardActivityDto[]>>>({})
  const boardResource = useAsyncResource<MyBoardActivityDto[]>(() => [])

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
    await boardResource.run(async () => {
      const clean: { responsible_id?: number; q?: string } = {}
      if (params.responsible_id) clean.responsible_id = params.responsible_id
      if (params.q) clean.q = params.q

      const r = await activityApi.getTeamBoard(clean)

      const normalised = Object.fromEntries(
        ALL_TEAM_BOARD_BUCKETS.map((k) => [
          k,
          (r.data[k] ?? []).map((t) => ({
            ...t,
            assigned_to: t.responsible ?? t.assigned_to ?? null,
          })),
        ]),
      ) as Partial<Record<MyBoardBucket, MyBoardActivityDto[]>>

      serverBuckets.value = normalised
      return ALL_TEAM_BOARD_BUCKETS.flatMap((k) => normalised[k] ?? [])
    })
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

  return {
    loading: computed(() => boardResource.loading.value),
    error: computed(() => boardResource.error.value),
    bucketsData,
    totalVisible,
    allDone,
    load,
    reload,
    removeLocalById,
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
