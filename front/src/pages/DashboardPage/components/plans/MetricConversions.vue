<template>
  <div class="metric-conv">
    <!-- Endpoint not yet deployed (parallel backend) -->
    <Message
      v-if="endpointMissing && !loading"
      severity="info"
      :closable="false"
      icon="pi pi-info-circle"
      class="mb-4"
    >
      {{ t('dashboard.plans.conv_endpoint_missing') }}
    </Message>

    <!-- Read-only note + disabled «Добавить конверсию» stub (constructor is out of scope) -->
    <div v-if="!endpointMissing && !loading" class="metric-conv__bar">
      <span class="metric-conv__note">{{ t('dashboard.plans.conv_readonly') }}</span>
      <Button
        icon="pi pi-plus"
        :label="t('dashboard.plans.add_conversion')"
        severity="secondary"
        outlined
        size="small"
        disabled
        v-tooltip.bottom="t('common.coming_soon')"
      />
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="metric-conv__skeleton">
      <Skeleton height="2rem" class="mb-3" style="width: 40%" />
      <Skeleton v-for="n in 4" :key="`p${n}`" height="2.5rem" class="mb-2" />
      <Skeleton height="2rem" class="mt-4 mb-3" style="width: 40%" />
      <Skeleton v-for="n in 3" :key="`s${n}`" height="3rem" class="mb-2" />
    </div>

    <!-- Empty -->
    <PlansEmpty
      v-else-if="!hasData"
      icon="pi-percentage"
      :title="t('dashboard.plans.conv_empty_title')"
      :hint="t('dashboard.plans.conv_empty_hint')"
    />

    <template v-else>
      <!-- (a) Conversion pairs (plan / fact / %) -->
      <section v-if="pairs.length" class="metric-conv__section">
        <h3 class="metric-conv__section-title">{{ t('dashboard.plans.conv_pairs_title') }}</h3>
        <ConversionPairsTable :pairs="pairs" />
      </section>

      <!-- (b) Honest stage conversions (funnel) -->
      <section v-if="stageConversions.length" class="metric-conv__section">
        <h3 class="metric-conv__section-title">
          {{ t('dashboard.plans.conv_stage_title') }}
          <span class="metric-conv__section-hint">{{ t('dashboard.plans.conv_stage_hint') }}</span>
        </h3>
        <StageConversionFunnel :stages="stageConversions" />
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Button from 'primevue/button'
import PlansEmpty from './PlansEmpty.vue'
import ConversionPairsTable from './ConversionPairsTable.vue'
import StageConversionFunnel from './StageConversionFunnel.vue'
import { useConversionsTab } from '../../composables/useConversionsTab'
import type { PlanLayer } from '@/entities/planTargets'

const props = defineProps<{
  year: number
  layer: PlanLayer
  pipelineId: number | null
}>()

const { t } = useI18n()

const { pairs, stageConversions, hasData, loading, endpointMissing } = useConversionsTab({
  year: () => props.year,
  layer: () => props.layer,
  pipelineId: () => props.pipelineId,
})
</script>

<style lang="scss" scoped>
.metric-conv {
  display: flex;
  flex-direction: column;
}

.metric-conv__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-3;
  flex-wrap: wrap;
  margin-bottom: $space-4;
}

.metric-conv__note {
  font-size: $font-size-sm;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.metric-conv__skeleton {
  padding: $space-2 0;
}

.metric-conv__section {
  margin-bottom: $space-6;

  &:last-child {
    margin-bottom: 0;
  }
}

.metric-conv__section-title {
  display: flex;
  align-items: baseline;
  gap: $space-3;
  margin: 0 0 $space-3;
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-900;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.metric-conv__section-hint {
  font-size: $font-size-xs;
  font-weight: $font-weight-normal;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-500);
  }
}
</style>
