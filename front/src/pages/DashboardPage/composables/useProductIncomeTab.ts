/**
 * «Планы» tab · «Поступления по линейкам» (product_income) metric composable — R6.
 *
 * SpaceCRM plans income per **product line** (`catalog_product_groups`); this panel
 * shows that single cut only:
 *   - **По линейкам** → R6 `GET /api/reports/product-income` (rows = product groups,
 *     scope_type='company' + scope_product_group_id). Cells carry the SpaceCRM
 *     «Прогноз | Поступления» split (plan/expected/fact/total).
 *
 * A «По сотрудникам» cut is deliberately NOT offered: the plan-targets contract §3.3
 * has no valid `product_income × scope_type=user` combination and no per-employee
 * product-line endpoint, so a user cut could only re-issue the plain `new_income/user`
 * request — indistinguishable from the «Поступления» metric (which already provides
 * that per-manager grid). Offering it produced the QA-E1 "чужая метрика" bug. See
 * CONTRACT GAP; if BE later exposes per-employee product-line income, restore the
 * toggle with an honest second endpoint.
 *
 * Feeds the shared `usePlanMatrixCore` (dirty-set + cell edit + P-2 bulk-upsert) with a
 * product-group scope-FK stamp (`cellScope`), so there is zero duplication of the
 * edit/save machinery.
 *
 * Server-state routes through `useAsyncResource` / `useMutation` (no raw axios in
 * components). Backend is built in parallel — a 404 degrades gracefully to an empty
 * matrix + hint (the established graceful-404 pattern, mirrors usePlansTab/useTasksTab).
 */
import { ref, computed, watch, onBeforeUnmount, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { onBeforeRouteLeave } from 'vue-router'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { copyPreviousPeriod } from '@/api/planTargets'
import { getProductIncomeReport } from '@/api/reports'
import { getApiErrorStatus } from '@/utils/errors'
import { usePlanMatrixCore, type CellScopeStamp } from './usePlanMatrixCore'
import { productIncomeToMatrix } from '@/entities/reports'
import type {
  PlanMatrixResponse,
  PlanMatrixRow,
  PlanLayer,
  CopyPreviousResult,
} from '@/entities/planTargets'

export interface ProductIncomeTabDeps {
  year: () => number
  layer: () => PlanLayer
  pipelineId: () => number | null
}

export const useProductIncomeTab = (deps: ProductIncomeTabDeps) => {
  const { t } = useI18n()
  const toast = useToast()

  const resource = useAsyncResource<PlanMatrixResponse | null>(() => null)
  const dataReady = ref(false)
  const endpointMissing = ref(false)

  const matrix = computed<PlanMatrixResponse | null>(() => resource.data.value)

  const load = async (): Promise<void> => {
    endpointMissing.value = false
    try {
      await resource.run(() =>
        getProductIncomeReport({ year: deps.year(), layer: deps.layer() }).then(
          productIncomeToMatrix,
        ),
      )
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

  // ─── Shared editing core — company-wide plan per product line ────────────────
  const cellScope = (row: PlanMatrixRow): CellScopeStamp => ({
    scope_type: 'company',
    scope_product_group_id: row.scope.id,
  })

  const core = usePlanMatrixCore({ matrix, reload: load, cellScope })

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

  // ─── Copy previous period (P-3) — only for the editable product/user grid ───
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
          metric: 'product_income',
          layer: deps.layer(),
          scope_type: 'company',
          from_year: fromYear,
          from_month: null,
          to_year: year,
          to_month: null,
        }),
      {
        onSuccess: async (result) => {
          if (result.created > 0) {
            toast.add({
              severity: 'success',
              summary: t('dashboard.plans.copied', { n: result.created }),
              life: 3000,
            })
            await load()
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

  const canEdit = computed<boolean>(() => matrix.value?.meta.can_edit ?? false)

  // ─── Unsaved-changes guard (route-leave + beforeunload) ────────────────────
  onMounted(() => {
    window.addEventListener('beforeunload', core.onBeforeUnload)
    void load()
  })
  onBeforeUnmount(() => window.removeEventListener('beforeunload', core.onBeforeUnload))

  onBeforeRouteLeave(() => core.confirmLeave())

  return {
    // matrix
    matrix,
    loading,
    endpointMissing,
    canEdit,
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
    reload: load,
  }
}
