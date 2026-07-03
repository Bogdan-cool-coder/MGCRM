/**
 * «Планы» tab data composable — matrix read + inline plan editing.
 *
 * Owns the plan matrix (P-1), the dirty-set of edited cells, bulk-upsert (P-2),
 * copy-previous (P-3) and the unsaved-changes guard. Ф1 wires the `new_income`
 * (Поступления) metric scope=user end-to-end; other metric sub-tabs are stubs.
 *
 * Server-state routes through `useAsyncResource` / `useMutation` (no raw axios in
 * components). Backend is built in parallel — a 404 (endpoint not yet shipped)
 * degrades gracefully to an "empty" matrix so the tab renders instead of erroring
 * (the obkatanный graceful-404 pattern for parallel BE/FE work).
 *
 * A cell key is `<scopeId>:<columnKey>` (e.g. `12:1`, `12:annual`). The dirty-set
 * holds the pending edited value (kopecks | count | null=delete) per key; saving
 * only ships dirty cells; clearing a value = a delete on the backend (contract O10).
 */
import { ref, reactive, computed, watch, onBeforeUnmount, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { onBeforeRouteLeave } from 'vue-router'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import {
  getPlanMatrix,
  bulkUpsertCells,
  copyPreviousPeriod,
} from '@/api/planTargets'
import { getApiErrorStatus } from '@/utils/errors'
import { toKopecks, fromKopecks } from '@/utils/currency'
import type {
  PlanMatrixResponse,
  PlanMatrixRow,
  PlanMetric,
  PlanLayer,
  UpsertCellData,
  BulkUpsertCellsPayload,
  CopyPreviousResult,
} from '@/entities/planTargets'

/** Ф1 metric sub-tabs — only income is fully wired; the rest are shell stubs. */
export type PlanMetricTab = 'income' | 'tasks' | 'conversions'

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
  const confirm = useConfirm()

  // ─── Metric sub-tab (`?metric=`-independent local state; income only for Ф1) ──
  const metricTab = ref<PlanMetricTab>('income')

  /** Ф1 wires only `new_income`; the map is ready for the other metrics. */
  const METRIC_MAP: Record<PlanMetricTab, PlanMetric> = {
    income: 'new_income',
    tasks: 'tasks_completed',
    conversions: 'conversion',
  }

  const metric = computed<PlanMetric>(() => METRIC_MAP[metricTab.value])

  // ─── Matrix resource ───────────────────────────────────────────────────────
  const matrixResource = useAsyncResource<PlanMatrixResponse | null>(() => null)
  const dataReady = ref(false)
  /** True when the backend endpoint is not yet deployed (404) — shows a hint. */
  const endpointMissing = ref(false)

  const matrix = computed<PlanMatrixResponse | null>(() => matrixResource.data.value)

  /** Dirty-set: cellKey → pending value in display units (or null = delete). */
  const dirty = reactive<Map<string, number | null>>(new Map())

  const cellKey = (scopeId: number | null, columnKey: string): string =>
    `${scopeId ?? 'company'}:${columnKey}`

  const dirtyCount = computed<number>(() => dirty.size)
  const isDirty = computed<boolean>(() => dirty.size > 0)

  const loadMatrix = async (): Promise<void> => {
    endpointMissing.value = false
    try {
      await matrixResource.run(() =>
        getPlanMatrix({
          metric: metric.value,
          scope_type: 'user',
          layer: deps.layer(),
          year: deps.year(),
          pipeline_id: deps.pipelineId(),
        }),
      )
      dirty.clear()
    } catch (err) {
      if (getApiErrorStatus(err) === 404) {
        // Endpoint not yet shipped — degrade to an empty grid + hint (no error toast).
        endpointMissing.value = true
        matrixResource.reset(emptyMatrix(metric.value, deps.layer(), deps.year()))
        dirty.clear()
        return
      }
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
    } finally {
      dataReady.value = true
    }
  }

  const loading = computed<boolean>(() => !dataReady.value || matrixResource.loading.value)

  // Reload when metric / period / layer / pipeline change (drop pending edits
  // silently — the tab guard already warns on navigation away; here we confirm).
  const reloadGuarded = async (): Promise<void> => {
    if (isDirty.value) {
      const ok = await askConfirm(t('dashboard.plans.leave_confirm'))
      if (!ok) return
      dirty.clear()
    }
    await loadMatrix()
  }

  watch(
    () => [metric.value, deps.year(), deps.layer(), deps.pipelineId()],
    () => {
      void reloadGuarded()
    },
  )

  // ─── Cell read/write against the dirty-set ─────────────────────────────────
  const isMoney = computed<boolean>(() => matrix.value?.meta.value_kind === 'money')

  /** Current display value of a cell: pending dirty edit wins over the stored plan. */
  const cellValue = (row: PlanMatrixRow, columnKey: string): number | null => {
    const key = cellKey(row.scope.id, columnKey)
    if (dirty.has(key)) return dirty.get(key) ?? null
    const cell = row.cells[columnKey]
    if (!cell) return null
    if (isMoney.value) {
      return cell.plan_kopecks != null ? fromKopecks(cell.plan_kopecks) : null
    }
    return cell.plan_count ?? null
  }

  /** Original stored value (display units) — used to detect a no-op edit. */
  const storedValue = (row: PlanMatrixRow, columnKey: string): number | null => {
    const cell = row.cells[columnKey]
    if (!cell) return null
    if (isMoney.value) return cell.plan_kopecks != null ? fromKopecks(cell.plan_kopecks) : null
    return cell.plan_count ?? null
  }

  const setCellValue = (row: PlanMatrixRow, columnKey: string, value: number | null): void => {
    const key = cellKey(row.scope.id, columnKey)
    const original = storedValue(row, columnKey)
    // Editing back to the stored value clears the dirty flag (no phantom count).
    if (value === original) {
      dirty.delete(key)
    } else {
      dirty.set(key, value)
    }
  }

  const isCellDirty = (scopeId: number | null, columnKey: string): boolean =>
    dirty.has(cellKey(scopeId, columnKey))

  /** Row total (display units) across the 12 month columns, dirty-aware. */
  const rowTotal = (row: PlanMatrixRow): number => {
    if (!matrix.value) return 0
    return matrix.value.columns
      .filter((c) => c.period_month != null)
      .reduce((sum, c) => sum + (cellValue(row, c.key) ?? 0), 0)
  }

  // ─── Build & save dirty cells (P-2) ────────────────────────────────────────
  const rowByScopeKey = (companyOrId: string): PlanMatrixRow | undefined => {
    if (!matrix.value) return undefined
    return matrix.value.rows.find((r) => `${r.scope.id ?? 'company'}` === companyOrId)
  }

  const buildDirtyPayload = (): BulkUpsertCellsPayload | null => {
    if (!matrix.value) return null
    const layer = matrix.value.meta.layer
    const year = matrix.value.meta.year
    const cells: UpsertCellData[] = []

    for (const [key, value] of dirty.entries()) {
      const [scopePart, columnKey] = key.split(':')
      if (scopePart == null || columnKey == null) continue
      const column = matrix.value.columns.find((c) => c.key === columnKey)
      if (!column) continue
      const row = rowByScopeKey(scopePart)
      if (!row) continue
      // Annual (derived) column is not directly editable in Ф1 — skip.
      if (column.period_month == null) continue

      const base: UpsertCellData = {
        metric: matrix.value.meta.metric,
        scope_type: 'user',
        scope_user_id: row.scope.id,
        period_year: year,
        period_month: column.period_month,
        layer,
      }

      if (isMoney.value) {
        base.value_kopecks = value == null ? null : toKopecks(value)
        base.currency = value == null ? null : (row.cells[columnKey]?.currency ?? matrix.value.meta.base_currency)
      } else {
        base.value_count = value == null ? null : Math.round(value)
      }
      cells.push(base)
    }

    return cells.length ? { cells } : null
  }

  const saveState = useMutation<void>()

  const save = async (): Promise<void> => {
    const payload = buildDirtyPayload()
    if (!payload) return
    await saveState.run(() => bulkUpsertCells(payload).then(() => undefined), {
      onSuccess: async () => {
        dirty.clear()
        toast.add({ severity: 'success', summary: t('dashboard.plans.saved'), life: 3000 })
        await loadMatrix()
      },
      onError: (err) => {
        const msg = err instanceof Error ? err.message : String(err)
        toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
      },
    })
  }

  // ─── Copy previous period (P-3) — fills the grid as dirty, does not persist ──
  const copyState = useMutation<CopyPreviousResult>()

  const copyPrevious = async (): Promise<void> => {
    if (!matrix.value) return
    if (isDirty.value) {
      const ok = await askConfirm(t('dashboard.plans.leave_confirm'))
      if (!ok) return
    }
    const year = deps.year()
    const fromYear = deps.layer() === 'annual' ? year - 1 : year
    // For monthly layer, copy from the previous month; for annual, the previous year.
    const isAnnual = deps.layer() === 'annual'
    await copyState.run(
      () =>
        copyPreviousPeriod({
          metric: metric.value,
          layer: deps.layer(),
          scope_type: 'user',
          from_year: fromYear,
          from_month: isAnnual ? null : null,
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
            // Nothing to clone — either the previous period had no plans, or
            // every cell already exists in the target (no overwrite).
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
  const askConfirm = (message: string): Promise<boolean> =>
    new Promise((resolve) => {
      confirm.require({
        message,
        header: t('dashboard.plans.leave_header'),
        icon: 'pi pi-exclamation-triangle',
        rejectLabel: t('common.stay'),
        acceptLabel: t('common.leave'),
        acceptClass: 'p-button-danger',
        rejectProps: { severity: 'secondary', outlined: true },
        accept: () => resolve(true),
        reject: () => resolve(false),
        onHide: () => resolve(false),
      })
    })

  /**
   * Single guard used by BOTH the route-leave hook and the in-hub tab switch:
   * clean → leave immediately; dirty → prompt, and on «Уйти» drop pending edits
   * and allow. Returns true = safe to leave.
   */
  const confirmLeave = async (): Promise<boolean> => {
    if (!isDirty.value) return true
    const ok = await askConfirm(t('dashboard.plans.leave_confirm'))
    if (ok) dirty.clear()
    return ok
  }

  const onBeforeUnload = (e: BeforeUnloadEvent): void => {
    if (isDirty.value) {
      e.preventDefault()
      e.returnValue = ''
    }
  }

  onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload)
    void loadMatrix()
  })
  onBeforeUnmount(() => window.removeEventListener('beforeunload', onBeforeUnload))

  onBeforeRouteLeave(() => confirmLeave())

  return {
    // metric sub-tab
    metricTab,
    metric,
    // matrix
    matrix,
    loading,
    endpointMissing,
    isMoney,
    // cell edit
    cellValue,
    setCellValue,
    isCellDirty,
    rowTotal,
    // dirty / save
    dirtyCount,
    isDirty,
    confirmLeave,
    save,
    saving: saveState.isPending,
    copyPrevious,
    copying: copyState.isPending,
    reload: loadMatrix,
  }
}
