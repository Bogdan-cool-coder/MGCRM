<template>
  <div class="filter-field">
    <label class="filter-label">{{ getLabel(config) }}</label>
    <MultiSelect
      v-if="config.type === 'multiselect'"
      :model-value="selectedValues"
      :options="options"
      option-value="value"
      option-label="label"
      :placeholder="t('selectMultiplePlaceholder')"
      :filter="true"
      :filter-placeholder="t('searchPlaceholder')"
      class="w-full"
      @update:model-value="emitChange"
    />
    <Select
      v-else-if="config.type === 'select'"
      :model-value="selectedSingleValue"
      :options="options"
      option-value="value"
      option-label="label"
      :placeholder="t('selectSinglePlaceholder')"
      :filter="true"
      :filter-placeholder="t('searchPlaceholder')"
      :auto-filter-focus="true"
      :clearable="true"
      class="w-full"
      @update:model-value="emitSingleChange"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import MultiSelect from 'primevue/multiselect'
import Select from 'primevue/select'
import type {
  MultiSelectFilterConfig,
  ReportFilterOption,
  SingleSelectFilterConfig,
} from '@/entities/report'
import { getLocalizedText } from '@/utils/localization'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface SelectOption {
  value: string | number
  label: string
}

interface Props {
  field: string
  config: SingleSelectFilterConfig | MultiSelectFilterConfig
  modelValue?: string | number | Array<string | number>
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: () => [],
})

const emit = defineEmits<{
  'update:modelValue': [value: string | number | Array<string | number>]
}>()

const selectedValues = ref<Array<string | number>>([])
const selectedSingleValue = ref<string | number | null>(null)

// Initialize from model value
watch(
  () => props.modelValue,
  (newVal) => {
    if (props.config.type === 'multiselect') {
      selectedValues.value = Array.isArray(newVal) ? newVal : []
    } else {
      selectedSingleValue.value =
        typeof newVal === 'string' || typeof newVal === 'number' ? newVal : null
    }
  },
  { immediate: true },
)

const options = computed<SelectOption[]>(() => {
  return props.config.options.map((opt: ReportFilterOption) => ({
    value: opt.value,
    label:
      typeof opt.label === 'object'
        ? getLocalizedText(opt.label)
        : String(opt.label),
  }))
})

const getLabel = (config: SingleSelectFilterConfig | MultiSelectFilterConfig): string => {
  if (config.label) {
    return getLocalizedText(config.label)
  }
  // Capitalize field name
  return props.field.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())
}

const emitChange = (value: Array<string | number>) => {
  selectedValues.value = value
  emit('update:modelValue', value)
}

const emitSingleChange = (value: string | number) => {
  selectedSingleValue.value = value
  emit('update:modelValue', value)
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

  :deep(.p-select-label),
  :deep(.p-multiselect-label) {
    display: flex;
    align-items: center;
    min-height: 2.5rem;
    padding: 0 0.5rem;
    font-size: 1rem;
  }
}
</style>
