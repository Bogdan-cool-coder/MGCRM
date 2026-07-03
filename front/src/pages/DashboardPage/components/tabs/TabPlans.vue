<template>
  <div class="tab-plans">
    <!-- Metric sub-tabs. Ф1 wires «Поступления» (income); Задачи/Конверсии are
         disabled shells (Ф4/Ф5) — the sub-navigation scaffolding is live. -->
    <div class="tab-plans__metrics">
      <SelectButton
        :model-value="metricTab"
        :options="metricOptions"
        option-label="label"
        option-value="value"
        option-disabled="disabled"
        :allow-empty="false"
        @update:model-value="(v: PlanMetricTab | null) => v && (metricTab = v)"
      >
        <template #option="{ option }">
          <span
            v-tooltip.top="(option as MetricOption).disabled ? t('common.coming_soon') : undefined"
          >{{ (option as MetricOption).label }}</span>
        </template>
      </SelectButton>
    </div>

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
    <div v-if="loading" class="tab-plans__skeleton">
      <Skeleton v-for="n in 6" :key="n" height="2.5rem" class="mb-2" />
    </div>

    <!-- Empty (no employees in scope) -->
    <div
      v-else-if="!matrix || matrix.rows.length === 0"
      class="tab-plans__empty"
    >
      <i class="pi pi-users tab-plans__empty-icon" aria-hidden="true" />
      <h3 class="tab-plans__empty-title">{{ t('dashboard.plans.empty_title') }}</h3>
      <p class="tab-plans__empty-hint">{{ t('dashboard.plans.empty_hint') }}</p>
    </div>

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
import SelectButton from 'primevue/selectbutton'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import PlanMatrix from '../plans/PlanMatrix.vue'
import PlanSaveBar from '../plans/PlanSaveBar.vue'
import { usePlansTab, type PlanMetricTab } from '../../composables/usePlansTab'
import { HUB_REGISTER_LEAVE_GUARD } from '../../composables/useAnalyticsHub'
import type { PlanLayer, PlanMatrixRow } from '@/entities/planTargets'

const props = defineProps<{
  year: number
  layer: PlanLayer
  pipelineId: number | null
}>()

const { t } = useI18n()

interface MetricOption {
  label: string
  value: PlanMetricTab
  disabled: boolean
}

const {
  metricTab,
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

// Register the dirty-guard with the hub shell so an in-hub tab switch (via the
// SelectButton strip) prompts before losing unsaved edits — route-leave and
// beforeunload are already covered inside usePlansTab. Under <keep-alive> the
// guard is scoped to while THIS tab is active (activated/deactivated), so it
// only gates switches AWAY from «Планы», never other tabs' switches.
const registerLeaveGuard = inject(HUB_REGISTER_LEAVE_GUARD, null)
const attachGuard = (): void => registerLeaveGuard?.(confirmLeave)
const detachGuard = (): void => registerLeaveGuard?.(null)
onMounted(attachGuard)
onActivated(attachGuard)
onDeactivated(detachGuard)
onBeforeUnmount(detachGuard)

const canEdit = computed<boolean>(() => matrix.value?.meta.can_edit ?? false)

const metricOptions = computed<MetricOption[]>(() => [
  { label: t('dashboard.plans.metric_income'), value: 'income', disabled: false },
  { label: t('dashboard.plans.metric_tasks'), value: 'tasks', disabled: true },
  { label: t('dashboard.plans.metric_conversions'), value: 'conversions', disabled: true },
])

const onCellInput = (row: PlanMatrixRow, columnKey: string, value: number | null): void => {
  setCellValue(row, columnKey, value)
}

// Ф1: currency change is a per-row visual change; persisted with the cells on
// save (the backend takes currency alongside value_kopecks). Marking the row's
// month cells dirty happens on value edit — the currency select updates the
// stored ref via the matrix cell, applied on the next save/reload.
const onRowCurrency = (row: PlanMatrixRow, currency: string): void => {
  for (const key of Object.keys(row.cells)) {
    const cell = row.cells[key]
    if (cell) cell.currency = currency
  }
}
</script>

<style lang="scss" scoped>
.tab-plans {
  display: flex;
  flex-direction: column;
}

.tab-plans__metrics {
  margin-bottom: $space-4;
}

.tab-plans__skeleton {
  padding: $space-2 0;
}

.tab-plans__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  min-height: 260px;
  padding: $space-8 $space-6;
  text-align: center;
}

.tab-plans__empty-icon {
  font-size: $font-size-icon-xl;
  color: $surface-400;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.tab-plans__empty-title {
  margin: 0;
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.tab-plans__empty-hint {
  margin: 0;
  font-size: $font-size-sm;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-500);
  }
}
</style>
