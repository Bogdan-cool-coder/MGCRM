<template>
  <Card class="analytics-filter-bar mb-4">
    <template #content>
      <div class="analytics-filter-bar__grid">
        <!-- Row 1: period granularity + stepper + layer -->
        <div class="analytics-filter-bar__row">
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
        </div>

        <!-- Row 2: pipeline + manager -->
        <div class="analytics-filter-bar__row">
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
        </div>
      </div>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Button from 'primevue/button'
import type { PipelineDto } from '@/entities/sales'
import type { UserOptionDto } from '@/api/users'
import type { PlanLayer } from '@/entities/planTargets'
import type { PeriodGranularity } from '../composables/useAnalyticsHub'

const props = defineProps<{
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
.analytics-filter-bar {
  :deep(.p-card-body) {
    padding: $space-3;
  }
}

.analytics-filter-bar__grid {
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

.analytics-filter-bar__row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: $space-3;
}

.analytics-filter-bar__stepper {
  display: flex;
  align-items: center;
  gap: $space-2;
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

@media (max-width: 768px) {
  .analytics-filter-bar__row {
    flex-direction: column;
    align-items: stretch;
  }

  .analytics-filter-bar__select,
  .analytics-filter-bar__granularity,
  .analytics-filter-bar__stepper {
    width: 100%;
  }

  .analytics-filter-bar__stepper {
    justify-content: space-between;
  }
}
</style>
