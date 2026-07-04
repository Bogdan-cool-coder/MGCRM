<template>
  <div class="plan-matrix">
    <DataTable
      :value="matrix.rows"
      size="small"
      :striped-rows="true"
      row-hover
      scrollable
      scroll-height="flex"
      class="plan-matrix__table"
    >
      <!-- Employee / product line (frozen left) -->
      <Column
        :header="entityLabel ?? t('dashboard.plans.col_employee')"
        :footer="t('dashboard.plans.row_total')"
        frozen
        align-frozen="left"
        class="plan-matrix__col-employee"
      >
        <template #body="{ data }">
          <div class="plan-matrix__employee">
            <EntityAvatar
              :name="(data as PlanMatrixRow).scope.label"
              :entity-id="(data as PlanMatrixRow).scope.id ?? undefined"
              :pixel-size="22"
            />
            <span class="plan-matrix__employee-name">
              {{ (data as PlanMatrixRow).scope.label }}
            </span>
          </div>
        </template>
      </Column>

      <!-- Currency -->
      <Column :header="t('dashboard.plans.col_currency')" class="plan-matrix__col-currency">
        <template #body="{ data }">
          <PlanMatrixCurrencyCell
            :is-money="isMoney"
            :currency="rowCurrency(data as PlanMatrixRow)"
            :disabled="!canEdit"
            :breakdown="rowBreakdown(data as PlanMatrixRow)"
            :base-kopecks="rowBaseKopecks(data as PlanMatrixRow)"
            :base-currency="matrix.meta.base_currency"
            @update:currency="(c: string) => emit('update:rowCurrency', data as PlanMatrixRow, c)"
          />
        </template>
        <template #footer><span class="plan-matrix__footer-na">—</span></template>
      </Column>

      <!-- Total plan (read-only, base) -->
      <Column :header="t('dashboard.plans.col_total')" class="plan-matrix__col-total">
        <template #body="{ data }">
          <span
            v-tooltip.top="
              rowHasPlan(data as PlanMatrixRow) ? undefined : t('dashboard.plans.no_plan_hint')
            "
            class="plan-matrix__total"
            :class="{ 'plan-matrix__total--empty': !rowHasPlan(data as PlanMatrixRow) }"
          >
            {{ formatTotal(data as PlanMatrixRow) }}
          </span>
        </template>
        <template #footer>
          <span
            v-tooltip.top="grandTotalHasPlan ? undefined : t('dashboard.plans.no_plan_hint')"
            class="plan-matrix__footer-cell"
            :class="{ 'plan-matrix__footer-cell--empty': !grandTotalHasPlan }"
          >
            {{ grandTotalLabel }}
          </span>
        </template>
      </Column>

      <!-- Months × 12 + annual -->
      <Column
        v-for="col in matrix.columns"
        :key="col.key"
        :header="col.label"
        class="plan-matrix__col-month"
      >
        <template #body="{ data }">
          <div class="plan-matrix__cell">
            <InputNumber
              :model-value="cellValue(data as PlanMatrixRow, col.key)"
              :disabled="!canEdit || isDerivedColumn(col)"
              :min="0"
              :min-fraction-digits="0"
              :max-fraction-digits="0"
              locale="ru-RU"
              :input-class="[
                'plan-matrix__input-el',
                { 'plan-matrix__input-el--dirty': isCellDirty((data as PlanMatrixRow).scope.id, col.key) },
              ]"
              class="plan-matrix__input"
              @update:model-value="(v) => emit('cell-input', data as PlanMatrixRow, col.key, v)"
            />
            <div class="plan-matrix__fact">
              <span
                v-tooltip.top="factTooltip(data as PlanMatrixRow, col.key)"
                class="plan-matrix__fact-value"
              >
                {{ factLabel(data as PlanMatrixRow, col.key) }}
              </span>
              <PctTag
                :value="pctOf(data as PlanMatrixRow, col.key)"
                :badge="badgeOf(data as PlanMatrixRow, col.key)"
                size="sm"
              />
            </div>
          </div>
        </template>
        <template #footer>
          <span class="plan-matrix__footer-cell">{{ totalLabel(col.key) }}</span>
        </template>
      </Column>
    </DataTable>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputNumber from 'primevue/inputnumber'
import EntityAvatar from '@/components/crm/entity/EntityAvatar.vue'
import PctTag from '@/components/shared/PctTag.vue'
import PlanMatrixCurrencyCell from './PlanMatrixCurrencyCell.vue'
import { formatMkMoney } from '@/utils/motivation'
import type { PctBadge } from '@/entities/motivation'
import type {
  PlanMatrixResponse,
  PlanMatrixRow,
  PlanMatrixColumn,
} from '@/entities/planTargets'
import type { ProductIncomeCell } from '@/entities/reports'

