<template>
  <section class="pipeline-params-panel">
    <header class="pipeline-params-panel__head">
      <i class="pi pi-sliders-h pipeline-params-panel__head-icon" />
      <h3 class="pipeline-params-panel__head-title">{{ t('sales.pipelineEditor.params.title') }}</h3>
    </header>

    <div class="pipeline-params-panel__body">
      <div class="pipeline-params-panel__field">
        <label class="pipeline-params-panel__label" :for="selectId">
          {{ t('sales.pipelineEditor.defaultStage.label') }}
        </label>
        <Select
          :input-id="selectId"
          :model-value="modelValue"
          :options="stageOptions"
          option-label="name"
          option-value="id"
          show-clear
          :disabled="disabled || loading || stageOptions.length === 0"
          :loading="loading"
          :placeholder="t('sales.pipelineEditor.defaultStage.autoNote')"
          class="pipeline-params-panel__select"
          append-to="body"
          @change="onChange"
        />
        <small
          v-if="stageOptions.length === 0 && !loading"
          class="pipeline-params-panel__hint"
        >
          {{ t('sales.pipelineEditor.defaultStage.emptyHint') }}
        </small>
        <small v-else class="pipeline-params-panel__hint">
          {{ t('sales.pipelineEditor.defaultStage.hint') }}
        </small>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import Select, { type SelectChangeEvent } from 'primevue/select'
import type { PipelineStageDto } from '@/entities/sales'

const props = defineProps<{
  /** Current default_stage_id (null = automatic / first stage). */
  modelValue: number | null
  /** All stages of the selected pipeline. */
  stages: PipelineStageDto[]
  loading?: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{
  /** Persist a new default stage (null clears it). */
  save: [stageId: number | null]
}>()

const { t } = useI18n()
const selectId = useId()

// Only stages valid as a start stage: exclude won / lost / hidden-by-default.
const stageOptions = computed<PipelineStageDto[]>(() =>
  props.stages.filter((s) => !s.is_won && !s.is_lost && !s.hidden_by_default),
)

function onChange(e: SelectChangeEvent) {
  const next = (e.value as number | null | undefined) ?? null
  emit('save', next)
}
</script>

<style lang="scss" scoped>
.pipeline-params-panel {
  // flex-shrink:0 is REQUIRED here. This panel is a flex item inside the
  // parent's column flex container (.pipeline-settings-page__form-content:
  // display:flex; overflow-y:auto; min-height:0). Because this root sets
  // overflow:hidden, the flexbox spec resets its automatic minimum size to 0,
  // so without an explicit floor the flex algorithm collapses the panel to
  // ~2px height while sibling panels keep full size. flex-shrink:0 pins it.
  flex-shrink: 0;
  background: $surface-card;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-lg;
  box-shadow: $shadow-card;
  overflow: hidden;
  // $surface-card + --p-surface-200 are theme-reactive → reads in dark too.
}

.pipeline-params-panel__head {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);
}

.pipeline-params-panel__head-icon {
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
}

.pipeline-params-panel__head-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: $surface-500;
  margin: 0;
}

.pipeline-params-panel__body {
  padding: $space-4;
}

.pipeline-params-panel__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  max-width: 420px;
}

.pipeline-params-panel__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  // $surface-700 theme-reactive secondary text — no dark override.
}

.pipeline-params-panel__select {
  width: 100%;
}

.pipeline-params-panel__hint {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
}
</style>
