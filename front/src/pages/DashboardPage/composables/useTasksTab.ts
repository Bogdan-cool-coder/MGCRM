/**
 * «Планы» tab · «Задачи» (tasks_completed) metric data composable — R4.
 *
 * Fetches the R4 task-matrix (`GET /api/reports/task-matrix`) which returns one
 * P-1-shaped matrix per activity kind (call/meeting/…/presentation) plus an "all"
 * aggregate. The FE picks a kind (a `SelectButton` over the grid); the selected
 * group is adapted 1:1 to a `PlanMatrixResponse` (count mode), so the same
 * `PlanMatrix` component and the shared `usePlanMatrixCore` edit/save it exactly
 * like the income grid — maximum reuse.
 *
 * Plan edits persist through P-2 bulk-upsert with `config: { activity_kind }`
 * (contract §3.5 / §6.2), injected via the core's `cellConfig`. The "all" group is
 * read-only (an aggregate, not a plannable kind).
 *
 * Server-state routes through `useAsyncResource` / `useMutation` (no raw axios in
 * components). Backend is built in parallel — a 404 degrades gracefully to an empty
 * report + hint (the established graceful-404 pattern, mirrors usePlansTab).
 */
import { ref, computed, watch, onBeforeUnmount, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { onBeforeRouteLeave } from 'vue-router'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { getTaskMatrix } from '@/api/reports'
import { getApiErrorStatus } from '@/utils/errors'
import { usePlanMatrixCore } from './usePlanMatrixCore'
import type {
  TaskMatrixResponse,
  TaskMatrixGroup,
} from '@/entities/reports'
import { taskGroupToMatrix } from '@/entities/reports'
import type { PlanMatrixResponse, PlanLayer } from '@/entities/planTargets'

/** The "all" group is an aggregate — a plan cannot be authored against it. */
const ALL_KIND = 'all'

export interface TasksTabDeps {
  year: () => number
  layer: () => PlanLayer
  pipelineId: () => number | null
}

export interface KindOption {
  value: string
  label: string
}

export const useTasksTab = (deps: TasksTabDeps) => {
  const { t } = useI18n()
  const toast = useToast()

  const resource = useAsyncResource<TaskMatrixResponse | null>(() => null)
  const dataReady = ref(false)
  const endpointMissing = ref(false)

  const report = computed<TaskMatrixResponse | null>(() => resource.data.value)

  // ─── Selected activity kind (client-side; a group is picked from `groups`) ──
  const selectedKind = ref<string | null>(null)

  const kindOptions = computed<KindOption[]>(
    () => report.value?.groups.map((g) => ({ value: g.kind, label: g.label })) ?? [],
  )

  const currentGroup = computed<TaskMatrixGroup | null>(() => {
    const groups = report.value?.groups ?? []
    if (groups.length === 0) return null
    return groups.find((g) => g.kind === selectedKind.value) ?? groups[0] ?? null
  })

  /** The selected group adapted to a plan matrix (count mode) for reuse. */
  const matrix = computed<PlanMatrixResponse | null>(() => {
    const group = currentGroup.value
    const meta = report.value?.meta
    if (!group || !meta) return null
    return taskGroupToMatrix(group, meta)
  })

  /** "all" aggregate is not editable — only real activity kinds are plannable. */
  const isAggregateKind = computed<boolean>(() => currentGroup.value?.kind === ALL_KIND)

  const canEdit = computed<boolean>(
    () => (report.value?.meta.can_edit ?? false) && !isAggregateKind.value,
  )

  const load = async (): Promise<void> => {
    endpointMissing.value = false
    try {
      await resource.run(() =>
        getTaskMatrix({
          year: deps.year(),
          layer: deps.layer(),
          pipeline_id: deps.pipelineId(),
        }),
      )
      // Keep the selected kind if it still exists; else default to the first
      // real (non-«all») kind so the grid opens on a plannable matrix.
      const groups = report.value?.groups ?? []
      if (!groups.some((g) => g.kind === selectedKind.value)) {
        const firstReal = groups.find((g) => g.kind !== ALL_KIND) ?? groups[0]
        selectedKind.value = firstReal?.kind ?? null
      }
      core.clearDirty()
    } catch (err) {
      if (getApiErrorStatus(err) === 404) {
        endpointMissing.value = true
        resource.reset(null)
        core.clearDirty()
        return
      }
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
    } finally {
      dataReady.value = true
    }
  }

  const loading = computed<boolean>(() => !dataReady.value || resource.loading.value)

  // ─── Shared editing core (dirty-set + cell read/write + save) ───────────────
  // Tasks persist with `config: { activity_kind }` — injected per saved cell.
  const core = usePlanMatrixCore({
    matrix,
    reload: load,
    cellConfig: () => {
      const kind = currentGroup.value?.kind
      return kind && kind !== ALL_KIND ? { activity_kind: kind } : undefined
    },
  })

  // Switching kind with unsaved edits prompts (edits are kind-specific).
  const setKind = async (kind: string): Promise<void> => {
    if (kind === selectedKind.value) return
    if (core.isDirty.value) {
      const ok = await core.askConfirm(t('dashboard.plans.leave_confirm'))
      if (!ok) return
      core.clearDirty()
    }
    selectedKind.value = kind
  }

  const reloadGuarded = async (): Promise<void> => {
    if (core.isDirty.value) {
      const ok = await core.askConfirm(t('dashboard.plans.leave_confirm'))
      if (!ok) return
      core.clearDirty()
    }
    await load()
  }

  watch(
    () => [deps.year(), deps.layer(), deps.pipelineId()],
    () => {
      void reloadGuarded()
    },
  )

  onMounted(() => {
    window.addEventListener('beforeunload', core.onBeforeUnload)
    void load()
  })
  onBeforeUnmount(() => window.removeEventListener('beforeunload', core.onBeforeUnload))

  onBeforeRouteLeave(() => core.confirmLeave())

  return {
    // report / kind
    report,
    matrix,
    loading,
    endpointMissing,
    selectedKind,
    kindOptions,
    setKind,
    isAggregateKind,
    canEdit,
    // cell edit (from the shared core)
    isMoney: core.isMoney,
    cellValue: core.cellValue,
    setCellValue: core.setCellValue,
    isCellDirty: core.isCellDirty,
    rowTotal: core.rowTotal,
    // dirty / save
    dirtyCount: core.dirtyCount,
    isDirty: core.isDirty,
    confirmLeave: core.confirmLeave,
    save: core.save,
    saving: core.saving,
    reload: load,
  }
}
