/**
 * «Планы» tab · «Поступления по линейкам» (product_income) metric composable — R6.
 *
 * SpaceCRM plans income per **product line** (`catalog_product_groups`); MGCRM keeps
 * the discretion of a breakdown toggle «Сотрудник | Продукт» (ТЗ ОВ-1):
 *   - **Продукт** → R6 `GET /api/reports/product-income` (rows = product groups,
 *     scope_type='company' + scope_product_group_id). Richer cells carry the
 *     SpaceCRM «Прогноз | Поступления» split (plan/expected/fact/total).
 *   - **Сотрудник** → the P-1 `new_income` matrix (rows = managers, scope_type='user') —
 *     the same money grid the «Поступления» metric already shows, kept here so the
 *     product metric offers both cuts without a second tab.
 *
 * Both feed the shared `usePlanMatrixCore` (dirty-set + cell edit + P-2 bulk-upsert);
 * the only per-breakdown difference is the scope-FK stamp (`cellScope`), so there is
 * zero duplication of the edit/save machinery.
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
import { getPlanMatrix, copyPreviousPeriod } from '@/api/planTargets'
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

/** Which cut of the product-income plan is shown: per manager, or per product line. */
export type IncomeBreakdown = 'user' | 'product'

export interface ProductIncomeTabDeps {
  year: () => number
  layer: () => PlanLayer
  pipelineId: () => number | null
}

export const useProductIncomeTab = (deps: ProductIncomeTabDeps) => {
  const { t } = useI18n()
  const toast = useToast()

  /** Default cut = product line (the R6 raison d'être); toggle to user for the manager grid. */
  const breakdown = ref<IncomeBreakdown>('product')

  const resource = useAsyncResource<PlanMatrixResponse | null>(() => null)
  const dataReady = ref(false)
  const endpointMissing = ref(false)

  const matrix = computed<PlanMatrixResponse | null>(() => resource.data.value)

  const load = async (): Promise<void> => {
    endpointMissing.value = false
    try {
      if (breakdown.value === 'product') {
        await resource.run(() =>
          getProductIncomeReport({ year: deps.year(), layer: deps.layer() }).then(
            productIncomeToMatrix,
          ),
        )
      } else {
        await resource.run(() =>
          getPlanMatrix({
            metric: 'new_income',
            scope_type: 'user',
            layer: deps.layer(),
            year: deps.year(),
            pipeline_id: deps.pipelineId(),
          }),
        )
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

  // ─── Shared editing core — scope-FK stamp depends on the breakdown ──────────
  // Product cut → company-wide plan per product line (scope_product_group_id).
  // User cut    → default user scope (scope_user_id).
  const cellScope = (row: PlanMatrixRow): CellScopeStamp =>
    breakdown.value === 'product'
      ? { scope_type: 'company', scope_product_group_id: row.scope.id }
      : { scope_type: 'user', scope_user_id: row.scope.id }

  const core = usePlanMatrixCore({ matrix, reload: load, cellScope })

  const setBreakdown = async (value: IncomeBreakdown): Promise<void> => {
    if (value === breakdown.value) return
    if (core.isDirty.value) {
      const ok = await core.askConfirm(t('dashboard.plans.leave_confirm'))
      if (!ok) return
      core.clearDirty()
    }
    breakdown.value = value
    await load()
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
    const isProduct = breakdown.value === 'product'
    await copyState.run(
      () =>
        copyPreviousPeriod({
          metric: 'product_income',
          layer: deps.layer(),
          scope_type: isProduct ? 'company' : 'user',
          from_year: fromYear,
          from_month: null,
          to_year: year,
          to_month: null,
          pipeline_id: isProduct ? undefined : (deps.pipelineId() ?? undefined),
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
    // matrix / breakdown
    matrix,
    loading,
    endpointMissing,
    breakdown,
    setBreakdown,
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