const props = defineProps<{
  matrix: PlanMatrixResponse
  isMoney: boolean
  canEdit: boolean
  /** Current display value of a cell (dirty-aware) — from the tab composable. */
  cellValue: (row: PlanMatrixRow, columnKey: string) => number | null
  /** Whether a cell holds a pending edit. */
  isCellDirty: (scopeId: number | null, columnKey: string) => boolean
  /** Row plan total (display units) across 12 months, dirty-aware. */
  rowTotal: (row: PlanMatrixRow) => number
  /** Frozen-column header label (e.g. «Сотрудник» | «Продуктовая линейка»). */
  entityLabel?: string
}>()

const emit = defineEmits<{
  'cell-input': [row: PlanMatrixRow, columnKey: string, value: number | null]
  'update:rowCurrency': [row: PlanMatrixRow, currency: string]
}>()

const { t } = useI18n()

const isDerivedColumn = (col: PlanMatrixColumn): boolean => col.period_month == null

// ─── Cell fact / pct helpers (read straight from the stored matrix cell) ───────
const pctOf = (row: PlanMatrixRow, columnKey: string): number | null =>
  row.cells[columnKey]?.pct ?? null

const badgeOf = (row: PlanMatrixRow, columnKey: string): PctBadge =>
  row.cells[columnKey]?.badge ?? 'none'

const factLabel = (row: PlanMatrixRow, columnKey: string): string => {
  const cell = row.cells[columnKey]
  if (!cell) return '—'
  if (props.isMoney) {
    return cell.fact_kopecks != null
      ? formatMkMoney(cell.fact_kopecks, props.matrix.meta.base_currency)
      : '—'
  }
  return cell.fact_count != null ? String(cell.fact_count) : '—'
}

/**
 * Fact tooltip. For money grids that carry the R6 «Прогноз | Поступления» split
 * (expected/total ride along on `ProductIncomeCell`), append «Ожидается» and «Всего»
 * so the reused grid tells the full product-income story without a second component.
 */
const factTooltip = (row: PlanMatrixRow, columnKey: string): string => {
  const lines = [`${t('dashboard.plans.col_fact')}: ${factLabel(row, columnKey)}`]
  const cell = row.cells[columnKey] as ProductIncomeCell | undefined
  if (props.isMoney && cell) {
    const base = props.matrix.meta.base_currency
    if (cell.expected_kopecks != null) {
      lines.push(`${t('dashboard.plans.expected')}: ${formatMkMoney(cell.expected_kopecks, base)}`)
    }
    if (cell.total_kopecks != null) {
      lines.push(`${t('dashboard.plans.total_forecast')}: ${formatMkMoney(cell.total_kopecks, base)}`)
    }
  }
  return lines.join(' · ')
}

// ─── Total column (row-level, base) ────────────────────────────────────────────
/**
 * Whether the row carries any authored plan (dirty-aware, matching `rowTotal`
 * semantics). Drives the honest «—» placeholder: when a row has no plan we show
 * a dash rather than «0 ₽», so it can never be misread against the fact figure
 * that the «Год» column surfaces as its sub-line (the UX trap QA hit twice —
 * empty plan «0 ₽» sitting next to a real fact reads as a bug).
 */
const rowHasPlan = (row: PlanMatrixRow): boolean => props.rowTotal(row) !== 0

const formatTotal = (row: PlanMatrixRow): string => {
  if (!rowHasPlan(row)) return t('dashboard.plans.no_plan_dash')
  const total = props.rowTotal(row)
  if (props.isMoney) return formatMkMoney(rowCurrencyToBase(row, total), props.matrix.meta.base_currency)
  return String(Math.round(total))
}

// The plan input is in the row's currency; the «Всего» is shown in base. Without
// a live FX rate on the FE we surface the row-currency sum as-is when the row
// currency equals base, else the API's per-cell base is the source of truth.
// For Ф1 (RUB-first) the two coincide; the base conversion is applied by the API
// on reload, so this local sum is a best-effort preview until save.
const rowCurrencyToBase = (row: PlanMatrixRow, sumUnits: number): number => {
  // sumUnits is display units in the row currency → kopecks.
  return Math.round(sumUnits * 100)
}

// ─── Currency cell helpers ─────────────────────────────────────────────────────
const rowCurrency = (row: PlanMatrixRow): string => {
  const first = props.matrix.columns
    .map((c) => row.cells[c.key]?.currency)
    .find((c): c is string => c != null)
  return first ?? props.matrix.meta.base_currency
}

const rowBreakdown = (row: PlanMatrixRow) => {
  const annual = row.cells['annual']
  return annual?.currency_breakdown ?? []
}

const rowBaseKopecks = (row: PlanMatrixRow): number => {
  const annual = row.cells['annual']
  return annual?.plan_kopecks ?? 0
}

// ─── ИТОГО footer per-column ───────────────────────────────────────────────────
// Product-income (R6) footers show «Всего» = fact + expected (backend supplies it
// in `total_kopecks`); the plan-oriented income/tasks grids show the plan sum. The
// mode is derived from the matrix metric so the footer can never drift from the data.
const isProductIncome = computed<boolean>(
  () => props.matrix.meta.metric === 'product_income',
)

