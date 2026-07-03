<template>
  <div class="metric-income">
    <!-- Endpoint not yet deployed (parallel backend) -->
    <Message
      v-if="endpointMissing && !loading"
      severity="info"
      :closable="false"
      icon="pi pi-info-circle"
      class="mb-4"
    >
      {{ t('dashboard.plans.endpoint_missing') }}
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
    <div v-if="loading" class="metric-income__skeleton">
      <Skeleton v-for="n in 6" :key="n" height="2.5rem" class="mb-2" />
    </div>

    <!-- Empty (no employees in scope) -->
    <PlansEmpty
      v-else-if="!matrix || matrix.rows.length === 0"
      icon="pi-users"
      :title="t('dashboard.plans.empty_title')"
      :hint="t('dashboard.plans.empty_hint')"
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
import { computed, inject, onMounted, onActivated, onDeactivated, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import PlanMatrix from './PlanMatrix.vue'
import PlanSaveBar from './PlanSaveBar.vue'
import PlansEmpty from './PlansEmpty.vue'
import { usePlansTab } from '../../composables/usePlansTab'
import { PLANS_REGISTER_METRIC_GUARD } from '../../composables/useAnalyticsHub'
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
} = usePlansTab({
  year: () => props.year,
  layer: () => props.layer,
  pipelineId: () => props.pipelineId,
})

// Register the dirty-guard with TabPlans (which forwards it to the hub for outer
// tab switches and consults it on inner metric switches). Route-leave/beforeunload
// are covered in the composable. Only the active metric panel is mounted, so
// exactly one guard is registered at a time.
const registerMetricGuard = inject(PLANS_REGISTER_METRIC_GUARD, null)
const attachGuard = (): void => registerMetricGuard?.(confirmLeave)
const detachGuard = (): void => registerMetricGuard?.(null)
onMounted(attachGuard)
onActivated(attachGuard)
onDeactivated(detachGuard)
onBeforeUnmount(detachGuard)

const canEdit = computed<boolean>(() => matrix.value?.meta.can_edit ?? false)

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
.metric-income {
  display: flex;
  flex-direction: column;
}

.metric-income__skeleton {
  padding: $space-2 0;
}
</style>
