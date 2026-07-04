<template>
  <!-- Compact single-row filter strip on a widget-card surface (restyle §1.3).
       Tab-aware: the Обзор tab uses a month-picker (one OR several months → the
       dashboard `months[]` query, Ф8) + Edit/Done toggle, while report tabs use
       the granularity + month-stepper + layer. Pipeline / manager are SHARED
       across both — the single source of those filters (audit §3в: legacy
       DashboardToolbar removed, no duplicate pickers). -->
  <div class="analytics-filter-bar">
    <!-- ── Обзор period: multi-month picker (unique to the overview widgets) ──── -->
    <template v-if="overviewMode">
      <MonthRangePicker
        :model-value="overviewMonths"
        @update:model-value="(v: string[]) => emit('update:overviewMonths', v)"
      />
    </template>

    <!-- ── Report period: granularity + month-stepper + layer ────────────────── -->
    <template v-else>
      <SelectButton
        :model-value="granularity"
        :options="granularityOptions"
        option-label="label"
        option-value="value"
        :allow-empty="false"
        class="analytics-filter-bar__granularity"
        @update:model-value="(v: PeriodGranularity) => emit('update:granularity', v)"
      />

      <div class="analytics-filter-bar__stepper">
        <Button
          text
          rounded
          severity="secondary"
          icon="pi pi-chevron-left"
          :aria-label="t('dashboard.filters.prev_period')"
          @click="emit('step', -1)"
        />
        <span class="analytics-filter-bar__period-label">{{ periodLabel }}</span>
        <Button
          text
          rounded
          severity="secondary"
          icon="pi pi-chevron-right"
          :aria-label="t('dashboard.filters.next_period')"
          @click="emit('step', 1)"
        />
      </div>

      <!-- Layer (Operative | Annual) — affects Plans + plan-columns.
           On tabs where it does not apply it is dimmed with a tooltip (ОВ-3). -->
      <div
        class="analytics-filter-bar__layer"
        :class="{ 'analytics-filter-bar__layer--dimmed': !layerActive }"
      >
        <SelectButton
          v-tooltip.top="layerActive ? undefined : t('dashboard.filters.layer_hint')"
          :model-value="layer"
          :options="layerOptions"
          option-label="label"
          option-value="value"
          :allow-empty="false"
          @update:model-value="(v: PlanLayer) => emit('update:layer', v)"
        />
      </div>
    </template>

    <!-- Vertical divider separates period controls from scope filters (§1.3). -->
    <span class="analytics-filter-bar__divider" aria-hidden="true" />

    <Select
      :model-value="pipelineId"
      :options="pipelines"
      option-label="name"
      option-value="id"
      :loading="pipelinesLoading"
      show-clear
      :placeholder="t('dashboard.filters.allPipelines')"
      class="analytics-filter-bar__select"
      @update:model-value="(v: number | null) => emit('update:pipelineId', v)"
    />
    <Select
      v-if="canSeeAllManagers"
      :model-value="managerId"
      :options="managers"
      option-label="full_name"
      option-value="id"
      filter
      show-clear
      :placeholder="t('dashboard.filters.allManagers')"
      class="analytics-filter-bar__select"
      @update:model-value="(v: number | null) => emit('update:managerId', v)"
    />

    <!-- ── Обзор widget-grid Edit/Done toggle (right-aligned) ────────────────── -->
    <template v-if="overviewMode">
      <span class="analytics-filter-bar__spacer" />
      <Button
        :label="editMode ? t('dashboard.layout.done') : t('dashboard.layout.edit')"
        :icon="editMode ? 'pi pi-check' : 'pi pi-pencil'"
        :severity="editMode ? 'primary' : 'secondary'"
        :outlined="!editMode"
        size="small"
        @click="emit('toggle-edit')"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Button from 'primevue/button'
import MonthRangePicker from './MonthRangePicker.vue'
import type { PipelineDto } from '@/entities/sales'
import type { UserOptionDto } from '@/api/users'
import type { PlanLayer } from '@/entities/planTargets'
import type { PeriodGranularity } from '../composables/useAnalyticsHub'

