<template>
  <div class="change-stage-config">
    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.targetStage') }} <span class="required">*</span></label>
      <Select
        v-model="toStageId"
        :options="stageOptions"
        option-label="name"
        option-value="id"
        fluid
        :invalid="!!errors['action_config.to_stage_id'] || !!localErrors.to_stage_id"
        :empty-message="t('automation.fields.noStages')"
      >
        <template #option="{ option }">
          <div class="d-flex align-items-center gap-2">
            <span>{{ option.name }}</span>
            <Tag
              v-if="option.is_won"
              :value="t('automation.fields.stageWon')"
              severity="success"
              size="small"
            />
            <Tag
              v-else-if="option.is_lost"
              :value="t('automation.fields.stageLost')"
              severity="danger"
              size="small"
            />
          </div>
        </template>
      </Select>
      <small v-if="errors['action_config.to_stage_id']" class="field-error">
        {{ errors['action_config.to_stage_id'] }}
      </small>
      <small v-else-if="localErrors.to_stage_id" class="field-error">{{ localErrors.to_stage_id }}</small>
      <small class="field-hint">{{ t('automation.fields.changeStageNote') }}</small>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import type { PipelineStageDto } from '@/entities/sales'

const props = defineProps<{
  config: Record<string, unknown>
  errors: Record<string, string>
  stages: PipelineStageDto[]
  stageId: number | null
}>()

const emit = defineEmits<{
  'update:config': [v: Record<string, unknown>]
}>()

const { t } = useI18n()

// Exclude current stage from choices
const stageOptions = computed(() =>
  props.stages.filter((s) => s.id !== props.stageId),
)

const toStageId = ref<number | null>((props.config.to_stage_id as number | null) ?? null)
const localErrors = ref<Record<string, string>>({})

watch(toStageId, (v) => {
  emit('update:config', { to_stage_id: v })
})

watch(
  () => props.config,
  (v) => {
    // Identity guard: skip re-hydration if incoming config equals our own last emit.
    if (JSON.stringify(v) === JSON.stringify({ to_stage_id: toStageId.value })) return
    toStageId.value = (v.to_stage_id as number | null) ?? null
  },
  { deep: true },
)

function validate(): boolean {
  localErrors.value = {}
  if (toStageId.value === null) {
    localErrors.value.to_stage_id = t('automation.errors.stageRequired')
    return false
  }
  return true
}

defineExpose({ validate })
</script>

<style lang="scss" scoped>
.change-stage-config {
  .field-label {
    display: block;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    margin-bottom: $space-1;
  }

  .field-hint {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    display: block;
    margin-top: $space-1;
  }

  .field-error {
    display: block;
    color: var(--p-red-500);
    font-size: $font-size-xs;
    margin-top: $space-1;
  }

  .required {
    color: var(--p-red-500);
    margin-left: 2px;
  }
}
</style>
