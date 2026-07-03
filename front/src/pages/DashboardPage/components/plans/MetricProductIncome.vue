<template>
  <div class="metric-product">
    <!-- Breakdown toggle: per manager (P-1 new_income) | per product line (R6) -->
    <div class="metric-product__breakdown">
      <label class="metric-product__breakdown-label">{{ t('dashboard.plans.breakdown') }}</label>
      <SelectButton
        :model-value="breakdown"
        :options="breakdownOptions"
        option-label="label"
        option-value="value"
        :allow-empty="false"
        class="metric-product__breakdown-select"
        @update:model-value="(v: IncomeBreakdown | null) => v && onBreakdown(v)"
      />
    </div>

    <!-- Endpoint not yet deployed (parallel backend) -->
    <Message
      v-if="endpointMissing && !loading"
      severity="info"
      :closable="false"
      icon="pi pi-info-circle"
      class="mb-4"
    >
      {{ t('dashboard.plans.product_endpoint_missing') }}
    </Message>

    <!-- Multi-currency warning -->
    <Message
      v-else-if="matrix?.meta?.multi_currency_warning"
      severity="warn"
      :closable="false"
      icon="pi pi-info-circle"
      class="mb-4"
    >
      {{ t('dashboard.multiCurrencyWarning') }}
    </Message>

    <!-- Loading skeleton shaped like the grid -->
    <div v-if="loading" class="metric-product__skeleton">
      <Skeleton v-for="n in 6" :key="n" height="2.5rem" class="mb-2" />
    </div>

    <!-- Empty (no product lines / employees in scope) -->
    <PlansEmpty
      v-else-if="!matrix || matrix.rows.length === 0"
      :icon="breakdown === 'product' ? 'pi-box' : 'pi-users'"
      :title="t(breakdown === 'product' ? 'dashboard.plans.product_empty_title' : 'dashboard.plans.empty_title')"
      :hint="t(breakdown === 'product' ? 'dashboard.plans.product_empty_hint' : 'dashboard.plans.empty_hint')"
    />

    <!-- Matrix + save bar -->
    <template v-else>
      <PlanMatrix
        :matrix="matrix"
        :is-money="isMoney"
        :can-edit="canEdit"
        :cell-value="cellValue"
        :is-cell-dirty="isCellDirty"
        :row-total="rowTotal"
        :entity-label="breakdown === 'product' ? t('dashboard.plans.col_product_line') : t('dashboard.plans.col_employee')"
        @cell-input="onCellInput"
        @update:row-currency="onRowCurrency"
      />

      <PlanSaveBar
        v-if="canEdit"
        :dirty-count="dirtyCount"
        :saving="saving"
        :copying="copying"
        :can-edit="canEdit"
        @save="save"
        @copy-prev="copyPrevious"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, inject, watch, onMounted, onActivated, onDeactivated, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import SelectButton from 'primevue/selectbutton'
import PlanMatrix from './PlanMatrix.vue'
import PlanSaveBar from './PlanSaveBar.vue'
import PlansEmpty from './PlansEmpty.vue'
import { useProductIncomeTab, type IncomeBreakdown } from '../../composables/useProductIncomeTab'
import { PLANS_REGISTER_METRIC_GUARD, PLANS_REGISTER_EXPORT } from '../../composables/useAnalyticsHub'
import type { PlanLayer, PlanMatrixRow } from '@/entities/planTargets'

const props = defineProps<{
  year: number
  layer: PlanLayer
  pipelineId: number | null
}>()

const { t } = useI18n()

const {
  matrix,
  loading,
  endpointMissing,
  breakdown,
  setBreakdown,
  canEdit,
  isMoney,
  cellValue,
  setCellValue,
  isCellDirty,
  rowTotal,
  dirtyCount,
  confirmLeave,
  save,
  saving,
  copyPrevious,
  copying,
} = useProductIncomeTab({
  year: () => props.year,
  layer: () => props.layer,
  pipelineId: () => props.pipelineId,
})

interface BreakdownOption {
  value: IncomeBreakdown
  label: string
}

const breakdownOptions = computed<BreakdownOption[]>(() => [
  { value: 'product', label: t('dashboard.plans.breakdown_by_product') },
  { value: 'user', label: t('dashboard.plans.breakdown_by_user') },
])

// Register the dirty-guard with TabPlans (identical pattern to MetricIncome).
const registerMetricGuard = inject(PLANS_REGISTER_METRIC_GUARD, null)
const attachGuard = (): void => registerMetricGuard?.(confirmLeave)
const detachGuard = (): void => registerMetricGuard?.(null)
onMounted(attachGuard)
onActivated(attachGuard)
onDeactivated(detachGuard)
onBeforeUnmount(detachGuard)

// Refine the plans-export descriptor for the active breakdown: «По линейкам» exports
// the product_income/company matrix; «По сотрудникам» exports the new_income/user
// grid — matching what this panel currently shows (same query as GET /api/plans/matrix).
const registerExport = inject(PLANS_REGISTER_EXPORT, null)
const publishExport = (): void => {
  registerExport?.(
    breakdown.value === 'product'
      ? { metric: 'product_income', scope_type: 'company' }
      : { metric: 'new_income', scope_type: 'user' },
  )
}
onMounted(publishExport)
onActivated(publishExport)
watch(breakdown, publishExport)

const onBreakdown = (value: IncomeBreakdown): void => {
  void setBreakdown(value)
}

const onCellInput = (row: PlanMatrixRow, columnKey: string, value: number | null): void => {
  setCellValue(row, columnKey, value)
}

// Currency change is a per-row visual change; persisted with the cells on save.
const onRowCurrency = (row: PlanMatrixRow, currency: string): void => {
  for (const key of Object.keys(row.cells)) {
    const cell = row.cells[key]
    if (cell) cell.currency = currency
  }
}
</script>

<style lang="scss" scoped>
.metric-product {
  display: flex;
  flex-direction: column;
}

.metric-product__breakdown {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: $space-3;
  margin-bottom: $space-4;
}

.metric-product__breakdown-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.metric-product__skeleton {
  padding: $space-2 0;
}
</style>
