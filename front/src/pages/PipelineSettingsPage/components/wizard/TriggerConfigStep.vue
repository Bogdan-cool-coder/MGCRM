<template>
  <div class="trigger-config-step">
    <div class="mb-4">
      <label class="field-label">{{ t('automation.trigger.label') }} <span class="required">*</span></label>

      <div class="trigger-list">
        <!-- on_enter_stage -->
        <div
          :class="['trigger-option', { 'trigger-option--selected': selectedTrigger === 'on_enter_stage' }]"
          role="button"
          tabindex="0"
          @click="selectTrigger('on_enter_stage')"
          @keydown.enter="selectTrigger('on_enter_stage')"
        >
          <RadioButton
            v-model="selectedTrigger"
            value="on_enter_stage"
            name="trigger_kind"
            @update:model-value="selectTrigger('on_enter_stage')"
          />
          <div class="trigger-option__body">
            <div class="trigger-option__title">
              {{ t('automation.trigger.on_enter_stage') }}
              <Tag :value="t('automation.trigger.instantBadge')" severity="success" size="small" class="ms-2" />
            </div>
            <div class="trigger-option__desc">{{ t('automation.trigger.on_enter_stage_desc') }}</div>
          </div>
        </div>

        <!-- idle_in_stage_days -->
        <div
          :class="['trigger-option', { 'trigger-option--selected': selectedTrigger === 'idle_in_stage_days' }]"
          role="button"
          tabindex="0"
          @click="selectTrigger('idle_in_stage_days')"
          @keydown.enter="selectTrigger('idle_in_stage_days')"
        >
          <RadioButton
            v-model="selectedTrigger"
            value="idle_in_stage_days"
            name="trigger_kind"
            @update:model-value="selectTrigger('idle_in_stage_days')"
          />
          <div class="trigger-option__body">
            <div class="trigger-option__title">
              {{ t('automation.trigger.idle_in_stage_days') }}
              <Tag :value="t('automation.trigger.cronBadge')" severity="warning" size="small" class="ms-2" />
            </div>
            <div class="trigger-option__desc">{{ t('automation.trigger.idle_desc') }}</div>
            <div v-if="selectedTrigger === 'idle_in_stage_days'" class="trigger-option__sub mt-2" @click.stop>
              <label class="field-label-sm">{{ t('automation.trigger.idleDays') }} <span class="required">*</span></label>
              <InputNumber
                v-model="idleDays"
                :min="1"
                :max="365"
                :invalid="!!errors.idleDays"
              />
              <small v-if="errors.idleDays" class="field-error">{{ errors.idleDays }}</small>
            </div>
          </div>
        </div>

        <!-- date_field_approaching -->
        <div
          :class="['trigger-option', { 'trigger-option--selected': selectedTrigger === 'date_field_approaching' }]"
          role="button"
          tabindex="0"
          @click="selectTrigger('date_field_approaching')"
          @keydown.enter="selectTrigger('date_field_approaching')"
        >
          <RadioButton
            v-model="selectedTrigger"
            value="date_field_approaching"
            name="trigger_kind"
            @update:model-value="selectTrigger('date_field_approaching')"
          />
          <div class="trigger-option__body">
            <div class="trigger-option__title">
              {{ t('automation.trigger.date_field_approaching') }}
              <Tag :value="t('automation.trigger.cronBadge')" severity="warning" size="small" class="ms-2" />
            </div>
            <div class="trigger-option__desc">{{ t('automation.trigger.date_desc') }}</div>
            <div v-if="selectedTrigger === 'date_field_approaching'" class="trigger-option__sub mt-2" @click.stop>
              <div class="mb-2">
                <label class="field-label-sm">{{ t('automation.trigger.dateField') }} <span class="required">*</span></label>
                <Select
                  v-model="dateField"
                  :options="DATE_FIELDS"
                  option-label="label"
                  option-value="value"
                  fluid
                  :invalid="!!errors.dateField"
                />
                <small v-if="errors.dateField" class="field-error">{{ errors.dateField }}</small>
              </div>
              <div>
                <label class="field-label-sm">{{ t('automation.trigger.daysBeforeDate') }} <span class="required">*</span></label>
                <InputNumber
                  v-model="dateDays"
                  :min="1"
                  :max="365"
                  :invalid="!!errors.dateDays"
                />
                <small v-if="errors.dateDays" class="field-error">{{ errors.dateDays }}</small>
              </div>
            </div>
          </div>
        </div>

        <!-- on_create -->
        <div
          :class="['trigger-option', { 'trigger-option--selected': selectedTrigger === 'on_create' }]"
          role="button"
          tabindex="0"
          @click="selectTrigger('on_create')"
          @keydown.enter="selectTrigger('on_create')"
        >
          <RadioButton
            v-model="selectedTrigger"
            value="on_create"
            name="trigger_kind"
            @update:model-value="selectTrigger('on_create')"
          />
          <div class="trigger-option__body">
            <div class="trigger-option__title">
              {{ t('automation.trigger.on_create') }}
              <Tag :value="t('automation.trigger.instantBadge')" severity="success" size="small" class="ms-2" />
            </div>
            <div class="trigger-option__desc">{{ t('automation.trigger.on_create_desc') }}</div>
          </div>
        </div>
      </div>

      <small v-if="errors.trigger_kind" class="field-error mt-1">{{ errors.trigger_kind }}</small>
    </div>

    <!-- is_active -->
    <div class="d-flex align-items-center gap-3 mt-4">
      <ToggleSwitch v-model="localIsActive" />
      <label class="field-label mb-0">{{ t('automation.fields.isActive') }}</label>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import RadioButton from 'primevue/radiobutton'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import type { TriggerKind } from '@/entities/automation'