const props = defineProps<{
  /** Обзор uses its own month-picker + edit toggle instead of the stepper. */
  overviewMode: boolean
  /** Обзор-only: explicit month selection ("YYYY-MM") driving the widget data (Ф8). */
  overviewMonths: string[]
  /** Обзор-only: widget-grid edit mode (Редактировать/Готово). */
  editMode: boolean
  granularity: PeriodGranularity
  year: number
  month: number
  layer: PlanLayer
  /** Whether the layer control affects the active tab (Plans). Else dimmed. */
  layerActive: boolean
  pipelineId: number | null
  managerId: number | null
  pipelines: PipelineDto[]
  managers: UserOptionDto[]
  pipelinesLoading: boolean
  canSeeAllManagers: boolean
}>()

const emit = defineEmits<{
  'update:overviewMonths': [value: string[]]
  'toggle-edit': []
  'update:granularity': [value: PeriodGranularity]
  step: [dir: -1 | 1]
  'update:layer': [value: PlanLayer]
  'update:pipelineId': [value: number | null]
  'update:managerId': [value: number | null]
}>()

const { t, locale } = useI18n()

const granularityOptions = computed(() => [
  { label: t('dashboard.filters.granularity_month'), value: 'month' as const },
  { label: t('dashboard.filters.granularity_year'), value: 'year' as const },
])

const layerOptions = computed(() => [
  { label: t('dashboard.filters.layer_operative'), value: 'operative' as const },
  { label: t('dashboard.filters.layer_annual'), value: 'annual' as const },
])

const periodLabel = computed<string>(() => {
  if (props.granularity === 'year') return String(props.year)
  const d = new Date(props.year, props.month - 1, 1)
  const monthName = d.toLocaleString(locale.value === 'en' ? 'en-US' : 'ru-RU', {
    month: 'long',
  })
  const capitalized = monthName.charAt(0).toUpperCase() + monthName.slice(1)
  return `${capitalized} ${props.year}`
})
</script>

<style lang="scss" scoped>
// Widget-card surface (= Card.widget-card in Обзор): reactive $surface-card fill,
// $surface-200 border (dark override on this scoped element), $radius-lg, $shadow-sm.
.analytics-filter-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-4;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;
  margin-bottom: $space-4;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.analytics-filter-bar__stepper {
  display: flex;
  align-items: center;
  gap: $space-2;
}

// Thin vertical rule between period controls and scope filters (§1.3).
.analytics-filter-bar__divider {
  width: 1px;
  height: 20px;
  flex-shrink: 0;
  background: $surface-200;

  .app-dark & {
    background: var(--p-surface-200);
  }
}

// The bar (esp. report tabs) starts wrapping to a second line well before the
// stacked ≤768 layout — around ≤1400 the controls no longer fit one row. Hide
// the vertical rule across that wrap range so it never dangles at a line break
// («палка в никуда», audit L1). Below 768 the stacked media query already hides
// it (that block wins at equal specificity — declared later in source).
@media (max-width: 1400px) {
  .analytics-filter-bar__divider {
    display: none;
  }
}

.analytics-filter-bar__period-label {
  min-width: 130px;
  text-align: center;
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  font-variant-numeric: tabular-nums;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.analytics-filter-bar__layer--dimmed {
  opacity: 0.55;
}

.analytics-filter-bar__select {
  min-width: 200px;
}

// Pushes the Обзор Edit/Done toggle to the trailing edge of the row.
.analytics-filter-bar__spacer {
  flex: 1 1 auto;
}

@media (max-width: 768px) {
  .analytics-filter-bar {
    flex-direction: column;
    align-items: stretch;
  }

  .analytics-filter-bar__select,
  .analytics-filter-bar__granularity,
  .analytics-filter-bar__stepper {
    width: 100%;
  }

  // The month-picker (Обзор) fills the stacked row like the other controls.
  :deep(.month-range-picker),
  :deep(.month-range-picker__trigger) {
    width: 100%;
  }

  .analytics-filter-bar__stepper {
    justify-content: space-between;
  }

  // Divider is a horizontal-row device; drop it in the stacked layout.
  .analytics-filter-bar__divider {
    display: none;
  }

  // On stacked layout there's nothing to push against — collapse the spacer.
  .analytics-filter-bar__spacer {
    display: none;
  }
}
</style>
