/**
 * Shared plan-matrix editing core — the dirty-set + cell read/write + bulk-upsert
 * (P-2) machinery reused by every editable metric grid (Поступления · Задачи · …).
 *
 * Owns nothing about *loading* the matrix (that is metric-specific: P-1 for income,
 * R4 task-matrix for tasks) — it takes the current `PlanMatrixResponse` through a
 * getter and manages edits/saves on top of it. Extracted from `usePlansTab` so the
 * income and tasks sub-tabs share one implementation (reuse-first, no duplication).
 *
 * A cell key is `<scopeId>:<columnKey>` (e.g. `12:1`, `12:annual`). The dirty-set
 * holds the pending edited value (kopecks | count | null=delete) per key; saving
 * only ships dirty cells; clearing a value = a delete on the backend (contract O10).
 *
 * `cellConfig` injects the metric's `config` bag per saved cell — income passes
 * none; tasks pass `{ activity_kind }` (contract §3.5 / §6.2).
 */
import { reactive, computed, type ComputedRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { bulkUpsertCells } from '@/api/planTargets'
import { toKopecks, fromKopecks } from '@/utils/currency'
import type {
  PlanMatrixResponse,
  PlanMatrixRow,
  UpsertCellData,
  BulkUpsertCellsPayload,
} from '@/entities/planTargets'

/**
 * How a saved cell's scope FKs are stamped from its matrix row.
 *
 * Income / tasks plan per user (`scope_type='user'`, `scope_user_id` = row id).
 * R6 «Поступления по линейкам» plans per product line (`scope_type='company'`,
 * `scope_product_group_id` = row id). Pluggable so one core serves every scope.
 */
export interface CellScopeStamp {
  scope_type: UpsertCellData['scope_type']
  scope_user_id?: number | null
  scope_pipeline_id?: number | null
  scope_product_group_id?: number | null
}

/** Default (user-scoped) stamp — income + tasks grids plan per manager row. */
const userScopeStamp = (row: PlanMatrixRow): CellScopeStamp => ({
  scope_type: 'user',
  scope_user_id: row.scope.id,
})

export interface PlanMatrixCoreDeps {
  /** Current matrix (null until loaded). */
  matrix: ComputedRef<PlanMatrixResponse | null>
  /** Reload the matrix after a successful save. */
  reload: () => Promise<void>
  /** Optional per-cell `config` bag (e.g. `{ activity_kind }` for tasks). */
  cellConfig?: () => Record<string, unknown> | undefined
  /**
   * Optional scope-FK stamp per saved cell. Defaults to user-scoped
   * (`scope_type='user'`); R6 injects a product-group stamp so the same
   * bulk-upsert path plans product lines (`scope_type='company'` + group FK).
   */
  cellScope?: (row: PlanMatrixRow) => CellScopeStamp
}

export const usePlanMatrixCore = (deps: PlanMatrixCoreDeps) => {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()

  const matrix = deps.matrix

  /** Dirty-set: cellKey → pending value in display units (or null = delete). */
  const dirty = reactive<Map<string, number | null>>(new Map())

  const cellKey = (scopeId: number | null, columnKey: string): string =>
    `${scopeId ?? 'company'}:${columnKey}`

  const dirtyCount = computed<number>(() => dirty.size)
  const isDirty = computed<boolean>(() => dirty.size > 0)

  const clearDirty = (): void => {
    dirty.clear()
  }

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

  /**
   * Row «Всего» (display units), dirty-aware.
   *
   * A plan can be authored two ways (contract §O6): distributed across the 12
   * monthly cells, OR as a single standalone annual cell (`period_month` NULL) —
   * in which case the monthly cells are empty and the backend carries the whole
   * year on `cells.annual`. Summing only the months (the old behaviour) therefore
   * returned 0 for annual-authored plans even though «Год» showed the real figure
   * (BUG-PLAN-TOTALS-ZERO). We take the monthly sum and, when it is empty while a
   * standalone annual plan exists, fall back to the annual cell — both branches
   * dirty-aware via `cellValue`.
   */
  const rowTotal = (row: PlanMatrixRow): number => {
    if (!matrix.value) return 0
    const monthlySum = matrix.value.columns
      .filter((c) => c.period_month != null)
      .reduce((sum, c) => sum + (cellValue(row, c.key) ?? 0), 0)
    if (monthlySum !== 0) return monthlySum
    // No monthly plan → surface a standalone annual plan if one is stored.
    if (row.cells['annual']?.has_plan) {
      return cellValue(row, 'annual') ?? 0
    }
    return monthlySum
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
    const config = deps.cellConfig?.()
    const stampScope = deps.cellScope ?? userScopeStamp
    const cells: UpsertCellData[] = []

    for (const [key, value] of dirty.entries()) {
      const [scopePart, columnKey] = key.split(':')
      if (scopePart == null || columnKey == null) continue
      const column = matrix.value.columns.find((c) => c.key === columnKey)
      if (!column) continue
      const row = rowByScopeKey(scopePart)
      if (!row) continue
      // Annual (derived) column is not directly editable — skip.
      if (column.period_month == null) continue

      const base: UpsertCellData = {
        metric: matrix.value.meta.metric,
        ...stampScope(row),
        period_year: year,
        period_month: column.period_month,
        layer,
      }

      if (isMoney.value) {
        base.value_kopecks = value == null ? null : toKopecks(value)
        base.currency =
          value == null
            ? null
            : (row.cells[columnKey]?.currency ?? matrix.value.meta.base_currency)
      } else {
        base.value_count = value == null ? null : Math.round(value)
      }

      if (config != null) base.config = config
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
        await deps.reload()
      },
      onError: (err) => {
        const msg = err instanceof Error ? err.message : String(err)
        toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
      },
    })
  }

  // ─── Unsaved-changes prompt (shared by route-leave + in-hub tab switch) ─────
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

  return {
    dirty,
    dirtyCount,
    isDirty,
    clearDirty,
    isMoney,
    cellValue,
    setCellValue,
    isCellDirty,
    rowTotal,
    save,
    saving: saveState.isPending,
    askConfirm,
    confirmLeave,
    onBeforeUnload,
  }
}