/** The money field a footer cell reports: `total_kopecks` for R6, else `plan_kopecks`. */
const footerKopecks = (columnKey: string): number | null => {
  const cell = props.matrix.totals[columnKey]
  if (!cell) return null
  if (isProductIncome.value) {
    const total = (cell as ProductIncomeCell).total_kopecks
    return total ?? null
  }
  return cell.plan_kopecks ?? null
}

const totalLabel = (columnKey: string): string => {
  const cell = props.matrix.totals[columnKey]
  if (!cell) return '—'
  if (props.isMoney) {
    const kopecks = footerKopecks(columnKey)
    return kopecks != null ? formatMkMoney(kopecks, props.matrix.meta.base_currency) : '—'
  }
  return cell.plan_count != null ? String(cell.plan_count) : '—'
}

/**
 * «Всего» column footer (ИТОГО) — the total planned across all rows for the year.
 *
 * Mirrors `rowTotal`: sum the 12 monthly column-totals, but when a plan is
 * authored as a standalone annual cell the monthly totals are 0 while the whole
 * year rides on `totals.annual`. Falling back to the annual total keeps the
 * footer consistent with the «Год» column (fixes BUG-PLAN-TOTALS-ZERO where the
 * footer read 0 ₽ across all 12 months for annual-authored plans).
 */
// Grand total (plan) across all rows for the year, in raw units. Zero here means
// no plans authored anywhere — surfaced as «—» rather than «0 ₽».
const grandTotalRaw = computed<number>(() => {
  const monthCols = props.matrix.columns.filter((c) => c.period_month != null)
  if (props.isMoney) {
    const monthlySum = monthCols.reduce((acc, c) => acc + (footerKopecks(c.key) ?? 0), 0)
    return monthlySum !== 0 ? monthlySum : (footerKopecks('annual') ?? 0)
  }
  const monthlySum = monthCols.reduce(
    (acc, c) => acc + (props.matrix.totals[c.key]?.plan_count ?? 0),
    0,
  )
  return monthlySum !== 0 ? monthlySum : (props.matrix.totals['annual']?.plan_count ?? 0)
})

const grandTotalHasPlan = computed<boolean>(() => grandTotalRaw.value !== 0)

const grandTotalLabel = computed<string>(() => {
  if (!grandTotalHasPlan.value) return t('dashboard.plans.no_plan_dash')
  const raw = grandTotalRaw.value
  if (props.isMoney) return formatMkMoney(raw, props.matrix.meta.base_currency)
  return String(raw)
})
</script>

<style lang="scss" scoped>
.plan-matrix__table {
  :deep(.p-datatable-tbody > tr > td) {
    padding: $space-2 $space-3;
    vertical-align: top;
  }

  :deep(.p-datatable-thead > tr > th) {
    white-space: nowrap;
  }

  // Frozen «Сотрудник» column must be OPAQUE so scrolled month columns never show
  // through it (transparent frozen cells read as bleed-through on the navy card in
  // dark). --p-datatable-*-cell-background is the reactive table surface, so it
  // stays consistent with the striped rows in both themes.
  :deep(.p-datatable-frozen-column) {
    background: var(--p-datatable-header-cell-background, var(--p-card-background));
  }
}

.plan-matrix__employee {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.plan-matrix__employee-name {
  font-weight: $font-weight-medium;
  white-space: nowrap;
}

.plan-matrix__total {
  font-variant-numeric: tabular-nums;
  font-weight: $font-weight-semibold;
  white-space: nowrap;
}

// Empty-plan placeholder «—»: muted so it reads as "no plan", never as a value
// that could be confused with the fact figure in the «Год» column.
.plan-matrix__total--empty {
  color: $surface-400;
  font-weight: $font-weight-normal;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.plan-matrix__cell {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  min-width: 120px;
}

.plan-matrix__input {
  width: 100%;
}

:deep(.plan-matrix__input-el) {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

:deep(.plan-matrix__input-el--dirty) {
  // Theme-reactive primary — resolves to the light/dark primary automatically,
  // so no `.app-dark &` override is needed (which would be a dead selector when
  // nested inside :deep()). Single base rule works in both themes.
  border-color: var(--p-primary-color);
}

.plan-matrix__fact {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-2;
}

.plan-matrix__fact-value {
  font-size: $font-size-xs;
  color: $surface-600;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.plan-matrix__footer-cell {
  display: block;
  text-align: right;
  font-variant-numeric: tabular-nums;
  font-weight: $font-weight-semibold;
  white-space: nowrap;
}

.plan-matrix__footer-cell--empty {
  color: $surface-400;
  font-weight: $font-weight-normal;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.plan-matrix__footer-na {
  color: $surface-400;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.plan-matrix__table {
  :deep(.p-datatable-tfoot > tr > td) {
    padding: $space-2 $space-3;
    font-weight: $font-weight-bold;
  }
}
</style>
