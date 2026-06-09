<template>
  <div class="filter-field">
    <label class="filter-label">{{ getLabel(config) }}</label>
    <InputText
      v-model="textValue"
      :placeholder="getPlaceholder(config)"
      class="w-full"
      @input="emitChange"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import InputText from 'primevue/inputtext'
import type { TextFilterConfig } from '@/entities/report'
import { getLocalizedText } from '@/utils/localization'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Props {
  field: string
  config: TextFilterConfig
  modelValue?: string
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: '',
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const textValue = ref<string>('')

// Initialize from model value
watch(
  () => props.modelValue,
  (newVal) => {
    textValue.value = typeof newVal === 'string' ? newVal : ''
  },
  { immediate: true },
)

const getLabel = (config: TextFilterConfig): string => {
  if (config.label) {
    return getLocalizedText(config.label)
  }
  // Capitalize field name
  return props.field.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())
}

const getPlaceholder = (config: TextFilterConfig): string => {
  if (config.placeholder) {
    return typeof config.placeholder === 'object'
      ? getLocalizedText(config.placeholder)
      : config.placeholder
  }
  return t('textPlaceholder')
}

const emitChange = () => {
  emit('update:modelValue', textValue.value)
}
</script>

<style lang="scss" scoped>
.filter-field {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;

  .filter-label {
    font-weight: $font-weight-semibold;
    font-size: $font-size-sm;
    color: $surface-700;
  }

  :deep(.p-inputtext) {
    display: flex;
    align-items: center;
    min-height: 2.5rem;
    padding: 0 0.5rem;
    font-size: 1rem;
  }
}
</style>
