/**
 * «Планы» tab · «Поступления» (new_income) metric data composable.
 *
 * Owns the income plan matrix (P-1), copy-previous (P-3), reload guard, and the
 * unsaved-changes guard; the cell/dirty/save machinery is the shared
 * `usePlanMatrixCore` (reused by the Задачи metric too). Ф1 wires `new_income`
 * scope=user end-to-end.
 *
 * Server-state routes through `useAsyncResource` / `useMutation` (no raw axios in
 * components). Backend is built in parallel — a 404 (endpoint not yet shipped)
 * degrades gracefully to an "empty" matrix so the tab renders instead of erroring
 * (the obkatanный graceful-404 pattern for parallel BE/FE work).
 */
import { ref, computed, watch, onBeforeUnmount, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { onBeforeRouteLeave } from 'vue-router'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { getPlanMatrix, copyPreviousPeriod } from '@/api/planTargets'
import { getApiErrorStatus } from '@/utils/errors'
import { usePlanMatrixCore } from './usePlanMatrixCore'
import type {
  PlanMatrixResponse,
  PlanMetric,
  PlanLayer,
  CopyPreviousResult,
} from '@/entities/planTargets'

/** Empty matrix used as the graceful fallback when the endpoint 404s (parallel BE). */
const emptyMatrix = (metric: PlanMetric, layer: PlanLayer, year: number): PlanMatrixResponse => ({
  meta: {
    metric,
    scope_type: 'user',
    layer,
    year,
    pipeline_id: null,
    value_kind: metric === 'new_income' || metric === 'product_income' ? 'money' : 'count',
    base_currency: 'RUB',
    fact_source: 'won_deals',
    multi_currency_warning: false,
    can_edit: false,
  },
  columns: [],
  rows: [],
  totals: {},
})

export interface PlansTabDeps {
  year: () => number
  layer: () => PlanLayer
  pipelineId: () => number | null
}

export const usePlansTab = (deps: PlansTabDeps) => {
  const { t } = useI18n()
  const toast = useToast()

  const metric: PlanMetric = 'new_income'

  // ─── Matrix resource ───────────────────────────────────────────────────────
  const matrixResource = useAsyncResource<PlanMatrixResponse | null>(() => null)
  const dataReady = ref(false)
  /** True when the backend endpoint is not yet deployed (404) — shows a hint. */
  const endpointMissing = ref(false)

  const matrix = computed<PlanMatrixResponse | null>(() => matrixResource.data.value)

  const loadMatrix = async (): Promise<void> => {
    endpointMissing.value = false
    try {
      await matrixResource.run(() =>
        getPlanMatrix({
          metric,
          scope_type: 'user',
          layer: deps.layer(),
          year: deps.year(),
          pipeline_id: deps.pipelineId(),
        }),
      )
      core.clearDirty()
    } catch (err) {
      if (getApiErrorStatus(err) === 404) {
        // Endpoint not yet shipped — degrade to an empty grid + hint (no error toast).
        endpointMissing.value = true
        matrixResource.reset(emptyMatrix(metric, deps.layer(), deps.year()))
        core.clearDirty()
        return
      }
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
    } finally {
      dataReady.value = true
    }
  }

  const loading = computed<boolean>(() => !dataReady.value || matrixResource.loading.value)

  // ─── Shared editing core (dirty-set + cell read/write + save) ───────────────
  const core = usePlanMatrixCore({ matrix, reload: loadMatrix })

  // Reload when period / layer / pipeline change (confirm if there are edits).
  const reloadGuarded = async (): Promise<void> => {
    if (core.isDirty.value) {
      const ok = await core.askConfirm(t('dashboard.plans.leave_confirm'))
      if (!ok) return
      core.clearDirty()
    }
    await loadMatrix()
  }

  watch(
    () => [deps.year(), deps.layer(), deps.pipelineId()],
    () => {
      void reloadGuarded()
    },
  )

  // ─── Copy previous period (P-3) — fills the grid as dirty, does not persist ──
  const copyState = useMutation<CopyPreviousResult>()

  const copyPrevious = async (): Promise<void> => {
    if (!matrix.value) return
    if (core.isDirty.value) {
      const ok = await core.askConfirm(t('dashboard.plans.leave_confirm'))
      if (!ok) return
    }
    const year = deps.year()
    const isAnnual = deps.layer() === 'annual'
    const fromYear = isAnnual ? year - 1 : year
    await copyState.run(
      () =>
        copyPreviousPeriod({
          metric,
          layer: deps.layer(),
          scope_type: 'user',
          from_year: fromYear,
          from_month: null,
          to_year: year,
          to_month: null,
          pipeline_id: deps.pipelineId() ?? undefined,
        }),
      {
        onSuccess: async (result) => {
          if (result.created > 0) {
            toast.add({
              severity: 'success',
              summary: t('dashboard.plans.copied', { n: result.created }),
              life: 3000,
            })
            await loadMatrix()
          } else {
            toast.add({ severity: 'info', summary: t('dashboard.plans.copy_nothing'), life: 4000 })
          }
        },
        onError: (err) => {
          if (getApiErrorStatus(err) === 404) {
            toast.add({ severity: 'info', summary: t('dashboard.plans.copy_unavailable'), life: 4000 })
            return
          }
          const msg = err instanceof Error ? err.message : String(err)
          toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
        },
      },
    )
  }

  // ─── Unsaved-changes guard (route-leave + beforeunload) ────────────────────
  onMounted(() => {
    window.addEventListener('beforeunload', core.onBeforeUnload)
    void loadMatrix()
  })
  onBeforeUnmount(() => window.removeEventListener('beforeunload', core.onBeforeUnload))

  onBeforeRouteLeave(() => core.confirmLeave())

  return {
    // matrix
    matrix,
    loading,
    endpointMissing,
    isMoney: core.isMoney,
    // cell edit
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
    copyPrevious,
    copying: copyState.isPending,
    reload: loadMatrix,
  }
}
