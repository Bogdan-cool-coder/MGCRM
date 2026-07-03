<template>
  <div class="metric-tasks">
    <!-- Endpoint not yet deployed (parallel backend) -->
    <Message
      v-if="endpointMissing && !loading"
      severity="info"
      :closable="false"
      icon="pi pi-info-circle"
      class="mb-4"
    >
      {{ t('dashboard.plans.tasks_endpoint_missing') }}
    </Message>

    <!-- Kind selector (which activity kind's matrix to plan/view) -->
    <div v-if="!endpointMissing && kindOptions.length" class="metric-tasks__kind">
      <label class="metric-tasks__kind-label">{{ t('dashboard.plans.task_kind') }}</label>
      <SelectButton
        :model-value="selectedKind"
        :options="kindOptions"
        option-label="label"
        option-value="value"
        :allow-empty="false"
        class="metric-tasks__kind-select"
        @update:model-value="(v: string | null) => v && onKind(v)"
      />
    </div>

    <!-- Aggregate «Все» is read-only (not a plannable kind) -->
    <Message
      v-if="isAggregateKind && !loading && matrix?.rows.length"
      severity="secondary"
      :closable="false"
      icon="pi pi-info-circle"
      class="mb-4"
    >
      {{ t('dashboard.plans.tasks_all_readonly') }}
    </Message>

    <!-- Loading skeleton shaped like the grid -->
    <div v-if="loading" class="metric-tasks__skeleton">
      <Skeleton v-for="n in 6" :key="n" height="2.5rem" class="mb-2" />
    </div>

    <!-- Empty (no employees in scope) -->
    <PlansEmpty
      v-else-if="!matrix || matrix.rows.length === 0"
      icon="pi-users"
      :title="t('dashboard.plans.empty_title')"
      :hint="t('dashboard.plans.empty_hint')"
    />

    <!-- Matrix (count) + save bar -->
    <template v-else>
      <PlanMatrix
        :matrix="matrix"
        :is-money="isMoney"
        :can-edit="canEdit"
        :cell-value="cellValue"
        :is-cell-dirty="isCellDirty"
        :row-total="rowTotal"
        @cell-input="onCellInput"
      />

      <PlanSaveBar
        v-if="canEdit"
        :dirty-count="dirtyCount"
        :saving="saving"
        :can-edit="canEdit"
        :show-copy="false"
        @save="save"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { inject, onMounted, onActivated, onDeactivated, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import SelectButton from 'primevue/selectbutton'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import PlanMatrix from './PlanMatrix.vue'
import PlanSaveBar from './PlanSaveBar.vue'
import PlansEmpty from './PlansEmpty.vue'
import { useTasksTab } from '../../composables/useTasksTab'
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
  selectedKind,
  kindOptions,
  setKind,
  isAggregateKind,
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
} = useTasksTab({
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

const onKind = (kind: string): void => {
  void setKind(kind)
}

const onCellInput = (row: PlanMatrixRow, columnKey: string, value: number | null): void => {
  setCellValue(row, columnKey, value)
}
</script>

<style lang="scss" scoped>
.metric-tasks {
  display: flex;
  flex-direction: column;
}

.metric-tasks__kind {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: $space-3;
  margin-bottom: $space-4;
}

.metric-tasks__kind-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.metric-tasks__skeleton {
  padding: $space-2 0;
}
</style>
