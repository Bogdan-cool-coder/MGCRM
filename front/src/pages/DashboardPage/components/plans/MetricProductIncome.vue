<template>
  <div class="metric-product">
    <!--
      Breakdown is fixed to «По линейкам» (product line). A «По сотрудникам» cut is
      intentionally NOT offered here: the plan-targets contract (§3.3) has no valid
      `product_income × scope_type=user` combination and no per-employee product-line
      endpoint, so that cut could only re-issue the plain `new_income/user` request —
      i.e. it would show numbers indistinguishable from the «Поступления» metric (which
      already provides that per-manager grid). Removed to avoid the misleading duplicate.
      See CONTRACT GAP (QA E1).
    -->

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

    <!-- Empty (no product lines in scope) -->
    <PlansEmpty
      v-else-if="!matrix || matrix.rows.length === 0"
      icon="pi-box"
      :title="t('dashboard.plans.product_empty_title')"
      :hint="t('dashboard.plans.product_empty_hint')"
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
        :entity-label="t('dashboard.plans.col_product_line')"
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
import { inject, onMounted, onActivated, onDeactivated, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import PlanMatrix from './PlanMatrix.vue'
import PlanSaveBar from './PlanSaveBar.vue'
import PlansEmpty from './PlansEmpty.vue'
import { useProductIncomeTab } from '../../composables/useProductIncomeTab'
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

// Register the dirty-guard with TabPlans (identical pattern to MetricIncome).
const registerMetricGuard = inject(PLANS_REGISTER_METRIC_GUARD, null)
const attachGuard = (): void => registerMetricGuard?.(confirmLeave)
const detachGuard = (): void => registerMetricGuard?.(null)
onMounted(attachGuard)
onActivated(attachGuard)
onDeactivated(detachGuard)
onBeforeUnmount(detachGuard)

// Export descriptor: this panel only shows the «По линейкам» cut, so it always
// exports the product_income/company matrix (GET /api/plans/matrix/export).
const registerExport = inject(PLANS_REGISTER_EXPORT, null)
const publishExport = (): void => {
  registerExport?.({ metric: 'product_income', scope_type: 'company' })
}
onMounted(publishExport)
onActivated(publishExport)

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

.metric-product__skeleton {
  padding: $space-2 0;
}
</style>
