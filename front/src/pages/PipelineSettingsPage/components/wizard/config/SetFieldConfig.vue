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
      <!-- tags is an array column → chips input (AutoComplete in multiple mode) -->
      <AutoComplete
        v-if="field === 'tags'"
        v-model="tagsValue"
        :suggestions="[]"
        multiple
        fluid
        :placeholder="t('automation.fields.tagsPlaceholder')"
        :invalid="!!errors['action_config.value'] || !!localErrors.value"
        @complete="() => {}"
      />
      <InputText
        v-else
        v-model="textValue"
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
import AutoComplete from 'primevue/autocomplete'
import Message from 'primevue/message'

const props = defineProps<{
  config: Record<string, unknown>
  errors: Record<string, string>
}>()

const emit = defineEmits<{
  'update:config': [v: Record<string, unknown>]
}>()

const { t } = useI18n()

// Whitelisted deal columns — must match backend config('automation.set_field.deal').
const FIELD_WHITELIST = ['title', 'tags']

const fieldOptions = computed(() =>
  FIELD_WHITELIST.map((f) => ({
    label: t(`automation.fields.dealField.${f}`, f),
    value: f,
  })),
)

function readTags(raw: unknown): string[] {
  return Array.isArray(raw) ? raw.map((v) => String(v)) : []
}

const field = ref<string>((props.config.field as string) ?? '')
const textValue = ref<string>(field.value === 'tags' ? '' : ((props.config.value as string) ?? ''))
const tagsValue = ref<string[]>(field.value === 'tags' ? readTags(props.config.value) : [])
const localErrors = ref<Record<string, string>>({})

// The emitted value depends on the field: tags → string[], everything else → string.
const emittedValue = computed<unknown>(() => (field.value === 'tags' ? tagsValue.value : textValue.value))

watch([field, textValue, tagsValue], () => {
  emit('update:config', { field: field.value, value: emittedValue.value })
})

watch(
  () => props.config,
  (v) => {
    const nextValue = v.field === 'tags' ? readTags(v.value) : ((v.value as string) ?? '')
    // Identity guard: skip re-hydration if incoming config equals our own last emit.
    if (JSON.stringify(v) === JSON.stringify({ field: field.value, value: emittedValue.value })) return
    field.value = (v.field as string) ?? ''
    if (field.value === 'tags') {
      tagsValue.value = nextValue as string[]
      textValue.value = ''
    } else {
      textValue.value = nextValue as string
      tagsValue.value = []
    }
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
  const hasValue = field.value === 'tags' ? tagsValue.value.length > 0 : textValue.value.trim() !== ''
  if (!hasValue) {
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