const props = defineProps<{
  modelTrigger: TriggerKind | null
  modelConfig: Record<string, unknown>
  modelIsActive: boolean
}>()

const emit = defineEmits<{
  'update:modelTrigger': [v: TriggerKind]
  'update:modelConfig': [v: Record<string, unknown>]
  'update:modelIsActive': [v: boolean]
}>()

const { t } = useI18n()

// Whitelist date fields (from automation.date_fields.deal config)
const DATE_FIELDS = computed(() => [
  { label: t('automation.dateField.expected_close_date'), value: 'expected_close_date' },
  { label: t('automation.dateField.expected_sign_date'), value: 'expected_sign_date' },
  { label: t('automation.dateField.expected_payment_date'), value: 'expected_payment_date' },
])

const errors = ref<Record<string, string>>({})

// Local refs mirroring props.
// NOTE: named selectedTrigger (not modelTrigger) to avoid shadowing the prop of the
// same name, which confused Vue's reactivity and caused BUG-SELF-SHADOW.
const selectedTrigger = ref<TriggerKind | null>(props.modelTrigger)
const idleDays = ref<number | null>(null)
const dateField = ref<string>('')
const dateDays = ref<number | null>(null)
const localIsActive = ref(props.modelIsActive)

function buildTriggerConfig(): Record<string, unknown> {
  if (selectedTrigger.value === 'idle_in_stage_days') {
    return { days: idleDays.value }
  }
  if (selectedTrigger.value === 'date_field_approaching') {
    return { field: dateField.value, days: dateDays.value }
  }
  return {}
}

// Init from existing config (immediate) and keep in sync when parent resets wizard.
// Identity guard prevents echo-cycle: after emitConfig() the parent may reflect the
// same object back as a new prop reference, which would re-trigger the watcher and
// call emitConfig() again → infinite loop. Skipping when values match breaks the cycle.
watch(
  () => props.modelConfig,
  (v) => {
    if (JSON.stringify(v) === JSON.stringify(buildTriggerConfig())) return
    idleDays.value = (v.days as number | null) ?? null
    dateField.value = (v.field as string) ?? ''
    dateDays.value = (v.days as number | null) ?? null
  },
  { immediate: true, deep: true },
)

watch(
  () => props.modelTrigger,
  (v) => {
    if (v !== selectedTrigger.value) selectedTrigger.value = v
  },
)

watch(localIsActive, (v) => emit('update:modelIsActive', v))
watch(
  () => props.modelIsActive,
  (v) => {
    if (v !== localIsActive.value) localIsActive.value = v
  },
)

function selectTrigger(kind: TriggerKind) {
  // Idempotent guard: if this trigger is already selected, do not re-emit.
  // Prevents double-emit when both @click on the wrapper div AND
  // @update:model-value on RadioButton fire for the same selection.
  if (selectedTrigger.value === kind) return
  selectedTrigger.value = kind
  emit('update:modelTrigger', kind)
  // Reset sub-config when trigger changes
  idleDays.value = null
  dateField.value = ''
  dateDays.value = null
  errors.value = {}
  emitConfig()
}

function emitConfig() {
  emit('update:modelConfig', buildTriggerConfig())
}

watch([idleDays, dateField, dateDays], () => {
  emitConfig()
})

// Validate
function validate(): boolean {
  errors.value = {}
  if (!selectedTrigger.value) {
    errors.value.trigger_kind = t('automation.errors.triggerRequired')
    return false
  }
  if (selectedTrigger.value === 'idle_in_stage_days') {
    if (!idleDays.value || idleDays.value < 1) {
      errors.value.idleDays = t('automation.errors.idleDaysRequired')
      return false
    }
  }
  if (selectedTrigger.value === 'date_field_approaching') {
    if (!dateField.value) {
      errors.value.dateField = t('automation.errors.dateFieldRequired')
      return false
    }
    if (dateDays.value === null || dateDays.value < 1) {
      errors.value.dateDays = t('automation.errors.dateDaysRequired')
      return false
    }
  }
  return true
}

defineExpose({ validate })
</script>

<style lang="scss" scoped>
.trigger-config-step {
  .field-label {
    display: block;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    margin-bottom: $space-2;
  }

  .field-label-sm {
    display: block;
    font-size: $font-size-xs;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    margin-bottom: $space-1;
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

.trigger-list {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.trigger-option {
  display: flex;
  align-items: flex-start;
  gap: $space-3;
  padding: $space-3;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  cursor: pointer;
  transition: border-color var(--app-transition-fast), background-color var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-700);
  }

  &--selected {
    border-color: var(--p-primary-color);
    background-color: var(--p-primary-50);

    .app-dark & {
      background-color: var(--p-primary-900);
      border-color: var(--p-primary-400);
    }
  }

  &:hover:not(&--selected) {
    border-color: var(--p-primary-300);
    background-color: var(--p-surface-50);

    .app-dark & {
      background-color: var(--p-surface-700);
    }
  }

  &__body {
    flex: 1;
    min-width: 0;
  }

  &__title {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: $space-2;
  }

  &__desc {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    margin-top: $space-1;
  }

  &__sub {
    padding-left: $space-1;
  }
}
</style>
