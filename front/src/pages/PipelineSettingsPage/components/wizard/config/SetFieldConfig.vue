<template>
  <div class="set-field-config">
    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.field') }} <span class="required">*</span></label>
      <Select
        v-model="field"
        :options="fieldOptions"
        option-label="label"
        option-value="value"
        fluid
        :invalid="!!errors['action_config.field'] || !!localErrors.field"
      />
      <small v-if="errors['action_config.field']" class="field-error">{{ errors['action_config.field'] }}</small>
      <small v-else-if="localErrors.field" class="field-error">{{ localErrors.field }}</small>
    </div>

    <div class="mb-3">
      <label class="field-label">{{ t('automation.fields.value') }} <span class="required">*</span></label>
      <Textarea
        v-if="field === 'notes'"
        v-model="value"
        rows="4"
        fluid
        :invalid="!!errors['action_config.value'] || !!localErrors.value"
      />
      <InputText
        v-else
        v-model="value"
        fluid
        :invalid="!!errors['action_config.value'] || !!localErrors.value"
      />
      <small v-if="errors['action_config.value']" class="field-error">{{ errors['action_config.value'] }}</small>
      <small v-else-if="localErrors.value" class="field-error">{{ localErrors.value }}</small>
    </div>

    <Message severity="info" :closable="false" class="mt-2">
      {{ t('automation.fields.setFieldNote') }}
    </Message>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Message from 'primevue/message'

const props = defineProps<{
  config: Record<string, unknown>
  errors: Record<string, string>
}>()

const emit = defineEmits<{
  'update:config': [v: Record<string, unknown>]
}>()

const { t } = useI18n()

// Whitelisted fields (from automationConfig / backend comments)
const FIELD_WHITELIST = ['notes', 'title']

const fieldOptions = computed(() =>
  FIELD_WHITELIST.map((f) => ({
    label: t(`automation.fields.dealField.${f}`, f),
    value: f,
  })),
)

const field = ref<string>((props.config.field as string) ?? '')
const value = ref<string>((props.config.value as string) ?? '')
const localErrors = ref<Record<string, string>>({})

watch([field, value], () => {
  emit('update:config', { field: field.value, value: value.value })
})

watch(
  () => props.config,
  (v) => {
    // Identity guard: skip re-hydration if incoming config equals our own last emit.
    if (JSON.stringify(v) === JSON.stringify({ field: field.value, value: value.value })) return
    field.value = (v.field as string) ?? ''
    value.value = (v.value as string) ?? ''
  },
  { deep: true },
)

function validate(): boolean {
  localErrors.value = {}
  let ok = true
  if (!field.value) {
    localErrors.value.field = t('automation.errors.fieldRequired')
    ok = false
  }
  if (!value.value.trim()) {
    localErrors.value.value = t('automation.errors.fieldValueRequired')
    ok = false
  }
  return ok
}

defineExpose({ validate })
</script>

<style lang="scss" scoped>
.set-field-config {
  .field-label {
    display: block;
    font-size: $font-size-sm;
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
</style>
