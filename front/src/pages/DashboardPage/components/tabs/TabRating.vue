<template>
  <div class="tab-rating">
    <!-- Year + scoring-mode controls -->
    <div class="tab-rating__controls">
      <div class="tab-rating__control">
        <label class="tab-rating__control-label" :for="yearSelectId">
          {{ t('dashboard.rating.year') }}
        </label>
        <Select
          :input-id="yearSelectId"
          :model-value="year"
          :options="yearOptions"
          class="tab-rating__year"
          @update:model-value="onYearChange"
        />
      </div>
      <SelectButton
        :model-value="mode"
        :options="modeOptions"
        option-label="label"
        option-value="value"
        :allow-empty="false"
        class="tab-rating__mode"
        @update:model-value="onModeChange"
      />
    </div>

    <!-- Endpoint not yet deployed (parallel backend) -->
    <Message
      v-if="endpointMissing && !loading"
      severity="info"
      :closable="false"
      icon="pi pi-info-circle"
      class="tab-rating__notice"
    >
      {{ t('dashboard.rating.endpoint_missing') }}
    </Message>

    <!-- Multi-currency warning -->
    <Message
      v-else-if="report?.meta?.multi_currency_warning"
      severity="warn"
      :closable="false"
      icon="pi pi-info-circle"
      class="tab-rating__notice"
    >
      {{ t('dashboard.multiCurrencyWarning') }}
    </Message>

    <!-- Loading skeleton: hero card + rating rows -->
    <div v-if="loading" class="tab-rating__skeleton">
      <Skeleton height="7rem" class="mb-4" />
      <Skeleton v-for="n in 6" :key="`r${n}`" height="2.75rem" class="mb-2" />
    </div>

    <template v-else-if="hasData">
      <LeaderCard
        v-if="report!.leader"
        :leader="report!.leader"
        :year="year"
        :base-currency="baseCurrency"
        class="tab-rating__leader"
      />
      <RatingTable
        :rows="report!.rows"
        :base-currency="baseCurrency"
        class="tab-rating__table"
      />
    </template>

    <!-- No data (endpoint-missing already shows its own hint above) -->
    <div v-else-if="!endpointMissing" class="tab-rating__empty">
      <i class="pi pi-trophy tab-rating__empty-icon" aria-hidden="true" />
      <h3 class="tab-rating__empty-title">{{ t('dashboard.rating.empty') }}</h3>
      <p class="tab-rating__empty-hint">{{ t('dashboard.rating.empty_hint') }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import LeaderCard from '../rating/LeaderCard.vue'
import RatingTable from '../rating/RatingTable.vue'
import { useRatingTab } from '../../composables/useRatingTab'
import type { BestManagerMode } from '@/entities/reports'

const props = defineProps<{
  year: number
  pipelineId: number | null
}>()

const emit = defineEmits<{
  (e: 'update:year', value: number): void
}>()

const { t } = useI18n()

const yearSelectId = useId()

// Scoring mode is tab-local (not a cross-cutting hub filter) — «Стандартный | Абсолютный».
const mode = ref<BestManagerMode>('standard')

const modeOptions = computed(() => [
  { value: 'standard' as BestManagerMode, label: t('dashboard.rating.mode_standard') },
  { value: 'absolute' as BestManagerMode, label: t('dashboard.rating.mode_absolute') },
])

// Year list: current year and the previous four (annual granularity).
const YEAR_SPAN = 5
const yearOptions = computed<number[]>(() => {
  const current = new Date().getFullYear()
  return Array.from({ length: YEAR_SPAN }, (_, i) => current - i)
})

const { report, loading, endpointMissing } = useRatingTab({
  year: () => props.year,
  mode: () => mode.value,
  pipelineId: () => props.pipelineId,
})

const baseCurrency = computed<string>(() => report.value?.meta.base_currency ?? 'RUB')

const hasData = computed<boolean>(
  () => report.value != null && report.value.rows.length > 0,
)

const onYearChange = (value: number | null): void => {
  if (value != null) emit('update:year', value)
}

const onModeChange = (value: BestManagerMode | null): void => {
  if (value != null) mode.value = value
}
</script>

<style lang="scss" scoped>
.tab-rating {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

// ── Controls (year + mode) ───────────────────────────────────────────────────
.tab-rating__controls {
  display: flex;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: $space-4;
}

.tab-rating__control {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.tab-rating__control-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: $surface-500;
}

.tab-rating__year {
  min-width: 8rem;
}

.tab-rating__notice {
  margin: 0;
}

.tab-rating__skeleton {
  padding: $space-2 0;
}

// ── Empty state ──────────────────────────────────────────────────────────────
.tab-rating__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  min-height: 260px;
  padding: $space-8 $space-6;
  text-align: center;
}

// $surface-* inverts on .app-dark via PrimeVue — reactive from the base rule.
.tab-rating__empty-icon {
  font-size: $font-size-icon-xl;
  color: $surface-400;
}

.tab-rating__empty-title {
  margin: 0;
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-800;
}

.tab-rating__empty-hint {
  margin: 0;
  font-size: $font-size-sm;
  color: $surface-500;
}

// ── Adaptive (≤1280) ─────────────────────────────────────────────────────────
@media (max-width: 1280px) {
  .tab-rating__controls {
    align-items: stretch;
  }

  .tab-rating__mode {
    align-self: flex-start;
  }
}
</style>
