<template>
  <div class="filter-field">
    <label class="filter-label">{{ getLabel(config) }}</label>
    <div class="number-range-inputs">
      <InputNumber
        :model-value="fromValue"
        :placeholder="getPlaceholder(config, 'from')"
        :min="config.min"
        :max="config.max"
        :mode="config.mode || 'decimal'"
        :locale="locale"
        class="number-input"
        input-class="w-full"
        @input="onFromInput"
      />
      <InputNumber
        :model-value="toValue"
        :placeholder="getPlaceholder(config, 'to')"
        :min="config.min"
        :max="config.max"
        :mode="config.mode || 'decimal'"
        :locale="locale"
        class="number-input"
        input-class="w-full"
        @input="onToInput"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import InputNumber, { type InputNumberInputEvent } from 'primevue/inputnumber'
import type {
  NumberRangeFilterConfig,
  NumberRangeValue,
} from '@/entities/report'
import { getLocalizedText } from '@/utils/localization'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Props {
  field: string
  config: NumberRangeFilterConfig
  modelValue?: NumberRangeValue
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: () => ({}),
})

const emit = defineEmits<{
  'update:modelValue': [value: NumberRangeValue]
}>()

const fromValue = ref<number | null>(null)
const toValue = ref<number | null>(null)
const locale = 'ru-RU'

// Sync from the external model value.
//
// `emitChange` builds a fresh object on every keystroke, which the parent
// stores back into `localFilters[field]` and feeds straight back in as
// `modelValue`. A naive watcher would then re-assign `fromValue`/`toValue`
// on every keystroke, resetting InputNumber's internal edit state and making
// the inputs feel like they "reject" typing. Guard against this echo: only
// overwrite a local ref when the incoming value genuinely differs from what
// is already there (external reset / hydration), never when it just mirrors
// our own emit.
watch(
  () => props.modelValue,
  (newVal) => {
    const nextFrom = typeof newVal?.from === 'number' ? newVal.from : null
    const nextTo = typeof newVal?.to === 'number' ? newVal.to : null
    if (nextFrom !== fromValue.value) {
      fromValue.value = nextFrom
    }
    if (nextTo !== toValue.value) {
      toValue.value = nextTo
    }
  },
  { immediate: true, deep: true },
)

const getLabel = (config: NumberRangeFilterConfig): string => {
  if (config.label) {
    return getLocalizedText(config.label)
  }
  // Capitalize field name
  return props.field.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())
}

const getPlaceholder = (config: NumberRangeFilterConfig, type: 'from' | 'to'): string => {
  if (config.placeholder) {
    const placeholder =
      typeof config.placeholder === 'object'
        ? getLocalizedText(config.placeholder)
        : config.placeholder
    return placeholder
  }
  return type === 'from' ? t('numberFrom') : t('numberTo')
}

// PrimeVue InputNumber's `@input` payload is `{ originalEvent, value, ... }`,
// where `value` is typed `string | number | undefined` (the parsed value for
// the current keystroke). We read it from the event rather than from the bound
// ref because the ref is not yet updated at the time `@input` fires. Coerce to
// the `number | null` our model uses: only a real number survives, everything
// else (empty / cleared / unparsable) collapses to null.
const toNumberOrNull = (value: string | number | undefined): number | null =>
  typeof value === 'number' && Number.isFinite(value) ? value : null

const emitChange = () => {
  const value: NumberRangeValue = {
    from: fromValue.value,
    to: toValue.value,
  }
  emit('update:modelValue', value)
}

const onFromInput = (event: InputNumberInputEvent) => {
  fromValue.value = toNumberOrNull(event.value)
  emitChange()
}

const onToInput = (event: InputNumberInputEvent) => {
  toValue.value = toNumberOrNull(event.value)
  emitChange()
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

  .number-range-inputs {
    display: flex;
    align-items: center;
    gap: 0.75rem;

    .number-input {
      flex: 1;

      :deep(.p-inputtext) {
        width: 100%;
        display: flex;
        align-items: center;
        min-height: 2.5rem;
        padding: 0 0.5rem;
        font-size: 1rem;
      }
    }
  }
}
</style>
