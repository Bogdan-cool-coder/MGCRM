<template>
  <div class="plan-matrix">
    <DataTable
      :value="matrix.rows"
      size="small"
      show-gridlines
      scrollable
      scroll-height="flex"
      class="plan-matrix__table"
    >
      <!-- Employee (frozen left) -->
      <Column
        :header="t('dashboard.plans.col_employee')"
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

      <!-- Total (read-only, base) -->
      <Column :header="t('dashboard.plans.col_total')" class="plan-matrix__col-total">
        <template #body="{ data }">
          <span class="plan-matrix__total">{{ formatTotal(data as PlanMatrixRow) }}</span>
        </template>
        <template #footer>
          <span class="plan-matrix__footer-cell">{{ grandTotalLabel }}</span>
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

const factTooltip = (row: PlanMatrixRow, columnKey: string): string =>
  `${t('dashboard.plans.col_fact')}: ${factLabel(row, columnKey)}`

// ─── Total column (row-level, base) ────────────────────────────────────────────
const formatTotal = (row: PlanMatrixRow): string => {
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
const totalLabel = (columnKey: string): string => {
  const cell = props.matrix.totals[columnKey]
  if (!cell) return '—'
  if (props.isMoney) {
    return cell.plan_kopecks != null
      ? formatMkMoney(cell.plan_kopecks, props.matrix.meta.base_currency)
      : '—'
  }
  return cell.plan_count != null ? String(cell.plan_count) : '—'
}

/** «Всего» column footer — sum of the 12 month totals in base. */
const grandTotalLabel = computed<string>(() => {
  const monthCols = props.matrix.columns.filter((c) => c.period_month != null)
  if (props.isMoney) {
    const sum = monthCols.reduce(
      (acc, c) => acc + (props.matrix.totals[c.key]?.plan_kopecks ?? 0),
      0,
    )
    return formatMkMoney(sum, props.matrix.meta.base_currency)
  }
  const sum = monthCols.reduce(
    (acc, c) => acc + (props.matrix.totals[c.key]?.plan_count ?? 0),
    0,
  )
  return String(sum)
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

.plan-matrix__footer-na {
  color: $surface-400;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.plan-matrix__table {
  :deep(.p-datatable-tfoot > tr > td) {
    padding: $space-2 $space-3;
    font-weight: $font-weight-bold;
  }
}
</style>
